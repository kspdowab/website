<?php
/**
 * KSPDOWA — WhatsApp Messaging Integration
 * ============================================================
 * Single entry point for WhatsApp transactional notifications:
 * WhatsApp::send($phone, $message).
 *
 * Supported Drivers:
 *   'log'       (default, safe for local testing) -- writes to
 *               storage/whatsapp.log without network activity.
 *   'cloud_api' (Meta WhatsApp Cloud API) -- sends via official
 *               Facebook Graph API v18.0.
 *   'webhook'   (Custom Webhook/Aggregator) -- posts JSON to a
 *               configured aggregator endpoint (e.g. Gupshup/MSG91).
 *
 * Never throws: all operations catch exceptions, enforce a 5-second
 * network timeout, and log errors gracefully.
 * ============================================================
 */

declare(strict_types=1);

class WhatsApp
{
    /**
     * Resolve active WhatsApp configuration.
     */
    public static function getConfig(): array
    {
        $enabled = defined('WHATSAPP_ENABLED')
            ? (bool) WHATSAPP_ENABLED
            : (class_exists('Settings') && Settings::get('whatsapp_enabled', 'false') === 'true');

        $driver = defined('WHATSAPP_DRIVER') && WHATSAPP_DRIVER !== ''
            ? WHATSAPP_DRIVER
            : (class_exists('Settings') ? Settings::get('whatsapp_driver', 'log') : 'log');

        $endpoint = defined('WHATSAPP_ENDPOINT') && WHATSAPP_ENDPOINT !== ''
            ? WHATSAPP_ENDPOINT
            : (class_exists('Settings') ? Settings::get('whatsapp_endpoint', 'https://graph.facebook.com/v18.0') : 'https://graph.facebook.com/v18.0');

        $phoneId = defined('WHATSAPP_PHONE_NUMBER_ID') && WHATSAPP_PHONE_NUMBER_ID !== ''
            ? WHATSAPP_PHONE_NUMBER_ID
            : (class_exists('Settings') ? Settings::get('whatsapp_phone_number_id', '') : '');

        $token = defined('WHATSAPP_TOKEN') && WHATSAPP_TOKEN !== ''
            ? WHATSAPP_TOKEN
            : (class_exists('Settings') ? Settings::get('whatsapp_token', '') : '');

        return [
            'enabled'   => $enabled,
            'driver'    => $driver,
            'endpoint'  => $endpoint,
            'phone_id'  => $phoneId,
            'token'     => $token,
        ];
    }

    /**
     * Send a WhatsApp message.
     */
    public static function send(string $phone, string $message, ?string &$errorOut = null): bool
    {
        $cfg = self::getConfig();

        if (!$cfg['enabled'] && $cfg['driver'] !== 'log') {
            $errorOut = 'WhatsApp integration is currently disabled.';
            return false;
        }

        $cleanPhone = self::normalizePhone($phone);
        if ($cleanPhone === null) {
            $errorOut = 'Invalid phone number format: ' . $phone;
            return false;
        }

        try {
            return match ($cfg['driver']) {
                'cloud_api' => self::sendCloudApi($cleanPhone, $message, $cfg, $errorOut),
                'webhook'   => self::sendWebhook($cleanPhone, $message, $cfg, $errorOut),
                default     => self::sendLog($cleanPhone, $message, $errorOut),
            };
        } catch (Throwable $e) {
            $errorOut = 'WhatsApp error: ' . $e->getMessage();
            error_log('[KSPDOWA][WhatsApp] ' . $errorOut);
            return false;
        }
    }

    /**
     * Diagnostic test method for Admin Integrations panel.
     */
    public static function sendTest(string $phone, ?string &$diagnosticMsg = null): bool
    {
        $cfg = self::getConfig();
        $testMsg = "KSPDOWA WhatsApp Integration Test\n"
                 . "Timestamp: " . date('Y-m-d H:i:s') . "\n"
                 . "Status: Operational\n"
                 . "Designed & Developed by : KHUBAASING JADAV";

        $cleanPhone = self::normalizePhone($phone);
        if ($cleanPhone === null) {
            $diagnosticMsg = 'Invalid phone number. Please enter a valid 10-digit Indian mobile number.';
            return false;
        }

        if ($cfg['driver'] === 'log') {
            $ok = self::sendLog($cleanPhone, $testMsg, $diagnosticMsg);
            if ($ok) {
                $diagnosticMsg = 'Driver is currently set to "log". Message written to storage/whatsapp.log.';
                return true;
            }
            return false;
        }

        return self::send($cleanPhone, $testMsg, $diagnosticMsg);
    }

