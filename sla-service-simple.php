<?php

class SlaServiceSimple
{
    private $pdo;
    
    // Business hours configuration (24-hour format)
    private $businessHours = [
        'monday' => ['start' => '09:00', 'end' => '17:00'],
        'tuesday' => ['start' => '09:00', 'end' => '17:00'],
        'wednesday' => ['start' => '09:00', 'end' => '17:00'],
        'thursday' => ['start' => '09:00', 'end' => '17:00'],
        'friday' => ['start' => '09:00', 'end' => '17:00'],
        'saturday' => null, // Closed
        'sunday' => null,   // Closed
    ];
    
    // Holidays (YYYY-MM-DD format)
    private $holidays = [
        '2024-01-01', // New Year's Day
        '2024-12-25', // Christmas Day
        '2024-12-26', // Boxing Day
        // Add more holidays as needed
    ];
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Calculate business hours between two dates
     */
    public function calculateBusinessHours($startDate, $endDate)
    {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        
        if ($start >= $end) {
            return 0;
        }
        
        $totalMinutes = 0;
        $current = clone $start;
        
        while ($current < $end) {
            $dayOfWeek = strtolower($current->format('l'));
            $dateStr = $current->format('Y-m-d');
            
            // Skip weekends and holidays
            if ($this->businessHours[$dayOfWeek] === null || in_array($dateStr, $this->holidays)) {
                $current->add(new DateInterval('P1D'));
                $current->setTime(0, 0, 0);
                continue;
            }
            
            $businessStart = DateTime::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $this->businessHours[$dayOfWeek]['start']);
            $businessEnd = DateTime::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $this->businessHours[$dayOfWeek]['end']);
            
            // Determine the actual start and end times for this day
            $dayStart = max($current, $businessStart);
            $dayEnd = min($end, $businessEnd);
            
            // If there's overlap with business hours on this day
            if ($dayStart < $dayEnd) {
                $diff = $dayEnd->diff($dayStart);
                $totalMinutes += ($diff->h * 60) + $diff->i;
            }
            
