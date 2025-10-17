<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

require_once 'config/database.php';
require_once 'sla-service-simple.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Initialize SLA service
    $slaService = new SlaServiceSimple($pdo);
    
    // Get current user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        die('User not found');
    }
    
    $exportType = $_GET['type'] ?? 'tickets';
    $filename = '';
    $data = [];
    
    switch ($exportType) {
        case 'tickets':
            $filename = 'tickets_export_' . date('Y-m-d_H-i-s') . '.csv';
            // Get filter parameters
            $filters = [
                'assignee' => $_GET['assignee'] ?? '',
                'status' => $_GET['status'] ?? '',
                'priority' => $_GET['priority'] ?? '',
                'sort' => $_GET['sort'] ?? 'created_at',
                'dir' => $_GET['dir'] ?? 'desc',
                'sla_filter' => $_GET['sla_filter'] ?? ''
            ];
            $data = exportTickets($pdo, $slaService, $user, $filters);
            break;
            
        case 'sla_report':
            $filename = 'sla_report_' . date('Y-m-d_H-i-s') . '.csv';
            $dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
            $dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today
            $data = exportSlaReport($pdo, $slaService, $dateFrom, $dateTo);
            break;
            
        case 'sla_breaches':
            $filename = 'sla_breaches_' . date('Y-m-d_H-i-s') . '.csv';
            $data = exportSlaBreaches($pdo, $slaService);
            break;
            
        default:
            die('Invalid export type');
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    // Output CSV
    $output = fopen('php://output', 'w');
    
    if (!empty($data)) {
        // Write header row with filter information as comment
        if ($exportType === 'tickets' && !empty(array_filter($filters))) {
            $filterInfo = "# Filtered Export - ";
            $activeFilters = [];
            if (!empty($filters['assignee']) && $filters['assignee'] !== 'all') {
                $activeFilters[] = "Assignee: " . $filters['assignee'];
            }
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $activeFilters[] = "Status: " . $filters['status'];
            }
            if (!empty($filters['priority']) && $filters['priority'] !== 'all') {
                $activeFilters[] = "Priority: " . $filters['priority'];
            }
            if (!empty($filters['sla_filter'])) {
                $activeFilters[] = "SLA: " . $filters['sla_filter'];
            }
            if (!empty($filters['sort'])) {
                $activeFilters[] = "Sort: " . $filters['sort'] . " " . $filters['dir'];
            }
            if (!empty($activeFilters)) {
                fputcsv($output, [$filterInfo . implode(', ', $activeFilters)]);
            }
        }
        
        // Write header row
        fputcsv($output, array_keys($data[0]));
        
        // Write data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    die('Export error: ' . $e->getMessage());
}

