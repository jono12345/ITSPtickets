<?php
/*
|--------------------------------------------------------------------------
| API Key Management Script
|--------------------------------------------------------------------------
|
| Command-line script to manage API keys for ITSPtickets
|
| Usage:
|   php manage-api-keys.php create [user_id] [name] [abilities] [expires_days]
|   php manage-api-keys.php list [user_id]
|   php manage-api-keys.php revoke [token]
|   php manage-api-keys.php show [token]
|
*/

require_once 'config/database.php';
require_once 'api-key-functions.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Ensure API tokens table exists
    ensureApiTokensTable($pdo);
    
    // Parse command line arguments
    $command = $argv[1] ?? 'help';
    
    switch ($command) {
        case 'create':
            createApiKey($pdo, $argv);
            break;
            
        case 'list':
            listApiKeys($pdo, $argv);
            break;
            
        case 'revoke':
            revokeApiKey($pdo, $argv);
            break;
            
        case 'show':
            showApiKey($pdo, $argv);
            break;
            
        case 'users':
            listUsers($pdo);
            break;
            
        case 'help':
        default:
            showHelp();
            break;
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function createApiKey($pdo, $argv)
{
    $userId = $argv[2] ?? null;
    $name = $argv[3] ?? null;
    $abilitiesStr = $argv[4] ?? '*';
    $expiresDays = isset($argv[5]) ? (int)$argv[5] : null;
    
    if (!$userId || !$name) {
        echo "Usage: php manage-api-keys.php create [user_id] [name] [abilities] [expires_days]\n";
        echo "Example: php manage-api-keys.php create 1 \"My API Key\" \"*\" 30\n";
        echo "Abilities: '*' for all, or comma-separated list like 'read,write'\n";
        exit(1);
    }
    
    // Verify user exists
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ? AND active = TRUE");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "Error: User ID $userId not found or inactive\n";
        exit(1);
    }
    
    // Parse abilities
    $abilities = [];
    if ($abilitiesStr !== '*') {
        $abilities = array_map('trim', explode(',', $abilitiesStr));
    } else {
        $abilities = ['*'];
    }
    
    // Calculate expiry
    $expiresAt = null;
    if ($expiresDays) {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));
    }
    
    // Create token
    $result = generateApiToken($pdo, $userId, $name, $abilities, $expiresAt);
    
    if ($result) {
        echo "✅ API Key created successfully!\n\n";
        echo "Details:\n";
        echo "--------\n";
        echo "ID: {$result['id']}\n";
        echo "Name: {$result['name']}\n";
        echo "User: {$user['name']} ({$user['email']}) - {$user['role']}\n";
        echo "Token: {$result['token']}\n";
        echo "Abilities: " . implode(', ', $abilities) . "\n";
        echo "Expires: " . ($expiresAt ? $expiresAt : 'Never') . "\n\n";
        echo "🔑 Save this token securely - it won't be shown again!\n\n";
        echo "Example curl usage:\n";
        echo "curl -H \"Authorization: Bearer {$result['token']}\" \\\n";
        echo "     http://your-domain.com/ITSPtickets/api/tickets.php\n\n";
    } else {
        echo "❌ Failed to create API key\n";
        exit(1);
    }
}

function listApiKeys($pdo, $argv)
{
    $userId = $argv[2] ?? null;
    
    if ($userId) {
        // List keys for specific user
        $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo "Error: User ID $userId not found\n";
            exit(1);
        }
        
        $tokens = getUserApiTokens($pdo, $userId);
        
        echo "API Keys for {$user['name']} ({$user['email']}):\n";
        echo str_repeat("=", 50) . "\n\n";
        
    } else {
        // List all keys
        $stmt = $pdo->query("
            SELECT at.*, u.name as user_name, u.email as user_email, u.role as user_role
            FROM api_tokens at
            LEFT JOIN users u ON at.user_id = u.id
            ORDER BY at.created_at DESC
        ");
        $tokens = $stmt->fetchAll();
        
        echo "All API Keys:\n";
        echo str_repeat("=", 50) . "\n\n";
    }
    
    if (empty($tokens)) {
        echo "No API keys found.\n";
        return;
    }
    
    foreach ($tokens as $token) {
        $abilities = json_decode($token['abilities'] ?? '[]', true);
        $abilitiesStr = empty($abilities) ? 'All' : implode(', ', $abilities);
        
        $expired = '';
        if ($token['expires_at']) {
            $isExpired = strtotime($token['expires_at']) < time();
            $expired = $isExpired ? ' (EXPIRED)' : '';
        }
        
        echo "ID: {$token['id']}\n";
        echo "Name: {$token['name']}{$expired}\n";
        if (!$userId) {
            echo "User: {$token['user_name']} ({$token['user_email']}) - {$token['user_role']}\n";
        }
        echo "Token: " . substr($token['token'], 0, 16) . "...\n";
        echo "Abilities: {$abilitiesStr}\n";
        echo "Last Used: " . ($token['last_used_at'] ?? 'Never') . "\n";
        echo "Expires: " . ($token['expires_at'] ?? 'Never') . "\n";
        echo "Created: {$token['created_at']}\n";
        echo str_repeat("-", 30) . "\n\n";
    }
}

