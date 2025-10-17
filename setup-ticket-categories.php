<?php
require_once 'config/database.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "<h2>Setting Up Ticket Categories System</h2>";
    
    // Read and execute the SQL script
    $sql = file_get_contents('database/add-ticket-categories.sql');
    
    // Split by semicolons to execute individual statements
    $statements = explode(';', $sql);
    
    $executed = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                // Some statements might fail if columns already exist, that's OK
                if (strpos($e->getMessage(), 'Duplicate column name') === false && 
                    strpos($e->getMessage(), 'Duplicate key name') === false) {
                    echo "<p>⚠️ Warning: " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            }
        }
    }
    
    echo "<p>✅ Executed $executed SQL statements</p>";
    
    // Verify the setup
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ticket_categories WHERE parent_id IS NULL");
    $categories = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ticket_categories WHERE parent_id IS NOT NULL");
    $subcategories = $stmt->fetch()['count'];
    
    echo "<p>✅ Created $categories main categories</p>";
    echo "<p>✅ Created $subcategories subcategories</p>";
    
    // Check if columns were added to tickets table
    $stmt = $pdo->query("DESCRIBE tickets");
    $columns = $stmt->fetchAll();
    $hasCategory = false;
    $hasSubcategory = false;
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'category_id') $hasCategory = true;
        if ($column['Field'] === 'subcategory_id') $hasSubcategory = true;
    }
    
    if ($hasCategory && $hasSubcategory) {
        echo "<p>✅ Added category_id and subcategory_id columns to tickets table</p>";
    } else {
        echo "<p>❌ Failed to add category columns to tickets table</p>";
    }
    
    echo "<hr>";
    echo "<h3>Category Structure:</h3>";
    
    // Display category structure
    $stmt = $pdo->query("SELECT * FROM ticket_categories WHERE parent_id IS NULL ORDER BY sort_order");
    $mainCategories = $stmt->fetchAll();
    
    foreach ($mainCategories as $category) {
        echo "<h4>{$category['name']}</h4>";
        echo "<p><em>{$category['description']}</em></p>";
        
        $stmt = $pdo->prepare("SELECT * FROM ticket_categories WHERE parent_id = ? ORDER BY sort_order");
        $stmt->execute([$category['id']]);
        $subcats = $stmt->fetchAll();
        
        if (!empty($subcats)) {
            echo "<ul>";
            foreach ($subcats as $subcat) {
                echo "<li>{$subcat['name']} - {$subcat['description']} ({$subcat['default_time_estimate']}h)</li>";
            }
            echo "</ul>";
        }
        echo "<br>";
    }
    
    echo "<p><a href='index.php' class='btn btn-primary'>← Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

<style>
.btn {
    display: inline-block;
    padding: 10px 20px;
    background: #3b82f6;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    margin-top: 20px;
}
.btn:hover {
    opacity: 0.9;
}
h4 {
    color: #1f2937;
    margin-top: 20px;
}
</style>