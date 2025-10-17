#!/bin/bash

################################################################################
# ITSPtickets API Test Suite
# Comprehensive curl commands to test all API endpoints
# Uses API Key authentication
################################################################################

BASE_URL="http://168.62.52.225/ITSPtickets"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# API Key for authentication
# Generate your own key with: php manage-api-keys.php create USER_ID "Key Name" "*" 90
API_KEY="8de12222a81e4f4069495f59c5e7c633f447d786533cec2cadba449320bff3e1"

echo -e "${BLUE}╔═══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║       ITSPtickets API Test Suite                         ║${NC}"
echo -e "${BLUE}║       Using API Key Authentication                       ║${NC}"
echo -e "${BLUE}╔═══════════════════════════════════════════════════════════╗${NC}"
echo ""

################################################################################
# Helper Functions
################################################################################

print_header() {
    echo -e "\n${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}$1${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

pause() {
    echo -e "\n${BLUE}Press Enter to continue...${NC}"
    read -r
}

################################################################################
# Test 1: API Connectivity Test
################################################################################

print_header "Test 1: API Connectivity Test"
print_info "Testing basic API connectivity with /api-test.php"
echo ""
echo "curl -X GET '${BASE_URL}/api-test.php'"
echo ""
curl -X GET "${BASE_URL}/api-test.php"
echo -e "\n"
pause

################################################################################
# Test 2: Simple API Test
################################################################################

print_header "Test 2: Simple API Test with Data"
print_info "Testing /api-test-simple.php endpoint"
echo ""
echo "curl -X GET '${BASE_URL}/api-test-simple.php'"
echo ""
curl -X GET "${BASE_URL}/api-test-simple.php"
echo -e "\n"
pause

################################################################################
# Test 3: Verify API Key
################################################################################

print_header "Test 3: Verify API Key Authentication"
print_info "API Key: ${API_KEY:0:20}..."
print_info "Testing authenticated access to API"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?limit=1' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
TEST_RESPONSE=$(curl -s -X GET "${BASE_URL}/api/tickets.php?limit=1" \
  -H "Authorization: Bearer ${API_KEY}")

if echo "$TEST_RESPONSE" | grep -q '"success":true'; then
    print_success "API Key authentication successful!"
    echo "$TEST_RESPONSE" | jq '.' 2>/dev/null || echo "$TEST_RESPONSE"
else
    print_error "API Key authentication failed!"
    echo "$TEST_RESPONSE"
    echo ""
    echo "Please generate a valid API key with:"
    echo "  php manage-api-keys.php create USER_ID \"API Test Suite\" \"*\" 90"
    exit 1
fi
echo -e "\n"
pause

################################################################################
# Test 4: List All Tickets (Default Pagination)
################################################################################

print_header "Test 4: List All Tickets (Default Pagination)"
print_info "GET /api/tickets.php"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -X GET "${BASE_URL}/api/tickets.php" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 5: List Tickets with Pagination
################################################################################

print_header "Test 5: List Tickets with Pagination"
print_info "GET /api/tickets.php?page=1&limit=5"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?page=1&limit=5' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -X GET "${BASE_URL}/api/tickets.php?page=1&limit=5" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php?page=1&limit=5" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 6: Filter Tickets by Status
################################################################################

print_header "Test 6: Filter Tickets by Status"
print_info "Testing all available statuses: new, in_progress, waiting, resolved, closed"
echo ""

for status in "new" "in_progress" "waiting" "resolved" "closed"; do
    echo -e "${BLUE}Testing status: ${status}${NC}"
    echo "curl -X GET '${BASE_URL}/api/tickets.php?status=${status}&limit=3' \\"
    echo "  -H 'Authorization: Bearer \$API_KEY'"
    echo ""
    curl -X GET "${BASE_URL}/api/tickets.php?status=${status}&limit=3" \
      -H "Authorization: Bearer ${API_KEY}" \
      | jq '.pagination' 2>/dev/null || echo "Error or no jq installed"
    echo -e "\n"
done
pause

################################################################################
# Test 7: Filter Tickets by Priority
################################################################################

print_header "Test 7: Filter Tickets by Priority"
print_info "Testing all available priorities: low, normal, high, urgent"
echo ""

for priority in "low" "normal" "high" "urgent"; do
    echo -e "${BLUE}Testing priority: ${priority}${NC}"
    echo "curl -X GET '${BASE_URL}/api/tickets.php?priority=${priority}&limit=3' \\"
    echo "  -H 'Authorization: Bearer \$API_KEY'"
    echo ""
    curl -X GET "${BASE_URL}/api/tickets.php?priority=${priority}&limit=3" \
      -H "Authorization: Bearer ${API_KEY}" \
      | jq '.pagination' 2>/dev/null || echo "Error or no jq installed"
    echo -e "\n"
done
pause

################################################################################
# Test 8: Filter Tickets by Assignee
################################################################################

print_header "Test 8: Filter Tickets by Assignee ID"
print_info "GET /api/tickets.php?assignee_id=2"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?assignee_id=2&limit=5' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -X GET "${BASE_URL}/api/tickets.php?assignee_id=2&limit=5" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php?assignee_id=2&limit=5" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 9: Combined Filters
################################################################################

print_header "Test 9: Combined Filters"
print_info "GET /api/tickets.php?status=in_progress&priority=high&limit=10"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?status=in_progress&priority=high&limit=10' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -X GET "${BASE_URL}/api/tickets.php?status=in_progress&priority=high&limit=10" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php?status=in_progress&priority=high&limit=10" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 10: Create a New Ticket
################################################################################

print_header "Test 10: Create a New Ticket"
print_info "POST /api/tickets.php"
echo ""

TICKET_JSON='{
  "subject": "API Test - Server Performance Issue",
  "description": "This is a test ticket created via API to test server performance monitoring and response times.",
  "type": "incident",
  "priority": "high",
  "requester_email": "testuser@example.com",
  "requester_name": "API Test User"
}'

