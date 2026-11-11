<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Config\ConfigException;
use Atwx\Flow\Config\ConfigImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * sake flow:import-config <file>
 */
#[AsCommand(name: 'flow:import-config', description: 'Import workflow configuration from a YAML file')]
class ImportConfigCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the YAML file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        try {
            $stats = ConfigImporter::create()->importFile($file);
        } catch (ConfigException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($stats === []) {
            $output->writeln('Nothing changed.');
        }
        foreach ($stats as $type => $counts) {
            $output->writeln(sprintf('%s: %d created, %d updated', $type, $counts['created'], $counts['updated']));
        }
        return Command::SUCCESS;
    }
}