    /**
     * Normalize Indian and international mobile numbers into standard E.164 digits without '+'.
     */
    public static function normalizePhone(string $phone): ?string
    {
        // Strip everything except digits
        $digits = preg_replace('/[^\d]/', '', $phone);

        // Standard 10-digit Indian mobile number starting with 6, 7, 8, or 9
        if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
            return '91' . $digits;
        }

        // 11-digit starting with 0 (e.g. 098XXXXXXXX)
        if (strlen($digits) === 11 && str_starts_with($digits, '0') && preg_match('/^0[6-9]\d{9}$/', $digits)) {
            return '91' . substr($digits, 1);
        }

        // 12-digit Indian number starting with 91 (e.g. 9198XXXXXXXX)
        if (strlen($digits) === 12 && str_starts_with($digits, '91') && preg_match('/^91[6-9]\d{9}$/', $digits)) {
            return $digits;
        }

        // Generic international digits (10-15 digits)
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return $digits;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 'log' driver
    // ------------------------------------------------------------------
    private static function sendLog(string $phone, string $message, ?string &$errorOut = null): bool
    {
        $logPath = PROJECT_ROOT . '/storage/whatsapp.log';
        $dir     = dirname($logPath);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $errorOut = 'Could not create storage directory for WhatsApp log.';
            return false;
        }

        $entry = sprintf(
            "==== WHATSAPP (log driver -- not sent) ====\nTime: %s\nTo: %s\n----\n%s\n\n",
            date('Y-m-d H:i:s'),
            $phone,
            $message
        );

        $written = @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            $errorOut = 'Failed to write to storage/whatsapp.log.';
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // 'cloud_api' driver (Meta WhatsApp Cloud API)
    // ------------------------------------------------------------------
    private static function sendCloudApi(string $phone, string $message, array $cfg, ?string &$errorOut = null): bool
    {
        $phoneId = $cfg['phone_id'];
        $token   = $cfg['token'];

        if ($phoneId === '' || $token === '') {
            $errorOut = 'Meta WhatsApp Cloud API requires Phone Number ID and Access Token.';
            return false;
        }

        $url = rtrim($cfg['endpoint'], '/') . '/' . rawurlencode($phoneId) . '/messages';

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $message,
            ],
        ];

        return self::executeHttpJson($url, $payload, ['Authorization: Bearer ' . $token], $errorOut);
    }

    // ------------------------------------------------------------------
    // 'webhook' driver
    // ------------------------------------------------------------------
    private static function sendWebhook(string $phone, string $message, array $cfg, ?string &$errorOut = null): bool
    {
        $url = $cfg['endpoint'];
        if ($url === '') {
            $errorOut = 'Webhook URL is not configured.';
            return false;
        }

        $payload = [
            'recipient' => $phone,
            'message'   => $message,
            'sender'    => 'KSPDOWA',
            'timestamp' => time(),
        ];

        $headers = [];
        if ($cfg['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['token'];
        }

        return self::executeHttpJson($url, $payload, $headers, $errorOut);
    }

    private static function executeHttpJson(string $url, array $payload, array $customHeaders, ?string &$errorOut = null): bool
    {
        $json = json_encode($payload);
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: KSPDOWA-Platform/1.0',
        ], $customHeaders);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $errorOut = 'cURL error connecting to WhatsApp provider: ' . $curlErr;
                return false;
            }

            if ($httpCode >= 400) {
                $errorOut = "WhatsApp provider responded with HTTP {$httpCode}: " . substr((string)$response, 0, 200);
                return false;
            }

            return true;
        }

        // Fallback to stream context if cURL is unavailable
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $errorOut = 'HTTP request to WhatsApp provider failed.';
            return false;
        }

        return true;
    }
}