function revokeApiKey($pdo, $argv)
{
    $token = $argv[2] ?? null;
    
    if (!$token) {
        echo "Usage: php manage-api-keys.php revoke [token]\n";
        exit(1);
    }
    
    if (strlen($token) !== 64) {
        echo "Error: Invalid token format. Token should be 64 characters long.\n";
        exit(1);
    }
    
    $result = revokeApiToken($pdo, $token);
    
    if ($result) {
        echo "✅ API key revoked successfully!\n";
    } else {
        echo "❌ Failed to revoke API key (token may not exist)\n";
        exit(1);
    }
}

function showApiKey($pdo, $argv)
{
    $token = $argv[2] ?? null;
    
    if (!$token) {
        echo "Usage: php manage-api-keys.php show [token]\n";
        exit(1);
    }
    
    $stmt = $pdo->prepare("
        SELECT at.*, u.name as user_name, u.email as user_email, u.role as user_role
        FROM api_tokens at
        LEFT JOIN users u ON at.user_id = u.id
        WHERE at.token = ?
    ");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch();
    
    if (!$tokenData) {
        echo "❌ API key not found\n";
        exit(1);
    }
    
    $abilities = json_decode($tokenData['abilities'] ?? '[]', true);
    $abilitiesStr = empty($abilities) ? 'All' : implode(', ', $abilities);
    
    $expired = '';
    if ($tokenData['expires_at']) {
        $isExpired = strtotime($tokenData['expires_at']) < time();
        $expired = $isExpired ? ' ❌ EXPIRED' : ' ✅ Valid';
    } else {
        $expired = ' ✅ Never expires';
    }
    
    echo "API Key Details:\n";
    echo str_repeat("=", 30) . "\n\n";
    echo "ID: {$tokenData['id']}\n";
    echo "Name: {$tokenData['name']}\n";
    echo "User: {$tokenData['user_name']} ({$tokenData['user_email']}) - {$tokenData['user_role']}\n";
    echo "Token: {$tokenData['token']}\n";
    echo "Abilities: {$abilitiesStr}\n";
    echo "Status:{$expired}\n";
    echo "Last Used: " . ($tokenData['last_used_at'] ?? 'Never') . "\n";
    echo "Expires: " . ($tokenData['expires_at'] ?? 'Never') . "\n";
    echo "Created: {$tokenData['created_at']}\n";
}

function listUsers($pdo)
{
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.active,
               COUNT(at.id) as token_count
        FROM users u
        LEFT JOIN api_tokens at ON u.id = at.user_id
        GROUP BY u.id
        ORDER BY u.name
    ");
    $users = $stmt->fetchAll();
    
    echo "Users in the system:\n";
    echo str_repeat("=", 50) . "\n\n";
    
    foreach ($users as $user) {
        $status = $user['active'] ? '✅ Active' : '❌ Inactive';
        echo "ID: {$user['id']}\n";
        echo "Name: {$user['name']}\n";
        echo "Email: {$user['email']}\n";
        echo "Role: {$user['role']}\n";
        echo "Status: {$status}\n";
        echo "API Keys: {$user['token_count']}\n";
        echo str_repeat("-", 30) . "\n\n";
    }
}

function showHelp()
{
    echo "ITSPtickets API Key Management\n";
    echo str_repeat("=", 50) . "\n\n";
    echo "Commands:\n";
    echo "  create [user_id] [name] [abilities] [expires_days]\n";
    echo "    Create a new API key\n";
    echo "    Abilities: '*' for all, or comma-separated list\n";
    echo "    Expires: number of days (optional)\n\n";
    echo "  list [user_id]\n";
    echo "    List API keys (all or for specific user)\n\n";
    echo "  revoke [token]\n";
    echo "    Revoke an API key\n\n";
    echo "  show [token]\n";
    echo "    Show details of a specific API key\n\n";
    echo "  users\n";
    echo "    List all users in the system\n\n";
    echo "Examples:\n";
    echo "  php manage-api-keys.php users\n";
    echo "  php manage-api-keys.php create 1 \"Production API\" \"*\" 90\n";
    echo "  php manage-api-keys.php list 1\n";
    echo "  php manage-api-keys.php show abc123...\n";
    echo "  php manage-api-keys.php revoke abc123...\n";
}