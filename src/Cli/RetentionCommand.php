<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Api\ApiLog;
use Atwx\Flow\Retention\Retention;
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
 * ApiLog.retention_days and the due records of the automatic deletion rules
 * (Retention.rules with automatic: true). Run it daily (cron). Extension
 * hook: updateRetention.
 */
#[AsCommand(
    name: 'flow:retention',
    description: 'Delete data whose retention period has passed (API log, automatic deletion rules)'
)]
class RetentionCommand extends Command
{
    use Configurable;
    use Extensible;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('API log: %d entries deleted', ApiLog::prune()));
        $retention = Retention::singleton();
        foreach ($retention->rules() as $rule) {
            if (!$rule->isAutomatic()) {
                continue;
            }
            $run = $retention->run($rule, null, true);
            $output->writeln(sprintf(
                '%s: %d deleted, %d kept, %d failed',
                $rule->getTitle(),
                $run->Deleted,
                $run->Kept,
                $run->Failed
            ));
        }
        $this->extend('updateRetention', $output);
        return Command::SUCCESS;
    }
}
