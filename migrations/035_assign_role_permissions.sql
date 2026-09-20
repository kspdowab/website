-- ============================================================
-- KSPDOWA Migration 035 — Role Permissions Matrix for Administration Levels
-- ============================================================
-- Assigns official operational permissions to administrative roles
-- (State, District, Taluk) as defined in 06_ROLES_PERMISSIONS_MATRIX.md.
-- Server-side geographic scoping continues to be strictly enforced via
-- association_units (district_id / taluk_id).
-- ============================================================

-- 1. State President & State General Secretary (Statewide Administration)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON p.module IN (
    'members', 'membership', 'payments', 'donations', 'grievances',
    'news', 'orders', 'circulars', 'documents', 'activities',
    'events', 'meetings', 'office_bearers', 'reports', 'suggestions', 'gallery', 'users'
)
WHERE r.name IN ('State President', 'State General Secretary');

-- 2. District President & District Secretary (District Scoped Administration)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
    (p.module = 'members' AND p.action IN ('view', 'create', 'edit', 'manage', 'export')) OR
    (p.module = 'membership' AND p.action = 'view') OR
    (p.module = 'payments' AND p.action IN ('view', 'export')) OR
    (p.module = 'donations' AND p.action = 'view') OR
    (p.module = 'grievances' AND p.action IN ('view', 'create', 'edit', 'forward', 'escalate', 'assign', 'upload', 'manage', 'export')) OR
    (p.module IN ('orders', 'circulars', 'news', 'office_bearers', 'gallery') AND p.action = 'view') OR
    (p.module = 'documents' AND p.action IN ('view', 'upload')) OR
    (p.module IN ('activities', 'events', 'meetings') AND p.action IN ('view', 'create', 'edit', 'manage')) OR
    (p.module = 'reports' AND p.action IN ('view', 'export')) OR
    (p.module = 'suggestions' AND p.action IN ('view', 'create', 'manage'))
)
WHERE r.name IN ('District President', 'District Secretary');

-- 3. Taluk President & Taluk Secretary (Taluk Scoped Administration)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
    (p.module = 'members' AND p.action IN ('view', 'create', 'edit', 'manage', 'export')) OR
    (p.module = 'membership' AND p.action = 'view') OR
    (p.module = 'payments' AND p.action IN ('view', 'export')) OR
    (p.module = 'donations' AND p.action = 'view') OR
    (p.module = 'grievances' AND p.action IN ('view', 'create', 'edit', 'forward', 'escalate', 'upload', 'manage')) OR
    (p.module IN ('orders', 'circulars', 'news', 'office_bearers', 'gallery') AND p.action = 'view') OR
    (p.module = 'documents' AND p.action IN ('view', 'upload')) OR
    (p.module IN ('activities', 'events', 'meetings') AND p.action IN ('view', 'create', 'edit', 'manage')) OR
    (p.module = 'reports' AND p.action IN ('view', 'export')) OR
    (p.module = 'suggestions' AND p.action IN ('view', 'create', 'manage'))
)
WHERE r.name IN ('Taluk President', 'Taluk Secretary');

-- 4. State, District & Taluk Grievance Officers
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
    (p.module = 'grievances') OR
    (p.module IN ('members', 'orders', 'circulars', 'documents', 'reports', 'suggestions') AND p.action = 'view')
)
WHERE r.name IN ('State Grievance Officer', 'District Grievance Officer', 'Taluk Grievance Officer');

-- 5. State Committee Members (Statewide Review / Read Access)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
    p.action = 'view' AND p.module IN (
        'members', 'grievances', 'activities', 'events', 'meetings',
        'news', 'orders', 'circulars', 'documents', 'office_bearers', 'reports', 'suggestions'
    )
)
WHERE r.name = 'State Committee Member';
