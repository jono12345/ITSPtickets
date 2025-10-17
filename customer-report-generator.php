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
    
    // Validate POST data
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: customer-reports.php');
        exit;
    }
    
    $organization_id = intval($_POST['organization_id']);
    $report_type = $_POST['report_type'];
    $format = $_POST['format'] ?? 'html';
    
    // Get organization details
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? AND active = 1");
    $stmt->execute([$organization_id]);
    $organization = $stmt->fetch();
    
    if (!$organization) {
        die("Organization not found or inactive.");
    }
    
    // Calculate date range based on report type
    switch ($report_type) {
        case 'monthly':
            $year_month = $_POST['year_month'];
            $start_date = $year_month . '-01';
            $end_date = date('Y-m-t', strtotime($start_date));
            $period_name = date('F Y', strtotime($start_date));
            break;
            
        case 'quarterly':
            $year_quarter = $_POST['year_quarter'];
            list($year, $quarter) = explode('-Q', $year_quarter);
            $quarter = intval($quarter);
            
            $quarter_months = [
                1 => ['01', '03'],
                2 => ['04', '06'],
                3 => ['07', '09'],
                4 => ['10', '12']
            ];
            
            $start_date = $year . '-' . $quarter_months[$quarter][0] . '-01';
            $end_date = $year . '-' . $quarter_months[$quarter][1] . '-' . date('t', strtotime($year . '-' . $quarter_months[$quarter][1] . '-01'));
            $period_name = "Q{$quarter} {$year}";
            break;
            
        case 'custom':
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $period_name = date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date));
            break;
            
        default:
            die("Invalid report type.");
    }
    
    // Generate report data
    $report_data = generateReportData($pdo, $organization_id, $start_date, $end_date);
    
    // Save report to database
    $stmt = $pdo->prepare("
        INSERT INTO customer_reports (
            organization_id, report_type, period_start, period_end, 
            total_tickets, total_hours, billable_hours, prepaid_hours_used, 
            prepaid_hours_remaining, generated_by_user_id, report_data
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $prepaid_hours_used = $report_data['total_hours'];
    $monthly_allowance = $organization['monthly_hours_allowance'];
    $quarterly_allowance = $organization['quarterly_hours_allowance'];
    
    // Calculate remaining hours based on report type
    if ($report_type === 'monthly') {
        $prepaid_hours_remaining = max(0, $monthly_allowance - $prepaid_hours_used);
    } elseif ($report_type === 'quarterly') {
        $prepaid_hours_remaining = max(0, $quarterly_allowance - $prepaid_hours_used);
    } else {
        $prepaid_hours_remaining = 0; // Custom reports don't use allowance
    }
    
    $stmt->execute([
        $organization_id,
        $report_type,
        $start_date,
        $end_date,
        $report_data['ticket_count'],
        $report_data['total_hours'],
        $report_data['total_hours'], // Assuming all hours are billable for now
        $prepaid_hours_used,
        $prepaid_hours_remaining,
        $user['id'],
        json_encode($report_data)
    ]);
    
    $report_id = $pdo->lastInsertId();
    
    // Generate and display the report
    if ($format === 'pdf') {
        // For now, redirect to HTML version - PDF generation can be added later
        header("Location: customer-report-view.php?id={$report_id}&format=pdf");
    } else {
        header("Location: customer-report-view.php?id={$report_id}");
    }
    exit;
    
} catch (Exception $e) {
    die("Error generating report: " . $e->getMessage());
}

function generateReportData($pdo, $organization_id, $start_date, $end_date) {
    // Get all tickets for the organization in the date range
    $stmt = $pdo->prepare("
        SELECT t.*, r.name as requester_name, r.email as requester_email,
               u.name as assignee_name,
               tc.name as category_name,
               tsc.name as subcategory_name
        FROM tickets t
        JOIN requesters r ON t.requester_id = r.id
        LEFT JOIN users u ON t.assignee_id = u.id
        LEFT JOIN ticket_categories tc ON t.category_id = tc.id
        LEFT JOIN ticket_categories tsc ON t.subcategory_id = tsc.id
        WHERE r.organization_id = ?
        AND t.created_at >= ?
        AND t.created_at <= ?
        AND t.status IN ('resolved', 'closed')
        ORDER BY t.created_at ASC
    ");
    
    $stmt->execute([$organization_id, $start_date, $end_date . ' 23:59:59']);
    $tickets = $stmt->fetchAll();
    
    // Aggregate data by category
    $category_summary = [];
    $total_hours = 0;
    $ticket_details = [];
    
    foreach ($tickets as $ticket) {
        // Use category name, fallback to ticket type if no category
        $category_display = $ticket['category_name'] ?: ucfirst($ticket['type']);
        if ($ticket['subcategory_name']) {
            $category_display .= ' > ' . $ticket['subcategory_name'];
        }
        
        $hours = floatval($ticket['time_spent'] ?: $ticket['billable_hours'] ?: 0);
        
        // Add to category summary
        if (!isset($category_summary[$category_display])) {
            $category_summary[$category_display] = [
                'tickets' => 0,
                'hours' => 0
            ];
        }
        
        $category_summary[$category_display]['tickets']++;
        $category_summary[$category_display]['hours'] += $hours;
        $total_hours += $hours;
        
        // Add to ticket details
        $ticket_details[] = [
            'ticket_key' => $ticket['key'],
            'subject' => $ticket['subject'],
            'from' => $ticket['requester_name'],
            'category' => $category_display,
            'time' => $hours,
            'created_at' => $ticket['created_at']
        ];
    }
    
    return [
        'ticket_count' => count($tickets),
        'total_hours' => $total_hours,
        'category_summary' => $category_summary,
        'ticket_details' => $ticket_details,
        'period_start' => $start_date,
        'period_end' => $end_date
    ];
}
?>