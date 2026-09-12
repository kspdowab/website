<?php
/**
 * KSPDOWA — Admin/Officer Login
 * ============================================================
 * Phase 2 slice: login only. Registration, self-service password
 * reset, and the member portal are NOT part of this page — they
 * are full Phase 2 scope, built later.
 *
 * This page exists now solely to gate the Office Bearers admin
 * screen (Phase 1 dependency) behind real authentication instead
 * of leaving it open to the public.
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

        <form method="post" action="/login.php" autocomplete="off">
            <?= CSRF::htmlField() ?>

            <label for="identifier">Email, Mobile, or Username</label>
            <input type="text" id="identifier" name="identifier" required autofocus
                   value="<?= Sanitize::attr($_POST['identifier'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Sign In</button>
        </form>

        <div class="back-link"><a href="/">&larr; Back to home</a></div>
    </div>
</body>
</html>
