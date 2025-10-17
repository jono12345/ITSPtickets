-- Add category and subcategory columns to tickets table
ALTER TABLE tickets ADD COLUMN category_id BIGINT NULL AFTER priority;
ALTER TABLE tickets ADD COLUMN subcategory_id BIGINT NULL AFTER category_id;

-- Add indexes for better performance
ALTER TABLE tickets ADD INDEX idx_category_id (category_id);
ALTER TABLE tickets ADD INDEX idx_subcategory_id (subcategory_id);

-- Add foreign key constraints
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES ticket_categories(id);
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_subcategory FOREIGN KEY (subcategory_id) REFERENCES ticket_categories(id);

-- Clear existing ticket_categories and add comprehensive categories with subcategories
TRUNCATE TABLE ticket_categories;

-- Insert main categories (parent_id = NULL)
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Email', NULL, 'Email-related support issues', 0.50, 10),
('Network', NULL, 'Network connectivity and infrastructure', 1.00, 20),
('Security', NULL, 'Security-related issues and concerns', 1.50, 30),
('Teams', NULL, 'Microsoft Teams support', 0.75, 40),
('Screens', NULL, 'Display and monitor issues', 1.00, 50),
('Apps', NULL, 'Application support and troubleshooting', 1.25, 60),
('Hardware', NULL, 'Hardware-related issues and repairs', 2.00, 70),
('Office 365', NULL, 'Microsoft Office 365 support', 1.00, 80),
('Software', NULL, 'General software support', 1.50, 90),
('Windows', NULL, 'Windows operating system support', 1.75, 100);

-- Insert subcategories for Email
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Setup/Configuration', (SELECT id FROM ticket_categories WHERE name = 'Email' AND parent_id IS NULL), 'Email client setup and configuration', 0.75, 1),
('Outlook Issues', (SELECT id FROM ticket_categories WHERE name = 'Email' AND parent_id IS NULL), 'Microsoft Outlook specific problems', 0.50, 2),
('Webmail Access', (SELECT id FROM ticket_categories WHERE name = 'Email' AND parent_id IS NULL), 'Webmail login and access issues', 0.25, 3),
('Email Delivery', (SELECT id FROM ticket_categories WHERE name = 'Email' AND parent_id IS NULL), 'Email not sending or receiving', 0.50, 4);

-- Insert subcategories for Network
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Internet Connection', (SELECT id FROM ticket_categories WHERE name = 'Network' AND parent_id IS NULL), 'Internet connectivity problems', 1.00, 1),
('WiFi Issues', (SELECT id FROM ticket_categories WHERE name = 'Network' AND parent_id IS NULL), 'Wireless network problems', 0.75, 2),
('VPN Access', (SELECT id FROM ticket_categories WHERE name = 'Network' AND parent_id IS NULL), 'VPN connection and setup', 1.25, 3),
('Network Drives', (SELECT id FROM ticket_categories WHERE name = 'Network' AND parent_id IS NULL), 'Shared drive access issues', 0.75, 4);

-- Insert subcategories for Security
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Password Reset', (SELECT id FROM ticket_categories WHERE name = 'Security' AND parent_id IS NULL), 'Password reset requests', 0.25, 1),
('Account Lockout', (SELECT id FROM ticket_categories WHERE name = 'Security' AND parent_id IS NULL), 'User account locked out', 0.50, 2),
('Malware/Virus', (SELECT id FROM ticket_categories WHERE name = 'Security' AND parent_id IS NULL), 'Virus or malware detection', 2.00, 3),
('Security Updates', (SELECT id FROM ticket_categories WHERE name = 'Security' AND parent_id IS NULL), 'Security patch installations', 1.00, 4);

-- Insert subcategories for Teams
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Audio/Video Issues', (SELECT id FROM ticket_categories WHERE name = 'Teams' AND parent_id IS NULL), 'Teams call quality problems', 0.75, 1),
('Meeting Setup', (SELECT id FROM ticket_categories WHERE name = 'Teams' AND parent_id IS NULL), 'Teams meeting configuration', 0.50, 2),
('File Sharing', (SELECT id FROM ticket_categories WHERE name = 'Teams' AND parent_id IS NULL), 'Teams file sharing issues', 0.50, 3),
('Teams Installation', (SELECT id FROM ticket_categories WHERE name = 'Teams' AND parent_id IS NULL), 'Teams software installation', 0.75, 4);

