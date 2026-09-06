<?php
/**
 * KSPDOWA — PDO Database Layer (Singleton)
 * ============================================================
 * Provides a single shared PDO connection for the application.
 * All queries use prepared statements (PDO::ATTR_EMULATE_PREPARES=false)
 * to guard against SQL injection.
 *
 * Usage:
 *   $db  = Database::getInstance();       // raw PDO
 *   $row = Database::fetchOne($sql, $p);  // single row
 *   $all = Database::fetchAll($sql, $p);  // all rows
 *   $n   = Database::execute($sql, $p);   // affected rows
 * ============================================================
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    // Prevent instantiation / cloning
    private function __construct() {}
    private function __clone() {}

    // ------------------------------------------------------------------
    // Connection management
    // ------------------------------------------------------------------

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::connect();
        }

        return self::$instance;
    }

    private static function connect(): void
    {
        // These constants are defined by config/db.secret.php via config/db.php
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // true prepared statements
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
                                          . "time_zone = '+05:30'",
        ];

        try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log full detail; expose nothing to the browser
            error_log('[KSPDOWA][DB] Connection failed: ' . $e->getMessage());

            throw new RuntimeException(
                'Database connection could not be established. '
                . 'Please contact the administrator.'
            );
        }
    }

    /**
     * Close the connection (useful in long-running CLI scripts).
     */
    public static function close(): void
    {
        self::$instance = null;
    }

    // ------------------------------------------------------------------
    // Query helpers
    // ------------------------------------------------------------------

    /**
     * Prepare and execute a statement, return the PDOStatement.
     *
     * @param string  $sql    SQL with named (:name) or positional (?) placeholders
     * @param array   $params Bound values
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row. Returns false when no row matches.
     */
    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::query($sql, $params)->fetch();
    }

    /**
     * Fetch all matching rows.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single scalar value (first column of first row).
     */
    public static function fetchScalar(string $sql, array $params = []): mixed
    {
        $row = self::fetchOne($sql, $params);
        return $row ? reset($row) : null;
    }

    /**
     * Execute a non-SELECT statement and return the affected row count.
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Return the last auto-increment ID inserted.
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Transaction helpers
    // ------------------------------------------------------------------

    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    public static function rollBack(): void
    {
        if (self::getInstance()->inTransaction()) {
            self::getInstance()->rollBack();
        }
    }

    /**
     * Run a callable inside a transaction.
     * Commits on success, rolls back on any Throwable.
     *
     * @param callable $callback Receives no arguments; must do its own DB calls.
     * @return mixed Return value of $callback.
     */
    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();
        try {
            $result = $callback();
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }
}