echo "curl -X POST '${BASE_URL}/api/tickets.php' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${TICKET_JSON}'"
echo ""

RESPONSE=$(curl -s -X POST "${BASE_URL}/api/tickets.php" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${TICKET_JSON}")

echo "$RESPONSE" | jq '.' 2>/dev/null || echo "$RESPONSE"

# Extract ticket ID for later tests
CREATED_TICKET_ID=$(echo "$RESPONSE" | jq -r '.data.id // empty' 2>/dev/null)
if [ ! -z "$CREATED_TICKET_ID" ]; then
    print_success "Created ticket with ID: ${CREATED_TICKET_ID}"
    export CREATED_TICKET_ID
fi
echo -e "\n"
pause

################################################################################
# Test 11: Create Another Ticket (Different Type)
################################################################################

print_header "Test 11: Create Ticket - Service Request"
print_info "POST /api/tickets.php (type: request)"
echo ""

REQUEST_JSON='{
  "subject": "API Test - New User Account Request",
  "description": "Request for creating a new user account with admin privileges for the finance department.",
  "type": "request",
  "priority": "normal",
  "requester_email": "finance@company.com",
  "requester_name": "Finance Manager"
}'

echo "curl -X POST '${BASE_URL}/api/tickets.php' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${REQUEST_JSON}'"
echo ""

curl -s -X POST "${BASE_URL}/api/tickets.php" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${REQUEST_JSON}" \
  | jq '.' 2>/dev/null || curl -X POST "${BASE_URL}/api/tickets.php" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${REQUEST_JSON}"
echo -e "\n"
pause

################################################################################
# Test 12: Create Job Ticket
################################################################################

print_header "Test 12: Create Ticket - Job Type"
print_info "POST /api/tickets.php (type: job)"
echo ""

JOB_JSON='{
  "subject": "API Test - Database Backup Scheduled Job",
  "description": "Monthly database backup and cleanup job to be performed during maintenance window.",
  "type": "job",
  "priority": "low",
  "requester_email": "sysadmin@company.com",
  "requester_name": "System Administrator"
}'

