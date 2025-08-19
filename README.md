# GitLab Webhook CI/CD

Simple PHP application to handle GitLab webhooks and automate CI/CD deployments for Symfony projects.

## Features

- **Multi-repositories**: Manage multiple projects simultaneously
- **Security**: Unique token system per repository
- **Per-repository logs**: Complete traceability in files
- **Automatic rollback**: Return to previous version on error
- **Symfony support**: Compatible with Webpack/Encore and AssetMapper
- **Simple CLI**: Commands to manage repositories
- **No complex dependencies**: Uses only native PHP with Composer autoloader


## Installation

### Local Development

#### Requirements
* PHP 8.0 or higher
* Composer
* Git

### Quick Start
* Clone or update repository:
  * Clone: `git clone https://github.com/GBonnaire/php-gitlab-cicd-webhook.git [folder]`
  * Update: `git pull`
* Install dependencies: `composer install --no-dev --optimize-autoloader`
* Configure web server to point to the project public folder

### Authentication Options

When setting up repository access, you have two authentication methods:

#### Option 1: Personal Access Token (Default)
Use your GitLab personal access token for repository access. This is the default method.

#### Option 2: Deploy Keys
For enhanced security, you can use SSH deploy keys instead of personal tokens.

**Deploy Key Setup:**
1. Go to your GitLab project
2. Navigate to Settings → Repository → Deploy Keys  
3. Add a new deploy key:
   - **Title**: Give your key a descriptive name
   - **Key**: Paste your public key content (found in `$HOME/.ssh/*.pub`)
   - **Grant write access**: Enable if you need write permissions
   - Click "Add key"

**Generate SSH Key (if needed):**
```bash
# Generate a new SSH key pair
ssh-keygen -t ed25519 -C "your-email@example.com"

# Display the public key
cat ~/.ssh/id_ed25519.pub
```

## Usage

### Available CLI commands

```bash
# Show help
php bin/console help

# Install a new repository
php bin/console app:install [git-url] [local-path] [branch] [type]

# List all repositories
php bin/console app:list

# Remove a repository
php bin/console app:remove [name]

# Test a deployment
php bin/console app:test [repository]

# View logs
php bin/console app:logs [name] [lines]
```

#### Command Details

**app:install** - Install a new GitLab repository for CI/CD deployment
- `git-url` (optional): Git repository URL
- `local-path` (optional): Local path for the repository
- `branch` (optional): Git branch (default: main)
- `type` (optional): Project type (default: symfony-webpack)
  - `symfony-webpack`: Symfony with Webpack/Encore (npm build)
  - `symfony-asset-mapper`: Symfony with AssetMapper (asset:map compile)
  - `symfony-api`: Symfony API only (no frontend compilation)
  - `simple`: Simple deployment (git pull only)

**app:list** - List all configured repositories

**app:remove** - Remove a configured repository
- `name` (optional): Repository name to remove (interactive selection if not provided)

**app:test** - Test deployment for a repository
- `repository` (optional): Repository name to test (interactive selection if not provided)

**app:logs** - Show logs for a repository or global logs
- `name` (optional): Repository name (default: global for application logs)
- `lines` (optional): Number of lines to show (default: 50)

### Usage examples

```bash
# Install a Symfony project with Webpack (interactive mode)
php bin/console app:install

# Install a Symfony project with Webpack (direct mode)
php bin/console app:install https://gitlab.com/user/project.git ./repositories/project main symfony-webpack

# Install a project with AssetMapper
php bin/console app:install https://gitlab.com/user/app.git ./repositories/app main symfony-asset-mapper

# Install a Symfony API project (no frontend)
php bin/console app:install https://gitlab.com/user/api.git ./repositories/api main symfony-api

# Install a simple project (git pull only)
php bin/console app:install https://gitlab.com/user/simple.git ./repositories/simple main simple

# List configured repositories
php bin/console app:list

# Remove a repository (interactive selection)
php bin/console app:remove

# Remove a specific repository
php bin/console app:remove project

# Test a deployment (interactive selection)
php bin/console app:test

# Test a specific repository deployment
php bin/console app:test project

# View global application logs (last 50 lines)
php bin/console app:logs

# View project logs (last 100 lines)
php bin/console app:logs project 100

# View project logs (last 20 lines)
php bin/console app:logs project 20
```

## GitLab webhook configuration

### 1. Install a repository

```bash
php bin/console install https://gitlab.com/user/project.git ./repositories/project
```

The command will display:
```
✓ Repository installed successfully!

Webhook Configuration:
  URL: https://your-domain.com/
  Token: a1b2c3d4e5f6...
  Events: Push events

Configure this webhook in your GitLab project settings.
```

### 2. Configure GitLab Webhook

1. Go to your GitLab project
2. Navigate to Settings → Webhooks  
3. Add a new webhook:
   - **URL**: The webhook URL displayed by the service (visit your domain to get the current URL)
   - **Secret Token**: The token generated by the install command
   - **Triggers**: Select **Push events**
   - **SSL verification**: Enabled (recommended)

### 3. Customize deployment

Each repository has its own directory in `repositories/` with:
- `deployment.php`: Custom deployment logic
- `properties.json`: Repository-specific configuration (optional)

You can customize the deployment by editing these files:

