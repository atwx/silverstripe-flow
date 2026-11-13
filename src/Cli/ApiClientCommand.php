<?php

namespace Atwx\Flow\Cli;

use Atwx\Flow\Api\ApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * sake flow:api-client "<title>" --scope=intake:application [--scope=…] [--rate=60]
 * sake flow:api-client --renew=<id>
 * sake flow:api-client --list
 *
 * Creates an API client (or renews its key) and prints the key once.
 */
#[AsCommand(name: 'flow:api-client', description: 'Create an API client or renew its key; prints the key once')]
class ApiClientCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('title', InputArgument::OPTIONAL, 'Name of the calling system');
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scope(s)');
        $this->addOption(
            'rate',
            null,
            InputOption::VALUE_REQUIRED,
            'Requests per minute (0 = default, -1 = unlimited)',
            '0'
        );
        $this->addOption('renew', null, InputOption::VALUE_REQUIRED, 'ID of the client whose key is renewed');
        $this->addOption('list', null, InputOption::VALUE_NONE, 'List the clients');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list')) {
            foreach (ApiClient::get() as $client) {
                $output->writeln(sprintf(
                    '#%d %s [%s]%s',
                    $client->ID,
                    $client->Title,
                    implode(' ', $client->scopeList()),
                    $client->Active ? '' : ' (inactive)'
                ));
            }
            return Command::SUCCESS;
        }
        $renew = (int) $input->getOption('renew');
        if ($renew > 0) {
            $client = ApiClient::get()->byID($renew);
            if (!$client) {
                $output->writeln('<error>No client #' . $renew . '</error>');
                return Command::FAILURE;
            }
            $client->renewKey()->write();
            $output->writeln((string) $client->newKey());
            return Command::SUCCESS;
        }
        $title = trim((string) $input->getArgument('title'));
        $scopes = array_values(array_filter((array) $input->getOption('scope')));
        if ($title === '' || $scopes === []) {
            $output->writeln('<error>Title and at least one --scope are needed</error>');
            return Command::FAILURE;
        }
        [$client, $key] = ApiClient::createWithKey($title, $scopes, (int) $input->getOption('rate'));
        $output->writeln('Client #' . $client->ID . ' created. Key (shown only now):');
        $output->writeln($key);
        return Command::SUCCESS;
    }
}
