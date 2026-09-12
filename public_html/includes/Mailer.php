<?php
/**
 * KSPDOWA — Mail Abstraction
 * ============================================================
 * Single entry point for sending application email: Mailer::send().
 * Driver is selected by the MAIL_DRIVER constant (config/mail.php):
 *
 *   'log'  (default, no credentials required) -- appends the message
 *          to storage/mail.log instead of sending it. This is the
 *          local-development / testing driver: it lets the whole
 *          "temporary password emailed to the member" flow be
 *          developed and tested end-to-end without any SMTP account.
 *
 *   'smtp' -- a minimal built-in SMTP client (EHLO/STARTTLS/AUTH LOGIN)
 *          with no external library dependency. Activate it by copying
 *          config/mail.secret.example.php to config/mail.secret.php,
 *          filling in real SMTP_* credentials, and setting
 *          MAIL_DRIVER to 'smtp' there. Not exercised by the default
 *          configuration and has not been tested against a live
 *          mail server (no real credentials exist in this project
 *          yet) -- treat it as a best-effort implementation to
 *          verify against a real account before relying on it.
 *
 * Never throws: any failure is logged via error_log() and Mailer::send()
 * returns false, so a mail problem never breaks the calling page.
 * ============================================================
 */

declare(strict_types=1);

class Mailer
{
    /**
     * Send an email. Returns true on success (or on successful write
     * to the log driver), false on failure.
     */
    public static function send(string $to, string $subject, string $bodyText, ?string $bodyHtml = null): bool
    {
        $driver = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'log';

        try {
            return match ($driver) {
                'smtp'  => self::sendSmtp($to, $subject, $bodyText, $bodyHtml),
                default => self::sendLog($to, $subject, $bodyText),
            };
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Mailer] Failed to send mail to ' . $to . ': ' . $e->getMessage());
            return false;
        }
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

    private static function sendSmtp(string $to, string $subject, string $bodyText, ?string $bodyHtml): bool
    {
        $required = ['SMTP_HOST', 'SMTP_PORT', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'];
        foreach ($required as $const) {
            if (!defined($const)) {
                error_log("[KSPDOWA][Mailer] SMTP driver selected but {$const} is not configured.");
                return false;
            }
        }

        $host       = (string) SMTP_HOST;
        $port       = (int) SMTP_PORT;
        $user       = defined('SMTP_USER') ? (string) SMTP_USER : '';
        $pass       = defined('SMTP_PASS') ? (string) SMTP_PASS : '';
        $encryption = defined('SMTP_ENCRYPTION') ? strtolower((string) SMTP_ENCRYPTION) : 'tls';
        $fromAddr   = (string) MAIL_FROM_ADDRESS;
        $fromName   = (string) MAIL_FROM_NAME;

        $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $sock = @fsockopen($transport, $port, $errno, $errstr, 15);
        if (!$sock) {
            error_log("[KSPDOWA][Mailer] SMTP connect to {$host}:{$port} failed: {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($sock, 15);

        $readResponse = static function () use ($sock): int {
            $line = '';
            do {
                $chunk = fgets($sock, 515);
                if ($chunk === false) {
                    break;
                }
                $line = $chunk;
            } while (isset($chunk[3]) && $chunk[3] === '-');
            return (int) substr($line, 0, 3);
        };

        $sendLine = static function (string $cmd) use ($sock): void {
            fwrite($sock, $cmd . "\r\n");
        };

        $fail = static function (string $stage) use ($sock): bool {
            error_log("[KSPDOWA][Mailer] SMTP failed at stage: {$stage}");
            fclose($sock);
            return false;
        };

        if ($readResponse() >= 400) {
            return $fail('greeting');
        }

        $sendLine('EHLO localhost');
        if ($readResponse() >= 400) {
            return $fail('ehlo');
        }

        if ($encryption === 'tls') {
            $sendLine('STARTTLS');
            if ($readResponse() >= 400) {
                return $fail('starttls');
            }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail('tls_handshake');
            }
            $sendLine('EHLO localhost');
            if ($readResponse() >= 400) {
                return $fail('ehlo_after_tls');
            }
        }

        if ($user !== '') {
            $sendLine('AUTH LOGIN');
            if ($readResponse() >= 400) {
                return $fail('auth_login');
            }
            $sendLine(base64_encode($user));
            if ($readResponse() >= 400) {
                return $fail('auth_user');
            }
            $sendLine(base64_encode($pass));
            if ($readResponse() >= 400) {
                return $fail('auth_pass');
            }
        }

        $sendLine('MAIL FROM:<' . $fromAddr . '>');
        if ($readResponse() >= 400) {
            return $fail('mail_from');
        }
        $sendLine('RCPT TO:<' . $to . '>');
        if ($readResponse() >= 400) {
            return $fail('rcpt_to');
        }
        $sendLine('DATA');
        if ($readResponse() >= 400) {
            return $fail('data');
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

        return $finalCode < 400;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
