<?php

class EmailWebhookSimple
{
    private $pdo;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Handle incoming email webhook
     */
    public function handleWebhook($data)
    {
        try {
            // Parse email data based on common webhook formats
            $emailData = $this->parseEmailData($data);
            
            if (!$emailData) {
                return ['success' => false, 'error' => 'Invalid email data'];
            }
            
            // Check if this is a reply to existing ticket
            $ticketId = $this->extractTicketId($emailData['subject']);
            
            if ($ticketId) {
                // Add message to existing ticket
                return $this->addMessageToTicket($ticketId, $emailData);
            } else {
                // Create new ticket
                return $this->createTicketFromEmail($emailData);
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Parse email data from webhook payload
     */
    private function parseEmailData($data)
    {
        // Handle different webhook formats (Mailgun, SendGrid, Postmark, etc.)
        $emailData = null;
        
        // Mailgun format
        if (isset($data['sender']) && isset($data['subject'])) {
            $emailData = [
                'from' => $data['sender'],
                'to' => $data['recipient'] ?? '',
                'subject' => $data['subject'],
                'body_text' => $data['body-plain'] ?? '',
                'body_html' => $data['body-html'] ?? '',
                'timestamp' => $data['timestamp'] ?? time()
            ];
        }
        // SendGrid format
        elseif (isset($data['from']) && isset($data['subject'])) {
            $emailData = [
                'from' => $data['from'],
                'to' => $data['to'] ?? '',
                'subject' => $data['subject'],
                'body_text' => $data['text'] ?? '',
                'body_html' => $data['html'] ?? '',
                'timestamp' => time()
            ];
        }
        // Generic format
        elseif (isset($data['email'])) {
            $email = $data['email'];
            $emailData = [
                'from' => $email['from'] ?? '',
                'to' => $email['to'] ?? '',
                'subject' => $email['subject'] ?? '',
                'body_text' => $email['text'] ?? $email['body'] ?? '',
                'body_html' => $email['html'] ?? '',
                'timestamp' => $email['timestamp'] ?? time()
            ];
        }
        
        if ($emailData) {
            // Clean and validate email data
            $emailData['from'] = $this->extractEmail($emailData['from']);
            $emailData['subject'] = trim($emailData['subject']);
            $emailData['body'] = !empty($emailData['body_text']) ? $emailData['body_text'] : strip_tags($emailData['body_html']);
        }
        
        return $emailData;
    }
    
    /**
     * Extract email address from string
     */
    private function extractEmail($emailString)
    {
        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $emailString, $matches)) {
            return strtolower($matches[1]);
        }
        return strtolower(trim($emailString));
    }
    
    /**
     * Extract ticket ID from subject line
     */
    private function extractTicketId($subject)
    {
        // Look for ticket key pattern (e.g., TKT-0001, [TKT-0001], Re: TKT-0001)
        if (preg_match('/\b(TKT-\d{4})\b/i', $subject, $matches)) {
            $ticketKey = strtoupper($matches[1]);
            
            $stmt = $this->pdo->prepare("SELECT id FROM tickets WHERE `key` = ?");
            $stmt->execute([$ticketKey]);
            $ticket = $stmt->fetch();
            
            return $ticket ? $ticket['id'] : null;
        }
        
        return null;
    }
    
    /**
     * Create new ticket from email
     */
    private function createTicketFromEmail($emailData)
    {
        // Find or create requester
        $stmt = $this->pdo->prepare("SELECT * FROM requesters WHERE email = ? AND active = 1");
        $stmt->execute([$emailData['from']]);
        $requester = $stmt->fetch();
        
        if (!$requester) {
            // Extract name from email or use email as name
            $name = $this->extractNameFromEmail($emailData['from']);
            
            $stmt = $this->pdo->prepare("INSERT INTO requesters (name, email, active) VALUES (?, ?, 1)");
            $stmt->execute([$name, $emailData['from']]);
            $requesterId = $this->pdo->lastInsertId();
        } else {
            $requesterId = $requester['id'];
        }
        
        // Generate ticket key
        $stmt = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(`key`, 5) AS UNSIGNED)) as max_num FROM tickets WHERE `key` LIKE 'TKT-%'");
        $stmt->execute();
        $result = $stmt->fetch();
        $nextNum = ($result['max_num'] ?? 0) + 1;
        $ticketKey = 'TKT-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
        
