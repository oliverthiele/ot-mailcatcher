<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Domain\Repository;

use OliverThiele\OtMailcatcher\Check\MailAddressHelper;
use OliverThiele\OtMailcatcher\Domain\Dto\CapturedAttachment;
use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;
use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * Reads the captured .eml files. There is no database table and no cache: the
 * files are the data, which is what keeps a captured mail byte-identical to
 * what the transport produced.
 */
class CapturedMailRepository
{
    /**
     * Identifiers come from request parameters, so they are validated against
     * the exact shape FileTransport produces rather than merely sanitised.
     */
    private const IDENTIFIER_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}-[0-9a-z.]+\.eml$/';

    public const CONTEXT_HEADER = 'X-Mailcatcher-Context';

    /**
     * List view: headers and bodies (the rules need the body), but without the
     * raw source and attachment contents, which are the expensive parts.
     *
     * @return CapturedMail[]
     */
    public function findAll(): array
    {
        $mails = [];
        foreach ($this->listFiles() as $filePath) {
            $mail = $this->buildFromFile($filePath, false);
            if ($mail !== null) {
                $mails[] = $mail;
            }
        }

        return $mails;
    }

    public function findByIdentifier(string $identifier): ?CapturedMail
    {
        $filePath = $this->resolveFilePath($identifier);

        return $filePath === null ? null : $this->buildFromFile($filePath, true);
    }

    public function countAll(): int
    {
        return count($this->listFiles());
    }

    public function delete(string $identifier): bool
    {
        $filePath = $this->resolveFilePath($identifier);

        return $filePath !== null && self::deleteMailFile($filePath);
    }

    public function deleteAll(): int
    {
        $deleted = 0;
        foreach ($this->listFiles() as $filePath) {
            if (self::deleteMailFile($filePath)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Removes a captured mail together with its envelope file.
     */
    public static function deleteMailFile(string $filePath): bool
    {
        if (!@unlink($filePath)) {
            return false;
        }
        @unlink($filePath . FileTransport::ENVELOPE_SUFFIX);

        return true;
    }

    /**
     * Raw bytes of one attachment, for the download route.
     *
     * @return array{fileName: string, mimeType: string, content: string}|null
     */
    public function getAttachment(string $identifier, int $partIndex): ?array
    {
        $filePath = $this->resolveFilePath($identifier);
        if ($filePath === null) {
            return null;
        }

        $message = $this->parse($filePath);
        $part = $message->getAttachmentPart($partIndex);
        if ($part === null) {
            return null;
        }

        return [
            'fileName' => $part->getFilename() ?? ('attachment-' . $partIndex),
            'mimeType' => (string)$part->getContentType(),
            'content' => (string)$part->getContent(),
        ];
    }

    /**
     * Newest first. The file name starts with a sortable timestamp, so the
     * order comes from the name and needs no parsing.
     *
     * @return string[]
     */
    private function listFiles(): array
    {
        $directory = MailcatcherState::getStorageDirectory();
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.eml');
        if ($files === false) {
            return [];
        }

        rsort($files, SORT_STRING);

        return $files;
    }

    private function resolveFilePath(string $identifier): ?string
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            return null;
        }

        $filePath = MailcatcherState::getStorageDirectory() . '/' . $identifier;

        return is_file($filePath) ? $filePath : null;
    }

    private function parse(string $filePath): IMessage
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open "%s".', $filePath), 1755691203);
        }

        return Message::from($handle, true);
    }

    /**
     * @param bool $full Also read the raw source and the attachment contents.
     */
    private function buildFromFile(string $filePath, bool $full): ?CapturedMail
    {
        if (!is_file($filePath)) {
            return null;
        }

        $message = $this->parse($filePath);

        $htmlBody = (string)$message->getHtmlContent();
        $textBody = (string)$message->getTextContent();

        $headers = [];
        if ($full) {
            foreach ($message->getAllHeaders() as $header) {
                // getValue() returns the first part only — one recipient of three,
                // a Content-Type without its parameters.
                $headers[] = ['name' => $header->getName(), 'value' => $header->getDecodedValue()];
            }
        }

        $attachments = [];
        if ($full) {
            foreach ($message->getAllAttachmentParts() as $index => $part) {
                // The size from the stream, so listing an attachment does not
                // decode it into memory.
                $size = $part->getBinaryContentStream()?->getSize();
                $attachments[] = new CapturedAttachment(
                    (int)$index,
                    $part->getFilename() ?? ('attachment-' . $index),
                    (string)$part->getContentType(),
                    $size ?? strlen((string)$part->getContent()),
                );
            }
        }

        $to = $this->addresses($message, HeaderConsts::TO);
        $cc = $this->addresses($message, HeaderConsts::CC);
        $envelope = $this->readEnvelope($filePath);
        if ($envelope !== null) {
            $envelopeSender = $envelope['sender'];
            $envelopeRecipients = $envelope['recipients'];
            // The .eml never carries Bcc — Symfony strips it before serialising.
            // Whoever is in the envelope but in neither To nor Cc was Bcc.
            $visible = array_map(MailAddressHelper::extractAddress(...), array_merge($to, $cc));
            $bcc = array_values(array_filter(
                $envelopeRecipients,
                static fn(string $address): bool => !in_array(MailAddressHelper::extractAddress($address), $visible, true)
            ));
        } else {
            $bcc = $this->addresses($message, HeaderConsts::BCC);
            $envelopeSender = $this->firstAddress($message, HeaderConsts::SENDER)
                ?: $this->firstAddress($message, HeaderConsts::RETURN_PATH)
                ?: $this->firstAddress($message, HeaderConsts::FROM);
            $envelopeRecipients = array_merge($to, $cc, $bcc);
        }

        return new CapturedMail(
            identifier: basename($filePath),
            subject: (string)$message->getHeaderValue(HeaderConsts::SUBJECT),
            from: $this->firstAddress($message, HeaderConsts::FROM),
            to: $to,
            cc: $cc,
            bcc: $bcc,
            replyTo: $this->addresses($message, HeaderConsts::REPLY_TO),
            date: $this->parseDate($message),
            size: (int)filesize($filePath),
            hasHtmlPart: $htmlBody !== '',
            hasTextPart: $textBody !== '',
            context: $message->getHeaderValue(self::CONTEXT_HEADER),
            textBody: $textBody,
            htmlBody: $htmlBody,
            rawSource: $full ? (string)file_get_contents($filePath) : '',
            attachments: $attachments,
            headers: $headers,
            envelopeSender: $envelopeSender,
            envelopeRecipients: $envelopeRecipients,
        );
    }

    /**
     * The envelope FileTransport stored next to the mail, or null for a mail
     * captured without one (before 0.8.0) or a file placed there by hand.
     *
     * @return array{sender: string, recipients: list<string>}|null
     */
    private function readEnvelope(string $filePath): ?array
    {
        $envelopeFile = $filePath . FileTransport::ENVELOPE_SUFFIX;
        if (!is_file($envelopeFile)) {
            return null;
        }
        $raw = @file_get_contents($envelopeFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_string($decoded['sender'] ?? null) || !is_array($decoded['recipients'] ?? null)) {
            return null;
        }

        return [
            'sender' => $decoded['sender'],
            'recipients' => array_values(array_filter($decoded['recipients'], static fn(mixed $recipient): bool => is_string($recipient))),
        ];
    }

    /**
     * @return string[]
     */
    private function addresses(IMessage $message, string $headerName): array
    {
        $header = $message->getHeader($headerName);
        if (!$header instanceof AddressHeader) {
            return [];
        }

        $addresses = [];
        foreach ($header->getAddresses() as $address) {
            $name = $address->getName();
            $email = $address->getEmail();
            $addresses[] = $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
        }

        return $addresses;
    }

    private function firstAddress(IMessage $message, string $headerName): string
    {
        return $this->addresses($message, $headerName)[0] ?? '';
    }

    private function parseDate(IMessage $message): ?\DateTimeImmutable
    {
        $rawDate = $message->getHeaderValue(HeaderConsts::DATE);
        if (!is_string($rawDate) || $rawDate === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($rawDate);
        } catch (\Exception) {
            return null;
        }
    }
}
