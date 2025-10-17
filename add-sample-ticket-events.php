<?php
// Sample ticket events creator for testing timeline functionality
require_once 'config/database.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "Adding sample ticket events for timeline testing...\n\n";
    
    // Get a sample ticket
    $ticketStmt = $pdo->query("SELECT id FROM tickets LIMIT 1");
    $ticket = $ticketStmt->fetch();
    
    if (!$ticket) {
        echo "No tickets found. Please create a ticket first.\n";
        exit;
    }
    
    $ticketId = $ticket['id'];
    echo "Adding events to ticket ID: {$ticketId}\n";
    
    // Get a sample user
    $userStmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $user = $userStmt->fetch();
    $userId = $user ? $user['id'] : null;
    
    // Sample events to add
    $sampleEvents = [
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'ticket_created',
            'description' => 'Ticket was created',
            'old_value' => null,
            'new_value' => 'new',
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'status_change',
            'description' => 'Status changed from new to in_progress',
            'old_value' => 'new',
            'new_value' => 'in_progress',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour 45 minutes'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'assignment',
            'description' => 'Ticket assigned to technical support team',
            'old_value' => null,
            'new_value' => 'Technical Support',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour 30 minutes'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'priority_change',
            'description' => 'Priority elevated due to customer impact',
            'old_value' => 'normal',
            'new_value' => 'high',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => null,
            'event_type' => 'sla_warning',
            'description' => 'SLA response time approaching deadline',
            'old_value' => null,
            'new_value' => '15 minutes remaining',
            'created_at' => date('Y-m-d H:i:s', strtotime('-45 minutes'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'message_added',
            'description' => 'Internal note added for coordination',
            'old_value' => null,
            'new_value' => 'Internal communication',
            'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'category_change',
            'description' => 'Category updated for better classification',
            'old_value' => 'General Support',
            'new_value' => 'Technical Issue',
            'created_at' => date('Y-m-d H:i:s', strtotime('-15 minutes'))
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'event_type' => 'status_change',
            'description' => 'Status updated to resolved after troubleshooting',
            'old_value' => 'in_progress',
            'new_value' => 'resolved',
            'created_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))
        ]
    ];
    
    // Insert sample events
    $insertStmt = $pdo->prepare("
        INSERT INTO ticket_events (ticket_id, user_id, event_type, description, old_value, new_value, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($sampleEvents as $event) {
        $insertStmt->execute([
            $event['ticket_id'],
            $event['user_id'],
            $event['event_type'],
            $event['description'],
            $event['old_value'],
            $event['new_value'],
            $event['created_at']
        ]);
        echo "✓ Added: {$event['event_type']} - {$event['description']}\n";
    }
    
    echo "\n✅ Successfully added " . count($sampleEvents) . " sample ticket events!\n";
    echo "🎯 Timeline feature is ready for testing.\n";
    echo "🔗 View the ticket at: /ITSPtickets/ticket-simple.php?id={$ticketId}\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>