            // Move to next day
            $current->add(new DateInterval('P1D'));
            $current->setTime(0, 0, 0);
        }
        
        return round($totalMinutes / 60, 2); // Return hours
    }
    
    /**
     * Check if a ticket is SLA compliant
     */
    public function checkSlaCompliance($ticketId)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, sp.response_target, sp.resolution_target, sp.name as sla_name
            FROM tickets t
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        
        if (!$ticket || !$ticket['sla_policy_id']) {
            return [
                'has_sla' => false,
                'response_compliant' => null,
                'resolution_compliant' => null
            ];
        }
        
        $result = [
            'has_sla' => true,
            'sla_name' => $ticket['sla_name'],
            'response_target' => $ticket['response_target'],
            'resolution_target' => $ticket['resolution_target']
        ];
        
        // Check response SLA
        if ($ticket['first_response_at']) {
            $responseHours = $this->calculateBusinessHours($ticket['created_at'], $ticket['first_response_at']);
            $result['response_hours'] = $responseHours;
            $result['response_compliant'] = $responseHours <= ($ticket['response_target'] / 60);
        } else {
            $currentHours = $this->calculateBusinessHours($ticket['created_at'], date('Y-m-d H:i:s'));
            $result['response_hours'] = $currentHours;
            $result['response_compliant'] = $currentHours <= ($ticket['response_target'] / 60);
        }
        
        // Check resolution SLA
        if ($ticket['resolved_at']) {
            $resolutionHours = $this->calculateBusinessHours($ticket['created_at'], $ticket['resolved_at']);
            $result['resolution_hours'] = $resolutionHours;
            $result['resolution_compliant'] = $resolutionHours <= ($ticket['resolution_target'] / 60);
        } else {
            $currentHours = $this->calculateBusinessHours($ticket['created_at'], date('Y-m-d H:i:s'));
            $result['resolution_hours'] = $currentHours;
            $result['resolution_compliant'] = $currentHours <= ($ticket['resolution_target'] / 60);
        }
        
        return $result;
    }
    
    /**
     * Get SLA dashboard statistics
     */
    public function getSlaStatistics($filters = [])
    {
        $sql = "SELECT 
                    COUNT(*) as total_tickets,
                    COUNT(t.sla_policy_id) as tickets_with_sla,
                    SUM(CASE WHEN t.first_response_at IS NOT NULL THEN 1 ELSE 0 END) as responded_tickets,
                    SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved_tickets
                FROM tickets t
                WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['date_from'])) {
            $sql .= " AND t.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (isset($filters['date_to'])) {
            $sql .= " AND t.created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    /**
     * Get SLA breach alerts
     */
    public function getSlaBreaches($filters = [])
    {
        $breaches = [];
        
        $sql = "SELECT t.*, sp.response_target, sp.resolution_target, sp.name as sla_name,
                       r.name as requester_name, u.name as assignee_name
                FROM tickets t
                LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
                LEFT JOIN requesters r ON t.requester_id = r.id
                LEFT JOIN users u ON t.assignee_id = u.id
                WHERE t.sla_policy_id IS NOT NULL
                AND t.status NOT IN ('closed', 'resolved')
                ORDER BY t.created_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $tickets = $stmt->fetchAll();
        
        foreach ($tickets as $ticket) {
            $slaCheck = $this->checkSlaCompliance($ticket['id']);
            
            if (!$slaCheck['response_compliant'] || !$slaCheck['resolution_compliant']) {
                $breaches[] = [
                    'ticket' => $ticket,
                    'sla_check' => $slaCheck,
                    'response_breach' => !$slaCheck['response_compliant'],
                    'resolution_breach' => !$slaCheck['resolution_compliant']
                ];
            }
        }
        
        return $breaches;
    }
    
    /**
     * Get SLA warnings for tickets approaching breach
     */
    public function getSlaWarnings($warningThreshold = 75)
    {
        $warnings = [];
        
        $sql = "SELECT t.*, sp.response_target, sp.resolution_target, sp.name as sla_name,
                       r.name as requester_name, u.name as assignee_name
                FROM tickets t
                LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
                LEFT JOIN requesters r ON t.requester_id = r.id
                LEFT JOIN users u ON t.assignee_id = u.id
                WHERE t.sla_policy_id IS NOT NULL
                AND t.status NOT IN ('closed', 'resolved')
                ORDER BY t.created_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $tickets = $stmt->fetchAll();
        
        foreach ($tickets as $ticket) {
            $slaCheck = $this->checkSlaCompliance($ticket['id']);
            
            // Skip if already breached
            if (!$slaCheck['response_compliant'] || !$slaCheck['resolution_compliant']) {
                continue;
            }
            
            $warningTriggered = false;
            $warningType = [];
            
            // Check response warning
            if (!$ticket['first_response_at'] && isset($slaCheck['response_hours'])) {
                $responsePercentage = ($slaCheck['response_hours'] / ($slaCheck['response_target'] / 60)) * 100;
                if ($responsePercentage >= $warningThreshold) {
                    $warningTriggered = true;
                    $warningType[] = [
                        'type' => 'response',
                        'percentage' => $responsePercentage,
                        'remaining_hours' => max(0, ($slaCheck['response_target'] / 60) - $slaCheck['response_hours'])
                    ];
                }
            }
            
            // Check resolution warning
            if (!$ticket['resolved_at'] && isset($slaCheck['resolution_hours'])) {
                $resolutionPercentage = ($slaCheck['resolution_hours'] / ($slaCheck['resolution_target'] / 60)) * 100;
                if ($resolutionPercentage >= $warningThreshold) {
                    $warningTriggered = true;
                    $warningType[] = [
                        'type' => 'resolution',
                        'percentage' => $resolutionPercentage,
                        'remaining_hours' => max(0, ($slaCheck['resolution_target'] / 60) - $slaCheck['resolution_hours'])
                    ];
                }
            }
            
            if ($warningTriggered) {
                $warnings[] = [
                    'ticket' => $ticket,
                    'sla_check' => $slaCheck,
                    'warnings' => $warningType
                ];
            }
        }
        
        return $warnings;
    }
    
    /**
     * Update SLA policy for ticket based on type and priority
     */
    public function assignSlaPolicy($ticketId, $type, $priority)
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM sla_policies 
            WHERE type = ? AND priority = ? AND active = 1 
            LIMIT 1
        ");
        $stmt->execute([$type, $priority]);
        $slaPolicy = $stmt->fetch();
        
        if ($slaPolicy) {
            $stmt = $this->pdo->prepare("UPDATE tickets SET sla_policy_id = ? WHERE id = ?");
            $stmt->execute([$slaPolicy['id'], $ticketId]);
            
            return $slaPolicy['id'];
        }
        
        return null;
    }
    
    /**
     * Create default SLA policies
     */
    public function createDefaultSlaPolicies()
    {
        // Get the first available calendar
        $stmt = $this->pdo->prepare("SELECT id FROM calendars WHERE active = 1 LIMIT 1");
        $stmt->execute();
        $calendar = $stmt->fetch();
        
        if (!$calendar) {
            throw new Exception("No active calendar found. Please create a business hours calendar first.");
        }
        
        $calendarId = $calendar['id'];
        
        $policies = [
            // Incident SLAs
            ['type' => 'incident', 'priority' => 'urgent', 'response_target' => 60, 'resolution_target' => 240, 'name' => 'Urgent Incident'],
            ['type' => 'incident', 'priority' => 'high', 'response_target' => 120, 'resolution_target' => 480, 'name' => 'High Incident'],
            ['type' => 'incident', 'priority' => 'normal', 'response_target' => 240, 'resolution_target' => 960, 'name' => 'Normal Incident'],
            ['type' => 'incident', 'priority' => 'low', 'response_target' => 480, 'resolution_target' => 1920, 'name' => 'Low Incident'],
            
            // Request SLAs
            ['type' => 'request', 'priority' => 'urgent', 'response_target' => 120, 'resolution_target' => 480, 'name' => 'Urgent Request'],
            ['type' => 'request', 'priority' => 'high', 'response_target' => 240, 'resolution_target' => 960, 'name' => 'High Request'],
            ['type' => 'request', 'priority' => 'normal', 'response_target' => 480, 'resolution_target' => 1920, 'name' => 'Normal Request'],
            ['type' => 'request', 'priority' => 'low', 'response_target' => 960, 'resolution_target' => 3840, 'name' => 'Low Request'],
            
            // Job SLAs
            ['type' => 'job', 'priority' => 'urgent', 'response_target' => 240, 'resolution_target' => 960, 'name' => 'Urgent Job'],
            ['type' => 'job', 'priority' => 'high', 'response_target' => 480, 'resolution_target' => 1920, 'name' => 'High Job'],
            ['type' => 'job', 'priority' => 'normal', 'response_target' => 960, 'resolution_target' => 3840, 'name' => 'Normal Job'],
            ['type' => 'job', 'priority' => 'low', 'response_target' => 1920, 'resolution_target' => 7680, 'name' => 'Low Job'],
        ];
        
        foreach ($policies as $policy) {
            // Check if policy already exists
            $stmt = $this->pdo->prepare("SELECT id FROM sla_policies WHERE type = ? AND priority = ?");
            $stmt->execute([$policy['type'], $policy['priority']]);
            
            if (!$stmt->fetch()) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO sla_policies (name, type, priority, response_target, resolution_target, calendar_id, active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $policy['name'],
                    $policy['type'],
                    $policy['priority'],
                    $policy['response_target'],
                    $policy['resolution_target'],
                    $calendarId
                ]);
            }
        }
    }
}

