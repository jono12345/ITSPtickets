<?php
session_start();

// Check if user is logged in
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
    
    // Check if user has reporting permissions (agents can only see their own data)
    $canViewAll = in_array($user['role'], ['admin', 'supervisor']);
    
    // Get date range from parameters
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
    $dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today
    $reportType = $_GET['report_type'] ?? 'summary';
    
    // Build base filter for user role
    $userFilter = '';
    $userParams = [];
    if (!$canViewAll) {
        $userFilter = " AND t.assignee_id = ?";
        $userParams[] = $user['id'];
    }
    
    // Generate reports based on type
    $reportData = [];
    
    switch ($reportType) {
        case 'summary':
            $reportData = generateSummaryReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams);
            break;
        case 'tickets':
            $reportData = generateTicketReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams);
            break;
        case 'performance':
            $reportData = generatePerformanceReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams);
            break;
        case 'sla':
            $reportData = generateSlaReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams);
            break;
        case 'agents':
            if ($canViewAll) {
                $reportData = generateAgentReport($pdo, $dateFrom, $dateTo);
            }
            break;
    }
    
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

function generateSummaryReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams)
{
    $data = [];
    
    // Basic statistics
    $sql = "SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_tickets,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting_tickets,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_tickets,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_tickets,
                SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_tickets
            FROM tickets t 
            WHERE t.created_at BETWEEN ? AND ? {$userFilter}";
    
    $params = array_merge([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'], $userParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data['summary'] = $stmt->fetch();
    
    // Tickets by type
    $sql = "SELECT type, COUNT(*) as count 
            FROM tickets t 
            WHERE t.created_at BETWEEN ? AND ? {$userFilter}
            GROUP BY type ORDER BY count DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data['by_type'] = $stmt->fetchAll();
    
    // Tickets by priority
    $sql = "SELECT priority, COUNT(*) as count 
            FROM tickets t 
            WHERE t.created_at BETWEEN ? AND ? {$userFilter}
            GROUP BY priority ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data['by_priority'] = $stmt->fetchAll();
    
    // Daily ticket creation trend
    $sql = "SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM tickets t 
            WHERE t.created_at BETWEEN ? AND ? {$userFilter}
            GROUP BY DATE(created_at) ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data['daily_trend'] = $stmt->fetchAll();
    
    return $data;
}

function generateTicketReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams)
{
    $sql = "SELECT t.key, t.subject, t.type, t.priority, t.status, 
                   t.created_at, t.resolved_at, t.closed_at,
                   r.name as requester_name, r.email as requester_email,
                   u.name as assignee_name,
                   TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.resolved_at, NOW())) as resolution_hours
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            WHERE t.created_at BETWEEN ? AND ? {$userFilter}
            ORDER BY t.created_at DESC";
    
    $params = array_merge([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'], $userParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

function generatePerformanceReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams)
{
    $data = [];
    
    // Resolution times
    $sql = "SELECT 
                AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_resolution_hours,
                MIN(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as min_resolution_hours,
                MAX(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as max_resolution_hours,
                COUNT(*) as resolved_count
            FROM tickets t 
            WHERE t.resolved_at IS NOT NULL 
            AND t.created_at BETWEEN ? AND ? {$userFilter}";
    
    $params = array_merge([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'], $userParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data['resolution_stats'] = $stmt->fetch();
    
    // Response times (first response)
    $sql = "SELECT 
                AVG(TIMESTAMPDIFF(HOUR, created_at, first_response_at)) as avg_response_hours,
                MIN(TIMESTAMPDIFF(HOUR, created_at, first_response_at)) as min_response_hours,
                MAX(TIMESTAMPDIFF(HOUR, created_at, first_response_at)) as max_response_hours,
                COUNT(*) as responded_count
            FROM tickets t 
            WHERE t.first_response_at IS NOT NULL 
            AND t.created_at BETWEEN ? AND ? {$userFilter}";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data['response_stats'] = $stmt->fetch();
    
    return $data;
}

function generateSlaReport($pdo, $dateFrom, $dateTo, $userFilter, $userParams)
{
    require_once 'sla-service-simple.php';
    $slaService = new SlaServiceSimple($pdo);
    
    $data = [];
    
    // Get tickets with SLA policies
    $sql = "SELECT t.id, t.key, t.subject, t.priority, t.status,
                   t.created_at, t.first_response_at, t.resolved_at,
                   sp.name as sla_name, sp.response_target, sp.resolution_target
            FROM tickets t
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE t.sla_policy_id IS NOT NULL 
            AND t.created_at BETWEEN ? AND ? {$userFilter}
            ORDER BY t.created_at DESC";
    
    $params = array_merge([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'], $userParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
    
    $slaCompliance = [];
    $breachCount = 0;
    $complianceCount = 0;
    
    foreach ($tickets as $ticket) {
        $compliance = $slaService->checkSlaCompliance($ticket['id']);
        $compliance['ticket'] = $ticket;
        
        if (!$compliance['response_compliant'] || !$compliance['resolution_compliant']) {
            $breachCount++;
        } else {
            $complianceCount++;
        }
        
        $slaCompliance[] = $compliance;
    }
    
    $data['tickets'] = $slaCompliance;
    $data['summary'] = [
        'total_tickets' => count($tickets),
        'breach_count' => $breachCount,
        'compliance_count' => $complianceCount,
        'compliance_rate' => count($tickets) > 0 ? round(($complianceCount / count($tickets)) * 100, 2) : 0
    ];
    
    return $data;
}

function generateAgentReport($pdo, $dateFrom, $dateTo)
{
    $sql = "SELECT u.name, u.email,
                   COUNT(t.id) as total_assigned,
                   SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
                   SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                   AVG(CASE WHEN t.resolved_at IS NOT NULL 
                       THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) 
                       ELSE NULL END) as avg_resolution_hours
            FROM users u
            LEFT JOIN tickets t ON u.id = t.assignee_id 
                AND t.created_at BETWEEN ? AND ?
            WHERE u.role IN ('agent', 'supervisor') AND u.active = 1
            GROUP BY u.id, u.name, u.email
            ORDER BY total_assigned DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    
    return $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Reports - ITSPtickets</title>
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
            gap: 20px;
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
        .btn-secondary {
            background: #6b7280;
            color: white;
            font-size: 12px;
            padding: 6px 12px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #1f2937;
        }
        .report-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .section-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .section-content {
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th,
        table td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        table tr:hover {
            background: #f9fafb;
        }
        .priority-urgent { color: #dc2626; font-weight: 600; }
        .priority-high { color: #ea580c; font-weight: 600; }
        .priority-normal { color: #0369a1; }
        .priority-low { color: #059669; }
        .status-new { color: #2563eb; }
        .status-in-progress { color: #d97706; }
        .status-waiting { color: #7c3aed; }
        .status-resolved { color: #059669; }
        .status-closed { color: #6b7280; }
        .chart-container {
            height: 300px;
            display: flex;
            align-items: end;
            gap: 5px;
            padding: 20px 0;
        }
        .chart-bar {
            background: #3b82f6;
            border-radius: 4px 4px 0 0;
            min-height: 10px;
            flex: 1;
            display: flex;
            align-items: end;
            justify-content: center;
            color: white;
            font-size: 12px;
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
                font-size: 24px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
                font-size: 14px;
            }
            
            .filters {
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .filters form {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .form-group label {
                font-size: 15px;
                margin-bottom: 6px;
            }
            
            .form-group input,
            .form-group select {
                padding: 12px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
                border-radius: 8px;
            }
            
            .btn {
                width: 100%;
                padding: 12px 16px;
                font-size: 16px;
                min-height: 44px;
            }
            
            .btn-secondary {
                padding: 10px 14px;
                font-size: 14px;
                margin-bottom: 8px;
                width: auto;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                padding: 15px;
                text-align: center;
            }
            
            .stat-card h3 {
                font-size: 13px;
                margin-bottom: 8px;
            }
            
            .stat-number {
                font-size: 24px;
            }
            
            .report-section {
                margin-bottom: 20px;
                border-radius: 6px;
            }
            
            .section-header {
                padding: 15px;
            }
            
            .section-header h2 {
                font-size: 18px;
            }
            
            .section-content {
                padding: 15px;
            }
            
            /* Table responsive design */
            table {
                font-size: 13px;
                width: 100%;
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            table thead,
            table tbody,
            table tr {
                display: block;
            }
            
            table tr {
                border: 1px solid #e5e7eb;
                margin-bottom: 8px;
                padding: 10px;
                border-radius: 6px;
                background: #f9fafb;
                position: relative;
            }
            
            table th,
            table td {
                display: block;
                text-align: left;
                padding: 4px 0;
                border: none;
                white-space: normal;
            }
            
            table th {
                display: none;
            }
            
            table td:before {
                content: attr(data-label) ": ";
                font-weight: 600;
                color: #374151;
                margin-right: 8px;
                display: inline-block;
                min-width: 100px;
            }
            
            /* Chart responsiveness */
            .chart-container {
                height: 200px;
                padding: 15px 0;
                gap: 3px;
            }
            
            .chart-bar {
                font-size: 10px;
                min-width: 20px;
            }
            
            /* SLA section mobile improvements */
            .section-content > div[style*="grid-template-columns"] {
                display: flex !important;
                flex-direction: column !important;
                gap: 20px !important;
            }
            
            .section-content div[style*="width: 150px"] {
                width: 120px !important;
                height: 120px !important;
            }
            
            .section-content div[style*="width: 80px"] {
                width: 60px !important;
                height: 60px !important;
                font-size: 14px;
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
            
            .filters {
                padding: 10px;
                margin-bottom: 15px;
            }
            
            .filters form {
                gap: 12px;
            }
            
            .form-group label {
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .form-group input,
            .form-group select {
                padding: 16px 14px; /* Larger for better touch targets */
                font-size: 16px;
                border-width: 2px;
                border-radius: 10px;
            }
            
            .btn {
                padding: 16px 20px;
                font-size: 17px;
                min-height: 52px;
                border-radius: 10px;
            }
            
            .btn-secondary {
                padding: 12px 16px;
                font-size: 15px;
                min-height: 44px;
                border-radius: 8px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
                margin-bottom: 15px;
            }
            
            .stat-card {
                padding: 12px;
                text-align: left;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .stat-card h3 {
                font-size: 14px;
                margin-bottom: 0;
                order: 1;
            }
            
            .stat-number {
                font-size: 20px;
                order: 2;
            }
            
            .section-header {
                padding: 12px 10px;
            }
            
            .section-header h2 {
                font-size: 16px;
            }
            
            .section-content {
                padding: 12px 10px;
            }
            
            table {
                font-size: 12px;
            }
            
            table tr {
                padding: 8px;
                margin-bottom: 6px;
            }
            
            table td {
                padding: 3px 0;
                font-size: 12px;
                line-height: 1.4;
            }
            
            table td:before {
                font-size: 11px;
                min-width: 80px;
                display: block;
                margin-bottom: 2px;
                margin-right: 0;
                color: #6b7280;
            }
            
            .chart-container {
                height: 150px;
                padding: 10px 0;
                gap: 2px;
            }
            
            .chart-bar {
                font-size: 9px;
                min-width: 15px;
            }
            
            /* Pie chart mobile adjustments */
            .section-content div[style*="width: 150px"] {
                width: 100px !important;
                height: 100px !important;
            }
            
            .section-content div[style*="width: 80px"] {
                width: 50px !important;
                height: 50px !important;
                font-size: 12px;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .btn {
                min-height: 44px; /* Apple's recommended touch target size */
            }
            
            .form-group input,
            .form-group select {
                min-height: 44px;
            }
            
            .btn:active {
                transform: scale(0.98);
                transition: transform 0.1s ease;
            }
            
            .stat-card:active {
                background-color: #f3f4f6;
                transform: scale(0.98);
                transition: transform 0.1s ease;
            }
            
            .table tr:active {
                background-color: #f1f5f9;
            }
            
            .form-group select {
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
                background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'%3E%3Cpath fill='%23666' d='m2 0-2 2h4zm0 5 2-2H0z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 12px center;
                background-size: 12px;
                padding-right: 36px;
            }
        }
        
        /* Landscape phones */
        @media (max-width: 768px) and (orientation: landscape) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .chart-container {
                height: 180px;
            }
            
            .section-content > div[style*="grid-template-columns"] {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 15px !important;
            }
        }
        
        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .filters,
            .report-section,
            .stat-card {
                border: 2px solid #000;
            }
            
            .form-group input,
            .form-group select {
                border-width: 2px;
            }
            
            .btn {
                border: 2px solid #fff;
            }
            
            table tr {
                border-width: 2px;
            }
        }
        
        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            .btn:active,
            .stat-card:active {
                transform: none;
            }
            
            .chart-bar {
                transition: none;
            }
        }
        
        /* Print styles */
        @media print {
            .container {
                padding: 0;
                max-width: none;
            }
            
            .header {
                background: none !important;
                box-shadow: none !important;
                border-bottom: 2px solid #000;
            }
            
            .filters {
                display: none;
            }
            
            .chart-container {
                display: none;
            }
            
            table {
                display: table !important;
                font-size: 10px;
            }
            
            table thead,
            table tbody,
            table tr {
                display: table-row-group !important;
            }
            
            table tr {
                display: table-row !important;
                border: none !important;
                background: none !important;
            }
            
            table th,
            table td {
                display: table-cell !important;
                border: 1px solid #000 !important;
                padding: 6px !important;
            }
            
            table th {
                display: table-cell !important;
                background: #f0f0f0 !important;
            }
            
            table td:before {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Reports & Analytics</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <a href='/ITSPtickets/tickets-simple.php'>Tickets</a>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
                <a href='/ITSPtickets/logout.php'>Logout</a>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div style='background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
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
                    <label for='report_type'>Report Type</label>
                    <select id='report_type' name='report_type'>
                        <option value='summary' <?= $reportType === 'summary' ? 'selected' : '' ?>>Summary</option>
                        <option value='tickets' <?= $reportType === 'tickets' ? 'selected' : '' ?>>Ticket Details</option>
                        <option value='performance' <?= $reportType === 'performance' ? 'selected' : '' ?>>Performance</option>
                        <option value='sla' <?= $reportType === 'sla' ? 'selected' : '' ?>>SLA Compliance</option>
                        <?php if ($canViewAll): ?>
                            <option value='agents' <?= $reportType === 'agents' ? 'selected' : '' ?>>Agent Performance</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class='form-group'>
                    <button type='submit' class='btn'>Generate Report</button>
                </div>
                
                <div class='form-group'>
                    <label>&nbsp;</label>
                    <div style='display: flex; gap: 10px; flex-wrap: wrap;'>
                        <?php if ($reportType === 'tickets'): ?>
                            <a href='/ITSPtickets/export-csv.php?type=tickets' class='btn btn-secondary'>Export Tickets CSV</a>
                        <?php elseif ($reportType === 'sla'): ?>
                            <a href='/ITSPtickets/export-csv.php?type=sla_report&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>' class='btn btn-secondary'>Export SLA CSV</a>
                            <a href='/ITSPtickets/export-csv.php?type=sla_breaches' class='btn btn-secondary'>Export Breaches CSV</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if (!empty($reportData)): ?>
            
            <?php if ($reportType === 'summary'): ?>
                <div class='stats-grid'>
                    <div class='stat-card'>
                        <h3>Total Tickets</h3>
                        <div class='stat-number'><?= $reportData['summary']['total_tickets'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>New Tickets</h3>
                        <div class='stat-number'><?= $reportData['summary']['new_tickets'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>In Progress</h3>
                        <div class='stat-number'><?= $reportData['summary']['in_progress_tickets'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Resolved</h3>
                        <div class='stat-number'><?= $reportData['summary']['resolved_tickets'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Urgent Priority</h3>
                        <div class='stat-number'><?= $reportData['summary']['urgent_tickets'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>High Priority</h3>
                        <div class='stat-number'><?= $reportData['summary']['high_tickets'] ?></div>
                    </div>
                </div>
                
                <div class='report-section'>
                    <div class='section-header'>
                        <h2>Tickets by Type</h2>
                    </div>
                    <div class='section-content'>
                        <table>
                            <thead>
                                <tr><th>Type</th><th>Count</th><th>Percentage</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total = $reportData['summary']['total_tickets'];
                                foreach ($reportData['by_type'] as $row): 
                                    $percentage = $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td><?= ucfirst(htmlspecialchars($row['type'])) ?></td>
                                        <td><?= $row['count'] ?></td>
                                        <td><?= $percentage ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class='report-section'>
                    <div class='section-header'>
                        <h2>Daily Ticket Trend</h2>
                    </div>
                    <div class='section-content'>
                        <?php if (!empty($reportData['daily_trend'])): ?>
                            <?php 
                            $maxCount = max(array_column($reportData['daily_trend'], 'count'));
                            ?>
                            <div class='chart-container'>
                                <?php foreach ($reportData['daily_trend'] as $day): ?>
                                    <?php 
                                    $height = $maxCount > 0 ? ($day['count'] / $maxCount) * 280 : 10;
                                    ?>
                                    <div class='chart-bar' style='height: <?= $height ?>px;' title='<?= $day['date'] ?>: <?= $day['count'] ?> tickets'>
                                        <?= $day['count'] ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p>No data available for the selected date range.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($reportType === 'tickets'): ?>
                <div class='report-section'>
                    <div class='section-header'>
                        <h2>Ticket Details</h2>
                    </div>
                    <div class='section-content'>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Subject</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Requester</th>
                                    <th>Assignee</th>
                                    <th>Created</th>
                                    <th>Resolution Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $ticket): ?>
                                    <tr>
                                        <td><a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>'><?= htmlspecialchars($ticket['key']) ?></a></td>
                                        <td><?= htmlspecialchars($ticket['subject']) ?></td>
                                        <td><?= ucfirst(htmlspecialchars($ticket['type'])) ?></td>
                                        <td class='priority-<?= $ticket['priority'] ?>'><?= ucfirst(htmlspecialchars($ticket['priority'])) ?></td>
                                        <td class='status-<?= str_replace('_', '-', $ticket['status']) ?>'><?= ucfirst(str_replace('_', ' ', htmlspecialchars($ticket['status']))) ?></td>
                                        <td><?= htmlspecialchars($ticket['requester_name'] ?? 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($ticket['assignee_name'] ?? 'Unassigned') ?></td>
                                        <td><?= date('M j, Y', strtotime($ticket['created_at'])) ?></td>
                                        <td><?= $ticket['resolution_hours'] ? round($ticket['resolution_hours'], 1) . 'h' : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <?php elseif ($reportType === 'performance'): ?>
                <div class='stats-grid'>
                    <div class='stat-card'>
                        <h3>Avg Resolution Time</h3>
                        <div class='stat-number'><?= $reportData['resolution_stats']['avg_resolution_hours'] ? round($reportData['resolution_stats']['avg_resolution_hours'], 1) . 'h' : 'N/A' ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Avg Response Time</h3>
                        <div class='stat-number'><?= $reportData['response_stats']['avg_response_hours'] ? round($reportData['response_stats']['avg_response_hours'], 1) . 'h' : 'N/A' ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Tickets Resolved</h3>
                        <div class='stat-number'><?= $reportData['resolution_stats']['resolved_count'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Tickets Responded</h3>
                        <div class='stat-number'><?= $reportData['response_stats']['responded_count'] ?></div>
                    </div>
                </div>
                
            <?php elseif ($reportType === 'sla'): ?>
                <div class='stats-grid'>
                    <div class='stat-card'>
                        <h3>SLA Compliance Rate</h3>
                        <div class='stat-number' style='color: <?= $reportData['summary']['compliance_rate'] >= 80 ? '#059669' : ($reportData['summary']['compliance_rate'] >= 60 ? '#d97706' : '#dc2626') ?>'>
                            <?= $reportData['summary']['compliance_rate'] ?>%
                        </div>
                    </div>
                    <div class='stat-card'>
                        <h3>Total SLA Tickets</h3>
                        <div class='stat-number'><?= $reportData['summary']['total_tickets'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>SLA Breaches</h3>
                        <div class='stat-number' style='color: #dc2626'><?= $reportData['summary']['breach_count'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>SLA Compliant</h3>
                        <div class='stat-number' style='color: #059669'><?= $reportData['summary']['compliance_count'] ?></div>
                    </div>
                </div>
                
                <!-- SLA Compliance Chart -->
                <div class='report-section'>
                    <div class='section-header'>
                        <h2>SLA Compliance Overview</h2>
                    </div>
                    <div class='section-content'>
                        <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px;'>
                            <!-- Compliance Pie Chart -->
                            <div>
                                <h4>Compliance Distribution</h4>
                                <div style='display: flex; align-items: center; gap: 20px; margin: 20px 0;'>
                                    <div style='width: 150px; height: 150px; border-radius: 50%; background: conic-gradient(#059669 0deg <?= ($reportData['summary']['compliance_count'] / max($reportData['summary']['total_tickets'], 1)) * 360 ?>deg, #dc2626 <?= ($reportData['summary']['compliance_count'] / max($reportData['summary']['total_tickets'], 1)) * 360 ?>deg 360deg); position: relative;'>
                                        <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #1f2937;'>
                                            <?= $reportData['summary']['compliance_rate'] ?>%
                                        </div>
                                    </div>
                                    <div>
                                        <div style='display: flex; align-items: center; gap: 8px; margin-bottom: 8px;'>
                                            <div style='width: 16px; height: 16px; background: #059669; border-radius: 2px;'></div>
                                            <span>Compliant: <?= $reportData['summary']['compliance_count'] ?></span>
                                        </div>
                                        <div style='display: flex; align-items: center; gap: 8px;'>
                                            <div style='width: 16px; height: 16px; background: #dc2626; border-radius: 2px;'></div>
                                            <span>Breaches: <?= $reportData['summary']['breach_count'] ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- SLA Policy Performance -->
                            <div>
                                <h4>Performance by SLA Policy</h4>
                                <?php
                                $policyPerformance = [];
                                foreach ($reportData['tickets'] as $item) {
                                    $policyName = $item['ticket']['sla_name'];
                                    if (!isset($policyPerformance[$policyName])) {
                                        $policyPerformance[$policyName] = ['total' => 0, 'compliant' => 0];
                                    }
                                    $policyPerformance[$policyName]['total']++;
                                    if ($item['response_compliant'] && $item['resolution_compliant']) {
                                        $policyPerformance[$policyName]['compliant']++;
                                    }
                                }
                                ?>
                                <div style='margin-top: 20px;'>
                                    <?php foreach ($policyPerformance as $policy => $stats): ?>
                                        <?php $rate = $stats['total'] > 0 ? round(($stats['compliant'] / $stats['total']) * 100, 1) : 0; ?>
                                        <div style='margin-bottom: 15px;'>
                                            <div style='display: flex; justify-content: space-between; margin-bottom: 4px;'>
                                                <span style='font-size: 14px; font-weight: 500;'><?= htmlspecialchars($policy) ?></span>
                                                <span style='font-size: 14px; color: <?= $rate >= 80 ? '#059669' : ($rate >= 60 ? '#d97706' : '#dc2626') ?>'><?= $rate ?>%</span>
                                            </div>
                                            <div style='width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;'>
                                                <div style='width: <?= $rate ?>%; height: 100%; background: <?= $rate >= 80 ? '#059669' : ($rate >= 60 ? '#d97706' : '#dc2626') ?>'></div>
                                            </div>
                                            <div style='font-size: 12px; color: #6b7280; margin-top: 2px;'>
                                                <?= $stats['compliant'] ?>/<?= $stats['total'] ?> tickets
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Breach Analysis -->
                <?php if ($reportData['summary']['breach_count'] > 0): ?>
                    <div class='report-section'>
                        <div class='section-header'>
                            <h2>SLA Breach Analysis</h2>
                        </div>
                        <div class='section-content'>
                            <?php
                            $responseBreaches = 0;
                            $resolutionBreaches = 0;
                            $avgResponseOverrun = 0;
                            $avgResolutionOverrun = 0;
                            $breachCount = 0;
                            
                            foreach ($reportData['tickets'] as $item) {
                                if (!$item['response_compliant'] || !$item['resolution_compliant']) {
                                    $breachCount++;
                                    if (!$item['response_compliant']) {
                                        $responseBreaches++;
                                        if (isset($item['response_hours'])) {
                                            $target = ($item['response_target'] ?? 0) / 60;
                                            $overrun = $item['response_hours'] - $target;
                                            $avgResponseOverrun += max(0, $overrun);
                                        }
                                    }
                                    if (!$item['resolution_compliant']) {
                                        $resolutionBreaches++;
                                        if (isset($item['resolution_hours'])) {
                                            $target = ($item['resolution_target'] ?? 0) / 60;
                                            $overrun = $item['resolution_hours'] - $target;
                                            $avgResolutionOverrun += max(0, $overrun);
                                        }
                                    }
                                }
                            }
                            
                            $avgResponseOverrun = $responseBreaches > 0 ? $avgResponseOverrun / $responseBreaches : 0;
                            $avgResolutionOverrun = $resolutionBreaches > 0 ? $avgResolutionOverrun / $resolutionBreaches : 0;
                            ?>
                            
                            <div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;'>
                                <div style='text-align: center; padding: 15px; background: #fee2e2; border-radius: 6px;'>
                                    <div style='font-size: 24px; font-weight: 600; color: #dc2626;'><?= $responseBreaches ?></div>
                                    <div style='font-size: 14px; color: #991b1b;'>Response Breaches</div>
                                    <div style='font-size: 12px; color: #6b7280; margin-top: 4px;'>
                                        Avg overrun: <?= round($avgResponseOverrun, 1) ?>h
                                    </div>
                                </div>
                                
                                <div style='text-align: center; padding: 15px; background: #fee2e2; border-radius: 6px;'>
                                    <div style='font-size: 24px; font-weight: 600; color: #dc2626;'><?= $resolutionBreaches ?></div>
                                    <div style='font-size: 14px; color: #991b1b;'>Resolution Breaches</div>
                                    <div style='font-size: 12px; color: #6b7280; margin-top: 4px;'>
                                        Avg overrun: <?= round($avgResolutionOverrun, 1) ?>h
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class='report-section'>
                    <div class='section-header'>
                        <h2>SLA Details</h2>
                    </div>
                    <div class='section-content'>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Subject</th>
                                    <th>Priority</th>
                                    <th>SLA Policy</th>
                                    <th>Response Status</th>
                                    <th>Resolution Status</th>
                                    <th>Response Time</th>
                                    <th>Resolution Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['tickets'] as $item): ?>
                                    <?php $ticket = $item['ticket']; ?>
                                    <tr style='<?= (!$item['response_compliant'] || !$item['resolution_compliant']) ? 'background: #fef2f2;' : '' ?>'>
                                        <td><a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>'><?= htmlspecialchars($ticket['key']) ?></a></td>
                                        <td><?= htmlspecialchars($ticket['subject']) ?></td>
                                        <td class='priority-<?= $ticket['priority'] ?>'><?= ucfirst(htmlspecialchars($ticket['priority'])) ?></td>
                                        <td><?= htmlspecialchars($ticket['sla_name']) ?></td>
                                        <td>
                                            <span style='color: <?= $item['response_compliant'] ? '#059669' : '#dc2626' ?>; font-weight: 600;'>
                                                <?= $item['response_compliant'] ? '✓ Compliant' : '✗ Breach' ?>
                                            </span>
                                            <?php if (!$item['response_compliant'] && isset($item['response_hours'])): ?>
                                                <div style='font-size: 11px; color: #dc2626;'>
                                                    <?= round((($item['response_hours'] - ($item['response_target'] / 60)) / ($item['response_target'] / 60)) * 100, 1) ?>% over
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style='color: <?= $item['resolution_compliant'] ? '#059669' : '#dc2626' ?>; font-weight: 600;'>
                                                <?= $item['resolution_compliant'] ? '✓ Compliant' : '✗ Breach' ?>
                                            </span>
                                            <?php if (!$item['resolution_compliant'] && isset($item['resolution_hours'])): ?>
                                                <div style='font-size: 11px; color: #dc2626;'>
                                                    <?= round((($item['resolution_hours'] - ($item['resolution_target'] / 60)) / ($item['resolution_target'] / 60)) * 100, 1) ?>% over
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= isset($item['response_hours']) ? round($item['response_hours'], 1) . 'h' : '-' ?>
                                            <div style='font-size: 11px; color: #6b7280;'>
                                                Target: <?= round($item['response_target'] / 60, 1) ?>h
                                            </div>
                                        </td>
                                        <td>
                                            <?= isset($item['resolution_hours']) ? round($item['resolution_hours'], 1) . 'h' : '-' ?>
                                            <div style='font-size: 11px; color: #6b7280;'>
                                                Target: <?= round($item['resolution_target'] / 60, 1) ?>h
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <?php elseif ($reportType === 'agents' && $canViewAll): ?>
                <div class='report-section'>
                    <div class='section-header'>
                        <h2>Agent Performance</h2>
                    </div>
                    <div class='section-content'>
                        <table>
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th>Email</th>
                                    <th>Assigned</th>
                                    <th>Resolved</th>
                                    <th>Closed</th>
                                    <th>Resolution Rate</th>
                                    <th>Avg Resolution Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $agent): ?>
                                    <?php $resolutionRate = $agent['total_assigned'] > 0 ? round(($agent['resolved_count'] / $agent['total_assigned']) * 100, 1) : 0; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($agent['name']) ?></td>
                                        <td><?= htmlspecialchars($agent['email']) ?></td>
                                        <td><?= $agent['total_assigned'] ?></td>
                                        <td><?= $agent['resolved_count'] ?></td>
                                        <td><?= $agent['closed_count'] ?></td>
                                        <td><?= $resolutionRate ?>%</td>
                                        <td><?= $agent['avg_resolution_hours'] ? round($agent['avg_resolution_hours'], 1) . 'h' : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class='report-section'>
                <div class='section-content'>
                    <p>No data available for the selected criteria. Please adjust your filters and try again.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>