-- Insert subcategories for Screens
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Monitor Setup', (SELECT id FROM ticket_categories WHERE name = 'Screens' AND parent_id IS NULL), 'Monitor installation and setup', 1.00, 1),
('Display Issues', (SELECT id FROM ticket_categories WHERE name = 'Screens' AND parent_id IS NULL), 'Screen resolution or display problems', 0.75, 2),
('Multiple Monitors', (SELECT id FROM ticket_categories WHERE name = 'Screens' AND parent_id IS NULL), 'Multi-monitor configuration', 1.25, 3),
('Projector Setup', (SELECT id FROM ticket_categories WHERE name = 'Screens' AND parent_id IS NULL), 'Projector connection and setup', 1.50, 4);

-- Insert subcategories for Apps
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Software Installation', (SELECT id FROM ticket_categories WHERE name = 'Apps' AND parent_id IS NULL), 'Application installation requests', 1.00, 1),
('Application Crashes', (SELECT id FROM ticket_categories WHERE name = 'Apps' AND parent_id IS NULL), 'Software crashes and errors', 1.25, 2),
('License Issues', (SELECT id FROM ticket_categories WHERE name = 'Apps' AND parent_id IS NULL), 'Software licensing problems', 1.50, 3),
('Updates/Patches', (SELECT id FROM ticket_categories WHERE name = 'Apps' AND parent_id IS NULL), 'Application updates and patches', 0.75, 4);

-- Insert subcategories for Hardware
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('PC/Laptop Issues', (SELECT id FROM ticket_categories WHERE name = 'Hardware' AND parent_id IS NULL), 'Computer hardware problems', 2.00, 1),
('Printer Problems', (SELECT id FROM ticket_categories WHERE name = 'Hardware' AND parent_id IS NULL), 'Printer setup and troubleshooting', 1.50, 2),
('Keyboard/Mouse', (SELECT id FROM ticket_categories WHERE name = 'Hardware' AND parent_id IS NULL), 'Input device issues', 0.50, 3),
('Equipment Request', (SELECT id FROM ticket_categories WHERE name = 'Hardware' AND parent_id IS NULL), 'New hardware requests', 2.50, 4);

-- Insert subcategories for Office 365
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Word/Excel Issues', (SELECT id FROM ticket_categories WHERE name = 'Office 365' AND parent_id IS NULL), 'Microsoft Office application problems', 1.00, 1),
('OneDrive Sync', (SELECT id FROM ticket_categories WHERE name = 'Office 365' AND parent_id IS NULL), 'OneDrive synchronization issues', 1.25, 2),
('SharePoint Access', (SELECT id FROM ticket_categories WHERE name = 'Office 365' AND parent_id IS NULL), 'SharePoint connectivity problems', 1.50, 3),
('Office Updates', (SELECT id FROM ticket_categories WHERE name = 'Office 365' AND parent_id IS NULL), 'Office 365 updates and patches', 0.75, 4);

-- Insert subcategories for Software
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Third-party Apps', (SELECT id FROM ticket_categories WHERE name = 'Software' AND parent_id IS NULL), 'Non-Microsoft software issues', 1.50, 1),
('Browser Issues', (SELECT id FROM ticket_categories WHERE name = 'Software' AND parent_id IS NULL), 'Web browser problems', 0.75, 2),
('Antivirus Software', (SELECT id FROM ticket_categories WHERE name = 'Software' AND parent_id IS NULL), 'Antivirus software issues', 1.00, 3),
('System Utilities', (SELECT id FROM ticket_categories WHERE name = 'Software' AND parent_id IS NULL), 'System utility software problems', 1.25, 4);

-- Insert subcategories for Windows
INSERT INTO ticket_categories (name, parent_id, description, default_time_estimate, sort_order) VALUES
('Windows Updates', (SELECT id FROM ticket_categories WHERE name = 'Windows' AND parent_id IS NULL), 'Windows update issues', 1.50, 1),
('System Crashes', (SELECT id FROM ticket_categories WHERE name = 'Windows' AND parent_id IS NULL), 'Windows system crashes and errors', 2.00, 2),
('User Profile Issues', (SELECT id FROM ticket_categories WHERE name = 'Windows' AND parent_id IS NULL), 'User profile corruption or issues', 1.75, 3),
('Performance Issues', (SELECT id FROM ticket_categories WHERE name = 'Windows' AND parent_id IS NULL), 'System slowness and performance', 2.00, 4);