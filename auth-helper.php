<?php
/*
|--------------------------------------------------------------------------
| Simple Model Authentication Helper
|--------------------------------------------------------------------------
| Standardized authentication functions for Simple Model architecture
| Reduces code duplication while maintaining self-contained file philosophy
*/

/**
 * Start session and check if user is authenticated
 * Redirects to login if not authenticated
 */
function requireAuth($requiredRole = null) {
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        header('Location: /ITSPtickets/login.php');
        exit;
    }
    
    return $_SESSION['user_id'];
}

/**
 * Get current authenticated user with role verification
 * Returns user data array or dies with error message
 */
function getCurrentUser($pdo, $requiredRole = null) {
    $userId = requireAuth($requiredRole);
    
    try {
        // Get current user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || !$user['active']) {
            session_destroy();
            header('Location: /ITSPtickets/login.php');
            exit;
        }
        
        // Check role requirement
        if ($requiredRole && !checkUserRole($user, $requiredRole)) {
            http_response_code(403);
            die("Access denied. {$requiredRole} permissions required.");
        }
        
        return $user;
        
    } catch (PDOException $e) {
        error_log("Authentication error: " . $e->getMessage());
        die("Authentication error. Please try again.");
    }
}

/**
 * Check if user has required role(s)
 */
function checkUserRole($user, $requiredRole) {
    if (!$requiredRole) {
        return true;
    }
    
    $userRole = $user['role'];
    
    // Handle array of roles
    if (is_array($requiredRole)) {
        return in_array($userRole, $requiredRole);
    }
    
    // Handle string role requirements
    switch ($requiredRole) {
        case 'admin':
            return $userRole === 'admin';
            
        case 'supervisor':
            return in_array($userRole, ['admin', 'supervisor']);
            
        case 'agent':
            return in_array($userRole, ['admin', 'supervisor', 'agent']);
            
        case 'staff':
            return in_array($userRole, ['admin', 'supervisor', 'agent']);
            
        default:
            return $userRole === $requiredRole;
    }
}

/**
 * Quick auth check for different permission levels
 */
function requireAdminAuth() {
    return requireAuth('admin');
}

function requireSupervisorAuth() {
    return requireAuth('supervisor'); // includes admin
}

function requireStaffAuth() {
    return requireAuth('staff'); // includes admin, supervisor, agent
}

/**
 * Get authenticated user with admin role requirement
 */
function getCurrentAdmin($pdo) {
    return getCurrentUser($pdo, 'admin');
}

/**
 * Get authenticated user with supervisor+ role requirement  
 */
function getCurrentSupervisor($pdo) {
    return getCurrentUser($pdo, 'supervisor');
}

/**
 * Get authenticated user with staff role requirement
 */
function getCurrentStaff($pdo) {
    return getCurrentUser($pdo, 'staff');
}

/**
 * Destroy session and redirect to login
 */
function logout() {
    session_start();
    
    try {
        // Destroy session data
        $_SESSION = [];
        
        if (session_id()) {
            session_destroy();
        }
        
        // Delete session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time()-3600, '/');
        }
        
    } catch (Exception $e) {
        error_log("Logout error: " . $e->getMessage());
    }
    
    // Always redirect to login regardless of errors
    header('Location: /ITSPtickets/login.php');
    exit;
}

/**
 * Check if user is already logged in (for login pages)
 */
function redirectIfLoggedIn() {
    session_start();
    
    if (isset($_SESSION['user_id'])) {
        header('Location: /ITSPtickets/dashboard-simple.php');
        exit;
    }
}

/**
 * Simple Model authentication pattern template
 * Usage example for new files:
 * 
 * <?php
 * require_once 'auth-helper.php';
 * require_once 'db-connection.php';
 * 
 * $pdo = createDatabaseConnection();
 * $user = getCurrentUser($pdo, 'admin'); // or 'supervisor', 'staff', etc.
 * 
 * // Your functionality here...
 */