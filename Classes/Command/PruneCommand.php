<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Command;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted.');
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
