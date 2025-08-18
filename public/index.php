<?php

require_once __DIR__ . '/../vendor/autoload.php';


define('ROOT_FOLDER', dirname(__DIR__) . "/../");

use App\Logger;
use App\Config;
use App\Security;
use App\Deployer;


// Initialize services
$logger = new Logger();
$config = new Config();
$security = new Security();
$deployer = new Deployer($logger);

// Handle webhook requests
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestUri === '/webhook' && $requestMethod === 'POST') {
    // Handle webhook
    header('Content-Type: application/json');
    
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
        
        // Check if it's a push event
        $eventName = $headers['X-Gitlab-Event'] ?? '';
        if ($eventName !== 'Push Hook') {
            echo json_encode(['success' => true, 'message' => 'Event ignored (not a push)']);
            exit;
        }
        
        // Extract branch
        $branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
        if ($branch !== $repository['branch']) {
            echo json_encode(['success' => true, 'message' => "Branch {$branch} ignored"]);
            exit;
        }
        
        $logger->info("Received webhook for {$repository['name']}, branch: {$branch}", $repository['name']);
        
        // Deploy
        $result = $deployer->deploy($repository, $data);
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        $logger->error('Webhook error: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
} else {
    // Simple status page
    header('Content-Type: application/json');
    
    $repositories = $config->getAllRepositories();
    echo json_encode([
        'service' => 'GitLab Webhook CI/CD',
        'version' => '2.0.0',
        'status' => 'running',
        'repositories' => count($repositories),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}