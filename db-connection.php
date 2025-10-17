<?php
/*
|--------------------------------------------------------------------------
| Simple Model Database Connection Helper
|--------------------------------------------------------------------------
| Standardized database connection for all Simple Model files
| Ensures consistent security options across the application
*/

/**
 * Create standardized PDO database connection
 * Uses configuration from config/database.php
 */
function createDatabaseConnection() {
    $config = require __DIR__ . '/config/database.php';
    
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Alternative method: Get database connection
 * For backward compatibility with existing code patterns
 */
function getDatabaseConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        $pdo = createDatabaseConnection();
    }
    
    return $pdo;
}