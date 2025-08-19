<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment;

use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;

class SymfonyAssetMapperDeploymentService extends BaseDeploymentService
{
    protected function getUpSteps(): array
    {
        return [
            'git_pull' => function() {
                $result = $this->gitPull();
                if ($result['success']) {
                    $this->addRollbackStep('git_pull', fn() => $this->gitReset($this->currentCommit));
                }
                return $result;
            },
            'composer_install' => function() {
                $result = $this->composerInstall();
                if ($result['success']) {
                    $this->addRollbackStep('composer_install', fn() => $this->runCommand('composer install --no-dev --optimize-autoloader'));
                }
                return $result;
            },
            'asset_map_compile' => function() {
                $result = $this->assetMapCompile();
                if ($result['success']) {
                    $this->addRollbackStep('asset_map_compile', fn() => $this->assetMapCompile());
                }
                return $result;
            },
            'doctrine_migrate' => function() {
                $result = $this->doctrineMigrate();
                if ($result['success']) {
                    $this->addRollbackStep('doctrine_migrate', fn() => $this->runCommand('php bin/console doctrine:migrations:migrate prev --no-interaction'));
                }
                return $result;
            },
            'cache_clear' => function() {
                $result = $this->clearCache();
                if ($result['success']) {
                    $this->addRollbackStep('cache_clear', fn() => $this->clearCache());
                }
                return $result;
            }
        ];
    }

    public function deploy(): array
    {
        return $this->up();
    }
}