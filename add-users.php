<?php
// Add new admin users: Eddie and Diarmuid

// Database connection
$config = require 'config/database.php';
$dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "Connected to database successfully.\n";
    
    // First, let's add phone field to users table if it doesn't exist
    echo "Checking if phone field exists in users table...\n";
    
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'phone'");
    $stmt->execute();
    $phoneFieldExists = $stmt->fetch();
    
    if (!$phoneFieldExists) {
        echo "Adding phone field to users table...\n";
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
        echo "Phone field added successfully.\n";
    } else {
        echo "Phone field already exists.\n";
    }
    
    // Hash the password "itsp"
    $hashedPassword = password_hash('itsp', PASSWORD_DEFAULT);
    
    // Prepare the insert statement
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, phone, password, role, active) 
        VALUES (?, ?, ?, ?, 'admin', 1)
        ON DUPLICATE KEY UPDATE 
        name = VALUES(name), 
        phone = VALUES(phone), 
        password = VALUES(password), 
        role = VALUES(role),
        active = VALUES(active)
    ");
    
    // Add Eddie
    echo "Adding Eddie...\n";
    $stmt->execute(['Eddie', 'eddie@itsp.co.uk', '07971 649459', $hashedPassword]);
    echo "Eddie added successfully.\n";
    
    // Add Diarmuid  
    echo "Adding Diarmuid...\n";
    $stmt->execute(['Diarmuid', 'd.coyle@itsp.co.uk', '07760 264290', $hashedPassword]);
    echo "Diarmuid added successfully.\n";
    
    // Display all admin users
    echo "\nCurrent admin users:\n";
    $stmt = $pdo->prepare("SELECT id, name, email, phone, role, active FROM users WHERE role = 'admin' ORDER BY name");
    $stmt->execute();
    $admins = $stmt->fetchAll();
    
    foreach ($admins as $admin) {
        echo "- {$admin['name']} ({$admin['email']})";
        if ($admin['phone']) {
            echo " - {$admin['phone']}";
        }
        echo " - " . ($admin['active'] ? 'Active' : 'Inactive') . "\n";
    }
    
    echo "\nUsers added successfully! Both Eddie and Diarmuid can now log in with password 'itsp'.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}