echo "curl -X POST '${BASE_URL}/api/tickets.php' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${JOB_JSON}'"
echo ""

curl -s -X POST "${BASE_URL}/api/tickets.php" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${JOB_JSON}" \
  | jq '.' 2>/dev/null || curl -X POST "${BASE_URL}/api/tickets.php" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${JOB_JSON}"
echo -e "\n"
pause

################################################################################
# Test 13: Get Specific Ticket by ID
################################################################################

print_header "Test 13: Get Specific Ticket by ID"
TICKET_TO_GET=${CREATED_TICKET_ID:-29}
print_info "GET /api/tickets.php?id=${TICKET_TO_GET}"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?id=${TICKET_TO_GET}' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php?id=${TICKET_TO_GET}" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php?id=${TICKET_TO_GET}" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 14: Update Ticket Status
################################################################################

print_header "Test 14: Update Ticket Status"
TICKET_TO_UPDATE=${CREATED_TICKET_ID:-29}
print_info "PUT /api/tickets.php?id=${TICKET_TO_UPDATE}"
echo ""

UPDATE_JSON='{
  "status": "in_progress"
}'

echo "curl -X PUT '${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${UPDATE_JSON}'"
echo ""

curl -s -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${UPDATE_JSON}" \
  | jq '.' 2>/dev/null || curl -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${UPDATE_JSON}"
echo -e "\n"
pause

################################################################################
# Test 15: Update Ticket Priority
################################################################################

print_header "Test 15: Update Ticket Priority"
print_info "PUT /api/tickets.php?id=${TICKET_TO_UPDATE}"
echo ""

UPDATE_PRIORITY_JSON='{
  "priority": "urgent"
}'

echo "curl -X PUT '${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${UPDATE_PRIORITY_JSON}'"
echo ""

curl -s -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${UPDATE_PRIORITY_JSON}" \
  | jq '.' 2>/dev/null || curl -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${UPDATE_PRIORITY_JSON}"
echo -e "\n"
pause

################################################################################
# Test 16: Update Multiple Fields
################################################################################

print_header "Test 16: Update Multiple Ticket Fields"
print_info "PUT /api/tickets.php?id=${TICKET_TO_UPDATE}"
echo ""

MULTI_UPDATE_JSON='{
  "status": "resolved",
  "priority": "normal",
  "subject": "API Test - Server Performance Issue (RESOLVED)"
}'

echo "curl -X PUT '${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${MULTI_UPDATE_JSON}'"
echo ""

curl -s -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${MULTI_UPDATE_JSON}" \
  | jq '.' 2>/dev/null || curl -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${MULTI_UPDATE_JSON}"
echo -e "\n"
pause

################################################################################
# Test 17: Update Ticket Assignment
################################################################################

print_header "Test 17: Update Ticket Assignment"
print_info "PUT /api/tickets.php?id=${TICKET_TO_UPDATE}"
echo ""

ASSIGN_JSON='{
  "assignee_id": 2
}'

echo "curl -X PUT '${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${ASSIGN_JSON}'"
echo ""

curl -s -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${ASSIGN_JSON}" \
  | jq '.' 2>/dev/null || curl -X PUT "${BASE_URL}/api/tickets.php?id=${TICKET_TO_UPDATE}" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${ASSIGN_JSON}"
echo -e "\n"
pause

################################################################################
# Test 18: Alternative Endpoint - List Tickets
################################################################################

print_header "Test 18: Alternative Endpoint - /api-tickets.php"
print_info "GET /api-tickets.php?status=in_progress"
echo ""
echo "curl -X GET '${BASE_URL}/api-tickets.php?status=in_progress&limit=5' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -s -X GET "${BASE_URL}/api-tickets.php?status=in_progress&limit=5" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api-tickets.php?status=in_progress&limit=5" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 19: Test Invalid Ticket ID (404 Error)
################################################################################

print_header "Test 19: Error Handling - Invalid Ticket ID"
print_info "GET /api/tickets.php?id=999999"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?id=999999' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php?id=999999" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php?id=999999" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 20: Test Invalid Status Filter
################################################################################

