<?php
/*
|--------------------------------------------------------------------------
| API Tickets Endpoint - Simple Model
|--------------------------------------------------------------------------
|
| Self-contained API endpoint for tickets using the Simple model approach.
| All functionality is embedded without external controllers.
|
*/

// Set headers for API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Start session for authentication
session_start();

// Database connection
try {
    $config = require dirname(__DIR__) . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Simple API key authentication check
function checkApiAuth() {
    // Check for API key in headers or session
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
    
    if ($apiKey) {
        // Validate API key against database
        global $pdo;
        $stmt = $pdo->prepare("SELECT user_id FROM api_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$apiKey]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    
    // Check session authentication
    if (isset($_SESSION['user_id'])) {
        return true;
    }
    
    return false;
}

// Authentication check
if (!checkApiAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Get request method and ticket ID
$method = $_SERVER['REQUEST_METHOD'];
$ticketId = $_GET['id'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($ticketId) {
                // Get specific ticket
                $stmt = $pdo->prepare("
                    SELECT t.*,
                           r.name as requester_name, r.email as requester_email,
                           u.name as assignee_name,
                           sp.name as sla_name
                    FROM tickets t
                    LEFT JOIN requesters r ON t.requester_id = r.id
                    LEFT JOIN users u ON t.assignee_id = u.id
                    LEFT JOIN sla_policies sp ON t.sla_policy_id = sp.id
                    WHERE t.id = ?
                ");
                $stmt->execute([$ticketId]);
                $ticket = $stmt->fetch();
                
                if (!$ticket) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Ticket not found']);
                    exit;
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $ticket
                ]);
            } else {
                // List tickets with filters
                $page = max(1, $_GET['page'] ?? 1);
                $limit = min(100, $_GET['limit'] ?? 20);
                $offset = ($page - 1) * $limit;
                
                $where = ['1=1'];
                $params = [];
                
                // Apply filters
                if (!empty($_GET['status'])) {
                    $where[] = "t.status = ?";
                    $params[] = $_GET['status'];
                }
                
                if (!empty($_GET['priority'])) {
                    $where[] = "t.priority = ?";
                    $params[] = $_GET['priority'];
                }
                
                if (!empty($_GET['assignee_id'])) {
                    $where[] = "t.assignee_id = ?";
                    $params[] = $_GET['assignee_id'];
                }
                
                $sql = "SELECT t.*,
                               r.name as requester_name, r.email as requester_email,
                               u.name as assignee_name
                        FROM tickets t
                        LEFT JOIN requesters r ON t.requester_id = r.id
                        LEFT JOIN users u ON t.assignee_id = u.id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY t.created_at DESC
                        LIMIT ? OFFSET ?";
                
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $tickets = $stmt->fetchAll();
                
                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM tickets t WHERE " . implode(' AND ', $where);
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
                $total = $countStmt->fetch()['total'];
                
                echo json_encode([
                    'success' => true,
                    'data' => $tickets,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]);
            }
            break;
            
        case 'POST':
            if ($ticketId) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot POST to specific ticket ID. Use PUT to update.']);
                exit;
            }
            
            // Create new ticket
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            $required = ['subject', 'description', 'requester_email', 'type', 'priority'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Field '{$field}' is required"]);
                    exit;
                }
            }
            
            // Find or create requester
            $stmt = $pdo->prepare("SELECT * FROM requesters WHERE email = ? AND active = 1");
            $stmt->execute([$input['requester_email']]);
            $requester = $stmt->fetch();
            
            if (!$requester) {
                $stmt = $pdo->prepare("INSERT INTO requesters (name, email, active) VALUES (?, ?, 1)");
                $stmt->execute([
                    $input['requester_name'] ?? $input['requester_email'],
                    $input['requester_email']
                ]);
                $requesterId = $pdo->lastInsertId();
            } else {
                $requesterId = $requester['id'];
            }
            
            // Generate ticket key
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(`key`, 5) AS UNSIGNED)) as max_num FROM tickets WHERE `key` LIKE 'TKT-%'");
            $stmt->execute();
            $result = $stmt->fetch();
            $nextNum = ($result['max_num'] ?? 0) + 1;
            $ticketKey = 'TKT-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
            
            // Create ticket
            $stmt = $pdo->prepare("INSERT INTO tickets (
                `key`, type, subject, description, priority, status,
                requester_id, assignee_id, channel, created_at
            ) VALUES (?, ?, ?, ?, ?, 'new', ?, ?, 'api', NOW())");
            
            $stmt->execute([
                $ticketKey,
                $input['type'],
                $input['subject'],
                $input['description'],
                $input['priority'],
                $requesterId,
                $input['assignee_id'] ?? null
            ]);
            
            $newTicketId = $pdo->lastInsertId();
            
            // Return created ticket
            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
            $stmt->execute([$newTicketId]);
            $ticket = $stmt->fetch();
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Ticket created successfully',
                'data' => $ticket
            ]);
            break;
            
        case 'PUT':
            if (!$ticketId) {
                http_response_code(400);
                echo json_encode(['error' => 'PUT requires a ticket ID parameter']);
                exit;
            }
            
            // Update ticket
            $input = json_decode(file_get_contents('php://input'), true);
            
            $fields = [];
            $params = [];
            
            $allowedFields = ['subject', 'description', 'status', 'priority', 'assignee_id'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $fields[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                exit;
            }
            
            $fields[] = "updated_at = NOW()";
            $params[] = $ticketId;
            
            $sql = "UPDATE tickets SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Ticket not found']);
                exit;
            }
            
            // Return updated ticket
            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'message' => 'Ticket updated successfully',
                'data' => $ticket
            ]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}