// Usage example and testing
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    require_once 'config/database.php';
    
    try {
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $slaService = new SlaServiceSimple($pdo);
        
        echo "<h1>SLA Service Test</h1>";
        
        // Create default policies
        echo "<h2>Creating Default SLA Policies</h2>";
        $slaService->createDefaultSlaPolicies();
        echo "✓ Default SLA policies created<br>";
        
        // Test business hours calculation
        echo "<h2>Business Hours Calculation Test</h2>";
        $start = '2024-01-15 10:00:00'; // Monday
        $end = '2024-01-17 14:00:00';   // Wednesday
        $hours = $slaService->calculateBusinessHours($start, $end);
        echo "Business hours between {$start} and {$end}: {$hours} hours<br>";
        
        // Get SLA statistics
        echo "<h2>SLA Statistics</h2>";
        $stats = $slaService->getSlaStatistics();
        echo "Total tickets: {$stats['total_tickets']}<br>";
        echo "Tickets with SLA: {$stats['tickets_with_sla']}<br>";
        echo "Responded tickets: {$stats['responded_tickets']}<br>";
        echo "Resolved tickets: {$stats['resolved_tickets']}<br>";
        
        // Check for breaches
        echo "<h2>SLA Breaches</h2>";
        $breaches = $slaService->getSlaBreaches();
        if (empty($breaches)) {
            echo "No SLA breaches found<br>";
        } else {
            foreach ($breaches as $breach) {
                echo "Ticket {$breach['ticket']['key']} - ";
                if ($breach['response_breach']) echo "Response breach ";
                if ($breach['resolution_breach']) echo "Resolution breach ";
                echo "<br>";
            }
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>