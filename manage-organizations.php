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
                case 'add_organization':
                    $name = trim($_POST['name']);
                    $code = strtoupper(trim($_POST['code']));
                    $description = trim($_POST['description']) ?: null;
                    $contact_email = trim($_POST['contact_email']) ?: null;
                    $contact_phone = trim($_POST['contact_phone']) ?: null;
                    $address = trim($_POST['address']) ?: null;
                    $monthly_allowance = floatval($_POST['monthly_hours_allowance']);
                    $quarterly_allowance = floatval($_POST['quarterly_hours_allowance']);
                    
                    if (empty($name) || empty($code)) {
                        throw new Exception("Organization name and code are required");
                    }
                    
                    if ($contact_email && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid contact email format");
                    }
                    
                    // Check if code already exists
                    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE code = ?");
                    $stmt->execute([$code]);
                    if ($stmt->fetch()) {
                        throw new Exception("Organization code already exists");
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO organizations (
                            name, code, description, contact_email, contact_phone, address, 
                            monthly_hours_allowance, quarterly_hours_allowance, active, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $stmt->execute([
                        $name, $code, $description, $contact_email, $contact_phone, 
                        $address, $monthly_allowance, $quarterly_allowance
                    ]);
                    $success = "Organization '{$name}' added successfully";
                    break;
                    
                case 'edit_organization':
                    $id = intval($_POST['organization_id']);
                    $name = trim($_POST['name']);
                    $code = strtoupper(trim($_POST['code']));
                    $description = trim($_POST['description']) ?: null;
                    $contact_email = trim($_POST['contact_email']) ?: null;
                    $contact_phone = trim($_POST['contact_phone']) ?: null;
                    $address = trim($_POST['address']) ?: null;
                    $monthly_allowance = floatval($_POST['monthly_hours_allowance']);
                    $quarterly_allowance = floatval($_POST['quarterly_hours_allowance']);
                    $active = intval($_POST['active']);
                    
                    if (empty($name) || empty($code) || !$id) {
                        throw new Exception("Organization name, code, and ID are required");
                    }
                    
                    if ($contact_email && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid contact email format");
                    }
                    
                    // Check if code already exists for another organization
                    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE code = ? AND id != ?");
                    $stmt->execute([$code, $id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Organization code already exists for another organization");
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE organizations SET 
                            name = ?, code = ?, description = ?, contact_email = ?, contact_phone = ?, 
                            address = ?, monthly_hours_allowance = ?, quarterly_hours_allowance = ?, active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $name, $code, $description, $contact_email, $contact_phone, 
                        $address, $monthly_allowance, $quarterly_allowance, $active, $id
                    ]);
                    $success = "Organization '{$name}' updated successfully";
                    break;
                    
                case 'delete_organization':
                    $id = intval($_POST['organization_id']);
                    
                    if (!$id) {
                        throw new Exception("Organization ID is required");
                    }
                    
                    // Check if organization has linked requesters
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM requesters WHERE organization_id = ?");
                    $stmt->execute([$id]);
                    $requesterCount = $stmt->fetch()['count'];
                    
                    if ($requesterCount > 0) {
                        throw new Exception("Cannot delete organization: it has {$requesterCount} linked requesters. Deactivate instead.");
                    }
                    
                    // Check if organization has customer reports
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM customer_reports WHERE organization_id = ?");
                    $stmt->execute([$id]);
                    $reportCount = $stmt->fetch()['count'];
                    
                    if ($reportCount > 0) {
                        throw new Exception("Cannot delete organization: it has {$reportCount} customer reports. Deactivate instead.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM organizations WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Organization deleted successfully";
                    break;
                    
                case 'assign_requester':
                    $requester_id = intval($_POST['requester_id']);
                    $organization_id = intval($_POST['organization_id']) ?: null;
                    
                    if (!$requester_id) {
                        throw new Exception("Requester ID is required");
                    }
                    
                    $stmt = $pdo->prepare("UPDATE requesters SET organization_id = ? WHERE id = ?");
                    $stmt->execute([$organization_id, $requester_id]);
                    
                    $orgName = 'No Organization';
                    if ($organization_id) {
                        $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
                        $stmt->execute([$organization_id]);
                        $org = $stmt->fetch();
                        $orgName = $org['name'];
                    }
                    
                    $success = "Requester assignment updated to '{$orgName}'";
                    break;
                    
                default:
                    throw new Exception("Invalid action");
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    // Get all organizations with statistics
    $stmt = $pdo->prepare("
        SELECT o.*, 
               COUNT(r.id) as requester_count,
               COUNT(t.id) as ticket_count,
               SUM(CASE WHEN t.status NOT IN ('closed', 'resolved') THEN 1 ELSE 0 END) as active_tickets
        FROM organizations o
        LEFT JOIN requesters r ON o.id = r.organization_id
        LEFT JOIN tickets t ON r.id = t.requester_id
        GROUP BY o.id
        ORDER BY o.active DESC, o.name
    ");
    $stmt->execute();
    $organizations = $stmt->fetchAll();
    
    // Get unassigned requesters
    $stmt = $pdo->prepare("
        SELECT r.*, COUNT(t.id) as ticket_count
        FROM requesters r
        LEFT JOIN tickets t ON r.id = t.requester_id
        WHERE r.organization_id IS NULL AND r.active = 1
        GROUP BY r.id
        ORDER BY r.name
    ");
    $stmt->execute();
    $unassigned_requesters = $stmt->fetchAll();
    
    // Get organization statistics
    $orgStats = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM organizations");
    $stmt->execute();
    $orgStats['total'] = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as active FROM organizations WHERE active = 1");
    $stmt->execute();
    $orgStats['active'] = $stmt->fetch()['active'];
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Manage Organizations - ITSPtickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc; 
            line-height: 1.6;
        }
        .container { 
            max-width: 1600px; 
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
            grid-template-columns: 400px 1fr;
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
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group textarea {
            resize: vertical;
            height: 80px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
        
        .organizations-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .organizations-header {
            background: #f8fafc;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .organizations-header h3 {
            color: #1f2937;
            font-size: 18px;
        }
        
        .organization-item {
            border-bottom: 1px solid #e5e7eb;
            padding: 25px;
            transition: background-color 0.2s ease;
        }
        
        .organization-item:last-child {
            border-bottom: none;
        }
        
        .organization-item:hover {
            background: #f9fafb;
        }
        
        .organization-item.inactive {
            opacity: 0.6;
            background: #f8fafc;
        }
        
        .org-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .org-info {
            flex: 1;
        }
        
        .org-name {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .org-code {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 10px;
        }
        
        .org-description {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .org-contact {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
            font-size: 13px;
            color: #6b7280;
        }
        
        .org-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            font-size: 13px;
            color: #6b7280;
        }
        
        .org-allowances {
            background: #f0f9ff;
            padding: 10px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 13px;
        }
        
        .org-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        
        .status-active { 
            background: #d1fae5; 
            color: #065f46; 
        }
        
        .status-inactive { 
            background: #fee2e2; 
            color: #991b1b; 
        }
        
        .org-actions {
            display: flex;
            gap: 8px;
            flex-direction: column;
            align-items: flex-end;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .requesters-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 30px;
            padding: 20px;
        }
        
        .requester-assignment {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .requester-assignment:last-child {
            border-bottom: none;
        }
        
        .requester-info {
            flex: 1;
        }
        
        .requester-name {
            font-weight: 500;
            color: #1f2937;
        }
        
        .requester-email {
            font-size: 13px;
            color: #6b7280;
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
            .org-header {
                flex-direction: column;
                gap: 15px;
            }
            .org-actions {
                flex-direction: row;
                align-items: center;
            }
            .stats-bar {
                flex-wrap: wrap;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🏢 Organization Management</h1>
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
                <span>🏢 Total Organizations:</span>
                <span class='stat-number'><?= $orgStats['total'] ?></span>
            </div>
            <div class='stat'>
                <span>✅ Active:</span>
                <span class='stat-number'><?= $orgStats['active'] ?></span>
            </div>
            <div class='stat'>
                <span>👥 Unassigned Requesters:</span>
                <span class='stat-number'><?= count($unassigned_requesters) ?></span>
            </div>
        </div>
        
        <div class='management-section'>
            <!-- Add Organization Form -->
            <div class='form-section'>
                <h3>➕ Add New Organization</h3>
                <form method='POST'>
                    <input type='hidden' name='action' value='add_organization'>
                    <div class='form-group'>
                        <label for='name'>Organization Name *</label>
                        <input type='text' id='name' name='name' required placeholder='ACME Corporation'>
                    </div>
                    <div class='form-group'>
                        <label for='code'>Organization Code *</label>
                        <input type='text' id='code' name='code' required placeholder='ACME' style='text-transform: uppercase;' maxlength='10'>
                    </div>
                    <div class='form-group'>
                        <label for='description'>Description</label>
                        <textarea id='description' name='description' placeholder='Brief description of the organization'></textarea>
                    </div>
                    <div class='form-row'>
                        <div class='form-group'>
                            <label for='contact_email'>Contact Email</label>
                            <input type='email' id='contact_email' name='contact_email' placeholder='admin@company.com'>
                        </div>
                        <div class='form-group'>
                            <label for='contact_phone'>Contact Phone</label>
                            <input type='tel' id='contact_phone' name='contact_phone' placeholder='+1 (555) 123-4567'>
                        </div>
                    </div>
                    <div class='form-group'>
                        <label for='address'>Address</label>
                        <textarea id='address' name='address' placeholder='Street address, city, country'></textarea>
                    </div>
                    <div class='form-row'>
                        <div class='form-group'>
                            <label for='monthly_hours_allowance'>Monthly Hours Allowance</label>
                            <input type='number' id='monthly_hours_allowance' name='monthly_hours_allowance' step='0.25' min='0' value='0'>
                        </div>
                        <div class='form-group'>
                            <label for='quarterly_hours_allowance'>Quarterly Hours Allowance</label>
                            <input type='number' id='quarterly_hours_allowance' name='quarterly_hours_allowance' step='0.25' min='0' value='0'>
                        </div>
                    </div>
                    <button type='submit' class='btn btn-success'>Add Organization</button>
                </form>
            </div>
            
            <!-- Organizations List -->
            <div class='organizations-list'>
                <div class='organizations-header'>
                    <h3>📋 Organizations Directory</h3>
                    <span style='font-size: 14px; color: #6b7280;'><?= count($organizations) ?> total organizations</span>
                </div>
                
                <?php if (empty($organizations)): ?>
                    <div class='organization-item'>
                        <p>No organizations found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($organizations as $org): ?>
                        <div class='organization-item <?= $org['active'] ? '' : 'inactive' ?>'>
                            <div class='org-header'>
                                <div class='org-info'>
                                    <div class='org-name'>
                                        <?= htmlspecialchars($org['name']) ?>
                                        <span class='org-code'><?= htmlspecialchars($org['code']) ?></span>
                                        <?php if (!$org['active']): ?>
                                            <span style='color: #ef4444; font-size: 14px; font-weight: normal;'>(Inactive)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class='org-status status-<?= $org['active'] ? 'active' : 'inactive' ?>'>
                                        <?= $org['active'] ? 'Active' : 'Inactive' ?>
                                    </div>
                                    <?php if ($org['description']): ?>
                                        <div class='org-description'><?= htmlspecialchars($org['description']) ?></div>
                                    <?php endif; ?>
                                    <div class='org-contact'>
                                        <?php if ($org['contact_email']): ?>
                                            <span>📧 <?= htmlspecialchars($org['contact_email']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($org['contact_phone']): ?>
                                            <span>📞 <?= htmlspecialchars($org['contact_phone']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class='org-stats'>
                                        <span>👥 <?= $org['requester_count'] ?> requesters</span>
                                        <span>🎫 <?= $org['ticket_count'] ?> total tickets</span>
                                        <span>⚡ <?= $org['active_tickets'] ?> active</span>
                                        <span>📅 Since <?= date('M Y', strtotime($org['created_at'])) ?></span>
                                    </div>
                                    <?php if ($org['monthly_hours_allowance'] > 0 || $org['quarterly_hours_allowance'] > 0): ?>
                                        <div class='org-allowances'>
                                            <strong>Hour Allowances:</strong>
                                            <?php if ($org['monthly_hours_allowance'] > 0): ?>
                                                Monthly: <?= $org['monthly_hours_allowance'] ?>h
                                            <?php endif; ?>
                                            <?php if ($org['quarterly_hours_allowance'] > 0): ?>
                                                <?= $org['monthly_hours_allowance'] > 0 ? ' | ' : '' ?>Quarterly: <?= $org['quarterly_hours_allowance'] ?>h
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class='org-actions'>
                                    <button onclick="toggleEdit('org-<?= $org['id'] ?>')" class='btn btn-primary btn-sm'>✏️ Edit</button>
                                    <?php if ($org['requester_count'] == 0): ?>
                                        <form method='POST' style='display: inline;' onsubmit="return confirm('Are you sure you want to delete this organization?')">
                                            <input type='hidden' name='action' value='delete_organization'>
                                            <input type='hidden' name='organization_id' value='<?= $org['id'] ?>'>
                                            <button type='submit' class='btn btn-danger btn-sm'>🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Edit Form -->
                            <div id='org-<?= $org['id'] ?>' class='edit-form'>
                                <form method='POST'>
                                    <input type='hidden' name='action' value='edit_organization'>
                                    <input type='hidden' name='organization_id' value='<?= $org['id'] ?>'>
                                    <div class='edit-form-grid'>
                                        <div class='form-group'>
                                            <label>Organization Name</label>
                                            <input type='text' name='name' value='<?= htmlspecialchars($org['name']) ?>' required>
                                        </div>
                                        <div class='form-group'>
                                            <label>Code</label>
                                            <input type='text' name='code' value='<?= htmlspecialchars($org['code']) ?>' required style='text-transform: uppercase;' maxlength='10'>
                                        </div>
                                        <div class='form-group'>
                                            <label>Contact Email</label>
                                            <input type='email' name='contact_email' value='<?= htmlspecialchars($org['contact_email']) ?>'>
                                        </div>
                                        <div class='form-group'>
                                            <label>Contact Phone</label>
                                            <input type='tel' name='contact_phone' value='<?= htmlspecialchars($org['contact_phone']) ?>'>
                                        </div>
                                        <div class='form-group'>
                                            <label>Monthly Hours</label>
                                            <input type='number' name='monthly_hours_allowance' value='<?= $org['monthly_hours_allowance'] ?>' step='0.25' min='0'>
                                        </div>
                                        <div class='form-group'>
                                            <label>Quarterly Hours</label>
                                            <input type='number' name='quarterly_hours_allowance' value='<?= $org['quarterly_hours_allowance'] ?>' step='0.25' min='0'>
                                        </div>
                                        <div class='form-group'>
                                            <label>Status</label>
                                            <select name='active'>
                                                <option value='1' <?= $org['active'] ? 'selected' : '' ?>>Active</option>
                                                <option value='0' <?= !$org['active'] ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class='form-group'>
                                        <label>Description</label>
                                        <textarea name='description'><?= htmlspecialchars($org['description']) ?></textarea>
                                    </div>
                                    <div class='form-group'>
                                        <label>Address</label>
                                        <textarea name='address'><?= htmlspecialchars($org['address']) ?></textarea>
                                    </div>
                                    <div class='form-actions'>
                                        <button type='submit' class='btn btn-success btn-sm'>💾 Save Changes</button>
                                        <button type='button' onclick="toggleEdit('org-<?= $org['id'] ?>')" class='btn btn-secondary btn-sm'>Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Unassigned Requesters Section -->
        <?php if (!empty($unassigned_requesters)): ?>
            <div class='requesters-section'>
                <h3>👥 Unassigned Requesters</h3>
                <p style='color: #6b7280; margin-bottom: 20px;'>These requesters are not currently assigned to any organization.</p>
                
                <?php foreach ($unassigned_requesters as $requester): ?>
                    <div class='requester-assignment'>
                        <div class='requester-info'>
                            <div class='requester-name'><?= htmlspecialchars($requester['name']) ?></div>
                            <div class='requester-email'><?= htmlspecialchars($requester['email']) ?> | <?= $requester['ticket_count'] ?> tickets</div>
                        </div>
                        <form method='POST' style='display: flex; gap: 10px; align-items: center;'>
                            <input type='hidden' name='action' value='assign_requester'>
                            <input type='hidden' name='requester_id' value='<?= $requester['id'] ?>'>
                            <select name='organization_id' required style='width: 200px; padding: 5px;'>
                                <option value=''>Assign to Organization</option>
                                <?php foreach ($organizations as $org): ?>
                                    <?php if ($org['active']): ?>
                                        <option value='<?= $org['id'] ?>'><?= htmlspecialchars($org['name']) ?> (<?= htmlspecialchars($org['code']) ?>)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type='submit' class='btn btn-primary btn-sm'>Assign</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleEdit(formId) {
            const form = document.getElementById(formId);
            const isActive = form.classList.contains('active');
            
            // Hide all other edit forms
            document.querySelectorAll('.edit-form.active').forEach(f => f.classList.remove('active'));
            
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
        
        // Auto-uppercase organization codes
        document.addEventListener('input', function(e) {
            if (e.target.name === 'code' || e.target.id === 'code') {
                e.target.value = e.target.value.toUpperCase();
            }
        });
    </script>
</body>
</html>