<?php
require_once 'config/database.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "<h2>Testing Admin Login Credentials</h2>";
    
    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute(['admin@itsptickets.local']);
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "<p>✅ Admin user found:</p>";
        echo "<ul>";
        echo "<li>ID: {$admin['id']}</li>";
        echo "<li>Name: {$admin['name']}</li>";
        echo "<li>Email: {$admin['email']}</li>";
        echo "<li>Role: {$admin['role']}</li>";
        echo "<li>Password Hash: " . substr($admin['password'], 0, 20) . "...</li>";
        echo "</ul>";
        
        // Test password verification
        $test_password = 'admin123';
        $verify = password_verify($test_password, $admin['password']);
        echo "<p>Password verification for 'admin123': " . ($verify ? "✅ SUCCESS" : "❌ FAILED") . "</p>";
        
        if (!$verify) {
            echo "<p>❌ Password hash doesn't match. Creating new admin user...</p>";
            
            // Delete existing admin and create new one
            $stmt = $pdo->prepare("DELETE FROM users WHERE email = ?");
            $stmt->execute(['admin@itsptickets.local']);
            
            // Create new admin with correct password hash
            $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute(['System Admin', 'admin@itsptickets.local', $new_hash, 'admin']);
            
            echo "<p>✅ New admin user created with correct password hash</p>";
        }
    } else {
        echo "<p>❌ Admin user not found. Creating admin user...</p>";
        
        // Create admin user
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['System Admin', 'admin@itsptickets.local', $hash, 'admin']);
        
        echo "<p>✅ Admin user created</p>";
    }
    
    echo "<hr>";
    echo "<h3>Test Login:</h3>";
    echo "<p><strong>Email:</strong> admin@itsptickets.local</p>";
    echo "<p><strong>Password:</strong> admin123</p>";
    echo "<p><a href='login.php'>Go to Login Page</a></p>";
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?>