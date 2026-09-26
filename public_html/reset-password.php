<?php
/**
 * KSPDOWA — Reset Password
 * ============================================================
 * Handles setting a new password via a verified, single-use reset token.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::getCurrentMemberId() !== null ? '/member/index.php' : '/admin/index.php'));
    exit;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$resetUser = null;
$error = null;
$errors = [];

if ($token === '') {
    $error = 'Missing password reset token. Please request a new link.';
} else {
    $resetUser = Auth::verifyPasswordResetToken($token);
    if (!$resetUser) {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $resetUser) {
    CSRF::requireValid();

    $newPassword     = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The two new passwords do not match.';
    }

    $pwdErrors = Sanitize::password($newPassword);
    $errors    = array_merge($errors, $pwdErrors);

    if (empty($errors)) {
        $res = Auth::completePasswordReset($token, $newPassword);
        if ($res['success']) {
            Session::flash('notice', 'Your password has been successfully reset! Please sign in with your new password.');
            header('Location: /login.php');
            exit;
        } else {
            $errors[] = $res['error'] ?? 'An error occurred while resetting your password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password — <?= Sanitize::html(Settings::get('site_short_name', APP_SHORT_NAME)) ?></title>
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
        input[type="password"] {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #1769AA;
            box-shadow: 0 0 0 3px rgba(23, 105, 170, 0.15);
        }
        .password-field-wrap {
            position: relative;
            margin-bottom: 18px;
        }
        .password-field-wrap input.has-toggle {
            padding-right: 44px;
        }
        .password-toggle-btn {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            background: transparent;
            border: none;
            border-radius: 4px;
            padding: 0;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 0;
            transition: color 0.15s, background-color 0.15s;
        }
        .password-toggle-btn:hover {
            color: #1769AA;
            background: #EEF5FA;
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
            margin-top: 8px;
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
        .user-pill {
            background: #F1F5F9;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            color: #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        <p class="subtitle">Set a New Password</p>

        <?php if ($error !== null): ?>
            <div class="error" role="alert">
                <?= Sanitize::html($error) ?>
            </div>
            <div class="back-link">
                <a href="/forgot-password.php">&larr; Request a new password reset link</a>
            </div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="error" role="alert">
                    <?php foreach ($errors as $e): ?>
                        <div>• <?= Sanitize::html($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="user-pill">
                <span>Account: <strong><?= Sanitize::html($resetUser['member_name'] ?: ($resetUser['email'] ?: 'Member')) ?></strong></span>
                <?php if (!empty($resetUser['kgid_no'])): ?>
                    <span style="color:#64748b; font-size:0.8rem;">KGID: <?= Sanitize::html((string)$resetUser['kgid_no']) ?></span>
                <?php endif; ?>
            </div>

            <form method="post" action="/reset-password.php">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="token" value="<?= Sanitize::attr($token) ?>">

                <label for="new_password">New Password</label>
                <div class="password-field-wrap">
                    <input type="password" id="new_password" name="new_password" required class="has-toggle" autofocus
                           autocomplete="new-password" placeholder="Enter new password (min. 8 characters)">
                    <button type="button" class="password-toggle-btn" data-target="new_password" aria-label="Toggle password visibility">
                        <svg class="icon-eye" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.8 21.8 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 7 11 7a21.7 21.7 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <label for="confirm_password">Confirm New Password</label>
                <div class="password-field-wrap">
                    <input type="password" id="confirm_password" name="confirm_password" required class="has-toggle"
                           autocomplete="new-password" placeholder="Re-enter new password">
                    <button type="button" class="password-toggle-btn" data-target="confirm_password" aria-label="Toggle password visibility">
                        <svg class="icon-eye" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.8 21.8 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 7 11 7a21.7 21.7 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:10px 12px; margin-bottom:16px; font-size:0.8rem; color:#64748b;">
                    Password must be at least <strong>8 characters</strong> and include uppercase (A-Z), lowercase (a-z), and a number (0-9).
                </div>

                <button type="submit">Update Password &amp; Sign In</button>
            </form>

            <div class="back-link">
                <a href="/login.php">Cancel and back to Sign In</a>
            </div>

        <?php endif; ?>
    </div>

    <script>
    document.querySelectorAll('.password-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-target');
            var input = document.getElementById(targetId);
            var eye = this.querySelector('.icon-eye');
            var eyeOff = this.querySelector('.icon-eye-off');
            if (input.type === 'password') {
                input.type = 'text';
                eye.style.display = 'none';
                eyeOff.style.display = 'inline-block';
            } else {
                input.type = 'password';
                eye.style.display = 'inline-block';
                eyeOff.style.display = 'none';
            }
        });
    });
    </script>
</body>
</html>
