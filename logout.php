<?php
/*
|--------------------------------------------------------------------------
| Logout - Simple Model
|--------------------------------------------------------------------------
|
| Simple logout functionality without MVC controllers.
| Handles session cleanup and redirects to login page.
|
*/

// Start session
session_start();

try {
    // Clear all session data
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
    
    // Clear remember token cookie if exists
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }
    
    // Clear any other authentication cookies
    $cookiesToClear = ['auth_token', 'user_session', 'api_key'];
    foreach ($cookiesToClear as $cookie) {
        if (isset($_COOKIE[$cookie])) {
            setcookie($cookie, '', time() - 3600, '/', '', false, true);
        }
    }
    
    // Start new session for success message
    session_start();
    $_SESSION['success'] = 'You have been logged out successfully';
    
    // Redirect to login page
    header('Location: /ITSPtickets/login.php');
    exit;
    
} catch (Exception $e) {
    // If there's any error during logout, still clear what we can and redirect
    error_log("Logout error: " . $e->getMessage());
    
    // Force clear session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
    
    // Start new session for error message
    session_start();
    $_SESSION['error'] = 'Logout completed with minor issues';
    
    // Redirect to login
    header('Location: /ITSPtickets/login.php');
    exit;
}