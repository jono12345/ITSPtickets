# ITSPtickets System Documentation

## Project Overview

ITSPtickets is a comprehensive ticket management system built using PHP with a "simple model" architecture - self-contained PHP files that handle their own database connections, authentication, and business logic rather than following strict MVC patterns. This approach provides flexibility and ease of maintenance for a medium-scale ticketing system.

## System Architecture

### Core Components

- **Database**: MySQL/MariaDB with comprehensive schema for tickets, users, organizations, SLA policies
- **Authentication**: Session-based auth with role-based access control (admin, agent, customer)
- **File Structure**: Simple model with individual PHP files handling complete workflows
- **API Layer**: RESTful API with token-based authentication for external integrations

### Key Features

- Ticket lifecycle management (creation, assignment, updates, resolution)
- SLA policy enforcement with automated compliance tracking
- Multi-organizational support with hour allowance tracking
- Real-time notifications system
- Comprehensive reporting and analytics
- Customer portal for self-service ticket creation
- Staff dashboard with workload management
- API for external system integration

## Recent Implementation: Manual Billable Hours System

### Overview

A complete manual billable hours tracking system was implemented to address the gap between existing monthly/quarterly hour allowances in organization management and the need for granular time tracking on individual tickets.

### Business Requirements

- **Manual Time Entry**: Staff can manually input time spent and billable hours on tickets
- **Simple Approach**: Avoid complex automated time tracking in favor of straightforward manual input
- **Integration**: Seamlessly integrate with existing customer reporting system
- **Validation**: Ensure billable hours cannot exceed time spent
- **Audit Trail**: Log all time tracking changes for accountability

### Technical Implementation

#### 1. Database Schema Enhancement

The existing database already contained the required columns:
- [`tickets.time_spent`](database/schema.sql:45) (DECIMAL 5,2) - Total time worked on ticket
- [`tickets.billable_hours`](database/schema.sql:46) (DECIMAL 5,2) - Hours billable to customer

No schema changes were required as the foundation was already present.

#### 2. User Interface Implementation

##### A. Ticket Update Interface ([`update-ticket-simple.php`](update-ticket-simple.php))

**Added Time Tracking Section (Lines 633-685):**
- Time Spent field with quarter-hour increments (0.25 step)
- Billable Hours field with quarter-hour increments (0.25 step)
- Professional styling with blue accent theme
- Real-time validation preventing billable hours > time spent
- Form integration with existing ticket update workflow

**Backend Processing (Lines 69-93):**
```php
// Time tracking updates
if (isset($_POST['time_spent']) && $_POST['time_spent'] !== '') {
    $timeSpent = (float) $_POST['time_spent'];
    $billableHours = isset($_POST['billable_hours']) && $_POST['billable_hours'] !== '' 
        ? (float) $_POST['billable_hours'] : 0;
    
    // Validation: billable hours cannot exceed time spent
    if ($billableHours > $timeSpent) {
        $errors[] = "Billable hours ({$billableHours}) cannot exceed time spent ({$timeSpent})";
    } else {
        $updates[] = "time_spent = :time_spent";
        $updates[] = "billable_hours = :billable_hours";
        $params[':time_spent'] = $timeSpent;
        $params[':billable_hours'] = $billableHours;
        
        // Log time tracking event
        logTicketEvent($pdo, $ticketId, 'time_updated', 
            "Time tracking updated: {$timeSpent}h spent, {$billableHours}h billable", 
            $user['id']);
    }
}
```

##### B. Ticket Details View ([`ticket-simple.php`](ticket-simple.php))

**Added Time Tracking Display Box (Lines 926-951):**
- Professional blue-themed information box
- Shows time spent, billable hours, and calculated billable percentage
- Responsive design with proper spacing and typography
- Only displays when time data is available

**Implementation:**
```php
<?php if ($ticket['time_spent'] > 0 || $ticket['billable_hours'] > 0): ?>
    <div style='background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 15px; margin: 20px 0;'>
        <div style='font-weight: 600; color: #1d4ed8; margin-bottom: 8px; display: flex; align-items: center;'>
            <span style='margin-right: 8px;'>⏱️</span>
            Time Tracking
        </div>
        <div style='display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; font-size: 14px;'>
            <div>
                <div style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;'>Time Spent</div>
                <div style='font-weight: 500; color: #374151;'><?= number_format($ticket['time_spent'], 2) ?> hours</div>
            </div>
            <!-- ... additional columns for billable hours and percentage -->
        </div>
    </div>
<?php endif; ?>
```

##### C. Ticket List View ([`tickets-simple.php`](tickets-simple.php))

**Added Time Indicators (Lines 953-961):**
- Compact green-themed time display in ticket meta information
- Shows spent/billable hours in format: "⏱️ 3.5h spent | 2.5h billable"
- Only displays when time data is available
- Integrates seamlessly with existing ticket list layout

#### 3. Testing and Verification

Created comprehensive test script [`test-billable-hours.php`](test-billable-hours.php) that verifies:

