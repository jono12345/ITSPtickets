<?php
/*
|--------------------------------------------------------------------------
| Dashboard - Simple Model
|--------------------------------------------------------------------------
|
| Main dashboard interface showing system overview, quick stats, and
| navigation to key sections of the ITSPtickets system.
|
*/

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

// Get user info from session
$user = [
    'id' => $_SESSION['user_id'],
    'name' => $_SESSION['user_name'],
    'role' => $_SESSION['user_role'],
    'email' => $_SESSION['user_email']
];

// Database connection
require_once 'sla-service-simple.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Initialize SLA service
    $slaService = new SlaServiceSimple($pdo);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Get dashboard statistics with role-based filtering
$stats = [];
$roleFilter = '';
$roleParams = [];

// If user is agent, only show their assigned tickets
if ($user['role'] === 'agent') {
    $roleFilter = ' AND assignee_id = ?';
    $roleParams = [$user['id']];
}

// Total tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tickets WHERE 1=1" . $roleFilter);
$stmt->execute($roleParams);
$stats['total_tickets'] = $stmt->fetch()['total'];

// Open tickets (new + triaged)
$stmt = $pdo->prepare("SELECT COUNT(*) as open FROM tickets WHERE status IN ('new', 'triaged')" . $roleFilter);
$stmt->execute($roleParams);
$stats['open_tickets'] = $stmt->fetch()['open'];

// In Progress tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as in_progress FROM tickets WHERE status = 'in_progress'" . $roleFilter);
$stmt->execute($roleParams);
$stats['in_progress_tickets'] = $stmt->fetch()['in_progress'];

// Waiting tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as waiting FROM tickets WHERE status = 'waiting'" . $roleFilter);
$stmt->execute($roleParams);
$stats['waiting_tickets'] = $stmt->fetch()['waiting'];

// On Hold tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as on_hold FROM tickets WHERE status = 'on_hold'" . $roleFilter);
$stmt->execute($roleParams);
$stats['on_hold_tickets'] = $stmt->fetch()['on_hold'];

// Closed tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as closed FROM tickets WHERE status IN ('resolved', 'closed')" . $roleFilter);
$stmt->execute($roleParams);
$stats['closed_tickets'] = $stmt->fetch()['closed'];

// Urgent tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as urgent FROM tickets WHERE priority = 'urgent'" . $roleFilter);
$stmt->execute($roleParams);
$stats['urgent_tickets'] = $stmt->fetch()['urgent'];

// High priority tickets
$stmt = $pdo->prepare("SELECT COUNT(*) as high FROM tickets WHERE priority = 'high'" . $roleFilter);
$stmt->execute($roleParams);
$stats['high_tickets'] = $stmt->fetch()['high'];

// SLA breaches - count from sla_segments table with role filtering
if ($user['role'] === 'agent') {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.ticket_id) as breaches
        FROM sla_segments s
        JOIN tickets t ON s.ticket_id = t.id
        WHERE s.is_breached = 1 AND t.assignee_id = ?
    ");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT ticket_id) as breaches
        FROM sla_segments
        WHERE is_breached = 1
    ");
}
$stats['sla_breaches'] = $stmt->fetch()['breaches'];

