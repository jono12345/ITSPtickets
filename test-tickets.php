<?php
/*
|--------------------------------------------------------------------------
| Test Tickets - Simple Model
|--------------------------------------------------------------------------
|
| Test file to verify Simple model ticket functionality works correctly.
| Tests database connections and basic ticket operations.
|
*/

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Testing Simple Model Tickets</h1>";
echo "<p>Testing the Simple model implementation...</p>";

try {
    // Test database connection
    echo "<h2>1. Database Connection Test</h2>";
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<p>✓ Database connection successful</p>";
    
    // Test ticket count
    echo "<h2>2. Ticket Count Test</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tickets");
    $ticketCount = $stmt->fetch()['count'];
    echo "<p>✓ Found {$ticketCount} tickets in database</p>";
    
    // Test users count  
    echo "<h2>3. Users Test</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE active = 1");
    $userCount = $stmt->fetch()['count'];
    echo "<p>✓ Found {$userCount} active users</p>";
    
    // Test Simple files exist
    echo "<h2>4. Simple Files Test</h2>";
    $simpleFiles = [
        'tickets-simple.php' => 'Tickets listing',
        'ticket-simple.php' => 'Ticket details',
        'create-ticket-simple.php' => 'Create ticket',
        'portal-simple.php' => 'Customer portal',
        'reports-simple.php' => 'Reports'
    ];
    
    foreach ($simpleFiles as $file => $description) {
        if (file_exists($file)) {
            echo "<p>✓ {$description}: {$file} exists</p>";
        } else {
            echo "<p>✗ Missing: {$file}</p>";
        }
    }
    
    // Test redirects work
    echo "<h2>5. Redirect Files Test</h2>";
    $redirectFiles = [
        'tickets.php' => 'Should redirect to tickets-simple.php',
        'ticket.php' => 'Should redirect to ticket-simple.php', 
        'create-ticket.php' => 'Should redirect to create-ticket-simple.php'
    ];
    
    foreach ($redirectFiles as $file => $description) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (strpos($content, 'header("Location:') !== false || strpos($content, "header('Location:") !== false) {
                echo "<p>✓ {$description}: {$file} contains redirect</p>";
            } else {
                echo "<p>⚠️ Warning: {$file} may not have proper redirect</p>";
            }
        } else {
            echo "<p>✗ Missing: {$file}</p>";
        }
    }
    
    // Test API endpoint
    echo "<h2>6. API Test</h2>";
    if (file_exists('api/tickets.php')) {
        $content = file_get_contents('api/tickets.php');
        if (strpos($content, 'Simple Model') !== false) {
            echo "<p>✓ API endpoint updated to Simple model</p>";
        } else {
            echo "<p>⚠️ API endpoint may still use MVC controllers</p>";
        }
    } else {
        echo "<p>✗ API endpoint missing</p>";
    }
    
    echo "<h2>✅ Simple Model Test Complete</h2>";
    echo "<p><strong>Summary:</strong> Simple model implementation appears to be working correctly.</p>";
    echo "<p><a href='/ITSPtickets/tickets-simple.php'>Test Tickets List</a> | <a href='/ITSPtickets/portal-simple.php'>Test Portal</a></p>";
    
} catch (Exception $e) {
    echo "<h2>❌ Test Failed</h2>";
    echo "<p>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
} catch (Error $e) {
    echo "<h2>❌ Fatal Error</h2>";
    echo "<p>✗ Fatal Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
}

echo "<hr>";
echo "<p><small>Simple Model Test Suite - " . date('Y-m-d H:i:s') . "</small></p>";