function exportTickets($pdo, $slaService, $user, $filters = [])
{
    // Build SQL query with filters (same logic as tickets-simple.php)
    $sql = "SELECT t.*,
                   r.name as requester_name, r.email as requester_email, r.company as requester_company,
                   u.name as assignee_name,
                   sp.name as sla_name, sp.response_target, sp.resolution_target
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE 1=1";
    
    $params = [];
    
    // Filter based on user role
    if ($user['role'] === 'agent') {
        $sql .= " AND t.assignee_id = ?";
        $params[] = $user['id'];
    }
    
    // Apply filters
    if (!empty($filters['assignee']) && $filters['assignee'] !== 'all') {
        if ($filters['assignee'] === 'unassigned') {
            $sql .= " AND t.assignee_id IS NULL";
        } else {
            $sql .= " AND t.assignee_id = ?";
            $params[] = $filters['assignee'];
        }
    }
    
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND t.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['priority']) && $filters['priority'] !== 'all') {
        $sql .= " AND t.priority = ?";
        $params[] = $filters['priority'];
    }
    
    // Apply sorting
    $validSortFields = ['created_at', 'updated_at', 'priority', 'status', 'subject', 'sla_proximity'];
    $validDirections = ['asc', 'desc'];
    
    $sortBy = in_array($filters['sort'], $validSortFields) ? $filters['sort'] : 'created_at';
    $sortDir = in_array(strtolower($filters['dir']), $validDirections) ? $filters['dir'] : 'desc';
    
    // Special sorting for priority (urgent first)
    if ($sortBy === 'priority') {
        $sql .= " ORDER BY FIELD(t.priority, 'urgent', 'high', 'normal', 'low') " . ($sortDir === 'desc' ? 'ASC' : 'DESC');
    } elseif ($sortBy === 'sla_proximity') {
        // SLA proximity will be sorted after fetching data
        $sql .= " ORDER BY t.created_at DESC";
    } else {
        $sql .= " ORDER BY t.{$sortBy} " . strtoupper($sortDir);
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
    
    $exportData = [];
    
    // Add SLA compliance information to each ticket and calculate SLA proximity (same as tickets-simple.php)
    foreach ($tickets as &$ticket) {
        if ($ticket['sla_policy_id']) {
            try {
                $ticket['sla_compliance'] = $slaService->checkSlaCompliance($ticket['id']);
                
                // Calculate SLA proximity score for sorting (0 = breached, 100 = safe)
                $responseProximity = 100;
                $resolutionProximity = 100;
                
                if (isset($ticket['sla_compliance']['response_hours']) && $ticket['response_target']) {
                    $responsePercentage = ($ticket['sla_compliance']['response_hours'] * 60) / $ticket['response_target'] * 100;
                    $responseProximity = max(0, 100 - $responsePercentage);
                }
                
                if (isset($ticket['sla_compliance']['resolution_hours']) && $ticket['resolution_target']) {
                    $resolutionPercentage = ($ticket['sla_compliance']['resolution_hours'] * 60) / $ticket['resolution_target'] * 100;
                    $resolutionProximity = max(0, 100 - $resolutionPercentage);
                }
                
                $ticket['sla_proximity_score'] = min($responseProximity, $resolutionProximity);
            } catch (Exception $e) {
                error_log("SLA compliance check failed for ticket {$ticket['id']}: " . $e->getMessage());
                $ticket['sla_proximity_score'] = 50; // Default middle score for sorting
            }
        } else {
            $ticket['sla_proximity_score'] = 50; // Default for tickets without SLA
        }
    }
    
    // Apply SLA filter (same as tickets-simple.php)
    if (!empty($filters['sla_filter'])) {
        switch ($filters['sla_filter']) {
            case 'breached':
                $tickets = array_filter($tickets, function($ticket) {
                    return isset($ticket['sla_compliance']) &&
                           (!$ticket['sla_compliance']['response_compliant'] || !$ticket['sla_compliance']['resolution_compliant']);
                });
                break;
            case 'at_risk':
                $tickets = array_filter($tickets, function($ticket) {
                    return isset($ticket['sla_compliance']) &&
                           $ticket['sla_compliance']['response_compliant'] &&
                           $ticket['sla_compliance']['resolution_compliant'] &&
                           $ticket['sla_proximity_score'] < 25; // Less than 25% buffer remaining
                });
                break;
            case 'safe':
                $tickets = array_filter($tickets, function($ticket) {
                    return isset($ticket['sla_compliance']) &&
                           $ticket['sla_compliance']['response_compliant'] &&
                           $ticket['sla_compliance']['resolution_compliant'] &&
                           $ticket['sla_proximity_score'] >= 25;
                });
                break;
        }
    }
    
    // Apply SLA proximity sorting if requested (same as tickets-simple.php)
    if ($sortBy === 'sla_proximity') {
        usort($tickets, function($a, $b) use ($sortDir) {
            $scoreA = $a['sla_proximity_score'];
            $scoreB = $b['sla_proximity_score'];
            
            if ($sortDir === 'asc') {
                return $scoreA <=> $scoreB; // Most at risk first
            } else {
                return $scoreB <=> $scoreA; // Safest first
            }
        });
    }
    
    $exportData = [];
    
    foreach ($tickets as $ticket) {
        $slaCompliance = isset($ticket['sla_compliance']) ? $ticket['sla_compliance'] : null;
        
        $row = [
            'Ticket Key' => $ticket['key'],
            'Subject' => $ticket['subject'],
            'Type' => ucfirst($ticket['type']),
            'Priority' => ucfirst($ticket['priority']),
            'Status' => ucfirst(str_replace('_', ' ', $ticket['status'])),
            'Requester Name' => $ticket['requester_name'],
            'Requester Email' => $ticket['requester_email'],
            'Requester Company' => $ticket['requester_company'] ?: 'N/A',
            'Assignee' => $ticket['assignee_name'] ?: 'Unassigned',
            'Created Date' => $ticket['created_at'],
            'First Response Date' => $ticket['first_response_at'] ?: 'N/A',
            'Resolved Date' => $ticket['resolved_at'] ?: 'N/A',
            'Closed Date' => $ticket['closed_at'] ?: 'N/A',
            'SLA Policy' => $ticket['sla_name'] ?: 'None',
            'Response Target (hours)' => $ticket['response_target'] ? round($ticket['response_target'] / 60, 1) : 'N/A',
            'Resolution Target (hours)' => $ticket['resolution_target'] ? round($ticket['resolution_target'] / 60, 1) : 'N/A',
            'SLA Proximity Score' => isset($ticket['sla_proximity_score']) ? round($ticket['sla_proximity_score'], 1) : 'N/A'
        ];
        
        // Add SLA compliance information
        if ($slaCompliance) {
            $row['Response Time (hours)'] = isset($slaCompliance['response_hours']) ? round($slaCompliance['response_hours'], 1) : 'N/A';
            $row['Response Compliant'] = isset($slaCompliance['response_compliant']) ? ($slaCompliance['response_compliant'] ? 'Yes' : 'No') : 'N/A';
            $row['Resolution Time (hours)'] = isset($slaCompliance['resolution_hours']) ? round($slaCompliance['resolution_hours'], 1) : 'N/A';
            $row['Resolution Compliant'] = isset($slaCompliance['resolution_compliant']) ? ($slaCompliance['resolution_compliant'] ? 'Yes' : 'No') : 'N/A';
            
            // Add SLA status based on compliance and proximity
            $slaStatus = 'Safe';
            if (!$slaCompliance['response_compliant'] || !$slaCompliance['resolution_compliant']) {
                $slaStatus = 'Breached';
            } elseif (isset($ticket['sla_proximity_score']) && $ticket['sla_proximity_score'] < 25) {
                $slaStatus = 'At Risk';
            }
            $row['SLA Status'] = $slaStatus;
        } else {
            $row['Response Time (hours)'] = 'N/A';
            $row['Response Compliant'] = 'N/A';
            $row['Resolution Time (hours)'] = 'N/A';
            $row['Resolution Compliant'] = 'N/A';
            $row['SLA Status'] = 'No SLA';
        }
        
        $exportData[] = $row;
    }
    
    return $exportData;
}

