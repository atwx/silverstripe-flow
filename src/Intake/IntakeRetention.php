<?php

namespace Atwx\Flow\Intake;

use SilverStripe\Core\Extension;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds the inbox to sake flow:retention: deletes submissions (and their
 * files) whose retention period has passed.
 *
 * @extends Extension<\Atwx\Flow\Cli\RetentionCommand>
 */
class IntakeRetention extends Extension
{
    protected function updateRetention(OutputInterface $output): void
    {
        $output->writeln(sprintf('Inbox: %d submissions deleted', IntakeService::singleton()->applyRetention()));
    }
}
