<?php

require_once __DIR__ . '/../vendor/autoload.php';


define('ROOT_FOLDER', dirname(__DIR__)."/");
define('MODE_CLI', false);

use Gbonnaire\PhpGitlabCicdWebhook\Service\LoggerService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\ConfigService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\SecurityService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\DeployerService;
use Gbonnaire\PhpGitlabCicdWebhook\Service\HttpService;


// Initialize services
$logger = new LoggerService();
$config = new ConfigService();
$security = new SecurityService();
$deployer = new DeployerService($logger);
$http = new HttpService();

// Handle webhook requests
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    // Handle webhook
    try {
        // Get payload
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        
        if (!$data) {
            throw new Exception('Invalid JSON payload');
        }
        
        // Get GitLab token from headers
        $headers = getallheaders();
        $gitlabToken = $headers['X-Gitlab-Token'] ?? null;
        
        if (!$gitlabToken) {
            throw new Exception('Missing GitLab token');
        }
        
        // Verify token and get repository
        $repositories = $config->getAllRepositories();
        $repository = $security->getRepositoryByToken($gitlabToken, $repositories);
        
        if (!$repository) {
            throw new Exception('Invalid token');
        }
        
        // Check if it's a push or merge request event
        $eventName = $headers['X-Gitlab-Event'] ?? '';
        if (!in_array($eventName, ['Push Hook', 'Merge Request Hook'])) {
            $http->responseJson(['success' => true, 'message' => "Event ignored ({$eventName}))"]);
            exit;
        }
        
        // For merge requests, only process when merged
        if ($eventName === 'Merge Request Hook') {
            $mergeRequestData = $data['object_attributes'] ?? [];
            if (($mergeRequestData['state'] ?? '') !== 'merged') {
                $http->responseJson(['success' => true, 'message' => 'Merge request event ignored (not merged)']);
                exit;
            }
            // Use target branch for merge requests
            $branch = $mergeRequestData['target_branch'] ?? '';
        } else {
            // Extract branch for push events
            $branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
        }
        
        if ($branch !== $repository['branch']) {
            $http->responseJson(['success' => true, 'message' => "Branch {$branch} ignored"]);
            exit;
        }
        
        $logger->info("Received webhook for {$repository['name']}, branch: {$branch}", $repository['name']);
        
        // Deploy
        $result = $deployer->deploy($repository, $data);
        
        $http->responseJson($result, $result['success'] ? HttpService::HTTP_RESPONSE_OK : HttpService::HTTP_RESPONSE_INTERNAL_SERVER_ERROR);
        
    } catch (Exception $e) {
        $logger->error('Webhook error: ' . $e->getMessage());
        $http->responseJson(['success' => false, 'error' => $e->getMessage()], HttpService::HTTP_RESPONSE_NOT_FOUND);
    }
    
} else {
    $repositories = $config->getAllRepositories();
    $currentDomain = $http->getCurrentDomain();
    
    $http->responseTemplateHTML("index", [
        'repositories' => $repositories,
        'currentDomain' => $currentDomain
    ]);
}