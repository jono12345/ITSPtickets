<?php
/*
|--------------------------------------------------------------------------
| ITSPtickets Application Entry Point - Simple Model
|--------------------------------------------------------------------------
|
| This file serves as the entry point for the ITSPtickets application
| redirecting to the staff login for internal users.
| Customer portal is accessible via /portal-simple.php
|
*/

// Check if user is already logged in
session_start();

if (isset($_SESSION['user_id'])) {
    // Staff user is logged in, redirect to dashboard
    header('Location: /ITSPtickets/dashboard-simple.php', true, 301);
} else {
    // Not logged in, redirect to login
    header('Location: /ITSPtickets/login.php', true, 301);
}
exit;