function exportSlaReport($pdo, $slaService, $dateFrom, $dateTo)
{
    $sql = "SELECT t.*,
                   r.name as requester_name, r.email as requester_email,
                   u.name as assignee_name,
                   sp.name as sla_name, sp.response_target, sp.resolution_target
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE t.created_at >= ? AND t.created_at <= ?
            AND t.sla_policy_id IS NOT NULL
            ORDER BY t.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $tickets = $stmt->fetchAll();
    
    $exportData = [];
    
    foreach ($tickets as $ticket) {
        try {
            $slaCompliance = $slaService->checkSlaCompliance($ticket['id']);
            
            $row = [
                'Ticket Key' => $ticket['key'],
                'Subject' => $ticket['subject'],
                'Type' => ucfirst($ticket['type']),
                'Priority' => ucfirst($ticket['priority']),
                'Status' => ucfirst(str_replace('_', ' ', $ticket['status'])),
                'Requester' => $ticket['requester_name'],
                'Assignee' => $ticket['assignee_name'] ?: 'Unassigned',
                'Created Date' => $ticket['created_at'],
                'SLA Policy' => $ticket['sla_name'],
                'Response Target (hours)' => round($ticket['response_target'] / 60, 1),
                'Response Time (hours)' => isset($slaCompliance['response_hours']) ? round($slaCompliance['response_hours'], 1) : 'N/A',
                'Response Compliant' => isset($slaCompliance['response_compliant']) ? ($slaCompliance['response_compliant'] ? 'Yes' : 'No') : 'N/A',
                'Response Breach (hours)' => isset($slaCompliance['response_compliant']) && !$slaCompliance['response_compliant'] ? 
                    round(($slaCompliance['response_hours'] ?? 0) - ($ticket['response_target'] / 60), 1) : '0',
                'Resolution Target (hours)' => round($ticket['resolution_target'] / 60, 1),
                'Resolution Time (hours)' => isset($slaCompliance['resolution_hours']) ? round($slaCompliance['resolution_hours'], 1) : 'N/A',
                'Resolution Compliant' => isset($slaCompliance['resolution_compliant']) ? ($slaCompliance['resolution_compliant'] ? 'Yes' : 'No') : 'N/A',
                'Resolution Breach (hours)' => isset($slaCompliance['resolution_compliant']) && !$slaCompliance['resolution_compliant'] ? 
                    round(($slaCompliance['resolution_hours'] ?? 0) - ($ticket['resolution_target'] / 60), 1) : '0'
            ];
            
            $exportData[] = $row;
        } catch (Exception $e) {
            // Skip tickets with SLA calculation errors
            continue;
        }
    }
    
    return $exportData;
}

