<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Api\ApiLog;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * sake flow:retention
 *
 * Deletes what is kept only for a time: API log entries older than
 * ApiLog.retention_days. Run it daily (cron). Extension hook: updateRetention.
 */
#[AsCommand(name: 'flow:retention', description: 'Delete data whose retention period has passed (API log)')]
class RetentionCommand extends Command
{
    use Configurable;
    use Extensible;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('API log: %d entries deleted', ApiLog::prune()));
        $this->extend('updateRetention', $output);
        return Command::SUCCESS;
    }
}
