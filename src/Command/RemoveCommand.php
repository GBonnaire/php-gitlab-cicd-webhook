<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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
            ->addArgument('name', InputArgument::OPTIONAL, 'Repository name to remove')
            ->setHelp('This command allows you to remove a configured repository...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $repositoryName = $input->getArgument('name');
        
        if (!$repositoryName) {
            return $this->selectRepository($io);
        }
        
        return $this->removeRepository($io, $repositoryName);
    }

    private function selectRepository(SymfonyStyle $io): int
    {
        $repositories = $this->config->getAllRepositories();
        
        if (empty($repositories)) {
            $io->error('No repositories configured. Use app:install to add repositories.');
            return Command::FAILURE;
        }
        
        $io->title('Remove Repository');
        
        $choices = [];
        foreach ($repositories as $name => $repo) {
            $choices[$name] = "{$name} ({$repo['type']}) - {$repo['branch']} - {$repo['local_path']}";
        }
        
        $question = new ChoiceQuestion(
            'Select repository to remove:',
            $choices
        );
        
        $selectedRepo = $io->askQuestion($question);
        
        return $this->removeRepository($io, $selectedRepo);
    }

    private function removeRepository(SymfonyStyle $io, string $repositoryName): int
    {
        if (!$this->config->hasRepository($repositoryName)) {
            $io->error("Repository '{$repositoryName}' not found.");
            return Command::FAILURE;
        }

        $repository = $this->config->getRepository($repositoryName);

        $io->section("Repository to Remove: {$repositoryName}");
        $io->definitionList(
            ['Name' => $repository['name']],
            ['Type' => $repository['type']],
            ['Git URL' => $repository['git_url']],
            ['Local path' => $repository['local_path']],
            ['Branch' => $repository['branch']],
            ['Created' => $repository['created_at'] ?? 'Unknown']
        );

        $io->warning([
            'This will remove the repository configuration.',
            'Local files will NOT be deleted automatically.',
            'You may want to manually clean up:'
        ]);
        
        $io->writeln("  - Local repository: <comment>{$repository['local_path']}</comment>");
        $io->writeln("  - SSH deploy key: <comment>~/.ssh/{$repositoryName}_deploy*</comment>");
        $io->writeln("  - SSH config entry for: <comment>gitlab.com-{$repositoryName}</comment>");
        $io->writeln("  - GitLab webhook configuration");

        $confirmQuestion = new ConfirmationQuestion(
            'Are you sure you want to remove this repository configuration?', 
            false
        );
        
        if ($io->askQuestion($confirmQuestion)) {
            $this->config->removeRepository($repositoryName);

            $this->deleteDirectory(dirname(__DIR__) . "/../repositories/{$repositoryName}" );

            $io->success("Repository '{$repositoryName}' removed successfully!");
            
            // Ask if user wants to delete the project directory completely
            $deleteProjectQuestion = new ConfirmationQuestion(
                "Do you also want to completely delete the project directory at '{$repository['local_path']}'? This action cannot be undone.", 
                false
            );
            
            if ($io->askQuestion($deleteProjectQuestion)) {
                if (is_dir($repository['local_path'])) {
                    $this->deleteDirectory($repository['local_path']);
                    $io->success("Project directory '{$repository['local_path']}' deleted successfully!");
                } else {
                    $io->warning("Project directory '{$repository['local_path']}' not found or already deleted.");
                }
            } else {
                $io->section('Manual Cleanup Required');
                $io->writeln('Remember to manually remove if needed:');
                $io->writeln("  <comment>rm -rf {$repository['local_path']}</comment>");
            }
            
            $io->writeln('  - Remove webhook from GitLab project settings');
            
        } else {
            $io->info('Operation cancelled.');
        }

        return Command::SUCCESS;
    }

    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }
}