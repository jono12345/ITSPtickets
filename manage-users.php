<?php
session_start();

// Check if user is logged in and is admin
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
    
    if (!$user || $user['role'] !== 'admin') {
        die("Access denied. Admin permissions required.");
    }
    
    $error = '';
    $success = '';
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'add_user':
                    $name = trim($_POST['name']);
                    $email = trim($_POST['email']);
                    $role = $_POST['role'];
                    $phone = trim($_POST['phone']) ?: null;
                    $password = $_POST['password'];
                    
                    if (empty($name) || empty($email) || empty($role) || empty($password)) {
                        throw new Exception("Name, email, role, and password are required");
                    }
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email format");
                    }
                    
                    if (strlen($password) < 6) {
                        throw new Exception("Password must be at least 6 characters long");
                    }
                    
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        throw new Exception("Email already exists");
                    }
                    
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone, active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
                    $stmt->execute([$name, $email, $hashedPassword, $role, $phone]);
                    $success = "User '{$name}' added successfully";
                    break;
                    
                case 'edit_user':
                    $id = intval($_POST['user_id']);
                    $name = trim($_POST['name']);
                    $email = trim($_POST['email']);
                    $role = $_POST['role'];
                    $phone = trim($_POST['phone']) ?: null;
                    $active = intval($_POST['active']);
                    
                    if (empty($name) || empty($email) || empty($role) || !$id) {
                        throw new Exception("Name, email, role, and user ID are required");
                    }
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email format");
                    }
                    
                    // Check if email already exists for another user
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Email already exists for another user");
                    }
                    
                    // Prevent admin from deactivating themselves
                    if ($id == $user['id'] && $active == 0) {
                        throw new Exception("You cannot deactivate your own account");
                    }
                    
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, phone = ?, active = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $role, $phone, $active, $id]);
                    $success = "User '{$name}' updated successfully";
                    break;
                    
                case 'reset_password':
                    $id = intval($_POST['user_id']);
                    $password = $_POST['new_password'];
                    
                    if (!$id || empty($password)) {
                        throw new Exception("User ID and new password are required");
                    }
                    
                    if (strlen($password) < 6) {
                        throw new Exception("Password must be at least 6 characters long");
                    }
                    
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $id]);
                    
                    // Get user name for success message
                    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    $userName = $stmt->fetch()['name'];
                    
                    $success = "Password reset successfully for user '{$userName}'";
                    break;
                    
                case 'delete_user':
                    $id = intval($_POST['user_id']);
                    
                    if (!$id) {
                        throw new Exception("User ID is required");
                    }
                    
                    // Prevent admin from deleting themselves
                    if ($id == $user['id']) {
                        throw new Exception("You cannot delete your own account");
                    }
                    
                    // Check if user has assigned tickets
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE assignee_id = ?");
                    $stmt->execute([$id]);
                    $ticketCount = $stmt->fetch()['count'];
                    
                    if ($ticketCount > 0) {
                        throw new Exception("Cannot delete user: they have {$ticketCount} assigned tickets. Deactivate instead.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "User deleted successfully";
                    break;
                    
                default:
                    throw new Exception("Invalid action");
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    // Get all users with statistics
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(t.id) as assigned_tickets,
               COUNT(CASE WHEN t.status NOT IN ('closed', 'resolved') THEN 1 END) as active_tickets
        FROM users u
        LEFT JOIN tickets t ON u.id = t.assignee_id
        GROUP BY u.id
        ORDER BY u.active DESC, u.role, u.name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Get role statistics
    $roleStats = [];
    $stmt = $pdo->prepare("SELECT role, COUNT(*) as count FROM users WHERE active = 1 GROUP BY role");
    $stmt->execute();
    $roleData = $stmt->fetchAll();
    
    foreach ($roleData as $roleInfo) {
        $roleStats[$roleInfo['role']] = $roleInfo['count'];
    }
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Manage Users - ITSPtickets</title>
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
        
        .alert { 
            padding: 12px 20px; 
            border-radius: 6px; 
            margin-bottom: 20px; 
            font-weight: 500;
        }
        .alert-success { 
            background: #dcfce7; 
            color: #166534; 
            border: 1px solid #86efac; 
        }
        .alert-error { 
            background: #fee2e2; 
            color: #991b1b; 
            border: 1px solid #fca5a5; 
        }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stat { 
            font-weight: 500; 
            color: #374151; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .stat-number {
            background: #3b82f6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .management-section {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .form-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .form-section h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
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
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
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
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .btn-primary { 
            background: #3b82f6; 
            color: white; 
        }
        
        .btn-success { 
            background: #10b981; 
            color: white; 
        }
        
        .btn-danger { 
            background: #ef4444; 
            color: white; 
        }
        
        .btn-warning { 
            background: #f59e0b; 
            color: white; 
        }
        
        .btn-secondary { 
            background: #6b7280; 
            color: white; 
        }
        
        .btn:hover { 
            opacity: 0.9; 
            transform: translateY(-1px);
        }
        
        .users-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .users-header {
            background: #f8fafc;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .users-header h3 {
            color: #1f2937;
            font-size: 18px;
        }
        
        .user-item {
            border-bottom: 1px solid #e5e7eb;
            padding: 20px;
            transition: background-color 0.2s ease;
        }
        
        .user-item:last-child {
            border-bottom: none;
        }
        
        .user-item:hover {
            background: #f9fafb;
        }
        
        .user-item.inactive {
            opacity: 0.6;
            background: #f8fafc;
        }
        
        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .user-name {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .user-email {
            color: #6b7280;
            font-size: 14px;
            margin-top: 2px;
        }
        
        .user-role {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .role-admin { background: #fef3c7; color: #92400e; }
        .role-supervisor { background: #dbeafe; color: #1e40af; }
        .role-agent { background: #d1fae5; color: #065f46; }
        .role-requester { background: #f3e8ff; color: #7c3aed; }
        
        .user-stats {
            display: flex;
            gap: 15px;
            margin-top: 8px;
            font-size: 13px;
            color: #6b7280;
        }
        
        .user-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .edit-form {
            display: none;
            background: #f9fafb;
            padding: 20px;
            border-radius: 6px;
            margin-top: 15px;
            border: 1px solid #e5e7eb;
        }
        
        .edit-form.active {
            display: block;
        }
        
        .edit-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .password-reset-form {
            display: none;
            background: #fff7ed;
            padding: 15px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #fed7aa;
        }
        
        .password-reset-form.active {
            display: block;
        }
        
        @media (max-width: 1200px) {
            .management-section { 
                grid-template-columns: 1fr; 
            }
        }
        
        @media (max-width: 768px) {
            .edit-form-grid { 
                grid-template-columns: 1fr; 
            }
            .user-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .user-actions {
                flex-wrap: wrap;
            }
            .stats-bar {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>👥 User Management</h1>
            <div class='user-info'>
                <a href='/ITSPtickets/settings.php'>← Back to Settings</a>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class='alert alert-error'><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class='alert alert-success'><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <div class='stats-bar'>
            <div class='stat'>
                <span>👥 Total Users:</span>
                <span class='stat-number'><?= count($users) ?></span>
            </div>
            <?php foreach ($roleStats as $role => $count): ?>
                <div class='stat'>
                    <span><?= ucfirst($role) ?>s:</span>
                    <span class='stat-number'><?= $count ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class='management-section'>
            <!-- Add User Form -->
            <div class='form-section'>
                <h3>➕ Add New User</h3>
                <form method='POST'>
                    <input type='hidden' name='action' value='add_user'>
                    <div class='form-group'>
                        <label for='name'>Full Name *</label>
                        <input type='text' id='name' name='name' required placeholder='John Smith'>
                    </div>
                    <div class='form-group'>
                        <label for='email'>Email Address *</label>
                        <input type='email' id='email' name='email' required placeholder='john@company.com'>
                    </div>
                    <div class='form-group'>
                        <label for='role'>Role *</label>
                        <select id='role' name='role' required>
                            <option value=''>Select Role</option>
                            <option value='admin'>Admin</option>
                            <option value='supervisor'>Supervisor</option>
                            <option value='agent'>Agent</option>
                            <option value='requester'>Requester</option>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='phone'>Phone Number</label>
                        <input type='tel' id='phone' name='phone' placeholder='+1 (555) 123-4567'>
                    </div>
                    <div class='form-group'>
                        <label for='password'>Password *</label>
                        <input type='password' id='password' name='password' required minlength='6' placeholder='Minimum 6 characters'>
                    </div>
                    <button type='submit' class='btn btn-success'>Add User</button>
                </form>
            </div>
            
            <!-- Users List -->
            <div class='users-list'>
                <div class='users-header'>
                    <h3>📋 User Directory</h3>
                    <span style='font-size: 14px; color: #6b7280;'><?= count($users) ?> total users</span>
                </div>
                
                <?php if (empty($users)): ?>
                    <div class='user-item'>
                        <p>No users found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($users as $userItem): ?>
                        <div class='user-item <?= $userItem['active'] ? '' : 'inactive' ?>'>
                            <div class='user-header'>
                                <div>
                                    <div class='user-name'>
                                        <?= htmlspecialchars($userItem['name']) ?>
                                        <?php if (!$userItem['active']): ?>
                                            <span style='color: #ef4444; font-size: 14px; font-weight: normal;'>(Inactive)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class='user-email'><?= htmlspecialchars($userItem['email']) ?></div>
                                    <?php if ($userItem['phone']): ?>
                                        <div style='font-size: 13px; color: #6b7280; margin-top: 2px;'>
                                            📞 <?= htmlspecialchars($userItem['phone']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class='user-stats'>
                                        <span>🎫 <?= $userItem['assigned_tickets'] ?> total tickets</span>
                                        <span>⚡ <?= $userItem['active_tickets'] ?> active</span>
                                        <span>📅 Joined <?= date('M Y', strtotime($userItem['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div style='display: flex; flex-direction: column; align-items: flex-end; gap: 10px;'>
                                    <span class='user-role role-<?= $userItem['role'] ?>'>
                                        <?= htmlspecialchars($userItem['role']) ?>
                                    </span>
                                    <div class='user-actions'>
                                        <button onclick="toggleEdit('user-<?= $userItem['id'] ?>')" class='btn btn-primary btn-sm'>✏️ Edit</button>
                                        <button onclick="togglePasswordReset('pwd-<?= $userItem['id'] ?>')" class='btn btn-warning btn-sm'>🔑 Reset</button>
                                        <?php if ($userItem['id'] != $user['id'] && $userItem['assigned_tickets'] == 0): ?>
                                            <form method='POST' style='display: inline;' onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                <input type='hidden' name='action' value='delete_user'>
                                                <input type='hidden' name='user_id' value='<?= $userItem['id'] ?>'>
                                                <button type='submit' class='btn btn-danger btn-sm'>🗑️</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Edit Form -->
                            <div id='user-<?= $userItem['id'] ?>' class='edit-form'>
                                <form method='POST'>
                                    <input type='hidden' name='action' value='edit_user'>
                                    <input type='hidden' name='user_id' value='<?= $userItem['id'] ?>'>
                                    <div class='edit-form-grid'>
                                        <div class='form-group'>
                                            <label>Full Name</label>
                                            <input type='text' name='name' value='<?= htmlspecialchars($userItem['name']) ?>' required>
                                        </div>
                                        <div class='form-group'>
                                            <label>Email</label>
                                            <input type='email' name='email' value='<?= htmlspecialchars($userItem['email']) ?>' required>
                                        </div>
                                        <div class='form-group'>
                                            <label>Role</label>
                                            <select name='role' required>
                                                <option value='admin' <?= $userItem['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                <option value='supervisor' <?= $userItem['role'] === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                                                <option value='agent' <?= $userItem['role'] === 'agent' ? 'selected' : '' ?>>Agent</option>
                                                <option value='requester' <?= $userItem['role'] === 'requester' ? 'selected' : '' ?>>Requester</option>
                                            </select>
                                        </div>
                                        <div class='form-group'>
                                            <label>Phone</label>
                                            <input type='tel' name='phone' value='<?= htmlspecialchars($userItem['phone']) ?>'>
                                        </div>
                                        <div class='form-group'>
                                            <label>Status</label>
                                            <select name='active'>
                                                <option value='1' <?= $userItem['active'] ? 'selected' : '' ?>>Active</option>
                                                <option value='0' <?= !$userItem['active'] ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class='form-actions'>
                                        <button type='submit' class='btn btn-success btn-sm'>💾 Save Changes</button>
                                        <button type='button' onclick="toggleEdit('user-<?= $userItem['id'] ?>')" class='btn btn-secondary btn-sm'>Cancel</button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Password Reset Form -->
                            <div id='pwd-<?= $userItem['id'] ?>' class='password-reset-form'>
                                <form method='POST'>
                                    <input type='hidden' name='action' value='reset_password'>
                                    <input type='hidden' name='user_id' value='<?= $userItem['id'] ?>'>
                                    <div style='display: flex; gap: 15px; align-items: end;'>
                                        <div class='form-group' style='flex: 1; margin-bottom: 0;'>
                                            <label>New Password</label>
                                            <input type='password' name='new_password' required minlength='6' placeholder='Minimum 6 characters'>
                                        </div>
                                        <button type='submit' class='btn btn-warning btn-sm'>🔑 Reset Password</button>
                                        <button type='button' onclick="togglePasswordReset('pwd-<?= $userItem['id'] ?>')" class='btn btn-secondary btn-sm'>Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function toggleEdit(formId) {
            const form = document.getElementById(formId);
            const isActive = form.classList.contains('active');
            
            // Hide all other edit forms
            document.querySelectorAll('.edit-form.active').forEach(f => f.classList.remove('active'));
            document.querySelectorAll('.password-reset-form.active').forEach(f => f.classList.remove('active'));
            
            // Toggle this form
            if (!isActive) {
                form.classList.add('active');
            }
        }
        
        function togglePasswordReset(formId) {
            const form = document.getElementById(formId);
            const isActive = form.classList.contains('active');
            
            // Hide all other forms
            document.querySelectorAll('.edit-form.active').forEach(f => f.classList.remove('active'));
            document.querySelectorAll('.password-reset-form.active').forEach(f => f.classList.remove('active'));
            
            // Toggle this form
            if (!isActive) {
                form.classList.add('active');
            }
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>