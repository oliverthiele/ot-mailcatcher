<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\Mailer;

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

    public function __construct(
        private readonly CapturedMailRepository $capturedMailRepository,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * Sends every captured mail and moves the ones that made it out into a
     * "sent" subdirectory — moved rather than deleted, so a delivery can still
     * be traced afterwards, and so a failure never destroys the only copy.
     *
     * @return array{sent: int, failed: int, errors: string[]}
     */
    public function resendAll(): array
    {
        if (MailcatcherState::isEnabled()) {
            throw new \RuntimeException(
                'Refusing to resend while the mail catcher is switched on — the mails would be captured again. '
                . 'Switch it off first.',
                1756080002
            );
        }

        $sentDirectory = MailcatcherState::getStorageDirectory() . '/' . self::SENT_DIRECTORY_NAME;
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->capturedMailRepository->findAll() as $mail) {
            $fullMail = $this->capturedMailRepository->findByIdentifier($mail->identifier);
            if ($fullMail === null || $fullMail->rawSource === '') {
                $failed++;
                $errors[] = sprintf('%s: could not be read', $mail->identifier);
                continue;
            }

            $recipients = $this->toAddresses(array_merge($fullMail->to, $fullMail->cc, $fullMail->bcc));
            $sender = $this->toAddresses([$fullMail->from])[0] ?? null;

            if ($sender === null || $recipients === []) {
                $failed++;
                $errors[] = sprintf('%s: no usable sender or recipient', $mail->identifier);
                continue;
            }

            try {
                // The raw source is sent unchanged, so the message keeps its
                // original headers — including Date, which therefore shows when
                // the mail was captured rather than when it was delivered.
                $this->mailer->send(
                    new RawMessage($fullMail->rawSource),
                    new Envelope($sender, $recipients)
                );
            } catch (\Throwable $exception) {
                $failed++;
                $errors[] = sprintf('%s: %s', $mail->identifier, $exception->getMessage());
                continue;
            }

            if ($this->moveToSent($mail->identifier, $sentDirectory)) {
                $sent++;
            } else {
                // Delivered, but the file stayed put. Reported as a failure on
                // purpose: a second run would deliver it twice, and that is worth
                // knowing about.
                $failed++;
                $errors[] = sprintf('%s: delivered, but could not be moved out of the way', $mail->identifier);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
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
        if (!is_dir($sentDirectory) && !mkdir($sentDirectory, 0775, true) && !is_dir($sentDirectory)) {
            return false;
        }

        $source = MailcatcherState::getStorageDirectory() . '/' . $identifier;

        return is_file($source) && rename($source, $sentDirectory . '/' . $identifier);
    }
}
