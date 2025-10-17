<?php
/*
|--------------------------------------------------------------------------
| SLA Management - Simple Model
|--------------------------------------------------------------------------
| Service Level Agreement management (Admin/Supervisor)
*/

require_once 'auth-helper.php';
require_once 'db-connection.php';
require_once 'sla-service-simple.php';

try {
    $pdo = createDatabaseConnection();
    $user = getCurrentSupervisor($pdo);
    
    // Initialize SLA service
    $slaService = new SlaServiceSimple($pdo);
    
    $message = '';
    $messageType = '';
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_policy':
                $name = trim($_POST['name'] ?? '');
                $type = $_POST['type'] ?? '';
                $priority = $_POST['priority'] ?? '';
                $responseTarget = (int)($_POST['response_target'] ?? 0);
                $resolutionTarget = (int)($_POST['resolution_target'] ?? 0);
                $calendarId = (int)($_POST['calendar_id'] ?? 0);
                
                if ($name && $type && $priority && $responseTarget > 0 && $resolutionTarget > 0 && $calendarId > 0) {
                    // Check if policy already exists
                    $stmt = $pdo->prepare("SELECT id FROM sla_policies WHERE type = ? AND priority = ?");
                    $stmt->execute([$type, $priority]);
                    
                    if ($stmt->fetch()) {
                        $message = "SLA policy for {$type} with {$priority} priority already exists.";
                        $messageType = 'error';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO sla_policies (name, type, priority, response_target, resolution_target, calendar_id, active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                        $stmt->execute([$name, $type, $priority, $responseTarget, $resolutionTarget, $calendarId]);
                        $message = "SLA policy '{$name}' created successfully.";
                        $messageType = 'success';
                    }
                } else {
                    $message = "All fields are required and targets must be greater than 0.";
                    $messageType = 'error';
                }
                break;
                
            case 'update_policy':
                $policyId = (int)($_POST['policy_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $responseTarget = (int)($_POST['response_target'] ?? 0);
                $resolutionTarget = (int)($_POST['resolution_target'] ?? 0);
                $active = isset($_POST['active']) ? 1 : 0;
                
                if ($policyId && $name && $responseTarget > 0 && $resolutionTarget > 0) {
                    $stmt = $pdo->prepare("UPDATE sla_policies SET name = ?, response_target = ?, resolution_target = ?, active = ? WHERE id = ?");
                    $stmt->execute([$name, $responseTarget, $resolutionTarget, $active, $policyId]);
                    $message = "SLA policy updated successfully.";
                    $messageType = 'success';
                } else {
                    $message = "Invalid policy data provided.";
                    $messageType = 'error';
                }
                break;
                
            case 'delete_policy':
                $policyId = (int)($_POST['policy_id'] ?? 0);
                if ($policyId) {
                    // Check if policy is in use
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE sla_policy_id = ?");
                    $stmt->execute([$policyId]);
                    $inUse = $stmt->fetch()['count'] > 0;
                    
                    if ($inUse) {
                        $message = "Cannot delete SLA policy as it is currently assigned to tickets.";
                        $messageType = 'error';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM sla_policies WHERE id = ?");
                        $stmt->execute([$policyId]);
                        $message = "SLA policy deleted successfully.";
                        $messageType = 'success';
                    }
                }
                break;
                
            case 'create_defaults':
                $slaService->createDefaultSlaPolicies();
                $message = "Default SLA policies created successfully.";
                $messageType = 'success';
                break;
        }
    }
    
    // Get all SLA policies
    $stmt = $pdo->prepare("SELECT * FROM sla_policies ORDER BY type, priority");
    $stmt->execute();
    $policies = $stmt->fetchAll();
    
    // Get available calendars
    $stmt = $pdo->prepare("SELECT * FROM calendars WHERE active = 1 ORDER BY name");
    $stmt->execute();
    $calendars = $stmt->fetchAll();
    
    // Get policy usage statistics
    $policyStats = [];
    foreach ($policies as $policy) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE sla_policy_id = ?");
        $stmt->execute([$policy['id']]);
        $policyStats[$policy['id']] = $stmt->fetch()['count'];
    }
    
} catch (Exception $e) {
    $message = "Database error: " . $e->getMessage();
    $messageType = 'error';
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>SLA Management - ITSPtickets</title>
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
        .user-info a { color: #3b82f6; text-decoration: none; }
        .user-info a:hover { text-decoration: underline; }
        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .message.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .section-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
        }
        .section-content {
            padding: 20px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
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
            margin-right: 10px;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #059669; color: white; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn:hover { opacity: 0.9; }
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
        .status-active { color: #059669; font-weight: 600; }
        .status-inactive { color: #6b7280; }
        .priority-urgent { color: #dc2626; font-weight: 600; }
        .priority-high { color: #ea580c; font-weight: 600; }
        .priority-normal { color: #0369a1; }
        .priority-low { color: #059669; }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover { color: black; }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>SLA Management</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <a href='/ITSPtickets/reports-simple.php'>Reports</a>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
                <a href='/ITSPtickets/logout.php'>Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class='message <?= $messageType ?>'><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <!-- Create New SLA Policy -->
        <div class='section'>
            <div class='section-header'>Create New SLA Policy</div>
            <div class='section-content'>
                <form method='POST'>
                    <input type='hidden' name='action' value='create_policy'>
                    
                    <div class='form-grid'>
                        <div class='form-group'>
                            <label for='name'>Policy Name</label>
                            <input type='text' id='name' name='name' required placeholder='e.g., Critical Incident SLA'>
                        </div>
                        
                        <div class='form-group'>
                            <label for='type'>Ticket Type</label>
                            <select id='type' name='type' required>
                                <option value=''>Select Type</option>
                                <option value='incident'>Incident</option>
                                <option value='request'>Request</option>
                                <option value='job'>Job</option>
                            </select>
                        </div>
                        
                        <div class='form-group'>
                            <label for='priority'>Priority</label>
                            <select id='priority' name='priority' required>
                                <option value=''>Select Priority</option>
                                <option value='urgent'>Urgent</option>
                                <option value='high'>High</option>
                                <option value='normal'>Normal</option>
                                <option value='low'>Low</option>
                            </select>
                        </div>
                        
                        <div class='form-group'>
                            <label for='response_target'>Response Target (minutes)</label>
                            <input type='number' id='response_target' name='response_target' min='1' required placeholder='60'>
                        </div>
                        
                        <div class='form-group'>
                            <label for='resolution_target'>Resolution Target (minutes)</label>
                            <input type='number' id='resolution_target' name='resolution_target' min='1' required placeholder='240'>
                        </div>
                        
                        <div class='form-group'>
                            <label for='calendar_id'>Business Hours Calendar</label>
                            <select id='calendar_id' name='calendar_id' required>
                                <option value=''>Select Calendar</option>
                                <?php foreach ($calendars as $calendar): ?>
                                    <option value='<?= $calendar['id'] ?>'><?= htmlspecialchars($calendar['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type='submit' class='btn btn-primary'>Create SLA Policy</button>
                    <button type='button' class='btn btn-secondary' onclick='document.querySelector("form").reset()'>Reset</button>
                </form>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class='section'>
            <div class='section-header'>Quick Actions</div>
            <div class='section-content'>
                <form method='POST' style='display: inline;'>
                    <input type='hidden' name='action' value='create_defaults'>
                    <button type='submit' class='btn btn-success' onclick='return confirm("This will create default SLA policies for all type/priority combinations. Continue?")'>
                        Create Default SLA Policies
                    </button>
                </form>
                <p style='margin-top: 10px; color: #6b7280; font-size: 14px;'>
                    This will create standard SLA policies for all ticket types and priorities if they don't already exist.
                </p>
            </div>
        </div>
        
        <!-- Existing SLA Policies -->
        <div class='section'>
            <div class='section-header'>Existing SLA Policies</div>
            <div class='section-content'>
                <?php if (empty($policies)): ?>
                    <p>No SLA policies found. Create your first policy above or use the "Create Default SLA Policies" button.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Response Target</th>
                                <th>Resolution Target</th>
                                <th>Status</th>
                                <th>Tickets Using</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($policies as $policy): ?>
                                <tr>
                                    <td><?= htmlspecialchars($policy['name']) ?></td>
                                    <td><?= ucfirst(htmlspecialchars($policy['type'])) ?></td>
                                    <td class='priority-<?= $policy['priority'] ?>'><?= ucfirst(htmlspecialchars($policy['priority'])) ?></td>
                                    <td><?= round($policy['response_target'] / 60, 1) ?>h</td>
                                    <td><?= round($policy['resolution_target'] / 60, 1) ?>h</td>
                                    <td class='<?= $policy['active'] ? 'status-active' : 'status-inactive' ?>'>
                                        <?= $policy['active'] ? 'Active' : 'Inactive' ?>
                                    </td>
                                    <td><?= $policyStats[$policy['id']] ?? 0 ?></td>
                                    <td>
                                        <button class='btn btn-primary' onclick='editPolicy(<?= json_encode($policy) ?>)'>Edit</button>
                                        <?php if (($policyStats[$policy['id']] ?? 0) == 0): ?>
                                            <form method='POST' style='display: inline;'>
                                                <input type='hidden' name='action' value='delete_policy'>
                                                <input type='hidden' name='policy_id' value='<?= $policy['id'] ?>'>
                                                <button type='submit' class='btn btn-danger' onclick='return confirm("Delete this SLA policy?")'>Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Edit Policy Modal -->
    <div id='editModal' class='modal'>
        <div class='modal-content'>
            <span class='close' onclick='closeEditModal()'>&times;</span>
            <h2>Edit SLA Policy</h2>
            <form method='POST' id='editForm'>
                <input type='hidden' name='action' value='update_policy'>
                <input type='hidden' name='policy_id' id='edit_policy_id'>
                
                <div class='form-group' style='margin-bottom: 15px;'>
                    <label for='edit_name'>Policy Name</label>
                    <input type='text' id='edit_name' name='name' required>
                </div>
                
                <div class='form-grid'>
                    <div class='form-group'>
                        <label for='edit_response_target'>Response Target (minutes)</label>
                        <input type='number' id='edit_response_target' name='response_target' min='1' required>
                    </div>
                    
                    <div class='form-group'>
                        <label for='edit_resolution_target'>Resolution Target (minutes)</label>
                        <input type='number' id='edit_resolution_target' name='resolution_target' min='1' required>
                    </div>
                </div>
                
                <div class='checkbox-group' style='margin: 15px 0;'>
                    <input type='checkbox' id='edit_active' name='active'>
                    <label for='edit_active'>Active</label>
                </div>
                
                <button type='submit' class='btn btn-primary'>Update Policy</button>
                <button type='button' class='btn btn-secondary' onclick='closeEditModal()'>Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function editPolicy(policy) {
            document.getElementById('edit_policy_id').value = policy.id;
            document.getElementById('edit_name').value = policy.name;
            document.getElementById('edit_response_target').value = policy.response_target;
            document.getElementById('edit_resolution_target').value = policy.resolution_target;
            document.getElementById('edit_active').checked = policy.active == 1;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>