        // Determine ticket priority and type based on subject
        $priority = $this->determinePriority($emailData['subject'], $emailData['body']);
        $type = $this->determineType($emailData['subject'], $emailData['body']);
        
        // Create ticket
        $stmt = $this->pdo->prepare("INSERT INTO tickets (
            `key`, type, subject, description, priority, status, 
            requester_id, channel, created_at
        ) VALUES (?, ?, ?, ?, ?, 'new', ?, 'email', NOW())");
        
        $stmt->execute([
            $ticketKey,
            $type,
            $emailData['subject'],
            $emailData['body'],
            $priority,
            $requesterId
        ]);
        
        $ticketId = $this->pdo->lastInsertId();
        
        // Auto-assign SLA policy
        $this->assignSlaPolicy($ticketId, $type, $priority);
        
        // Log the creation event
        $stmt = $this->pdo->prepare("INSERT INTO ticket_events (ticket_id, event_type, description) VALUES (?, ?, ?)");
        $stmt->execute([$ticketId, 'created', "Ticket created from email: {$emailData['from']}"]);
        
        return [
            'success' => true,
            'action' => 'created',
            'ticket_id' => $ticketId,
            'ticket_key' => $ticketKey
        ];
    }
    
    /**
     * Add message to existing ticket
     */
    private function addMessageToTicket($ticketId, $emailData)
    {
        // Get ticket and verify requester
        $stmt = $this->pdo->prepare("SELECT t.*, r.email FROM tickets t JOIN requesters r ON t.requester_id = r.id WHERE t.id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            return ['success' => false, 'error' => 'Ticket not found'];
        }
        
        // Check if email is from the requester
        if (strtolower($ticket['email']) !== strtolower($emailData['from'])) {
            // Email from different sender - create new ticket instead
            return $this->createTicketFromEmail($emailData);
        }
        
        // Add message to ticket
        $stmt = $this->pdo->prepare("INSERT INTO ticket_messages (
            ticket_id, sender_type, sender_id, message, is_private, channel, created_at
        ) VALUES (?, 'requester', ?, ?, 0, 'email', NOW())");
        
        $stmt->execute([$ticketId, $ticket['requester_id'], $emailData['body']]);
        
        // Update ticket status if it was closed/resolved
        if (in_array($ticket['status'], ['closed', 'resolved'])) {
            $stmt = $this->pdo->prepare("UPDATE tickets SET status = 'in_progress', last_update_at = NOW() WHERE id = ?");
            $stmt->execute([$ticketId]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE tickets SET last_update_at = NOW() WHERE id = ?");
            $stmt->execute([$ticketId]);
        }
        
        return [
            'success' => true,
            'action' => 'message_added',
            'ticket_id' => $ticketId,
            'ticket_key' => $ticket['key']
        ];
    }
    
    /**
     * Extract name from email address
     */
    private function extractNameFromEmail($email)
    {
        $parts = explode('@', $email);
        $localPart = $parts[0];
        
        // Convert common patterns to readable names
        $name = str_replace(['.', '_', '-'], ' ', $localPart);
        $name = ucwords($name);
        
        return $name ?: $email;
    }
    
    /**
     * Determine ticket priority from subject/body
     */
    private function determinePriority($subject, $body)
    {
        $text = strtolower($subject . ' ' . $body);
        
        if (preg_match('/\b(urgent|critical|emergency|down|outage|broken)\b/', $text)) {
            return 'urgent';
        } elseif (preg_match('/\b(high|important|asap|priority)\b/', $text)) {
            return 'high';
        } elseif (preg_match('/\b(low|minor|when possible)\b/', $text)) {
            return 'low';
        }
        
        return 'normal';
    }
    
    /**
     * Determine ticket type from subject/body
     */
    private function determineType($subject, $body)
    {
        $text = strtolower($subject . ' ' . $body);
        
        if (preg_match('/\b(request|need|want|add|new|create)\b/', $text)) {
            return 'request';
        } elseif (preg_match('/\b(job|task|work|project|maintenance)\b/', $text)) {
            return 'job';
        }
        
        return 'incident';
    }
    
    /**
     * Assign SLA policy to ticket
     */
    private function assignSlaPolicy($ticketId, $type, $priority)
    {
        $stmt = $this->pdo->prepare("SELECT id FROM sla_policies WHERE type = ? AND priority = ? AND active = 1 LIMIT 1");
        $stmt->execute([$type, $priority]);
        $slaPolicy = $stmt->fetch();
        
        if ($slaPolicy) {
            $stmt = $this->pdo->prepare("UPDATE tickets SET sla_policy_id = ? WHERE id = ?");
            $stmt->execute([$slaPolicy['id'], $ticketId]);
        }
    }
}

