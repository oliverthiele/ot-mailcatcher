<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use OliverThiele\OtMailcatcher\Check\MailAddressHelper;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Mail\FileTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Delivers captured mails after the fact.
 *
 * The reason this exists: switching the catcher on for a live incident is only
 * defensible because the mails are not lost. Without a way to send them, "not
 * lost" means "sits on disk as a file somebody has to deal with by hand", which
 * is not the same promise.
 *
 * The intended order is switch off, delete the test and debug mails, then send
 * what is left. Sending is therefore refused while the catcher is still active —
 * the mails would go straight back into it.
 */
final class ResendService
{
    private const SENT_DIRECTORY_NAME = 'sent';

    /**
     * How many failures in a row end a bulk run.
     *
     * A mail relay that refuses the fourth message will refuse the fortieth too,
     * and hammering it is how an account gets throttled or blocked. Stopping
     * early leaves everything unsent still in place, to retry once the cause is
     * known — individually, if the limit is per message.
     */
    private const MAXIMUM_CONSECUTIVE_FAILURES = 3;

    public function __construct(
        private readonly CapturedMailRepository $capturedMailRepository,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * Sends every captured mail and moves the ones that made it out into a
     * "sent" subdirectory — moved rather than deleted, so a delivery can still
     * be traced afterwards, and so a failure never destroys the only copy.
     *
     * @param int|null $limit Stop after this many mails. Null sends everything.
     * @return array{sent: int, failed: int, errors: string[], stoppedEarly: bool}
     */
    public function resendAll(?int $limit = null): array
    {
        self::assertResendPossible();

        $sent = 0;
        $failed = 0;
        $errors = [];
        $consecutiveFailures = 0;
        $stoppedEarly = false;

        foreach ($this->capturedMailRepository->findAll() as $mail) {
            if ($consecutiveFailures >= self::MAXIMUM_CONSECUTIVE_FAILURES) {
                $stoppedEarly = true;
                break;
            }

            if ($limit !== null && ($sent + $failed) >= $limit) {
                break;
            }

            $error = $this->resendOne($mail->identifier);
            if ($error === null) {
                $sent++;
                $consecutiveFailures = 0;
                continue;
            }

            $failed++;
            $consecutiveFailures++;
            $errors[] = $error;
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
            'stoppedEarly' => $stoppedEarly,
        ];
    }

    /**
     * What a run would do, without doing it.
     *
     * The external recipient count is the number that matters before pressing
     * anything: a staging system cloned from live holds real customer addresses,
     * and delivering to them is the mistake this reports before it happens.
     *
     * @param int|null $limit
     * @return array{mails: int, recipients: int, external: int, externalDistinct: int, externalAddresses: string[]}
     */
    public function describePending(?int $limit = null): array
    {
        $ownDomain = MailAddressHelper::getDefaultSenderDomain();
        $mails = 0;
        $recipients = 0;
        $externalOccurrences = 0;
        $externalAddresses = [];

        foreach ($this->capturedMailRepository->findAll() as $mail) {
            if ($limit !== null && $mails >= $limit) {
                break;
            }
            $mails++;

            $full = $this->capturedMailRepository->findByIdentifier($mail->identifier);
            if ($full === null) {
                continue;
            }

            foreach ($full->getDeliveryRecipients() as $address) {
                $recipients++;
                $bare = MailAddressHelper::extractAddress($address);
                if (!MailAddressHelper::belongsToDomain(MailAddressHelper::extractDomain($address), $ownDomain)) {
                    // Counted twice on purpose: how many deliveries leave the
                    // site, and how many different people receive them. Reporting
                    // only the distinct addresses against the total recipients
                    // compares two different things, and reads as if the rest
                    // stayed internal.
                    $externalOccurrences++;
                    $externalAddresses[$bare] = $bare;
                }
            }
        }

        return [
            'mails' => $mails,
            'recipients' => $recipients,
            'external' => $externalOccurrences,
            'externalDistinct' => count($externalAddresses),
            'externalAddresses' => array_values($externalAddresses),
        ];
    }

    /**
     * Sends one captured mail. Returns null on success, or the reason it failed.
     *
     * Public because the module offers this per mail as well: with a long list
     * and a host that limits how much may go out at once, sending everything in
     * one run is the wrong tool.
     */
    public function resendOne(string $identifier): ?string
    {
        self::assertResendPossible();

        $mail = $this->capturedMailRepository->findByIdentifier($identifier);
        if ($mail === null || $mail->rawSource === '') {
            return sprintf('%s: could not be read', $identifier);
        }

        // The stored envelope, so Bcc recipients and a rewritten envelope are
        // honoured — see FileTransport.
        $recipients = $this->toAddresses($mail->getDeliveryRecipients());
        $sender = $this->toAddresses([$mail->getDeliverySender()])[0] ?? null;

        if ($sender === null || $recipients === []) {
            return sprintf('%s: no usable sender or recipient', $identifier);
        }

        try {
            // The raw source is sent unchanged apart from the debugging header,
            // so the message keeps its original headers — including Date, which
            // therefore shows when the mail was captured rather than when it was
            // delivered.
            $this->mailer->send(
                new RawMessage(self::removeHeader($mail->rawSource, CapturedMailRepository::CONTEXT_HEADER)),
                new Envelope($sender, $recipients)
            );
        } catch (\Throwable $exception) {
            return sprintf('%s: %s', $identifier, $exception->getMessage());
        }

        if (!$this->moveToSent($identifier, MailcatcherState::getStorageDirectory() . '/' . self::SENT_DIRECTORY_NAME)) {
            // Delivered, but the file stayed put. Reported as a failure on
            // purpose: a second run would deliver it twice.
            return sprintf('%s: delivered, but could not be moved out of the way', $identifier);
        }

        return null;
    }

    /**
     * @param string[] $addresses
     * @return Address[]
     */
    private function toAddresses(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            try {
                $result[] = Address::create($address);
            } catch (\Throwable) {
                // An address the parser cannot make sense of is skipped rather
                // than failing the whole mail; the remaining recipients still get it.
                continue;
            }
        }

        return $result;
    }

