<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service;

class SecurityService
{
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function verifyWebhook(string $providedToken, array $repositories): bool
    {
        foreach ($repositories as $repo) {
            if (hash_equals($repo['webhook_token'], $providedToken)) {
                return true;
            }
        }
        return false;
    }

    public function getRepositoryByToken(string $token, array $repositories): ?array
    {
        foreach ($repositories as $repo) {
            if (hash_equals($repo['webhook_token'], $token)) {
                return $repo;
            }
        }
        return null;
    }
}