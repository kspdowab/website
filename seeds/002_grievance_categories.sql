-- ============================================================
-- KSPDOWA Seed 002 — Grievance Categories, Services, Authorities
-- Source: 03_GRIEVANCE_WORKFLOW.md §3, §4
-- Created: Phase 0
--
-- These lists are configurable by authorized administrators.
-- IDs are explicit to ensure FK consistency in seeds/tests.
-- ============================================================

-- ------------------------------------------------------------
-- Grievance categories (spec §3)
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_categories`
  (`id`, `name`, `description`, `status`, `sort_order`) VALUES
  (1, 'Service & Establishment',
   'Matters relating to service conditions and establishment procedures',
   'active', 1),
  (2, 'Panchayati Raj / Department Matters',
   'Matters relating to Panchayati Raj administration and departmental functions',
   'active', 2),
  (3, 'Salary & Financial Benefits',
   'Matters relating to salary, allowances, and financial benefits',
   'active', 3),
  (4, 'Promotion / Career',
   'Matters relating to promotion, career progression, and eligibility',
   'active', 4),
  (5, 'Legal / Disciplinary',
   'Matters relating to legal proceedings and disciplinary actions',
   'active', 5);

-- ------------------------------------------------------------
-- Category 1: Service & Establishment
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_services`
  (`category_id`, `name`, `status`, `sort_order`) VALUES
  (1, 'Appointment',          'active',  1),
  (1, 'Probation',            'active',  2),
  (1, 'Confirmation',         'active',  3),
  (1, 'Seniority',            'active',  4),
  (1, 'Promotion',            'active',  5),
  (1, 'Increment',            'active',  6),
  (1, 'Pay Fixation',         'active',  7),
  (1, 'Salary',               'active',  8),
  (1, 'Arrears',              'active',  9),
  (1, 'Transfer',             'active', 10),
  (1, 'Posting',              'active', 11),
  (1, 'Deputation',           'active', 12),
  (1, 'Relieving',            'active', 13),
  (1, 'Joining',              'active', 14),
  (1, 'Leave',                'active', 15),
  (1, 'Medical Leave',        'active', 16),
  (1, 'Earned Leave',         'active', 17),
  (1, 'Retirement',           'active', 18),
  (1, 'Voluntary Retirement', 'active', 19),
  (1, 'Pension',              'active', 20),
  (1, 'Gratuity',             'active', 21),
  (1, 'Service Register',     'active', 22),
  (1, 'Employee Records',     'active', 23),
  (1, 'Disciplinary Matters', 'active', 24),
  (1, 'Other Service Matters','active', 25);

-- ------------------------------------------------------------
-- Category 2: Panchayati Raj / Department Matters
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_services`
  (`category_id`, `name`, `status`, `sort_order`) VALUES
  (2, 'Gram Panchayati Administration',         'active',  1),
  (2, 'Panchayati Development Officer Duties',  'active',  2),
  (2, 'Administrative Powers',                  'active',  3),
  (2, 'Financial Powers',                       'active',  4),
  (2, 'Panchayati Meetings',                    'active',  5),
  (2, 'Panchayati Resolutions',                 'active',  6),
  (2, 'Panchayati Records',                     'active',  7),
  (2, 'Panchayati Staff Matters',               'active',  8),
  (2, 'Panchayati Funds',                       'active',  9),
  (2, 'e-Swathu',                               'active', 10),
  (2, 'Panchatantra',                           'active', 11),
  (2, 'Tax & Fees',                             'active', 12),
  (2, 'Audit',                                  'active', 13),
  (2, 'Inspection',                             'active', 14),
  (2, 'Other Departmental Matters',             'active', 15);

-- ------------------------------------------------------------
-- Category 3: Salary & Financial Benefits
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_services`
  (`category_id`, `name`, `status`, `sort_order`) VALUES
  (3, 'Salary Delay',            'active', 1),
  (3, 'Salary Discrepancy',      'active', 2),
  (3, 'Pay Revision',            'active', 3),
  (3, 'Allowances',              'active', 4),
  (3, 'TA/DA',                   'active', 5),
  (3, 'Medical Reimbursement',   'active', 6),
  (3, 'Other Reimbursement',     'active', 7),
  (3, 'Pension-related Payment', 'active', 8),
  (3, 'Other Financial Benefit', 'active', 9);

-- ------------------------------------------------------------
-- Category 4: Promotion / Career
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_services`
  (`category_id`, `name`, `status`, `sort_order`) VALUES
  (4, 'Promotion',              'active', 1),
  (4, 'Promotion Seniority',    'active', 2),
  (4, 'Promotion Eligibility',  'active', 3),
  (4, 'Departmental Examination','active',4),
  (4, 'Career Progression',     'active', 5),
  (4, 'Other Career Matter',    'active', 6);

-- ------------------------------------------------------------
-- Category 5: Legal / Disciplinary
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_services`
  (`category_id`, `name`, `status`, `sort_order`) VALUES
  (5, 'Show-cause Notice',       'active', 1),
  (5, 'Charge Sheet',            'active', 2),
  (5, 'Disciplinary Proceedings','active', 3),
  (5, 'Suspension',              'active', 4),
  (5, 'Court Matter',            'active', 5),
  (5, 'Legal Assistance',        'active', 6),
  (5, 'Departmental Enquiry',    'active', 7),
  (5, 'Other Legal Matter',      'active', 8);

-- ------------------------------------------------------------
-- Government authority hierarchy (spec §4)
-- Explicit IDs to preserve hierarchy integrity.
-- ------------------------------------------------------------
INSERT IGNORE INTO `grievance_authorities`
  (`id`, `parent_id`, `authority_type`, `name`, `code`, `status`) VALUES
  (1, NULL, 'government',          'Government',          'GOV',  'active'),
  (2,    1, 'department',          'RDPR Department',     'RDPR', 'active'),
  (3,    2, 'commissionerate',     'Commissionerate',     'COMM', 'active'),
  (4,    3, 'zilla_panchayati',    'Zilla Panchayati',   'ZP',   'active'),
  (5,    4, 'taluk_panchayati',    'Taluk Panchayati',   'TP',   'active'),
  (6,    5, 'gram_panchayati',     'Gram Panchayati',    'GP',   'active'),
  (7,    1, 'district_administration','District Administration','DA','active'),
  (8,    1, 'other_department',    'Other Department',    'OD',   'active'),
  (9,    1, 'other_authority',     'Other Authority',     'OA',   'active');
