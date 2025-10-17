# ITSPtickets API Testing Tools

Essential API testing tools for the ITSPtickets REST API.

**Download Postman Collection:** http://your-server.com/ITSPtickets/api/postman_collection.json

---

## 🧪 Testing Tools

### 1. **api-test-suite.sh** (23KB)
Comprehensive automated test suite for all API endpoints.

**Usage:**
```bash
cd /var/www/html/ITSPtickets/api
./api-test-suite.sh
```

**Features:**
- ✅ Tests all 25+ API scenarios
- ✅ API key authentication
- ✅ Interactive with color output
- ✅ Tests CRUD operations, filters, error handling
- ✅ Validates all endpoints

**Requirements:**
- Update the `API_KEY` variable with your API key
- Optional: Install `jq` for pretty JSON output

---

### 2. **postman_collection.json** (24KB)
Postman/Insomnia collection with 50+ pre-configured API requests.

**Import to Postman:**
1. Open Postman
2. Click "Import"
3. Select this file
4. Set environment variable `api_key` with your API key

**Import to Insomnia:**
1. Open Insomnia
2. Application → Preferences → Data → Import Data
3. Select this file
4. Update API key in requests

**Contents:**
- 12 organized folders
- All CRUD operations
- Filter examples
- Error handling tests

---

## 🔑 API Key Required

Both tools require a valid API key. Generate one with:

```bash
# List users
php manage-api-keys.php users

# Create API key (90-day validity)
php manage-api-keys.php create USER_ID "Test Key" "*" 90
```

---

## 📖 Documentation

**Full API Documentation:** http://your-server.com/ITSPtickets/api-docs.php
(Requires staff login)

**Key Endpoints:**
- `GET /api/tickets.php` - List tickets
- `POST /api/tickets.php` - Create ticket
- `GET /api/tickets.php?id={id}` - Get ticket
- `PUT /api/tickets.php?id={id}` - Update ticket

**Authentication:**
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://your-server.com/ITSPtickets/api/tickets.php
```

---

## ✅ Test Results

**Last Test Run:** October 8, 2025  
**Success Rate:** 100% (25/25 tests passing)  
**Total Tickets in System:** 29

All CRUD operations verified working with API key authentication.

---

## 🎯 Quick Start

1. **Generate API key:**
   ```bash
   php manage-api-keys.php create 1 "Test" "*" 90
   ```

2. **Update test suite:**
   ```bash
   nano api-test-suite.sh
   # Update API_KEY variable
   ```

3. **Run tests:**
   ```bash
   ./api-test-suite.sh
   ```

---

**For questions or issues, refer to the main API documentation.**

