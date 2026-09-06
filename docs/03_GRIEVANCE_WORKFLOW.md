# KSPDOWA MEMBER GRIEVANCE & SERVICE TRACKING
## Workflow Specification v1.0

## 1. Locked architecture

**One Master Grievance + Hierarchical Access + Controlled Forwarding + Immutable Timeline + Authority Tracking**

Never create a second master grievance when a case is forwarded.

## 2. Member workflow
Member:
1. Login
2. Select Member Grievance & Service Tracking
3. Select Service Category
4. Select Service Type
5. Enter subject and description
6. Select relevant authority
7. Upload supporting documents
8. Submit

System generates:
`KSPDOWA-GRV-YYYY-NNNNN`

## 3. Service categories

### Service & Establishment
Appointment, Probation, Confirmation, Seniority, Promotion, Increment, Pay Fixation, Salary, Arrears, Transfer, Posting, Deputation, Relieving, Joining, Leave, Medical Leave, Earned Leave, Retirement, Voluntary Retirement, Pension, Gratuity, Service Register, Employee Records, Disciplinary Matters, Other Service Matters.

### Panchayati Raj / Department Matters
Gram Panchayati administration, Panchayati Development Officer duties, Administrative powers, Financial powers, Panchayati meetings, Panchayati resolutions, Panchayati records, Panchayati staff matters, Panchayati funds, e-Swathu, Panchatantra, Tax & Fees, Audit, Inspection, Other departmental matters.

### Salary & Financial Benefits
Salary delay, Salary discrepancy, Pay revision, Allowances, TA/DA, Medical reimbursement, Other reimbursement, Pension-related payment, Other financial benefit.

### Promotion / Career
Promotion, Promotion seniority, Promotion eligibility, Departmental examination, Career progression, Other career matter.

### Legal / Disciplinary
Show-cause notice, Charge sheet, Disciplinary proceedings, Suspension, Court matter, Legal assistance, Departmental enquiry, Other legal matter.

These lists must be configurable by authorized administrators.

## 4. Government authority hierarchy
Government
RDPR Department
Commissionerate
Zilla Panchayati
Taluk Panchayati
Gram Panchayati
District Administration
Other Department
Other Authority

Authority level and association level are separate fields.

## 5. Association hierarchy
Member -> Taluk -> District -> State

## 6. Status values
Submitted
Under Verification
Accepted
Under Review
Forwarded
Pending
Clarification Required
Action Taken
Resolved
Rejected
Closed
Reopened

## 7. Current responsibility
A grievance has one current responsible association level/assignee.

Other authorized levels may have read visibility without becoming the current owner.

## 8. Forwarding
Allowed examples:
Taluk -> District
District -> State
State -> Government authority

Forwarding:
- preserves grievance ID
- creates a grievance event
- releases previous assignment
- creates new assignment
- updates current level
- notifies relevant officers
- notifies member

## 9. Escalation
Escalation is an authorized action, not an automatic assumption. It creates an event and changes current responsibility.

## 10. Immutable timeline
Never edit or delete historical grievance events.

Each event records:
- date/time
- actor
- role
- association level
- event type
- old/new status
- old/new authority
- remarks
- related document if applicable

## 11. Member visibility
Member can view:
- grievance number
- subject
- current status
- current association level
- current government authority
- pending since
- timeline
- permitted documents
- association remarks intended for the member

Member cannot view private internal notes unless explicitly marked member-visible.

## 12. Officer visibility
Taluk officers: cases within their Taluk.
District officers: cases within their District.
State officers: authorized statewide cases.
All access must be permission-controlled.

## 13. Notifications
Notify:
- relevant responsible officer
- next level on forwarding/escalation
- member on meaningful status changes

## 14. Dashboards
Member: own total/submitted/pending/resolved.
Taluk: new/under review/pending/forwarded/resolved.
District: new/pending/awaiting Taluk/awaiting authority/escalated/resolved.
State: statewide totals, backlog, authority-wise and service-wise analytics.

## 15. Security
No public indexing of grievances. No direct unrestricted file URLs. All access must pass authentication and authorization.
