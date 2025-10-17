<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

require_once 'config/database.php';
require_once 'app/Auth/ApiKeyAuth.php';

use App\Auth\ApiKeyAuth;

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Get current user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['active']) {
        die("Access denied. User not found or inactive.");
    }
    
    $apiKeyAuth = new ApiKeyAuth($pdo);
    $message = '';
    $messageType = '';
    $generatedToken = null;
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'generate':
                    $name = trim($_POST['name'] ?? '');
                    $expires_days = intval($_POST['expires_days'] ?? 0);
                    
                    if (empty($name)) {
                        $message = 'Token name is required.';
                        $messageType = 'error';
                    } else {
                        // Calculate expiration date
                        $expiresAt = null;
                        if ($expires_days > 0) {
                            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));
                        }
                        
                        $tokenData = $apiKeyAuth->generateToken(
                            $user['id'], 
                            $name, 
                            ['*'], // Full access for now
                            $expiresAt
                        );
                        
                        if ($tokenData) {
                            $generatedToken = $tokenData['token'];
                            $message = 'API key generated successfully! Make sure to copy it now - you won\'t be able to see it again.';
                            $messageType = 'success';
                        } else {
                            $message = 'Failed to generate API key. Please try again.';
                            $messageType = 'error';
                        }
                    }
                    break;
                    
                case 'revoke':
                    $token = $_POST['token'] ?? '';
                    
                    if ($apiKeyAuth->revokeToken($token)) {
                        $message = 'API key revoked successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to revoke API key.';
                        $messageType = 'error';
                    }
                    break;
            }
        }
    }
    
    // Get all user's tokens
    $tokens = $apiKeyAuth->getUserTokens($user['id']);
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>API Keys - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc; 
            line-height: 1.6;
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 20px; 
        }
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            margin-bottom: 30px;
        }
        .header h1 { color: #1f2937; display: flex; align-items: center; gap: 15px; }
        .user-info { display: flex; gap: 20px; align-items: center; }
        .user-info a { color: #3b82f6; text-decoration: none; }
        .user-info a:hover { text-decoration: underline; }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-weight: 500;
            border: 1px solid;
        }
        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-color: #10b981;
        }
        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-color: #ef4444;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            padding: 20px;
            font-size: 18px;
            font-weight: 600;
        }

        .card-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #6366f1;
            color: white;
        }

        .btn-primary:hover {
            background: #4f46e5;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .tokens-grid {
            display: grid;
            gap: 20px;
        }

        .token-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: border-color 0.2s ease;
        }

        .token-card:hover {
            border-color: #6366f1;
        }

        .token-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .token-name {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .token-preview {
            font-family: 'Monaco', 'Menlo', monospace;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 15px;
        }

        .token-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .meta-value {
            font-weight: 600;
            color: #1f2937;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .generated-token {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .generated-token-header {
            font-size: 18px;
            font-weight: 600;
            color: #065f46;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .token-value {
            font-family: 'Monaco', 'Menlo', monospace;
            background: white;
            padding: 15px;
            border-radius: 8px;
            font-size: 14px;
            word-break: break-all;
            border: 1px solid #10b981;
            position: relative;
        }

        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #10b981;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }

        .copy-btn:hover {
            background: #059669;
        }

        .warning-note {
            margin-top: 15px;
            padding: 12px;
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            color: #92400e;
            font-size: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: end;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 15px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
                font-size: 14px;
            }
            
            .card {
                margin-bottom: 20px;
            }
            
            .card-header {
                padding: 15px;
                font-size: 16px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .form-input, .form-select {
                padding: 14px 16px;
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .btn {
                padding: 14px 20px;
                font-size: 16px;
                width: 100%;
                text-align: center;
            }
            
            .token-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .token-name {
                font-size: 16px;
            }
            
            .token-preview {
                font-size: 11px;
                word-break: break-all;
            }
            
            .token-meta {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            
            .meta-item {
                padding: 10px;
                background: #f8fafc;
                border-radius: 6px;
            }
            
            .meta-label {
                font-size: 11px;
            }
            
            .meta-value {
                font-size: 13px;
            }
            
            .status-badge {
                align-self: flex-start;
                margin-top: 5px;
            }
            
            .generated-token {
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .generated-token-header {
                font-size: 16px;
            }
            
            .token-value {
                font-size: 12px;
                padding: 12px;
                word-break: break-all;
            }
            
            .copy-btn {
                position: static;
                margin-top: 10px;
                width: 100%;
                padding: 8px;
                font-size: 14px;
            }
            
            .warning-note {
                font-size: 13px;
                padding: 10px;
            }
            
            .empty-state {
                padding: 40px 15px;
            }
            
            .empty-state-icon {
                font-size: 36px;
            }
            
            /* Code block mobile optimization */
            div[style*="background: #1f2937"] {
                padding: 15px !important;
                font-size: 12px !important;
                word-break: break-all;
                overflow-x: auto;
            }
        }
        
        /* Extra small devices (phones in portrait) */
        @media (max-width: 480px) {
            .container {
                padding: 5px;
            }
            
            .header {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .card-header {
                padding: 12px;
                font-size: 14px;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .token-meta {
                grid-template-columns: 1fr;
            }
            
            .form-input, .form-select, .btn {
                font-size: 16px; /* Consistent iOS zoom prevention */
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .btn, .copy-btn {
                min-height: 44px; /* Apple's recommended touch target size */
            }
            
            .form-input, .form-select {
                min-height: 44px;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔑 API Key Management</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <span style='background: #6366f1; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; text-transform: uppercase; font-weight: 500;'>
                    <?= htmlspecialchars($user['role']) ?>
                </span>
                <a href='/ITSPtickets/settings.php'>← Back to Settings</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class='alert <?= $messageType ?>'>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($generatedToken): ?>
            <div class='generated-token'>
                <div class='generated-token-header'>
                    🎉 Your new API key has been generated!
                </div>
                <div class='token-value'>
                    <?= htmlspecialchars($generatedToken) ?>
                    <button class='copy-btn' onclick='copyToClipboard("<?= htmlspecialchars($generatedToken) ?>")'>
                        Copy
                    </button>
                </div>
                <div class='warning-note'>
                    ⚠️ <strong>Important:</strong> This is the only time you'll be able to see this token. Copy it now and store it securely.
                </div>
            </div>
        <?php endif; ?>

        <div class='card'>
            <div class='card-header'>
                ➕ Generate New API Key
            </div>
            <div class='card-body'>
                <form method='POST'>
                    <input type='hidden' name='action' value='generate'>
                    
                    <div class='form-row'>
                        <div class='form-group'>
                            <label class='form-label' for='name'>Token Name</label>
                            <input type='text' id='name' name='name' class='form-input' 
                                   placeholder='e.g., My Mobile App, Production Server, etc.' 
                                   required maxlength='100'>
                        </div>
                        
                        <div class='form-group'>
                            <label class='form-label' for='expires_days'>Expires In</label>
                            <select id='expires_days' name='expires_days' class='form-select'>
                                <option value='0'>Never</option>
                                <option value='7'>7 days</option>
                                <option value='30'>30 days</option>
                                <option value='90'>90 days</option>
                                <option value='365'>1 year</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>
                        🔑 Generate API Key
                    </button>
                </form>
            </div>
        </div>

        <div class='card'>
            <div class='card-header'>
                📋 Your API Keys (<?= count($tokens) ?>)
            </div>
            <div class='card-body'>
                <?php if (empty($tokens)): ?>
                    <div class='empty-state'>
                        <div class='empty-state-icon'>🔐</div>
                        <h3>No API keys yet</h3>
                        <p>Generate your first API key to start using the REST API.</p>
                    </div>
                <?php else: ?>
                    <div class='tokens-grid'>
                        <?php foreach ($tokens as $token): ?>
                            <?php 
                                $isExpired = $token['expires_at'] && strtotime($token['expires_at']) < time();
                                $tokenPreview = substr($token['token'], 0, 8) . '...' . substr($token['token'], -8);
                            ?>
                            <div class='token-card'>
                                <div class='token-header'>
                                    <div>
                                        <div class='token-name'><?= htmlspecialchars($token['name']) ?></div>
                                        <div class='token-preview'><?= htmlspecialchars($tokenPreview) ?></div>
                                    </div>
                                    <div>
                                        <span class='status-badge <?= $isExpired ? 'status-expired' : 'status-active' ?>'>
                                            <?= $isExpired ? 'Expired' : 'Active' ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class='token-meta'>
                                    <div class='meta-item'>
                                        <span class='meta-label'>Created</span>
                                        <span class='meta-value'>
                                            <?= date('M j, Y', strtotime($token['created_at'])) ?>
                                        </span>
                                    </div>
                                    
                                    <div class='meta-item'>
                                        <span class='meta-label'>Last Used</span>
                                        <span class='meta-value'>
                                            <?= $token['last_used_at'] ? date('M j, Y', strtotime($token['last_used_at'])) : 'Never' ?>
                                        </span>
                                    </div>
                                    
                                    <div class='meta-item'>
                                        <span class='meta-label'>Expires</span>
                                        <span class='meta-value'>
                                            <?= $token['expires_at'] ? date('M j, Y', strtotime($token['expires_at'])) : 'Never' ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <form method='POST' style='margin-top: 15px;' 
                                      onsubmit='return confirm("Are you sure you want to revoke this API key? This action cannot be undone.")'>
                                    <input type='hidden' name='action' value='revoke'>
                                    <input type='hidden' name='token' value='<?= htmlspecialchars($token['token']) ?>'>
                                    <button type='submit' class='btn btn-danger'>
                                        🗑️ Revoke Key
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class='card'>
            <div class='card-header'>
                📖 How to use your API keys
            </div>
            <div class='card-body'>
                <p style='margin-bottom: 20px;'>Include your API key in the request headers when making API calls:</p>
                
                <div style='background: #1f2937; color: #f9fafb; padding: 20px; border-radius: 8px; font-family: Monaco, monospace; margin-bottom: 20px;'>
                    <div style='color: #10b981; margin-bottom: 10px;'># Using curl:</div>
                    curl -H "Authorization: Bearer YOUR_API_KEY" \<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;https://yourdomain.com/ITSPtickets/api/tickets.php<br><br>
                    
                    <div style='color: #10b981; margin-bottom: 10px;'># Alternative header format:</div>
                    curl -H "X-API-Key: YOUR_API_KEY" \<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;https://yourdomain.com/ITSPtickets/api/tickets.php
                </div>
                
                <p style='color: #6b7280; font-size: 14px;'>
                    📚 For complete API documentation, visit: 
                    <a href='/ITSPtickets/api-docs.php' style='color: #6366f1; text-decoration: none;'>API Documentation</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                // Show success feedback
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.background = '#059669';
                
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.style.background = '#10b981';
                }, 2000);
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                alert('API key copied to clipboard!');
            });
        }
    </script>
</body>
</html>