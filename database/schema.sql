-- ITSPtickets Database Schema
-- Based on the specifications in claude.md

SET FOREIGN_KEY_CHECKS = 0;

-- Users table for internal staff (agents, supervisors, admins)
CREATE TABLE IF NOT EXISTS users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'supervisor', 'agent') NOT NULL,
    team_id BIGINT NULL,
    active BOOLEAN DEFAULT TRUE,
    last_login_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (team_id),
    INDEX (role),
    INDEX (active)
);

-- Teams table for organizing agents
CREATE TABLE IF NOT EXISTS teams (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    supervisor_id BIGINT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (supervisor_id),
    INDEX (active)
);

-- External requesters (customers)
CREATE TABLE IF NOT EXISTS requesters (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50) NULL,
    company VARCHAR(255) NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (active)
);

-- Business hours calendars
CREATE TABLE IF NOT EXISTS calendars (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    timezone VARCHAR(100) DEFAULT 'Europe/London',
    monday_start TIME NULL,
    monday_end TIME NULL,
    tuesday_start TIME NULL,
    tuesday_end TIME NULL,
    wednesday_start TIME NULL,
    wednesday_end TIME NULL,
    thursday_start TIME NULL,
    thursday_end TIME NULL,
    friday_start TIME NULL,
    friday_end TIME NULL,
    saturday_start TIME NULL,
    saturday_end TIME NULL,
    sunday_start TIME NULL,
    sunday_end TIME NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- SLA policies
CREATE TABLE IF NOT EXISTS sla_policies (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    type ENUM('incident', 'request', 'job') NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL,
    response_target INT NOT NULL COMMENT 'Minutes',
    update_target INT NULL COMMENT 'Minutes',
    resolution_target INT NOT NULL COMMENT 'Minutes',
    calendar_id BIGINT NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (type, priority),
    INDEX (calendar_id),
    INDEX (active),
    FOREIGN KEY (calendar_id) REFERENCES calendars(id)
);

-- Main tickets table
CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    `key` VARCHAR(32) NOT NULL,
    type ENUM('incident','request','job') NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description MEDIUMTEXT,
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    status ENUM('new','triaged','in_progress','waiting','on_hold','resolved','closed') DEFAULT 'new',
    requester_id BIGINT NOT NULL,
    assignee_id BIGINT NULL,
    team_id BIGINT NULL,
    sla_policy_id BIGINT NULL,
    channel ENUM('web','email','sms','api') DEFAULT 'web',
    tags JSON,
    first_response_at DATETIME NULL,
    last_update_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    closed_at DATETIME NULL,
    UNIQUE (`key`),
    INDEX (status),
    INDEX (priority),
    INDEX (assignee_id, status),
    INDEX (team_id, status),
    INDEX (requester_id),
    INDEX (created_at),
    FULLTEXT (subject, description),
    FOREIGN KEY (requester_id) REFERENCES requesters(id),
    FOREIGN KEY (assignee_id) REFERENCES users(id),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (sla_policy_id) REFERENCES sla_policies(id)
);

-- Ticket messages/replies
CREATE TABLE IF NOT EXISTS ticket_messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    sender_type ENUM('user', 'requester') NOT NULL,
    sender_id BIGINT NOT NULL,
    message MEDIUMTEXT NOT NULL,
    is_private BOOLEAN DEFAULT FALSE,
    channel ENUM('web','email','sms','api') DEFAULT 'web',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (ticket_id, created_at),
    INDEX (sender_type, sender_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

-- Ticket events/activities
CREATE TABLE IF NOT EXISTS ticket_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    user_id BIGINT NULL,
    event_type VARCHAR(100) NOT NULL,
    description TEXT,
    old_value TEXT NULL,
    new_value TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (ticket_id, created_at),
    INDEX (user_id),
    INDEX (event_type),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- SLA tracking segments
CREATE TABLE IF NOT EXISTS sla_segments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    segment_type ENUM('response', 'update', 'resolution') NOT NULL,
    started_at DATETIME NOT NULL,
    paused_at DATETIME NULL,
    resumed_at DATETIME NULL,
    completed_at DATETIME NULL,
    business_minutes INT DEFAULT 0,
    calendar_minutes INT DEFAULT 0,
    target_minutes INT NOT NULL,
    is_breached BOOLEAN DEFAULT FALSE,
    INDEX (ticket_id, segment_type),
    INDEX (is_breached),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

-- Escalations
CREATE TABLE IF NOT EXISTS escalations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    escalation_type ENUM('sla_warning', 'sla_breach', 'manual') NOT NULL,
    from_user_id BIGINT NULL,
    to_user_id BIGINT NULL,
    to_team_id BIGINT NULL,
    reason TEXT,
    escalated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX (ticket_id),
    INDEX (escalation_type),
    INDEX (to_user_id),
    INDEX (to_team_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id),
    FOREIGN KEY (to_user_id) REFERENCES users(id),
    FOREIGN KEY (to_team_id) REFERENCES teams(id)
);

