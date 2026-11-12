<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Service\FollowUpDigest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * sake flow:followup-digest [--dry-run] [--force]
 *
 * Mails every member the follow-ups that are due; run once a day by cron.
 */
#[AsCommand(name: 'flow:followup-digest', description: 'Mail every member the follow-ups that are due')]
class FollowUpDigestCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report who would get a mail');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Also to members who already got it today');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = FollowUpDigest::singleton()->send(
            null,
            (bool) $input->getOption('dry-run'),
            (bool) $input->getOption('force')
        );
        foreach ($report as $line) {
            $output->writeln($line);
        }
        $output->writeln(sprintf('%d members with due follow-ups', count($report)));
        return Command::SUCCESS;
    }
}
