# KSPDOWA MEMBERS MANAGEMENT, MEMBERSHIP LIFECYCLE & REPORTING SPECIFICATION

**Document Status:** Locked Working Specification  
**Project:** KSPDOWA Digital Association Platform  
**Technology:** PHP + MySQL/MariaDB, HTML5, CSS3, JavaScript  
**Development Mode:** Local development first. Deployment only after project completion and explicit approval.

---

## 1. PURPOSE

This document defines the locked requirements for the KSPDOWA Members Management, annual membership lifecycle, member transfer, retirement/termination, bulk import, Members List, and Abstract Reporting modules.

This document must be kept in the project repository and used as an implementation reference by Antigravity/Cowork.

Do not silently change these rules. Any change must be explicitly approved and documented.

---

# 2. ADMIN MEMBERS STRUCTURE

The Admin Members section shall contain:

```text
MEMBERS
├── + Add Member
├── Members List
├── Bulk Import
└── Abstract Reports
    ├── District-wise Abstract
    └── Taluk-wise Abstract
```

### Add Member

- Must be separate from Members List.
- Add Member form is collapsed/hidden by default.
- Clicking `+ Add Member` expands the form.
- Use the existing approved member registration rules and validation.
- Do not create a second or conflicting registration logic.

---

# 3. MEMBER DATA

Member records must retain the approved registration information, including:

### Personal Details

- Full Name
- Father / Husband Name
- Gender
- Phone
- Personal Email
- KGID No.
- Date of Birth
- Designation = PDO

### Working / Membership Details

Apply the approved working-status rules already defined in the project:

- Working District
- Working Taluk
- Gram Panchayati where applicable
- Organization Type where applicable
- Organization Name where applicable
- Office Address where applicable
- Membership District
- Membership Taluk

Do not allow members to manually override automatically derived Membership District/Taluk where the approved registration rules require automatic assignment.

---

# 4. KGID DUPLICATE RULE

**KGID No. is the unique permanent member identity.**

Duplicate membership detection is strictly:

```text
KGID + Financial Year
```

### Examples

```text
KGID 27253 + 2026-27 = Duplicate if already registered for 2026-27
KGID 27253 + 2027-28 = Allowed
```

### Important

Do NOT use the following as the membership duplicate key:

- Name
- Father/Husband Name
- Phone
- Email

The same KGID cannot have two membership records for the same financial year.

KGID remains unchanged throughout the member's association history.

---

# 5. MEMBERS LIST

The Members List must provide:

- Sl.No
- Membership No
- Name
- Gender
- Father/Husband Name
- KGID
- Phone
- Email
- Taluk
- District
- Financial Year
- Payment Status
- Payment Mode
- Razorpay Payment ID
- Receipt
- View

### View Details

The View option must show the complete member registration information and relevant membership/payment information.

Payment information should include, where applicable:

- Financial Year
- Payment Status
- Payment Mode
- Razorpay Order ID
- Razorpay Payment ID
- Amount
- Payment Date
- Receipt Number
- Receipt View/Download

Offline payments must not receive a fake Razorpay Payment ID.

---

# 6. MEMBERS LIST FILTERS

The Members List must support:

### Financial Year

Dynamic selection from configured membership years.

Do not hard-code the financial year.

### District

- All Districts for authorized statewide users
- Locked to the user's authorized District for District-level users

### Taluk

- Dependent on selected District
- District-level users see only taluks in their district
- Taluk-level users are locked to their own taluk

### Payment Status

```text
All
Paid
Unpaid
```

For the selected financial year:

**Paid** = verified/completed annual membership payment.

Paid includes:

- Verified Razorpay payment
- Verified Offline/Manual payment

**Unpaid** = no verified/completed annual membership payment for the selected financial year.

### Search

Search must support:

- Name
- Membership No.
- KGID
- Phone
- Email
- Razorpay Payment ID

All filters must work together.

---

# 7. RBAC AND GEOGRAPHIC SCOPE

Use the existing RBAC system and existing approved roles/permissions.

Never introduce an `is_admin` shortcut.

### State-authorized users

Can access statewide member data according to their approved permissions.

### District President / authorized District-level role

- District is automatically locked to their own District.
- Can see only members of their District.
- Taluk filter contains only taluks within that District.

### Taluk President / authorized Taluk-level role

- District is locked to their District.
- Taluk is locked to their own Taluk.
- Can see only members of their Taluk.

### Regular Member

No access to the Admin Members List.

### Security

Geographic scope must be enforced server-side.

Never trust:

- URL parameters
- hidden fields
- POST values
- client-side filters