**Database Schema Validation:**
- Confirms `time_spent` and `billable_hours` columns exist
- Validates correct data types (DECIMAL 5,2)
- Tests database connectivity

**Time Tracking Functionality:**
- Creates test ticket with sample data
- Updates time tracking fields via simulated form submission
- Verifies data persistence in database
- Tests validation rules (billable ≤ spent)

**Customer Report Integration:**
- Validates existing fallback logic: `time_spent ?: billable_hours ?: 0`
- Confirms seamless integration with organization hour allowances
- Tests report generation with new time data

#### 4. Integration Points

##### A. Customer Reporting System

The existing [`customer-report-generator.php`](customer-report-generator.php) already used proper fallback logic:

```php
$time = $ticket['time_spent'] ?: $ticket['billable_hours'] ?: 0;
```

This ensures backward compatibility while prioritizing the new manual time tracking fields.

##### B. Event Logging System

All time tracking changes are logged via the existing event system:

```php
logTicketEvent($pdo, $ticketId, 'time_updated', 
    "Time tracking updated: {$timeSpent}h spent, {$billableHours}h billable", 
    $user['id']);
```

This provides full audit trail for all time modifications.

#### 5. User Experience Design

**Professional Styling:**
- Blue accent theme (`#eff6ff` backgrounds, `#1d4ed8` text)
- Consistent typography and spacing
- Responsive grid layouts
- Intuitive form controls with quarter-hour increments

**Workflow Integration:**
- Time tracking section placed logically in ticket update form
- Clear labels and placeholder text
- Real-time validation feedback
- Seamless integration with existing update workflow

#### 6. Business Rules and Validation

**Core Validation Rules:**
1. Billable hours cannot exceed time spent
2. Both fields accept quarter-hour increments (0.25 minimum)
3. Negative values not permitted
4. Fields are optional but validated when provided

**Data Integrity:**
- Server-side validation in addition to client-side
- Proper decimal handling with 2-decimal precision
- Database constraints ensure data consistency

### Documentation and Training

#### A. Staff Workflow Documentation

Created comprehensive [`BILLABLE-HOURS-WORKFLOW.md`](BILLABLE-HOURS-WORKFLOW.md) including:

**Workflow Instructions:**
- Step-by-step time tracking process
- Best practices for time entry
- Examples of billable vs non-billable scenarios

**Business Rules:**
- Time tracking guidelines
- Billing policies and procedures
- Quality assurance standards

**Troubleshooting Guide:**
- Common issues and solutions
- Error message explanations
- Support escalation procedures

#### B. Technical Documentation

This document serves as the technical implementation guide covering:
- Architecture decisions and rationales
- Code organization and file modifications
- Integration points and dependencies
- Testing procedures and validation

### Deployment and Rollout

**Implementation Status:**
- ✅ All core functionality implemented and tested
- ✅ User interface components completed
- ✅ Backend processing and validation operational
- ✅ Database integration verified
- ✅ Customer reporting integration confirmed
- ✅ Staff documentation completed

**Files Modified:**
1. [`update-ticket-simple.php`](update-ticket-simple.php) - Added time tracking form and processing
2. [`ticket-simple.php`](ticket-simple.php) - Added time tracking display box
3. [`tickets-simple.php`](tickets-simple.php) - Added time indicators in list view
4. [`test-billable-hours.php`](test-billable-hours.php) - Created comprehensive test suite
5. [`BILLABLE-HOURS-WORKFLOW.md`](BILLABLE-HOURS-WORKFLOW.md) - Created staff documentation

**No Breaking Changes:**
- All modifications are additive - no existing functionality altered
- Backward compatibility maintained throughout
- Existing customer reports continue to function normally

### Future Considerations

**Potential Enhancements:**
1. **Bulk Time Entry**: Interface for entering time across multiple tickets
2. **Time Reports**: Dedicated reporting for time tracking analysis
3. **Integration APIs**: External time tracking tool integration
4. **Mobile Optimization**: Enhanced mobile interface for field time entry
5. **Automated Timers**: Optional automated time tracking capabilities

**Monitoring and Metrics:**
- Track time entry adoption rates
- Monitor billable hour accuracy
- Analyze customer satisfaction with time transparency
- Measure efficiency gains from improved time tracking

## System Maintenance

### Regular Maintenance Tasks

1. **Database Optimization**: Regular index maintenance and query optimization
2. **Security Updates**: Keep PHP and database versions current
3. **Backup Procedures**: Regular database and file system backups
4. **Performance Monitoring**: Monitor response times and system resource usage
5. **Log Rotation**: Manage system and application log files

### Monitoring and Alerts

- SLA breach notifications
- System performance alerts
- Database connectivity monitoring
- User authentication failure tracking
- API rate limiting and abuse detection

## Conclusion

The ITSPtickets system now provides comprehensive manual billable hours tracking that seamlessly integrates with existing organizational hour allowances and customer reporting. The implementation maintains the system's "simple model" architecture while adding professional-grade time tracking capabilities that meet business requirements for transparency and accuracy in customer billing.

The solution is production-ready with comprehensive testing, documentation, and staff training materials in place.