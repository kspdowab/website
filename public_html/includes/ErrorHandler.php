<?php
/**
 * KSPDOWA — Centralized Error Handler
 * ============================================================
 * Registers PHP error, exception and shutdown handlers.
 * In development: renders details to screen.
 * In production: logs silently and shows generic message.
 * ============================================================
 */

declare(strict_types=1);

class ErrorHandler
{
    private static bool $registered = false;

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    /**
     * PHP error handler (E_WARNING, E_NOTICE, etc.)
     */
    public static function handleError(
        int    $errno,
        string $errstr,
        string $errfile = '',
        int    $errline = 0
    ): bool {
        // Respect the @ operator (suppressed errors)
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $level   = self::errorLevelName($errno);
        $message = sprintf(
            '[%s][%s] %s in %s on line %d',
            date('Y-m-d H:i:s'),
            $level,
            $errstr,
            $errfile,
            $errline
        );

        error_log('[KSPDOWA] ' . $message);

        if (defined('APP_ENV') && APP_ENV !== 'production') {
            echo '<pre style="background:#1e1e1e;color:#f8f8f2;padding:12px;border-left:4px solid #e74c3c;">'
               . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
               . '</pre>';
        }

        // Do not execute PHP internal error handler for non-fatal errors
        return true;
    }

    /**
     * Uncaught exception handler
     */
    public static function handleException(Throwable $e): void
    {
        $message = sprintf(
            '[%s][Exception] %s: %s in %s on line %d',
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        error_log('[KSPDOWA] ' . $message);
        error_log('[KSPDOWA] Stack trace: ' . $e->getTraceAsString());

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (defined('APP_ENV') && APP_ENV !== 'production') {
            echo '<pre style="background:#1e1e1e;color:#f8f8f2;padding:12px;border-left:4px solid #e74c3c;">'
               . htmlspecialchars($message . "\n\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
               . '</pre>';
        } else {
            self::renderProductionError();
        }
    }

    /**
     * Shutdown handler — catches fatal errors (E_ERROR, E_PARSE, etc.)
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (in_array($error['type'], $fatalTypes, true)) {
            self::handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }

    // ------------------------------------------------------------------
    // Utility methods
    // ------------------------------------------------------------------

    /**
     * Abort with an HTTP status code and short message.
     * Terminates execution immediately.
     */
    public static function abort(int $code, string $message = ''): never
    {
        if (!headers_sent()) {
            http_response_code($code);
        }

        $defaults = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Access Denied',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];

        $text = $message ?: ($defaults[$code] ?? 'Error ' . $code);

        // Minimal HTML output — styled pages added in Phase 1
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<title>' . $code . ' — ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</title></head>'
           . '<body><h1>' . $code . '</h1><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></body></html>';

        exit;
    }

    /**
     * Return a JSON error response (for API endpoints) and terminate.
     */
    public static function jsonError(string $message, int $code = 500): never
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode(
            ['success' => false, 'error' => $message],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        exit;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function renderProductionError(): void
    {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<title>Error — KSPDOWA</title></head>'
           . '<body><p>An unexpected error occurred. Please try again later '
           . 'or contact the administrator.</p></body></html>';
    }

    private static function errorLevelName(int $errno): string
    {
        $levels = [
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        ];

        return $levels[$errno] ?? 'E_UNKNOWN(' . $errno . ')';
    }
}
