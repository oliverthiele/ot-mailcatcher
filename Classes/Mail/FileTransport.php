<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Mail;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes every outgoing message to its own .eml file instead of sending it.
 *
 * One file per message on purpose. TYPO3's own mbox transport appends all
 * messages to a single file without an mbox separator line, which leaves no
 * reliable boundary to split them again — two mails sent within the same request
 * can then no longer be told apart. Writing separate files removes that problem
 * instead of working around it.
 *
 * Next to every .eml file it stores the envelope as JSON: the sender and every
 * recipient the mail was really addressed to. The .eml alone cannot hold that —
 * Symfony removes the Bcc header before a message is serialised, and an event
 * listener may have rewritten the envelope. Sending a captured mail later needs
 * exactly that information.
 *
 * Registered through $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'], which
 * TransportFactory resolves as a class name in its default branch — see
 * MailcatcherState::wireMailTransport() for what else has to be cleared.
 *
 * @see \TYPO3\CMS\Core\Mail\TransportFactory::get()
 */
class FileTransport extends AbstractTransport
{
    public const ENVELOPE_SUFFIX = '.envelope.json';

    /**
     * @param array<string, mixed> $mailSettings Passed by TransportFactory and
     *        deliberately unused — the storage directory is fixed, because the
     *        module, the API and the commands read from that one place.
     */
    public function __construct(
        array $mailSettings = [],
        ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($eventDispatcher, $logger);
        // No artificial throttling — nothing leaves the machine.
        $this->setMaxPerSecond(0);
    }

    protected function doSend(SentMessage $message): void
    {
        $targetDirectory = MailcatcherState::getStorageDirectory();
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException(
                sprintf('Could not create the mailcatcher directory "%s".', $targetDirectory),
                1755691201
            );
        }

        $envelope = $message->getEnvelope();
        $fileName = $this->buildFileName();
        $mailFile = $targetDirectory . '/' . $fileName;

        // The envelope first: once the .eml appears, everything that lists or
        // resends it finds its envelope next to it.
        $this->writeAtomically(
            $mailFile . self::ENVELOPE_SUFFIX,
            json_encode([
                'sender' => $envelope->getSender()->getAddress(),
                'recipients' => array_map(static fn($address): string => $address->getAddress(), $envelope->getRecipients()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
        );
        $this->writeAtomically($mailFile, $message->toString());
    }

    public function __toString(): string
    {
        return 'mailcatcher://' . MailcatcherState::getStorageDirectory();
    }

    /**
     * A sortable timestamp and 16 random hex digits. uniqid() is only unique
     * within one process; two PHP workers capturing a mail in the same
     * microsecond produced the same name, and the second write replaced the
     * first mail.
     */
    private function buildFileName(): string
    {
        return date('Y-m-d_His') . '-' . bin2hex(random_bytes(8)) . '.eml';
    }

    /**
     * Writes to a temporary name that does not end in .eml and renames it into
     * place, so a list request never parses a half-written mail.
     */
    private function writeAtomically(string $targetFile, string $contents): void
    {
        $temporaryFile = dirname($targetFile) . '/.' . basename($targetFile) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporaryFile, $contents) === false || !@rename($temporaryFile, $targetFile)) {
            @unlink($temporaryFile);
            throw new \RuntimeException(
                sprintf('Could not write the captured mail to "%s".', $targetFile),
                1755691202
            );
        }

        GeneralUtility::fixPermissions($targetFile);
    }
}