for authorization.

Export endpoints must independently enforce the same RBAC scope.

---

# 8. FINANCIAL YEAR LIFECYCLE

The financial year changes automatically on **April 1**.

Do NOT change old payment records from Paid to Unpaid.

Example:

```text
2026-27 → PAID
```

After April 1:

```text
2026-27 → Historical PAID
2027-28 → UNPAID until payment is verified
```

The member does not register again.

### Member Login

For an active member:

```text
Login
→ Check current financial year
→ Current FY payment verified?
    YES → Member Dashboard
    NO  → Annual Membership Payment
```

If unpaid, redirect the existing member to payment.

Do not show a new registration form.

Existing member details must be retained.

After successful verified payment:

```text
Current FY Membership = PAID / ACTIVE
Member Dashboard Access = ENABLED
```

---

# 9. MEMBERSHIP NUMBER

Format:

```text
KSPDOWA-{DISTRICT_SHORT_CODE}-{4_DIGIT_DISTRICT_SERIAL}
```

Example:

```text
KSPDOWA-BGK-0001
KSPDOWA-VJP-0001
KSPDOWA-VJP-0002
```

Rules:

- District serial is independent for each District.
- Serial must never be reused.
- Membership Number must be globally unique.
- Generated server-side.
- District code comes from the District master table.
- Never accept the district code from user input.
- Use transaction/locking for serial generation.
- KGID is permanent and never changes.

---

# 10. TRANSFER MEMBERSHIP NUMBER RULE

This is a locked special rule.

When a member transfers to another District:

**The existing Membership Number does NOT change immediately.**

A new District Membership Number is generated only after:

1. Transfer is approved by Super Admin.
2. The member reaches the new financial year.
3. The member pays the annual membership fee in the new District.
4. Payment is successfully verified.
5. New financial-year membership is activated.

### Example

2026-27:

```text
District: BAGALKOTE
Membership No: KSPDOWA-BGK-0045
Paid
```

Member transfers to Vijayapura.

Before new FY payment:

```text
Existing historical Membership No:
KSPDOWA-BGK-0045
```

After 2027-28 payment in Vijayapura:

```text
New Membership No:
KSPDOWA-VJP-XXXX
```

The old Membership Number must remain preserved in historical records.

Do not consume a new District serial before the new financial-year payment.

---

# 11. MEMBER TRANSFER REQUEST

A member may request a working-location transfer/change from the Member Dashboard.

The request should include:

- Current District
- Current Taluk
- New Working District
- New Working Taluk
- Gram Panchayati where applicable
- Reason / Remarks

Show current and requested location before submission.

### Approval

**Only Super Admin can approve or reject the transfer**, according to the Association Bylaw.

On approval:

- Preserve previous location
- Record new location
- Record requester
- Record approver
- Record request date
- Record approval date
- Record reason/remarks
- Create immutable audit/event record

Do not silently overwrite historical location.

After approval, current District/Taluk scope for authorized officers must reflect the new approved location.

---

# 12. RETIREMENT AND TERMINATION

Super Admin must have member lifecycle actions:

- Retirement
- Termination

### Rules

- Do not delete the member.
- Preserve complete history.
- Record effective date.
- Record reason/category.
- Record detailed remarks.
- Record supporting document where applicable.
- Record approving Super Admin.
- Record timestamp.
- Create audit record.

Termination/retirement reasons must not be invented.

Use configurable reasons or approved Association Bylaw provisions.

### Lifecycle Status

Keep lifecycle status separate from annual payment status:

```text
ACTIVE
RETIRED
TERMINATED
```

A retired or terminated member:

- Cannot access Member Dashboard.
- Must not be treated as an ordinary unpaid member.
- Must not automatically be redirected to annual renewal payment.
- Must not automatically become eligible for new annual membership.

---

# 13. BULK MEMBER IMPORT

Authorized Admin/Super Admin must have a Bulk Import function.

Support:

- CSV
- Excel
- Downloadable sample/template

### Import workflow

```text
Upload
→ Validate
→ Preview
→ Show results
→ Admin confirmation
→ Import
```

Validation result categories:

- Valid
- Invalid
- Duplicate
- Already Paid
- Existing Member where applicable

Do not activate members merely during file validation.

### Duplicate Rule

Only:

```text
KGID + Financial Year
```

determines a duplicate membership.

Do not use name/phone/email as the membership duplicate key.

### Offline/Manual Paid Import

Bulk import must support offline/manual paid membership where authorized.

Record:

