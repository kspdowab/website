-- ============================================================
-- KSPDOWA Seed 001 — Roles and Permissions
-- Source: 06_ROLES_PERMISSIONS_MATRIX.md
-- Created: Phase 0
--
-- Seeds exactly what the specification defines.
-- Does NOT invent role-permission mappings beyond what is stated.
--
-- Role-permission assignments seeded:
--   State Super Admin → all permissions (full system scope per spec §2)
--   Regular Member    → self-service permissions only (own records per spec §4)
--
-- All other role-permission mappings must be configured by the
-- State Super Admin via the admin interface in Phase 6.
-- ============================================================

-- ------------------------------------------------------------
-- Roles (06_ROLES_PERMISSIONS_MATRIX.md §1)
-- ------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `scope_type`, `status`) VALUES
  ('State Super Admin',        'state',    'active'),
  ('State President',          'state',    'active'),
  ('State General Secretary',  'state',    'active'),
  ('State Grievance Officer',  'state',    'active'),
  ('State Committee Member',   'state',    'active'),
  ('District President',       'district', 'active'),
  ('District Secretary',       'district', 'active'),
  ('District Grievance Officer','district','active'),
  ('Taluk President',          'taluk',    'active'),
  ('Taluk Secretary',          'taluk',    'active'),
  ('Taluk Grievance Officer',  'taluk',    'active'),
  ('Regular Member',           'member',   'active');

-- ------------------------------------------------------------
-- Permissions (06_ROLES_PERMISSIONS_MATRIX.md §3)
-- Actions: view, create, edit, approve, forward, escalate,
--          assign, upload, publish, export, manage
-- ------------------------------------------------------------

-- members
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('members', 'view',   'View Members'),
  ('members', 'create', 'Create Member'),
  ('members', 'edit',   'Edit Member'),
  ('members', 'manage', 'Manage Members'),
  ('members', 'export', 'Export Members');

-- membership
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('membership', 'view',    'View Membership Records'),
  ('membership', 'create',  'Create Membership Record'),
  ('membership', 'edit',    'Edit Membership Record'),
  ('membership', 'approve', 'Approve Membership'),
  ('membership', 'manage',  'Manage Membership');

-- payments
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('payments', 'view',   'View Payments'),
  ('payments', 'manage', 'Manage Payments'),
  ('payments', 'export', 'Export Payments');

-- donations
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('donations', 'view',   'View Donations'),
  ('donations', 'manage', 'Manage Donations');

-- grievances
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('grievances', 'view',     'View Grievances'),
  ('grievances', 'create',   'Submit Grievance'),
  ('grievances', 'edit',     'Edit Grievance'),
  ('grievances', 'forward',  'Forward Grievance'),
  ('grievances', 'escalate', 'Escalate Grievance'),
  ('grievances', 'assign',   'Assign Grievance'),
  ('grievances', 'upload',   'Upload Grievance Document'),
  ('grievances', 'manage',   'Manage Grievances'),
  ('grievances', 'export',   'Export Grievances');

-- news
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('news', 'view',    'View News'),
  ('news', 'create',  'Create News'),
  ('news', 'edit',    'Edit News'),
  ('news', 'publish', 'Publish News'),
  ('news', 'manage',  'Manage News');

-- orders
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('orders', 'view',    'View Orders'),
  ('orders', 'create',  'Create Order'),
  ('orders', 'edit',    'Edit Order'),
  ('orders', 'publish', 'Publish Order'),
  ('orders', 'manage',  'Manage Orders');

-- circulars
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('circulars', 'view',    'View Circulars'),
  ('circulars', 'create',  'Create Circular'),
  ('circulars', 'edit',    'Edit Circular'),
  ('circulars', 'publish', 'Publish Circular'),
  ('circulars', 'manage',  'Manage Circulars');

-- documents
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('documents', 'view',   'View Documents'),
  ('documents', 'upload', 'Upload Document'),
  ('documents', 'manage', 'Manage Documents');

-- activities
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('activities', 'view',   'View Activities'),
  ('activities', 'create', 'Create Activity'),
  ('activities', 'edit',   'Edit Activity'),
  ('activities', 'manage', 'Manage Activities');

-- events
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('events', 'view',   'View Events'),
  ('events', 'create', 'Create Event'),
  ('events', 'edit',   'Edit Event'),
  ('events', 'manage', 'Manage Events');

-- meetings
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('meetings', 'view',   'View Meetings'),
  ('meetings', 'create', 'Create Meeting'),
  ('meetings', 'edit',   'Edit Meeting'),
  ('meetings', 'manage', 'Manage Meetings');

-- office_bearers
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('office_bearers', 'view',   'View Office Bearers'),
  ('office_bearers', 'manage', 'Manage Office Bearers');

-- reports
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('reports', 'view',   'View Reports'),
  ('reports', 'export', 'Export Reports');

-- users
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('users', 'view',   'View Users'),
  ('users', 'create', 'Create User'),
  ('users', 'edit',   'Edit User'),
  ('users', 'manage', 'Manage Users');

-- roles
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('roles', 'view',   'View Roles'),
  ('roles', 'manage', 'Manage Roles');

-- audit_logs
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('audit_logs', 'view', 'View Audit Logs');

-- settings
INSERT IGNORE INTO `permissions` (`module`, `action`, `name`) VALUES
  ('settings', 'view',   'View Settings'),
  ('settings', 'manage', 'Manage Settings');

-- ------------------------------------------------------------
-- Role-permission assignments
-- ------------------------------------------------------------

-- State Super Admin: full system scope = ALL permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  CROSS JOIN `permissions` p
  WHERE r.name = 'State Super Admin';

-- Regular Member: self-service permissions only
-- "A member can view only their own private records and grievances." (spec §4)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.module = 'grievances' AND p.action IN ('view', 'create', 'upload')
  WHERE r.name = 'Regular Member';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.module = 'members' AND p.action = 'view'
  WHERE r.name = 'Regular Member';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.module IN ('news','orders','circulars','documents','activities',
                    'events','office_bearers','payments')
   AND p.action = 'view'
  WHERE r.name = 'Regular Member';
