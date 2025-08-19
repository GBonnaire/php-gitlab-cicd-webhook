<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:remove',
    description: 'Remove a configured repository'
)]
class RemoveCommand extends Command
{
    public function __construct(
        private ConfigService $config
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Repository name to remove')
            ->setHelp('This command allows you to remove a configured repository...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');

        if (!$this->config->hasRepository($name)) {
            $io->error("Repository '{$name}' not found");
            return Command::FAILURE;
        }

        $this->config->removeRepository($name);

        $io->success("Repository '{$name}' removed successfully");
        $io->note('Local files were not deleted. Remove them manually if needed.');

        return Command::SUCCESS;
    }
}