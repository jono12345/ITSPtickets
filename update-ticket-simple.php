<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

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
    
    // Get current user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        header('Location: /ITSPtickets/login.php');
        exit;
    }
    
    // Get ticket ID
    $ticketId = $_GET['id'] ?? $_POST['ticket_id'] ?? null;
    if (!$ticketId) {
        $_SESSION['error'] = 'Ticket ID required';
        header('Location: /ITSPtickets/tickets-simple.php');
        exit;
    }
    
    // Get ticket details with permissions check
    $sql = "SELECT t.*, 
                   r.name as requester_name, r.email as requester_email,
                   u.name as assignee_name
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            WHERE t.id = ?";
    
    // Check permissions for agents
    if ($user['role'] === 'agent') {
        $sql .= " AND t.assignee_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ticketId, $user['id']]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ticketId]);
    }
    
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        $_SESSION['error'] = 'Ticket not found or access denied';
        header('Location: /ITSPtickets/tickets-simple.php');
        exit;
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $errors = [];
        $success = '';
        
        try {
            $pdo->beginTransaction();
            
            switch ($_POST['action']) {
                case 'update_status':
                    $newStatus = $_POST['status'] ?? '';
                    $resolution = $_POST['resolution'] ?? '';
                    
                    if (empty($newStatus)) {
                        $errors[] = 'Status is required';
                        break;
                    }
                    
                    // Validate status transition
                    $validStatuses = ['new', 'triaged', 'in_progress', 'waiting', 'on_hold', 'resolved', 'closed'];
                    if (!in_array($newStatus, $validStatuses)) {
                        $errors[] = 'Invalid status';
                        break;
                    }
                    
                    $updateData = ['status' => $newStatus, 'last_update_at' => date('Y-m-d H:i:s')];
                    
                    // Handle specific status transitions
                    if ($newStatus === 'resolved') {
                        $updateData['resolved_at'] = date('Y-m-d H:i:s');
                        if (empty($resolution)) {
                            $errors[] = 'Resolution description is required when resolving ticket';
                            break;
                        }
                    } elseif ($newStatus === 'closed') {
                        $updateData['closed_at'] = date('Y-m-d H:i:s');
                        if (!$ticket['resolved_at']) {
                            $updateData['resolved_at'] = date('Y-m-d H:i:s');
                        }
                    }
                    
                    if (empty($errors)) {
                        // Update ticket
                        $setParts = [];
                        $params = [];
                        foreach ($updateData as $field => $value) {
                            $setParts[] = "{$field} = ?";
                            $params[] = $value;
                        }
                        $params[] = $ticketId;
                        
                        $stmt = $pdo->prepare("UPDATE tickets SET " . implode(', ', $setParts) . " WHERE id = ?");
                        $stmt->execute($params);
                        
                        // Add resolution message if provided
                        if (!empty($resolution) && $newStatus === 'resolved') {
                            $stmt = $pdo->prepare("INSERT INTO ticket_messages (
                                ticket_id, sender_type, sender_id, message, is_private, channel, created_at
                            ) VALUES (?, 'user', ?, ?, 0, 'web', NOW())");
                            $stmt->execute([$ticketId, $user['id'], "Ticket resolved: " . $resolution]);
                        }
                        
                        // Log the event
                        $stmt = $pdo->prepare("INSERT INTO ticket_events (
                            ticket_id, user_id, event_type, description, old_value, new_value, created_at
                        ) VALUES (?, ?, 'status_changed', ?, ?, ?, NOW())");
                        $stmt->execute([
                            $ticketId,
                            $user['id'],
                            "Status changed from {$ticket['status']} to {$newStatus}",
                            $ticket['status'],
                            $newStatus
                        ]);
                        
                        $success = "Ticket status updated to {$newStatus}";
                        
                        // Trigger notifications based on status
                        if ($newStatus === 'resolved') {
                            $notificationService->sendNotification('ticket_resolved', $ticketId, [
                                'resolution' => $resolution ?? 'Ticket marked as resolved'
                            ]);
                        } elseif ($newStatus === 'closed') {
                            $notificationService->sendNotification('ticket_closed', $ticketId);
                        } else {
                            $notificationService->sendNotification('ticket_updated', $ticketId, [
                                'new_status' => $newStatus,
                                'updated_by' => $user['name']
                            ]);
                        }
                    }
                    break;
                    
                case 'update_assignment':
                    $newAssigneeId = !empty($_POST['assignee_id']) ? $_POST['assignee_id'] : null;
                    
                    if ($newAssigneeId && $newAssigneeId != $ticket['assignee_id']) {
                        // Verify assignee exists and is active
                        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND active = 1 AND role IN ('agent', 'supervisor', 'admin')");
                        $stmt->execute([$newAssigneeId]);
                        $assignee = $stmt->fetch();
                        
                        if (!$assignee) {
                            $errors[] = 'Invalid assignee selected';
                            break;
                        }
                    }
                    
                    if (empty($errors)) {
                        // Update assignment
                        $stmt = $pdo->prepare("UPDATE tickets SET assignee_id = ?, last_update_at = NOW() WHERE id = ?");
                        $stmt->execute([$newAssigneeId, $ticketId]);
                        
                        // Log the event
                        $assigneeName = $newAssigneeId ? $assignee['name'] : 'Unassigned';
                        $stmt = $pdo->prepare("INSERT INTO ticket_events (
                            ticket_id, user_id, event_type, description, old_value, new_value, created_at
                        ) VALUES (?, ?, 'assignment_changed', ?, ?, ?, NOW())");
                        $stmt->execute([
                            $ticketId,
                            $user['id'],
                            "Ticket assigned to {$assigneeName}",
                            $ticket['assignee_id'],
                            $newAssigneeId
                        ]);
                        
                        $success = "Ticket assignment updated";
                        
                        // Trigger notification
                        $notificationService->sendNotification('ticket_assigned', $ticketId, [
                            'assignee_name' => $assigneeName
                        ]);
                    }
                    break;
                    
                case 'update_priority':
                    $newPriority = $_POST['priority'] ?? '';
                    
                    $validPriorities = ['low', 'normal', 'high', 'urgent'];
                    if (!in_array($newPriority, $validPriorities)) {
                        $errors[] = 'Invalid priority';
                        break;
                    }
                    
                    if ($newPriority !== $ticket['priority']) {
                        // Update priority
                        $stmt = $pdo->prepare("UPDATE tickets SET priority = ?, last_update_at = NOW() WHERE id = ?");
                        $stmt->execute([$newPriority, $ticketId]);
                        
                        // Log the event
                        $stmt = $pdo->prepare("INSERT INTO ticket_events (
                            ticket_id, user_id, event_type, description, old_value, new_value, created_at
                        ) VALUES (?, ?, 'priority_changed', ?, ?, ?, NOW())");
                        $stmt->execute([
                            $ticketId,
                            $user['id'],
                            "Priority changed from {$ticket['priority']} to {$newPriority}",
                            $ticket['priority'],
                            $newPriority
                        ]);
                        
                        $success = "Ticket priority updated to {$newPriority}";
                        
                        // Update SLA policy based on new priority
                        $slaService->assignSlaPolicy($ticketId, $ticket['type'], $newPriority);
                        
                        // Send notification for priority change
                        $notificationService->sendNotification('ticket_updated', $ticketId, [
                            'change_type' => 'priority',
                            'old_priority' => $ticket['priority'],
                            'new_priority' => $newPriority,
                            'updated_by' => $user['name']
                        ]);
                    }
                    break;
                    
                case 'add_message':
                    $message = trim($_POST['message'] ?? '');
                    $isPrivate = isset($_POST['is_private']) ? 1 : 0;
                    
                    if (empty($message)) {
                        $errors[] = 'Message content is required';
                        break;
                    }
                    
                    // Add message
                    $stmt = $pdo->prepare("INSERT INTO ticket_messages (
                        ticket_id, sender_type, sender_id, message, is_private, channel, created_at
                    ) VALUES (?, 'user', ?, ?, ?, 'web', NOW())");
                    $stmt->execute([$ticketId, $user['id'], $message, $isPrivate]);
                    
                    // Update ticket timestamp and first response if needed
                    $stmt = $pdo->prepare("UPDATE tickets SET last_update_at = NOW() WHERE id = ?");
                    $stmt->execute([$ticketId]);
                    
                    if (!$ticket['first_response_at'] && !$isPrivate) {
                        $stmt = $pdo->prepare("UPDATE tickets SET first_response_at = NOW() WHERE id = ?");
                        $stmt->execute([$ticketId]);
                    }
                    
                    $success = "Message added successfully";
                    
                    // Trigger notification for public messages only
                    if (!$isPrivate) {
                        $notificationService->sendNotification('message_added', $ticketId, [
                            'message' => $message,
                            'sender_name' => $user['name']
                        ]);
                    }
                    break;
                    
                case 'update_time':
                    $timeSpent = floatval($_POST['time_spent'] ?? 0);
                    $billableHours = floatval($_POST['billable_hours'] ?? 0);
                    
                    if ($timeSpent < 0 || $billableHours < 0) {
                        $errors[] = 'Time values cannot be negative';
                        break;
                    }
                    
                    if ($billableHours > $timeSpent && $timeSpent > 0) {
                        $errors[] = 'Billable hours cannot exceed time spent';
                        break;
                    }
                    
                    if (empty($errors)) {
                        // Update time fields
                        $stmt = $pdo->prepare("UPDATE tickets SET time_spent = ?, billable_hours = ?, last_update_at = NOW() WHERE id = ?");
                        $stmt->execute([$timeSpent, $billableHours, $ticketId]);
                        
                        // Log the event
                        $description = "Time updated: {$timeSpent}h spent, {$billableHours}h billable";
                        $stmt = $pdo->prepare("INSERT INTO ticket_events (
                            ticket_id, user_id, event_type, description, old_value, new_value, created_at
                        ) VALUES (?, ?, 'time_updated', ?, ?, ?, NOW())");
                        $stmt->execute([
                            $ticketId,
                            $user['id'],
                            $description,
                            ($ticket['time_spent'] ?? 0) . '|' . ($ticket['billable_hours'] ?? 0),
                            $timeSpent . '|' . $billableHours
                        ]);
                        
                        $success = "Time tracking updated: {$timeSpent}h spent, {$billableHours}h billable";
                        
                        // Trigger notification
                        $notificationService->sendNotification('ticket_updated', $ticketId, [
                            'change_type' => 'time_tracking',
                            'time_spent' => $timeSpent,
                            'billable_hours' => $billableHours,
                            'updated_by' => $user['name']
                        ]);
                    }
                    break;
                    
                default:
                    $errors[] = 'Invalid action';
            }
            
            if (empty($errors)) {
                $pdo->commit();
                $_SESSION['success'] = $success;
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = implode(', ', $errors);
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
        
        // Redirect back to ticket details
        header("Location: /ITSPtickets/ticket-simple.php?id={$ticketId}");
        exit;
    }
    
    // Get available users for assignment
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE role IN ('agent', 'supervisor', 'admin') AND active = 1 ORDER BY name ASC");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
} catch (Exception $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: /ITSPtickets/tickets-simple.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Update Ticket <?= htmlspecialchars($ticket['key']) ?> - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc; 
            line-height: 1.6;
        }
        .container { 
            max-width: 800px; 
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
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .update-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        .section-header {
            padding: 15px 20px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
        }
        .section-content {
            padding: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row.single {
            grid-template-columns: 1fr;
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
        .form-group select,
        .form-group textarea {
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
        .btn-success { background: #059669; color: white; }
        .btn-warning { background: #d97706; color: white; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn:hover { opacity: 0.9; }
        .ticket-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
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
        .status-closed { background: #f3f4f6; color: #374151; }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Update Ticket <?= htmlspecialchars($ticket['key']) ?></h1>
            <div class='user-info'>
                <a href='/ITSPtickets/ticket-simple.php?id=<?= $ticket['id'] ?>'>Back to Ticket</a>
                <a href='/ITSPtickets/tickets-simple.php'>All Tickets</a>
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
            </div>
            <p><strong>Requester:</strong> <?= htmlspecialchars($ticket['requester_name']) ?> (<?= htmlspecialchars($ticket['requester_email']) ?>)</p>
            <p><strong>Current Assignee:</strong> <?= htmlspecialchars($ticket['assignee_name'] ?: 'Unassigned') ?></p>
            <p><strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($ticket['created_at'])) ?></p>
        </div>
        
        <!-- Status Update -->
        <div class='update-section'>
            <div class='section-header'>Update Status</div>
            <div class='section-content'>
                <form method='POST'>
                    <input type='hidden' name='action' value='update_status'>
                    <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                    
                    <div class='form-row'>
                        <div class='form-group'>
                            <label for='status'>New Status</label>
                            <select id='status' name='status' required>
                                <option value='new' <?= $ticket['status'] === 'new' ? 'selected' : '' ?>>New</option>
                                <option value='triaged' <?= $ticket['status'] === 'triaged' ? 'selected' : '' ?>>Triaged</option>
                                <option value='in_progress' <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value='waiting' <?= $ticket['status'] === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                                <option value='on_hold' <?= $ticket['status'] === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
                                <option value='resolved' <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                <option value='closed' <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class='form-row single' id='resolution-group' style='display: none;'>
                        <div class='form-group'>
                            <label for='resolution'>Resolution Description</label>
                            <textarea id='resolution' name='resolution' rows='3' placeholder='Describe how the issue was resolved...'></textarea>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>Update Status</button>
                </form>
            </div>
        </div>
        
        <!-- Assignment Update -->
        <div class='update-section'>
            <div class='section-header'>Update Assignment</div>
            <div class='section-content'>
                <form method='POST'>
                    <input type='hidden' name='action' value='update_assignment'>
                    <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                    
                    <div class='form-row'>
                        <div class='form-group'>
                            <label for='assignee_id'>Assign To</label>
                            <select id='assignee_id' name='assignee_id'>
                                <option value=''>Unassigned</option>
                                <?php foreach ($users as $assignee): ?>
                                    <option value='<?= $assignee['id'] ?>' <?= $ticket['assignee_id'] == $assignee['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($assignee['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>Update Assignment</button>
                </form>
            </div>
        </div>
        
        <!-- Priority Update -->
        <div class='update-section'>
            <div class='section-header'>Update Priority</div>
            <div class='section-content'>
                <form method='POST'>
                    <input type='hidden' name='action' value='update_priority'>
                    <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                    
                    <div class='form-row'>
                        <div class='form-group'>
                            <label for='priority'>Priority</label>
                            <select id='priority' name='priority' required>
                                <option value='low' <?= $ticket['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                                <option value='normal' <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                                <option value='high' <?= $ticket['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                <option value='urgent' <?= $ticket['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>Update Priority</button>
                </form>
            </div>
        </div>
        
        <!-- Add Message -->
        <div class='update-section'>
            <div class='section-header'>Add Message</div>
            <div class='section-content'>
                <form method='POST'>
                    <input type='hidden' name='action' value='add_message'>
                    <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                    
                    <div class='form-row single'>
                        <div class='form-group'>
                            <label for='message'>Message</label>
                            <textarea id='message' name='message' rows='4' required placeholder='Add your message here...'></textarea>
                        </div>
                    </div>
                    
                    <div class='form-row single'>
                        <div class='checkbox-group'>
                            <input type='checkbox' id='is_private' name='is_private'>
                            <label for='is_private'>Private note (not visible to requester)</label>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>Add Message</button>
                </form>
            </div>
        </div>
        
        <!-- Time Tracking -->
        <div class='update-section'>
            <div class='section-header'>Time Tracking</div>
            <div class='section-content'>
                <form method='POST'>
                    <input type='hidden' name='action' value='update_time'>
                    <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                    
                    <div class='form-row'>
                        <div class='form-group'>
                            <label for='time_spent'>Time Spent (hours)</label>
                            <input type='number' id='time_spent' name='time_spent' step='0.25' min='0' 
                                   value='<?= htmlspecialchars($ticket['time_spent'] ?? '') ?>' 
                                   placeholder='e.g. 2.5'>
                            <small style='color: #6b7280; font-size: 12px; margin-top: 5px; display: block;'>
                                Total time spent working on this ticket
                            </small>
                        </div>
                        <div class='form-group'>
                            <label for='billable_hours'>Billable Hours</label>
                            <input type='number' id='billable_hours' name='billable_hours' step='0.25' min='0'
                                   value='<?= htmlspecialchars($ticket['billable_hours'] ?? '') ?>' 
                                   placeholder='e.g. 2.0'>
                            <small style='color: #6b7280; font-size: 12px; margin-top: 5px; display: block;'>
                                Hours billable to customer (≤ time spent)
                            </small>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>Update Time Tracking</button>
                </form>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class='update-section'>
            <div class='section-header'>Quick Actions</div>
            <div class='section-content'>
                <?php if ($ticket['status'] !== 'resolved' && $ticket['status'] !== 'closed'): ?>
                    <form method='POST' style='display: inline;'>
                        <input type='hidden' name='action' value='update_status'>
                        <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                        <input type='hidden' name='status' value='resolved'>
                        <button type='submit' class='btn btn-success' onclick='return confirm("Mark this ticket as resolved?")'>Mark Resolved</button>
                    </form>
                    
                    <form method='POST' style='display: inline;'>
                        <input type='hidden' name='action' value='update_status'>
                        <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                        <input type='hidden' name='status' value='closed'>
                        <button type='submit' class='btn btn-danger' onclick='return confirm("Close this ticket?")'>Close Ticket</button>
                    </form>
                <?php endif; ?>
                
                <?php if ($ticket['status'] === 'closed'): ?>
                    <form method='POST' style='display: inline;'>
                        <input type='hidden' name='action' value='update_status'>
                        <input type='hidden' name='ticket_id' value='<?= $ticket['id'] ?>'>
                        <input type='hidden' name='status' value='in_progress'>
                        <button type='submit' class='btn btn-warning' onclick='return confirm("Reopen this ticket?")'>Reopen Ticket</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Show/hide resolution field based on status
        document.getElementById('status').addEventListener('change', function() {
            const resolutionGroup = document.getElementById('resolution-group');
            const resolutionField = document.getElementById('resolution');
            
            if (this.value === 'resolved') {
                resolutionGroup.style.display = 'block';
                resolutionField.required = true;
            } else {
                resolutionGroup.style.display = 'none';
                resolutionField.required = false;
            }
        });
        
        // Trigger change event on page load
        document.getElementById('status').dispatchEvent(new Event('change'));
    </script>
</body>
</html>