<?php
/*
|--------------------------------------------------------------------------
| Tickets - Simple Model Redirect
|--------------------------------------------------------------------------
|
| This file redirects to the Simple model implementation for consistency.
| The Simple model contains all functionality in self-contained files.
|
*/

// Preserve any query parameters
$queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
$redirectUrl = '/ITSPtickets/tickets-simple.php' . $queryString;

header("Location: $redirectUrl", true, 301);
exit;