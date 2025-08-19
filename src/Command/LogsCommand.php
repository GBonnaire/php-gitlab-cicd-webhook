<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:logs',
    description: 'Show logs for a repository or global logs'
)]
class LogsCommand extends Command
{
    public function __construct(
        private LoggerService $logger
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Repository name (default: global)', 'global')
            ->addArgument('lines', InputArgument::OPTIONAL, 'Number of lines to show', 50)
            ->setHelp('This command allows you to view logs...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $lines = (int)$input->getArgument('lines');

        $logs = $this->logger->getLogs($name, $lines);

        if (empty($logs)) {
            $io->info("No logs found for: {$name}");
            return Command::SUCCESS;
        }

        $io->section("Last {$lines} log entries for: {$name}");

        foreach ($logs as $line) {
            $io->writeln($line);
        }

        return Command::SUCCESS;
    }
}