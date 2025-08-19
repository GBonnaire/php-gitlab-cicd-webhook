<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service;

class LoggerService
{
    private string $logDir = ROOT_FOLDER.'/logs';

    public function __construct()
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function log(string $message, string $level = 'INFO', string $repository = 'global'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$level}: {$message}\n";
        
        $filename = $repository === 'global' ? 'app.log' : $this->sanitizeRepoName($repository) . '.log';
        $logFile = $this->logDir . '/' . $filename;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function info(string $message, string $repository = 'global'): void
    {
        $this->log($message, 'INFO', $repository);
    }

    public function error(string $message, string $repository = 'global'): void
    {
        $this->log($message, 'ERROR', $repository);
    }

    public function warning(string $message, string $repository = 'global'): void
    {
        $this->log($message, 'WARNING', $repository);
    }

    public function getLogs(string $repository = 'global', int $lines = 100): array
    {
        $filename = $repository === 'global' ? 'app.log' : $this->sanitizeRepoName($repository) . '.log';
        $logFile = $this->logDir . '/' . $filename;

        if (!file_exists($logFile)) {
            return [];
        }

        $content = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice($content, -$lines);
    }

    private function sanitizeRepoName(string $repository): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $repository);
    }
}