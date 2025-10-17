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
    
    if (!$user || !in_array($user['role'], ['admin', 'supervisor', 'agent'])) {
        die("Access denied. Staff permissions required.");
    }
    
    // Get some quick stats for display
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tickets");
    $totalTickets = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT requester_id) as count FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $activeRequesters = $stmt->fetch()['count'];
    
    // Check if customer reports exist (for admin/supervisor)
    $customerReportsCount = 0;
    if (in_array($user['role'], ['admin', 'supervisor'])) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM customer_reports");
        $customerReportsCount = $stmt->fetch()['count'];
    }
    
    // Get available organizations for customer reports
    $organizations = [];
    if (in_array($user['role'], ['admin', 'supervisor'])) {
        $stmt = $pdo->query("SELECT * FROM organizations WHERE active = 1 ORDER BY name");
        $organizations = $stmt->fetchAll();
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
    <title>Reports - ITSPtickets</title>
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
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }
        
        .report-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .report-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.15);
        }
        
        .section-header {
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            color: white;
            padding: 25px;
            text-align: center;
        }
        
        .section-icon {
            font-size: 48px;
            margin-bottom: 10px;
            display: block;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .section-description {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .section-content {
            padding: 25px;
        }
        
        .section-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            display: block;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 500;
            margin-top: 5px;
        }
        
        .section-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            text-decoration: none;
            color: #1f2937;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            background: #f1f5f9;
            border-color: #3b82f6;
            color: #3b82f6;
            text-decoration: none;
        }
        
        .action-icon {
            font-size: 20px;
            margin-right: 12px;
        }
        
        .action-text {
            flex: 1;
        }
        
        .action-arrow {
            color: #9ca3af;
            font-size: 16px;
        }
        
        .action-btn:hover .action-arrow {
            color: #3b82f6;
        }
        
        /* Color variations for different sections */
        .internal-reports .section-header {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .customer-reports .section-header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .analytics-section .section-header {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        
        .exports-section .section-header {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .quick-actions {
            background: #f0f9ff;
            border: 2px solid #bfdbfe;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .quick-actions h3 {
            color: #1e40af;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: #1f2937;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .quick-action-btn:hover {
            background: #f8fafc;
            border-color: #3b82f6;
            color: #3b82f6;
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            .reports-grid { 
                grid-template-columns: 1fr; 
            }
            .section-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>📊 Reports & Analytics</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <span style='background: #3b82f6; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; text-transform: uppercase; font-weight: 500;'>
                    <?= htmlspecialchars($user['role']) ?>
                </span>
                <a href='/ITSPtickets/dashboard-simple.php'>← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class='quick-actions'>
            <h3>⚡ Quick Actions</h3>
            <div class='quick-actions-grid'>
                <a href='/ITSPtickets/reports-simple.php?report_type=summary' class='quick-action-btn'>
                    <span>📈</span>
                    <span>Today's Summary</span>
                </a>
                <a href='/ITSPtickets/reports-simple.php?report_type=sla' class='quick-action-btn'>
                    <span>⏰</span>
                    <span>SLA Status</span>
                </a>
                <a href='/ITSPtickets/export-csv.php?type=tickets' class='quick-action-btn'>
                    <span>💾</span>
                    <span>Export All Tickets</span>
                </a>
                <?php if (in_array($user['role'], ['admin', 'supervisor'])): ?>
                <a href='/ITSPtickets/customer-reports.php' class='quick-action-btn'>
                    <span>🏢</span>
                    <span>Generate Customer Report</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class='reports-grid'>
            <!-- Internal Reports Section -->
            <div class='report-section internal-reports'>
                <div class='section-header'>
                    <span class='section-icon'>📋</span>
                    <h2 class='section-title'>Internal Reports</h2>
                    <p class='section-description'>Team performance, ticket analytics, and operational metrics</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= number_format($totalTickets) ?></span>
                            <span class='stat-label'>Total Tickets</span>
                        </div>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $activeRequesters ?></span>
                            <span class='stat-label'>Active Users</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/reports-simple.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📊</span>
                                <span class='action-text'>Ticket Analytics</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/reports-simple.php?report_type=performance' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>⚡</span>
                                <span class='action-text'>Team Performance</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/reports-simple.php?report_type=sla' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>⏱️</span>
                                <span class='action-text'>SLA Compliance</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if (in_array($user['role'], ['admin', 'supervisor'])): ?>
            <!-- Customer Reports Section -->
            <div class='report-section customer-reports'>
                <div class='section-header'>
                    <span class='section-icon'>🏢</span>
                    <h2 class='section-title'>Customer Reports</h2>
                    <p class='section-description'>Professional client reports and billing summaries</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $customerReportsCount ?></span>
                            <span class='stat-label'>Generated</span>
                        </div>
                        <div class='stat-item'>
                            <span class='stat-number'><?= count($organizations) ?></span>
                            <span class='stat-label'>Organizations</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/customer-reports.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📄</span>
                                <span class='action-text'>Generate New Report</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/customer-report-history.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📁</span>
                                <span class='action-text'>Report History</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Data Export Section -->
            <div class='report-section exports-section'>
                <div class='section-header'>
                    <span class='section-icon'>💾</span>
                    <h2 class='section-title'>Data Exports</h2>
                    <p class='section-description'>Export data in various formats for external analysis</p>
                </div>
                <div class='section-content'>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/export-csv.php?type=tickets' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>🎫</span>
                                <span class='action-text'>Export Tickets (CSV)</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/export-csv.php?type=users' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>👥</span>
                                <span class='action-text'>Export Users (CSV)</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/export-csv.php?type=sla_performance' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📈</span>
                                <span class='action-text'>SLA Performance (CSV)</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Analytics Section -->
            <div class='report-section analytics-section'>
                <div class='section-header'>
                    <span class='section-icon'>📈</span>
                    <h2 class='section-title'>Advanced Analytics</h2>
                    <p class='section-description'>Detailed insights and trend analysis</p>
                </div>
                <div class='section-content'>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/reports-simple.php?report_type=trends' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📊</span>
                                <span class='action-text'>Ticket Trends</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/reports-simple.php?report_type=categories' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>🗂️</span>
                                <span class='action-text'>Category Analysis</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/reports-simple.php?report_type=workload' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>⚖️</span>
                                <span class='action-text'>Workload Distribution</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>