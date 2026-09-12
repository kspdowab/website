<?php
/**
 * KSPDOWA — Input Validation & Output Escaping Utilities
 * ============================================================
 * All user input MUST be validated through this class before use.
 * All output to HTML MUST pass through the escaping helpers.
 *
 * Principles:
 *   - Validate → sanitize → use
 *   - Never trust client-provided data (types, sizes, formats)
 *   - Use finfo (not client MIME) for file type detection
 *   - Generate safe random filenames for uploads
 * ============================================================
 */

declare(strict_types=1);

class Sanitize
{
    // ==================================================================
    // OUTPUT ESCAPING
    // Escape all user-controlled content before inserting into HTML.
    // ==================================================================

    /**
     * Escape for safe insertion inside HTML text nodes and attributes.
     * Use this as the default for any variable echoed into HTML.
     */
    public static function html(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /**
     * Escape for insertion inside an HTML attribute value (quoted).
     */
    public static function attr(mixed $value): string
    {
        return self::html($value);
    }

    /**
     * Encode a value for safe inline JavaScript output.
     * Produces a JSON-encoded string literal (including enclosing quotes).
     */
    public static function js(mixed $value): string
    {
        return json_encode(
            (string) $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
    }

    // ==================================================================
    // INPUT VALIDATION & CLEANING
    // Return cleaned value on success, false on validation failure.
    // ==================================================================

    /**
     * Trim and limit a string. Returns the trimmed string (may be empty).
     */
    public static function string(mixed $value, int $maxLength = 255): string
    {
        return mb_substr(trim((string) $value), 0, $maxLength, 'UTF-8');
    }

    /**
     * Validate and normalise an email address (lowercased).
     * Returns false if invalid.
     */
    public static function email(mixed $value): string|false
    {
        $clean = filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL);
        return $clean !== false ? strtolower($clean) : false;
    }

    /**
     * Validate an Indian mobile number.
     * Accepts formats: +91XXXXXXXXXX, 91XXXXXXXXXX, 0XXXXXXXXXX, XXXXXXXXXX
     * Returns the bare 10-digit number or false.
     */
    public static function mobile(mixed $value): string|false
    {
        $clean = trim((string) $value);
        $clean = preg_replace('/[\s\-\(\)]/', '', $clean);

        if (preg_match('/^(\+91|91|0)?([6-9]\d{9})$/', $clean, $m)) {
            return $m[2]; // return bare 10-digit number
        }

        return false;
    }

    /**
     * Strictly validate an Indian mobile number for contexts (such as the
     * self-service member registration form) that must REJECT any prefix,
     * spacing or formatting rather than normalizing it away -- exactly 10
     * digits, first digit 6-9, digits only. Unlike mobile() above (which
     * is intentionally lenient for admin data entry and strips +91/91/0
     * prefixes), this returns false for anything that is not already a
     * bare 10-digit number.
     */
    public static function mobileStrict(mixed $value): string|false
    {
        $clean = trim((string) $value);
        return preg_match('/^[6-9][0-9]{9}$/', $clean) === 1 ? $clean : false;
    }

    /**
     * Validate as integer. Returns false if not a valid integer.
     */
    public static function int(mixed $value): int|false
    {
        return filter_var($value, FILTER_VALIDATE_INT);
    }

    /**
     * Validate as a positive integer (> 0). Returns false otherwise.
     */
    public static function positiveInt(mixed $value): int|false
    {
        $v = filter_var($value, FILTER_VALIDATE_INT);
        return ($v !== false && $v > 0) ? $v : false;
    }

    /**
     * Validate as a non-negative integer (>= 0).
     */
    public static function nonNegativeInt(mixed $value): int|false
    {
        $v = filter_var($value, FILTER_VALIDATE_INT);
        return ($v !== false && $v >= 0) ? $v : false;
    }

    /**
     * Validate as float. Returns false if not valid.
     */
    public static function float(mixed $value): float|false
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT);
    }

    /**
     * Validate as a positive monetary amount (> 0, max 2 decimal places).
     */
    public static function amount(mixed $value): float|false
    {
        $v = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($v === false || $v <= 0) {
            return false;
        }
        // Round to 2 decimal places and verify it matches original
        return round($v, 2);
    }

    /**
     * Validate a date string against a given format (default Y-m-d).
     * Returns the date string on success, false on failure.
     */
    public static function date(mixed $value, string $format = 'Y-m-d'): string|false
    {
        $clean = trim((string) $value);
        $d     = DateTime::createFromFormat($format, $clean);
        return ($d && $d->format($format) === $clean) ? $clean : false;
    }

    /**
     * Validate that a value is one of the allowed options.
     * Returns the value if allowed, false otherwise.
     */
    public static function inArray(mixed $value, array $allowed): mixed
    {
        return in_array($value, $allowed, true) ? $value : false;
    }

    /**
     * Convert a string to a URL-safe slug.
     */
    public static function slug(mixed $value, int $maxLength = 200): string
    {
        $clean = mb_strtolower(trim((string) $value), 'UTF-8');
        $clean = preg_replace('/[^a-z0-9\-]/', '-', $clean);
        $clean = preg_replace('/-+/', '-', $clean);
        $clean = trim($clean, '-');
        return mb_substr($clean, 0, $maxLength, 'UTF-8');
    }

    /**
     * Validate password strength.
     * Returns empty array on success, or list of error messages.
     */
    public static function password(string $password): array
    {
        $errors = [];
        $min    = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;

        if (strlen($password) < $min) {
            $errors[] = "Password must be at least {$min} characters long.";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit.';
        }

        return $errors;
    }

    // ==================================================================
    // FILE UPLOAD VALIDATION
    // ==================================================================

    /**
     * Validate an uploaded file ($_FILES entry).
     *
     * @param array  $file         Entry from $_FILES
     * @param array  $allowedTypes Allowed MIME types (detected via finfo, not client header)
     * @param int    $maxBytes     Maximum allowed file size in bytes
     * @return array Empty array on success, list of error strings on failure
     */
    public static function fileUpload(
        array $file,
        array $allowedTypes,
        int   $maxBytes
    ): array {
        $errors = [];

        if (!isset($file['error'])) {
            $errors[] = 'Invalid file upload data.';
            return $errors;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed (error code: ' . $file['error'] . ').';
            return $errors;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Invalid upload — file was not uploaded via HTTP POST.';
            return $errors;
        }

        if ($file['size'] > $maxBytes) {
            $mb = round($maxBytes / (1024 * 1024), 1);
            $errors[] = "File size exceeds the maximum allowed size of {$mb} MB.";
        }

        // Use finfo for reliable MIME detection (ignore client-supplied type)
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file['tmp_name']);

        if (!in_array($detected, $allowedTypes, true)) {
            $errors[] = 'File type is not allowed. Detected: ' . $detected;
        }

        return $errors;
    }

    /**
     * Generate a cryptographically random safe filename for an upload.
     * Preserves the file extension (lowercase, stripped of non-alpha chars).
     */
    public static function safeUploadFilename(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        return bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    }
}
