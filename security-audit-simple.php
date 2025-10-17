<?php

class SecurityAuditSimple
{
    private $pdo;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Log security events
     */
    public function logSecurityEvent($eventType, $description, $userId = null, $ipAddress = null, $userAgent = null, $severity = 'info')
    {
        $ipAddress = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $userAgent = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (
                event_type, description, user_id, ip_address, user_agent, 
                severity, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        return $stmt->execute([
            $eventType,
            $description,
            $userId,
            $ipAddress,
            $userAgent,
            $severity
        ]);
    }
    
    /**
     * Log user authentication events
     */
    public function logAuthEvent($eventType, $email, $userId = null, $success = true)
    {
        $description = $success ? 
            "User authentication successful: {$email}" : 
            "User authentication failed: {$email}";
        
        $severity = $success ? 'info' : 'warning';
        
        return $this->logSecurityEvent($eventType, $description, $userId, null, null, $severity);
    }
    
    /**
     * Log data access events
     */
    public function logDataAccess($resourceType, $resourceId, $action, $userId, $details = null)
    {
        $description = "Data access: {$action} on {$resourceType} #{$resourceId}";
        if ($details) {
            $description .= " - {$details}";
        }
        
        return $this->logSecurityEvent('data_access', $description, $userId);
    }
    
    /**
     * Log system configuration changes
     */
    public function logConfigChange($configType, $oldValue, $newValue, $userId)
    {
        $description = "Configuration changed: {$configType} from '{$oldValue}' to '{$newValue}'";
        
        return $this->logSecurityEvent('config_change', $description, $userId, null, null, 'high');
    }
    
    /**
     * Check for suspicious activity
     */
    public function detectSuspiciousActivity()
    {
        $suspiciousEvents = [];
        
        // Check for multiple failed logins from same IP
        $stmt = $this->pdo->prepare("
            SELECT ip_address, COUNT(*) as attempts, MAX(created_at) as last_attempt
            FROM audit_log 
            WHERE event_type = 'login_failed' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY ip_address
            HAVING attempts >= 5
        ");
        $stmt->execute();
        $failedLogins = $stmt->fetchAll();
        
        foreach ($failedLogins as $attempt) {
            $suspiciousEvents[] = [
                'type' => 'multiple_failed_logins',
                'description' => "Multiple failed login attempts from IP: {$attempt['ip_address']}",
                'details' => $attempt,
                'severity' => 'high'
            ];
        }
        
        // Check for unusual data access patterns
        $stmt = $this->pdo->prepare("
            SELECT user_id, COUNT(*) as access_count, u.name
            FROM audit_log al
            JOIN users u ON al.user_id = u.id
            WHERE al.event_type = 'data_access' 
            AND al.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY user_id
            HAVING access_count > 100
        ");
        $stmt->execute();
        $highAccess = $stmt->fetchAll();
        
        foreach ($highAccess as $access) {
            $suspiciousEvents[] = [
                'type' => 'high_data_access',
                'description' => "Unusual high data access by user: {$access['name']}",
                'details' => $access,
                'severity' => 'medium'
            ];
        }
        
        // Check for after-hours access
        $stmt = $this->pdo->prepare("
            SELECT al.*, u.name
            FROM audit_log al
            JOIN users u ON al.user_id = u.id
            WHERE al.event_type IN ('login_success', 'data_access')
            AND al.created_at > DATE_SUB(NOW(), INTERVAL 24 HOURS)
            AND (HOUR(al.created_at) < 7 OR HOUR(al.created_at) > 19)
            AND WEEKDAY(al.created_at) < 5
        ");
        $stmt->execute();
        $afterHours = $stmt->fetchAll();
        
        if (!empty($afterHours)) {
            $suspiciousEvents[] = [
                'type' => 'after_hours_access',
                'description' => "After-hours system access detected",
                'details' => $afterHours,
                'severity' => 'medium'
            ];
        }
        
        return $suspiciousEvents;
    }
    
    /**
     * Get security statistics
     */
    public function getSecurityStats($dateFrom = null, $dateTo = null)
    {
        $dateFrom = $dateFrom ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo = $dateTo ?: date('Y-m-d');
        
        $stats = [];
        
        // Total events
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as total_events,
                   SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_severity,
                   SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warnings,
                   SUM(CASE WHEN event_type = 'login_failed' THEN 1 ELSE 0 END) as failed_logins,
                   SUM(CASE WHEN event_type = 'login_success' THEN 1 ELSE 0 END) as successful_logins
            FROM audit_log 
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['overview'] = $stmt->fetch();
        
        // Events by type
        $stmt = $this->pdo->prepare("
            SELECT event_type, COUNT(*) as count
            FROM audit_log 
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY event_type
            ORDER BY count DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['by_type'] = $stmt->fetchAll();
        
        // Events by user
        $stmt = $this->pdo->prepare("
            SELECT u.name, u.email, COUNT(*) as event_count
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE DATE(al.created_at) BETWEEN ? AND ?
            AND al.user_id IS NOT NULL
            GROUP BY al.user_id, u.name, u.email
            ORDER BY event_count DESC
            LIMIT 10
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['by_user'] = $stmt->fetchAll();
        
        // Top IP addresses
        $stmt = $this->pdo->prepare("
            SELECT ip_address, COUNT(*) as request_count,
                   SUM(CASE WHEN event_type = 'login_failed' THEN 1 ELSE 0 END) as failed_attempts
            FROM audit_log 
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY ip_address
            ORDER BY request_count DESC
            LIMIT 10
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['by_ip'] = $stmt->fetchAll();
        
        return $stats;
    }
    
    /**
     * Get detailed audit log
     */
    public function getAuditLog($filters = [], $limit = 100, $offset = 0)
    {
        $sql = "SELECT al.*, u.name as user_name, u.email as user_email
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['event_type'])) {
            $sql .= " AND al.event_type = ?";
            $params[] = $filters['event_type'];
        }
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['severity'])) {
            $sql .= " AND al.severity = ?";
            $params[] = $filters['severity'];
        }
        
        if (!empty($filters['ip_address'])) {
            $sql .= " AND al.ip_address = ?";
            $params[] = $filters['ip_address'];
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Clean old audit logs
     */
    public function cleanOldLogs($days = 90)
    {
        $stmt = $this->pdo->prepare("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Export audit log to CSV
     */
    public function exportAuditLog($filters = [], $filename = null)
    {
        $filename = $filename ?: 'audit_log_' . date('Y-m-d_H-i-s') . '.csv';
        
        $logs = $this->getAuditLog($filters, 10000); // Export up to 10k records
        
        $output = fopen('php://temp', 'w');
        
        // CSV headers
        fputcsv($output, [
            'ID', 'Event Type', 'Description', 'User', 'Email', 
            'IP Address', 'User Agent', 'Severity', 'Date/Time'
        ]);
        
        // CSV data
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['event_type'],
                $log['description'],
                $log['user_name'] ?: 'System',
                $log['user_email'] ?: '',
                $log['ip_address'],
                $log['user_agent'],
                $log['severity'],
                $log['created_at']
            ]);
        }
        
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        return [
            'filename' => $filename,
            'content' => $csvContent,
            'records' => count($logs)
        ];
    }
    
    /**
     * Security dashboard data
     */
    public function getDashboardData()
    {
        $data = [];
        
        // Recent high-severity events
        $stmt = $this->pdo->prepare("
            SELECT al.*, u.name as user_name
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.severity IN ('high', 'critical')
            ORDER BY al.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $data['recent_high_severity'] = $stmt->fetchAll();
        
        // Failed login attempts in last hour
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM audit_log 
            WHERE event_type = 'login_failed' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $data['recent_failed_logins'] = $stmt->fetch()['count'];
        
        // Active sessions
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT user_id) as count
            FROM audit_log 
            WHERE event_type = 'login_success' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 4 HOUR)
        ");
        $stmt->execute();
        $data['active_sessions'] = $stmt->fetch()['count'];
        
        // Today's events summary
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_events,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_severity_count,
                SUM(CASE WHEN event_type = 'login_failed' THEN 1 ELSE 0 END) as failed_login_count
            FROM audit_log 
            WHERE DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $data['today_summary'] = $stmt->fetch();
        
        return $data;
    }
}

// Security helper functions that can be called from throughout the application
function logSecurityEvent($eventType, $description, $userId = null, $severity = 'info')
{
    try {
        require_once 'config/database.php';
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $security = new SecurityAuditSimple($pdo);
        return $security->logSecurityEvent($eventType, $description, $userId, null, null, $severity);
        
    } catch (Exception $e) {
        error_log("Security logging error: " . $e->getMessage());
        return false;
    }
}

function logAuthEvent($eventType, $email, $userId = null, $success = true)
{
    try {
        require_once 'config/database.php';
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $security = new SecurityAuditSimple($pdo);
        return $security->logAuthEvent($eventType, $email, $userId, $success);
        
    } catch (Exception $e) {
        error_log("Auth logging error: " . $e->getMessage());
        return false;
    }
}

// Security dashboard and management interface
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    session_start();
    
    // Check if user is logged in and has admin privileges
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
            http_response_code(403);
            die('Access denied - Admin privileges required');
        }
        
        $security = new SecurityAuditSimple($pdo);
        
        // Handle actions
        $message = '';
        if ($_POST['action'] ?? '') {
            switch ($_POST['action']) {
                case 'export_log':
                    $export = $security->exportAuditLog($_POST);
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
                    echo $export['content'];
                    exit;
                    break;
                    
                case 'clean_logs':
                    $days = intval($_POST['days'] ?? 90);
                    $deleted = $security->cleanOldLogs($days);
                    $message = "Cleaned {$deleted} old log entries (older than {$days} days)";
                    logSecurityEvent('log_cleanup', $message, $user['id'], 'info');
                    break;
            }
        }
        
        // Get data for display
        $dashboardData = $security->getDashboardData();
        $stats = $security->getSecurityStats();
        $recentLogs = $security->getAuditLog([], 20);
        $suspiciousActivity = $security->detectSuspiciousActivity();
        
        ?>
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <title>Security & Audit - ITSPtickets</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                    background: #f8fafc; 
                    line-height: 1.6;
                }
                .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
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
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                .stat-card {
                    background: white;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .stat-card h3 { color: #6b7280; font-size: 14px; margin-bottom: 10px; }
                .stat-number { font-size: 32px; font-weight: bold; }
                .stat-number.critical { color: #dc2626; }
                .stat-number.warning { color: #d97706; }
                .stat-number.info { color: #059669; }
                .section {
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    margin-bottom: 30px;
                    overflow: hidden;
                }
                .section-header {
                    padding: 20px;
                    border-bottom: 1px solid #e5e7eb;
                    background: #f9fafb;
                }
                .section-content { padding: 20px; }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 14px;
                }
                table th,
                table td {
                    text-align: left;
                    padding: 8px 12px;
                    border-bottom: 1px solid #e5e7eb;
                }
                table th {
                    background: #f9fafb;
                    font-weight: 600;
                    color: #374151;
                }
                .severity-critical { color: #dc2626; font-weight: 600; }
                .severity-high { color: #ea580c; font-weight: 600; }
                .severity-warning { color: #d97706; }
                .severity-info { color: #059669; }
                .alert {
                    background: #fee2e2;
                    color: #991b1b;
                    border: 1px solid #fca5a5;
                    padding: 15px;
                    border-radius: 4px;
                    margin-bottom: 20px;
                }
                .alert.warning { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
                .btn { 
                    padding: 8px 16px; 
                    border-radius: 4px; 
                    border: none; 
                    background: #3b82f6; 
                    color: white; 
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                    margin-right: 10px;
                }
                .btn:hover { opacity: 0.9; }
                .btn-danger { background: #dc2626; }
                .form-inline { display: flex; gap: 10px; align-items: end; }
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
                    padding: 6px;
                    border: 1px solid #d1d5db;
                    border-radius: 4px;
                }
                .message { background: #dcfce7; color: #166534; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Security & Audit Dashboard</h1>
                    <div class='user-info'>
                        <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                        <a href='/ITSPtickets/reports-simple.php'>Reports</a>
                        <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
                        <a href='/ITSPtickets/logout.php'>Logout</a>
                    </div>
                </div>
                
                <?php if ($message): ?>
                    <div class='message'><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($suspiciousActivity)): ?>
                    <div class='alert'>
                        <strong>⚠️ Suspicious Activity Detected:</strong>
                        <?php foreach ($suspiciousActivity as $activity): ?>
                            <div style='margin: 10px 0;'><?= htmlspecialchars($activity['description']) ?> (<?= $activity['severity'] ?> severity)</div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div class='stats-grid'>
                    <div class='stat-card'>
                        <h3>Today's Events</h3>
                        <div class='stat-number info'><?= $dashboardData['today_summary']['total_events'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>High Severity Today</h3>
                        <div class='stat-number <?= $dashboardData['today_summary']['high_severity_count'] > 0 ? 'critical' : 'info' ?>'><?= $dashboardData['today_summary']['high_severity_count'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Failed Logins (1h)</h3>
                        <div class='stat-number <?= $dashboardData['recent_failed_logins'] > 5 ? 'warning' : 'info' ?>'><?= $dashboardData['recent_failed_logins'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Active Sessions</h3>
                        <div class='stat-number info'><?= $dashboardData['active_sessions'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Total Events (7d)</h3>
                        <div class='stat-number info'><?= $stats['overview']['total_events'] ?></div>
                    </div>
                    <div class='stat-card'>
                        <h3>Failed Logins (7d)</h3>
                        <div class='stat-number warning'><?= $stats['overview']['failed_logins'] ?></div>
                    </div>
                </div>
                
                <div class='section'>
                    <div class='section-header'>
                        <h2>Recent High-Severity Events</h2>
                    </div>
                    <div class='section-content'>
                        <?php if (empty($dashboardData['recent_high_severity'])): ?>
                            <p>No recent high-severity events.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr><th>Time</th><th>Event</th><th>Description</th><th>User</th><th>IP</th><th>Severity</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboardData['recent_high_severity'] as $event): ?>
                                        <tr>
                                            <td><?= date('M j, H:i', strtotime($event['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($event['event_type']) ?></td>
                                            <td><?= htmlspecialchars($event['description']) ?></td>
                                            <td><?= htmlspecialchars($event['user_name'] ?: 'System') ?></td>
                                            <td><?= htmlspecialchars($event['ip_address']) ?></td>
                                            <td class='severity-<?= $event['severity'] ?>'><?= ucfirst($event['severity']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class='section'>
                    <div class='section-header'>
                        <h2>Top IP Addresses (7 days)</h2>
                    </div>
                    <div class='section-content'>
                        <table>
                            <thead>
                                <tr><th>IP Address</th><th>Total Requests</th><th>Failed Attempts</th><th>Risk Level</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['by_ip'] as $ip): ?>
                                    <?php 
                                    $riskLevel = 'Low';
                                    $riskClass = 'info';
                                    if ($ip['failed_attempts'] > 10) {
                                        $riskLevel = 'High';
                                        $riskClass = 'critical';
                                    } elseif ($ip['failed_attempts'] > 3) {
                                        $riskLevel = 'Medium';
                                        $riskClass = 'warning';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ip['ip_address']) ?></td>
                                        <td><?= $ip['request_count'] ?></td>
                                        <td><?= $ip['failed_attempts'] ?></td>
                                        <td class='severity-<?= $riskClass ?>'><?= $riskLevel ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class='section'>
                    <div class='section-header'>
                        <h2>Recent Audit Log</h2>
                    </div>
                    <div class='section-content'>
                        <table>
                            <thead>
                                <tr><th>Time</th><th>Event</th><th>Description</th><th>User</th><th>IP</th><th>Severity</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td><?= date('M j, H:i:s', strtotime($log['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($log['event_type']) ?></td>
                                        <td style='max-width: 300px; overflow: hidden; text-overflow: ellipsis;'><?= htmlspecialchars($log['description']) ?></td>
                                        <td><?= htmlspecialchars($log['user_name'] ?: 'System') ?></td>
                                        <td><?= htmlspecialchars($log['ip_address']) ?></td>
                                        <td class='severity-<?= $log['severity'] ?>'><?= ucfirst($log['severity']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class='section'>
                    <div class='section-header'>
                        <h2>Management Tools</h2>
                    </div>
                    <div class='section-content'>
                        <div style='margin-bottom: 30px;'>
                            <h3 style='margin-bottom: 15px;'>Export Audit Log</h3>
                            <form method='POST' class='form-inline'>
                                <input type='hidden' name='action' value='export_log'>
                                <div class='form-group'>
                                    <label>From Date</label>
                                    <input type='date' name='date_from' value='<?= date('Y-m-d', strtotime('-30 days')) ?>'>
                                </div>
                                <div class='form-group'>
                                    <label>To Date</label>
                                    <input type='date' name='date_to' value='<?= date('Y-m-d') ?>'>
                                </div>
                                <div class='form-group'>
                                    <label>Event Type</label>
                                    <select name='event_type'>
                                        <option value=''>All Events</option>
                                        <option value='login_success'>Successful Logins</option>
                                        <option value='login_failed'>Failed Logins</option>
                                        <option value='data_access'>Data Access</option>
                                        <option value='config_change'>Config Changes</option>
                                    </select>
                                </div>
                                <div class='form-group'>
                                    <button type='submit' class='btn'>Export CSV</button>
                                </div>
                            </form>
                        </div>
                        
                        <div>
                            <h3 style='margin-bottom: 15px;'>Clean Old Logs</h3>
                            <form method='POST' class='form-inline'>
                                <input type='hidden' name='action' value='clean_logs'>
                                <div class='form-group'>
                                    <label>Delete logs older than</label>
                                    <input type='number' name='days' value='90' min='1' max='365'> days
                                </div>
                                <div class='form-group'>
                                    <button type='submit' class='btn btn-danger' onclick='return confirm("Are you sure you want to delete old log entries?")'>Clean Logs</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        
    } catch (Exception $e) {
        echo "<h1>Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
?>