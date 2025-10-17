<?php
session_start();

require_once 'config/database.php';
require_once 'sla-service-simple.php';
require_once 'notification-service-simple.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Initialize services
    $slaService = new SlaServiceSimple($pdo);
    $notificationService = new NotificationServiceSimple($pdo);
    
    // Handle login/logout
    $loggedIn = false;
    $requester = null;
    $error = '';
    $success = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'login':
                    if (!empty($_POST['email'])) {
                        $stmt = $pdo->prepare("SELECT * FROM requesters WHERE email = ? AND active = 1");
                        $stmt->execute([$_POST['email']]);
                        $requester = $stmt->fetch();
                        
                        if ($requester) {
                            $_SESSION['requester_id'] = $requester['id'];
                            $_SESSION['requester_email'] = $requester['email'];
                            $loggedIn = true;
                            $success = "Welcome back, " . htmlspecialchars($requester['name']);
                        } else {
                            $error = "Email not found in our system. Please contact support.";
                        }
                    } else {
                        $error = "Please enter your email address.";
                    }
                    break;
                    
                case 'logout':
                    session_destroy();
                    session_start();
                    $success = "You have been logged out.";
                    break;
                    
                case 'create_ticket':
                    if (isset($_SESSION['requester_id'])) {
                        $required = ['subject', 'description', 'type', 'priority', 'category_id'];
                        $errors = [];
                        
                        foreach ($required as $field) {
                            if (empty($_POST[$field])) {
                                $errors[] = "Field '{$field}' is required";
                            }
                        }
                        
                        if (empty($errors)) {
                            // Generate ticket key
                            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(`key`, 5) AS UNSIGNED)) as max_num FROM tickets WHERE `key` LIKE 'TKT-%'");
                            $stmt->execute();
                            $result = $stmt->fetch();
                            $nextNum = ($result['max_num'] ?? 0) + 1;
                            $ticketKey = 'TKT-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
                            
                            // Create ticket
                            $stmt = $pdo->prepare("INSERT INTO tickets (
                                `key`, type, subject, description, priority, status,
                                requester_id, channel, category_id, subcategory_id, created_at
                            ) VALUES (?, ?, ?, ?, ?, 'new', ?, 'web', ?, ?, NOW())");
                            
                            $subcategoryId = !empty($_POST['subcategory_id']) ? $_POST['subcategory_id'] : null;
                            
                            $stmt->execute([
                                $ticketKey,
                                $_POST['type'],
                                $_POST['subject'],
                                $_POST['description'],
                                $_POST['priority'],
                                $_SESSION['requester_id'],
                                $_POST['category_id'],
                                $subcategoryId
                            ]);
                            
                            $ticketId = $pdo->lastInsertId();
                            
                            // Assign SLA policy
                            $slaService->assignSlaPolicy($ticketId, $_POST['type'], $_POST['priority']);
                            
                            // Send notification
                            $notificationService->sendNotification('ticket_created', $ticketId);
                            
                            $success = "Ticket {$ticketKey} created successfully!";
                        } else {
                            $error = implode(', ', $errors);
                        }
                    }
                    break;
                    
                case 'add_message':
                    if (isset($_SESSION['requester_id']) && !empty($_POST['ticket_id']) && !empty($_POST['message'])) {
                        // Verify ticket belongs to requester
                        $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND requester_id = ?");
                        $stmt->execute([$_POST['ticket_id'], $_SESSION['requester_id']]);
                        
                        if ($stmt->fetch()) {
                            $stmt = $pdo->prepare("INSERT INTO ticket_messages (
                                ticket_id, sender_type, sender_id, message, is_private, channel, created_at
                            ) VALUES (?, 'requester', ?, ?, 0, 'web', NOW())");
                            
                            $stmt->execute([
                                $_POST['ticket_id'],
                                $_SESSION['requester_id'],
                                $_POST['message']
                            ]);
                            
                            // Update ticket last_update_at
                            $stmt = $pdo->prepare("UPDATE tickets SET last_update_at = NOW() WHERE id = ?");
                            $stmt->execute([$_POST['ticket_id']]);
                            
                            // Send notification
                            $notificationService->sendNotification('message_added', $_POST['ticket_id'], [
                                'message' => $_POST['message'],
                                'sender_name' => $requester['name']
                            ]);
                            
                            $success = "Message added successfully!";
                        } else {
                            $error = "Ticket not found or access denied.";
                        }
                    }
                    break;
            }
        }
    }
    
    // Check if already logged in
    if (isset($_SESSION['requester_id']) && !$requester) {
        $stmt = $pdo->prepare("SELECT * FROM requesters WHERE id = ? AND active = 1");
        $stmt->execute([$_SESSION['requester_id']]);
        $requester = $stmt->fetch();
        $loggedIn = !!$requester;
    }
    
    // Get tickets if logged in
    $tickets = [];
    $selectedTicket = null;
    $messages = [];
    
    if ($loggedIn) {
        $stmt = $pdo->prepare("SELECT t.*, u.name as assignee_name,
                                      sp.name as sla_name, sp.response_target, sp.resolution_target,
                                      tc.name as category_name,
                                      tsc.name as subcategory_name
                               FROM tickets t
                               LEFT JOIN users u ON t.assignee_id = u.id
                               LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
                               LEFT JOIN ticket_categories tc ON t.category_id = tc.id
                               LEFT JOIN ticket_categories tsc ON t.subcategory_id = tsc.id
                               WHERE t.requester_id = ?
                               ORDER BY t.created_at DESC");
        $stmt->execute([$requester['id']]);
        $tickets = $stmt->fetchAll();
        
        // Add SLA compliance information to each ticket
        foreach ($tickets as &$ticket) {
            if ($ticket['sla_policy_id']) {
                try {
                    $ticket['sla_compliance'] = $slaService->checkSlaCompliance($ticket['id']);
                } catch (Exception $e) {
                    error_log("SLA compliance check failed for ticket {$ticket['id']}: " . $e->getMessage());
                    // Continue without SLA info for this ticket
                }
            }
        }
        
        // Get selected ticket details
        if (isset($_GET['ticket_id'])) {
            $stmt = $pdo->prepare("SELECT t.*, u.name as assignee_name,
                                          sp.name as sla_name, sp.response_target, sp.resolution_target,
                                          tc.name as category_name,
                                          tsc.name as subcategory_name
                                   FROM tickets t
                                   LEFT JOIN users u ON t.assignee_id = u.id
                                   LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
                                   LEFT JOIN ticket_categories tc ON t.category_id = tc.id
                                   LEFT JOIN ticket_categories tsc ON t.subcategory_id = tsc.id
                                   WHERE t.id = ? AND t.requester_id = ?");
            $stmt->execute([$_GET['ticket_id'], $requester['id']]);
            $selectedTicket = $stmt->fetch();
            
            if ($selectedTicket && $selectedTicket['sla_policy_id']) {
                $selectedTicket['sla_compliance'] = $slaService->checkSlaCompliance($selectedTicket['id']);
            }
            
            if ($selectedTicket) {
                // Get messages (public only)
                $stmt = $pdo->prepare("SELECT tm.*, 
                                              CASE 
                                                  WHEN tm.sender_type = 'user' THEN u.name
                                                  WHEN tm.sender_type = 'requester' THEN r.name
                                                  ELSE 'System'
                                              END as sender_name
                                       FROM ticket_messages tm
                                       LEFT JOIN users u ON tm.sender_type = 'user' AND tm.sender_id = u.id
                                       LEFT JOIN requesters r ON tm.sender_type = 'requester' AND tm.sender_id = r.id
                                       WHERE tm.ticket_id = ? AND tm.is_private = 0
                                       ORDER BY tm.created_at ASC");
                $stmt->execute([$selectedTicket['id']]);
                $messages = $stmt->fetchAll();
            }
        }
    }
    
    // Get categories for ticket creation (only if logged in)
    $categories = [];
    $subcategories = [];
    if ($loggedIn) {
        $stmt = $pdo->prepare("SELECT id, name FROM ticket_categories WHERE parent_id IS NULL ORDER BY name ASC");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        // Get all subcategories for JavaScript
        $stmt = $pdo->prepare("SELECT id, name, parent_id FROM ticket_categories WHERE parent_id IS NOT NULL ORDER BY name ASC");
        $stmt->execute();
        $subcategories = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    $error = "System error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Customer Portal - ITSPtickets</title>
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
        .user-info a, .user-info button { color: #3b82f6; text-decoration: none; background: none; border: none; cursor: pointer; }
        .user-info a:hover, .user-info button:hover { text-decoration: underline; }
        .login-form, .create-form, .message-form {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #374151;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .btn { 
            padding: 10px 20px; 
            border-radius: 6px; 
            text-decoration: none; 
            font-weight: 500; 
            border: none;
            cursor: pointer;
            margin-right: 10px;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn:hover { opacity: 0.9; }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #fca5a5;
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #86efac;
        }
        .tickets-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }
        .tickets-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .ticket-item {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ticket-item:hover {
            border-color: #3b82f6;
            background: #f8fafc;
        }
        .ticket-item.selected {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .ticket-header {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }
        .ticket-key {
            font-weight: 600;
            color: #1f2937;
        }
        .ticket-status {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-new { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #fef3c7; color: #92400e; }
        .status-waiting { background: #f3e8ff; color: #7c3aed; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        .status-closed { background: #f3f4f6; color: #374151; }
        .sla-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .sla-compliant { background: #d1fae5; color: #065f46; }
        .sla-breach { background: #fee2e2; color: #991b1b; }
        .sla-info {
            background: #f0f9ff;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            font-size: 13px;
        }
        .sla-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        .sla-metric {
            padding: 8px;
            background: white;
            border-radius: 4px;
            text-align: center;
        }
        .sla-metric .value {
            font-weight: 600;
            color: #1f2937;
        }
        .sla-metric .label {
            font-size: 11px;
            color: #6b7280;
        }
        .ticket-subject {
            font-weight: 500;
            color: #1f2937;
            margin-bottom: 5px;
        }
        .ticket-meta {
            font-size: 12px;
            color: #6b7280;
        }
        .ticket-details {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .messages-section {
            margin-top: 30px;
        }
        .message-item {
            border-bottom: 1px solid #e5e7eb;
            padding: 15px 0;
        }
        .message-item:last-child {
            border-bottom: none;
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
        .message-content {
            color: #374151;
            white-space: pre-wrap;
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
            
            .user-info button {
                padding: 8px 16px;
                border-radius: 6px;
                background: #f3f4f6;
                min-height: 40px;
            }
            
            .login-form,
            .create-form,
            .message-form {
                padding: 20px 15px;
                margin-bottom: 15px;
            }
            
            .login-form h2,
            .create-form h2,
            .message-form h4 {
                font-size: 20px;
                margin-bottom: 15px;
            }
            
            .login-form p {
                font-size: 15px;
                margin-bottom: 20px;
            }
            
            .form-group {
                margin-bottom: 16px;
            }
            
            .form-group label {
                font-size: 15px;
                margin-bottom: 6px;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
                border-radius: 8px;
            }
            
            .form-group textarea {
                min-height: 100px;
                resize: vertical;
            }
            
            .create-form > form > div[style*="grid"] {
                display: flex !important;
                flex-direction: column !important;
                gap: 16px !important;
            }
            
            .btn {
                padding: 12px 20px;
                font-size: 16px;
                min-height: 44px;
                margin-right: 0;
                margin-bottom: 10px;
                width: 100%;
                text-align: center;
            }
            
            .error,
            .success {
                padding: 12px 15px;
                margin-bottom: 16px;
                font-size: 14px;
            }
            
            .tickets-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .tickets-list,
            .ticket-details {
                padding: 15px;
                border-radius: 8px;
            }
            
            .tickets-list h3,
            .ticket-details h3 {
                font-size: 18px;
                margin-bottom: 15px;
            }
            
            .ticket-item {
                padding: 12px;
                margin-bottom: 8px;
                border-radius: 8px;
            }
            
            .ticket-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
                margin-bottom: 10px;
            }
            
            .ticket-key {
                font-size: 16px;
                font-weight: 700;
            }
            
            .ticket-status {
                font-size: 10px;
                padding: 3px 8px;
                border-radius: 10px;
            }
            
            .sla-indicator {
                font-size: 9px;
                padding: 2px 5px;
                border-radius: 8px;
            }
            
            .ticket-subject {
                font-size: 15px;
                font-weight: 600;
                margin-bottom: 8px;
                line-height: 1.3;
            }
            
            .ticket-meta {
                font-size: 12px;
                line-height: 1.4;
            }
            
            .sla-info {
                padding: 12px;
                margin: 12px 0;
                font-size: 13px;
            }
            
            .sla-metrics {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-top: 8px;
            }
            
            .sla-metric {
                padding: 10px;
                text-align: left;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .sla-metric .value {
                font-size: 16px;
                order: 2;
            }
            
            .sla-metric .label {
                font-size: 12px;
                order: 1;
            }
            
            .messages-section {
                margin-top: 20px;
            }
            
            .messages-section h4 {
                font-size: 16px;
                margin-bottom: 12px;
            }
            
            .message-item {
                padding: 12px 0;
            }
            
            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
                margin-bottom: 8px;
            }
            
            .message-header strong {
                font-size: 14px;
            }
            
            .message-date {
                font-size: 11px;
            }
            
            .message-content {
                font-size: 14px;
                line-height: 1.5;
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
            
            .login-form,
            .create-form,
            .message-form {
                padding: 15px 10px;
                margin-bottom: 12px;
                border-radius: 6px;
            }
            
            .login-form h2,
            .create-form h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }
            
            .form-group {
                margin-bottom: 14px;
            }
            
            .form-group label {
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 16px 14px; /* Larger for better touch targets */
                font-size: 16px;
                border-width: 2px;
                border-radius: 10px;
            }
            
            .form-group input:focus,
            .form-group select:focus,
            .form-group textarea:focus {
                box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            }
            
            .form-group textarea {
                min-height: 80px;
            }
            
            .btn {
                padding: 16px 20px;
                font-size: 17px;
                min-height: 52px;
                border-radius: 10px;
            }
            
            .tickets-list,
            .ticket-details {
                padding: 12px 10px;
            }
            
            .ticket-item {
                padding: 10px;
                border-left: 4px solid #e5e7eb;
            }
            
            .ticket-item.selected {
                border-left-color: #3b82f6;
            }
            
            .sla-info {
                padding: 10px;
                margin: 10px 0;
            }
            
            .sla-metrics {
                gap: 6px;
            }
            
            .sla-metric {
                padding: 8px;
            }
            
            .sla-metric .value {
                font-size: 14px;
            }
            
            .sla-metric .label {
                font-size: 11px;
            }
            
            .message-form h4 {
                font-size: 16px;
                margin-bottom: 10px;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .btn {
                min-height: 44px; /* Apple's recommended touch target size */
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                min-height: 44px;
            }
            
            .ticket-item {
                min-height: 44px;
                cursor: pointer;
            }
            
            .ticket-item:active {
                background-color: #f1f5f9;
                transform: scale(0.98);
                transition: transform 0.1s ease;
            }
            
            .btn:active {
                transform: scale(0.98);
                transition: transform 0.1s ease;
            }
            
            .user-info button:active {
                background-color: #e5e7eb;
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
            .tickets-grid {
                grid-template-columns: 1fr 1.5fr;
                gap: 15px;
            }
            
            .create-form > form > div[style*="grid"] {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px !important;
            }
            
            .sla-metrics {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .login-form,
            .create-form,
            .message-form,
            .tickets-list,
            .ticket-details {
                border: 2px solid #000;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                border-width: 2px;
            }
            
            .btn {
                border: 2px solid #fff;
            }
            
            .ticket-item {
                border-width: 2px;
            }
        }
        
        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            .ticket-item:active,
            .btn:active {
                transform: none;
            }
            
            .ticket-item {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Customer Portal</h1>
            <div class='user-info'>
                <?php if ($loggedIn): ?>
                    <span>Welcome, <?= htmlspecialchars($requester['name']) ?></span>
                    <form method='POST' style='display: inline;'>
                        <input type='hidden' name='action' value='logout'>
                        <button type='submit'>Logout</button>
                    </form>
                <?php else: ?>
                    <a href='/ITSPtickets/'>Staff Login</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class='error'><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class='success'><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (!$loggedIn): ?>
            <div class='login-form'>
                <h2>Access Your Tickets</h2>
                <p>Enter your email address to view your support tickets:</p>
                <form method='POST'>
                    <input type='hidden' name='action' value='login'>
                    <div class='form-group'>
                        <label for='email'>Email Address</label>
                        <input type='email' id='email' name='email' required>
                    </div>
                    <button type='submit' class='btn btn-primary'>Access Portal</button>
                </form>
            </div>
        <?php else: ?>
            
            <div class='create-form'>
                <h2>Create New Ticket</h2>
                <form method='POST'>
                    <input type='hidden' name='action' value='create_ticket'>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px;'>
                        <div class='form-group'>
                            <label for='subject'>Subject</label>
                            <input type='text' id='subject' name='subject' required>
                        </div>
                        <div class='form-group'>
                            <label for='type'>Type</label>
                            <select id='type' name='type' required>
                                <option value=''>Select Type</option>
                                <option value='incident'>Incident</option>
                                <option value='request'>Request</option>
                                <option value='job'>Job</option>
                            </select>
                        </div>
                    </div>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px;'>
                        <div class='form-group'>
                            <label for='priority'>Priority</label>
                            <select id='priority' name='priority' required>
                                <option value=''>Select Priority</option>
                                <option value='low'>Low</option>
                                <option value='normal' selected>Normal</option>
                                <option value='high'>High</option>
                                <option value='urgent'>Urgent</option>
                            </select>
                        </div>
                    </div>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px;'>
                        <div class='form-group'>
                            <label for='category_id'>Category</label>
                            <select id='category_id' name='category_id' required>
                                <option value=''>Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value='<?= $category['id'] ?>'><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class='form-group'>
                            <label for='subcategory_id'>Subcategory</label>
                            <select id='subcategory_id' name='subcategory_id'>
                                <option value=''>Select Subcategory</option>
                            </select>
                        </div>
                    </div>
                    <div class='form-group'>
                        <label for='description'>Description</label>
                        <textarea id='description' name='description' rows='4' required></textarea>
                    </div>
                    <button type='submit' class='btn btn-primary'>Create Ticket</button>
                </form>
            </div>
            
            <div class='tickets-grid'>
                <div class='tickets-list'>
                    <h3>Your Tickets</h3>
                    <?php if (empty($tickets)): ?>
                        <p>No tickets found.</p>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <div class='ticket-item <?= ($selectedTicket && $selectedTicket['id'] == $ticket['id']) ? 'selected' : '' ?>' 
                                 onclick="window.location.href='?ticket_id=<?= $ticket['id'] ?>'">
                                <div class='ticket-header'>
                                    <span class='ticket-key'><?= htmlspecialchars($ticket['key']) ?></span>
                                    <span class='ticket-status status-<?= str_replace('_', '-', strtolower($ticket['status'])) ?>'>
                                        <?= htmlspecialchars($ticket['status']) ?>
                                    </span>
                                    <?php if (isset($ticket['sla_compliance'])): ?>
                                        <?php if (!$ticket['sla_compliance']['response_compliant'] || !$ticket['sla_compliance']['resolution_compliant']): ?>
                                            <span class='sla-indicator sla-breach'>SLA Breach</span>
                                        <?php else: ?>
                                            <span class='sla-indicator sla-compliant'>SLA OK</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class='ticket-subject'><?= htmlspecialchars($ticket['subject']) ?></div>
                                <div class='ticket-meta'>
                                    Created: <?= date('M j, Y', strtotime($ticket['created_at'])) ?>
                                    <?php if ($ticket['category_name']): ?>
                                        | Category: <?= htmlspecialchars($ticket['category_name']) ?>
                                        <?php if ($ticket['subcategory_name']): ?>
                                            > <?= htmlspecialchars($ticket['subcategory_name']) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($ticket['assignee_name']): ?>
                                        | Assigned: <?= htmlspecialchars($ticket['assignee_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class='ticket-details'>
                    <?php if ($selectedTicket): ?>
                        <h3>Ticket <?= htmlspecialchars($selectedTicket['key']) ?></h3>
                        <div style='margin-bottom: 20px;'>
                            <strong>Subject:</strong> <?= htmlspecialchars($selectedTicket['subject']) ?><br>
                            <strong>Status:</strong> <?= htmlspecialchars($selectedTicket['status']) ?><br>
                            <strong>Priority:</strong> <?= htmlspecialchars($selectedTicket['priority']) ?><br>
                            <strong>Type:</strong> <?= htmlspecialchars($selectedTicket['type']) ?><br>
                            <?php if ($selectedTicket['category_name']): ?>
                                <strong>Category:</strong> <?= htmlspecialchars($selectedTicket['category_name']) ?>
                                <?php if ($selectedTicket['subcategory_name']): ?>
                                    > <?= htmlspecialchars($selectedTicket['subcategory_name']) ?>
                                <?php endif; ?><br>
                            <?php endif; ?>
                            <?php if ($selectedTicket['assignee_name']): ?>
                                <strong>Assigned to:</strong> <?= htmlspecialchars($selectedTicket['assignee_name']) ?><br>
                            <?php endif; ?>
                            <strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($selectedTicket['created_at'])) ?>
                        </div>
                        
                        <div style='background: #f9fafb; padding: 15px; border-radius: 6px; margin-bottom: 20px;'>
                            <?= nl2br(htmlspecialchars($selectedTicket['description'])) ?>
                        </div>
                        
                        <?php if (isset($selectedTicket['sla_compliance'])): ?>
                            <div class='sla-info'>
                                <strong>Service Level Agreement</strong>
                                <?php if (!$selectedTicket['sla_compliance']['response_compliant'] || !$selectedTicket['sla_compliance']['resolution_compliant']): ?>
                                    <span class='sla-indicator sla-breach'>Breach Detected</span>
                                <?php else: ?>
                                    <span class='sla-indicator sla-compliant'>On Track</span>
                                <?php endif; ?>
                                
                                <div class='sla-metrics'>
                                    <div class='sla-metric'>
                                        <div class='value' style='color: <?= $selectedTicket['sla_compliance']['response_compliant'] ? '#059669' : '#dc2626' ?>'>
                                            <?= isset($selectedTicket['sla_compliance']['response_hours']) ? round($selectedTicket['sla_compliance']['response_hours'], 1) . 'h' : 'Pending' ?>
                                        </div>
                                        <div class='label'>Response Time</div>
                                        <div style='font-size: 10px; color: #9ca3af;'>
                                            Target: <?= round($selectedTicket['sla_compliance']['response_target'] / 60, 1) ?>h
                                        </div>
                                    </div>
                                    
                                    <div class='sla-metric'>
                                        <div class='value' style='color: <?= $selectedTicket['sla_compliance']['resolution_compliant'] ? '#059669' : '#dc2626' ?>'>
                                            <?= isset($selectedTicket['sla_compliance']['resolution_hours']) ? round($selectedTicket['sla_compliance']['resolution_hours'], 1) . 'h' : 'In Progress' ?>
                                        </div>
                                        <div class='label'>Resolution Time</div>
                                        <div style='font-size: 10px; color: #9ca3af;'>
                                            Target: <?= round($selectedTicket['sla_compliance']['resolution_target'] / 60, 1) ?>h
                                        </div>
                                    </div>
                                </div>
                                
                                <div style='margin-top: 10px; font-size: 12px; color: #6b7280;'>
                                    SLA Policy: <?= htmlspecialchars($selectedTicket['sla_name']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class='messages-section'>
                            <h4>Messages</h4>
                            <?php if (empty($messages)): ?>
                                <p>No messages yet.</p>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <div class='message-item'>
                                        <div class='message-header'>
                                            <strong><?= htmlspecialchars($message['sender_name']) ?></strong>
                                            <span class='message-date'><?= date('M j, Y g:i A', strtotime($message['created_at'])) ?></span>
                                        </div>
                                        <div class='message-content'><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class='message-form'>
                            <h4>Add Message</h4>
                            <form method='POST'>
                                <input type='hidden' name='action' value='add_message'>
                                <input type='hidden' name='ticket_id' value='<?= $selectedTicket['id'] ?>'>
                                <div class='form-group'>
                                    <label for='message'>Your Message</label>
                                    <textarea id='message' name='message' rows='3' required></textarea>
                                </div>
                                <button type='submit' class='btn btn-primary'>Add Message</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <p>Select a ticket to view details.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($loggedIn): ?>
    <script>
        // Subcategories data from PHP
        const subcategories = <?= json_encode($subcategories) ?>;
        
        // Update subcategories when category changes
        document.getElementById('category_id').addEventListener('change', function() {
            const categoryId = this.value;
            const subcategorySelect = document.getElementById('subcategory_id');
            
            // Clear existing options
            subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
            
            if (categoryId) {
                // Filter subcategories for selected category
                const filteredSubcategories = subcategories.filter(sub => sub.parent_id == categoryId);
                
                // Add filtered options
                filteredSubcategories.forEach(sub => {
                    const option = document.createElement('option');
                    option.value = sub.id;
                    option.textContent = sub.name;
                    subcategorySelect.appendChild(option);
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>