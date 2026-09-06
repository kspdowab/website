# KSPDOWA DATABASE SCHEMA
## MySQL / MariaDB Foundation v1.0

## 1. Principles
- Relational database design
- InnoDB
- UTF-8/utf8mb4
- Foreign keys where appropriate
- Indexed lookup fields
- No plain-text passwords
- Soft-delete/archive where historical records must be retained
- Audit-sensitive records must not be physically overwritten

## 2. Core tables

### users
- id BIGINT UNSIGNED PK
- member_id BIGINT UNSIGNED NULL
- username VARCHAR(100) UNIQUE NULL
- email VARCHAR(190) UNIQUE NULL
- mobile VARCHAR(20) UNIQUE NULL
- password_hash VARCHAR(255)
- status ENUM('active','inactive','locked','pending')
- last_login_at DATETIME NULL
- created_at DATETIME
- updated_at DATETIME

### members
- id BIGINT UNSIGNED PK
- member_no VARCHAR(50) UNIQUE
- name VARCHAR(200)
- designation VARCHAR(150)
- gp_id BIGINT UNSIGNED NULL
- taluk_id BIGINT UNSIGNED NULL
- district_id BIGINT UNSIGNED NULL
- membership_type_id BIGINT UNSIGNED NULL
- joining_date DATE NULL
- membership_status VARCHAR(50)
- photo_path VARCHAR(500) NULL
- created_at DATETIME
- updated_at DATETIME

### member_profiles
Store additional approved service/contact information linked 1:1 to members.

## 3. Geography
### districts
id, name, code, status, created_at, updated_at

### taluks
id, district_id, name, code, status, created_at, updated_at

### gram_panchayatis
id, taluk_id, name, code, status, created_at, updated_at

## 4. Association hierarchy
### association_units
id, unit_type, district_id, taluk_id, name, status

### roles
id, name, scope_type, status

### permissions
id, module, action, name, status

### role_permissions
role_id, permission_id

### user_roles
user_id, role_id, association_unit_id NULL

## 5. Office bearers
office_bearers:
id, member_id NULL, name, association_designation, official_designation, district_id NULL, taluk_id NULL, photo_path NULL, term_start NULL, term_end NULL, status

## 6. Membership and finance
### membership_types
id, name, description, fee_amount, status

### membership_years
id, financial_year, start_date, end_date, fee_amount, status

### membership_payments
id, member_id, membership_year_id, amount, gateway_order_id, gateway_payment_id, status, paid_at, created_at

### payment_receipts
id, payment_id, receipt_no, file_path, generated_at

### donations
id, member_id NULL, donor_name, purpose, amount, gateway_payment_id, status, paid_at, receipt_no

## 7. Content
### news
id, title, slug, content, language, category_id, featured_image, status, published_at, created_by, updated_by

### news_categories
id, name, status

### documents
id, title, category_id, description, file_path, access_level, published_at, uploaded_by, status

### document_categories
id, name, access_level, status

### orders
id, title, order_no, order_date, department, description, document_id, access_level

### circulars
id, title, circular_no, circular_date, department, description, document_id, access_level

### activities
id, title, description, activity_date, location, access_level, created_by, status

### events
id, title, description, event_date, location, access_level, created_by, status

### meetings
id, title, meeting_date, location, agenda, access_level, created_by

### meeting_minutes
id, meeting_id, content, document_id, approved_by, approved_at

### resolutions
id, meeting_id, resolution_no, title, content, status

## 8. Grievance tables
### grievances
id, grievance_no UNIQUE, member_id, category_id, service_id, subject, description, current_association_level, current_assignee, current_authority, current_status, submitted_at, last_updated_at, closed_at

### grievance_categories
id, name, description, status, sort_order

### grievance_services
id, category_id, name, description, status, sort_order

### grievance_authorities
id, parent_id NULL, authority_type, name, code, status

### grievance_assignments
id, grievance_id, assigned_to, association_level, assigned_at, released_at, is_current

### grievance_events
id, grievance_id, performed_by, association_level, event_type, old_status, new_status, old_authority, new_authority, remarks, created_at

### grievance_documents
id, grievance_id, event_id NULL, uploaded_by, document_type, file_path, original_filename, uploaded_at

## 9. Notifications
notifications:
id, user_id, type, title, message, related_module, related_id, read_at NULL, created_at

## 10. Governance documents
constitution_versions:
id, version, effective_date, title, document_id, status

annual_reports:
id, financial_year, title, document_id, status

## 11. Audit
audit_logs:
id, user_id NULL, action, module, record_id NULL, old_data JSON NULL, new_data JSON NULL, ip_address, user_agent, created_at

## 12. Settings
system_settings:
id, setting_key UNIQUE, setting_value, setting_type, updated_by, updated_at

## 13. Important indexes
Index:
- users.email
- users.mobile
- members.member_no
- members.district_id
- members.taluk_id
- members.gp_id
- membership_payments.member_id
- membership_payments.membership_year_id
- grievances.grievance_no
- grievances.member_id
- grievances.current_status
- grievances.current_association_level
- grievances.current_authority
- grievance_events.grievance_id
- notifications.user_id/read_at

Use migrations/versioned SQL scripts for all schema changes.
