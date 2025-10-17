<?php
session_start();

// Check if user is logged in and has appropriate permissions
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
    
    if (!$user || !in_array($user['role'], ['admin', 'supervisor'])) {
        die("Access denied. Admin or Supervisor permissions required.");
    }
    
    // Get all organizations for the dropdown
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE active = 1 ORDER BY name");
    $stmt->execute();
    $organizations = $stmt->fetchAll();
    
    // Get recent reports
    $stmt = $pdo->prepare("
        SELECT cr.*, o.name as org_name, o.code as org_code, u.name as generated_by 
        FROM customer_reports cr 
        JOIN organizations o ON cr.organization_id = o.id 
        JOIN users u ON cr.generated_by_user_id = u.id 
        ORDER BY cr.generated_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_reports = $stmt->fetchAll();
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Customer Reports - ITSPtickets</title>
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
        
        .report-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group label {
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        
        .form-group select,
        .form-group input {
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
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
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .reports-table th,
        .reports-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .reports-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        
        .reports-table tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .badge-monthly { background: #dbeafe; color: #1e40af; }
        .badge-quarterly { background: #d1fae5; color: #065f46; }
        .badge-custom { background: #fef3c7; color: #92400e; }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .quick-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>📊 Customer Reports</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <span class='role'><?= htmlspecialchars($user['role']) ?></span>
                <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
                <a href='/ITSPtickets/logout.php'>Logout</a>
            </div>
        </div>
        
        <!-- Generate New Report Section -->
        <div class='report-section'>
            <div class='section-title'>🎯 Generate New Report</div>
            <form action='customer-report-generator.php' method='POST'>
                <div class='form-grid'>
                    <div class='form-group'>
                        <label for='organization_id'>🏢 Organization</label>
                        <select id='organization_id' name='organization_id' required>
                            <option value=''>Select Organization</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value='<?= $org['id'] ?>'><?= htmlspecialchars($org['name']) ?> (<?= htmlspecialchars($org['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class='form-group'>
                        <label for='report_type'>📋 Report Type</label>
                        <select id='report_type' name='report_type' required onchange='toggleDateFields()'>
                            <option value=''>Select Report Type</option>
                            <option value='monthly'>Monthly Report</option>
                            <option value='quarterly'>Quarterly Report</option>
                            <option value='custom'>Custom Date Range</option>
                        </select>
                    </div>
                    
                    <div class='form-group' id='year_month_group' style='display: none;'>
                        <label for='year_month'>📅 Year & Month</label>
                        <input type='month' id='year_month' name='year_month' value='2024-08'>
                    </div>
                    
                    <div class='form-group' id='year_quarter_group' style='display: none;'>
                        <label for='year_quarter'>📅 Year & Quarter</label>
                        <select id='year_quarter' name='year_quarter'>
                            <?php
                            $current_year = date('Y');
                            for ($year = $current_year; $year >= 2024; $year--):
                                for ($quarter = 4; $quarter >= 1; $quarter--):
                            ?>
                                <option value='<?= $year ?>-Q<?= $quarter ?>' <?= ($year == 2024 && $quarter == 3) ? 'selected' : '' ?>>
                                    <?= $year ?> Q<?= $quarter ?>
                                </option>
                            <?php endfor; endfor; ?>
                        </select>
                    </div>
                    
                    <div class='form-group' id='start_date_group' style='display: none;'>
                        <label for='start_date'>📅 Start Date</label>
                        <input type='date' id='start_date' name='start_date' value='2024-08-01'>
                    </div>
                    
                    <div class='form-group' id='end_date_group' style='display: none;'>
                        <label for='end_date'>📅 End Date</label>
                        <input type='date' id='end_date' name='end_date' value='2024-08-31'>
                    </div>
                </div>
                
                <div class='quick-actions'>
                    <button type='submit' name='format' value='html' class='btn btn-primary'>🖥️ Generate HTML Report</button>
                    <button type='submit' name='format' value='pdf' class='btn btn-secondary'>📄 Generate PDF Report</button>
                </div>
            </form>
        </div>
        
        <!-- Recent Reports Section -->
        <div class='report-section'>
            <div class='section-title'>📋 Recent Reports</div>
            
            <?php if (empty($recent_reports)): ?>
                <p>No reports have been generated yet.</p>
            <?php else: ?>
                <table class='reports-table'>
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Period</th>
                            <th>Type</th>
                            <th>Tickets</th>
                            <th>Hours</th>
                            <th>Generated By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_reports as $report): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($report['org_name']) ?></strong>
                                <br><small><?= htmlspecialchars($report['org_code']) ?></small>
                            </td>
                            <td>
                                <?= date('M j', strtotime($report['period_start'])) ?> - 
                                <?= date('M j, Y', strtotime($report['period_end'])) ?>
                            </td>
                            <td>
                                <span class='badge badge-<?= $report['report_type'] ?>'>
                                    <?= htmlspecialchars($report['report_type']) ?>
                                </span>
                            </td>
                            <td><?= number_format($report['total_tickets']) ?></td>
                            <td><?= number_format($report['total_hours'], 2) ?></td>
                            <td><?= htmlspecialchars($report['generated_by']) ?></td>
                            <td><?= date('M j, Y g:i A', strtotime($report['generated_at'])) ?></td>
                            <td>
                                <a href='customer-report-view.php?id=<?= $report['id'] ?>' class='btn btn-primary' style='padding: 6px 12px; font-size: 12px;'>View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleDateFields() {
            const reportType = document.getElementById('report_type').value;
            const yearMonthGroup = document.getElementById('year_month_group');
            const yearQuarterGroup = document.getElementById('year_quarter_group');
            const startDateGroup = document.getElementById('start_date_group');
            const endDateGroup = document.getElementById('end_date_group');
            
            // Hide all date fields first
            yearMonthGroup.style.display = 'none';
            yearQuarterGroup.style.display = 'none';
            startDateGroup.style.display = 'none';
            endDateGroup.style.display = 'none';
            
            // Show appropriate fields based on report type
            switch(reportType) {
                case 'monthly':
                    yearMonthGroup.style.display = 'flex';
                    break;
                case 'quarterly':
                    yearQuarterGroup.style.display = 'flex';
                    break;
                case 'custom':
                    startDateGroup.style.display = 'flex';
                    endDateGroup.style.display = 'flex';
                    break;
            }
        }
        
        // Set default dates for custom range (already set in HTML)
        document.addEventListener('DOMContentLoaded', function() {
            // Default dates are already set to August 2024 in the HTML
            // This ensures we're looking at the period where sample data exists
        });
    </script>
</body>
</html>