function exportSlaBreaches($pdo, $slaService)
{
    $breaches = $slaService->getSlaBreaches();
    $exportData = [];
    
    foreach ($breaches as $breach) {
        $ticket = $breach['ticket'];
        $slaCheck = $breach['sla_check'];
        
        $row = [
            'Ticket Key' => $ticket['key'],
            'Subject' => $ticket['subject'],
            'Type' => ucfirst($ticket['type']),
            'Priority' => ucfirst($ticket['priority']),
            'Status' => ucfirst(str_replace('_', ' ', $ticket['status'])),
            'Requester' => $ticket['requester_name'],
            'Assignee' => $ticket['assignee_name'] ?: 'Unassigned',
            'Created Date' => $ticket['created_at'],
            'SLA Policy' => $ticket['sla_name'],
            'Response Breach' => $breach['response_breach'] ? 'Yes' : 'No',
            'Response Target (hours)' => round($ticket['response_target'] / 60, 1),
            'Response Time (hours)' => isset($slaCheck['response_hours']) ? round($slaCheck['response_hours'], 1) : 'N/A',
            'Response Breach (hours)' => $breach['response_breach'] ? 
                round(($slaCheck['response_hours'] ?? 0) - ($ticket['response_target'] / 60), 1) : '0',
            'Resolution Breach' => $breach['resolution_breach'] ? 'Yes' : 'No',
            'Resolution Target (hours)' => round($ticket['resolution_target'] / 60, 1),
            'Resolution Time (hours)' => isset($slaCheck['resolution_hours']) ? round($slaCheck['resolution_hours'], 1) : 'N/A',
            'Resolution Breach (hours)' => $breach['resolution_breach'] ? 
                round(($slaCheck['resolution_hours'] ?? 0) - ($ticket['resolution_target'] / 60), 1) : '0'
        ];
        
        $exportData[] = $row;
    }
    
    return $exportData;
}
?>