// Get tickets close to SLA breach (over 90% of time used but not yet breached) with role filtering
$sla_warnings = [];
try {
    if ($user['role'] === 'agent') {
        // Agent sees only their assigned tickets
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.`key`, t.subject, t.priority, t.created_at,
                   sp.response_target, sp.resolution_target,
                   s.business_minutes, s.target_minutes, s.segment_type
            FROM tickets t
            LEFT JOIN sla_segments s ON t.id = s.ticket_id
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE s.is_breached = 0
            AND s.business_minutes > (s.target_minutes * 0.9)
            AND s.business_minutes IS NOT NULL
            AND s.target_minutes IS NOT NULL
            AND s.target_minutes > 0
            AND t.status IN ('new', 'triaged', 'in_progress', 'waiting')
            AND t.assignee_id = ?
            ORDER BY (s.business_minutes / s.target_minutes) DESC
            LIMIT 5
        ");
        $stmt->execute([$user['id']]);
    } else {
        // Admin/supervisor sees all tickets
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.`key`, t.subject, t.priority, t.created_at,
                   sp.response_target, sp.resolution_target,
                   s.business_minutes, s.target_minutes, s.segment_type
            FROM tickets t
            LEFT JOIN sla_segments s ON t.id = s.ticket_id
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE s.is_breached = 0
            AND s.business_minutes > (s.target_minutes * 0.9)
            AND s.business_minutes IS NOT NULL
            AND s.target_minutes IS NOT NULL
            AND s.target_minutes > 0
            AND t.status IN ('new', 'triaged', 'in_progress', 'waiting')
            ORDER BY (s.business_minutes / s.target_minutes) DESC
            LIMIT 5
        ");
        $stmt->execute();
    }
    $sla_warnings = $stmt->fetchAll();
} catch (PDOException $e) {
    // If SLA warning query fails, just continue without warnings
    error_log("SLA warning query failed: " . $e->getMessage());
    $sla_warnings = [];
}

// Recent tickets (last 10) with role filtering
if ($user['role'] === 'agent') {
    // Agent sees recent tickets from their organization or assigned to them
    $stmt = $pdo->prepare("
        SELECT t.id, t.`key` as ticket_key, t.subject, t.priority, t.status, t.created_at,
               t.assignee_id, r.name as requester_name
        FROM tickets t
        LEFT JOIN requesters r ON t.requester_id = r.id
        WHERE t.assignee_id = ? OR t.assignee_id IS NULL
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
} else {
    // Admin/supervisor sees all recent tickets
    $stmt = $pdo->prepare("
        SELECT t.id, t.`key` as ticket_key, t.subject, t.priority, t.status, t.created_at,
               t.assignee_id, r.name as requester_name
        FROM tickets t
        LEFT JOIN requesters r ON t.requester_id = r.id
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
}
$recent_tickets = $stmt->fetchAll();

// My assigned tickets (active only)
$my_tickets = [];
if (in_array($user['role'], ['agent', 'admin', 'supervisor'])) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.`key` as ticket_key, t.subject, t.priority, t.status, t.created_at,
               r.name as requester_name
        FROM tickets t
        LEFT JOIN requesters r ON t.requester_id = r.id
        WHERE t.assignee_id = ? AND t.status IN ('new', 'triaged', 'in_progress', 'waiting', 'on_hold')
        ORDER BY t.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user['id']]);
    $my_tickets = $stmt->fetchAll();
}

// Recently completed tickets (for workflow continuity)
$my_completed_tickets = [];
if (in_array($user['role'], ['agent', 'admin', 'supervisor'])) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.`key` as ticket_key, t.subject, t.priority, t.status,
               t.created_at, t.updated_at, t.closed_at, t.resolved_at,
               r.name as requester_name
        FROM tickets t
        LEFT JOIN requesters r ON t.requester_id = r.id
        WHERE t.assignee_id = ? AND t.status IN ('closed', 'resolved')
        ORDER BY COALESCE(t.closed_at, t.resolved_at, t.updated_at) DESC
        LIMIT 3
    ");
    $stmt->execute([$user['id']]);
    $my_completed_tickets = $stmt->fetchAll();
}

// Get unread notification count for the current user
$unread_notifications = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$user['id']]);
    $result = $stmt->fetch();
    $unread_notifications = $result['unread_count'] ?? 0;
} catch (PDOException $e) {
    // If notifications table doesn't exist or query fails, default to 0
    error_log("Notification count query failed: " . $e->getMessage());
    $unread_notifications = 0;
}

