<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:list',
    description: 'List all configured repositories'
)]
class ListCommand extends Command
{
    public function __construct(
        private ConfigService $config
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repositories = $this->config->getAllRepositories();

        if (empty($repositories)) {
            $io->info('No repositories configured.');
            return Command::SUCCESS;
        }

        $io->title('Configured Repositories');

        $tableData = [];
        foreach ($repositories as $repo) {
            $tableData[] = [
                $repo['name'],
                $repo['local_path'],
                $repo['branch'],
                $repo['type'],
                $repo['created_at'] ?? 'N/A'
            ];
        }

        $io->table(
            ['Name', 'Local Path', 'Branch', 'Type', 'Created'],
            $tableData
        );

        return Command::SUCCESS;
    }
}