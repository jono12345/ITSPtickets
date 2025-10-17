<?php

class NotificationServiceSimple
{
    private $pdo;
    private $emailConfig;
    private $smsConfig;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->initializeConfig();
    }
    
    /**
     * Initialize notification configuration
     */
    private function initializeConfig()
    {
        // Email configuration (SMTP)
        $this->emailConfig = [
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email' => 'support@itsptickets.com',
            'from_name' => 'ITSPtickets Support',
            'enabled' => true
        ];
        
        // SMS configuration (example using a generic SMS API)
        $this->smsConfig = [
            'api_url' => 'https://api.sms-service.com/send',
            'api_key' => '',
            'from_number' => '+1234567890',
            'enabled' => false // Disabled by default
        ];
    }
    
    /**
     * Send notification based on event
     */
    public function sendNotification($event, $ticketId, $additionalData = [])
    {
        try {
            // Get ticket and related information
            $ticketData = $this->getTicketData($ticketId);
            if (!$ticketData) {
                return false;
            }
            
            // Determine recipients based on event
            $recipients = $this->getRecipients($event, $ticketData);
            
            // Generate notification content
            $content = $this->generateContent($event, $ticketData, $additionalData);
            
            // Send notifications
            $results = [];
            foreach ($recipients as $recipient) {
                if ($recipient['email']) {
                    $results['email_' . $recipient['id']] = $this->sendEmail($recipient, $content);
                }
                if ($recipient['phone'] && $this->smsConfig['enabled']) {
                    $results['sms_' . $recipient['id']] = $this->sendSms($recipient, $content);
                }
            }
            
            // Log notification
            $this->logNotification($event, $ticketId, $recipients, $results);
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get ticket data with related information
     */
    private function getTicketData($ticketId)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, 
                   r.name as requester_name, r.email as requester_email, r.phone as requester_phone,
                   u.name as assignee_name, u.email as assignee_email, u.phone as assignee_phone,
                   sp.name as sla_name, sp.response_target, sp.resolution_target
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticketId]);
        return $stmt->fetch();
    }
    
    /**
     * Determine notification recipients based on event
     */
    private function getRecipients($event, $ticketData)
    {
        $recipients = [];
        
        switch ($event) {
            case 'ticket_created':
                // Notify requester and assigned agent
                $recipients[] = [
                    'id' => $ticketData['requester_id'],
                    'name' => $ticketData['requester_name'],
                    'email' => $ticketData['requester_email'],
                    'phone' => $ticketData['requester_phone'],
                    'type' => 'requester'
                ];
                
                if ($ticketData['assignee_id']) {
                    $recipients[] = [
                        'id' => $ticketData['assignee_id'],
                        'name' => $ticketData['assignee_name'],
                        'email' => $ticketData['assignee_email'],
                        'phone' => $ticketData['assignee_phone'],
                        'type' => 'agent'
                    ];
                }
                break;
                
            case 'ticket_assigned':
                // Notify new assignee and requester
                if ($ticketData['assignee_id']) {
                    $recipients[] = [
                        'id' => $ticketData['assignee_id'],
                        'name' => $ticketData['assignee_name'],
                        'email' => $ticketData['assignee_email'],
                        'phone' => $ticketData['assignee_phone'],
                        'type' => 'agent'
                    ];
                }
                
                $recipients[] = [
                    'id' => $ticketData['requester_id'],
                    'name' => $ticketData['requester_name'],
                    'email' => $ticketData['requester_email'],
                    'phone' => $ticketData['requester_phone'],
                    'type' => 'requester'
                ];
                break;
                
            case 'ticket_updated':
            case 'message_added':
                // Notify all parties
                $recipients[] = [
                    'id' => $ticketData['requester_id'],
                    'name' => $ticketData['requester_name'],
                    'email' => $ticketData['requester_email'],
                    'phone' => $ticketData['requester_phone'],
                    'type' => 'requester'
                ];
                
                if ($ticketData['assignee_id']) {
                    $recipients[] = [
                        'id' => $ticketData['assignee_id'],
                        'name' => $ticketData['assignee_name'],
                        'email' => $ticketData['assignee_email'],
                        'phone' => $ticketData['assignee_phone'],
                        'type' => 'agent'
                    ];
                }
                break;
                
            case 'ticket_resolved':
            case 'ticket_closed':
                // Notify requester
                $recipients[] = [
                    'id' => $ticketData['requester_id'],
                    'name' => $ticketData['requester_name'],
                    'email' => $ticketData['requester_email'],
                    'phone' => $ticketData['requester_phone'],
                    'type' => 'requester'
                ];
                break;
                
            case 'sla_breach':
                // Notify supervisors and admins
                $stmt = $this->pdo->prepare("SELECT id, name, email, phone FROM users WHERE role IN ('supervisor', 'admin') AND active = 1");
                $stmt->execute();
                $supervisors = $stmt->fetchAll();
                
                foreach ($supervisors as $supervisor) {
                    $recipients[] = [
                        'id' => $supervisor['id'],
                        'name' => $supervisor['name'],
                        'email' => $supervisor['email'],
                        'phone' => $supervisor['phone'],
                        'type' => 'supervisor'
                    ];
                }
                break;
        }
        
        return $recipients;
    }
    
    /**
     * Generate notification content based on event and data
     */
    private function generateContent($event, $ticketData, $additionalData)
    {
        $content = [
            'subject' => '',
            'email_body' => '',
            'sms_body' => ''
        ];
        
        $ticketUrl = "http://{$_SERVER['HTTP_HOST']}/ITSPtickets/ticket-simple.php?id={$ticketData['id']}";
        
        switch ($event) {
            case 'ticket_created':
                $content['subject'] = "[{$ticketData['key']}] New Ticket: {$ticketData['subject']}";
                $content['email_body'] = "
Hello {recipient_name},

A new support ticket has been created:

Ticket: {$ticketData['key']}
Subject: {$ticketData['subject']}
Priority: {$ticketData['priority']}
Status: {$ticketData['status']}

Description:
{$ticketData['description']}

View ticket: {$ticketUrl}

Best regards,
ITSPtickets Support Team
                ";
                $content['sms_body'] = "New ticket {$ticketData['key']}: {$ticketData['subject']} (Priority: {$ticketData['priority']})";
                break;
                
            case 'ticket_assigned':
                $assigneeName = isset($additionalData['assignee_name']) ? $additionalData['assignee_name'] : $ticketData['assignee_name'];
                $content['subject'] = "[{$ticketData['key']}] Ticket Assigned: {$ticketData['subject']}";
                $content['email_body'] = "
Hello {recipient_name},

Ticket {$ticketData['key']} has been assigned to {$assigneeName}.

Subject: {$ticketData['subject']}
Priority: {$ticketData['priority']}
Status: {$ticketData['status']}

View ticket: {$ticketUrl}

Best regards,
ITSPtickets Support Team
                ";
                $content['sms_body'] = "Ticket {$ticketData['key']} assigned to {$assigneeName}";
                break;
                
            case 'message_added':
                $senderName = isset($additionalData['sender_name']) ? $additionalData['sender_name'] : 'Support Team';
                $content['subject'] = "[{$ticketData['key']}] New Message: {$ticketData['subject']}";
                $content['email_body'] = "
Hello {recipient_name},

A new message has been added to ticket {$ticketData['key']}:

From: {$senderName}
Message: {$additionalData['message']}

View ticket: {$ticketUrl}

Best regards,
ITSPtickets Support Team
                ";
                $content['sms_body'] = "New message on ticket {$ticketData['key']} from {$senderName}";
                break;
                
            case 'ticket_resolved':
                $content['subject'] = "[{$ticketData['key']}] Ticket Resolved: {$ticketData['subject']}";
                $content['email_body'] = "
Hello {recipient_name},

Your support ticket has been resolved:

Ticket: {$ticketData['key']}
Subject: {$ticketData['subject']}

Resolution: " . (isset($additionalData['resolution']) ? $additionalData['resolution'] : 'Please check the ticket for resolution details.') . "

If you're satisfied with the resolution, no further action is needed. If you have any questions or the issue persists, please reply to this email.

View ticket: {$ticketUrl}

Best regards,
ITSPtickets Support Team
                ";
                $content['sms_body'] = "Ticket {$ticketData['key']} has been resolved";
                break;
                
            case 'sla_breach':
                $breachType = isset($additionalData['breach_type']) ? $additionalData['breach_type'] : 'SLA';
                $content['subject'] = "[SLA BREACH] {$ticketData['key']}: {$ticketData['subject']}";
                $content['email_body'] = "
URGENT: SLA Breach Alert

Ticket {$ticketData['key']} has breached its {$breachType} SLA:

Subject: {$ticketData['subject']}
Priority: {$ticketData['priority']}
Created: {$ticketData['created_at']}
SLA Policy: {$ticketData['sla_name']}

Immediate attention required!

View ticket: {$ticketUrl}

ITSPtickets Alert System
                ";
                $content['sms_body'] = "SLA BREACH: Ticket {$ticketData['key']} requires immediate attention!";
                break;
        }
        
        return $content;
    }
    
    /**
     * Send email notification
     */
    private function sendEmail($recipient, $content)
    {
        if (!$this->emailConfig['enabled'] || !$recipient['email']) {
            return false;
        }
        
        // Replace recipient placeholder
        $emailBody = str_replace('{recipient_name}', $recipient['name'], $content['email_body']);
        
        // Simple email sending using mail() function
        // In production, use a proper SMTP library like PHPMailer
        $headers = [
            'From: ' . $this->emailConfig['from_name'] . ' <' . $this->emailConfig['from_email'] . '>',
            'Reply-To: ' . $this->emailConfig['from_email'],
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: ITSPtickets'
        ];
        
        return mail(
            $recipient['email'],
            $content['subject'],
            $emailBody,
            implode("\r\n", $headers)
        );
    }
    
    /**
     * Send SMS notification
     */
    private function sendSms($recipient, $content)
    {
        if (!$this->smsConfig['enabled'] || !$recipient['phone'] || !$this->smsConfig['api_key']) {
            return false;
        }
        
        // Prepare SMS data
        $data = [
            'to' => $recipient['phone'],
            'from' => $this->smsConfig['from_number'],
            'message' => $content['sms_body']
        ];
        
        // Send SMS via HTTP API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->smsConfig['api_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->smsConfig['api_key']
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode == 200;
    }
    
    /**
     * Log notification activity
     */
    private function logNotification($event, $ticketId, $recipients, $results)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (ticket_id, event_type, recipients, results, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $ticketId,
            $event,
            json_encode(array_column($recipients, 'email')),
            json_encode($results)
        ]);
    }
    
    /**
     * Check for SLA breaches and send alerts
     */
    public function checkSlaBreaches()
    {
        require_once 'sla-service-simple.php';
        $slaService = new SlaServiceSimple($this->pdo);
        
        $breaches = $slaService->getSlaBreaches();
        
        foreach ($breaches as $breach) {
            $breachType = '';
            if ($breach['response_breach']) $breachType .= 'Response ';
            if ($breach['resolution_breach']) $breachType .= 'Resolution ';
            
            $this->sendNotification('sla_breach', $breach['ticket']['id'], [
                'breach_type' => trim($breachType)
            ]);
        }
        
        return count($breaches);
    }
    
    /**
     * Send test notification
     */
    public function sendTestNotification($email)
    {
        if (!$email) {
            return false;
        }
        
        $recipient = [
            'id' => 0,
            'name' => 'Test User',
            'email' => $email,
            'phone' => '',
            'type' => 'test'
        ];
        
        $content = [
            'subject' => 'ITSPtickets - Test Notification',
            'email_body' => "
Hello Test User,

This is a test notification from ITSPtickets to verify that email notifications are working correctly.

If you received this message, your notification system is properly configured.

Best regards,
ITSPtickets Support Team
            ",
            'sms_body' => 'ITSPtickets test notification - system is working!'
        ];
        
        return $this->sendEmail($recipient, $content);
    }
}

