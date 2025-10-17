<?php
/*
|--------------------------------------------------------------------------
| Individual Ticket View - Simple Model
|--------------------------------------------------------------------------
| Detailed ticket view with timeline, SLA tracking, and messages
*/

// Get ticket ID or key
$ticketId = $_GET['id'] ?? null;
$ticketKey = $_GET['key'] ?? null;

if (!$ticketId && !$ticketKey) {
    header('Location: /ITSPtickets/tickets-simple.php');
    exit;
}

require_once 'auth-helper.php';
require_once 'db-connection.php';
require_once 'sla-service-simple.php';
require_once 'notification-service-simple.php';

try {
    $pdo = createDatabaseConnection();
    $user = getCurrentStaff($pdo);
    
    // Initialize services
    $slaService = new SlaServiceSimple($pdo);
    $notificationService = new NotificationServiceSimple($pdo);
    
    // Get ticket details with SLA information
    $sql = "SELECT t.*,
                   r.name as requester_name, r.email as requester_email,
                   u.name as assignee_name,
                   sp.name as sla_name, sp.response_target, sp.resolution_target,
                   tc.name as category_name,
                   tsc.name as subcategory_name
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            LEFT JOIN ticket_categories tc ON t.category_id = tc.id
            LEFT JOIN ticket_categories tsc ON t.subcategory_id = tsc.id";
    
    // Search by ID or key
    if ($ticketId) {
        $sql .= " WHERE t.id = ?";
        $searchParam = $ticketId;
    } else {
        $sql .= " WHERE t.key = ?";
        $searchParam = $ticketKey;
    }
    
    // Check permissions for agents
    if ($user['role'] === 'agent') {
        $sql .= " AND t.assignee_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$searchParam, $user['id']]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$searchParam]);
    }
    
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        http_response_code(404);
        die("<h1>404 - Ticket Not Found</h1>");
    }
    
    // Get ticket messages
    $messagesSql = "SELECT tm.*, 
                           CASE 
                               WHEN tm.sender_type = 'user' THEN u.name
                               WHEN tm.sender_type = 'requester' THEN r.name
                               ELSE 'System'
                           END as sender_name
                    FROM ticket_messages tm
                    LEFT JOIN users u ON tm.sender_type = 'user' AND tm.sender_id = u.id
                    LEFT JOIN requesters r ON tm.sender_type = 'requester' AND tm.sender_id = r.id
                    WHERE tm.ticket_id = ?
                    ORDER BY tm.created_at ASC";
    
    $stmt = $pdo->prepare($messagesSql);
    $stmt->execute([$ticket['id']]);  // Use the actual ticket ID from the fetched ticket
    $messages = $stmt->fetchAll();
    
    // Get SLA compliance information
    $slaCompliance = null;
    if ($ticket['sla_policy_id']) {
        try {
            $slaCompliance = $slaService->checkSlaCompliance($ticket['id']);
        } catch (Exception $e) {
            error_log("SLA compliance check failed: " . $e->getMessage());
            // Continue without SLA info
        }
    }
    
    // Get recent notifications for this ticket (optional - table may not exist)
    $recentNotifications = [];
    try {
        // Check if notifications table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'notifications'");
        if ($tableCheck->rowCount() > 0) {
            $notificationsStmt = $pdo->prepare("
                SELECT n.*, COUNT(*) as notification_count
                FROM notifications n
                WHERE n.ticket_id = ?
                GROUP BY n.event_type
                ORDER BY n.created_at DESC
                LIMIT 5
            ");
            $notificationsStmt->execute([$ticket['id']]);
            $recentNotifications = $notificationsStmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Notifications query failed: " . $e->getMessage());
        // Continue without notifications
    }
    
    // Get ticket events for timeline
    $ticketEvents = [];
    try {
        $eventsStmt = $pdo->prepare("
            SELECT te.*, u.name as user_name
            FROM ticket_events te
            LEFT JOIN users u ON te.user_id = u.id
            WHERE te.ticket_id = ?
            ORDER BY te.created_at DESC
        ");
        $eventsStmt->execute([$ticket['id']]);
        $ticketEvents = $eventsStmt->fetchAll();
    } catch (Exception $e) {
        error_log("Ticket events query failed: " . $e->getMessage());
        // Continue without events
    }
    
    // Calculate total worked time (after events are fetched)
    $totalWorkedTime = 0;
    if (!empty($ticketEvents)) {
        $totalWorkedTime = calculateWorkedTime($ticketEvents, $ticket['status']);
    }
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Helper function to get timeline event icon and category
function getTimelineEventInfo($eventType) {
    $eventMap = [
        'status_change' => ['icon' => '🔄', 'category' => 'status-change'],
        'assignment' => ['icon' => '👤', 'category' => 'assignment'],
        'priority_change' => ['icon' => '⚡', 'category' => 'priority-change'],
        'sla_breach' => ['icon' => '⚠️', 'category' => 'sla-event'],
        'sla_warning' => ['icon' => '⏰', 'category' => 'sla-event'],
        'message_added' => ['icon' => '💬', 'category' => 'message'],
        'ticket_created' => ['icon' => '🎫', 'category' => 'system'],
        'ticket_resolved' => ['icon' => '✅', 'category' => 'system'],
        'ticket_closed' => ['icon' => '🔒', 'category' => 'system'],
        'escalation' => ['icon' => '📈', 'category' => 'system'],
        'category_change' => ['icon' => '📂', 'category' => 'system'],
    ];
    
    return $eventMap[$eventType] ?? ['icon' => '📝', 'category' => 'system'];
}

// Helper function to format event description
function formatEventDescription($event) {
    $description = htmlspecialchars($event['description']);
    
    // Add old/new value formatting if available
    if ($event['old_value'] && $event['new_value']) {
        $description .= '<div class="timeline-changes">';
        $description .= '<span class="timeline-old-value">' . htmlspecialchars($event['old_value']) . '</span>';
        $description .= ' → ';
        $description .= '<span class="timeline-new-value">' . htmlspecialchars($event['new_value']) . '</span>';
        $description .= '</div>';
    } elseif ($event['new_value']) {
        $description .= '<div class="timeline-changes">';
        $description .= 'New value: <span class="timeline-new-value">' . htmlspecialchars($event['new_value']) . '</span>';
        $description .= '</div>';
    }
    
    return $description;
}

// Helper function to calculate total worked time (time spent in_progress)
function calculateWorkedTime($ticketEvents, $currentStatus) {
    $totalWorkedMinutes = 0;
    $inProgressPeriods = [];
    
    // If no events, but currently in_progress, assume it's been in progress since creation
    if (empty($ticketEvents) && $currentStatus === 'in_progress') {
        // We don't have creation time here, so return 0
        return 0;
    }
    
    // Sort events chronologically (oldest first)
    $events = array_reverse($ticketEvents);
    
    $currentInProgressStart = null;
    $lastKnownStatus = null;
    
    foreach ($events as $event) {
        if ($event['event_type'] === 'status_change') {
            $eventTime = strtotime($event['created_at']);
            $oldStatus = $event['old_value'];
            $newStatus = $event['new_value'];
            
            // Skip redundant status changes (same old and new status)
            if ($oldStatus === $newStatus) {
                continue;
            }
            
            // If we're entering in_progress
            if ($newStatus === 'in_progress') {
                $currentInProgressStart = $eventTime;
            }
            // If we're leaving in_progress
            elseif ($oldStatus === 'in_progress' && $currentInProgressStart !== null) {
                $duration = $eventTime - $currentInProgressStart;
                $totalWorkedMinutes += $duration / 60;
                $currentInProgressStart = null;
            }
            
            $lastKnownStatus = $newStatus;
        }
    }
    
    // If currently in_progress and we have an active start time
    if ($currentStatus === 'in_progress' && $currentInProgressStart !== null) {
        $duration = time() - $currentInProgressStart;
        $totalWorkedMinutes += $duration / 60;
    }
    
    // Handle edge case: if ticket is currently in_progress but we never saw it enter in_progress
    // This could happen if the ticket was created as in_progress or if we're missing events
    if ($currentStatus === 'in_progress' && $currentInProgressStart === null && !empty($events)) {
        // Find the most recent event time and assume it started in_progress then
        $lastEventTime = strtotime($events[count($events) - 1]['created_at']);
        $duration = time() - $lastEventTime;
        $totalWorkedMinutes += $duration / 60;
    }
    
    return max(0, $totalWorkedMinutes); // Ensure we never return negative time
}

// Helper function to format worked time nicely
function formatWorkedTime($minutes) {
    if ($minutes < 60) {
        return round($minutes, 1) . 'm';
    } elseif ($minutes < 1440) { // Less than 24 hours
        $hours = floor($minutes / 60);
        $remainingMinutes = round($minutes % 60);
        return $hours . 'h ' . ($remainingMinutes > 0 ? $remainingMinutes . 'm' : '');
    } else { // More than 24 hours
        $days = floor($minutes / 1440);
        $hours = floor(($minutes % 1440) / 60);
        return $days . 'd ' . ($hours > 0 ? $hours . 'h' : '');
    }
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Ticket <?= htmlspecialchars($ticket['key']) ?> - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc; 
            line-height: 1.6;
        }
        .container { 
            max-width: 1200px; 
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
        .header h1 { color: #1f2937; }
        .user-info { display: flex; gap: 20px; align-items: center; }
        .user-info a { color: #3b82f6; text-decoration: none; }
        .user-info a:hover { text-decoration: underline; }
        .ticket-info {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .ticket-info h2 {
            margin-bottom: 15px;
            color: #1f2937;
        }
        .ticket-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .ticket-priority, .ticket-status { 
            font-size: 11px; 
            padding: 4px 12px; 
            border-radius: 12px; 
            font-weight: 500; 
            text-transform: uppercase;
        }
        .priority-urgent { background: #fee2e2; color: #991b1b; }
        .priority-high { background: #fef3c7; color: #92400e; }
        .priority-normal { background: #e0f2fe; color: #0369a1; }
        .priority-low { background: #f0f9ff; color: #0284c7; }
        .status-new { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #fef3c7; color: #92400e; }
        .status-waiting { background: #f3e8ff; color: #7c3aed; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        .ticket-description {
            margin: 20px 0;
            padding: 20px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .ticket-details p {
            margin-bottom: 10px;
            color: #374151;
        }
        .messages-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .message-item {
            border-bottom: 1px solid #e5e7eb;
            padding: 15px 0;
        }
        .message-item:last-child {
            border-bottom: none;
        }
        .message-item.private {
            background: #fef3c7;
            margin: 0 -15px;
            padding: 15px;
            border-radius: 6px;
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .message-header strong {
            color: #1f2937;
        }
        .message-date {
            color: #6b7280;
            font-size: 12px;
        }
        .private-badge {
            background: #f59e0b;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 500;
        }
        .message-content {
            color: #374151;
            white-space: pre-wrap;
        }
        .sla-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .sla-indicator {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 10px;
        }
        .sla-compliant { background: #d1fae5; color: #065f46; }
        .sla-breach { background: #fee2e2; color: #991b1b; }
        .sla-warning { background: #fef3c7; color: #92400e; }
        .sla-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .sla-metric {
            padding: 10px;
            background: #f9fafb;
            border-radius: 6px;
            text-align: center;
        }
        .sla-metric .value {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        .sla-metric .label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        .notification-section {
            background: #f8fafc;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .notification-badge {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 5px;
        }
        .timeline-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .timeline-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .timeline-marker {
            position: absolute;
            left: -32px;
            top: 1px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: white;
            font-weight: bold;
        }
        .timeline-marker.status-change { background: #3b82f6; }
        .timeline-marker.assignment { background: #10b981; }
        .timeline-marker.sla-event { background: #f59e0b; }
        .timeline-marker.priority-change { background: #8b5cf6; }
        .timeline-marker.system { background: #6b7280; }
        .timeline-marker.message { background: #06b6d4; }
        .timeline-content {
            background: #f9fafb;
            padding: 12px 15px;
            border-radius: 6px;
            border-left: 3px solid #e5e7eb;
        }
        .timeline-content.status-change { border-left-color: #3b82f6; }
        .timeline-content.assignment { border-left-color: #10b981; }
        .timeline-content.sla-event { border-left-color: #f59e0b; }
        .timeline-content.priority-change { border-left-color: #8b5cf6; }
        .timeline-content.system { border-left-color: #6b7280; }
        .timeline-content.message { border-left-color: #06b6d4; }
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        .timeline-description {
            color: #374151;
            font-size: 14px;
            line-height: 1.5;
        }
        .timeline-meta {
            color: #6b7280;
            font-size: 12px;
        }
        .timeline-user {
            font-weight: 500;
            color: #1f2937;
        }
        .timeline-changes {
            margin-top: 8px;
            font-size: 13px;
            color: #6b7280;
        }
        .timeline-old-value {
            background: #fee2e2;
            padding: 2px 4px;
            border-radius: 3px;
            text-decoration: line-through;
        }
        .timeline-new-value {
            background: #d1fae5;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: 500;
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
                font-size: 22px;
                line-height: 1.3;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
                font-size: 14px;
            }
            
            .ticket-info {
                padding: 20px 15px;
                margin-bottom: 20px;
            }
            
            .ticket-info h2 {
                font-size: 18px;
                line-height: 1.4;
                margin-bottom: 12px;
            }
            
            .ticket-meta {
                flex-direction: column;
                gap: 8px;
                align-items: flex-start;
                margin-bottom: 15px;
            }
            
            .ticket-priority, .ticket-status {
                font-size: 10px;
                padding: 3px 8px;
                border-radius: 10px;
            }
            
            .ticket-description {
                margin: 15px 0;
                padding: 15px;
                font-size: 14px;
            }
            
            .ticket-details p {
                margin-bottom: 8px;
                font-size: 14px;
                line-height: 1.5;
            }
            
            .sla-section {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .sla-section h3 {
                font-size: 16px;
            }
            
            .sla-details {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .sla-metric {
                padding: 8px;
            }
            
            .sla-metric .value {
                font-size: 16px;
            }
            
            .sla-metric .label {
                font-size: 11px;
            }
            
            .messages-section {
                padding: 20px 15px;
            }
            
            .messages-section h3 {
                font-size: 18px;
                margin-bottom: 15px;
            }
            
            .message-item {
                padding: 12px 0;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .message-item.private {
                margin: 0 -10px;
                padding: 12px 10px;
            }
            
            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
                margin-bottom: 10px;
            }
            
            .message-header strong {
                font-size: 14px;
            }
            
            .message-date {
                font-size: 11px;
            }
            
            .private-badge {
                font-size: 9px;
                padding: 1px 5px;
            }
            
            .message-content {
                font-size: 14px;
                line-height: 1.5;
            }
            
            .timeline-section {
                padding: 20px 15px;
                margin-bottom: 20px;
            }
            
            .timeline-section h3 {
                font-size: 18px;
                margin-bottom: 15px;
            }
            
            .timeline {
                padding-left: 25px;
            }
            
            .timeline::before {
                left: 12px;
            }
            
            .timeline-marker {
                left: -27px;
                width: 25px;
                height: 25px;
                font-size: 14px;
            }
            
            .timeline-content {
                padding: 10px 12px;
                font-size: 13px;
            }
            
            .timeline-description {
                font-size: 13px;
                line-height: 1.4;
            }
            
            .timeline-meta {
                font-size: 11px;
            }
            
            .timeline-user {
                font-size: 13px;
            }
            
            .timeline-changes {
                font-size: 12px;
                margin-top: 6px;
            }
            
            .timeline-old-value, .timeline-new-value {
                padding: 1px 3px;
                font-size: 11px;
            }
            
            .notification-section {
                padding: 12px;
                margin-bottom: 15px;
            }
            
            .notification-section h4 {
                font-size: 14px;
                margin-bottom: 10px;
            }
            
            .notification-badge {
                font-size: 9px;
                padding: 1px 5px;
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
                font-size: 18px;
            }
            
            .ticket-info {
                padding: 15px 10px;
            }
            
            .ticket-info h2 {
                font-size: 16px;
            }
            
            .ticket-description {
                padding: 12px;
                font-size: 13px;
            }
            
            .sla-section {
                padding: 12px 10px;
            }
            
            .sla-details {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .sla-metric {
                padding: 6px;
                text-align: left;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .sla-metric .value {
                font-size: 14px;
                order: 2;
            }
            
            .sla-metric .label {
                font-size: 10px;
                order: 1;
            }
            
            .messages-section {
                padding: 15px 10px;
            }
            
            .timeline-section {
                padding: 15px 10px;
            }
            
            .timeline {
                padding-left: 20px;
            }
            
            .timeline::before {
                left: 10px;
            }
            
            .timeline-marker {
                left: -22px;
                width: 20px;
                height: 20px;
                font-size: 12px;
            }
            
            .timeline-content {
                padding: 8px 10px;
                font-size: 12px;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .message-item {
                min-height: 44px; /* Touch-friendly target size */
            }
            
            .timeline-item {
                min-height: 44px;
            }
            
            .sla-metric {
                min-height: 44px;
            }
            
            .user-info a {
                padding: 8px 12px;
                border-radius: 6px;
                background: #f3f4f6;
                text-align: center;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
        
        /* Landscape phones */
        @media (max-width: 768px) and (orientation: landscape) {
            .sla-details {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .ticket-meta {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Ticket <?= htmlspecialchars($ticket['key']) ?></h1>
            <div class='user-info'>
                <a href='/ITSPtickets/update-ticket-simple.php?id=<?= $ticket['id'] ?>'>Update Ticket</a>
                <a href='/ITSPtickets/tickets-simple.php'>Back to Tickets</a>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
            </div>
        </div>
        
        <div class='ticket-info'>
            <h2><?= htmlspecialchars($ticket['subject']) ?></h2>
            <div class='ticket-meta'>
                <span class='ticket-priority priority-<?= strtolower($ticket['priority']) ?>'>
                    <?= htmlspecialchars($ticket['priority']) ?>
                </span>
                <span class='ticket-status status-<?= str_replace('_', '-', strtolower($ticket['status'])) ?>'>
                    <?= htmlspecialchars($ticket['status']) ?>
                </span>
                <span>Type: <?= htmlspecialchars($ticket['type']) ?></span>
                <?php if ($ticket['category_name']): ?>
                    <span>Category: <?= htmlspecialchars($ticket['category_name']) ?>
                        <?php if ($ticket['subcategory_name']): ?>
                            > <?= htmlspecialchars($ticket['subcategory_name']) ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class='ticket-description'>
                <?= nl2br(htmlspecialchars($ticket['description'])) ?>
            </div>
            
            <div class='ticket-details'>
                <p><strong>Requester:</strong> <?= htmlspecialchars($ticket['requester_name']) ?> (<?= htmlspecialchars($ticket['requester_email']) ?>)</p>
                <?php if ($ticket['assignee_name']): ?>
                    <p><strong>Assigned to:</strong> <?= htmlspecialchars($ticket['assignee_name']) ?></p>
                <?php endif; ?>
                <?php if ($ticket['category_name']): ?>
                    <p><strong>Category:</strong> <?= htmlspecialchars($ticket['category_name']) ?>
                        <?php if ($ticket['subcategory_name']): ?>
                            > <?= htmlspecialchars($ticket['subcategory_name']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <p><strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($ticket['created_at'])) ?></p>
                <?php if ($ticket['resolved_at']): ?>
                    <p><strong>Resolved:</strong> <?= date('M j, Y g:i A', strtotime($ticket['resolved_at'])) ?></p>
                <?php endif; ?>
                
                <?php if (($ticket['time_spent'] ?? 0) > 0 || ($ticket['billable_hours'] ?? 0) > 0): ?>
                    <div style='background: #f0f9ff; padding: 15px; border-radius: 6px; margin-top: 15px; border-left: 3px solid #3b82f6;'>
                        <p style='margin-bottom: 8px; font-weight: 600; color: #1e40af;'>⏱️ Time Tracking</p>
                        <?php if (($ticket['time_spent'] ?? 0) > 0): ?>
                            <p><strong>Time Spent:</strong> <?= number_format($ticket['time_spent'], 2) ?> hours</p>
                        <?php endif; ?>
                        <?php if (($ticket['billable_hours'] ?? 0) > 0): ?>
                            <p><strong>Billable Hours:</strong> <?= number_format($ticket['billable_hours'], 2) ?> hours</p>
                        <?php endif; ?>
                        <?php if (($ticket['time_spent'] ?? 0) > 0 && ($ticket['billable_hours'] ?? 0) > 0): ?>
                            <?php 
                            $billablePercentage = ($ticket['billable_hours'] / $ticket['time_spent']) * 100;
                            ?>
                            <p style='font-size: 13px; color: #6b7280; margin-top: 5px;'>
                                Billable: <?= number_format($billablePercentage, 1) ?>% of time spent
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($slaCompliance): ?>
                <div class='sla-section'>
                    <h3>SLA Information
                        <?php if (!$slaCompliance['response_compliant'] || !$slaCompliance['resolution_compliant']): ?>
                            <span class='sla-indicator sla-breach'>SLA Breach</span>
                        <?php else: ?>
                            <span class='sla-indicator sla-compliant'>SLA Compliant</span>
                        <?php endif; ?>
                    </h3>
                    
                    <p><strong>SLA Policy:</strong> <?= htmlspecialchars($slaCompliance['sla_name']) ?></p>
                    
                    <div class='sla-details'>
                        <div class='sla-metric'>
                            <div class='value' style='color: <?= $slaCompliance['response_compliant'] ? '#059669' : '#dc2626' ?>'>
                                <?php if (isset($slaCompliance['response_hours'])): ?>
                                    <?php if ($slaCompliance['response_hours'] < 1): ?>
                                        <?= round($slaCompliance['response_hours'] * 60) ?>m
                                    <?php else: ?>
                                        <?= round($slaCompliance['response_hours'], 1) ?>h
                                    <?php endif; ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </div>
                            <div class='label'>Response Time</div>
                            <div style='font-size: 11px; color: #6b7280;'>
                                Target: <?= round($slaCompliance['response_target'] / 60, 1) ?>h
                            </div>
                        </div>
                        
                        <div class='sla-metric'>
                            <div class='value' style='color: <?= $slaCompliance['resolution_compliant'] ? '#059669' : '#dc2626' ?>'>
                                <?php if (isset($slaCompliance['resolution_hours'])): ?>
                                    <?php if ($slaCompliance['resolution_hours'] < 1): ?>
                                        <?= round($slaCompliance['resolution_hours'] * 60) ?>m
                                    <?php else: ?>
                                        <?= round($slaCompliance['resolution_hours'], 1) ?>h
                                    <?php endif; ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </div>
                            <div class='label'>Resolution Time</div>
                            <div style='font-size: 11px; color: #6b7280;'>
                                Target: <?= round($slaCompliance['resolution_target'] / 60, 1) ?>h
                            </div>
                        </div>
                        
                        <div class='sla-metric'>
                            <div class='value'>
                                <?= $ticket['status'] === 'resolved' || $ticket['status'] === 'closed' ? '✓' : '⏳' ?>
                            </div>
                            <div class='label'>Status</div>
                            <div style='font-size: 11px; color: #6b7280;'>
                                <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                            </div>
                        </div>
                        
                        <div class='sla-metric'>
                            <div class='value' style='color: #059669;'>
                                <?= formatWorkedTime($totalWorkedTime) ?>
                            </div>
                            <div class='label'>Worked Time</div>
                            <div style='font-size: 11px; color: #6b7280;'>
                                Time in Progress
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($recentNotifications)): ?>
                <div class='notification-section'>
                    <h4>Recent Notifications</h4>
                    <?php foreach ($recentNotifications as $notification): ?>
                        <div style='margin: 5px 0; font-size: 13px; color: #6b7280;'>
                            <?= ucfirst(str_replace('_', ' ', $notification['event_type'])) ?>
                            <span class='notification-badge'><?= $notification['notification_count'] ?></span>
                            <span style='float: right;'><?= date('M j, g:i A', strtotime($notification['created_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($ticketEvents)): ?>
        <div class='timeline-section'>
            <h3>Ticket Activity Timeline</h3>
            <div class='timeline'>
                <?php foreach ($ticketEvents as $event):
                    $eventInfo = getTimelineEventInfo($event['event_type']);
                ?>
                    <div class='timeline-item'>
                        <div class='timeline-marker <?= $eventInfo['category'] ?>'>
                            <?= $eventInfo['icon'] ?>
                        </div>
                        <div class='timeline-content <?= $eventInfo['category'] ?>'>
                            <div class='timeline-header'>
                                <div class='timeline-user'>
                                    <?= $event['user_name'] ? htmlspecialchars($event['user_name']) : 'System' ?>
                                </div>
                                <div class='timeline-meta'>
                                    <?= date('M j, Y g:i A', strtotime($event['created_at'])) ?>
                                </div>
                            </div>
                            <div class='timeline-description'>
                                <?= formatEventDescription($event) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class='messages-section'>
            <h3>Messages</h3>
            <?php if (empty($messages)): ?>
                <p>No messages yet.</p>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <div class='message-item <?= $message['is_private'] ? 'private' : '' ?>'>
                        <div class='message-header'>
                            <strong><?= htmlspecialchars($message['sender_name']) ?></strong>
                            <div>
                                <span class='message-date'><?= date('M j, Y g:i A', strtotime($message['created_at'])) ?></span>
                                <?php if ($message['is_private']): ?>
                                    <span class='private-badge'>Private</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class='message-content'><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>