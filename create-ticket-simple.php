<?php
/*
|--------------------------------------------------------------------------
| Create Ticket - Simple Model
|--------------------------------------------------------------------------
| Ticket creation interface for staff members
*/

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
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $errors = [];
        
        // Validate required fields
        $required = ['subject', 'description', 'type', 'priority', 'requester_email', 'requester_name', 'category_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }
        
        if (empty($errors)) {
            try {
                // Find or create requester
                $stmt = $pdo->prepare("SELECT * FROM requesters WHERE email = ? AND active = 1 LIMIT 1");
                $stmt->execute([$_POST['requester_email']]);
                $requester = $stmt->fetch();
                
                if (!$requester) {
                    // Create new requester
                    $stmt = $pdo->prepare("INSERT INTO requesters (name, email, active) VALUES (?, ?, 1)");
                    $stmt->execute([$_POST['requester_name'], $_POST['requester_email']]);
                    $requesterId = $pdo->lastInsertId();
                } else {
                    $requesterId = $requester['id'];
                }
                
                // Generate ticket key
                $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(`key`, 5) AS UNSIGNED)) as max_num FROM tickets WHERE `key` LIKE 'TKT-%'");
                $stmt->execute();
                $result = $stmt->fetch();
                $nextNum = ($result['max_num'] ?? 0) + 1;
                $ticketKey = 'TKT-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
                
                // Create ticket
                $stmt = $pdo->prepare("INSERT INTO tickets (
                    `key`, type, subject, description, priority, status,
                    requester_id, assignee_id, channel, category_id, subcategory_id, created_at
                ) VALUES (?, ?, ?, ?, ?, 'new', ?, ?, 'web', ?, ?, NOW())");
                
                $assigneeId = !empty($_POST['assignee_id']) ? $_POST['assignee_id'] : null;
                $subcategoryId = !empty($_POST['subcategory_id']) ? $_POST['subcategory_id'] : null;
                
                $stmt->execute([
                    $ticketKey,
                    $_POST['type'],
                    $_POST['subject'],
                    $_POST['description'],
                    $_POST['priority'],
                    $requesterId,
                    $assigneeId,
                    $_POST['category_id'],
                    $subcategoryId
                ]);
                
                $ticketId = $pdo->lastInsertId();
                
                // Assign SLA policy based on type and priority
                $slaService->assignSlaPolicy($ticketId, $_POST['type'], $_POST['priority']);
                
                // Send notifications
                $notificationService->sendNotification('ticket_created', $ticketId, [
                    'created_by' => $user['name']
                ]);
                
                // If assigned, also send assignment notification
                if ($assigneeId) {
                    $notificationService->sendNotification('ticket_assigned', $ticketId);
                }
                
                $_SESSION['success'] = "Ticket {$ticketKey} created successfully";
                header("Location: /ITSPtickets/ticket-simple.php?id={$ticketId}");
                exit;
                
            } catch (Exception $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
    
    // Get users for assignment
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE role IN ('agent', 'supervisor', 'admin') AND active = 1 ORDER BY name ASC");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Get categories
    $stmt = $pdo->prepare("SELECT id, name FROM ticket_categories WHERE parent_id IS NULL ORDER BY name ASC");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    // Get all subcategories for JavaScript
    $stmt = $pdo->prepare("SELECT id, name, parent_id FROM ticket_categories WHERE parent_id IS NOT NULL ORDER BY name ASC");
    $stmt->execute();
    $subcategories = $stmt->fetchAll();
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Create New Ticket - ITSPtickets</title>
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
        .ticket-form {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        .btn { 
            padding: 10px 20px; 
            border-radius: 6px; 
            text-decoration: none; 
            font-weight: 500; 
            border: none;
            cursor: pointer;
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
        .error ul {
            margin: 0;
            padding-left: 20px;
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
            
            .ticket-form {
                padding: 20px 15px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
                margin-bottom: 18px;
            }
            
            .form-row.single {
                margin-bottom: 18px;
            }
            
            .form-group {
                margin-bottom: 0;
            }
            
            .form-group label {
                margin-bottom: 6px;
                font-size: 15px;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
                border-radius: 8px;
            }
            
            .form-group textarea {
                min-height: 120px;
                resize: vertical;
            }
            
            .form-actions {
                flex-direction: column;
                gap: 12px;
                margin-top: 25px;
            }
            
            .btn {
                width: 100%;
                padding: 14px 20px;
                font-size: 16px;
                min-height: 50px;
                text-align: center;
                display: block;
            }
            
            .error {
                padding: 12px 15px;
                margin-bottom: 18px;
                font-size: 14px;
            }
            
            .error ul {
                padding-left: 16px;
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
                line-height: 1.3;
            }
            
            .user-info {
                font-size: 13px;
            }
            
            .ticket-form {
                padding: 15px 10px;
                border-radius: 6px;
            }
            
            .form-row {
                gap: 12px;
                margin-bottom: 15px;
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
                min-height: 100px;
            }
            
            .form-actions {
                margin-top: 20px;
                gap: 10px;
            }
            
            .btn {
                padding: 16px 20px;
                font-size: 17px;
                min-height: 52px;
                border-radius: 10px;
            }
            
            .error {
                padding: 10px 12px;
                font-size: 13px;
                margin-bottom: 15px;
                border-radius: 8px;
            }
            
            .error ul {
                padding-left: 14px;
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
            
            .btn:active {
                transform: scale(0.98);
                transition: transform 0.1s ease;
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
            .form-row:not(.single) {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .ticket-form {
                max-height: 85vh;
                overflow-y: auto;
            }
        }
        
        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .ticket-form {
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
        }
        
        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            .btn:active {
                transform: none;
            }
            
            .form-group input:focus,
            .form-group select:focus,
            .form-group textarea:focus {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Create New Ticket</h1>
            <div class='user-info'>
                <a href='/ITSPtickets/tickets-simple.php'>Back to Tickets</a>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
            </div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class='error'>
                <strong>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method='POST' class='ticket-form'>
            <div class='form-row single'>
                <div class='form-group'>
                    <label for='subject'>Subject *</label>
                    <input type='text' id='subject' name='subject' value='<?= htmlspecialchars($_POST['subject'] ?? '') ?>' required>
                </div>
            </div>
            
            <div class='form-row single'>
                <div class='form-group'>
                    <label for='description'>Description *</label>
                    <textarea id='description' name='description' rows='5' required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
            </div>
            
            <div class='form-row'>
                <div class='form-group'>
                    <label for='type'>Type *</label>
                    <select id='type' name='type' required>
                        <option value=''>Select Type</option>
                        <option value='incident' <?= ($_POST['type'] ?? '') === 'incident' ? 'selected' : '' ?>>Incident</option>
                        <option value='request' <?= ($_POST['type'] ?? '') === 'request' ? 'selected' : '' ?>>Request</option>
                        <option value='job' <?= ($_POST['type'] ?? '') === 'job' ? 'selected' : '' ?>>Job</option>
                    </select>
                </div>
                
                <div class='form-group'>
                    <label for='priority'>Priority *</label>
                    <select id='priority' name='priority' required>
                        <option value=''>Select Priority</option>
                        <option value='low' <?= ($_POST['priority'] ?? '') === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value='normal' <?= ($_POST['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value='high' <?= ($_POST['priority'] ?? '') === 'high' ? 'selected' : '' ?>>High</option>
                        <option value='urgent' <?= ($_POST['priority'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
            </div>
            
            <div class='form-row'>
                <div class='form-group'>
                    <label for='category_id'>Category *</label>
                    <select id='category_id' name='category_id' required>
                        <option value=''>Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value='<?= $category['id'] ?>' <?= ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
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
            
            <div class='form-row'>
                <div class='form-group'>
                    <label for='requester_name'>Requester Name *</label>
                    <input type='text' id='requester_name' name='requester_name' value='<?= htmlspecialchars($_POST['requester_name'] ?? '') ?>' required>
                </div>
                
                <div class='form-group'>
                    <label for='requester_email'>Requester Email *</label>
                    <input type='email' id='requester_email' name='requester_email' value='<?= htmlspecialchars($_POST['requester_email'] ?? '') ?>' required>
                </div>
            </div>
            
            <div class='form-row'>
                <div class='form-group'>
                    <label for='assignee_id'>Assign To</label>
                    <select id='assignee_id' name='assignee_id'>
                        <option value=''>Unassigned</option>
                        <?php foreach ($users as $assignee): ?>
                            <option value='<?= $assignee['id'] ?>' <?= ($_POST['assignee_id'] ?? '') == $assignee['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($assignee['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class='form-actions'>
                <button type='submit' class='btn btn-primary'>Create Ticket</button>
                <a href='/ITSPtickets/tickets-simple.php' class='btn btn-secondary'>Cancel</a>
            </div>
        </form>
    </div>

    <script>
        // Subcategories data from PHP
        const subcategories = <?= json_encode($subcategories) ?>;
        const selectedSubcategory = '<?= $_POST['subcategory_id'] ?? '' ?>';
        
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
                    if (sub.id == selectedSubcategory) {
                        option.selected = true;
                    }
                    subcategorySelect.appendChild(option);
                });
            }
        });
        
        // Initialize subcategories on page load if category is selected
        document.addEventListener('DOMContentLoaded', function() {
            const categorySelect = document.getElementById('category_id');
            if (categorySelect.value) {
                categorySelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>