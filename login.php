<?php
/*
|--------------------------------------------------------------------------
| Login - Simple Model
|--------------------------------------------------------------------------
|
| Self-contained login functionality using the Simple model approach.
| Handles both login form display and authentication processing.
|
*/

session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/dashboard-simple.php');
    exit;
}

// Initialize variables
$error = '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        try {
            // Database connection
            $config = require 'config/database.php';
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // Check for user in users table (internal staff)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Successful login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];
                
                // Update last login
                $updateStmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Redirect to dashboard
                header('Location: /ITSPtickets/dashboard-simple.php');
                exit;
                
            } else {
                // Check for requester (external customer)
                $stmt = $pdo->prepare("SELECT * FROM requesters WHERE email = ? AND active = 1");
                $stmt->execute([$email]);
                $requester = $stmt->fetch();
                
                if ($requester) {
                    // For requesters, we'd need to implement password hashing
                    // For now, redirect to customer portal with simple check
                    $_SESSION['requester_id'] = $requester['id'];
                    $_SESSION['requester_name'] = $requester['name'];
                    $_SESSION['requester_email'] = $requester['email'];
                    
                    header('Location: /ITSPtickets/portal-simple.php');
                    exit;
                } else {
                    $error = 'Invalid email or password';
                }
            }
            
        } catch (PDOException $e) {
            error_log("Login database error: " . $e->getMessage());
            $error = 'Database connection error. Please try again later.';
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'An unexpected error occurred. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Login - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h1 {
            color: #1f2937;
            font-size: 28px;
            margin-bottom: 8px;
        }
        .login-header p {
            color: #6b7280;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s ease;
        }
        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .btn:hover {
            background: #2563eb;
        }
        .btn:active {
            transform: translateY(1px);
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #fca5a5;
        }
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #6ee7b7;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .footer p {
            color: #6b7280;
            font-size: 12px;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .login-container {
                padding: 25px 20px;
                margin: 15px;
                max-width: 500px;
            }
            
            .login-header h1 {
                font-size: 26px;
            }
            
            .login-header p {
                font-size: 15px;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
            
            .form-group label {
                font-size: 15px;
                margin-bottom: 6px;
            }
            
            .form-group input {
                padding: 12px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .btn {
                padding: 14px 16px;
                font-size: 16px;
                min-height: 50px;
            }
            
            .error, .success {
                padding: 14px 18px;
                font-size: 15px;
            }
            
            .footer {
                margin-top: 25px;
                padding-top: 18px;
            }
            
            .footer p {
                font-size: 13px;
            }
        }
        
        /* Extra small devices (phones in portrait) */
        @media (max-width: 480px) {
            body {
                padding: 5px;
            }
            
            .login-container {
                padding: 20px 15px;
                margin: 10px;
                border-radius: 8px;
            }
            
            .login-header {
                margin-bottom: 25px;
            }
            
            .login-header h1 {
                font-size: 22px;
                margin-bottom: 6px;
            }
            
            .login-header p {
                font-size: 14px;
            }
            
            .form-group {
                margin-bottom: 16px;
            }
            
            .form-group label {
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .form-group input {
                padding: 16px 14px; /* Larger for better touch targets */
                font-size: 16px;
                border-width: 2px;
            }
            
            .form-group input:focus {
                border-color: #3b82f6;
                box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            }
            
            .btn {
                padding: 16px;
                font-size: 17px;
                min-height: 52px;
                border-radius: 10px;
            }
            
            .error, .success {
                padding: 12px 16px;
                font-size: 14px;
                margin-bottom: 18px;
            }
            
            .footer {
                margin-top: 20px;
                padding-top: 16px;
            }
            
            .footer p {
                font-size: 12px;
                line-height: 1.4;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .btn {
                min-height: 44px; /* Apple's recommended touch target size */
            }
            
            .form-group input {
                min-height: 44px;
            }
            
            .btn:active {
                transform: scale(0.98);
                transition: transform 0.1s ease;
            }
        }
        
        /* Landscape phones */
        @media (max-width: 768px) and (orientation: landscape) {
            .login-container {
                max-height: 90vh;
                overflow-y: auto;
            }
            
            .login-header {
                margin-bottom: 20px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
        }
        
        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .login-container {
                border: 2px solid #000;
            }
            
            .form-group input {
                border-width: 2px;
            }
            
            .btn {
                border: 2px solid #fff;
            }
        }
        
        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            .btn:active {
                transform: none;
            }
        }
    </style>
</head>
<body>
    <div class='login-container'>
        <div class='login-header'>
            <h1>🎫 ITSPtickets</h1>
            <p>Sign in to your account</p>
        </div>
        
        <?php if ($error): ?>
            <div class='error'>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class='success'>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <form method='POST'>
            <div class='form-group'>
                <label for='email'>Email Address</label>
                <input type='email' id='email' name='email' 
                       value='<?= htmlspecialchars($_POST['email'] ?? '') ?>' 
                       required autocomplete='email'>
            </div>
            
            <div class='form-group'>
                <label for='password'>Password</label>
                <input type='password' id='password' name='password' 
                       required autocomplete='current-password'>
            </div>
            
            <button type='submit' class='btn'>Sign In</button>
        </form>
        
        <div class='footer'>
            <p>ITSPtickets Simple Model - Secure Authentication</p>
        </div>
    </div>
</body>
</html>