?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Dashboard - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            line-height: 1.6;
        }
        .header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            color: #1f2937;
            font-size: 1.5rem;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .user-info span {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .user-info .role {
            background: #3b82f6;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        .user-info a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
        .user-info a:hover {
            color: #2563eb;
        }
        .main-content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .welcome {
            margin-bottom: 2rem;
        }
        .welcome h2 {
            color: #1f2937;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        .welcome p {
            color: #6b7280;
            font-size: 1rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid;
        }
        .stat-card.total { border-left-color: #3b82f6; }
        .stat-card.urgent { border-left-color: #dc2626; }
        .stat-card.open { border-left-color: #f59e0b; }
        .stat-card.in-progress { border-left-color: #8b5cf6; }
        .stat-card.waiting { border-left-color: #06b6d4; }
        .stat-card.on-hold { border-left-color: #f97316; }
        .stat-card.closed { border-left-color: #10b981; }
        .stat-card.breach { border-left-color: #ef4444; }
        .stat-card h3 {
            color: #6b7280;
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        .stat-card .number {
            color: #1f2937;
            font-size: 2rem;
            font-weight: 700;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .action-btn {
            background: #3b82f6;
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        .action-btn:hover {
            background: #2563eb;
        }
        .action-btn.secondary {
            background: #6b7280;
        }
        .action-btn.secondary:hover {
            background: #4b5563;
        }
        .section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .section-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header h3 {
            color: #1f2937;
            font-size: 1.1rem;
        }
        .section-content {
            padding: 1.5rem;
        }
        .ticket-list {
            list-style: none;
        }
        .ticket-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .ticket-item:last-child {
            border-bottom: none;
        }
        .ticket-info {
            flex: 1;
        }
        .ticket-key {
            color: #3b82f6;
            font-weight: 500;
            text-decoration: none;
        }
        .ticket-key:hover {
            color: #2563eb;
        }
        .ticket-subject {
            color: #6b7280;
            font-size: 0.9rem;
            margin-left: 1rem;
        }
        .ticket-meta {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .priority-badge, .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .priority-high { background: #fee2e2; color: #991b1b; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-low { background: #e0f2fe; color: #0f4c75; }
        .status-open { background: #fef3c7; color: #92400e; }
        .status-in-progress { background: #dbeafe; color: #1e40af; }
        .status-closed { background: #d1fae5; color: #065f46; }
        .empty-state {
            text-align: center;
            color: #6b7280;
            padding: 2rem;
        }
        .sla-warning-box {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .sla-warning-box.hidden {
            display: none;
        }
        .sla-warning-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        .sla-warning-header h3 {
            color: #92400e;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0 0 0 0.5rem;
        }
        .sla-warning-icon {
            color: #f59e0b;
            font-size: 1.2rem;
        }
        .sla-warning-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .sla-warning-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #fde68a;
        }
        .sla-warning-item:last-child {
            border-bottom: none;
        }
        .sla-warning-ticket {
            flex: 1;
        }
        .sla-warning-key {
            color: #92400e;
            font-weight: 600;
            text-decoration: none;
        }
        .sla-warning-key:hover {
            text-decoration: underline;
        }
        .sla-warning-subject {
            color: #78350f;
            font-size: 0.9rem;
            margin-left: 1rem;
        }
        .sla-warning-time {
            color: #92400e;
            font-size: 0.8rem;
            font-weight: 500;
            text-align: right;
        }
        
        /* Notification bell styles */
        .notification-bell {
            position: relative;
            background: none;
            border: none;
            color: #6b7280;
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            margin-right: 15px;
        }
        .notification-bell:hover {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        .notification-count {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            font-size: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .nav-links {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .nav-links a {
            color: #3b82f6;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 4px;
            transition: background-color 0.2s;
            font-weight: 500;
        }
        .nav-links a:hover {
            background-color: #eff6ff;
            text-decoration: none;
        }
        .nav-links .logout-link {
            color: #ef4444;
        }
        .nav-links .logout-link:hover {
            background-color: #fef2f2;
        }
        
        /* Auto-update indicators */
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card.updated {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        /* Recently completed section styles */
        .completed-section {
            opacity: 0.9;
            border-left: 3px solid #10b981;
        }
        .completed-section .section-header h3 {
            color: #059669;
        }
        .completed-item {
            background: #f8fafc;
            border-left: 2px solid #10b981;
        }
        .section-subtitle {
            font-size: 12px;
            font-weight: normal;
            color: #6b7280;
            font-style: italic;
        }
        .compact-list .ticket-item {
            padding: 10px 0;
            margin-bottom: 5px;
        }
        .section-actions {
            margin-top: 15px;
            text-align: center;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            color: #3b82f6;
            text-decoration: none;
            border: 1px solid #3b82f6;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .btn-sm:hover {
            background: #3b82f6;
            color: white;
        }
        
        /* Comprehensive Mobile Responsive Design */
        
        /* Tablet breakpoint */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 15px;
            }
            
            .header h1 {
                font-size: 24px;
                margin-bottom: 10px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
            }
            
            .nav-links a {
                padding: 10px 15px;
                font-size: 14px;
                min-height: 44px;
                display: flex;
                align-items: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 12px;
                margin-bottom: 25px;
            }
            
            .stat-card {
                padding: 20px 15px;
                min-height: 80px;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
            
            .stat-card h3 {
                font-size: 12px;
                margin-bottom: 10px;
            }
            
            .stat-card .number {
                font-size: 26px;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .action-btn {
                text-align: center;
                padding: 15px 20px;
                font-size: 16px;
                min-height: 44px;
            }
            
            .sla-warning-box {
                padding: 15px;
            }
            
            .ticket-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                margin-bottom: 12px;
            }
            
            .ticket-key {
                font-size: 16px;
                margin-bottom: 5px;
            }
            
            .priority-badge, .status-badge {
                font-size: 11px;
                padding: 6px 12px;
            }
            
            .notification-bell {
                font-size: 20px;
                padding: 12px;
                min-height: 44px;
                min-width: 44px;
            }
            
            .section {
                margin-bottom: 20px;
            }
            
            .section-content {
                padding: 15px;
            }
        }
        
        /* Mobile breakpoint */
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
            }
            
            .header {
                padding: 12px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .user-info span {
                font-size: 14px;
            }
            
            .user-info .role {
                font-size: 11px;
                padding: 3px 8px;
            }
            
            .nav-links a {
                padding: 8px 12px;
                font-size: 13px;
                min-height: 44px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .stat-card {
                padding: 15px 10px;
                min-height: 70px;
            }
            
            .stat-card h3 {
                font-size: 11px;
                margin-bottom: 8px;
            }
            
            .stat-card .number {
                font-size: 22px;
            }
            
            .section-content {
                padding: 12px;
            }
            
            .ticket-item {
                padding: 12px 0;
                margin-bottom: 8px;
            }
            
            .ticket-subject {
                font-size: 14px;
                line-height: 1.4;
                margin-bottom: 8px;
                margin-left: 0;
            }
            
            .ticket-meta {
                font-size: 11px;
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .ticket-meta span {
                margin-right: 10px;
                margin-bottom: 2px;
            }
            
            .sla-warning-box {
                padding: 12px;
            }
            
            .sla-warning-header h3 {
                font-size: 14px;
            }
            
            .sla-warning-item {
                padding: 6px 0;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .sla-warning-key {
                display: block;
                margin-bottom: 4px;
            }
            
            .sla-warning-time {
                align-self: flex-end;
                text-align: right;
            }
            
            .section-subtitle {
                font-size: 11px;
            }
            
            .welcome h2 {
                font-size: 1.5rem;
            }
            
            .welcome p {
                font-size: 0.9rem;
            }
        }
        
        /* Touch device optimizations */
        @media (pointer: coarse) {
            .nav-links a,
            .action-btn,
            .notification-bell,
            .ticket-key {
                min-height: 44px;
                min-width: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .nav-links a {
                padding: 12px 16px;
            }
            
            .action-btn {
                padding: 14px 20px;
                font-size: 16px;
            }
            
            .ticket-item {
                cursor: pointer;
                transition: background-color 0.2s;
            }
            
            .ticket-item:hover {
                background-color: #f8fafc;
            }
        }
        
        /* Landscape orientation adjustments */
        @media (max-width: 768px) and (orientation: landscape) {
            .header {
                flex-direction: row;
                justify-content: space-between;
                text-align: left;
                padding: 12px 15px;
            }
            
            .user-info {
                flex-direction: row;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* iOS Safari specific fixes */
        @media screen and (-webkit-min-device-pixel-ratio: 2) {
            input, select, textarea {
                font-size: 16px; /* Prevent zoom on iOS */
            }
        }
        
        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .stat-card, .section, .sla-warning-box {
                border: 2px solid #000;
            }
            
            .action-btn {
                border: 2px solid #000;
            }
        }
        
        /* Reduced motion preferences */
        @media (prefers-reduced-motion: reduce) {
            .stat-card.updated {
                transform: none;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .notification-bell,
            .action-btn,
            .ticket-item {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <div class='header'>
        <h1>🎫 ITSPtickets Dashboard</h1>
        <div class='user-info'>
            <button class='notification-bell' title='<?= $unread_notifications > 0 ? $unread_notifications . ' unread notifications' : 'No new notifications' ?>'>
                🔔
                <span class='notification-count' <?= $unread_notifications == 0 ? 'style="display:none;"' : '' ?>><?= $unread_notifications ?></span>
            </button>
            <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
            <span class='role'><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
            <div class='nav-links'>
                <a href='/ITSPtickets/tickets-simple.php'>Tickets</a>
                <a href='/ITSPtickets/reports-simple.php'>Reports</a>
                <?php if (in_array($user['role'], ['admin', 'supervisor'])): ?>
                    <a href='/ITSPtickets/settings.php'>Settings</a>
                <?php endif; ?>
                <a href='/ITSPtickets/notifications-log.php'>Notifications</a>
                <a href='/ITSPtickets/logout.php' class='logout-link'>Logout</a>
            </div>
        </div>
    </div>

    <div class='main-content'>
        <div class='welcome'>
            <h2>Welcome back, <?= htmlspecialchars($user['name']) ?>!</h2>
            <p>Here's an overview of your <?= $user['role'] === 'agent' ? 'assigned tickets and' : '' ?> ITSPtickets system</p>
        </div>

        <?php if (!empty($sla_warnings)): ?>
        <div class='sla-warning-box'>
            <div class='sla-warning-header'>
                <span class='sla-warning-icon'>⚠️</span>
                <h3>SLA Warnings - Tickets Close to Breach (90% threshold)</h3>
            </div>
            <ul class='sla-warning-list'>
                <?php foreach ($sla_warnings as $warning): ?>
                <?php
                $percentUsed = ($warning['business_minutes'] / $warning['target_minutes']) * 100;
                $timeRemaining = $warning['target_minutes'] - $warning['business_minutes'];
                $hoursRemaining = round($timeRemaining / 60, 1);
                ?>
                <li class='sla-warning-item'>
                    <div class='sla-warning-ticket'>
                        <a href='/ITSPtickets/ticket-simple.php?id=<?= $warning['id'] ?>' class='sla-warning-key'>
                            <?= htmlspecialchars($warning['key']) ?>
                        </a>
                        <span class='sla-warning-subject'><?= htmlspecialchars($warning['subject']) ?></span>
                    </div>
                    <div class='sla-warning-time'>
                        <?= round($percentUsed, 1) ?>% used<br>
                        <?= $hoursRemaining ?>h remaining
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

                        <div class='stats-grid'>
            <div class='stat-card total update-indicator' data-stat='total'>
                <h3>Total Tickets</h3>
                <div class='number'><?= number_format($stats['total_tickets']) ?></div>
            </div>
            <div class='stat-card urgent update-indicator' data-stat='urgent'>
                <h3>Urgent</h3>
                <div class='number'><?= number_format($stats['urgent_tickets']) ?></div>
            </div>
            <div class='stat-card open update-indicator' data-stat='open'>
                <h3>Open Tickets</h3>
                <div class='number'><?= number_format($stats['open_tickets']) ?></div>
            </div>
            <div class='stat-card in-progress update-indicator' data-stat='in_progress'>
                <h3>In Progress</h3>
                <div class='number'><?= number_format($stats['in_progress_tickets']) ?></div>
            </div>
            <div class='stat-card waiting update-indicator' data-stat='waiting'>
                <h3>Waiting</h3>
                <div class='number'><?= number_format($stats['waiting_tickets']) ?></div>
            </div>
            <div class='stat-card on-hold update-indicator' data-stat='on_hold'>
                <h3>On Hold</h3>
                <div class='number'><?= number_format($stats['on_hold_tickets']) ?></div>
            </div>
            <div class='stat-card closed update-indicator' data-stat='closed'>
                <h3>Closed Tickets</h3>
                <div class='number'><?= number_format($stats['closed_tickets']) ?></div>
            </div>
            <div class='stat-card breach update-indicator' data-stat='breaches'>
                <h3>SLA Breaches</h3>
                <div class='number'><?= number_format($stats['sla_breaches']) ?></div>
            </div>
        </div>

        <div class='quick-actions'>
            <a href='/ITSPtickets/create-ticket-simple.php' class='action-btn'>
                ➕ Create New Ticket
            </a>
            <a href='/ITSPtickets/tickets-simple.php' class='action-btn'>
                📋 View All Tickets
            </a>
            <a href='/ITSPtickets/reports-simple.php' class='action-btn secondary'>
                📊 Reports
            </a>
            <a href='/ITSPtickets/settings.php' class='action-btn secondary'>
                ⚙️ Settings
            </a>
        </div>

        <?php if (!empty($my_tickets)): ?>
        <div class='section'>
            <div class='section-header'>
                <h3>My Assigned Tickets (<?= count($my_tickets) ?>)</h3>
                <a href='/ITSPtickets/tickets-simple.php?assigned=me'>View All</a>
            </div>
            <div class='section-content'>
                <ul class='ticket-list'>
                    <?php foreach ($my_tickets as $ticket): ?>
                    <li class='ticket-item'>
                        <div class='ticket-info'>
                            <a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>' class='ticket-key'>
                                <?= htmlspecialchars($ticket['ticket_key']) ?>
                            </a>
                            <span class='ticket-subject'><?= htmlspecialchars($ticket['subject']) ?></span>
                        </div>
                        <div class='ticket-meta'>
                            <span class='priority-badge priority-<?= strtolower($ticket['priority']) ?>'>
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                            <span class='status-badge status-<?= strtolower(str_replace(' ', '-', $ticket['status'])) ?>'>
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($my_completed_tickets)): ?>
        <div class='section completed-section'>
            <div class='section-header'>
                <h3>Recently Completed <span class='section-subtitle'>(last 3 closed/resolved)</span></h3>
                <a href='/ITSPtickets/tickets-simple.php?assignee_id=<?= $user['id'] ?>&status[]=closed&status[]=resolved'>View All</a>
            </div>
            <div class='section-content'>
                <ul class='ticket-list compact-list'>
                    <?php foreach ($my_completed_tickets as $ticket): ?>
                    <?php
                    $completedDate = $ticket['status'] === 'closed'
                        ? ($ticket['closed_at'] ? date('M j, Y', strtotime($ticket['closed_at'])) : 'Recently')
                        : ($ticket['resolved_at'] ? date('M j, Y', strtotime($ticket['resolved_at'])) : 'Recently');
                    ?>
                    <li class='ticket-item completed-item'>
                        <div class='ticket-info'>
                            <a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>' class='ticket-key'>
                                <?= htmlspecialchars($ticket['ticket_key']) ?>
                            </a>
                            <span class='ticket-subject'><?= htmlspecialchars($ticket['subject']) ?></span>
                        </div>
                        <div class='ticket-meta'>
                            <span class='priority-badge priority-<?= strtolower($ticket['priority']) ?>'>
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                            <span class='status-badge status-<?= strtolower(str_replace(' ', '-', $ticket['status'])) ?>'>
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                            <small>Completed: <?= $completedDate ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class='section'>
            <div class='section-header'>
                <h3>Recent Tickets (<?= count($recent_tickets) ?>)</h3>
                <a href='/ITSPtickets/tickets-simple.php'>View All</a>
            </div>
            <div class='section-content'>
                <?php if (!empty($recent_tickets)): ?>
                <ul class='ticket-list'>
                    <?php foreach ($recent_tickets as $ticket): ?>
                    <li class='ticket-item'>
                        <div class='ticket-info'>
                            <a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>' class='ticket-key'>
                                <?= htmlspecialchars($ticket['ticket_key']) ?>
                            </a>
                            <span class='ticket-subject'><?= htmlspecialchars($ticket['subject']) ?></span>
                        </div>
                        <div class='ticket-meta'>
                            <span class='priority-badge priority-<?= strtolower($ticket['priority']) ?>'>
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                            <span class='status-badge status-<?= strtolower(str_replace(' ', '-', $ticket['status'])) ?>'>
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                            <small><?= date('M j, Y', strtotime($ticket['created_at'])) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class='empty-state'>
                    <p>No tickets found</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Real-time Notifications JavaScript -->
    <script>
        // Initialize real-time notifications for dashboard
        document.addEventListener('DOMContentLoaded', function() {
            const notificationBell = document.querySelector('.notification-bell');
            const notificationCount = document.querySelector('.notification-count');
            
            // Real notification count management
            let notificationCountValue = <?= $unread_notifications ?>;
            
            // Update notification display
            function updateNotificationCount(count) {
                notificationCountValue = count;
                notificationCount.textContent = count;
                notificationCount.style.display = count > 0 ? 'flex' : 'none';
                
                if (count > 0) {
                    notificationBell.style.color = '#ef4444';
                    notificationBell.title = count + ' unread notifications';
                } else {
                    notificationBell.style.color = '#6b7280';
                    notificationBell.title = 'No new notifications';
                }
            }
            
            // Add click handler for notification bell
            if (notificationBell) {
                notificationBell.addEventListener('click', function() {
                    // Navigate to notifications page
                    window.location.href = '/ITSPtickets/notifications-log.php';
                });
            }
            
            // Initialize notification count display with real data
            updateNotificationCount(notificationCountValue);
            
            // Real-time updates could be implemented here with WebSockets or Server-Sent Events
            // For now, notifications update on page refresh which is standard behavior
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'h': // Ctrl+H for home/dashboard
                            e.preventDefault();
                            window.location.href = '/ITSPtickets/dashboard-simple.php';
                            break;
                        case 't': // Ctrl+T for tickets
                            e.preventDefault();
                            window.location.href = '/ITSPtickets/tickets-simple.php';
                            break;
                        case 'n': // Ctrl+N for new ticket
                            e.preventDefault();
                            window.location.href = '/ITSPtickets/create-ticket-simple.php';
                            break;
                    }
                }
            });
            
            // Add tooltip for keyboard shortcuts
            const tooltipDiv = document.createElement('div');
            tooltipDiv.innerHTML = `
                <div style='position:fixed;bottom:10px;right:10px;background:#374151;color:white;padding:8px;border-radius:4px;font-size:11px;z-index:1000;display:none;' id='shortcut-tooltip'>
                    <div><strong>Keyboard Shortcuts:</strong></div>
                    <div>Ctrl+H - Dashboard</div>
                    <div>Ctrl+T - Tickets</div>
                    <div>Ctrl+N - New Ticket</div>
                </div>
            `;
            document.body.appendChild(tooltipDiv);
            
            // Show/hide shortcuts on Alt key
            document.addEventListener('keydown', function(e) {
                if (e.altKey) {
                    document.getElementById('shortcut-tooltip').style.display = 'block';
                }
            });
            
            document.addEventListener('keyup', function(e) {
                if (!e.altKey) {
                    document.getElementById('shortcut-tooltip').style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>