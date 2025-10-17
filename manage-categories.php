<?php
/*
|--------------------------------------------------------------------------
| Category Management - Simple Model
|--------------------------------------------------------------------------
| Ticket category and subcategory management (Admin only)
*/

require_once 'auth-helper.php';
require_once 'db-connection.php';

try {
    $pdo = createDatabaseConnection();
    $user = getCurrentAdmin($pdo);
    
    $error = '';
    $success = '';
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'add_category':
                    $name = trim($_POST['category_name']);
                    $time_estimate = intval($_POST['time_estimate']);
                    
                    if (empty($name)) {
                        throw new Exception("Category name is required");
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO ticket_categories (name, parent_id, time_estimate_minutes, active) VALUES (?, NULL, ?, 1)");
                    $stmt->execute([$name, $time_estimate]);
                    $success = "Category '{$name}' added successfully";
                    break;
                    
                case 'add_subcategory':
                    $name = trim($_POST['subcategory_name']);
                    $parent_id = intval($_POST['parent_id']);
                    $time_estimate = intval($_POST['time_estimate']);
                    
                    if (empty($name) || !$parent_id) {
                        throw new Exception("Subcategory name and parent category are required");
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO ticket_categories (name, parent_id, time_estimate_minutes, active) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$name, $parent_id, $time_estimate]);
                    $success = "Subcategory '{$name}' added successfully";
                    break;
                    
                case 'edit_category':
                    $id = intval($_POST['category_id']);
                    $name = trim($_POST['category_name']);
                    $time_estimate = intval($_POST['time_estimate']);
                    $active = intval($_POST['active']);
                    
                    if (empty($name) || !$id) {
                        throw new Exception("Category name and ID are required");
                    }
                    
                    $stmt = $pdo->prepare("UPDATE ticket_categories SET name = ?, time_estimate_minutes = ?, active = ? WHERE id = ?");
                    $stmt->execute([$name, $time_estimate, $active, $id]);
                    $success = "Category '{$name}' updated successfully";
                    break;
                    
                case 'delete_category':
                    $id = intval($_POST['category_id']);
                    
                    if (!$id) {
                        throw new Exception("Category ID is required");
                    }
                    
                    // Check if category is in use
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE category_id = ? OR subcategory_id = ?");
                    $stmt->execute([$id, $id]);
                    $usage = $stmt->fetch();
                    
                    if ($usage['count'] > 0) {
                        throw new Exception("Cannot delete category: {$usage['count']} tickets are using this category");
                    }
                    
                    // Check if category has subcategories
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ticket_categories WHERE parent_id = ?");
                    $stmt->execute([$id]);
                    $subcats = $stmt->fetch();
                    
                    if ($subcats['count'] > 0) {
                        throw new Exception("Cannot delete category: it has {$subcats['count']} subcategories. Delete subcategories first.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM ticket_categories WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Category deleted successfully";
                    break;
                    
                default:
                    throw new Exception("Invalid action");
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    // Get all categories and subcategories
    $stmt = $pdo->prepare("SELECT * FROM ticket_categories ORDER BY parent_id ASC, name ASC");
    $stmt->execute();
    $all_categories = $stmt->fetchAll();
    
    // Organize into main categories and subcategories
    $categories = [];
    $subcategories = [];
    
    foreach ($all_categories as $cat) {
        if ($cat['parent_id'] === null) {
            $categories[] = $cat;
        } else {
            if (!isset($subcategories[$cat['parent_id']])) {
                $subcategories[$cat['parent_id']] = [];
            }
            $subcategories[$cat['parent_id']][] = $cat;
        }
    }
    
    // Get usage statistics
    $stats = [];
    foreach ($all_categories as $cat) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE category_id = ? OR subcategory_id = ?");
        $stmt->execute([$cat['id'], $cat['id']]);
        $result = $stmt->fetch();
        $stats[$cat['id']] = $result['count'];
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
    <title>Manage Categories - ITSPtickets</title>
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
        
        .management-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .form-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        
        .btn-secondary { 
            background: #6b7280; 
            color: white; 
        }
        
        .btn:hover { 
            opacity: 0.9; 
            transform: translateY(-1px);
        }
        
        .categories-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .categories-header {
            background: #f8fafc;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .categories-header h3 {
            color: #1f2937;
            font-size: 18px;
        }
        
        .category-item {
            border-bottom: 1px solid #e5e7eb;
            padding: 20px;
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .category-name {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .category-info {
            display: flex;
            gap: 15px;
            align-items: center;
            font-size: 13px;
            color: #6b7280;
        }
        
        .category-status {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-active { 
            background: #d1fae5; 
            color: #065f46; 
        }
        
        .status-inactive { 
            background: #fee2e2; 
            color: #991b1b; 
        }
        
        .category-actions {
            display: flex;
            gap: 10px;
        }
        
        .subcategories {
            margin-left: 30px;
            margin-top: 15px;
        }
        
        .subcategory-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .subcategory-item:last-child {
            border-bottom: none;
        }
        
        .subcategory-name {
            font-weight: 500;
            color: #374151;
        }
        
        .subcategory-info {
            display: flex;
            gap: 10px;
            align-items: center;
            font-size: 12px;
            color: #6b7280;
        }
        
        .edit-form {
            display: none;
            background: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            border: 1px solid #e5e7eb;
        }
        
        .edit-form.active {
            display: block;
        }
        
        .edit-form-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 10px;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .management-grid { grid-template-columns: 1fr; }
            .edit-form-row { grid-template-columns: 1fr; }
            .category-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🗂️ Manage Categories</h1>
            <div class='user-info'>
                <a href='/ITSPtickets/dashboard-simple.php'>← Back to Dashboard</a>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class='alert alert-error'><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class='alert alert-success'><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <div class='management-grid'>
            <!-- Add Category Form -->
            <div class='form-section'>
                <h3>➕ Add New Category</h3>
                <form method='POST'>
                    <input type='hidden' name='action' value='add_category'>
                    <div class='form-group'>
                        <label for='category_name'>Category Name *</label>
                        <input type='text' id='category_name' name='category_name' required placeholder='e.g., Email, Network, Security'>
                    </div>
                    <div class='form-group'>
                        <label for='time_estimate'>Default Time Estimate (minutes)</label>
                        <input type='number' id='time_estimate' name='time_estimate' value='30' min='0' step='15'>
                    </div>
                    <button type='submit' class='btn btn-success'>Add Category</button>
                </form>
            </div>
            
            <!-- Add Subcategory Form -->
            <div class='form-section'>
                <h3>➕ Add New Subcategory</h3>
                <form method='POST'>
                    <input type='hidden' name='action' value='add_subcategory'>
                    <div class='form-group'>
                        <label for='parent_id'>Parent Category *</label>
                        <select id='parent_id' name='parent_id' required>
                            <option value=''>Select Parent Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value='<?= $category['id'] ?>'><?= htmlspecialchars($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='subcategory_name'>Subcategory Name *</label>
                        <input type='text' id='subcategory_name' name='subcategory_name' required placeholder='e.g., Password Reset, WiFi Issues'>
                    </div>
                    <div class='form-group'>
                        <label for='sub_time_estimate'>Time Estimate (minutes)</label>
                        <input type='number' id='sub_time_estimate' name='time_estimate' value='15' min='0' step='15'>
                    </div>
                    <button type='submit' class='btn btn-success'>Add Subcategory</button>
                </form>
            </div>
        </div>
        
        <!-- Categories List -->
        <div class='categories-list'>
            <div class='categories-header'>
                <h3>📋 Current Categories & Subcategories</h3>
            </div>
            
            <?php if (empty($categories)): ?>
                <div class='category-item'>
                    <p>No categories found. Add some categories to get started.</p>
                </div>
            <?php else: ?>
                <?php foreach ($categories as $category): ?>
                    <div class='category-item'>
                        <div class='category-header'>
                            <div>
                                <div class='category-name'>
                                    📁 <?= htmlspecialchars($category['name']) ?>
                                    <span class='category-status status-<?= $category['active'] ? 'active' : 'inactive' ?>'>
                                        <?= $category['active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                                <div class='category-info'>
                                    <span>⏱️ <?= $category['time_estimate_minutes'] ?> min</span>
                                    <span>🎫 <?= $stats[$category['id']] ?> tickets</span>
                                    <span>📂 <?= count($subcategories[$category['id']] ?? []) ?> subcategories</span>
                                </div>
                            </div>
                            <div class='category-actions'>
                                <button onclick="toggleEdit('cat-<?= $category['id'] ?>')" class='btn btn-primary'>✏️ Edit</button>
                                <?php if ($stats[$category['id']] == 0): ?>
                                    <form method='POST' style='display: inline;' onsubmit="return confirm('Are you sure you want to delete this category?')">
                                        <input type='hidden' name='action' value='delete_category'>
                                        <input type='hidden' name='category_id' value='<?= $category['id'] ?>'>
                                        <button type='submit' class='btn btn-danger'>🗑️ Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Edit Form -->
                        <div id='cat-<?= $category['id'] ?>' class='edit-form'>
                            <form method='POST'>
                                <input type='hidden' name='action' value='edit_category'>
                                <input type='hidden' name='category_id' value='<?= $category['id'] ?>'>
                                <div class='edit-form-row'>
                                    <div class='form-group'>
                                        <label>Category Name</label>
                                        <input type='text' name='category_name' value='<?= htmlspecialchars($category['name']) ?>' required>
                                    </div>
                                    <div class='form-group'>
                                        <label>Time (min)</label>
                                        <input type='number' name='time_estimate' value='<?= $category['time_estimate_minutes'] ?>' min='0' step='15'>
                                    </div>
                                    <div class='form-group'>
                                        <label>Status</label>
                                        <select name='active'>
                                            <option value='1' <?= $category['active'] ? 'selected' : '' ?>>Active</option>
                                            <option value='0' <?= !$category['active'] ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div class='form-group'>
                                        <button type='submit' class='btn btn-success'>💾 Save</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Subcategories -->
                        <?php if (isset($subcategories[$category['id']])): ?>
                            <div class='subcategories'>
                                <?php foreach ($subcategories[$category['id']] as $subcategory): ?>
                                    <div class='subcategory-item'>
                                        <div>
                                            <div class='subcategory-name'>
                                                📄 <?= htmlspecialchars($subcategory['name']) ?>
                                                <span class='category-status status-<?= $subcategory['active'] ? 'active' : 'inactive' ?>'>
                                                    <?= $subcategory['active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </div>
                                            <div class='subcategory-info'>
                                                <span>⏱️ <?= $subcategory['time_estimate_minutes'] ?> min</span>
                                                <span>🎫 <?= $stats[$subcategory['id']] ?> tickets</span>
                                            </div>
                                        </div>
                                        <div class='category-actions'>
                                            <button onclick="toggleEdit('subcat-<?= $subcategory['id'] ?>')" class='btn btn-primary'>✏️ Edit</button>
                                            <?php if ($stats[$subcategory['id']] == 0): ?>
                                                <form method='POST' style='display: inline;' onsubmit="return confirm('Are you sure you want to delete this subcategory?')">
                                                    <input type='hidden' name='action' value='delete_category'>
                                                    <input type='hidden' name='category_id' value='<?= $subcategory['id'] ?>'>
                                                    <button type='submit' class='btn btn-danger'>🗑️</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Subcategory Edit Form -->
                                    <div id='subcat-<?= $subcategory['id'] ?>' class='edit-form'>
                                        <form method='POST'>
                                            <input type='hidden' name='action' value='edit_category'>
                                            <input type='hidden' name='category_id' value='<?= $subcategory['id'] ?>'>
                                            <div class='edit-form-row'>
                                                <div class='form-group'>
                                                    <label>Subcategory Name</label>
                                                    <input type='text' name='category_name' value='<?= htmlspecialchars($subcategory['name']) ?>' required>
                                                </div>
                                                <div class='form-group'>
                                                    <label>Time (min)</label>
                                                    <input type='number' name='time_estimate' value='<?= $subcategory['time_estimate_minutes'] ?>' min='0' step='15'>
                                                </div>
                                                <div class='form-group'>
                                                    <label>Status</label>
                                                    <select name='active'>
                                                        <option value='1' <?= $subcategory['active'] ? 'selected' : '' ?>>Active</option>
                                                        <option value='0' <?= !$subcategory['active'] ? 'selected' : '' ?>>Inactive</option>
                                                    </select>
                                                </div>
                                                <div class='form-group'>
                                                    <button type='submit' class='btn btn-success'>💾 Save</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
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
    </script>
</body>
</html>