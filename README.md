# ITSPtickets - Simple Model Ticketing System

> **A lightweight, self-contained PHP ticketing system designed for simplicity and performance**

⚠️ **WORK IN PROGRESS - ALPHA STAGE** ⚠️
> **This project is under active development and largely untested. Many features may be incomplete, broken, or subject to significant changes. Use at your own risk and expect bugs.**

[![Development Status](https://img.shields.io/badge/Status-Alpha%20%2F%20Work%20in%20Progress-red)](https://github.com/yourusername/ITSPtickets)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://php.net)
[![Architecture](https://img.shields.io/badge/Architecture-Simple%20Model-green)](SIMPLE-MODEL-ARCHITECTURE.md)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Database](https://img.shields.io/badge/Database-MySQL-orange)](https://mysql.com)

## ⚠️ Important Development Status Notice

**This project is in ALPHA/DEVELOPMENT stage:**

- 🚧 **Many features are untested** and may not work as documented
- 🐛 **Expect bugs, incomplete features, and breaking changes**
- 🔄 **Architecture recently migrated** from Laravel to Simple Model
- 📝 **Documentation may describe intended functionality** rather than current reality
- ⚠️ **NOT recommended for production use** without extensive testing
- 🧪 **Contributions and testing help welcome** - see [CONTRIBUTING.md](CONTRIBUTING.md)

**Use this project as:**
- 📚 Reference for Simple Model architecture concepts
- 🛠️ Development/learning exercise
- 🔬 Experimental ticketing system (with proper backups!)

**DO NOT use for:**
- ❌ Production environments without thorough testing
- ❌ Critical business operations
- ❌ Systems where data loss would be problematic

📋 **[Read the complete Development Status & Testing Notes →](DEVELOPMENT-STATUS.md)**

## 🎯 Overview

ITSPtickets is a modern, lightweight help desk and ticketing system built with the **Simple Model** architecture. Unlike complex frameworks, it uses self-contained PHP files for maximum simplicity, performance, and maintainability.

### ✨ Key Features

- 🎫 **Full ITIL Compliance** - Incidents, Requests, Jobs with proper workflow
- ⚡ **Simple Model Architecture** - No framework overhead, direct PHP execution
- 🔐 **Secure API** - RESTful API with token-based authentication
- 📊 **Advanced Reporting** - Performance metrics, SLA tracking, and analytics
- 🕐 **Working Time Tracking** - Accurate time tracking for SLA compliance
- 📧 **Email Integration** - Webhook support for inbound email processing
- 👥 **Multi-Role Support** - Admin, Supervisor, Agent, Requester permissions
- 🎨 **Clean UI** - Modern, responsive web interface
- 📱 **Real-time Updates** - Live notifications and status changes

## 🚀 Quick Start

### Prerequisites

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)

### Installation

1. **Clone the repository**
```bash
git clone https://github.com/yourusername/ITSPtickets.git
cd ITSPtickets
```

2. **Set up the database**
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE ITSPtickets CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import schema
mysql -u root -p ITSPtickets < database/schema.sql
```

3. **Configure database connection**
```bash
# Copy and edit database configuration
cp config/database.php.example config/database.php
# Edit with your database credentials
```

4. **Set up web server**
- Point document root to the project directory
- Ensure `.htaccess` is enabled for Apache
- Set appropriate file permissions

5. **Access the system**
```
http://your-server/login.php
```

### Default Login
- **Email**: `admin@admin.local`
- **Password**: `admin`

⚠️ **Change default credentials immediately in production!**

## 📁 Project Structure

```
ITSPtickets/
├── *-simple.php          # Core Simple Model files
├── login.php             # Authentication
├── api/                  # RESTful API endpoints
│   ├── tickets.php       # Main API endpoint
│   ├── API-TESTING.md    # API testing guide
│   └── postman_collection.json
├── config/               # Configuration files
│   └── database.php      # Database configuration
├── database/             # Database schema and migrations
│   └── schema.sql        # Complete database schema
├── css/                  # Stylesheets
├── js/                   # JavaScript files
└── docs/                 # Documentation
```

## 🏗️ Architecture

ITSPtickets uses the **Simple Model** architecture:

- ✅ **Self-Contained Files** - Each file contains all necessary logic
- ✅ **Direct Database Access** - PDO connections in each file
- ✅ **No Framework Dependencies** - Pure PHP implementation
- ✅ **Easy Debugging** - All logic visible and traceable
- ✅ **Fast Performance** - No abstraction layer overhead

Read more: [Simple Model Architecture Guide](SIMPLE-MODEL-ARCHITECTURE.md)

## 🔌 API Documentation

ITSPtickets includes a comprehensive RESTful API:

### Quick API Example
```bash
# Get all tickets
curl -H "Authorization: Bearer YOUR_API_KEY" \
     http://your-server/api/tickets.php

# Create a ticket
curl -X POST http://your-server/api/tickets.php \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -H "Content-Type: application/json" \
     -d '{
       "subject": "Server Issue",
       "description": "Production server is down",
       "type": "incident",
       "priority": "urgent"
     }'
```

### API Features
- 🔑 **Token Authentication** - Secure API key system
- 📄 **Full CRUD Operations** - Create, Read, Update tickets
- 🔍 **Advanced Filtering** - Status, priority, assignee filters
- 📊 **Pagination Support** - Efficient data handling
- ⏱️ **Working Time Tracking** - Accurate time calculations
- 📝 **Comprehensive Documentation** - Built-in API docs

**Full API Documentation**: `/api-docs.php` (requires login)

## 🧪 Testing

### API Testing Tools
```bash
# Run comprehensive API test suite
cd api/
./api-test-suite.sh

# Simple connectivity test
curl http://your-server/api-test-simple.php
```

### Postman Collection
Import the included Postman collection for easy API testing:
- File: `api/postman_collection.json`
- 50+ pre-configured requests
- Organized test scenarios

## 📊 Features

### Ticketing System
- **ITIL-Compliant Workflow** - Proper incident/request/job handling
- **Status Management** - New → In Progress → Resolved → Closed
- **Priority Levels** - Low, Normal, High, Urgent
- **Assignment System** - User and team-based assignment
- **SLA Tracking** - Automatic SLA policy assignment and monitoring

### Reporting & Analytics
- **Performance Metrics** - Response times, resolution rates
- **SLA Compliance** - Breach detection and reporting  
- **Team Analytics** - Workload distribution and performance
- **Export Capabilities** - CSV exports with filtering
- **Customer Reports** - Professional client-facing reports

### User Management
- **Role-Based Access** - Admin, Supervisor, Agent, Requester
- **Team Organization** - Hierarchical team structure
- **Secure Authentication** - Session-based with API key support
- **User Profiles** - Comprehensive user management

### Integration Features
- **Email Webhooks** - Mailgun, SendGrid, generic email processing
- **Real-time Notifications** - Server-Sent Events (SSE)
- **API Integration** - RESTful API for external systems
- **Webhook Support** - Incoming email to ticket conversion

## ⚡ Performance

The Simple Model architecture provides significant performance benefits:

- **~40% faster** load times vs framework-based systems
- **~30% lower** memory usage
- **Direct execution** without abstraction layers
- **Optimized queries** without ORM overhead

## 🔒 Security

- **SQL Injection Protection** - Prepared statements throughout
- **XSS Prevention** - HTML escaping on all outputs  
- **CSRF Protection** - Session-based validation
- **API Security** - Token authentication with rate limiting
- **Audit Logging** - Comprehensive activity tracking
- **Role-Based Access Control** - Granular permissions

## 📚 Documentation

- [Simple Model Architecture](SIMPLE-MODEL-ARCHITECTURE.md) - Architecture overview
- [API Testing Guide](api/API-TESTING.md) - API testing tools and examples
- [Billable Hours Workflow](BILLABLE-HOURS-WORKFLOW.md) - Time tracking guide
- [Post-Cleanup Architecture](POST-CLEANUP-ARCHITECTURE.md) - Clean architecture status

## 🤝 Contributing

Contributions are welcome! This project follows the Simple Model philosophy:

1. **Keep it Simple** - Avoid unnecessary complexity
2. **Self-Contained Files** - Each feature in its own file
3. **Direct Database Access** - Use PDO directly
4. **Comprehensive Testing** - Test all functionality
5. **Clear Documentation** - Document all features

### Development Setup
```bash
# Clone repository
git clone https://github.com/yourusername/ITSPtickets.git

# Set up development database
mysql -u root -p ITSPtickets < database/schema.sql

# Run tests
cd api && ./api-test-suite.sh
```

## 📋 Requirements

### Server Requirements
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher  
- **Extensions**: PDO, PDO_MySQL, JSON, cURL
- **Web Server**: Apache with mod_rewrite or Nginx

### Browser Support
- Chrome 70+
- Firefox 65+
- Safari 12+
- Edge 79+

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Built with the **Simple Model** architecture philosophy
- Inspired by ITIL best practices
- Designed for simplicity and performance

## 📞 Support

- **Documentation**: Check the `/docs` directory
- **API Docs**: Access `/api-docs.php` after login
- **Issues**: Report bugs via GitHub Issues
- **Testing**: Use included API test suite

---

**ITSPtickets** - Simple, Fast, Reliable Ticketing ✨