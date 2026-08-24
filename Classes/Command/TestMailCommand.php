<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Command;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Mail\MailMessage;

/**
 * Sends a pair of test mails in a single run.
 *
 * A pair rather than a single mail on purpose: EXT:form's EmailFinisher usually
 * sends a receiver notification and a sender confirmation within one request,
 * and that is exactly the case where a catcher storing everything in one file
 * loses a message. Two files must appear.
 */
final class TestMailCommand extends Command
{
    public function __construct(
        // Core\Mail\Mailer, not MailerInterface: v14 aliases the interface via
        // #[AsAlias], v13 does not — the concrete class resolves in both.
        private readonly Mailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'recipient',
            InputArgument::REQUIRED,
            'Recipient address. Use an address you own — if the catcher is off, these mails are really sent.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $recipient = $input->getArgument('recipient');
        if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $io->error('The recipient argument must be a valid email address.');
            return Command::INVALID;
        }

        if (MailcatcherState::isActive()) {
            $io->note(sprintf('Mailcatcher is active — mails are written to %s.', MailcatcherState::getStorageDirectory()));
        } else {
            $io->warning('Mailcatcher is NOT active — these mails will really be sent.');
        }

        $sentAt = date('Y-m-d H:i:s');

        foreach (['receiver' => 'Test mail to receiver', 'sender' => 'Test mail to sender'] as $role => $subject) {
            $message = new MailMessage();
            $message
                ->to($recipient)
                ->from($recipient)
                ->subject(sprintf('[%s] %s', $role, $subject))
                ->text(sprintf("Plain text part.\nRole: %s\nSent at: %s\n", $role, $sentAt))
                ->html(sprintf('<p>HTML part.</p><p>Role: %s<br>Sent at: %s</p>', $role, $sentAt));

            $this->mailer->send($message);
        }

        $io->success('Two test mails handed to the mail transport.');

        return Command::SUCCESS;
    }
}
