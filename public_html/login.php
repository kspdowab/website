<?php
/**
 * KSPDOWA — Sign In (Admin/Officer AND Member accounts)
 * ============================================================
 * One shared login form for every `users` row, admin/officer and
 * member alike -- Auth::login() already looks accounts up generically
 * by email/mobile/username. What differs is what happens AFTER a
 * successful password check:
 *
 *   - Non-member accounts (member_id IS NULL): unchanged Phase 1/2
 *     behavior -- redirect to the admin area (or wherever the user
 *     was headed before being bounced here).
 *
 *   - Member accounts: this is where the approved Phase 3 "current
 *     year eligibility" architecture rule is enforced. A member
 *     account only ever exists because member-login.php verified
 *     current-year payment at activation time, but eligibility can
 *     change later (a member who paid last year but not this one
 *     must NOT be allowed to log in as current-year eligible) -- so
 *     it is re-checked on every login, not just at account creation:
 *       1. must_change_password=1 (always true right after
 *          activation) -> force /member/change-password.php before
 *          anything else.
 *       2. Not current-year eligible -> the session that Auth::login()
 *          just created is destroyed immediately (no lingering
 *          logged-in state for an ineligible member) and the person is
 *          sent back to member-login.php with a clear message.
 *       3. Otherwise -> the member portal.
 *
 * Registration and a generic self-service password reset are
 * deliberately NOT part of this page (Phase 3 scope explicitly
 * excludes both beyond the first-login temporary-password change).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already logged in? Don't show the login form again.
if (Auth::isLoggedIn()) {
    header('Location: /admin/office-bearers.php');
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $identifier = Sanitize::string($_POST['identifier'] ?? '', 190);
    $password   = (string) ($_POST['password'] ?? '');

    $result = Auth::login($identifier, $password);

    if ($result['success']) {
        $memberId = Session::get('member_id');

        if ($memberId !== null) {
            $userRow = Database::fetchOne('SELECT must_change_password FROM users WHERE id = ?', [$result['user_id']]);

            if ($userRow && (int) $userRow['must_change_password'] === 1) {
                // First login on a system-issued temporary password --
                // this member WAS current-year eligible at activation
                // time (member-login.php only ever creates the account
                // after verifying that); force the password change
                // before anything else.
                header('Location: /member/change-password.php');
                exit;
            }

            $currentYear = Membership::getCurrentYear();
            if ($currentYear === null || !Membership::isEligibleForYear((int) $memberId, (int) $currentYear['id'])) {
                // Architecture rule: a member not eligible for the
                // CURRENT year may not be logged in, even if they were
                // eligible in a previous year and already know their
                // real password. Destroy the session Auth::login() just
                // created rather than leaving it standing.
                AuditLogger::log('LOGIN_DENIED_INELIGIBLE', 'users', $result['user_id']);
                Session::destroy();
                Session::flash('error', 'Your current-year annual membership fee has not been verified yet. Please complete payment to access the member portal.');
                header('Location: /member-login.php');
                exit;
            }

            header('Location: /member/index.php');
            exit;
        }

        $redirect = Session::getFlash('redirect_after_login', '/admin/office-bearers.php');
        header('Location: ' . $redirect);
        exit;
    }

    $error = $result['error'] ?? 'Invalid credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f7fa;
            color: #1a1a2e;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 16px rgba(26, 58, 107, 0.10);
            padding: 40px 32px;
            width: 100%;
            max-width: 380px;
        }
        h1 {
            color: #1a3a6b;
            font-size: 1.3rem;
            margin: 0 0 4px;
            text-align: center;
        }
        .subtitle {
            color: #666;
            font-size: 0.85rem;
            text-align: center;
            margin: 0 0 28px;
        }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #33415c;
            margin-bottom: 6px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 1rem;
            margin-bottom: 18px;
        }
        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #1a3a6b;
        }
        button {
            width: 100%;
            background: #1a3a6b;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #142c52; }
        .error {
            background: #fdecea;
            color: #a12622;
            border: 1px solid #f5c2be;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 0.85rem;
            margin-bottom: 18px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 0.85rem;
        }
        .back-link a { color: #1a3a6b; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= Sanitize::html(APP_SHORT_NAME) ?></h1>
        <p class="subtitle">Officer / Admin Sign In</p>

        <?php if ($error !== null): ?>
            <div class="error" role="alert"><?= Sanitize::html($error) ?></div>
        <?php endif; ?>

        <?php
        // Coming from member-login.php with a known-eligible email
        // (an existing account, or one just activated): pre-fill the
        // identifier and let the member type only their password,
        // rather than making them re-enter an email that's already
        // confirmed correct. A failed POST re-render keeps whatever
        // the person actually typed (so a mistaken identifier stays
        // editable), only a GET query value locks the field.
        $identifierFromQuery = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['identifier'] ?? '') !== '';
        $identifierValue     = (string) ($_POST['identifier'] ?? $_GET['identifier'] ?? '');
        ?>
        <form method="post" action="/login.php" autocomplete="off">
            <?= CSRF::htmlField() ?>

            <label for="identifier">Email, Mobile, or Username</label>
            <input type="text" id="identifier" name="identifier" required
                   <?= $identifierFromQuery ? 'readonly' : 'autofocus' ?>
                   value="<?= Sanitize::attr($identifierValue) ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required
                   <?= $identifierFromQuery ? 'autofocus' : '' ?>>

            <button type="submit">Sign In</button>
        </form>

        <div class="back-link"><a href="/">&larr; Back to home</a></div>
    </div>
</body>
</html>
