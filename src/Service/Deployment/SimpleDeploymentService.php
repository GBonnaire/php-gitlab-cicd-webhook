<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service\Deployment;

use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;

class SimpleDeploymentService extends BaseDeploymentService
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
            }
        ];
    }

    public function deploy(): array
    {
        return $this->up();
    }
}