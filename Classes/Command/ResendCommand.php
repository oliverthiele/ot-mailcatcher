<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Command;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use OliverThiele\OtMailcatcher\Service\ResendService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Delivers captured mails from the command line.
 *
 * Bulk delivery lives here rather than in the backend module on purpose. One
 * click for an unbounded, irreversible send is the wrong shape for the decision
 * it represents: in a real incident the mails deserve to be judged one by one —
 * a three-day-old password reset should be deleted, the order confirmation next
 * to it delivered. The module therefore offers only per-mail sending, and this
 * command covers the case where the list is too long for that.
 *
 * Being a command also makes it schedulable and throttleable, which is what a
 * host with a sending limit needs.
 */
final class ResendCommand extends Command
{
    public function __construct(
        private readonly ResendService $resendService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Send at most this many mails in one run.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be sent.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_REQUIRED,
                'Required outside development: pass the number of external recipients reported by --dry-run.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputOutput = new SymfonyStyle($input, $output);

        if (MailcatcherState::isEnabled()) {
            $inputOutput->error(
                'The mail catcher is switched on — the mails would be captured again. Switch it off first.'
            );
            return Command::INVALID;
        }

        $rawLimit = $input->getOption('limit');
        $limit = is_numeric($rawLimit) ? (int)$rawLimit : null;
        if ($limit !== null && $limit < 1) {
            $inputOutput->error('The limit must be at least one.');
            return Command::INVALID;
        }

        $pending = $this->resendService->describePending($limit);
        if ($pending['mails'] === 0) {
            $inputOutput->success('Nothing to send.');
            return Command::SUCCESS;
        }

        $inputOutput->section('About to send');
        $inputOutput->listing([
            sprintf('%d mail(s)', $pending['mails']),
            sprintf('%d recipient(s), %d of them outside this site', $pending['recipients'], $pending['external']),
        ]);

        // The addresses matter more than the count: a staging system cloned from
        // live holds real customer addresses, and seeing them is what stops
        // somebody from delivering test mail to actual people.
        if ($pending['external'] > 0) {
            $inputOutput->warning('External recipients: ' . implode(', ', array_slice($pending['externalAddresses'], 0, 20)));
        }

        if ($input->getOption('dry-run') === true) {
            $inputOutput->note('Dry run — nothing was sent.');
            return Command::SUCCESS;
        }

        // Outside development the confirmation is the external recipient count,
        // not a plain yes. A number that has to be read off a dry run cannot be
        // supplied by reflex, and it changes when the list does.
        if (!Environment::getContext()->isDevelopment()) {
            $confirmation = $input->getOption('force');
            $confirmed = is_scalar($confirmation) ? (string)$confirmation : '';
            if ($confirmed !== (string)$pending['external']) {
                $inputOutput->error(sprintf(
                    'Refusing to send in the "%s" context without confirmation. '
                    . 'Run with --dry-run first, then repeat with --force=%d.',
                    (string)Environment::getContext(),
                    $pending['external']
                ));
                return Command::INVALID;
            }
        }

        $result = $this->resendService->resendAll($limit);

        if ($result['sent'] > 0) {
            $inputOutput->success(sprintf('Sent %d mail(s).', $result['sent']));
        }

        if ($result['failed'] > 0) {
            $inputOutput->error(sprintf('%d mail(s) could not be sent and are still here.', $result['failed']));
            $inputOutput->listing(array_slice($result['errors'], 0, 10));

            if ($result['stoppedEarly']) {
                $inputOutput->note(
                    'Stopped after three failures in a row. A host that refuses one mail usually refuses '
                    . 'the next, and retrying in bulk risks the account being throttled.'
                );
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
