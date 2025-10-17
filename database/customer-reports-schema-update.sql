-- Customer Reporting System - Schema Updates
-- Add missing fields and tables for professional customer reports

-- Add time tracking columns to tickets table
ALTER TABLE tickets ADD COLUMN time_spent DECIMAL(5,2) DEFAULT 0.00;
ALTER TABLE tickets ADD COLUMN billable_hours DECIMAL(5,2) DEFAULT 0.00;

-- Add indexes for type-based reporting (using existing type field)
-- Note: The tickets table already has a 'type' field with values ('incident','request','job')
-- No need to add category/subcategory fields as they don't exist in the current system

-- Create organizations table for better customer management
CREATE TABLE IF NOT EXISTS organizations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(10) UNIQUE NOT NULL,
    description TEXT NULL,
    contact_email VARCHAR(255) NULL,
    contact_phone VARCHAR(50) NULL,
    address TEXT NULL,
    monthly_hours_allowance DECIMAL(6,2) DEFAULT 0.00,
    quarterly_hours_allowance DECIMAL(6,2) DEFAULT 0.00,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (code),
    INDEX (active)
);

-- Add organization relationship to requesters
ALTER TABLE requesters ADD COLUMN organization_id BIGINT NULL;
ALTER TABLE requesters ADD INDEX idx_organization (organization_id);
ALTER TABLE requesters ADD CONSTRAINT fk_requesters_organization FOREIGN KEY (organization_id) REFERENCES organizations(id);

-- Create ticket categories reference table
CREATE TABLE IF NOT EXISTS ticket_categories (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    parent_id BIGINT NULL,
    description TEXT NULL,
    default_time_estimate DECIMAL(4,2) DEFAULT 0.00,
    active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (parent_id),
    INDEX (active, sort_order)
);

-- Create time tracking entries for detailed time logging
CREATE TABLE IF NOT EXISTS time_entries (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    description TEXT NULL,
    hours DECIMAL(4,2) NOT NULL,
    billable BOOLEAN DEFAULT TRUE,
    date_worked DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (ticket_id),
    INDEX (user_id),
    INDEX (date_worked),
    INDEX (billable),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create customer reports tracking
CREATE TABLE IF NOT EXISTS customer_reports (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT NOT NULL,
    report_type ENUM('monthly', 'quarterly', 'custom') NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_tickets INT DEFAULT 0,
    total_hours DECIMAL(6,2) DEFAULT 0.00,
    billable_hours DECIMAL(6,2) DEFAULT 0.00,
    prepaid_hours_used DECIMAL(6,2) DEFAULT 0.00,
    prepaid_hours_remaining DECIMAL(6,2) DEFAULT 0.00,
    generated_by_user_id BIGINT NOT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    report_data JSON NULL,
    INDEX (organization_id, period_start),
    INDEX (report_type),
    INDEX (generated_at),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (generated_by_user_id) REFERENCES users(id)
);

-- Insert default ticket categories based on the report example
INSERT IGNORE INTO ticket_categories (name, description, sort_order) VALUES
('Email', 'Email-related support issues', 10),
('Network', 'Network connectivity and infrastructure', 20),
('Security', 'Security-related issues and concerns', 30),
('Teams', 'Microsoft Teams support', 40),
('Screens', 'Display and monitor issues', 50),
('Apps', 'Application support and troubleshooting', 60),
('Hardware', 'Hardware-related issues and repairs', 70),
('Office 365', 'Microsoft Office 365 support', 80),
('Software', 'General software support', 90),
('Windows', 'Windows operating system support', 100);

-- Insert sample organization (EXAMPLE DATA ONLY - Replace with your actual data)
INSERT IGNORE INTO organizations (name, code, description, contact_email, monthly_hours_allowance) VALUES
('Example Corp', 'DEMO', 'Sample organization for demonstration purposes only', 'demo@example.com', 40.0);