```php
<?php
// repositories/project/deployment.php

require_once __DIR__ . '/../../vendor/autoload.php';
use App\BaseDeployment;

class Deployment extends BaseDeployment
{
    public function up(array $webhookData): array
    {
        // Environment modifications (pre-deployment)
        $preEnvChanges = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0'
        ];
        
        if (!empty($preEnvChanges)) {
            $result = $this->updateEnvFile($preEnvChanges);
            if (!$result['success']) return $result;
        }

        // Deployment sequence
        $result = $this->gitPull();
        if (!$result['success']) return $result;

        $result = $this->composerInstall();
        if (!$result['success']) return $result;

        $result = $this->npmInstall();
        if (!$result['success']) return $result;

        $result = $this->npmBuild();
        if (!$result['success']) return $result;

        $result = $this->doctrineMigrate();
        if (!$result['success']) return $result;

        $result = $this->clearCache();
        if (!$result['success']) return $result;

        // Environment modifications (post-deployment)
        $postEnvChanges = [
            // Changes after deployment
        ];
        
        if (!empty($postEnvChanges)) {
            $result = $this->updateEnvFile($postEnvChanges);
            if (!$result['success']) return $result;
        }

        return ['success' => true, 'message' => 'Deployment completed successfully'];
    }

    public function down(string $previousCommit): array
    {
        // Rollback procedure
        $result = $this->gitReset($previousCommit);
        if (!$result['success']) return $result;

        $result = $this->composerInstall();
        if (!$result['success']) return $result;

        $result = $this->npmInstall();
        if (!$result['success']) return $result;

        $result = $this->npmBuild();
        if (!$result['success']) return $result;

        $result = $this->clearCache();
        if (!$result['success']) return $result;

        return ['success' => true, 'message' => 'Rollback completed successfully'];
    }
}
```

**Properties Configuration (properties.json):**
```json
{
  "environment": "production",
  "database_url": "mysql://user:pass@localhost/dbname",
  "custom_settings": {
    "feature_flag_x": true,
    "max_upload_size": "10M"
  }
}
```

Access properties in your deployment class:
```php
public function up(array $webhookData): array
{
    // Access properties
    $environment = $this->properties['environment'] ?? 'dev';
    $dbUrl = $this->properties['database_url'] ?? null;
    
    // Use properties for environment updates
    $envChanges = [
        'APP_ENV' => $environment,
        'DATABASE_URL' => $dbUrl
    ];
    
    $result = $this->updateEnvFile($envChanges);
    if (!$result['success']) return $result;
    
    // ... rest of deployment
}
```

## Available methods in BaseDeployment

### Git commands
- `gitPull()`: Git pull on configured branch
- `gitReset($commit)`: Reset to specific commit

### PHP/Composer commands
- `composerInstall()`: PHP dependencies installation
- `doctrineMigrate()`: Doctrine migrations
- `clearCache()`: Symfony cache clearing

### Node.js commands
- `npmInstall()`: Installation with npm ci
- `npmBuild()`: Build with npm run build
- `yarnInstall()`: Installation with yarn
- `yarnBuild()`: Build with yarn build

### Symfony commands
- `assetMapCompile()`: AssetMapper compilation
- `updateEnvFile($changes)`: Update .env.local file

### Custom execution
- `runCommand($command)`: Execute a custom command

## 📋 Logs and monitoring

### Log types

- **logs/app.log**: Global application logs
- **logs/project-name.log**: Repository-specific logs

### Log consultation

```bash
# Global logs (last 50 lines)
php bin/console logs

# Project logs (last 100 lines)
php bin/console logs my-project 100

# View file directly
tail -f logs/my-project.log
```

### Log format

```
[2024-08-18 10:30:15] INFO: Received webhook for my-project, branch: main
[2024-08-18 10:30:16] INFO: Starting deployment for my-project
[2024-08-18 10:30:17] INFO: Executing: cd /var/repositories/my-project && git pull origin main
[2024-08-18 10:30:18] INFO: Deployment successful: Deployment completed successfully
```

## Advanced customization

### Adding custom commands

```php
// In your Deployment class
public function up(array $webhookData): array
{
    // Custom command
    $result = $this->runCommand('php bin/console app:custom-command');
    if (!$result['success']) return $result;
    
    // Or multiple commands
    $commands = [
        'php bin/console cache:warmup',
        'php bin/console messenger:consume async -t 60',
        'sudo supervisorctl restart all'
    ];
    
    foreach ($commands as $command) {
        $result = $this->runCommand($command);
        if (!$result['success']) return $result;
    }
    
    return ['success' => true, 'message' => 'Custom deployment completed'];
}
```

### Supported project types

- **symfony-webpack**: Symfony with Webpack/Encore (npm/yarn)
- **symfony-asset-mapper**: Symfony with AssetMapper

## Security

### Tokens
- **Automatic generation**: 64 hexadecimal characters
- **Validation**: hash_equals() to avoid timing attacks
- **Storage**: repositories.json file (to be secured)

### Best practices
1. Use HTTPS in production
2. Configure a firewall
3. Limit access to log files
4. Backup the repositories.json file

## Troubleshooting

### Common errors

**"Repository not found"**
```bash
php bin/console list  # Check the list
php bin/console install ...  # Reinstall if necessary
```

**"Command failed"**
```bash
php bin/console logs project-name 20  # View detailed logs
# Check repository folder permissions
```

**"Invalid token"**
```bash
# Check the token in GitLab
# Compare with the token in repositories.json
```

### Debug

```bash
# View logs in real time
tail -f logs/app.log

# Test a deployment manually
php bin/console test project-name

# Check configuration
cat repositories.json
```