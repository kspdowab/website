<?php
/**
 * KSPDOWA Database Secret Configuration — EXAMPLE / TEMPLATE
 * ============================================================
 * Copy this file to config/db.secret.php and fill in your credentials.
 *
 *   cp config/db.secret.example.php config/db.secret.php
 *
 * db.secret.php is listed in .gitignore and must NEVER be committed
 * to any version control system or shared publicly.
 *
 * Hostinger note:
 *   - DB_HOST is typically 'localhost' on shared hosting.
 *   - DB_NAME, DB_USER, DB_PASS are found in Hostinger hPanel
 *     under Hosting → Manage → MySQL Databases.
 *   - Create a dedicated restricted MySQL user for this application;
 *     do not use the master hosting account credentials.
 * ============================================================
 */

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'your_database_name');
define('DB_USER',    'your_database_user');
define('DB_PASS',    'your_database_password');
define('DB_CHARSET', 'utf8mb4');