// Notification triggers - can be called from other parts of the system
function triggerNotification($event, $ticketId, $additionalData = [])
{
    try {
        require_once 'config/database.php';
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $notificationService = new NotificationServiceSimple($pdo);
        return $notificationService->sendNotification($event, $ticketId, $additionalData);
        
    } catch (Exception $e) {
        error_log("Notification trigger error: " . $e->getMessage());
        return false;
    }
}

// Test page and SLA checker
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    require_once 'config/database.php';
    
    try {
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $notificationService = new NotificationServiceSimple($pdo);
        
        // Handle actions
        if (isset($_POST['action']) && $_POST['action']) {
            switch ($_POST['action']) {
                case 'test_email':
                    if (!empty($_POST['email'])) {
                        $result = $notificationService->sendTestNotification($_POST['email']);
                        $message = $result ? 'Test email sent successfully!' : 'Failed to send test email.';
                    } else {
                        $message = 'Please enter an email address.';
                    }
                    break;
                    
                case 'check_sla':
                    $breachCount = $notificationService->checkSlaBreaches();
                    $message = "Checked SLA breaches. Found {$breachCount} breach(es).";
                    break;
            }
        }
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Notification Service Test - ITSPtickets</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
                .form-group { margin-bottom: 20px; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input, select { width: 300px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
                button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px; }
                button:hover { background: #005a87; }
                .message { padding: 15px; border-radius: 4px; margin: 20px 0; }
                .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                .section { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
            </style>
        </head>
        <body>
            <h1>Notification Service Test</h1>
            
            <?php if (isset($message)): ?>
                <div class="message success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <div class="section">
                <h2>Test Email Notification</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="test_email">
                    <div class="form-group">
                        <label for="email">Email Address:</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    <button type="submit">Send Test Email</button>
                </form>
            </div>
            
            <div class="section">
                <h2>SLA Breach Check</h2>
                <p>Check for SLA breaches and send alerts to supervisors.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="check_sla">
                    <button type="submit">Check SLA Breaches</button>
                </form>
            </div>
            
            <div class="section">
                <h2>Notification Events</h2>
                <p>The following events trigger automatic notifications:</p>
                <ul>
                    <li><strong>ticket_created</strong> - Notifies requester and assigned agent</li>
                    <li><strong>ticket_assigned</strong> - Notifies new assignee and requester</li>
                    <li><strong>message_added</strong> - Notifies all parties</li>
                    <li><strong>ticket_resolved</strong> - Notifies requester</li>
                    <li><strong>ticket_closed</strong> - Notifies requester</li>
                    <li><strong>sla_breach</strong> - Notifies supervisors and admins</li>
                </ul>
            </div>
            
            <div class="section">
                <h2>Integration</h2>
                <p>To trigger notifications from other parts of the system, use:</p>
                <pre><code>triggerNotification('event_name', $ticketId, $additionalData);</code></pre>
                
                <p>Set up a cron job to check for SLA breaches:</p>
                <pre><code>*/15 * * * * php <?= __FILE__ ?> --check-sla</code></pre>
            </div>
        </body>
        </html>
        <?php
        
    } catch (Exception $e) {
        echo "<h1>Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Command line SLA checker
if (php_sapi_name() === 'cli' && in_array('--check-sla', $argv)) {
    require_once 'config/database.php';
    
    try {
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $notificationService = new NotificationServiceSimple($pdo);
        $breachCount = $notificationService->checkSlaBreaches();
        
        echo "Checked SLA breaches. Found {$breachCount} breach(es).\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>