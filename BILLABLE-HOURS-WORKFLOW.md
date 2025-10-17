# Manual Billable Hours Workflow - ITSPtickets

## Overview
The ITSPtickets system now supports manual time tracking with separate fields for **Time Spent** (total work time) and **Billable Hours** (time billable to customers). This enables accurate billing and reporting for client work.

## Key Features
- ✅ **Simple Manual Input**: Staff manually enter time spent and billable hours
- ✅ **Validation**: Billable hours cannot exceed time spent
- ✅ **Visual Display**: Time tracking shows in ticket details and ticket lists
- ✅ **Customer Reports**: Billable hours automatically included in customer reports
- ✅ **Event Logging**: All time updates are tracked in ticket timeline

## How to Add Time Tracking to Tickets

### Method 1: Via Ticket Update Interface (Recommended)
1. **Open any ticket** from the tickets list
2. **Click "Update Ticket"** in the top-right corner
3. **Scroll to "Time Tracking" section**
4. **Enter time values:**
   - **Time Spent**: Total hours worked (e.g., 3.5)
   - **Billable Hours**: Hours to bill customer (e.g., 2.5)
5. **Click "Update Time Tracking"**
6. **Return to ticket view** to see the time tracking display

### Method 2: While Resolving Tickets
When resolving tickets, you can add time tracking either before or after updating the status to "Resolved".

## Field Definitions

### Time Spent
- **Purpose**: Total time worked on the ticket
- **Includes**: All time spent including research, testing, documentation, communication
- **Examples**: 2.75 hours, 4.0 hours, 0.5 hours

### Billable Hours  
- **Purpose**: Time to be billed to the customer
- **Includes**: Only time directly billable to client
- **Excludes**: Internal training time, learning, administrative tasks
- **Rule**: Must be ≤ Time Spent

## Examples of Time Tracking

### Example 1: Server Issue
- **Time Spent**: 4.0 hours
- **Billable Hours**: 3.5 hours
- **Reason**: 30 minutes spent learning new diagnostic tool (non-billable)

### Example 2: User Training
- **Time Spent**: 2.0 hours  
- **Billable Hours**: 2.0 hours
- **Reason**: All time directly helping customer (fully billable)

### Example 3: Research Task
- **Time Spent**: 6.0 hours
- **Billable Hours**: 4.0 hours  
- **Reason**: 2 hours spent on internal documentation and knowledge sharing

## Where Time Tracking Appears

### 1. Ticket Details Page
Shows a blue time tracking box with:
- Time Spent: X.XX hours
- Billable Hours: X.XX hours  
- Billable percentage: XX.X% of time spent

### 2. Tickets List
Shows green time indicator: ⏱️ X.Xh spent | X.Xh billable

### 3. Customer Reports
- **Monthly Reports**: Uses billable hours for organization allowance calculations
- **Quarterly Reports**: Tracks against quarterly hour allowances
- **Custom Reports**: Shows all billable time in date range

## Business Rules

### Validation Rules
1. **Time Spent** must be ≥ 0
2. **Billable Hours** must be ≥ 0  
3. **Billable Hours** cannot exceed **Time Spent**
4. Both fields accept decimal values (e.g., 2.25 for 2 hours 15 minutes)

### Workflow Rules
1. **Time tracking can be updated anytime** during ticket lifecycle
2. **Multiple updates allowed** - system tracks changes in timeline
3. **Staff can see all time tracking** in ticket history
4. **Customers see billable hours** in their reports

## Integration with Customer Reports

### Hour Allowances
Organizations can have:
- **Monthly Hours Allowance**: Prepaid hours per month
- **Quarterly Hours Allowance**: Prepaid hours per quarter

### Report Calculations
- **Billable hours are deducted** from allowances first
- **Overage hours** are shown separately for additional billing
- **Remaining allowance** calculated and displayed

### Report Display
Customer reports show:
- Total tickets resolved
- Total hours worked (time spent)
- **Prebooked hours used (billable hours)**
- Remaining allowance hours

## Tips for Accurate Time Tracking

### Best Practices
1. **Track time promptly** while details are fresh
2. **Round to quarter hours** (0.25 increments) for consistency
3. **Be honest about billable vs. non-billable** time
4. **Use time tracking for all resolved tickets**

### Common Scenarios
- **Phone calls**: Usually fully billable (unless internal)
- **Email responses**: Generally billable
- **Research for ticket**: Often partially billable  
- **Learning new tools**: Usually non-billable
- **Documentation**: May be billable if client-specific

## Troubleshooting

### Common Issues
- **"Billable hours cannot exceed time spent"**: Reduce billable hours or increase time spent
- **Time tracking not saving**: Check network connection and try again
- **Time not showing in reports**: Ensure ticket status is "resolved" or "closed"

### Getting Help
- **Questions about billing**: Contact your supervisor
- **Technical issues**: Contact system administrator
- **Report questions**: Check with billing department

## Testing the System

A test script is available at `/ITSPtickets/test-billable-hours.php` to verify:
- Database schema is properly set up
- Time tracking saves correctly
- Customer report integration works

---

**Document Version**: 1.0  
**Last Updated**: <?= date('Y-m-d') ?>  
**For**: ITSPtickets Staff Manual Time Tracking