// Webhook endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';
    
    try {
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $webhook = new EmailWebhookSimple($pdo);
        
        // Get POST data
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        // If JSON decode fails, try form data
        if (!$data) {
            $data = $_POST;
        }
        
        // Process webhook
        $result = $webhook->handleWebhook($data);
        
        // Return response
        header('Content-Type: application/json');
        echo json_encode($result);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// Test page
elseif (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Email Webhook Test - ITSPtickets</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 5px; font-weight: bold; }
            input, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
            button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
            button:hover { background: #005a87; }
            .result { margin-top: 20px; padding: 15px; border-radius: 4px; }
            .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        </style>
    </head>
    <body>
        <h1>Email Webhook Test</h1>
        <p>This tool simulates incoming emails to test the webhook functionality.</p>
        
        <form method="POST" id="emailForm">
            <div class="form-group">
                <label for="from">From Email:</label>
                <input type="email" name="sender" id="from" value="customer@example.com" required>
            </div>
            
            <div class="form-group">
                <label for="subject">Subject:</label>
                <input type="text" name="subject" id="subject" value="Urgent: System is down" required>
            </div>
            
            <div class="form-group">
                <label for="body">Message Body:</label>
                <textarea name="body-plain" id="body" rows="6" required>Hello,

Our main system appears to be down and users cannot access the application. This is affecting all our customers. Please help urgently.

Thanks,
Customer</textarea>
            </div>
            
            <button type="submit">Send Test Email</button>
        </form>
        
        <div id="result"></div>
        
        <h2>Webhook URL</h2>
        <p>Configure your email service to POST to: <code><?= $_SERVER['REQUEST_SCHEME'] ?>://<?= $_SERVER['HTTP_HOST'] ?><?= $_SERVER['REQUEST_URI'] ?></code></p>
        
        <h2>Supported Formats</h2>
        <ul>
            <li>Mailgun webhooks</li>
            <li>SendGrid webhooks</li>
            <li>Generic email webhooks</li>
        </ul>
        
        <script>
        document.getElementById('emailForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const resultDiv = document.getElementById('result');
                resultDiv.className = 'result ' + (data.success ? 'success' : 'error');
                resultDiv.innerHTML = '<strong>' + (data.success ? 'Success' : 'Error') + ':</strong> ' + 
                                     (data.success ? 
                                      'Action: ' + data.action + (data.ticket_key ? ', Ticket: ' + data.ticket_key : '') :
                                      data.error);
            })
            .catch(error => {
                const resultDiv = document.getElementById('result');
                resultDiv.className = 'result error';
                resultDiv.innerHTML = '<strong>Error:</strong> ' + error.message;
            });
        });
        </script>
    </body>
    </html>
    <?php
}
?>