print_header "Test 20: Error Handling - Invalid Status"
print_info "GET /api/tickets.php?status=invalid_status"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?status=invalid_status' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php?status=invalid_status" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php?status=invalid_status" -H "Authorization: Bearer ${API_KEY}"
echo -e "\n"
pause

################################################################################
# Test 21: Test Missing Required Fields (Create)
################################################################################

print_header "Test 21: Error Handling - Missing Required Fields"
print_info "POST /api/tickets.php (missing subject)"
echo ""

INVALID_JSON='{
  "description": "This ticket is missing a subject",
  "type": "incident"
}'

echo "curl -X POST '${BASE_URL}/api/tickets.php' \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -H 'Authorization: Bearer \$API_KEY' \\"
echo "  -d '${INVALID_JSON}'"
echo ""

curl -s -X POST "${BASE_URL}/api/tickets.php" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${API_KEY}" \
  -d "${INVALID_JSON}" \
  | jq '.' 2>/dev/null || curl -X POST "${BASE_URL}/api/tickets.php" -H 'Content-Type: application/json' -H "Authorization: Bearer ${API_KEY}" -d "${INVALID_JSON}"
echo -e "\n"
pause

################################################################################
# Test 22: Test Pagination Limits
################################################################################

print_header "Test 22: Pagination - Maximum Limit Test"
print_info "GET /api/tickets.php?limit=100 (max allowed)"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?limit=100' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php?limit=100" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.pagination' 2>/dev/null || echo "Error or no jq installed"
echo -e "\n"
pause

################################################################################
# Test 23: Test Pagination - Exceeding Limit
################################################################################

print_header "Test 23: Pagination - Exceeding Maximum Limit"
print_info "GET /api/tickets.php?limit=200 (exceeds max of 100)"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php?limit=200' \\"
echo "  -H 'Authorization: Bearer \$API_KEY'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php?limit=200" \
  -H "Authorization: Bearer ${API_KEY}" \
  | jq '.pagination' 2>/dev/null || echo "Error or no jq installed"
echo -e "\n"
pause

################################################################################
# Test 24: Test Without Authentication
################################################################################

print_header "Test 24: Unauthorized Access Test"
print_info "GET /api/tickets.php (without API key)"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php"
echo -e "\n"
pause

################################################################################
# Test 25: Test With Invalid API Key
################################################################################

print_header "Test 25: Invalid API Key Test"
print_info "GET /api/tickets.php (with invalid API key)"
echo ""
echo "curl -X GET '${BASE_URL}/api/tickets.php' \\"
echo "  -H 'Authorization: Bearer invalid_key_12345'"
echo ""
curl -s -X GET "${BASE_URL}/api/tickets.php" \
  -H "Authorization: Bearer invalid_key_12345" \
  | jq '.' 2>/dev/null || curl -X GET "${BASE_URL}/api/tickets.php" -H "Authorization: Bearer invalid_key_12345"
echo -e "\n"
pause

################################################################################
# Summary
################################################################################

print_header "Test Suite Complete!"
echo -e "${GREEN}All API endpoint tests have been executed.${NC}"
echo ""
echo -e "${YELLOW}Summary of Tested Endpoints:${NC}"
echo -e "  ✓ GET  /api-test.php"
echo -e "  ✓ GET  /api-test-simple.php"
echo -e "  ✓ GET  /api/tickets.php (with various filters)"
echo -e "  ✓ POST /api/tickets.php (create tickets)"
echo -e "  ✓ GET  /api/tickets.php?id={id} (get specific ticket)"
echo -e "  ✓ PUT  /api/tickets.php?id={id} (update tickets)"
echo -e "  ✓ GET  /api-tickets.php (alternative endpoint)"
echo ""
echo -e "${BLUE}API Key Used:${NC}"
echo -e "  ${API_KEY:0:20}...${API_KEY: -10}"
echo ""
echo -e "${YELLOW}To generate your own API key:${NC}"
echo -e "  php manage-api-keys.php create USER_ID \"Key Name\" \"*\" 90"
echo ""
