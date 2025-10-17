-- Add sample ticket data for CBM organization to test customer reports
-- This matches the data shown in the report image

-- First, let's ensure we have the CBM organization and some requesters
INSERT IGNORE INTO requesters (name, email, company, organization_id) VALUES 
('Sho Ibanez-Protacio', 'sho.ibanez-protacio@cbm-ltd.com', 'CBM Limited', 1),
('Sarah Woodbury', 'sarah.woodbury@cbm-ltd.com', 'CBM Limited', 1),
('Laura Parkes', 'laura.parkes@cbm-ltd.com', 'CBM Limited', 1),
('Samira Klorita', 'samira.klorita@cbm-ltd.com', 'CBM Limited', 1),
('Francesca Spataro', 'francesca.spataro@cbm-ltd.com', 'CBM Limited', 1),
('Allan Thompson', 'allan.thompson@cbm-ltd.com', 'CBM Limited', 1),
('Trena Battams', 'trena.battams@cbm-ltd.com', 'CBM Limited', 1),
('Ramya Jeyaprakash', 'ramya.jeyaprakash@cbm-ltd.com', 'CBM Limited', 1),
('Emily Fairweather', 'emily.fairweather@cbm-ltd.com', 'CBM Limited', 1);

-- Get the requester IDs for CBM
SET @sho_id = (SELECT id FROM requesters WHERE email = 'sho.ibanez-protacio@cbm-ltd.com' LIMIT 1);
SET @sarah_id = (SELECT id FROM requesters WHERE email = 'sarah.woodbury@cbm-ltd.com' LIMIT 1);
SET @laura_id = (SELECT id FROM requesters WHERE email = 'laura.parkes@cbm-ltd.com' LIMIT 1);
SET @samira_id = (SELECT id FROM requesters WHERE email = 'samira.klorita@cbm-ltd.com' LIMIT 1);
SET @francesca_id = (SELECT id FROM requesters WHERE email = 'francesca.spataro@cbm-ltd.com' LIMIT 1);
SET @allan_id = (SELECT id FROM requesters WHERE email = 'allan.thompson@cbm-ltd.com' LIMIT 1);
SET @trena_id = (SELECT id FROM requesters WHERE email = 'trena.battams@cbm-ltd.com' LIMIT 1);
SET @ramya_id = (SELECT id FROM requesters WHERE email = 'ramya.jeyaprakash@cbm-ltd.com' LIMIT 1);
SET @emily_id = (SELECT id FROM requesters WHERE email = 'emily.fairweather@cbm-ltd.com' LIMIT 1);

-- Insert sample tickets for August 2024 that match the report image
INSERT IGNORE INTO tickets (`key`, type, subject, description, priority, status, requester_id, category, subcategory, time_spent, billable_hours, created_at, resolved_at) VALUES 

-- Screens category tickets (3 tickets, 4.25 hours total)
('28605', 'request', 'Urgent Call Request - Black screen', 'User experiencing black screen on laptop', 'urgent', 'resolved', @sho_id, 'Screens', 'Screen', 0.25, 0.25, '2024-08-05 09:00:00', '2024-08-05 09:15:00'),
('28699', 'request', 'RE: Dell Service: Case Num', 'Dell hardware service request for screen replacement', 'normal', 'resolved', @trena_id, 'Hardware', 'Screens', 2.0, 2.0, '2024-08-20 13:15:00', '2024-08-20 15:15:00'),
('28447', 'incident', 'Laptop Screen Issue', 'Screen flickering on laptop display', 'normal', 'resolved', @emily_id, 'Windows', 'Screens', 2.0, 2.0, '2024-08-28 14:30:00', '2024-08-28 16:30:00'),

-- Email category tickets (2 tickets, 2 hours total)
('28691', 'request', 'Meeting acceptance', 'Unable to accept meeting invitations', 'normal', 'resolved', @laura_id, 'Email', '', 0.5, 0.5, '2024-08-12 14:00:00', '2024-08-12 14:30:00'),
('28709', 'request', 'FW: Payslips', 'Email forwarding issues with payslips', 'normal', 'resolved', @ramya_id, 'Email', '', 1.5, 1.5, '2024-08-25 10:20:00', '2024-08-25 11:50:00'),

-- Network category tickets (1 ticket, 2 hours)
('28692', 'request', 'IT9 from home', 'VPN connection issues working from home', 'normal', 'resolved', @samira_id, 'Network', '', 2.0, 2.0, '2024-08-15 11:00:00', '2024-08-15 13:00:00'),

-- Security category tickets (1 ticket, 0.25 hours)
('28698', 'request', 'Unknown Sign-in', 'Unknown sign-in notification received', 'normal', 'resolved', @allan_id, 'Security', '', 0.25, 0.25, '2024-08-18 09:45:00', '2024-08-18 10:00:00'),

-- Teams category tickets (1 ticket, 0.5 hours)
('28678', 'request', 'why am I not allowed to sa', 'Unable to save documents in Teams', 'normal', 'resolved', @sho_id, 'Teams', '', 0.5, 0.5, '2024-08-22 16:00:00', '2024-08-22 16:30:00'),

-- Apps category tickets (1 ticket, 0.25 hours)
('28693', 'incident', 'Microsoft Word Warning', 'Word displaying security warnings', 'normal', 'resolved', @francesca_id, 'Apps', '', 0.25, 0.25, '2024-08-16 15:30:00', '2024-08-16 15:45:00'),

-- Hardware category tickets (1 ticket, 1.0 hour)
('28671', 'incident', 'Urgent: Laptop failure', 'Complete laptop hardware failure', 'urgent', 'resolved', @sarah_id, 'Hardware', 'Laptop', 1.0, 1.0, '2024-08-08 10:30:00', '2024-08-08 11:30:00');

-- Update organization to set prebooked hours for this period
UPDATE organizations SET monthly_hours_allowance = 36.0 WHERE code = 'CBM';