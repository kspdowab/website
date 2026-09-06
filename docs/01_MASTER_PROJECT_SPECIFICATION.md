# KSPDOWA DIGITAL ASSOCIATION PLATFORM
## Master Project Specification v1.0

**Association:** KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICER WELFARE ASSOCIATION (R), BENGALURU

## 1. Objective
Develop a professional, secure, mobile-first association platform consisting of:
1. Public official website
2. Secure member portal
3. Association officer/admin portal

The platform must be suitable for Hostinger hosting and designed for long-term expansion.

## 2. Technology
- HTML5
- CSS3
- JavaScript
- PHP 8.2+ or latest stable Hostinger-supported version
- MySQL 8 / MariaDB
- Apache/LiteSpeed
- HTTPS/SSL

Do not introduce Node.js, PostgreSQL, or unnecessary server infrastructure without explicit approval.

## 3. Access Levels
### Public
Home, About, Recognition, Office Bearers, public News, Contact, Member Login.

### Member
Dashboard, Profile, Membership, Digital ID, Membership Fee, Payments/Receipts, Orders & Circulars, Activities, Finance, Documents, Grievances, Notifications.

### Officer/Admin
Role-based access to administration, workflow, content, finance, members, grievances, reports and settings.

## 4. Member-only sections
The following must not be publicly accessible:
- Orders & Circulars
- Activities
- Finance
- Documents
- Member Grievance & Service Tracking

Hiding menu items is insufficient. Server-side authorization is mandatory.

## 5. Core modules
- Public website
- Member registration/login
- Member profiles
- Membership management
- Annual membership fees
- Payments and receipts
- Donations
- Office bearer directory
- News
- Orders and circulars
- Activities
- Document management
- Events and meetings
- Notifications
- Member grievance and service tracking
- Role/permission management
- Reports and analytics
- Audit logs
- Recognition and official documents

## 6. Grievance architecture
LOCKED PRINCIPLE:

**One Master Grievance + Hierarchical Access + Controlled Forwarding + Immutable Timeline + Authority Tracking**

A grievance must retain one unique ID throughout its lifecycle. Forwarding must not create duplicate master grievances.

Association hierarchy:
Member -> Taluk -> District -> State

Government authority is tracked separately:
Gram Panchayati -> Taluk Panchayati -> Zilla Panchayati -> Commissionerate -> Government, as applicable.

Every status change, forwarding, escalation, assignment, remark and document action creates a timestamped event.

## 7. Security
Mandatory:
- Secure password hashing
- Secure sessions
- CSRF protection
- Prepared SQL/PDO
- Input validation
- Output escaping
- XSS protection
- File upload validation
- Rate limiting
- Secure password reset
- RBAC
- Audit logs
- Backups
- Server-side payment verification
- Protected member-only files

## 8. Development phases
1. Public website
2. Authentication and member profiles
3. Membership and payments
4. Member-only content
5. Grievance workflow
6. Administration and reporting
7. Advanced notifications/integrations

## 9. Development rule
Do not silently change approved architecture or business rules. Before implementation, inspect the relevant project specifications and existing dependencies. Do not invent missing association rules.
