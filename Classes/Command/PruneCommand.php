<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Command;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Deletes captured mails beyond a retention period.
 *
 * Without this the storage directory grows without bound — captured mails are
 * throwaway test data, but nothing removes them on its own.
 */
final class PruneCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Delete captured mails older than this many days.',
                (string)self::DEFAULT_RETENTION_DAYS
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Required in a Production context, where a captured mail may be real customer communication that was never delivered.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputOutput = new SymfonyStyle($input, $output);

        $rawDays = $input->getOption('days');
        $days = is_numeric($rawDays) ? (int)$rawDays : self::DEFAULT_RETENTION_DAYS;
        if ($days < 1) {
            $inputOutput->error('The retention period must be at least one day.');
            return Command::INVALID;
        }

        $isDryRun = $input->getOption('dry-run') === true;

        // On a live system the catcher is switched on for an incident, and what
        // it holds may be real mail that no recipient has seen yet. Deleting
        // that on a retention timer, from a scheduler task, is the quiet version
        // of losing customer communication — so it takes an explicit --force.
        if (!$isDryRun
            && Environment::getContext()->isProduction()
            && $input->getOption('force') !== true
        ) {
            $inputOutput->error(
                'Refusing to delete captured mails in a Production context without --force. '
                . 'Run with --dry-run to see what would go, or pass --force if that is intended.'
            );
            return Command::INVALID;
        }
        $threshold = time() - ($days * 86400);

        $files = glob(MailcatcherState::getStorageDirectory() . '/*.eml');
        if ($files === false) {
            $files = [];
        }

        $deleted = 0;
        foreach ($files as $file) {
            $modificationTime = filemtime($file);
            if ($modificationTime === false || $modificationTime >= $threshold) {
                continue;
            }

            if ($isDryRun || unlink($file)) {
                $deleted++;
            }
        }

        $inputOutput->success(sprintf(
            $isDryRun ? 'Would delete %d captured mails older than %d days.' : 'Deleted %d captured mails older than %d days.',
            $deleted,
            $days
        ));

        return Command::SUCCESS;
    }
}
