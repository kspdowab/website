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

            $recipientEmail = trim((string)($user['email'] ?: ($user['personal_email'] ?? '')));
            $recipientMobile = trim((string)($user['personal_mobile'] ?? ''));
            $memberName = trim((string)($user['member_name'] ?? 'Member'));
            $mailSent = false;

            if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $siteShort = Settings::get('site_short_name', APP_SHORT_NAME);
                $subject = 'Password Setup / Reset Request — ' . $siteShort;
                $plainText = "Dear {$memberName},\n\n"
                    . "A request has been received to set up or reset your password for the {$siteShort} member portal.\n\n"
                    . "Click the link below to set your password:\n"
                    . "{$resetLink}\n\n"
                    . "This link is valid for 1 hour. If you did not request this, you can safely ignore this email.\n\n"
                    . "Regards,\n{$siteShort}";

                $htmlBody = '<p>Dear <strong>' . htmlspecialchars($memberName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
                    . '<p>A request was received to set up or reset your password for the <strong>' . htmlspecialchars($siteShort, ENT_QUOTES, 'UTF-8') . '</strong> member portal.</p>'
                    . '<p>Please click the button below to create your password:</p>'
                    . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="background:#173F67; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;">Set Your Password</a></p>'
                    . '<p style="font-size:0.85rem; color:#64748b;">This secure link is valid for 1 hour. If you did not make this request, please contact the association office.</p>';

                if (class_exists('EmailTemplates')) {
                    $fullHtml = EmailTemplates::wrap($subject, $htmlBody, $resetLink, 'Set Your Password');
                } else {
                    $fullHtml = $htmlBody;
                }

                $mailSent = Mailer::send($recipientEmail, $subject, $plainText, $fullHtml);
                $maskedEmail = Auth::maskEmail($recipientEmail);

                // WhatsApp alert if enabled
                if ($recipientMobile !== '' && class_exists('WhatsApp') && class_exists('Settings') && Settings::get('notif_whatsapp_enabled', '0') === '1') {
                    $waMsg = "*{$siteShort} Security Alert*\n\nDear {$memberName},\n\nClick the link below to set your secure member portal password (valid for 1 hour):\n{$resetLink}";
                    $waErr = null;
                    WhatsApp::send($recipientMobile, $waMsg, $waErr);
                }

                if ($mailSent) {
                    $success = 'A secure password setup / reset link has been sent to ' . Sanitize::html($maskedEmail) . '. Please check your inbox (and spam folder) to set your password.';
                } else {
                    if (defined('APP_ENV') && APP_ENV === 'development') {
                        $success = 'A password reset link has been generated (Local Dev Mode):';
                        $directResetLink = $resetLink;
                    } else {
                        $error = 'Unable to deliver the password reset email at this moment. Please contact the association office for assistance.';
                    }
                }
            } else {
                $error = 'Your account was located, but there is no registered email address on file. Please contact your District/Taluk representative or the association office to update your registered email.';
            }
        } else {
            // Keep message generic to prevent account harvesting
            $success = 'If an account exists matching that identifier, password setup instructions have been sent to the registered email on file.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — <?= Sanitize::html(Settings::get('site_short_name', APP_SHORT_NAME)) ?></title>
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
            border-radius: 8px;
            font-size: 0.95rem;
            margin-bottom: 18px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
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
            border-radius: 8px;
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
        <h1><?= Sanitize::html(Settings::get('site_short_name', APP_SHORT_NAME)) ?></h1>
        <p class="subtitle">Reset Your Account Password</p>

        <?php if ($error !== null): ?>
            <div class="error" role="alert"><?= Sanitize::html($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== null): ?>
            <div class="success" role="alert">
                <?= Sanitize::html($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($directResetLink !== null && defined('APP_ENV') && APP_ENV === 'development'): ?>
            <div style="background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:16px; margin-bottom:18px; text-align:center;">
                <div style="font-weight:700; color:#B45309; font-size:0.95rem; margin-bottom:6px;">⚡ Direct Instant Password Reset (Dev Mode)</div>
                <p style="margin:0 0 12px; font-size:0.85rem; color:#92400E; line-height:1.4;">
                    Local environment detected. You can test the password reset immediately:
                </p>
                <a href="<?= Sanitize::attr($directResetLink) ?>" style="background:#D97706; color:#ffffff; font-weight:700; text-decoration:none; padding:10px 20px; border-radius:6px; display:inline-block; font-size:0.92rem; box-shadow:0 2px 6px rgba(217, 119, 6, 0.3);">
                    Set New Password Now &rarr;
                </a>
            </div>
        <?php endif; ?>

        <form method="post" action="/forgot-password.php" id="forgot-form">
            <?= CSRF::htmlField() ?>

            <label for="identifier">Registered Email, Mobile Number, or KGID</label>
            <input type="text" id="identifier" name="identifier" required autofocus
                   placeholder="Enter KGID No., Mobile No., or Email"
                   value="<?= Sanitize::attr($_POST['identifier'] ?? '') ?>">

            <button type="submit">Send Reset Instructions</button>
        </form>

        <div style="margin-top:20px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:12px; font-size:0.82rem; color:#475569; line-height:1.5;">
            <strong>Need Help?</strong><br>
            If your registered email has changed or you cannot access it, please contact your Taluk/District representative or the association office to update your contact information.
        </div>

        <div class="back-link">
            Remembered your password? <a href="/login.php">Back to Sign In</a>
        </div>
    </div>
</body>
</html>
