<?php
/*
|--------------------------------------------------------------------------
| Settings - Simple Model
|--------------------------------------------------------------------------
| System settings management for administrators and supervisors
*/

require_once 'auth-helper.php';
require_once 'db-connection.php';

try {
    $pdo = createDatabaseConnection();
    $user = getCurrentSupervisor($pdo);
    
    // Get some quick stats for display
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM sla_policies WHERE active = 1");
    $slaCount = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ticket_categories WHERE parent_id IS NULL AND active = 1");
    $categoryCount = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ticket_categories WHERE parent_id IS NOT NULL AND active = 1");
    $subcategoryCount = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE active = 1");
    $userCount = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM organizations WHERE active = 1");
    $orgCount = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM requesters WHERE organization_id IS NULL AND active = 1");
    $unassignedRequesters = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM api_tokens WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userApiTokens = $stmt->fetch()['count'];
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Settings - ITSPtickets</title>
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
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }
        
        .settings-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .settings-section:hover {
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
        .sla-section .section-header {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .category-section .section-header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .user-section .section-header {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        
        .system-section .section-header {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .settings-grid {
                grid-template-columns: 1fr;
                gap: 20px;
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
            
            .settings-section {
                margin-bottom: 15px;
            }
            
            .section-header {
                padding: 20px 15px;
            }
            
            .section-icon {
                font-size: 36px;
                margin-bottom: 8px;
            }
            
            .section-title {
                font-size: 20px;
                margin-bottom: 6px;
            }
            
            .section-description {
                font-size: 13px;
            }
            
            .section-content {
                padding: 20px 15px;
            }
            
            .section-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 20px;
            }
            
            .stat-item {
                padding: 12px 10px;
            }
            
            .stat-number {
                font-size: 24px;
            }
            
            .stat-label {
                font-size: 11px;
            }
            
            .section-actions {
                gap: 10px;
            }
            
            .action-btn {
                padding: 12px 15px;
                flex-direction: row;
                align-items: center;
            }
            
            .action-icon {
                font-size: 18px;
                margin-right: 10px;
            }
            
            .action-text {
                font-size: 14px;
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
            }
            
            .section-header {
                padding: 15px 10px;
            }
            
            .section-content {
                padding: 15px 10px;
            }
            
            .section-stats {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .stat-item {
                padding: 10px;
                text-align: left;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .stat-number {
                font-size: 20px;
            }
            
            .stat-label {
                font-size: 12px;
                text-transform: none;
            }
            
            .action-btn {
                padding: 15px 12px;
                min-height: 50px; /* Touch-friendly */
            }
            
            .action-text {
                font-size: 15px;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 768px) and (pointer: coarse) {
            .action-btn {
                min-height: 44px; /* Apple's recommended touch target size */
            }
            
            .settings-section {
                transition: transform 0.2s ease;
            }
            
            .settings-section:active {
                transform: scale(0.98);
            }
        }
        
        /* Landscape phones */
        @media (max-width: 768px) and (orientation: landscape) {
            .section-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>⚙️ System Settings</h1>
            <div class='user-info'>
                <span>Welcome, <?= htmlspecialchars($user['name']) ?></span>
                <span style='background: #3b82f6; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; text-transform: uppercase; font-weight: 500;'>
                    <?= htmlspecialchars($user['role']) ?>
                </span>
                <a href='/ITSPtickets/dashboard-simple.php'>← Back to Dashboard</a>
            </div>
        </div>
        
        <div class='settings-grid'>
            <!-- SLA Management Section -->
            <div class='settings-section sla-section'>
                <div class='section-header'>
                    <span class='section-icon'>⏱️</span>
                    <h2 class='section-title'>SLA Management</h2>
                    <p class='section-description'>Configure service level agreements and response targets</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $slaCount ?></span>
                            <span class='stat-label'>Active SLAs</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/sla-management.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📊</span>
                                <span class='action-text'>Manage SLA Policies</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Category Management Section -->
            <div class='settings-section category-section'>
                <div class='section-header'>
                    <span class='section-icon'>🗂️</span>
                    <h2 class='section-title'>Category Management</h2>
                    <p class='section-description'>Organize ticket categories and subcategories</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $categoryCount ?></span>
                            <span class='stat-label'>Categories</span>
                        </div>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $subcategoryCount ?></span>
                            <span class='stat-label'>Subcategories</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/manage-categories.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📁</span>
                                <span class='action-text'>Manage Categories</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- User Management Section -->
            <div class='settings-section user-section'>
                <div class='section-header'>
                    <span class='section-icon'>👥</span>
                    <h2 class='section-title'>User Management</h2>
                    <p class='section-description'>Manage users, roles, and permissions</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $userCount ?></span>
                            <span class='stat-label'>Active Users</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/manage-users.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>👤</span>
                                <span class='action-text'>Manage Users</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/user-roles.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>🔐</span>
                                <span class='action-text'>Role & Permissions</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Organization Management Section -->
            <div class='settings-section'>
                <div class='section-header' style='background: linear-gradient(135deg, #06b6d4, #0891b2);'>
                    <span class='section-icon'>🏢</span>
                    <h2 class='section-title'>Organization Management</h2>
                    <p class='section-description'>Manage client organizations and requester assignments</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $orgCount ?></span>
                            <span class='stat-label'>Organizations</span>
                        </div>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $unassignedRequesters ?></span>
                            <span class='stat-label'>Unassigned</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/manage-organizations.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>🏢</span>
                                <span class='action-text'>Manage Organizations</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/customer-reports.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📊</span>
                                <span class='action-text'>Customer Reports</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- API Key Management Section -->
            <div class='settings-section' style='background: linear-gradient(135deg, #6366f1, #4f46e5);'>
                <div class='section-header' style='background: linear-gradient(135deg, #6366f1, #4f46e5);'>
                    <span class='section-icon'>🔑</span>
                    <h2 class='section-title'>API Key Management</h2>
                    <p class='section-description'>Manage your personal API keys for external integrations</p>
                </div>
                <div class='section-content'>
                    <div class='section-stats'>
                        <div class='stat-item'>
                            <span class='stat-number'><?= $userApiTokens ?></span>
                            <span class='stat-label'>Active Keys</span>
                        </div>
                    </div>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/user-api-keys.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>🔐</span>
                                <span class='action-text'>Manage API Keys</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/api-docs.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📖</span>
                                <span class='action-text'>API Documentation</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- System Configuration Section -->
            <div class='settings-section system-section'>
                <div class='section-header'>
                    <span class='section-icon'>🔧</span>
                    <h2 class='section-title'>System Configuration</h2>
                    <p class='section-description'>General system settings and preferences</p>
                </div>
                <div class='section-content'>
                    <div class='section-actions'>
                        <a href='/ITSPtickets/notifications-log.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>🔔</span>
                                <span class='action-text'>Notification Settings</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/system-preferences.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>⚙️</span>
                                <span class='action-text'>System Preferences</span>
                            </div>
                            <span class='action-arrow'>→</span>
                        </a>
                        <a href='/ITSPtickets/email-templates.php' class='action-btn'>
                            <div style='display: flex; align-items: center;'>
                                <span class='action-icon'>📧</span>
                                <span class='action-text'>Email Templates</span>
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