    private function moveToSent(string $identifier, string $sentDirectory): bool
    {
        if (!is_dir($sentDirectory) && !@mkdir($sentDirectory, 0775, true) && !is_dir($sentDirectory)) {
            return false;
        }

        $source = MailcatcherState::getStorageDirectory() . '/' . $identifier;
        if (!is_file($source) || !rename($source, $sentDirectory . '/' . $identifier)) {
            return false;
        }
        $envelopeFile = $source . FileTransport::ENVELOPE_SUFFIX;
        if (is_file($envelopeFile)) {
            @rename($envelopeFile, $sentDirectory . '/' . $identifier . FileTransport::ENVELOPE_SUFFIX);
        }

        return true;
    }

    /**
     * Refuses while sending would not reach anybody: with the catcher switched
     * on, or with the transport still pointing at it although it is off — then
     * the mail would be captured again and reported as sent.
     */
    private static function assertResendPossible(): void
    {
        if (MailcatcherState::isEnabled()) {
            throw new \RuntimeException(
                'Refusing to resend while the mail catcher is switched on — the mails would be captured again. '
                . 'Switch it off first.',
                1756080002
            );
        }
        if (MailcatcherState::isWired()) {
            throw new \RuntimeException(
                'Refusing to resend: the mail transport still points at the mail catcher, so the mails would be '
                . 'captured again. Remove the transport assignment from config/system/additional.php first.',
                1790900003
            );
        }
    }

    /**
     * Removes one header, folded continuation lines included, from the header
     * block of a raw message. The body is left untouched.
     */
    public static function removeHeader(string $rawSource, string $headerName): string
    {
        $separator = str_contains($rawSource, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
        $parts = explode($separator, $rawSource, 2);
        $lineBreak = $separator === "\r\n\r\n" ? "\r\n" : "\n";
        $kept = [];
        $skipping = false;
        foreach (explode($lineBreak, $parts[0]) as $line) {
            if ($skipping && ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))) {
                continue;
            }
            $skipping = stripos($line, $headerName . ':') === 0;
            if (!$skipping) {
                $kept[] = $line;
            }
        }

        return implode($lineBreak, $kept) . (isset($parts[1]) ? $separator . $parts[1] : '');
    }
}
