<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Config\ConfigExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * sake flow:export-config [file] [--workflow=code]
 */
#[AsCommand(name: 'flow:export-config', description: 'Export workflow configuration as YAML')]
class ExportConfigCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Write to this file instead of the output');
        $this->addOption(
            'workflow',
            'w',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Only export the workflow with this code (repeatable)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $yaml = ConfigExporter::create()->toYaml((array) $input->getOption('workflow'));
        $file = $input->getArgument('file');
        if ($file) {
            if (file_put_contents((string) $file, $yaml) === false) {
                $output->writeln("<error>Cannot write {$file}</error>");
                return Command::FAILURE;
            }
            $output->writeln("Written to {$file}");
            return Command::SUCCESS;
        }
        $output->write($yaml, false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
