<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Load the system
require_once 'config/database.php';

try {
    $config = require 'config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Set JSON content type
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    // Get current user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid user session']);
        exit;
    }
    
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetTickets($pdo, $user);
            break;
            
        case 'POST':
            handleCreateTicket($pdo, $user);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

function handleGetTickets($pdo, $user) {
    // Get query parameters
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $page = (int)($_GET['page'] ?? 1);
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? null;
    $priority = $_GET['priority'] ?? null;
    
    // Build SQL
    $sql = "SELECT t.*, 
                   r.name as requester_name, r.email as requester_email,
                   u.name as assignee_name
            FROM tickets t
            LEFT JOIN requesters r ON t.requester_id = r.id
            LEFT JOIN users u ON t.assignee_id = u.id
            WHERE 1=1";
    
    $params = [];
    
    // Apply filters
    if ($status) {
        $sql .= " AND t.status = ?";
        $params[] = $status;
    }
    
    if ($priority) {
        $sql .= " AND t.priority = ?";
        $params[] = $priority;
    }
    
    // Apply role-based filtering for agents
    if ($user['role'] === 'agent') {
        $sql .= " AND t.assignee_id = ?";
        $params[] = $user['id'];
    }
    
    // Add ordering and pagination
    $sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as count FROM tickets t WHERE 1=1";
    $countParams = [];
    
    if ($status) {
        $countSql .= " AND t.status = ?";
        $countParams[] = $status;
    }
    
    if ($priority) {
        $countSql .= " AND t.priority = ?";
        $countParams[] = $priority;
    }
    
    if ($user['role'] === 'agent') {
        $countSql .= " AND t.assignee_id = ?";
        $countParams[] = $user['id'];
    }
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalCount = $countStmt->fetch()['count'];
    
    // Format tickets for API response
    $formattedTickets = [];
    foreach ($tickets as $ticket) {
        $formattedTickets[] = [
            'id' => (int)$ticket['id'],
            'key' => $ticket['key'],
            'subject' => $ticket['subject'],
            'description' => $ticket['description'],
            'type' => $ticket['type'],
            'priority' => $ticket['priority'],
            'status' => $ticket['status'],
            'created_at' => $ticket['created_at'],
            'updated_at' => $ticket['updated_at'],
            'requester' => $ticket['requester_name'] ? [
                'id' => (int)$ticket['requester_id'],
                'name' => $ticket['requester_name'],
                'email' => $ticket['requester_email']
            ] : null,
            'assignee' => $ticket['assignee_name'] ? [
                'id' => (int)$ticket['assignee_id'],
                'name' => $ticket['assignee_name']
            ] : null
        ];
    }
    
    $response = [
        'success' => true,
        'data' => $formattedTickets,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => (int)$totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ];
    
    echo json_encode($response);
}

function handleCreateTicket($pdo, $user) {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST; // Fallback to form data
    }
    
    // Validate required fields
    $required = ['subject', 'description', 'type', 'priority'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Field '$field' is required"]);
            return;
        }
    }
    
    // Validate enums
    $validTypes = ['incident', 'request', 'job'];
    $validPriorities = ['low', 'normal', 'high', 'urgent'];
    
    if (!in_array($input['type'], $validTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid ticket type']);
        return;
    }
    
    if (!in_array($input['priority'], $validPriorities)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid priority']);
        return;
    }
    
    // Handle requester
    $requesterId = null;
    if (!empty($input['requester_email'])) {
        $stmt = $pdo->prepare("SELECT id FROM requesters WHERE email = ?");
        $stmt->execute([$input['requester_email']]);
        $requester = $stmt->fetch();
        
        if (!$requester) {
            // Create new requester
            $stmt = $pdo->prepare("INSERT INTO requesters (email, name) VALUES (?, ?)");
            $requesterName = $input['requester_name'] ?? $input['requester_email'];
            $stmt->execute([$input['requester_email'], $requesterName]);
            $requesterId = $pdo->lastInsertId();
        } else {
            $requesterId = $requester['id'];
        }
    }
    
    // Generate ticket key
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(`key`, 5) AS UNSIGNED)) as max_num FROM tickets WHERE `key` LIKE 'TKT-%'");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    $ticketKey = 'TKT-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    
    // Create ticket
    $sql = "INSERT INTO tickets (key, type, subject, description, priority, status, requester_id, assignee_id, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, 'new', ?, ?, NOW(), NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $ticketKey,
        $input['type'],
        $input['subject'],
        $input['description'],
        $input['priority'],
        $requesterId,
        $input['assignee_id'] ?? null
    ]);
    
    $ticketId = $pdo->lastInsertId();
    
    // Get the created ticket
    $stmt = $pdo->prepare("
        SELECT t.*, 
               r.name as requester_name, r.email as requester_email,
               u.name as assignee_name
        FROM tickets t
        LEFT JOIN requesters r ON t.requester_id = r.id
        LEFT JOIN users u ON t.assignee_id = u.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    
    $formattedTicket = [
        'id' => (int)$ticket['id'],
        'key' => $ticket['key'],
        'subject' => $ticket['subject'],
        'description' => $ticket['description'],
        'type' => $ticket['type'],
        'priority' => $ticket['priority'],
        'status' => $ticket['status'],
        'created_at' => $ticket['created_at'],
        'updated_at' => $ticket['updated_at'],
        'requester' => $ticket['requester_name'] ? [
            'id' => (int)$ticket['requester_id'],
            'name' => $ticket['requester_name'],
            'email' => $ticket['requester_email']
        ] : null,
        'assignee' => $ticket['assignee_name'] ? [
            'id' => (int)$ticket['assignee_id'],
            'name' => $ticket['assignee_name']
        ] : null
    ];
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Ticket created successfully',
        'data' => $formattedTicket
    ]);
}
?>