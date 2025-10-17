<?php
/*
|--------------------------------------------------------------------------
| Tickets List - Simple Model
|--------------------------------------------------------------------------
| Main ticket listing with filtering, sorting, and SLA tracking
*/

require_once 'auth-helper.php';
require_once 'db-connection.php';
require_once 'sla-service-simple.php';

try {
    $pdo = createDatabaseConnection();
    $user = getCurrentStaff($pdo);
    
    // Initialize SLA service
    $slaService = new SlaServiceSimple($pdo);
    
    // Get filter parameters
    $assigneeFilter = $_GET['assignee'] ?? '';
    $statusFilter = $_GET['status'] ?? '';
    $priorityFilter = $_GET['priority'] ?? '';
    $sortBy = $_GET['sort'] ?? 'created_at';
    $sortDir = $_GET['dir'] ?? 'desc';
    $slaFilter = $_GET['sla_filter'] ?? '';
    $searchQuery = $_GET['search'] ?? '';
    
    // Build SQL query with filters
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
            LEFT JOIN ticket_categories tsc ON t.subcategory_id = tsc.id
            WHERE 1=1";
    
    $params = [];
    
    // Filter based on user role
    if ($user['role'] === 'agent') {
        $sql .= " AND t.assignee_id = ?";
        $params[] = $user['id'];
    }
    
    // Apply filters
    if (!empty($assigneeFilter) && $assigneeFilter !== 'all') {
        if ($assigneeFilter === 'unassigned') {
            $sql .= " AND t.assignee_id IS NULL";
        } else {
            $sql .= " AND t.assignee_id = ?";
            $params[] = $assigneeFilter;
        }
    }
    
    if (!empty($statusFilter) && $statusFilter !== 'all') {
        $sql .= " AND t.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($priorityFilter) && $priorityFilter !== 'all') {
        $sql .= " AND t.priority = ?";
        $params[] = $priorityFilter;
    }
    
    // Apply search filter
    if (!empty($searchQuery)) {
        $sql .= " AND (t.key LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR r.email LIKE ? OR r.name LIKE ?)";
        $searchParam = '%' . $searchQuery . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    // Apply sorting
    $validSortFields = ['created_at', 'updated_at', 'priority', 'status', 'subject', 'sla_proximity'];
    $validDirections = ['asc', 'desc'];
    
    if (!in_array($sortBy, $validSortFields)) {
        $sortBy = 'created_at';
    }
    if (!in_array(strtolower($sortDir), $validDirections)) {
        $sortDir = 'desc';
    }
    
    // Special sorting for priority (urgent first)
    if ($sortBy === 'priority') {
        $sql .= " ORDER BY FIELD(t.priority, 'urgent', 'high', 'normal', 'low') " . ($sortDir === 'desc' ? 'ASC' : 'DESC');
    } elseif ($sortBy === 'sla_proximity') {
        // SLA proximity will be sorted after fetching data
        $sql .= " ORDER BY t.created_at DESC";
    } else {
        $sql .= " ORDER BY t.{$sortBy} " . strtoupper($sortDir);
    }
    
    $sql .= " LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
    
    // Add SLA compliance information to each ticket and calculate SLA proximity
    foreach ($tickets as &$ticket) {
        if ($ticket['sla_policy_id']) {
            try {
                $ticket['sla_compliance'] = $slaService->checkSlaCompliance($ticket['id']);
                
                // Calculate SLA proximity score for sorting (0 = breached, 100 = safe)
                $responseProximity = 100;
                $resolutionProximity = 100;
                
                if (isset($ticket['sla_compliance']['response_hours']) && $ticket['response_target']) {
                    $responsePercentage = ($ticket['sla_compliance']['response_hours'] * 60) / $ticket['response_target'] * 100;
                    $responseProximity = max(0, 100 - $responsePercentage);
                }
                
                if (isset($ticket['sla_compliance']['resolution_hours']) && $ticket['resolution_target']) {
                    $resolutionPercentage = ($ticket['sla_compliance']['resolution_hours'] * 60) / $ticket['resolution_target'] * 100;
                    $resolutionProximity = max(0, 100 - $resolutionPercentage);
                }
                
                $ticket['sla_proximity_score'] = min($responseProximity, $resolutionProximity);
            } catch (Exception $e) {
                error_log("SLA compliance check failed for ticket {$ticket['id']}: " . $e->getMessage());
                $ticket['sla_proximity_score'] = 50; // Default middle score for sorting
            }
        } else {
            $ticket['sla_proximity_score'] = 50; // Default for tickets without SLA
        }
    }
    
    // Apply SLA filter
    if (!empty($slaFilter)) {
        switch ($slaFilter) {
            case 'breached':
                $tickets = array_filter($tickets, function($ticket) {
                    return isset($ticket['sla_compliance']) &&
                           (!$ticket['sla_compliance']['response_compliant'] || !$ticket['sla_compliance']['resolution_compliant']);
                });
                break;
            case 'at_risk':
                $tickets = array_filter($tickets, function($ticket) {
                    return isset($ticket['sla_compliance']) &&
                           $ticket['sla_compliance']['response_compliant'] &&
                           $ticket['sla_compliance']['resolution_compliant'] &&
                           $ticket['sla_proximity_score'] < 25; // Less than 25% buffer remaining
                });
                break;
            case 'safe':
                $tickets = array_filter($tickets, function($ticket) {
                    return isset($ticket['sla_compliance']) &&
                           $ticket['sla_compliance']['response_compliant'] &&
                           $ticket['sla_compliance']['resolution_compliant'] &&
                           $ticket['sla_proximity_score'] >= 25;
                });
                break;
        }
    }
    
    // Apply SLA proximity sorting if requested
    if ($sortBy === 'sla_proximity') {
        usort($tickets, function($a, $b) use ($sortDir) {
            $scoreA = $a['sla_proximity_score'];
            $scoreB = $b['sla_proximity_score'];
            
            if ($sortDir === 'asc') {
                return $scoreA <=> $scoreB; // Most at risk first
            } else {
                return $scoreB <=> $scoreA; // Safest first
            }
        });
    }
    
    // Get users for assignee filter (if admin/supervisor)
    $users = [];
    if (in_array($user['role'], ['admin', 'supervisor'])) {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
        $stmt->execute();
        $users = $stmt->fetchAll();
    }
    
    // Get statistics
    $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
                    SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count
                 FROM tickets";
    
    if ($user['role'] === 'agent') {
        $statsSql .= " WHERE assignee_id = ?";
        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute([$user['id']]);
    } else {
        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute();
    }
    
    $stats = $statsStmt->fetch();
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Tickets - ITSPtickets</title>
    <link rel="stylesheet" href="/ITSPtickets/css/realtime-notifications.css">
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
        .user-info .role { 
            background: #3b82f6; 
            color: white; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 12px; 
            text-transform: uppercase; 
            font-weight: 500;
        }
        .user-info a { color: #3b82f6; text-decoration: none; }
        .user-info a:hover { text-decoration: underline; }
        .stats-bar {
            display: flex;
            gap: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stat { font-weight: 500; color: #374151; }
        .actions { margin-bottom: 20px; }
        .btn { 
            display: inline-block; 
            padding: 10px 20px; 
            border-radius: 6px; 
            text-decoration: none; 
            font-weight: 500; 
            background: #3b82f6; 
            color: white;
        }
        .btn:hover { opacity: 0.9; }
        .btn-secondary { background: #6b7280; color: white; }
        .tickets-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .ticket-item { 
            border-bottom: 1px solid #e5e7eb; 
            padding: 20px; 
        }
        .ticket-item:last-child { border-bottom: none; }
        .ticket-header {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: nowrap;
        }
        .ticket-key { 
            font-weight: 600; 
            color: #3b82f6; 
            text-decoration: none;
        }
        .ticket-key:hover { text-decoration: underline; }
        .ticket-priority, .ticket-status {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 500;
            text-transform: uppercase;
            white-space: nowrap;
            display: inline-block;
        }
        .priority-urgent { background: #fee2e2; color: #991b1b; }
        .priority-high { background: #fef3c7; color: #92400e; }
        .priority-normal { background: #e0f2fe; color: #0369a1; }
        .priority-low { background: #f0f9ff; color: #0284c7; }
        .status-new { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #fef3c7; color: #92400e; }
        .status-waiting { background: #f3e8ff; color: #7c3aed; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        .ticket-subject { 
            font-weight: 500; 
            color: #1f2937; 
            margin-bottom: 8px; 
            font-size: 16px;
        }
        .ticket-meta {
            font-size: 13px;
            color: #6b7280;
        }
        .ticket-meta span { margin-right: 20px; }
        .sla-indicator {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 500;
            text-transform: uppercase;
            margin-left: 6px;
            white-space: nowrap;
        }
        .sla-compliant { background: #d1fae5; color: #065f46; }
        .sla-breach { background: #fee2e2; color: #991b1b; }
        .sla-warning { background: #fef3c7; color: #92400e; }
        .sla-details {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }
        .filters-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 25px;
            margin-bottom: 20px;
        }
        .filters-section h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        .filters-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .filter-section {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .filter-section-title {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            align-items: end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .filter-group label {
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        .filter-group select {
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.2s ease;
        }
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .filter-group select:hover {
            border-color: #9ca3af;
        }
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }
        .filter-actions .btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .btn-secondary:hover {
            background: #4b5563;
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
                font-size: 24px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
                font-size: 14px;
            }
            
            .user-info .role {
                font-size: 11px;
                padding: 3px 8px;
            }
            
            .stats-bar {
                flex-direction: column;
                gap: 10px;
                padding: 15px;
            }
            
            .stat {
                text-align: center;
                padding: 8px;
                background: #f8fafc;
                border-radius: 6px;
                font-size: 14px;
            }
            
            .filters-section {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .filters-section h3 {
                font-size: 16px;
                margin-bottom: 15px;
            }
            
            .filter-section {
                gap: 10px;
            }
            
            .filter-section-title {
                font-size: 13px;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .filter-group label {
                font-size: 13px;
            }
            
            .filter-group select {
                padding: 12px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .filter-group input[type="text"] {
                padding: 12px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .filter-actions {
                flex-direction: column;
                gap: 10px;
            }
            
            .filter-actions .btn {
                padding: 14px 20px;
                font-size: 16px;
            }
            
            .actions {
                margin-bottom: 15px;
            }
            
            .actions .btn {
                display: block;
                margin-bottom: 10px;
                text-align: center;
                padding: 14px 20px;
                font-size: 16px;
            }
            
            .ticket-item {
                padding: 15px;
                border-left: 4px solid #e5e7eb;
            }
            
            .ticket-header {
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 10px;
            }
            
            .ticket-key {
                font-size: 16px;
                font-weight: 700;
                order: 1;
                flex-basis: 100%;
                margin-bottom: 5px;
            }
            
            .ticket-priority, .ticket-status {
                font-size: 10px;
                padding: 3px 8px;
                border-radius: 12px;
                order: 2;
            }
            
            .sla-indicator {
                font-size: 8px;
                padding: 2px 6px;
                border-radius: 8px;
                order: 3;
            }
            
            .ticket-subject {
                font-size: 15px;
                margin-bottom: 10px;
                line-height: 1.4;
            }
            
            .ticket-meta {
                font-size: 12px;
                line-height: 1.5;
            }
            
            .ticket-meta span {
                display: block;
                margin-bottom: 4px;
                margin-right: 0;
            }
            
            .sla-details {
                font-size: 10px;
                margin-top: 6px;
                padding-top: 6px;
                border-top: 1px solid #f3f4f6;
            }
            
            .notification-bell {
                font-size: 16px;
                padding: 6px;
            }
            
            .notification-count {
                font-size: 10px;
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
                font-size: 20px;
            }
            
            .user-info {
                font-size: 13px;
            }
            
            .stats-bar {
                padding: 10px;
            }
            
            .stat {
                font-size: 13px;
                padding: 6px;
            }
            
            .filters-section {
                padding: 10px;
            }
            
            .filters-section h3 {
                font-size: 15px;
            }
            
            .ticket-item {
                padding: 12px;
            }
            
            .ticket-subject {
                font-size: 14px;
            }
            
            .ticket-meta {
                font-size: 11px;
            }
            
            .ticket-meta span {
                margin-bottom: 3px;
            }
            
            /* Make search input more prominent on small screens */
            .filter-group input[type="text"] {
                border-width: 2px;
                border-color: #3b82f6;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .btn, .filter-actions .btn, .actions .btn {
                min-height: 44px; /* Apple's recommended touch target size */
            }
            
            .filter-group select, .filter-group input {
                min-height: 44px;
            }
            
            .ticket-item {
                cursor: pointer;
            }
            
            .ticket-item:active {
                background-color: #f8fafc;
            }
            
            .notification-bell {
                min-width: 44px;
                min-height: 44px;
            }
        }
        
        /* Landscape phones */
        @media (max-width: 768px) and (orientation: landscape) {
            .stats-bar {
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .stat {
                flex: 1;
                min-width: 120px;
            }
            
            .filter-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Real-time notification integration styles */
        .header {
            position: relative;
        }
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
        }
        .notification-bell:hover {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        .stats-bar {
            position: relative;
        }
    </style>
</head>
<body>
    <!-- Connection Status Indicator -->
    <div class="connection-indicator" title="Connecting to real-time notifications..."></div>
    <div class='container'>
        <div class='header'>
            <h1>Tickets</h1>
            <div class='user-info'>
                <button class='notification-bell' title='Real-time notifications'>
                    🔔
                    <span class='notification-count'>0</span>
                </button>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <span class='role'><?= htmlspecialchars($user['role']) ?></span>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
                <a href='/ITSPtickets/logout.php'>Logout</a>
            </div>
        </div>
        
        
        <!-- Filter Controls -->
        <div class='filters-section'>
            <h3>🔍 Filter & Sort Tickets</h3>
            <form method='GET' class='filters-form'>
                <!-- Search Section -->
                <div class='filter-section'>
                    <div class='filter-section-title'>Search</div>
                    <div class='filter-row'>
                        <div class='filter-group' style='grid-column: span 2;'>
                            <label for='search'>🔍 Search Tickets</label>
                            <input type='text' id='search' name='search' value='<?= htmlspecialchars($searchQuery) ?>'
                                   placeholder='Search by ticket number, email, subject, or description...'
                                   style='padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; width: 100%; transition: all 0.2s ease;'>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class='filter-section'>
                    <div class='filter-section-title'>Filter Options</div>
                    <div class='filter-row'>
                        <?php if (in_array($user['role'], ['admin', 'supervisor'])): ?>
                        <div class='filter-group'>
                            <label for='assignee'>👤 Assigned To</label>
                            <select id='assignee' name='assignee'>
                                <option value='all' <?= $assigneeFilter === 'all' || empty($assigneeFilter) ? 'selected' : '' ?>>All Assignees</option>
                                <option value='unassigned' <?= $assigneeFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                                <?php foreach ($users as $assignee): ?>
                                    <option value='<?= $assignee['id'] ?>' <?= $assigneeFilter == $assignee['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($assignee['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class='filter-group'>
                            <label for='status'>📊 Status</label>
                            <select id='status' name='status'>
                                <option value='all' <?= $statusFilter === 'all' || empty($statusFilter) ? 'selected' : '' ?>>All Statuses</option>
                                <option value='new' <?= $statusFilter === 'new' ? 'selected' : '' ?>>New</option>
                                <option value='triaged' <?= $statusFilter === 'triaged' ? 'selected' : '' ?>>Triaged</option>
                                <option value='in_progress' <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value='waiting' <?= $statusFilter === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                                <option value='on_hold' <?= $statusFilter === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
                                <option value='resolved' <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                <option value='closed' <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                        
                        <div class='filter-group'>
                            <label for='priority'>🚨 Priority</label>
                            <select id='priority' name='priority'>
                                <option value='all' <?= $priorityFilter === 'all' || empty($priorityFilter) ? 'selected' : '' ?>>All Priorities</option>
                                <option value='urgent' <?= $priorityFilter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                                <option value='high' <?= $priorityFilter === 'high' ? 'selected' : '' ?>>High</option>
                                <option value='normal' <?= $priorityFilter === 'normal' ? 'selected' : '' ?>>Normal</option>
                                <option value='low' <?= $priorityFilter === 'low' ? 'selected' : '' ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class='filter-group'>
                            <label for='sla_filter'>⏱️ SLA Status</label>
                            <select id='sla_filter' name='sla_filter'>
                                <option value='' <?= empty($slaFilter) ? 'selected' : '' ?>>All SLA Statuses</option>
                                <option value='breached' <?= $slaFilter === 'breached' ? 'selected' : '' ?>>🚨 Breached</option>
                                <option value='at_risk' <?= $slaFilter === 'at_risk' ? 'selected' : '' ?>>⚠️ At Risk</option>
                                <option value='safe' <?= $slaFilter === 'safe' ? 'selected' : '' ?>>✅ Safe</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Sort Section -->
                <div class='filter-section'>
                    <div class='filter-section-title'>Sort Options</div>
                    <div class='filter-row'>
                        <div class='filter-group'>
                            <label for='sort'>📋 Sort By</label>
                            <select id='sort' name='sort'>
                                <option value='created_at' <?= $sortBy === 'created_at' ? 'selected' : '' ?>>Created Date</option>
                                <option value='updated_at' <?= $sortBy === 'updated_at' ? 'selected' : '' ?>>Updated Date</option>
                                <option value='priority' <?= $sortBy === 'priority' ? 'selected' : '' ?>>Priority</option>
                                <option value='status' <?= $sortBy === 'status' ? 'selected' : '' ?>>Status</option>
                                <option value='subject' <?= $sortBy === 'subject' ? 'selected' : '' ?>>Subject</option>
                                <option value='sla_proximity' <?= $sortBy === 'sla_proximity' ? 'selected' : '' ?>>SLA Risk</option>
                            </select>
                        </div>
                        
                        <div class='filter-group'>
                            <label for='dir'>🔄 Direction</label>
                            <select id='dir' name='dir'>
                                <option value='desc' <?= $sortDir === 'desc' ? 'selected' : '' ?>>Descending</option>
                                <option value='asc' <?= $sortDir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class='filter-actions'>
                    <button type='submit' class='btn btn-primary'>Apply Filters</button>
                    <a href='<?= $_SERVER['PHP_SELF'] ?>' class='btn btn-secondary'>Clear All</a>
                </div>
            </form>
        </div>
        
        <?php if ($user['role'] !== 'requester'): ?>
        <div class='actions'>
            <a href='/ITSPtickets/create-ticket-simple.php' class='btn'>Create New Ticket</a>
            <?php
            // Build export URL with current filters
            $exportParams = [
                'type' => 'tickets',
                'assignee' => $assigneeFilter,
                'status' => $statusFilter,
                'priority' => $priorityFilter,
                'sort' => $sortBy,
                'dir' => $sortDir,
                'sla_filter' => $slaFilter,
                'search' => $searchQuery
            ];
            $exportUrl = '/ITSPtickets/export-csv.php?' . http_build_query(array_filter($exportParams));
            ?>
            <a href='<?= htmlspecialchars($exportUrl) ?>' class='btn btn-secondary'>Export Filtered Results to CSV</a>
        </div>
        <?php endif; ?>
        
        <div class='tickets-list'>
            <?php if (empty($tickets)): ?>
                <div class='ticket-item'>
                    <?php if (!empty($searchQuery)): ?>
                        <p>No tickets found matching "<?= htmlspecialchars($searchQuery) ?>".</p>
                        <p style="margin-top: 10px; font-size: 14px; color: #6b7280;">Try different search terms or <a href="<?= $_SERVER['PHP_SELF'] ?>" style="color: #3b82f6;">clear all filters</a>.</p>
                    <?php else: ?>
                        <p>No tickets found.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <div class='ticket-item'>
                        <div class='ticket-header'>
                            <a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>' class='ticket-key'>
                                <?= htmlspecialchars($ticket['key']) ?>
                            </a>
                            <span class='ticket-priority priority-<?= strtolower($ticket['priority']) ?>'>
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                            <span class='ticket-status status-<?= str_replace('_', '-', strtolower($ticket['status'])) ?>'>
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                            <?php if (isset($ticket['sla_compliance'])): ?>
                                <?php
                                $isCompliant = $ticket['sla_compliance']['response_compliant'] && $ticket['sla_compliance']['resolution_compliant'];
                                $responseHours = $ticket['sla_compliance']['response_hours'] ?? 0;
                                $responseTarget = ($ticket['sla_compliance']['response_target'] ?? 0) / 60;
                                $isNearBreach = !$isCompliant || ($responseHours > ($responseTarget * 0.8));
                                ?>
                                <?php if (!$isCompliant): ?>
                                    <span class='sla-indicator sla-breach'>SLA Breach</span>
                                <?php elseif ($isNearBreach): ?>
                                    <span class='sla-indicator sla-warning'>SLA Warning</span>
                                <?php else: ?>
                                    <span class='sla-indicator sla-compliant'>SLA OK</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class='ticket-subject'><?= htmlspecialchars($ticket['subject']) ?></div>
                        <div class='ticket-meta'>
                            <span>By: <?= htmlspecialchars($ticket['requester_name']) ?></span>
                            <span>Created: <?= date('M j, Y g:i A', strtotime($ticket['created_at'])) ?></span>
                            <?php if ($ticket['category_name']): ?>
                                <span>Category: <?= htmlspecialchars($ticket['category_name']) ?>
                                    <?php if ($ticket['subcategory_name']): ?>
                                        > <?= htmlspecialchars($ticket['subcategory_name']) ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($ticket['assignee_name']): ?>
                                <span>Assigned: <?= htmlspecialchars($ticket['assignee_name']) ?></span>
                            <?php endif; ?>
                            <?php if (($ticket['time_spent'] ?? 0) > 0 || ($ticket['billable_hours'] ?? 0) > 0): ?>
                                <span style='color: #059669; font-weight: 500;'>
                                    ⏱️ 
                                    <?php if (($ticket['time_spent'] ?? 0) > 0): ?>
                                        <?= number_format($ticket['time_spent'], 1) ?>h spent
                                    <?php endif; ?>
                                    <?php if (($ticket['billable_hours'] ?? 0) > 0): ?>
                                        <?= ($ticket['time_spent'] ?? 0) > 0 ? ' | ' : '' ?><?= number_format($ticket['billable_hours'], 1) ?>h billable
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (isset($ticket['sla_compliance'])): ?>
                                <div class='sla-details'>
                                    SLA: <?= htmlspecialchars($ticket['sla_name'] ?? 'None') ?>
                                    <?php if ($ticket['sla_compliance']['response_hours']): ?>
                                        | Response: <?= round($ticket['sla_compliance']['response_hours'], 1) ?>h
                                    <?php endif; ?>
                                    <?php if ($ticket['sla_compliance']['resolution_hours']): ?>
                                        | Resolution: <?= round($ticket['sla_compliance']['resolution_hours'], 1) ?>h
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Real-time Notifications Scripts -->
    <script src="/ITSPtickets/js/realtime-notifications.js"></script>
    <script>
        // Initialize real-time notifications when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Add custom event listener for notifications
            window.addEventListener('realtime-notification', function(event) {
                const notification = event.detail;
                
                // Update page-specific elements based on notification type
                if (notification.type === 'new_tickets' && notification.count > 0) {
                    // Flash the New tickets counter
                    const newStat = document.querySelector('[data-stat="new_count"]');
                    if (newStat) {
                        newStat.classList.add('updated');
                        setTimeout(() => newStat.classList.remove('updated'), 1000);
                    }
                }
                
                if (notification.type === 'sla_breach') {
                    // Add visual emphasis to SLA breach indicators
                    document.querySelectorAll('.sla-breach').forEach(el => {
                        el.style.animation = 'flashUpdate 2s ease';
                    });
                }
                
                if (notification.type === 'new_assignments') {
                    // Highlight assigned tickets
                    document.querySelectorAll('.ticket-item').forEach(el => {
                        el.classList.add('flash');
                        setTimeout(() => el.classList.remove('flash'), 1000);
                    });
                }
            });
            
            // Optional: Refresh page data every 5 minutes as fallback
            setInterval(function() {
                // Only refresh if notifications are working
                if (window.realtimeNotifications && window.realtimeNotifications.isConnected) {
                    // Just update indicators, don't refresh full page
                    console.log('Real-time notifications active');
                }
            }, 300000); // 5 minutes
        });
    </script>
</body>
</html>