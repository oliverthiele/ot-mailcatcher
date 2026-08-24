<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Domain\Repository;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedAttachment;
use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;
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

        return $filePath !== null && unlink($filePath);
    }

    public function deleteAll(): int
    {
        $deleted = 0;
        foreach ($this->listFiles() as $filePath) {
            if (unlink($filePath)) {
                $deleted++;
            }
        }

        return $deleted;
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
            'mimeType' => $part->getContentType() ?? 'application/octet-stream',
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
                $headers[] = ['name' => $header->getName(), 'value' => $header->getValue() ?? ''];
            }
        }

        $attachments = [];
        if ($full) {
            foreach ($message->getAllAttachmentParts() as $index => $part) {
                $attachments[] = new CapturedAttachment(
                    (int)$index,
                    $part->getFilename() ?? ('attachment-' . $index),
                    $part->getContentType() ?? 'application/octet-stream',
                    strlen((string)$part->getContent()),
                );
            }
        }

        return new CapturedMail(
            identifier: basename($filePath),
            subject: (string)$message->getHeaderValue(HeaderConsts::SUBJECT),
            from: $this->firstAddress($message, HeaderConsts::FROM),
            to: $this->addresses($message, HeaderConsts::TO),
            cc: $this->addresses($message, HeaderConsts::CC),
            bcc: $this->addresses($message, HeaderConsts::BCC),
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
        );
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
