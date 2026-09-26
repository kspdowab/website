<?php
/**
 * KSPDOWA — Mail Abstraction
 * ============================================================
 * Single entry point for sending application email: Mailer::send().
 * Driver is selected by the MAIL_DRIVER constant (config/mail.php)
 * or system_settings ('mail_driver'):
 *
 *   'log'  (default, safe for local testing) -- appends the message
 *          to storage/mail.log instead of sending it.
 *
 *   'smtp' -- built-in SMTP client (EHLO/STARTTLS/AUTH LOGIN)
 *          with no external library dependency. Can be configured
 *          via config/mail.secret.php OR via Admin Integrations settings.
 *
 * Never throws: any failure is logged via error_log() and Mailer::send()
 * returns false, so a mail problem never breaks the calling page.
 * ============================================================
 */

declare(strict_types=1);

class Mailer
{
    /**
     * Resolve active mail configuration (constants override database settings).
     */
    public static function getConfig(): array
    {
        $driver = defined('MAIL_DRIVER') && MAIL_DRIVER !== ''
            ? MAIL_DRIVER
            : (class_exists('Settings') ? Settings::get('mail_driver', 'log') : 'log');

        $host = defined('SMTP_HOST') && SMTP_HOST !== ''
            ? (string) SMTP_HOST
            : (class_exists('Settings') ? Settings::get('smtp_host', '') : '');

        $port = defined('SMTP_PORT')
            ? (int) SMTP_PORT
            : (class_exists('Settings') ? (int) Settings::get('smtp_port', '587') : 587);

        $encryption = defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION !== ''
            ? strtolower((string) SMTP_ENCRYPTION)
            : (class_exists('Settings') ? strtolower(Settings::get('smtp_encryption', 'tls')) : 'tls');

        $user = defined('SMTP_USER')
            ? (string) SMTP_USER
            : (class_exists('Settings') ? Settings::get('smtp_user', '') : '');

        $pass = defined('SMTP_PASS')
            ? (string) SMTP_PASS
            : (class_exists('Settings') ? Settings::get('smtp_pass', '') : '');

        $fromAddress = defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== ''
            ? (string) MAIL_FROM_ADDRESS
            : (class_exists('Settings') ? Settings::get('mail_from_address', 'no-reply@kspdowa.org') : 'no-reply@kspdowa.org');

        $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== ''
            ? (string) MAIL_FROM_NAME
            : (class_exists('Settings') ? Settings::get('mail_from_name', defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'KSPDOWA BENGALURU') : 'KSPDOWA BENGALURU');

        return [
            'driver'            => $driver,
            'smtp_host'         => $host,
            'smtp_port'         => $port,
            'smtp_encryption'   => $encryption,
            'smtp_user'         => $user,
            'smtp_pass'         => $pass,
            'mail_from_address' => $fromAddress,
            'mail_from_name'    => $fromName,
        ];
    }

