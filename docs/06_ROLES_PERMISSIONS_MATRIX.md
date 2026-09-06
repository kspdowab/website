# KSPDOWA ROLES & PERMISSIONS
## v1.0

## 1. Roles
State:
- State Super Admin
- State President
- State General Secretary
- State Grievance Officer
- State Committee Member

District:
- District President
- District Secretary
- District Grievance Officer

Taluk:
- Taluk President
- Taluk Secretary
- Taluk Grievance Officer

Member:
- Regular Member

## 2. Scope
- Member: own records
- Taluk officer: own Taluk
- District officer: own District
- State officer: authorized statewide scope
- Super Admin: full system scope

## 3. Permission actions
View
Create
Edit
Approve
Forward
Escalate
Assign
Upload
Publish
Export
Manage

## 4. Key rules
A member can view only their own private records and grievances.
A Taluk officer cannot access another Taluk.
A District officer cannot access another District.
State access is permission-controlled.
Financial administration is separate from member payment history.
Private documents require authorization.
Grievance events are immutable.

## 5. Implementation
Use RBAC tables:
roles
permissions
role_permissions
user_roles

Do not use a single `is_admin` flag as the authorization system.
