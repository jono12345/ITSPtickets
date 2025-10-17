<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

require_once 'config/database.php';
require_once 'sla-service-simple.php';

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Initialize SLA service
    $slaService = new SlaServiceSimple($pdo);
    
    // Get current user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(401);
        exit('User not found');
    }
    
    // Get last check timestamp from session or use current time
    $lastCheck = $_SESSION['last_notification_check'] ?? date('Y-m-d H:i:s', strtotime('-5 minutes'));
    
    $notifications = [];
    
    // 1. Check for new tickets (for admins/supervisors)
    if (in_array($user['role'], ['admin', 'supervisor'])) {
        $sql = "SELECT COUNT(*) as count FROM tickets WHERE created_at > ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lastCheck]);
        $newTickets = $stmt->fetch()['count'];
        
        if ($newTickets > 0) {
            $notifications[] = [
                'type' => 'new_tickets',
                'title' => 'New Tickets',
                'message' => "{$newTickets} new ticket" . ($newTickets > 1 ? 's' : '') . " created",
                'count' => $newTickets,
                'priority' => 'info',
                'url' => '/ITSPtickets/tickets-simple.php?status=new',
                'timestamp' => date('c')
            ];
        }
    }
    
    // 2. Check for new assignments (for agents)
    if ($user['role'] === 'agent') {
        $sql = "SELECT COUNT(*) as count FROM tickets WHERE assignee_id = ? AND updated_at > ? AND status = 'new'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['id'], $lastCheck]);
        $newAssignments = $stmt->fetch()['count'];
        
        if ($newAssignments > 0) {
            $notifications[] = [
                'type' => 'new_assignments',
                'title' => 'New Assignments',
                'message' => "{$newAssignments} new ticket" . ($newAssignments > 1 ? 's' : '') . " assigned to you",
                'count' => $newAssignments,
                'priority' => 'warning',
                'url' => '/ITSPtickets/tickets-simple.php?assignee=' . $user['id'] . '&status=new',
                'timestamp' => date('c')
            ];
        }
    }
    
    // 3. Check for SLA breaches (with cooldown to prevent spam)
    try {
        // Only check for SLA breaches every 5 minutes to prevent spam
        $lastSlaCheck = $_SESSION['last_sla_breach_check'] ?? 0;
        if (time() - $lastSlaCheck > 300) { // 5 minutes = 300 seconds
            $slaBreaches = $slaService->getSlaBreaches();
            
            // Track notified breaches to prevent duplicates
            $notifiedBreaches = $_SESSION['notified_sla_breaches'] ?? [];
            $newBreaches = [];
            
            foreach ($slaBreaches as $breach) {
                $ticketId = $breach['ticket']['id'];
                if (!in_array($ticketId, $notifiedBreaches)) {
                    $newBreaches[] = $breach;
                    $notifiedBreaches[] = $ticketId;
                }
            }
            
            if (!empty($newBreaches)) {
                $breachCount = count($newBreaches);
                $notifications[] = [
                    'type' => 'sla_breach',
                    'title' => 'SLA Breach Alert',
                    'message' => "{$breachCount} ticket" . ($breachCount > 1 ? 's have' : ' has') . " breached SLA targets",
                    'count' => $breachCount,
                    'priority' => 'critical',
                    'url' => '/ITSPtickets/tickets-simple.php?sla_filter=breached',
                    'timestamp' => date('c')
                ];
            }
            
            // Update session tracking
            $_SESSION['notified_sla_breaches'] = array_slice($notifiedBreaches, -50); // Keep last 50 to prevent memory bloat
            $_SESSION['last_sla_breach_check'] = time();
        }
    } catch (Exception $e) {
        error_log("SLA breach check failed: " . $e->getMessage());
    }
    
    // 4. Check for tickets approaching SLA deadlines (with cooldown)
    try {
        // Only check for SLA warnings every 2 minutes to prevent spam
        $lastWarningCheck = $_SESSION['last_sla_warning_check'] ?? 0;
        if (time() - $lastWarningCheck > 120) { // 2 minutes = 120 seconds
            $slaWarnings = $slaService->getSlaWarnings(90);
            
            // Track notified warnings to prevent duplicates
            $notifiedWarnings = $_SESSION['notified_sla_warnings'] ?? [];
            $newWarnings = [];
            
            foreach ($slaWarnings as $warning) {
                $ticketId = $warning['ticket']['id'];
                if (!in_array($ticketId, $notifiedWarnings)) {
                    $newWarnings[] = $warning;
                    $notifiedWarnings[] = $ticketId;
                }
            }
            
            if (!empty($newWarnings)) {
                $warningCount = count($newWarnings);
                $notifications[] = [
                    'type' => 'sla_warning',
                    'title' => 'SLA Warning',
                    'message' => "{$warningCount} ticket" . ($warningCount > 1 ? 's are' : ' is') . " approaching SLA deadline",
                    'count' => $warningCount,
                    'priority' => 'warning',
                    'url' => '/ITSPtickets/tickets-simple.php?sla_filter=at_risk',
                    'timestamp' => date('c')
                ];
            }
            
            // Update session tracking
            $_SESSION['notified_sla_warnings'] = array_slice($notifiedWarnings, -30); // Keep last 30 to prevent memory bloat
            $_SESSION['last_sla_warning_check'] = time();
        }
    } catch (Exception $e) {
        error_log("SLA warning check failed: " . $e->getMessage());
    }
    
    // 5. Check for ticket updates on user's tickets
    if ($user['role'] === 'agent') {
        $sql = "SELECT COUNT(*) as count FROM tickets t 
                LEFT JOIN ticket_messages tm ON t.id = tm.ticket_id 
                WHERE t.assignee_id = ? AND (t.updated_at > ? OR tm.created_at > ?)
                AND t.status NOT IN ('closed', 'resolved')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['id'], $lastCheck, $lastCheck]);
        $updatedTickets = $stmt->fetch()['count'];
        
        if ($updatedTickets > 0) {
            $notifications[] = [
                'type' => 'ticket_updates',
                'title' => 'Ticket Updates',
                'message' => "{$updatedTickets} of your ticket" . ($updatedTickets > 1 ? 's have' : ' has') . " been updated",
                'count' => $updatedTickets,
                'priority' => 'info',
                'url' => '/ITSPtickets/tickets-simple.php?assignee=' . $user['id'],
                'timestamp' => date('c')
            ];
        }
    }
    
    // 6. Check for overdue tickets
    $sql = "SELECT COUNT(*) as count FROM tickets t
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE t.status NOT IN ('closed', 'resolved')
            AND sp.resolution_target IS NOT NULL
            AND TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) > sp.resolution_target";
    
    if ($user['role'] === 'agent') {
        $sql .= " AND t.assignee_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    
    $overdueTickets = $stmt->fetch()['count'];
    if ($overdueTickets > 0) {
        $notifications[] = [
            'type' => 'overdue_tickets',
            'title' => 'Overdue Tickets',
            'message' => "{$overdueTickets} ticket" . ($overdueTickets > 1 ? 's are' : ' is') . " overdue",
            'count' => $overdueTickets,
            'priority' => 'critical',
            'url' => '/ITSPtickets/tickets-simple.php?sla_filter=breached',
            'timestamp' => date('c')
        ];
    }
    
    // Update last check timestamp
    $_SESSION['last_notification_check'] = date('Y-m-d H:i:s');
    
    // Send notifications as Server-Sent Events
    if (!empty($notifications)) {
        echo "data: " . json_encode([
            'notifications' => $notifications,
            'timestamp' => date('c'),
            'user_id' => $user['id']
        ]) . "\n\n";
    } else {
        // Send heartbeat
        echo "data: " . json_encode([
            'type' => 'heartbeat',
            'timestamp' => date('c'),
            'user_id' => $user['id']
        ]) . "\n\n";
    }
    
    flush();
    
} catch (Exception $e) {
    error_log("Real-time notification error: " . $e->getMessage());
    echo "data: " . json_encode([
        'type' => 'error',
        'message' => 'Notification service temporarily unavailable'
    ]) . "\n\n";
    flush();
}
?>