    /**
     * Send an email. Returns true on success (or on successful write
     * to the log driver), false on failure.
     */
    public static function send(string $to, string $subject, string $bodyText, ?string $bodyHtml = null): bool
    {
        $cfg = self::getConfig();

        try {
            return match ($cfg['driver']) {
                'smtp'  => self::sendSmtp($to, $subject, $bodyText, $bodyHtml, $cfg),
                default => self::sendLog($to, $subject, $bodyText),
            };
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Mailer] Failed to send mail to ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Diagnostic test mailer for Admin Integrations testing.
     * Returns true if email dispatched successfully, false otherwise,
     * and populates $diagnosticError with stage/protocol error details.
     */
    public static function sendTest(string $to, ?string &$diagnosticError = null): bool
    {
        $cfg = self::getConfig();
        $subject = 'KSPDOWA SMTP Diagnostic Test — ' . date('Y-m-d H:i:s');
        $bodyText = "This is an automated test email sent from the KSPDOWA Portal administration.\n"
                  . "If you are reading this, your SMTP configuration is operational!\n\n"
                  . "Timestamp: " . date('r') . "\n"
                  . "Driver: " . $cfg['driver'] . "\n"
                  . "Host: " . $cfg['smtp_host'] . ":" . $cfg['smtp_port'] . " (" . $cfg['smtp_encryption'] . ")\n";

        if ($cfg['driver'] === 'log') {
            $ok = self::sendLog($to, $subject, $bodyText);
            if ($ok) {
                $diagnosticError = 'Driver is currently set to "log". Message written to storage/mail.log.';
                return true;
            }
            $diagnosticError = 'Failed to write to storage/mail.log.';
            return false;
        }

        return self::sendSmtp($to, $subject, $bodyText, null, $cfg, $diagnosticError);
    }

    // ------------------------------------------------------------------
    // 'log' driver -- safe for local development, requires no credentials
    // ------------------------------------------------------------------

    private static function sendLog(string $to, string $subject, string $bodyText): bool
    {
        $logPath = defined('MAIL_LOG_PATH') ? MAIL_LOG_PATH : (PROJECT_ROOT . '/storage/mail.log');
        $dir     = dirname($logPath);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[KSPDOWA][Mailer] Could not create mail log directory: ' . $dir);
            return false;
        }

        $entry = sprintf(
            "==== MAIL (log driver -- not actually sent) ====\nTime: %s\nTo: %s\nSubject: %s\n----\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $bodyText
        );

        return @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    // ------------------------------------------------------------------
    // 'smtp' driver -- minimal built-in client, no external library
    // ------------------------------------------------------------------

    private static function sendSmtp(
        string $to,
        string $subject,
        string $bodyText,
        ?string $bodyHtml,
        array $cfg,
        ?string &$errorOut = null
    ): bool {
        $host       = $cfg['smtp_host'];
        $port       = (int) $cfg['smtp_port'];
        $encryption = strtolower($cfg['smtp_encryption']);
        $user       = $cfg['smtp_user'];
        $pass       = $cfg['smtp_pass'];
        $fromAddr   = $cfg['mail_from_address'];
        $fromName   = $cfg['mail_from_name'];

        if ($host === '' || $port <= 0 || $fromAddr === '') {
            $errorOut = 'SMTP Host, Port, or From Address is missing in configuration.';
            error_log('[KSPDOWA][Mailer] ' . $errorOut);
            return false;
        }

        $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $sock = @fsockopen($transport, $port, $errno, $errstr, 12);
        if (!$sock) {
            $errorOut = "SMTP connect to {$host}:{$port} failed: {$errstr} ({$errno})";
            error_log('[KSPDOWA][Mailer] ' . $errorOut);
            return false;
        }
        stream_set_timeout($sock, 12);

        $readResponse = static function () use ($sock, &$lastLine): int {
            $line = '';
            do {
                $chunk = fgets($sock, 515);
                if ($chunk === false) {
                    break;
                }
                $line = $chunk;
            } while (isset($chunk[3]) && $chunk[3] === '-');
            $lastLine = trim($line);
            return (int) substr($line, 0, 3);
        };

        $sendLine = static function (string $cmd) use ($sock): void {
            fwrite($sock, $cmd . "\r\n");
        };

        $fail = static function (string $stage, string $detail = '') use ($sock, &$errorOut): bool {
            $msg = "SMTP failed at {$stage}: {$detail}";
            $errorOut = $msg;
            error_log('[KSPDOWA][Mailer] ' . $msg);
            fclose($sock);
            return false;
        };

        $lastResp = '';
        if ($readResponse() >= 400) {
            return $fail('greeting', $lastResp);
        }

        $sendLine('EHLO localhost');
        if ($readResponse() >= 400) {
            return $fail('ehlo', $lastResp);
        }

        if ($encryption === 'tls') {
            $sendLine('STARTTLS');
            if ($readResponse() >= 400) {
                return $fail('starttls', $lastResp);
            }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail('tls_handshake', 'TLS handshake failed on socket.');
            }
            $sendLine('EHLO localhost');
            if ($readResponse() >= 400) {
                return $fail('ehlo_after_tls', $lastResp);
            }
        }

        if ($user !== '') {
            $sendLine('AUTH LOGIN');
            if ($readResponse() >= 400) {
                return $fail('auth_login', $lastResp);
            }
            $sendLine(base64_encode($user));
            if ($readResponse() >= 400) {
                return $fail('auth_user', $lastResp);
            }
            $sendLine(base64_encode($pass));
            if ($readResponse() >= 400) {
                return $fail('auth_pass', $lastResp);
            }
        }

        $sendLine('MAIL FROM:<' . $fromAddr . '>');
        if ($readResponse() >= 400) {
            return $fail('mail_from', $lastResp);
        }
        $sendLine('RCPT TO:<' . $to . '>');
        if ($readResponse() >= 400) {
            return $fail('rcpt_to', $lastResp);
        }
        $sendLine('DATA');
        if ($readResponse() >= 400) {
            return $fail('data', $lastResp);
        }

        $boundary = 'kspdowa_' . bin2hex(random_bytes(8));
        $headers  = [
            'From: ' . $fromName . ' <' . $fromAddr . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
        ];

        if ($bodyHtml !== null) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $bodyText . "\r\n";
            $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $bodyHtml . "\r\n";
            $body .= "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $body = $bodyText;
        }

        // SMTP DATA transparency: escape lines that begin with a bare period.
        $body = preg_replace('/^\./m', '..', $body);

        $sendLine(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
        $finalCode = $readResponse();
        $sendLine('QUIT');
        fclose($sock);

        if ($finalCode >= 400) {
            $errorOut = "SMTP server rejected data with code {$finalCode}: {$lastResp}";
            return false;
        }

        return true;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
