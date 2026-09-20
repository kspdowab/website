<?php
/**
 * KSPDOWA — Forgot Password
 * ============================================================
 * Allows officers, admins, and members to request a secure password reset
 * using their registered Email, Mobile Number, or KGID.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::getCurrentMemberId() !== null ? '/member/index.php' : '/admin/index.php'));
    exit;
}

$error           = null;
$success         = null;
$tip             = null;
$maskedEmail     = null;
$directResetLink = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $identifier = trim(Sanitize::string($_POST['identifier'] ?? '', 190));

    if ($identifier === '') {
        $error = 'Please enter your Email, Mobile Number, or KGID.';
    } else {
        $user = Auth::findUserForReset($identifier);

        if ($user) {
            $token = Auth::createPasswordResetToken((int)$user['id']);
            $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://kspdowa.in';
            $resetLink = $baseUrl . '/reset-password.php?token=' . urlencode($token);

            $recipientEmail = $user['email'] ?: ($user['personal_email'] ?? '');
            $mailSent = false;

            if (!empty($recipientEmail) && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $subject = 'Password Reset Request — ' . APP_SHORT_NAME;
                $bodyText = "Dear " . ($user['member_name'] ?: 'Member') . ",\n\n"
                    . "We received a request to reset your password for the " . APP_SHORT_NAME . " portal.\n\n"
                    . "Click the link below to set a new password:\n"
                    . $resetLink . "\n\n"
                    . "This link is valid for 1 hour. If you did not request this, you can safely ignore this email.\n\n"
                    . "Regards,\n" . APP_SHORT_NAME;

                $mailSent = Mailer::send($recipientEmail, $subject, $bodyText);

                // Mask email for display: m****@gmail.com
                $parts = explode('@', $recipientEmail);
                $namePart = $parts[0];
                $domainPart = $parts[1] ?? '';
                $masked = substr($namePart, 0, 1) . str_repeat('*', max(3, strlen($namePart) - 2)) . (strlen($namePart) > 1 ? substr($namePart, -1) : '') . '@' . $domainPart;
                $maskedEmail = $masked;
            }

            $mailCfg = Mailer::getConfig();
            if ($mailSent) {
                $success = 'A password reset link has been sent to ' . Sanitize::html($maskedEmail ?? 'your registered email') . '. Please check your inbox.';
            } else {
                $success = 'A secure password reset link has been generated.';
                $directResetLink = $resetLink;
            }

            // If the member still has default initial password flag
            if (!empty($user['kgid_no']) && (int)($user['must_change_password'] ?? 0) === 1) {
                $tip = 'Your account has an active default temporary password: Kspdowa@' . Sanitize::html((string)$user['kgid_no']) . '. You can sign in immediately using this password at the Sign In page without resetting.';
            }
        } else {
            // Keep message generic to prevent account harvesting
            $success = 'If an account exists matching that identifier, password reset instructions have been issued.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Kannada:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans Kannada', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #F5F7FA;
            color: #17202A;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #ffffff;
            border: 1px solid #DCE3EA;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(23, 63, 103, 0.08);
            padding: 40px 32px;
            width: 100%;
            max-width: 440px;
        }
        h1 {
            color: #173F67;
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0 0 4px;
            text-align: center;
        }
        .subtitle {
            color: #667085;
            font-size: 0.88rem;
            text-align: center;
            margin: 0 0 24px;
        }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #17202A;
            margin-bottom: 6px;
        }
        input[type="text"] {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 1rem;
            margin-bottom: 18px;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #1769AA;
            box-shadow: 0 0 0 3px rgba(23, 105, 170, 0.15);
        }
        button[type="submit"] {
            width: 100%;
            background: #173F67;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        button[type="submit"]:hover { background: #0F4C81; }
        .error {
            background: #FDE8E8;
            color: #C00000;
            border: 1px solid #FECACA;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 0.85rem;
            margin-bottom: 18px;
        }
        .success {
            background: #F0FDF4;
            color: #166534;
            border: 1px solid #BBF7D0;
            border-radius: 6px;
            padding: 12px 14px;
            font-size: 0.88rem;
            line-height: 1.45;
            margin-bottom: 18px;
        }
        .tip-box {
            background: #EFF6FF;
            color: #1E40AF;
            border: 1px solid #BFDBFE;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 0.85rem;
            line-height: 1.5;
            margin-bottom: 18px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 0.88rem;
        }
        .back-link a { color: #1769AA; text-decoration: none; font-weight: 600; }
        .back-link a:hover { color: #0F4C81; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= Sanitize::html(APP_SHORT_NAME) ?></h1>
        <p class="subtitle">Reset Your Account Password</p>

        <?php if ($error !== null): ?>
            <div class="error" role="alert"><?= Sanitize::html($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== null): ?>
            <div class="success" role="alert">
                <?= Sanitize::html($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($directResetLink !== null): ?>
            <div style="background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:16px; margin-bottom:18px; text-align:center;">
                <div style="font-weight:700; color:#B45309; font-size:0.95rem; margin-bottom:6px;">⚡ Direct Instant Password Reset</div>
                <p style="margin:0 0 12px; font-size:0.85rem; color:#92400E; line-height:1.4;">
                    Your account has been verified. Click the button below to set a new password right away:
                </p>
                <a href="<?= Sanitize::attr($directResetLink) ?>" style="background:#D97706; color:#ffffff; font-weight:700; text-decoration:none; padding:10px 20px; border-radius:6px; display:inline-block; font-size:0.92rem; box-shadow:0 2px 6px rgba(217, 119, 6, 0.3);">
                    Set New Password Now &rarr;
                </a>
            </div>
        <?php endif; ?>

        <?php if ($tip !== null): ?>
            <div class="tip-box">
                <strong>💡 Member Quick Access:</strong><br>
                <?= Sanitize::html($tip) ?><br><br>
                <a href="/login.php" style="color:#1D4ED8; font-weight:700; text-decoration:underline;">Click here to Sign In with default password &rarr;</a>
            </div>
        <?php endif; ?>

        <form method="post" action="/forgot-password.php" id="forgot-form">
            <?= CSRF::htmlField() ?>

            <label for="identifier">Registered Email, Mobile Number, or KGID</label>
            <input type="text" id="identifier" name="identifier" required autofocus
                   placeholder="e.g. 123456 or 9845012345 or user@email.com"
                   value="<?= Sanitize::attr($_POST['identifier'] ?? '') ?>">

            <button type="submit">Send Reset Instructions</button>
        </form>

        <div style="margin-top:20px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; padding:12px; font-size:0.82rem; color:#475569; line-height:1.5;">
            <strong>New / Imported Member?</strong><br>
            All registered members have an initial default password formatted as: <code>Kspdowa@&lt;KGID&gt;</code> (e.g. <code>Kspdowa@123456</code>). On your first sign in, you will be asked to set your personal password.
        </div>

        <div class="back-link">
            Remembered your password? <a href="/login.php">Back to Sign In</a>
        </div>
    </div>
</body>
</html>
