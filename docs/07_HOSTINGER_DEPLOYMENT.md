# KSPDOWA HOSTINGER DEPLOYMENT SPECIFICATION
## v1.0

## 1. Stack
PHP 8.2+ / Hostinger-supported stable version
MySQL 8 / MariaDB
HTML5
CSS3
JavaScript
Apache/LiteSpeed
HTTPS

## 2. Suggested structure

/public_html/
- index.php
- login.php
- logout.php
- assets/
- config/
- includes/
- public/
- member/
- officer/
- admin/
- api/
- uploads/

Sensitive configuration and protected files should be outside the public web root where the hosting environment permits.

## 3. Configuration
Never commit production database credentials into public source files.
Use environment/configuration protection appropriate to Hostinger.

## 4. File handling
Large files should not be stored as database blobs. Store metadata in MySQL and files in controlled storage.

Member-only files must be delivered through an authorization-checked PHP endpoint or protected storage.

## 5. Database deployment
- Create database
- Apply versioned migration scripts
- Create restricted DB user
- Import seed/master data
- Verify indexes and foreign keys

## 6. Cron
Use Hostinger cron jobs only where needed, for example:
- notification processing
- scheduled reports
- cleanup of temporary files
- backups/maintenance

## 7. SSL
Force HTTPS and redirect HTTP to HTTPS.

## 8. Backup
Maintain:
- regular database backup
- file backup
- documented restore process

## 9. Production checks
Before launch:
- authentication
- authorization
- direct URL protection
- file access
- payment verification
- CSRF
- uploads
- SQL injection
- XSS
- mobile UI
- error handling
- backup/restore

## 10. Performance
Use optimized SQL, indexes, pagination, compressed images, caching where appropriate, and avoid loading large datasets unnecessarily.
