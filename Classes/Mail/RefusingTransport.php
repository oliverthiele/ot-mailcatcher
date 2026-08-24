<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Refuses to send. Used where the catcher is switched on but must not run.
 *
 * This is the safe direction, and it is the whole point. The switch says "no
 * mail leaves this system"; a process that cannot honour that must not quietly
 * fall back to real delivery. It happens when a process resolves a different
 * application context than the one the catcher was switched on in — most often
 * the command line, which does not inherit a context the web server passes
 * through fastcgi_param or SetEnv. Without this, a scheduler task would deliver
 * a bulk send to real recipients while the backend reports that nothing is
 * being sent.
 *
 * The exception is deliberate: loud and traceable beats silent and wrong.
 */
class RefusingTransport extends AbstractTransport
{
    /**
     * @param array<string, mixed> $mailSettings Passed by TransportFactory and
     *        deliberately unused — this transport has nothing to configure.
     */
    public function __construct(
        array $mailSettings = [],
        ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($eventDispatcher, $logger);
        $this->setMaxPerSecond(0);
    }

    protected function doSend(SentMessage $message): void
    {
        throw new \RuntimeException(
            sprintf(
                'Mailcatcher is switched on, so no mail may be sent — but it must not run in the "%s" context, '
                . 'so this mail cannot be captured either and was refused instead of delivered. '
                . 'Set MAILCATCHER_ALLOWED=1 in the .env loaded for this context to capture here too, '
                . 'or switch the catcher off in the Mailcatcher backend module.',
                (string)Environment::getContext()
            ),
            1756080001
        );
    }

    public function __toString(): string
    {
        return 'mailcatcher://refused';
    }
}
