<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Command;

use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\DeployerService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\HttpService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\SecurityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:install',
    description: 'Install a new GitLab repository for CI/CD deployment'
)]
class InstallCommand extends Command
{
    public function __construct(
        private ConfigService $config,
        private SecurityService $security,
        private DeployerService $deployer,
        private LoggerService $logger,
        private HttpService $http
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('git-url', InputArgument::OPTIONAL, 'Git repository URL')
            ->addArgument('local-path', InputArgument::OPTIONAL, 'Local path for the repository')
            ->addArgument('branch', InputArgument::OPTIONAL, 'Git branch', 'main')
            ->addArgument('type', InputArgument::OPTIONAL, 'Project type', 'symfony-webpack')
            ->setHelp('This command allows you to install a new GitLab repository for CI/CD deployment...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gitUrl = $input->getArgument('git-url');
        $localPath = $input->getArgument('local-path');
        $branch = $input->getArgument('branch');
        $type = $input->getArgument('type');

        if (!$gitUrl || !$localPath) {
            return $this->interactiveInstall($io);
        }

        return $this->installRepository($io, $gitUrl, $localPath, $branch, $type);
    }

    private function interactiveInstall(SymfonyStyle $io): int
    {
        $io->title('GitLab Repository Installation');

        $gitUrlQuestion = new Question('Git repository URL: ');
        $gitUrl = $io->askQuestion($gitUrlQuestion);

        if (empty($gitUrl)) {
            $io->error('Git URL is required');
            return Command::FAILURE;
        }

        preg_match('/\/([^\/]+)\.git$/', $gitUrl, $matches);
        $defaultName = $matches[1] ?? 'project';
        $folders = explode('/', trim(dirname(__DIR__), "/"));
        array_pop($folders);
        array_pop($folders);
        array_push($folders, $defaultName);
        $defaultLocalPath =  "/".implode('/', $folders);

        $localPathQuestion = new Question('Local path: ', $defaultLocalPath);
        $localPath = $io->askQuestion($localPathQuestion);;

        $branchQuestion = new Question('Branch: ', 'main');
        $branch = $io->askQuestion($branchQuestion);

        $typeQuestion = new ChoiceQuestion(
            'Select project type:',
            [
                'symfony-webpack' => 'Symfony with Webpack/Encore (npm build)',
                'symfony-asset-mapper' => 'Symfony with AssetMapper (asset:map compile)',
                'symfony-api' => 'Symfony API only (no frontend compilation)',
                'simple' => 'Simple deployment (git pull only)'
            ],
            'symfony-webpack'
        );
        $type = $io->askQuestion($typeQuestion);

        $io->section('Configuration Summary');
        $io->definitionList(
            ['Git URL' => $gitUrl],
            ['Local path' => $localPath],
            ['Branch' => $branch],
            ['Type' => $type]
        );

        $confirmQuestion = new ConfirmationQuestion('Proceed with installation?', true);
        if (!$io->askQuestion($confirmQuestion)) {
            $io->info('Installation cancelled.');
            return Command::SUCCESS;
        }

        return $this->installRepository($io, $gitUrl, $localPath, $branch, $type);
    }

    private function installRepository(SymfonyStyle $io, string $gitUrl, string $localPath, string $branch, string $type): int
    {
        preg_match('/\/([^\/]+)\.git$/', $gitUrl, $matches);
        $projectName = $matches[1] ?? basename($localPath);

        if ($this->config->hasRepository($projectName)) {
            $io->error("Repository '{$projectName}' already exists");
            return Command::FAILURE;
        }

        $io->section('Installing Repository');
        $io->definitionList(
            ['Name' => $projectName],
            ['Git URL' => $gitUrl],
            ['Local path' => $localPath],
            ['Branch' => $branch],
            ['Type' => $type]
        );

        $io->info('Cloning repository...');
        if (!$this->deployer->cloneRepository($gitUrl, $localPath, $branch)) {
            $io->error('Failed to clone repository');
            return Command::FAILURE;
        }

        $io->info('Generating security tokens...');
        $webhookToken = $this->security->generateToken();

        $io->info('Creating deployment file...');
        $this->createDeploymentFile($projectName, $type);

        $repoConfig = [
            'name' => $projectName,
            'git_url' => $gitUrl,
            'local_path' => $localPath,
            'branch' => $branch,
            'type' => $type,
            'webhook_token' => $webhookToken,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->config->addRepository($repoConfig);

        $io->success('Repository installed successfully!');

        $deployKeyPath = "/home/{$_SERVER['USER']}/.ssh/{$projectName}_deploy";
        $sshConfigHost = $this->extractGitLabHost($gitUrl) . "-{$projectName}";
        
        $io->section('GitLab Configuration Required');
        
        $io->writeln('1. <info>Deploy Key Setup</info>');
        
        $confirmKeyGen = $io->confirm('Are you install deploy Key in your project GitLab?', true);
        if ($confirmKeyGen) {
        } else {
            $io->writeln('   Generate an SSH key pair manually:');
            $io->writeln("   <comment>ssh-keygen -t ed25519 -f {$deployKeyPath} -N ''</comment>");

            $io->writeln('   ');
            $io->writeln('   Add the public key to GitLab:');
            $io->writeln('   - Go to Project → Settings → Repository → Deploy Keys');
            $io->writeln("   - Paste the content of {$deployKeyPath}.pub");
            $io->writeln('   - Check "Write access enabled" if needed for pushes');
            $io->writeln('   ');
        }

        $io->writeln('   ');
        
        $io->writeln('2. <info>Webhook Configuration</info>');
        $io->definitionList(
            ['Token' => $webhookToken],
            ['Events' => 'Push events'],
            ['SSL Verification' => 'Enable if using HTTPS']
        );
        
        $io->writeln('   Configure in GitLab:');
        $io->writeln('   - Go to Project → Settings → Integrations → Webhooks');
        $io->writeln('   - Add the URL and token above');
        $io->writeln('   - Select "Push events"');
        $io->writeln('   - Test the webhook after configuration');

        $io->note([
            'Complete both Deploy Key and Webhook setup for full functionality.',
            'The deploy key enables secure repository access.',
            'The webhook triggers automatic deployments on push.'
        ]);

        return Command::SUCCESS;
    }

    private function createDeploymentFile(string $repoName, string $type): void
    {
        $repoDir = dirname(__DIR__, 2) . "/repositories/{$repoName}";
        if (!is_dir($repoDir)) {
            mkdir($repoDir, 0755, true);
        }

        $deploymentFile = $repoDir . '/deployment.php';

        switch ($type) {
            case 'symfony-asset-mapper':
                $template = $this->getAssetMapperTemplate();
                break;
            case 'symfony-api':
                $template = $this->getSymfonyApiTemplate();
                break;
            case 'simple':
                $template = $this->getSimpleTemplate();
                break;
            default:
                $template = $this->getWebpackTemplate();
        }

        file_put_contents($deploymentFile, $template);
    }

    private function getWebpackTemplate(): string
    {
        return '<?php

use Gbonnaire\\PhpGitlabCicdWebhook\\Service\\Deployment\\SymfonyWebpackDeploymentService;

return function(array $repository, $logger) {
    try {
        $deployer = new SymfonyWebpackDeploymentService($repository, $logger);
        return $deployer->up();
    } catch (Exception $e) {
        return [\'success\' => false, \'error\' => $e->getMessage()];
    }
};
';
    }

    private function getAssetMapperTemplate(): string
    {
        return '<?php

use Gbonnaire\\PhpGitlabCicdWebhook\\Service\\Deployment\\SymfonyAssetMapperDeploymentService;

return function(array $repository, $logger) {
    try {
        $deployer = new SymfonyAssetMapperDeploymentService($repository, $logger);
        return $deployer->up();
    } catch (Exception $e) {
        return [\'success\' => false, \'error\' => $e->getMessage()];
    }
};
';
    }

    private function getSymfonyApiTemplate(): string
    {
        return '<?php

use Gbonnaire\\PhpGitlabCicdWebhook\\Service\\Deployment\\SymfonyApiDeploymentService;

return function(array $repository, $logger) {
    try {
        $deployer = new SymfonyApiDeploymentService($repository, $logger);
        return $deployer->up();
    } catch (Exception $e) {
        return [\'success\' => false, \'error\' => $e->getMessage()];
    }
};
';
    }

    private function getSimpleTemplate(): string
    {
        return '<?php

use Gbonnaire\\PhpGitlabCicdWebhook\\Service\\Deployment\\SimpleDeploymentService;

return function(array $repository, $logger) {
    try {
        $deployer = new SimpleDeploymentService($repository, $logger);
        return $deployer->up();
    } catch (Exception $e) {
        return [\'success\' => false, \'error\' => $e->getMessage()];
    }
};
';
    }

    private function extractGitLabHost(string $gitUrl): string
    {
        if (preg_match('/^(?:https?:\/\/|git@)([^\/]+)/', $gitUrl, $matches)) {
            return $matches[1];
        }
        return 'gitlab.com';
    }

    private function extractRepoPath(string $gitUrl): string
    {
        if (preg_match('/[\/:]([^\/]+\/[^\/]+)(?:\.git)?$/', $gitUrl, $matches)) {
            return $matches[1] . '.git';
        }
        return '';
    }
}