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
 * Registered through $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'], which
 * TransportFactory resolves as a class name in its default branch.
 *
 * @see \TYPO3\CMS\Core\Mail\TransportFactory::get()
 */
class FileTransport extends AbstractTransport
{
    /**
     * @param array<string, mixed> $mailSettings
     */
    public function __construct(
        private readonly array $mailSettings = [],
        ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($eventDispatcher, $logger);
        // No artificial throttling — nothing leaves the machine.
        $this->setMaxPerSecond(0);
    }

    protected function doSend(SentMessage $message): void
    {
        $targetDirectory = $this->resolveTargetDirectory();
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException(
                sprintf('Could not create the mailcatcher directory "%s".', $targetDirectory),
                1755691201
            );
        }

        $targetFile = $targetDirectory . '/' . $this->buildFileName();

        if (file_put_contents($targetFile, $message->toString()) === false) {
            throw new \RuntimeException(
                sprintf('Could not write the captured mail to "%s".', $targetFile),
                1755691202
            );
        }

        GeneralUtility::fixPermissions($targetFile);
    }

    public function __toString(): string
    {
        return 'mailcatcher://' . $this->resolveTargetDirectory();
    }

    /**
     * uniqid() rather than a counter: two messages sent within the same request
     * land in the same second, and the timestamp alone would collide.
     */
    private function buildFileName(): string
    {
        return date('Y-m-d_His') . '-' . uniqid('', false) . '.eml';
    }

    private function resolveTargetDirectory(): string
    {
        $configuredDirectory = $this->mailSettings['transport_file_directory'] ?? null;
        if (is_string($configuredDirectory) && $configuredDirectory !== '') {
            return rtrim($configuredDirectory, '/');
        }

        return MailcatcherState::getStorageDirectory();
    }
}
