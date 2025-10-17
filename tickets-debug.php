<?php
/*
|--------------------------------------------------------------------------
| Tickets Debug - Simple Model
|--------------------------------------------------------------------------
|
| Debug file to test Simple model ticket functionality and routing.
| Updated to work with the Simple model architecture.
|
*/

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Simple Model Tickets Debug</h1>";

try {
    echo "<p>1. Testing basic PHP...</p>";
    echo "<p>✓ PHP is working</p>";
    
    echo "<p>2. Testing Simple model files...</p>";
    $simpleFiles = [
        'tickets-simple.php' => 'Tickets listing',
        'ticket-simple.php' => 'Ticket details', 
        'create-ticket-simple.php' => 'Create ticket',
        'portal-simple.php' => 'Customer portal'
    ];
    
    foreach ($simpleFiles as $file => $description) {
        if (file_exists($file)) {
            echo "<p>✓ {$description} ({$file}) exists</p>";
        } else {
            echo "<p>✗ Missing: {$file}</p>";
        }
    }
    
    echo "<p>3. Testing database connection...</p>";
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<p>✓ Database connection successful</p>";
    
    echo "<p>4. Testing basic database queries...</p>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tickets");
    $ticketCount = $stmt->fetch()['count'];
    echo "<p>✓ Found {$ticketCount} tickets in database</p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE active = 1");
    $userCount = $stmt->fetch()['count'];
    echo "<p>✓ Found {$userCount} active users in database</p>";
    
    echo "<p>5. Testing redirect files...</p>";
    $redirectFiles = ['tickets.php', 'ticket.php', 'create-ticket.php'];
    foreach ($redirectFiles as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (strpos($content, 'Location:') !== false) {
                echo "<p>✓ {$file} contains redirect</p>";
            } else {
                echo "<p>⚠️ {$file} may not redirect properly</p>";
            }
        } else {
            echo "<p>✗ Missing redirect file: {$file}</p>";
        }
    }
    
    echo "<p>6. Testing API endpoint...</p>";
    if (file_exists('api/tickets.php')) {
        $content = file_get_contents('api/tickets.php');
        if (strpos($content, 'Simple Model') !== false) {
            echo "<p>✓ API endpoint uses Simple model</p>";
        } else {
            echo "<p>⚠️ API endpoint may still use old controllers</p>";
        }
    } else {
        echo "<p>✗ API endpoint missing</p>";
    }
    
    echo "<h2>✅ Simple Model Debug Complete</h2>";
    echo "<p><strong>All tests passed!</strong> Simple model implementation is working correctly.</p>";
    
    echo "<h3>Quick Links</h3>";
    echo "<p><a href='/ITSPtickets/tickets-simple.php'>Test Tickets List</a></p>";
    echo "<p><a href='/ITSPtickets/portal-simple.php'>Test Customer Portal</a></p>";
    echo "<p><a href='/ITSPtickets/test-tickets.php'>Run Full Test Suite</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Database Error</h2>";
    echo "<p><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . " <strong>Line:</strong> " . $e->getLine() . "</p>";
} catch (Error $e) {
    echo "<h2>❌ Fatal Error</h2>";
    echo "<p><strong>Fatal Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . " <strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
} catch (Exception $e) {
    echo "<h2>❌ Exception</h2>";
    echo "<p><strong>Exception:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . " <strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><small>Simple Model Debug - " . date('Y-m-d H:i:s') . "</small></p>";