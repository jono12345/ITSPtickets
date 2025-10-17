<?php
session_start();

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

require_once 'config/database.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Get current user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        header('Location: /ITSPtickets/login.php');
        exit;
    }
    
    // Check permissions (only admins and supervisors can see all notifications)
    $canViewAll = in_array($user['role'], ['admin', 'supervisor']);
    
    // Get filter parameters
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $eventType = $_GET['event_type'] ?? '';
    $ticketId = $_GET['ticket_id'] ?? '';
    
    // Build query with filters
    $sql = "SELECT n.*, t.key as ticket_key, t.subject as ticket_subject
            FROM notifications n
            LEFT JOIN tickets t ON n.ticket_id = t.id
            WHERE n.created_at BETWEEN ? AND ?";
    
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    
    if ($eventType) {
        $sql .= " AND n.event_type = ?";
        $params[] = $eventType;
    }
    
    if ($ticketId) {
        $sql .= " AND n.ticket_id = ?";
        $params[] = $ticketId;
    }
    
    // For agents, only show notifications related to their tickets
    if (!$canViewAll) {
        $sql .= " AND t.assignee_id = ?";
        $params[] = $user['id'];
    }
    
    $sql .= " ORDER BY n.created_at DESC LIMIT 200";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Get notification statistics
    $statsSql = "SELECT 
                    event_type,
                    COUNT(*) as count,
                    COUNT(CASE WHEN JSON_EXTRACT(results, '$') LIKE '%true%' THEN 1 END) as successful
                 FROM notifications n";
    
    if (!$canViewAll) {
        $statsSql .= " LEFT JOIN tickets t ON n.ticket_id = t.id WHERE t.assignee_id = ?";
        $statsParams = [$user['id']];
    } else {
        $statsParams = [];
    }
    
    $statsSql .= " GROUP BY event_type ORDER BY count DESC";
    
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Notification Log - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc; 
            line-height: 1.6;
        }
        .container { 
            max-width: 1400px; 
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
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: #374151;
        }
        .form-group input,
        .form-group select {
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .btn { 
            padding: 8px 16px; 
            border-radius: 6px; 
            text-decoration: none; 
            font-weight: 500; 
            border: none;
            cursor: pointer;
            background: #3b82f6;
            color: white;
        }
        .btn:hover { opacity: 0.9; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
        }
        .notifications-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .notification-item {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .notification-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .type-ticket-created { background: #dbeafe; color: #1e40af; }
        .type-ticket-assigned { background: #f3e8ff; color: #7c3aed; }
        .type-ticket-updated { background: #fef3c7; color: #92400e; }
        .type-message-added { background: #e0f2fe; color: #0369a1; }
        .type-ticket-resolved { background: #d1fae5; color: #065f46; }
        .type-ticket-closed { background: #f3f4f6; color: #374151; }
        .type-sla-breach { background: #fee2e2; color: #991b1b; }
        .notification-date {
            color: #6b7280;
            font-size: 12px;
        }
        .notification-content {
            margin-bottom: 10px;
        }
        .notification-recipients {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .notification-status {
            display: flex;
            gap: 10px;
            font-size: 12px;
        }
        .status-success { color: #059669; }
        .status-failed { color: #dc2626; }
        .ticket-link {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
        .ticket-link:hover { text-decoration: underline; }
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #6b7280;
        }
        @media (max-width: 768px) {
            .filters form { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Notification Log</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <?php if ($canViewAll): ?>
                    <a href='/ITSPtickets/sla-management.php'>SLA Management</a>
                <?php endif; ?>
                <a href='/ITSPtickets/reports-simple.php'>Reports</a>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
                <a href='/ITSPtickets/logout.php'>Logout</a>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div style='background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class='filters'>
            <form method='GET'>
                <div class='form-group'>
                    <label for='date_from'>From Date</label>
                    <input type='date' id='date_from' name='date_from' value='<?= htmlspecialchars($dateFrom) ?>'>
                </div>
                
                <div class='form-group'>
                    <label for='date_to'>To Date</label>
                    <input type='date' id='date_to' name='date_to' value='<?= htmlspecialchars($dateTo) ?>'>
                </div>
                
                <div class='form-group'>
                    <label for='event_type'>Event Type</label>
                    <select id='event_type' name='event_type'>
                        <option value=''>All Events</option>
                        <option value='ticket_created' <?= $eventType === 'ticket_created' ? 'selected' : '' ?>>Ticket Created</option>
                        <option value='ticket_assigned' <?= $eventType === 'ticket_assigned' ? 'selected' : '' ?>>Ticket Assigned</option>
                        <option value='ticket_updated' <?= $eventType === 'ticket_updated' ? 'selected' : '' ?>>Ticket Updated</option>
                        <option value='message_added' <?= $eventType === 'message_added' ? 'selected' : '' ?>>Message Added</option>
                        <option value='ticket_resolved' <?= $eventType === 'ticket_resolved' ? 'selected' : '' ?>>Ticket Resolved</option>
                        <option value='ticket_closed' <?= $eventType === 'ticket_closed' ? 'selected' : '' ?>>Ticket Closed</option>
                        <option value='sla_breach' <?= $eventType === 'sla_breach' ? 'selected' : '' ?>>SLA Breach</option>
                    </select>
                </div>
                
                <div class='form-group'>
                    <label for='ticket_id'>Ticket ID</label>
                    <input type='number' id='ticket_id' name='ticket_id' value='<?= htmlspecialchars($ticketId) ?>' placeholder='Optional'>
                </div>
                
                <div class='form-group'>
                    <button type='submit' class='btn'>Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Statistics -->
        <?php if (!empty($stats)): ?>
            <div class='stats-grid'>
                <?php foreach ($stats as $stat): ?>
                    <div class='stat-card'>
                        <h3><?= ucfirst(str_replace('_', ' ', $stat['event_type'])) ?></h3>
                        <div class='stat-number'><?= $stat['count'] ?></div>
                        <div style='font-size: 14px; color: #6b7280; margin-top: 5px;'>
                            Success rate: <?= $stat['count'] > 0 ? round(($stat['successful'] / $stat['count']) * 100, 1) : 0 ?>%
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Notifications List -->
        <div class='notifications-list'>
            <?php if (empty($notifications)): ?>
                <div class='empty-state'>
                    <h3>No notifications found</h3>
                    <p>Try adjusting your filters or check back later.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class='notification-item'>
                        <div class='notification-header'>
                            <div>
                                <span class='notification-type type-<?= str_replace('_', '-', $notification['event_type']) ?>'>
                                    <?= ucfirst(str_replace('_', ' ', $notification['event_type'])) ?>
                                </span>
                                <?php if ($notification['ticket_key']): ?>
                                    <a href='/ITSPtickets/ticket-simple.php?id=<?= $notification['ticket_id'] ?>' class='ticket-link'>
                                        <?= htmlspecialchars($notification['ticket_key']) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <span class='notification-date'>
                                <?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?>
                            </span>
                        </div>
                        
                        <?php if ($notification['ticket_subject']): ?>
                            <div class='notification-content'>
                                <strong>Subject:</strong> <?= htmlspecialchars($notification['ticket_subject']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class='notification-recipients'>
                            <strong>Recipients:</strong> 
                            <?php 
                            $recipients = json_decode($notification['recipients'], true);
                            echo $recipients ? implode(', ', $recipients) : 'N/A';
                            ?>
                        </div>
                        
                        <div class='notification-status'>
                            <?php 
                            $results = json_decode($notification['results'], true);
                            $successful = 0;
                            $total = 0;
                            
                            if ($results) {
                                foreach ($results as $result) {
                                    $total++;
                                    if ($result) $successful++;
                                }
                            }
                            ?>
                            <span class='status-<?= $successful == $total && $total > 0 ? 'success' : 'failed' ?>'>
                                Status: <?= $successful ?>/<?= $total ?> delivered
                            </span>
                            
                            <?php if ($total > 0): ?>
                                <span>Success rate: <?= round(($successful / $total) * 100, 1) ?>%</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (count($notifications) >= 200): ?>
            <div style='text-align: center; padding: 20px; color: #6b7280; font-size: 14px;'>
                Showing latest 200 notifications. Use filters to narrow down results.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>