-- Email mailboxes
CREATE TABLE IF NOT EXISTS mailboxes (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    type ENUM('pop3', 'imap', 'webhook') NOT NULL,
    host VARCHAR(255) NULL,
    port INT NULL,
    username VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    encryption ENUM('ssl', 'tls', 'none') DEFAULT 'none',
    webhook_secret VARCHAR(255) NULL,
    active BOOLEAN DEFAULT TRUE,
    last_checked_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (active)
);

-- SMS endpoints
CREATE TABLE IF NOT EXISTS sms_endpoints (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    provider ENUM('twilio', 'vonage') NOT NULL,
    phone_number VARCHAR(50) NOT NULL,
    api_key VARCHAR(255) NOT NULL,
    api_secret VARCHAR(255) NOT NULL,
    webhook_url VARCHAR(500) NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (phone_number),
    INDEX (active)
);

-- File attachments
CREATE TABLE IF NOT EXISTS files (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NULL,
    message_id BIGINT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size INT NOT NULL,
    path VARCHAR(500) NOT NULL,
    uploaded_by_user_id BIGINT NULL,
    uploaded_by_requester_id BIGINT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (ticket_id),
    INDEX (message_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id),
    FOREIGN KEY (uploaded_by_requester_id) REFERENCES requesters(id)
);

-- API tokens
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    user_id BIGINT NULL,
    abilities JSON,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (token),
    INDEX (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NULL,
    requester_id BIGINT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id BIGINT NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, created_at),
    INDEX (requester_id, created_at),
    INDEX (action),
    INDEX (resource_type, resource_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (requester_id) REFERENCES requesters(id)
);

-- Assets (optional for basic implementation)
CREATE TABLE IF NOT EXISTS assets (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    serial_number VARCHAR(255) NULL,
    location VARCHAR(255) NULL,
    assigned_to_requester_id BIGINT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (type),
    INDEX (assigned_to_requester_id),
    FOREIGN KEY (assigned_to_requester_id) REFERENCES requesters(id)
);

-- Add foreign key constraints
ALTER TABLE users ADD CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id);
ALTER TABLE teams ADD CONSTRAINT fk_teams_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id);

SET FOREIGN_KEY_CHECKS = 1;

-- Insert default data
INSERT INTO calendars (name, timezone, monday_start, monday_end, tuesday_start, tuesday_end, wednesday_start, wednesday_end, thursday_start, thursday_end, friday_start, friday_end) 
VALUES ('Business Hours', 'Europe/London', '09:00:00', '17:00:00', '09:00:00', '17:00:00', '09:00:00', '17:00:00', '09:00:00', '17:00:00', '09:00:00', '17:00:00');

INSERT INTO sla_policies (name, type, priority, response_target, resolution_target, calendar_id) VALUES
('Incident - Urgent', 'incident', 'urgent', 15, 240, 1),
('Incident - High', 'incident', 'high', 60, 480, 1),
('Incident - Normal', 'incident', 'normal', 240, 1440, 1),
('Request - High', 'request', 'high', 120, 2880, 1),
('Request - Normal', 'request', 'normal', 480, 5760, 1),
('Job - Normal', 'job', 'normal', 480, 2880, 1);

-- ⚠️  DEFAULT ADMIN USER - CHANGE PASSWORD IMMEDIATELY! ⚠️
-- Default login: admin@admin.local / Password: admin
-- This is for DEVELOPMENT/TESTING only - NEVER use in production!
INSERT INTO users (name, email, password, role) VALUES
('System Admin', 'admin@admin.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- SECURITY WARNING: After setup, immediately:
-- 1. Change the admin password: UPDATE users SET password = PASSWORD('new-secure-password') WHERE email = 'admin@admin.local';
-- 2. Or create your own admin and delete this one
-- 3. NEVER use this default account in production environments