- Financial Year
- Payment Status
- Payment Mode = OFFLINE/MANUAL
- Amount
- Payment Date
- Offline Receipt/Reference Number
- Remarks
- Supporting proof where applicable

Never create a fake Razorpay Payment ID.

After authorized verification/approval:

```text
Current FY Membership = PAID / ACTIVE
Member Dashboard Access = ENABLED
```

---

# 14. ONLINE AND OFFLINE PAYMENT LOGIC

Paid membership includes both:

```text
Verified Razorpay payment
OR
Verified Offline/Manual payment
```

Payment method must not change the definition of Paid.

A member can have one membership/payment record per financial year.

Historical payment records remain preserved.

---

# 15. DETAILED MEMBERS REPORT EXPORT

Export the currently filtered Members List to:

- PDF
- Excel

The export must use exactly the selected filters and authorized RBAC scope.

### Final export columns

Exactly:

```text
Sl.No
Name
Father Name
Gender
Phone
Email
KGID
Taluk
District
Date
```

**Gender is the only additional column compared with the existing reference report.**

Do NOT add:

- Membership No.
- Payment ID
- Receipt
- Payment Mode

to this detailed report.

Those remain available in the Admin Members List and Member Details.

Reference report:

`KSPDOWA_BAGALKOTE_10092026_192812.pdf`

Use it as the visual/layout reference.

---

# 16. MEMBERS LIST PDF

Page:

**Legal Landscape**

The detailed report must maintain the professional layout of the reference report.

Requirements:

- Single-line title
- Professional table
- Borders
- Appropriate column widths
- Wrapped text where necessary
- No overflow
- Repeated table header on every page
- Page numbers
- Footer:

```text
Designed & Developed by : KHUBAASING JADAV
```

---

# 17. MEMBERS LIST TITLES

Titles must ALWAYS remain on a single line.

### District

```text
KSPDOWA BENGALURU - {DISTRICT NAME} DISTRICT {FINANCIAL YEAR} MEMBERS LIST AS ON : {CURRENT DATE} {CURRENT TIME AM/PM}
```

### Taluk

```text
KSPDOWA BENGALURU - {TALUK NAME} - ({DISTRICT NAME}) TALUKA {FINANCIAL YEAR} MEMBERS LIST AS ON : {CURRENT DATE} {CURRENT TIME AM/PM}
```

### All Districts

```text
KSPDOWA BENGALURU - ALL DISTRICTS {FINANCIAL YEAR} MEMBERS LIST AS ON : {CURRENT DATE} {CURRENT TIME AM/PM}
```

If Paid/Unpaid filtering is selected, show the selected status separately as a report indicator without changing/breaking the required title format.

---

# 18. DISTRICT-WISE ABSTRACT

Create a separate District-wise Abstract Report.

Columns:

```text
Sl.No
District
Total Members
Paid Members
Unpaid Members
```

### Default sorting

```text
Sort By: Paid Members
Order: Descending
```

Highest Paid Members must appear first.

### Sort options

Numeric columns:

- Total Members
- Paid Members
- Unpaid Members

Each must support:

- Ascending
- Descending

After sorting, regenerate Sl.No as 1,2,3,...

---

# 19. TALUK-WISE ABSTRACT

Create a separate Taluk-wise Abstract Report.

Columns:

```text
Sl.No
District
Taluk
Total Members
Paid Members
Unpaid Members
```

Default:

```text
Sort By: Paid Members
Order: Descending
```

Sorting options:

- Total Members
- Paid Members
- Unpaid Members

Each:

- Ascending
- Descending

Regenerate Sl.No after sorting.

---

# 20. ABSTRACT FILTERS

### District Abstract

Support:

- Financial Year
- Payment Status where applicable

Use authorized geographic scope.

### Taluk Abstract

Support:

- Financial Year
- District
- Taluk
- Payment Status where applicable

District → Taluk must remain dependent.

Use the same Paid/Unpaid payment logic as Members List.

---

# 21. ABSTRACT PAGE FORMAT

Both Abstract Reports must use:

**A4 Portrait**

Requirements:

- Single-line title
- Professional compact table
- Borders
- Repeated header on multiple pages
- Page numbers
- Footer:

```text
Designed & Developed by : KHUBAASING JADAV
```

---

# 22. ABSTRACT TITLES

Titles must ALWAYS remain on a single line.

### District-wise

```text
KSPDOWA BENGALURU - DISTRICT WISE ABSTRACT {FINANCIAL YEAR} AS ON : {CURRENT DATE} {CURRENT TIME AM/PM}
```

### Taluk-wise

