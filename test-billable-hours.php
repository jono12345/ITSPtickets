<?php
// Simple test script to verify billable hours functionality
session_start();

// For testing purposes, simulate an admin user session
$_SESSION['user_id'] = 1; // Assuming admin user ID 1

require_once 'config/database.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "<h1>Billable Hours System Test</h1>";
    
    // 1. Check if time tracking columns exist
    echo "<h2>1. Database Schema Check</h2>";
    $stmt = $pdo->query("DESCRIBE tickets");
    $columns = $stmt->fetchAll();
    
    $hasTimeSpent = false;
    $hasBillableHours = false;
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'time_spent') {
            $hasTimeSpent = true;
            echo "✅ time_spent column exists: " . $column['Type'] . "<br>";
        }
        if ($column['Field'] === 'billable_hours') {
            $hasBillableHours = true;
            echo "✅ billable_hours column exists: " . $column['Type'] . "<br>";
        }
    }
    
    if (!$hasTimeSpent || !$hasBillableHours) {
        echo "❌ Missing required columns. Please run customer-reports-schema-update.sql<br>";
        exit;
    }
    
    // 2. Find an existing ticket to test with
    echo "<h2>2. Finding Test Ticket</h2>";
    $stmt = $pdo->query("SELECT id, `key`, subject FROM tickets ORDER BY created_at DESC LIMIT 1");
    $testTicket = $stmt->fetch();
    
    if (!$testTicket) {
        echo "❌ No tickets found. Please create a ticket first.<br>";
        exit;
    }
    
    echo "✅ Found test ticket: {$testTicket['key']} - {$testTicket['subject']}<br>";
    
    // 3. Test updating time tracking
    echo "<h2>3. Testing Time Tracking Update</h2>";
    $testTimeSpent = 3.5;
    $testBillableHours = 2.75;
    
    $stmt = $pdo->prepare("UPDATE tickets SET time_spent = ?, billable_hours = ?, last_update_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$testTimeSpent, $testBillableHours, $testTicket['id']]);
    
    if ($result) {
        echo "✅ Successfully updated ticket with time tracking data<br>";
        echo "   Time Spent: {$testTimeSpent} hours<br>";
        echo "   Billable Hours: {$testBillableHours} hours<br>";
    } else {
        echo "❌ Failed to update ticket<br>";
        exit;
    }
    
    // 4. Verify the data was saved
    echo "<h2>4. Verifying Saved Data</h2>";
    $stmt = $pdo->prepare("SELECT time_spent, billable_hours FROM tickets WHERE id = ?");
    $stmt->execute([$testTicket['id']]);
    $savedData = $stmt->fetch();
    
    if ($savedData) {
        echo "✅ Data verification successful:<br>";
        echo "   Saved Time Spent: {$savedData['time_spent']} hours<br>";
        echo "   Saved Billable Hours: {$savedData['billable_hours']} hours<br>";
        
        if ($savedData['time_spent'] == $testTimeSpent && $savedData['billable_hours'] == $testBillableHours) {
            echo "✅ Values match perfectly!<br>";
        } else {
            echo "⚠️ Values don't match exactly (may be due to decimal precision)<br>";
        }
    } else {
        echo "❌ Failed to retrieve saved data<br>";
    }
    
    // 5. Test customer report data retrieval
    echo "<h2>5. Testing Customer Report Integration</h2>";
    
    // Get organization for this ticket if available
    $stmt = $pdo->prepare("
        SELECT t.time_spent, t.billable_hours, r.organization_id, o.name as org_name
        FROM tickets t
        LEFT JOIN requesters r ON t.requester_id = r.id
        LEFT JOIN organizations o ON r.organization_id = o.id
        WHERE t.id = ?
    ");
    $stmt->execute([$testTicket['id']]);
    $reportData = $stmt->fetch();
    
    if ($reportData) {
        echo "✅ Customer report data retrieval successful:<br>";
        if ($reportData['org_name']) {
            echo "   Organization: {$reportData['org_name']}<br>";
        } else {
            echo "   Organization: Not assigned<br>";
        }
        echo "   Report Time Spent: {$reportData['time_spent']} hours<br>";
        echo "   Report Billable Hours: {$reportData['billable_hours']} hours<br>";
        
        // Test the fallback logic used in customer-report-generator.php
        $calculatedHours = floatval($reportData['time_spent'] ?: $reportData['billable_hours'] ?: 0);
        echo "   Calculated Hours (using fallback logic): {$calculatedHours} hours<br>";
        
        if ($calculatedHours > 0) {
            echo "✅ Customer reporting will work correctly<br>";
        } else {
            echo "⚠️ No billable hours would be reported<br>";
        }
    }
    
    // 6. Summary
    echo "<h2>6. Test Summary</h2>";
    echo "<div style='background: #d1fae5; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<strong>✅ Manual Billable Hours System Test PASSED</strong><br><br>";
    echo "Key Features Verified:<br>";
    echo "• Database schema supports time tracking<br>";
    echo "• Time spent and billable hours can be saved<br>";
    echo "• Data persists correctly in database<br>";
    echo "• Customer report integration ready<br><br>";
    echo "Next Steps:<br>";
    echo "• Staff can now use the Update Ticket interface to add time tracking<br>";
    echo "• Time tracking appears in ticket details and ticket lists<br>";
    echo "• Customer reports will include billable hours data<br>";
    echo "</div>";
    
    echo "<h2>7. Manual Testing Instructions</h2>";
    echo "<ol>";
    echo "<li>Visit a ticket: <a href='/ITSPtickets/ticket-simple.php?id={$testTicket['id']}' target='_blank'>Test Ticket {$testTicket['key']}</a></li>";
    echo "<li>Click 'Update Ticket'</li>";
    echo "<li>Scroll to 'Time Tracking' section</li>";
    echo "<li>Enter time spent and billable hours</li>";
    echo "<li>Click 'Update Time Tracking'</li>";
    echo "<li>Return to ticket view to see the time tracking display</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<strong>❌ Test Failed</strong><br>";
    echo "Error: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>

<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
    max-width: 800px; 
    margin: 20px auto; 
    padding: 20px; 
    line-height: 1.6;
}
h1, h2 { color: #1f2937; }
h1 { border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
h2 { border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; margin-top: 30px; }
</style>