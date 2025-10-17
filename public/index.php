<?php
/*
|--------------------------------------------------------------------------
| ITSPtickets Public Entry Point - Simple Model
|--------------------------------------------------------------------------
|
| This file serves as the public entry point for the ITSPtickets application
| redirecting to the Simple model implementation for consistency.
| All functionality is contained in self-contained Simple files.
|
*/

// Basic routing - handle subdirectory
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove the subdirectory from the URI if present
$basePath = '/ITSPtickets';
if (strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}

// Ensure we have at least a slash
if (empty($requestUri)) {
    $requestUri = '/';
}

// Simple routing to redirect to appropriate Simple files
switch ($requestUri) {
    case '/':
    case '/index.php':
        header('Location: /ITSPtickets/login.php', true, 301);
        break;
        
    case '/login':
    case '/login.php':
        header('Location: /ITSPtickets/login.php', true, 301);
        break;
        
    case '/logout':
    case '/logout.php':
        header('Location: /ITSPtickets/logout.php', true, 301);
        break;
        
    case '/tickets':
        header('Location: /ITSPtickets/tickets-simple.php', true, 301);
        break;
        
    case '/create-ticket':
        header('Location: /ITSPtickets/create-ticket-simple.php', true, 301);
        break;
        
    case '/ticket':
        $queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
        header('Location: /ITSPtickets/ticket-simple.php' . $queryString, true, 301);
        break;
        
    case '/api/tickets':
        header('Location: /ITSPtickets/api/tickets.php', true, 301);
        break;
        
    case '/api':
    case '/api/':
        header('Location: /ITSPtickets/api-docs.php', true, 301);
        break;
        
    case '/api/test':
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'API routing is working! (Simple Model)',
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $requestMethod,
            'uri' => $requestUri
        ]);
        break;
        
    default:
        // Handle API ticket individual operations: /api/tickets/{id}
        if (preg_match('/^\/api\/tickets\/(\d+)$/', $requestUri, $matches)) {
            $queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
            header('Location: /ITSPtickets/api/tickets.php' . $queryString, true, 301);
            break;
        }
        
        // Default fallback to login
        header('Location: /ITSPtickets/login.php', true, 301);
        break;
}
exit;