```text
KSPDOWA BENGALURU - TALUK WISE ABSTRACT {FINANCIAL YEAR} AS ON : {CURRENT DATE} {CURRENT TIME AM/PM}
```

For scoped District/Taluk users, reflect only their authorized scope.

---

# 23. ABSTRACT PDF AND EXCEL

Both Abstract Reports must support:

- PDF
- Excel

PDF:

- A4 Portrait
- Single-line title
- Selected sorting
- Selected filters
- Authorized data only

Excel:

- Title
- Report date/time
- Headers
- Borders
- Suitable column widths
- Wrapped text
- Freeze header
- Auto filter
- Print setup

Selected:

- Financial Year
- District
- Taluk
- Payment status
- Sort column
- Sort direction

must be preserved in the export.

---

# 24. ABSTRACT DATA CONSISTENCY

Use the same underlying business logic/query rules for:

- Members List
- Members PDF
- Members Excel
- District Abstract
- Taluk Abstract

The abstract totals must reconcile with the detailed Members List for the same:

- Financial Year
- Geographic scope
- Payment status

Do not create separate conflicting counting logic.

---

# 25. PAYMENT STATUS VS LIFECYCLE STATUS

These are separate concepts.

Example:

```text
Member A
Lifecycle: ACTIVE
2026-27: PAID

Member B
Lifecycle: ACTIVE
2026-27: UNPAID

Member C
Lifecycle: RETIRED
Historical FY: PAID

Member D
Lifecycle: TERMINATED
Historical FY: PAID
```

Do not use annual payment status as a replacement for lifecycle status.

---

# 26. SECURITY

Use existing project security architecture:

- RBAC
- PDO/prepared statements
- CSRF
- Secure sessions
- Validation
- Sanitization
- Audit logging
- Secure file handling

Personal-data exports must require authorization.

No public unrestricted export endpoints.

No path traversal.

No direct exposure of private payment/supporting documents.

No secrets in source control.

---

# 27. DATABASE RULES

Do not modify old migrations.

If a database change is genuinely required:

- Create a new numbered migration.
- Do not execute it automatically.
- Document the migration in the final status.

Do not destroy existing historical membership/payment data.

Do not reset historical payment records at the start of a new financial year.

---

# 28. DEVELOPMENT RULES

Development is LOCAL ONLY until the project is fully completed.

Do NOT:

- Deploy to Hostinger.
- Push to GitHub.
- Import/push local database to Hostinger.
- Modify production database.
- Modify the existing WordPress database.
- Automatically run migrations.
- Automatically run tests.
- Automatically run syntax checks.
- Automatically run smoke tests.
- Automatically run browser tests.
- Automatically run full test suites.

Testing will be explicitly requested by the project owner.

Do not repeat completed work unless an actual dependency or failure requires it.

Do not repeatedly ask for confirmation when the requirements are already defined.

---

# 29. IMPLEMENTATION PRINCIPLE

Implement one coherent Members module using the existing KSPDOWA architecture.

Do not create duplicate authentication, membership, payment, RBAC, geography, or receipt logic.

Reuse existing project classes/services/helpers wherever appropriate.

The final implementation must preserve:

```text
Permanent Member Identity
        +
Financial-Year Membership
        +
Financial-Year Payment
        +
Current Lifecycle Status
        +
Current Approved Location
        +
Historical Location
        +
Transfer History
        +
Payment History
        +
Audit History
```

---

# 30. FINAL REQUIREMENT SUMMARY

The Members module must provide:

- Separate/collapsible Add Member
- Members List
- Financial Year filter
- District filter
- Taluk filter
- Paid/Unpaid filter
- Search
- Payment ID
- Receipt view
- Member details
- CSV/Excel bulk import
- Offline/manual paid membership
- Current FY dashboard activation
- Automatic annual renewal from April 1
- No re-registration for renewal
- KGID + Financial Year duplicate rule
- District/Taluk RBAC
- Member transfer request
- Super Admin-only transfer approval
- Transfer history
- Financial-year-specific new Membership Number after approved transfer + new FY payment
- Retirement
- Termination
- Super Admin lifecycle control
- District-wise Abstract
- Taluk-wise Abstract
- Abstract numeric sorting
- Default Paid descending
- Legal Landscape Members List PDF
- A4 Portrait Abstract PDF
- Excel exports
- Single-line report titles
- Current date/time
- Financial year
- Reference-report-style detailed Members List with Gender as the only added column

---

## CHANGE CONTROL

This document represents the locked requirements as approved.

Any future change to these rules must be explicitly approved before implementation.

**Document Owner:** KSPDOWA Project  
**Implementation Reference:** Antigravity / Claude Cowork  
