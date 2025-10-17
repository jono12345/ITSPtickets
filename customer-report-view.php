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
    
    // Get report ID
    $report_id = intval($_GET['id'] ?? 0);
    if (!$report_id) {
        die("Report ID required.");
    }
    
    // Get report data
    $stmt = $pdo->prepare("
        SELECT cr.*, o.name as org_name, o.code as org_code, o.monthly_hours_allowance,
               o.quarterly_hours_allowance, u.name as generated_by
        FROM customer_reports cr
        JOIN organizations o ON cr.organization_id = o.id
        JOIN users u ON cr.generated_by_user_id = u.id
        WHERE cr.id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();
    
    if (!$report) {
        die("Report not found.");
    }
    
    // Decode report data
    $report_data = json_decode($report['report_data'], true);
    $format = $_GET['format'] ?? 'html';
    
    // Format period name
    if ($report['report_type'] === 'monthly') {
        $period_name = date('M Y', strtotime($report['period_start']));
    } elseif ($report['report_type'] === 'quarterly') {
        $year = date('Y', strtotime($report['period_start']));
        $start_month = date('n', strtotime($report['period_start']));
        $quarter = ceil($start_month / 3);
        $period_name = "Q{$quarter} {$year}";
    } else {
        $period_name = date('M j', strtotime($report['period_start'])) . ' - ' . date('M j, Y', strtotime($report['period_end']));
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
    <title>Customer Report - <?= htmlspecialchars($report['org_name']) ?> - <?= htmlspecialchars($period_name) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: Arial, sans-serif; 
            background: #f5f5f5;
            color: #333;
            line-height: 1.4;
        }
        
        .no-print {
            background: white;
            padding: 20px;
            margin: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .print-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            display: inline-block;
        }
        
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn:hover { opacity: 0.9; }
        
        /* Report Styles */
        .report-container {
            background: white;
            max-width: 210mm;
            margin: 0 auto;
            padding: 20mm;
            min-height: 297mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            border-bottom: 3px solid #0ea5e9;
            padding-bottom: 20px;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-placeholder {
            width: 60px;
            height: 40px;
            background: #0ea5e9;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #0f172a;
        }
        
        .company-tagline {
            font-size: 14px;
            color: #64748b;
            font-style: italic;
        }
        
        .report-pattern {
            width: 200px;
            height: 60px;
            background: linear-gradient(45deg, #0ea5e9 25%, transparent 25%, transparent 75%, #0ea5e9 75%), 
                        linear-gradient(45deg, #0ea5e9 25%, transparent 25%, transparent 75%, #0ea5e9 75%);
            background-size: 10px 10px;
            background-position: 0 0, 5px 5px;
            opacity: 0.1;
        }
        
        .report-title {
            font-size: 28px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 10px;
        }
        
        .report-period {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 40px;
        }
        
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: white;
        }
        
        .summary-table th {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            font-size: 14px;
            color: #374151;
        }
        
        .summary-table td {
            border: 1px solid #e2e8f0;
            padding: 12px;
            font-size: 14px;
        }
        
        .summary-table .total-row {
            background: #f1f5f9;
            font-weight: bold;
        }
        
        .summary-table .allowance-row {
            background: #fef3c7;
            font-style: italic;
        }
        
        .details-section {
            margin-top: 40px;
        }
        
        .details-title {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 20px;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .details-table th {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            color: #374151;
        }
        
        .details-table td {
            border: 1px solid #e2e8f0;
            padding: 8px;
            vertical-align: top;
        }
        
        .details-table tr:nth-child(even) {
            background: #fafbfc;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        /* Print Styles */
        @media print {
            body { background: white; margin: 0; }
            .no-print { display: none !important; }
            .report-container { 
                margin: 0; 
                padding: 15mm;
                box-shadow: none; 
                max-width: none;
                min-height: auto;
            }
        }
        
        @page {
            size: A4;
            margin: 15mm;
        }
    </style>
</head>
<body>
    <div class='no-print'>
        <div class='print-actions'>
            <button onclick='window.print()' class='btn btn-primary'>🖨️ Print Report</button>
            <a href='customer-reports.php' class='btn btn-secondary'>⬅️ Back to Reports</a>
        </div>
    </div>

    <div class='report-container'>
        <!-- Report Header -->
        <div class='report-header'>
            <div class='logo-section'>
                <div class='logo-placeholder'>IT</div>
                <div>
                    <div class='company-name'>IT Support Partners</div>
                    <div class='company-tagline'>Professional IT Solutions</div>
                </div>
            </div>
            <div class='report-pattern'></div>
        </div>
        
        <!-- Report Title -->
        <div class='report-title'>Ticket Report for <?= htmlspecialchars($report['org_code']) ?></div>
        <div class='report-period'>Month: <?= htmlspecialchars($period_name) ?></div>
        
        <!-- Summary Table -->
        <table class='summary-table'>
            <thead>
                <tr>
                    <th style='width: 40%;'></th>
                    <th style='width: 30%; text-align: center;'>Tickets</th>
                    <th style='width: 30%; text-align: center;'>Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Sort categories alphabetically for better organization
                $ordered_categories = $report_data['category_summary'];
                ksort($ordered_categories);
                
                foreach ($ordered_categories as $category => $data): ?>
                <tr>
                    <td><?= htmlspecialchars($category) ?></td>
                    <td class='text-center'><?= number_format($data['tickets']) ?></td>
                    <td class='text-center'><?= number_format($data['hours'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class='total-row'>
                    <td><strong>Total</strong></td>
                    <td class='text-center'><strong><?= number_format($report['total_tickets']) ?></strong></td>
                    <td class='text-center'><strong><?= number_format($report['total_hours'], 2) ?></strong></td>
                </tr>
                
                <?php if ($report['report_type'] !== 'custom'): ?>
                <tr class='allowance-row'>
                    <td><strong>Prebooked</strong></td>
                    <td class='text-center'></td>
                    <td class='text-center'><strong><?= number_format($report['prepaid_hours_used'], 1) ?></strong></td>
                </tr>
                <tr class='allowance-row'>
                    <td><strong>Remaining</strong></td>
                    <td class='text-center'></td>
                    <td class='text-center'><strong><?= number_format($report['prepaid_hours_remaining'], 1) ?></strong></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Ticket Details -->
        <div class='details-section'>
            <div class='details-title'>Ticket Details</div>
            
            <table class='details-table'>
                <thead>
                    <tr>
                        <th style='width: 10%;'>#</th>
                        <th style='width: 12%;'>Ticket</th>
                        <th style='width: 35%;'>Subject</th>
                        <th style='width: 20%;'>From</th>
                        <th style='width: 13%;'>Category</th>
                        <th style='width: 10%;'>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; ?>
                    <?php foreach ($report_data['ticket_details'] as $ticket): ?>
                    <tr>
                        <td class='text-center'><?= $counter++ ?></td>
                        <td><?= htmlspecialchars($ticket['ticket_key']) ?></td>
                        <td><?= htmlspecialchars($ticket['subject']) ?></td>
                        <td><?= htmlspecialchars($ticket['from']) ?></td>
                        <td><?= htmlspecialchars($ticket['category']) ?></td>
                        <td class='text-center'><?= number_format($ticket['time'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style='margin-top: 40px; font-size: 12px; color: #64748b; text-align: center;'>
            Report generated on <?= date('F j, Y \a\t g:i A') ?> by <?= htmlspecialchars($report['generated_by']) ?>
        </div>
    </div>
</body>
</html>