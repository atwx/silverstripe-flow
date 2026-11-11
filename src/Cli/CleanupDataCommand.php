<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\Workflow;
use SilverStripe\ORM\DataObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * sake flow:cleanup-data [--dry-run]
 *
 * Removes values of deleted custom fields from the case files (FlowData).
 */
#[AsCommand(name: 'flow:cleanup-data', description: 'Remove values of deleted custom fields from the case files')]
class CleanupDataCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be removed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        foreach (Workflow::get() as $workflow) {
            $class = (string) $workflow->CaseClass;
            if (!class_exists($class) || !DataObject::has_extension($class, CaseFileExtension::class)) {
                continue;
            }
            $codes = CustomField::get()->filter('WorkflowID', $workflow->ID)->column('Code');
            $cleaned = 0;
            foreach ($class::get()->exclude('FlowData', null) as $case) {
                /** @var DataObject&CaseFileExtension $case */
                $stale = array_diff(array_keys($case->flowData()), $codes);
                if ($stale === []) {
                    continue;
                }
                $cleaned++;
                $output->writeln(sprintf('%s #%d: %s', $class, $case->ID, implode(', ', $stale)));
                if (!$dryRun) {
                    foreach ($stale as $code) {
                        $case->setFlowValue((string) $code, null);
                    }
                    $case->write();
                }
            }
            $verb = $dryRun ? 'to clean' : 'cleaned';
            $output->writeln(sprintf('%s: %d case files %s', $workflow->Code, $cleaned, $verb));
        }
        return Command::SUCCESS;
    }
}
