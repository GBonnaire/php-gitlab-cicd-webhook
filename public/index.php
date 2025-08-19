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

if ($requestMethod === 'POST') {
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
        
        // Check if it's a push or merge request event
        $eventName = $headers['X-Gitlab-Event'] ?? '';
        if (!in_array($eventName, ['Push Hook', 'Merge Request Hook'])) {
            echo json_encode(['success' => true, 'message' => "Event ignored (${eventName}))"]);
            exit;
        }
        
        // For merge requests, only process when merged
        if ($eventName === 'Merge Request Hook') {
            $mergeRequestData = $data['object_attributes'] ?? [];
            if (($mergeRequestData['state'] ?? '') !== 'merged') {
                echo json_encode(['success' => true, 'message' => 'Merge request event ignored (not merged)']);
                exit;
            }
            // Use target branch for merge requests
            $branch = $mergeRequestData['target_branch'] ?? '';
        } else {
            // Extract branch for push events
            $branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
        }
        
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
    header('Content-Type: text/html');
    
    $repositories = $config->getAllRepositories();
    $currentDomain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                     '://' . $_SERVER['HTTP_HOST'];
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitLab Webhook CI/CD</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        .container {
            text-align: center;
            max-width: 600px;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
        }
        .logo {
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }
        h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            font-weight: 300;
        }
        .subtitle {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        .info-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .info-label {
            font-size: 0.9rem;
            opacity: 0.7;
            margin-bottom: 0.5rem;
        }
        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .webhook-url {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .webhook-url-label {
            font-size: 0.9rem;
            opacity: 0.7;
            margin-bottom: 0.5rem;
        }
        .webhook-url-value {
            font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
            font-size: 1rem;
            word-break: break-all;
            color: #ffd700;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 380 380">
                <defs>
                    <style>
                        .cls-1 { fill: #fca326; }
                        .cls-2 { fill: #fc6d26; }
                        .cls-3 { fill: #e24329; }
                    </style>
                </defs>
                <path class="cls-3" d="M265.26416,174.37243l-.2134-.55822-21.19899-55.30908c-.4236-1.08359-1.18542-1.99642-2.17699-2.62689-.98837-.63373-2.14749-.93253-3.32305-.87014-1.1689.06239-2.29195.48925-3.20809,1.21821-.90957.73554-1.56629,1.73047-1.87493,2.85346l-14.31327,43.80662h-57.90965l-14.31327-43.80662c-.30864-1.12299-.96536-2.11791-1.87493-2.85346-.91614-.72895-2.03911-1.15582-3.20809-1.21821-1.17548-.06239-2.33468.23641-3.32297.87014-.99166.63047-1.75348,1.5433-2.17707,2.62689l-21.19891,55.31237-.21348.55493c-6.28158,16.38521-.92929,34.90803,13.05891,45.48782.02621.01641.04922.03611.07552.05582l.18719.14119,32.29094,24.17392,15.97151,12.09024,9.71951,7.34871c2.34117,1.77316,5.57877,1.77316,7.92002,0l9.71943-7.34871,15.96822-12.09024,32.48142-24.31511c.02958-.02299.05588-.04269.08538-.06568,13.97834-10.57977,19.32735-29.09604,13.04905-45.47796Z"/>
                <path class="cls-2" d="M265.26416,174.37243l-.2134-.55822c-10.5174,2.16062-20.20405,6.6099-28.49844,12.81593-.1346.0985-25.20497,19.05805-46.55171,35.19699,15.84998,11.98517,29.6477,22.40405,29.6477,22.40405l32.48142-24.31511c.02958-.02299.05588-.04269.08538-.06568,13.97834-10.57977,19.32735-29.09604,13.04905-45.47796Z"/>
                <path class="cls-1" d="M160.34962,244.23117l15.97151,12.09024,9.71951,7.34871c2.34117,1.77316,5.57877,1.77316,7.92002,0l9.71943-7.34871,15.96822-12.09024s-13.79772-10.41888-29.6477-22.40405c-15.85327,11.98517-29.65099,22.40405-29.65099,22.40405Z"/>
                <path class="cls-2" d="M143.44561,186.63014c-8.29111-6.20274-17.97446-10.65531-28.49507-12.81264l-.21348.55493c-6.28158,16.38521-.92929,34.90803,13.05891,45.48782.02621.01641.04922.03611.07552.05582l.18719.14119,32.29094,24.17392s13.79772-10.41888,29.65099-22.40405c-21.34673-16.13894-46.42031-35.09848-46.55499-35.19699Z"/>
            </svg>
        </div>
        
        <h1>GitLab Webhook CI/CD</h1>
        <p class="subtitle">Automated deployment service is running</p>
        
        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">Status</div>
                <div class="info-value">✅ Running</div>
            </div>
            <div class="info-card">
                <div class="info-label">Version</div>
                <div class="info-value">2.0.0</div>
            </div>
            <div class="info-card">
                <div class="info-label">Repositories</div>
                <div class="info-value">' . count($repositories) . '</div>
            </div>
            <div class="info-card">
                <div class="info-label">Last Update</div>
                <div class="info-value">' . date('H:i:s') . '</div>
            </div>
        </div>
        
        <div class="webhook-url">
            <div class="webhook-url-label">Webhook URL</div>
            <div class="webhook-url-value">' . $currentDomain . '/</div>
        </div>
    </div>
</body>
</html>';
}