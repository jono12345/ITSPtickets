# ITSPtickets Laravel Infrastructure Cleanup - Backup Documentation

**Generated:** 2025-10-17  
**Purpose:** Document current structure before Laravel/MVC infrastructure removal  
**Target:** Complete transition to Simple Model architecture  

## Files and Directories to be REMOVED

### Laravel Command Line Interface
- `artisan` - Laravel command line tool (unused in Simple Model)

### Laravel Dependencies
- `composer.json` - Laravel framework dependencies and scripts
- `vendor/` directory (if exists) - Laravel vendor packages

### Laravel Bootstrap
- `bootstrap/app.php` - Laravel application initialization
- `bootstrap/` directory (entire directory unused)

### Laravel MVC Classes (app/ directory)
```
app/
├── Auth/
│   ├── ApiKeyAuth.php
│   └── Auth.php  
├── Console/
│   └── Kernel.php
├── Exceptions/
│   └── Handler.php
├── Http/
│   ├── Kernel.php
│   └── Controllers/
│       ├── AuthController.php
│       ├── HomeController.php  
│       ├── TicketController.php
│       └── Api/
│           └── TicketApiController.php
└── Models/
    ├── Model.php
    ├── Organization.php
    ├── Requester.php
    ├── SlaPolicy.php
    ├── Ticket.php
    ├── TicketMessage.php
    └── User.php
```

### Laravel Configuration
- `config/app.php` - Laravel-specific app configuration

## Files and Directories to be PRESERVED

### Core Simple Model Files
- `*-simple.php` files (all ticket system functionality)
- `login.php`, `logout.php` (Simple Model auth)
- `api/tickets.php` (Simple Model API)
- `config/database.php` (database configuration)

### Supporting Infrastructure  
- `database/` directory (schema and data)
- `css/` directory (styling)
- `js/` directory (client-side functionality)
- `.env` (environment configuration)

### Working Files
- All PHP files implementing Simple Model pattern
- All test and debug files
- Documentation files (`*.md`)

## Modifications Needed

### public/index.php
- Remove Laravel-specific routing
- Simplify to basic PHP redirects only

### .htaccess  
- Already optimized for Simple Model
- Remove references to Laravel directories after cleanup

## Validation Steps

After cleanup, verify:
1. All Simple Model files still accessible
2. Database connections work
3. Authentication flows work  
4. API endpoints function
5. No broken links or references

## Current Architecture Status

✅ **Simple Model Implementation:** Complete  
✅ **Laravel Migration:** Completed previously  
⚠️ **Infrastructure Cleanup:** In Progress  
🎯 **Target:** Pure Simple Model with no Laravel remnants  

---

**Note:** This documentation serves as a backup reference for the cleanup process. The Simple Model architecture is already fully functional and tested.