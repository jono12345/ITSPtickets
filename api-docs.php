<?php
// Check if user is logged in for documentation access
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

$baseUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/ITSPtickets';
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>ITSPtickets API Documentation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f8fafc; 
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { 
            background: white; 
            padding: 30px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            margin-bottom: 30px;
        }
        .nav { text-align: right; margin-bottom: 20px; }
        .nav a { color: #3b82f6; text-decoration: none; margin-left: 15px; }
        .nav a:hover { text-decoration: underline; }
        h1 { color: #1f2937; margin-bottom: 10px; }
        .subtitle { color: #6b7280; font-size: 16px; }
        .section { 
            background: white; 
            padding: 30px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            margin-bottom: 30px;
        }
        h2 { color: #1f2937; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
        h3 { color: #374151; margin-bottom: 15px; margin-top: 25px; }
        .endpoint { 
            background: #f9fafb; 
            border-left: 4px solid #3b82f6; 
            padding: 15px; 
            margin: 15px 0; 
        }
        .method { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: 600; 
            text-transform: uppercase; 
            margin-right: 10px;
        }
        .method.get { background: #d1fae5; color: #065f46; }
        .method.post { background: #fef3c7; color: #92400e; }
        .method.put { background: #dbeafe; color: #1e40af; }
        .method.delete { background: #fee2e2; color: #991b1b; }
        .url { font-family: 'Monaco', 'Courier New', monospace; color: #374151; }
        .code-block { 
            background: #1f2937; 
            color: #f9fafb; 
            padding: 20px; 
            border-radius: 6px; 
            overflow-x: auto; 
            margin: 15px 0; 
        }
        .code-block pre { margin: 0; font-size: 14px; }
        .param-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0; 
        }
        .param-table th, .param-table td { 
            border: 1px solid #e5e7eb; 
            padding: 8px 12px; 
            text-align: left; 
        }
        .param-table th { background: #f9fafb; font-weight: 600; }
        .required { color: #ef4444; font-weight: 600; }
        .optional { color: #6b7280; }
        .response-example { margin: 10px 0; }
        .toc { 
            background: #f9fafb; 
            padding: 20px; 
            border-radius: 6px; 
            margin-bottom: 20px; 
        }
        .toc ul { list-style: none; }
        .toc li { margin: 5px 0; }
        .toc a { color: #3b82f6; text-decoration: none; }
        .toc a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='nav'>
            <a href='/ITSPtickets/dashboard-simple.php'>Dashboard</a>
            <a href='/ITSPtickets/tickets-simple.php'>Tickets</a>
            <a href='/ITSPtickets/logout.php'>Logout</a>
        </div>
        
        <div class='header'>
            <h1>ITSPtickets REST API</h1>
            <p class='subtitle'>Complete API documentation for integrating with ITSPtickets</p>
        </div>
        
        <div class='section'>
            <h2>Table of Contents</h2>
            <div class='toc'>
                <ul>
                    <li><a href='#overview'>Overview</a></li>
                    <li><a href='#authentication'>Authentication</a></li>
                    <li><a href='#response-format'>Response Format</a></li>
                    <li><a href='#endpoints'>Endpoints</a>
                        <ul style='margin-left: 20px;'>
                            <li><a href='#list-tickets'>List Tickets</a></li>
                            <li><a href='#create-ticket'>Create Ticket</a></li>
                            <li><a href='#get-ticket'>Get Specific Ticket</a></li>
                            <li><a href='#update-ticket'>Update Ticket</a></li>
                            <li><a href='#alternative-endpoint'>Alternative Endpoints</a></li>
                            <li><a href='#working-time'>Working Time Calculation</a></li>
                        </ul>
                    </li>
                    <li><a href='#webhooks'>Webhooks</a></li>
                    <li><a href='#examples'>Usage Examples</a>
                        <ul style='margin-left: 20px;'>
                            <li><a href='#postman-collection'>Postman/Insomnia Collection</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
        
        <div class='section' id='overview'>
            <h2>Overview</h2>
            <p>The ITSPtickets REST API allows you to:</p>
            <ul style='margin: 15px 0; padding-left: 20px;'>
                <li>Create, read, update tickets programmatically</li>
                <li>Integrate with external systems and applications</li>
                <li>Automate ticket management workflows</li>
                <li>Access real-time ticket data</li>
            </ul>
            
            <h3>Base URL</h3>
            <div class='code-block'>
                <pre><?= $baseUrl ?></pre>
            </div>
            
            <div style='background: #fef3c7; border: 1px solid #f59e0b; padding: 15px; border-radius: 6px; margin: 15px 0;'>
                <strong>⚠️ Important:</strong> This API uses direct .php file endpoints for maximum compatibility.
                All endpoint URLs end with <code>.php</code> instead of using URL routing.
            </div>
            
            <h3>Content Types</h3>
            <p>All API requests should use <code>Content-Type: application/json</code></p>
            <p>All API responses return JSON with <code>Content-Type: application/json</code></p>
        </div>
        
        <div class='section' id='authentication'>
            <h2>🔐 Authentication</h2>
            <p>All API endpoints require authentication using <strong>API keys</strong>. You must include a valid API key in the Authorization header of every request.</p>
            
            <h3>API Key Format</h3>
            <ul style='margin: 15px 0; padding-left: 20px;'>
                <li><strong>Header:</strong> <code>Authorization: Bearer YOUR_API_KEY</code></li>
                <li><strong>Alternative:</strong> <code>X-API-Key: YOUR_API_KEY</code></li>
                <li><strong>Format:</strong> 64-character hexadecimal string</li>
                <li><strong>Scope:</strong> Linked to a specific user with role-based permissions</li>
            </ul>
            
            <h3>Getting an API Key</h3>
            <p>API keys must be generated by an administrator using the command-line tool:</p>
            <div class='code-block'>
                <pre># List users to get user ID
php manage-api-keys.php users

# Create API key for a user
php manage-api-keys.php create USER_ID "API Key Name" "*" 90

# Example: Create key for user 1, valid for 90 days
php manage-api-keys.php create 1 "Production API" "*" 90</pre>
            </div>
            
            <h3>Example API Requests</h3>
            <div class='code-block'>
                <pre># Using Authorization Bearer header (recommended)
curl -H "Authorization: Bearer abc123def456..." \
     -H "Content-Type: application/json" \
     <?= $baseUrl ?>/api/tickets.php

# Using X-API-Key header (alternative)
curl -H "X-API-Key: abc123def456..." \
     -H "Content-Type: application/json" \
     <?= $baseUrl ?>/api/tickets.php</pre>
            </div>
            
            <h3>Authentication Errors</h3>
            <table class='param-table'>
                <tr><th>Status Code</th><th>Error</th><th>Meaning</th></tr>
                <tr><td>401</td><td>Invalid or missing API key</td><td>API key not provided or invalid</td></tr>
                <tr><td>401</td><td>Unauthorized</td><td>API key expired or user inactive</td></tr>
                <tr><td>403</td><td>Access denied</td><td>Insufficient permissions for the operation</td></tr>
            </table>
            
            <h3>API Key Management</h3>
            <p>API keys can be managed using the command-line interface:</p>
            <div class='code-block'>
                <pre># List all API keys
php manage-api-keys.php list

# Show specific API key details
php manage-api-keys.php show YOUR_API_KEY

# Revoke an API key
php manage-api-keys.php revoke YOUR_API_KEY</pre>
            </div>
        </div>
        
        <div class='section' id='response-format'>
            <h2>Response Format</h2>
            
            <h3>Success Response</h3>
            <div class='code-block'>
                <pre>{
  "success": true,
  "data": { /* response data */ },
  "message": "Optional success message",
  "pagination": { /* for paginated responses */ }
}</pre>
            </div>
            
            <h3>Error Response</h3>
            <div class='code-block'>
                <pre>{
  "success": false,
  "error": "Error message describing what went wrong"
}</pre>
            </div>
            
            <h3>HTTP Status Codes</h3>
            <table class='param-table'>
                <tr><th>Status</th><th>Meaning</th></tr>
                <tr><td>200</td><td>OK - Request successful</td></tr>
                <tr><td>201</td><td>Created - Resource created successfully</td></tr>
                <tr><td>400</td><td>Bad Request - Invalid request data</td></tr>
                <tr><td>401</td><td>Unauthorized - Authentication required</td></tr>
                <tr><td>403</td><td>Forbidden - Access denied</td></tr>
                <tr><td>404</td><td>Not Found - Resource not found</td></tr>
                <tr><td>405</td><td>Method Not Allowed - HTTP method not supported</td></tr>
                <tr><td>500</td><td>Internal Server Error - Server error</td></tr>
            </table>
        </div>
        
        <div class='section' id='endpoints'>
            <h2>Endpoints</h2>
            
            <div class='endpoint' id='list-tickets'>
                <h3><span class='method get'>GET</span> <span class='url'>/api/tickets.php</span></h3>
                <p>Retrieve a list of tickets with optional filtering and pagination.</p>
                
                <h4>Query Parameters</h4>
                <table class='param-table'>
                    <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td>page</td><td>integer</td><td class='optional'>Optional</td><td>Page number (default: 1)</td></tr>
                    <tr><td>limit</td><td>integer</td><td class='optional'>Optional</td><td>Items per page (default: 20, max: 100)</td></tr>
                    <tr><td>status</td><td>string</td><td class='optional'>Optional</td><td>Filter by status (new, in_progress, waiting, resolved, closed)</td></tr>
                    <tr><td>priority</td><td>string</td><td class='optional'>Optional</td><td>Filter by priority (low, normal, high, urgent)</td></tr>
                    <tr><td>assignee_id</td><td>integer</td><td class='optional'>Optional</td><td>Filter by assigned user ID</td></tr>
                </table>
                
                <h4>Example Request</h4>
                <div class='code-block'>
                    <pre>GET <?= $baseUrl ?>/api/tickets.php?status=in_progress&limit=10</pre>
                </div>
                
                <h4>Example Response</h4>
                <div class='code-block'>
                    <pre>{
  "success": true,
  "data": [
    {
      "id": 1,
      "key": "TKT-0001",
      "subject": "Login issue",
      "description": "User cannot log in to system",
      "type": "incident",
      "priority": "high",
      "status": "in_progress",
      "created_at": "2024-01-15 10:30:00",
      "updated_at": "2024-01-15 11:45:00",
      "requester": {
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com"
      },
      "assignee": {
        "id": 2,
        "name": "Support Agent"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total": 45,
    "total_pages": 5
  }
}</pre>
                </div>
            </div>
            
            <div class='endpoint' id='create-ticket'>
                <h3><span class='method post'>POST</span> <span class='url'>/api/tickets.php</span></h3>
                <p>Create a new support ticket.</p>
                
                <h4>Request Body</h4>
                <table class='param-table'>
                    <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td>subject</td><td>string</td><td class='required'>Required</td><td>Ticket subject/title</td></tr>
                    <tr><td>description</td><td>string</td><td class='required'>Required</td><td>Detailed description of the issue</td></tr>
                    <tr><td>type</td><td>string</td><td class='required'>Required</td><td>incident, request, or job</td></tr>
                    <tr><td>priority</td><td>string</td><td class='required'>Required</td><td>low, normal, high, or urgent</td></tr>
                    <tr><td>requester_email</td><td>string</td><td class='optional'>Optional</td><td>Email of person requesting support</td></tr>
                    <tr><td>requester_name</td><td>string</td><td class='optional'>Optional</td><td>Name of requester (defaults to email if not provided)</td></tr>
                    <tr><td>assignee_id</td><td>integer</td><td class='optional'>Optional</td><td>ID of user to assign ticket to</td></tr>
                    <tr><td>category_id</td><td>integer</td><td class='optional'>Optional</td><td>Category ID for ticket classification</td></tr>
                </table>
                
                <h4>Example Request</h4>
                <div class='code-block'>
                    <pre>POST <?= $baseUrl ?>/api/tickets.php
Content-Type: application/json

{
  "subject": "Password reset request",
  "description": "User needs password reset for company portal",
  "type": "request",
  "priority": "normal",
  "requester_email": "jane@company.com",
  "requester_name": "Jane Smith"
}</pre>
                </div>
                
                <h4>Example Response</h4>
                <div class='code-block'>
                    <pre>{
  "success": true,
  "message": "Ticket created successfully",
  "data": {
    "id": 123,
    "key": "TKT-0123",
    "subject": "Password reset request",
    "description": "User needs password reset for company portal",
    "type": "request",
    "priority": "normal",
    "status": "new",
    "created_at": "2024-01-15 14:30:00",
    "updated_at": "2024-01-15 14:30:00",
    "requester": {
      "id": 45,
      "name": "Jane Smith",
      "email": "jane@company.com"
    }
  }
}</pre>
                </div>
            </div>
            
            <div class='endpoint' id='get-ticket'>
                <h3><span class='method get'>GET</span> <span class='url'>/api/tickets.php?id={id}</span></h3>
                <p>Retrieve detailed information about a specific ticket.</p>
                
                <h4>Query Parameters</h4>
                <table class='param-table'>
                    <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td>id</td><td>integer</td><td class='required'>Required</td><td>Unique ticket ID</td></tr>
                </table>
                
                <h4>Example Request</h4>
                <div class='code-block'>
                    <pre>GET <?= $baseUrl ?>/api/tickets.php?id=123</pre>
                </div>
                
                <h4>Example Response</h4>
                <div class='code-block'>
                    <pre>{
  "success": true,
  "data": {
    "id": 123,
    "key": "TKT-0123",
    "subject": "Password reset request",
    "description": "User needs password reset for company portal",
    "type": "request",
    "priority": "normal",
    "status": "resolved",
    "created_at": "2024-01-15 14:30:00",
    "updated_at": "2024-01-15 16:15:00",
    "resolved_at": "2024-01-15 16:15:00",
    "closed_at": null,
    "first_response_at": "2024-01-15 14:45:00",
    "requester": {
      "id": 45,
      "name": "Jane Smith",
      "email": "jane@company.com"
    },
    "assignee": {
      "id": 2,
      "name": "Support Agent"
    },
    "category": "Account Management",
    "subcategory": "Password Issues"
  }
}</pre>
                </div>
            </div>
            
            <div class='endpoint' id='update-ticket'>
                <h3><span class='method put'>PUT</span> <span class='url'>/api/tickets.php?id={id}</span></h3>
                <p>Update an existing ticket's details.</p>
                
                <h4>Query Parameters</h4>
                <table class='param-table'>
                    <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td>id</td><td>integer</td><td class='required'>Required</td><td>Unique ticket ID</td></tr>
                </table>
                
                <h4>Request Body (JSON)</h4>
                <table class='param-table'>
                    <tr><th>Field</th><th>Type</th><th>Description</th></tr>
                    <tr><td>subject</td><td>string</td><td>Update ticket subject</td></tr>
                    <tr><td>description</td><td>string</td><td>Update ticket description</td></tr>
                    <tr><td>status</td><td>string</td><td>new, in_progress, waiting, resolved, closed</td></tr>
                    <tr><td>priority</td><td>string</td><td>low, normal, high, urgent</td></tr>
                    <tr><td>assignee_id</td><td>integer</td><td>Reassign to different user</td></tr>
                </table>
                
                <h4>Example Request</h4>
                <div class='code-block'>
                    <pre>PUT <?= $baseUrl ?>/api/tickets.php?id=123
Content-Type: application/json

{
  "status": "resolved",
  "priority": "low"
}</pre>
                </div>
                
                <h4>Example Response</h4>
                <div class='code-block'>
                    <pre>{
  "success": true,
  "message": "Ticket updated successfully",
  "data": {
    "id": 123,
    "key": "TKT-0123",
    "subject": "Password reset request",
    "status": "resolved",
    "priority": "low",
    "updated_at": "2024-01-15 16:30:00"
  }
}</pre>
                </div>
            </div>
            
            
            <div class='endpoint' id='alternative-endpoint'>
                <h3><span class='method get'>GET</span> <span class='url'>/api-tickets.php</span></h3>
                <p><strong>Alternative endpoint</strong> - Same functionality as /api/tickets.php</p>
                <p>Use this endpoint if you prefer a simpler URL structure.</p>
                
                <h4>Example Request</h4>
                <div class='code-block'>
                    <pre>GET <?= $baseUrl ?>/api-tickets.php?status=in_progress</pre>
                </div>
                
                <h4>Query Parameters</h4>
                <p>Supports the same query parameters as <code>/api/tickets.php</code>:</p>
                <ul style='margin: 15px 0; padding-left: 20px;'>
                    <li><code>page</code>, <code>limit</code> - Pagination</li>
                    <li><code>status</code>, <code>priority</code> - Filtering</li>
                    <li><code>assignee_id</code> - Filter by assignee</li>
                </ul>
            </div>
        </div>
        
        <div class='section' id='working-time'>
            <h2>⏱️ Working Time Calculation</h2>
            
            <p>The ITSPtickets system automatically tracks <strong>working time</strong> for tickets based on status changes. This provides accurate time tracking for SLA compliance and reporting.</p>
            
            <h3>How Working Time is Calculated</h3>
            <ul style='margin: 15px 0; padding-left: 20px;'>
                <li><strong>Only tracks "in_progress" status time</strong> - Time when tickets are actively being worked on</li>
                <li><strong>Aggregates multiple time periods</strong> - Handles multiple in_progress → other status → in_progress cycles</li>
                <li><strong>Uses precise ticket_events table</strong> - Timestamp tracking of all status changes</li>
                <li><strong>Real-time calculation</strong> - Includes current in-progress time for active tickets</li>
                <li><strong>User-friendly display</strong> - Shows "22m" for under 1 hour, "1h 30m" for longer periods</li>
            </ul>
            
            <h3>Implementation Details</h3>
            <div class='code-block'>
                <pre>// Working time calculation logic:
// 1. Find all status_change events for the ticket
// 2. Track periods when status changes TO "in_progress"
// 3. Track periods when status changes FROM "in_progress"
// 4. Sum all the duration differences
// 5. Add current time if still in_progress</pre>
            </div>
            
            <h3>Example Scenario</h3>
            <table class='param-table'>
                <tr><th>Time</th><th>Status Change</th><th>Working Time</th></tr>
                <tr><td>10:00 AM</td><td>new → in_progress</td><td>Started tracking</td></tr>
                <tr><td>11:30 AM</td><td>in_progress → waiting</td><td>+90 minutes</td></tr>
                <tr><td>2:00 PM</td><td>waiting → in_progress</td><td>Started tracking again</td></tr>
                <tr><td>3:15 PM</td><td>in_progress → resolved</td><td>+75 minutes</td></tr>
                <tr><td colspan='2'><strong>Total Working Time:</strong></td><td><strong>2h 45m</strong></td></tr>
            </table>
            
            <h3>API Response Format</h3>
            <p>Working time information is included in ticket responses when available:</p>
            <div class='code-block'>
                <pre>{
  "success": true,
  "data": {
    "id": 123,
    "key": "TKT-0123",
    "subject": "Server maintenance",
    "status": "resolved",
    "created_at": "2024-01-15 10:00:00",
    "updated_at": "2024-01-15 15:15:00",
    "resolved_at": "2024-01-15 15:15:00",
    "working_time_display": "2h 45m",
    "working_time_minutes": 165,
    "timeline_events": [
      {
        "time": "2024-01-15 10:00:00",
        "event": "Status changed from new to in_progress"
      },
      {
        "time": "2024-01-15 11:30:00",
        "event": "Status changed from in_progress to waiting"
      },
      {
        "time": "2024-01-15 14:00:00",
        "event": "Status changed from waiting to in_progress"
      },
      {
        "time": "2024-01-15 15:15:00",
        "event": "Status changed from in_progress to resolved"
      }
    ]
  }
}</pre>
            </div>
            
            <h3>Business Rules</h3>
            <ul style='margin: 15px 0; padding-left: 20px;'>
                <li><strong>SLA Compliance:</strong> Working time is used for SLA breach calculations</li>
                <li><strong>Reporting:</strong> Working time data feeds into performance metrics</li>
                <li><strong>Billing:</strong> Can be used for time-based billing calculations</li>
                <li><strong>Agent Performance:</strong> Tracks actual work time vs total time</li>
            </ul>
            
            <h3>Status Definitions</h3>
            <table class='param-table'>
                <tr><th>Status</th><th>Counts as Working Time</th><th>Description</th></tr>
                <tr><td>new</td><td>❌ No</td><td>Ticket created, not yet assigned</td></tr>
                <tr><td>in_progress</td><td>✅ Yes</td><td>Actively being worked on</td></tr>
                <tr><td>waiting</td><td>❌ No</td><td>Waiting for external response</td></tr>
                <tr><td>resolved</td><td>❌ No</td><td>Issue resolved, pending confirmation</td></tr>
                <tr><td>closed</td><td>❌ No</td><td>Ticket completed and closed</td></tr>
            </table>
        </div>
        
        <div class='section' id='webhooks'>
            <h2>Webhooks</h2>
            <p>ITSPtickets supports incoming email webhooks for automatic ticket creation.</p>
            
            <h3>Email Webhook Endpoint</h3>
            <div class='code-block'>
                <pre><?= $baseUrl ?>/email-webhook-simple.php</pre>
            </div>
            
            <p>Supported email services:</p>
            <ul style='margin: 15px 0; padding-left: 20px;'>
                <li>Mailgun</li>
                <li>SendGrid</li>
                <li>Generic email webhooks</li>
            </ul>
            
            <h3>Real-time Notifications</h3>
            <div class='code-block'>
                <pre><?= $baseUrl ?>/realtime-notifications.php</pre>
            </div>
            <p>Server-Sent Events (SSE) endpoint for real-time ticket updates.</p>
        </div>
        
        <div class='section' id='examples'>
            <h2>Usage Examples</h2>
            
            <h3>cURL Examples</h3>
            
            <h4>Create a Ticket</h4>
            <div class='code-block'>
                <pre>curl -X POST '<?= $baseUrl ?>/api/tickets.php' \
  -H 'Authorization: Bearer your_api_key_here' \
  -H 'Content-Type: application/json' \
  -d '{
    "subject": "Server is down",
    "description": "Production server appears to be unreachable",
    "type": "incident",
    "priority": "urgent",
    "requester_email": "admin@company.com"
  }'</pre>
            </div>
            
            <h4>List Tickets</h4>
            <div class='code-block'>
                <pre>curl -X GET '<?= $baseUrl ?>/api/tickets.php?status=new&limit=5' \
  -H 'Authorization: Bearer your_api_key_here'</pre>
            </div>
            
            <h4>Get Specific Ticket</h4>
            <div class='code-block'>
                <pre>curl -X GET '<?= $baseUrl ?>/api/tickets.php?id=123' \
  -H 'Authorization: Bearer your_api_key_here'</pre>
            </div>
            
            <h4>Update Ticket</h4>
            <div class='code-block'>
                <pre>curl -X PUT '<?= $baseUrl ?>/api/tickets.php?id=123' \
  -H 'Authorization: Bearer your_api_key_here' \
  -H 'Content-Type: application/json' \
  -d '{
    "status": "in_progress",
    "assignee_id": 2
  }'</pre>
            </div>
            
            <h4>Test Connectivity</h4>
            <div class='code-block'>
                <pre>curl -X GET '<?= $baseUrl ?>/api-test-simple.php'</pre>
            </div>
            
            <h3 id='postman-collection'>Postman/Insomnia Collection</h3>
            <div style='background: #dbeafe; border: 1px solid #3b82f6; padding: 15px; border-radius: 6px; margin: 15px 0;'>
                <strong>📦 Ready-to-Use API Collection Available!</strong><br><br>
                Download our pre-configured Postman collection with 50+ API requests:<br><br>
                <a href='<?= $baseUrl ?>/api/postman_collection.json' download style='display: inline-block; background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-top: 10px;'>
                    ⬇️ Download Postman Collection
                </a>
            </div>
            
            <h4>How to Import</h4>
            <p><strong>For Postman:</strong></p>
            <ol style='margin: 10px 0; padding-left: 20px;'>
                <li>Open Postman</li>
                <li>Click "Import" button</li>
                <li>Select the downloaded <code>postman_collection.json</code> file</li>
                <li>Set environment variable <code>api_key</code> with your API key</li>
            </ol>
            
            <p><strong>For Insomnia:</strong></p>
            <ol style='margin: 10px 0; padding-left: 20px;'>
                <li>Open Insomnia</li>
                <li>Go to Application → Preferences → Data → Import Data</li>
                <li>Select the downloaded file</li>
                <li>Update API key in request headers</li>
            </ol>
            
            <p>The collection includes all endpoints organized into folders with example requests for create, read, update operations, filters, and error handling.</p>
            
            <h3>JavaScript/Fetch Example</h3>
            <div class='code-block'>
                <pre>// Create a new ticket
const apiKey = 'your_api_key_here';

fetch('<?= $baseUrl ?>/api/tickets.php', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${apiKey}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    subject: 'API Test Ticket',
    description: 'Testing the API integration',
    type: 'request',
    priority: 'normal',
    requester_email: 'test@example.com'
  })
})
.then(response => response.json())
.then(data => {
  if (data.success) {
    console.log('Ticket created:', data.data);
  } else {
    console.error('Error:', data.error);
  }
});</pre>
            </div>
            
            <h3>Available Endpoints Summary</h3>
            <table class='param-table'>
                <tr><th>Method</th><th>Endpoint</th><th>Description</th><th>Authentication</th></tr>
                <tr><td><span class='method get'>GET</span></td><td>/api/tickets.php</td><td>List tickets</td><td>API Key Required</td></tr>
                <tr><td><span class='method post'>POST</span></td><td>/api/tickets.php</td><td>Create ticket</td><td>API Key Required</td></tr>
                <tr><td><span class='method get'>GET</span></td><td>/api/tickets.php?id={id}</td><td>Get specific ticket</td><td>API Key Required</td></tr>
                <tr><td><span class='method put'>PUT</span></td><td>/api/tickets.php?id={id}</td><td>Update specific ticket</td><td>API Key Required</td></tr>
                <tr><td><span class='method get'>GET</span></td><td>/api-tickets.php</td><td>Alternative list endpoint</td><td>API Key Required</td></tr>
                <tr><td><span class='method get'>GET</span></td><td>/api-test-simple.php</td><td>Simple connectivity test</td><td>No Auth Required</td></tr>
                <tr><td><span class='method get'>GET</span></td><td>/api-docs.php</td><td>This documentation</td><td>Session Required</td></tr>
            </table>
            
            <div style='background: #d1fae5; border: 1px solid #10b981; padding: 15px; border-radius: 6px; margin: 15px 0;'>
                <strong>✅ API Key Authentication Active:</strong> All API endpoints now use secure API key authentication instead of session cookies. Generate API keys using the <code>manage-api-keys.php</code> command-line tool.
            </div>
        </div>
        
        <div class='section'>
            <h2>Rate Limiting & Best Practices</h2>
            <ul style='margin: 15px 0; padding-left: 20px;'>
                <li><strong>Rate Limits:</strong> No specific limits currently, but please use responsibly</li>
                <li><strong>Pagination:</strong> Always use pagination for large datasets (limit max 100)</li>
                <li><strong>Error Handling:</strong> Always check the <code>success</code> field in responses</li>
                <li><strong>Authentication:</strong> Keep API keys secure and rotate them regularly</li>
                <li><strong>Data Validation:</strong> Validate input before sending to prevent errors</li>
                <li><strong>API Key Security:</strong> Never expose API keys in client-side code or logs</li>
            </ul>
        </div>
    </div>
</body>
</html>