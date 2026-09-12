<?php
/**
 * KSPDOWA — Public Donation Form
 * ============================================================
 * Phase 3 unit (approved build order: Payment History -> Receipts ->
 * Donations). Public, no-login flow -- the approved donations schema
 * (docs/02_DATABASE_SCHEMA.md) has member_id explicitly NULLable
 * ("may be from members or non-members"), so this form never requires
 * an account and always creates member_id = NULL rows.
 *
 * Collects exactly the donor-facing fields the approved schema has:
 * donor_name (required), purpose (optional free text -- no fixed
 * category list exists anywhere in the approved docs, so none is
 * invented here), and amount (required, > 0). Everything else
 * (idempotency_key, gateway_* columns, receipt_no) is server-assigned.
 *
 * On success, binds THIS session to the pending donation just created
 * (Session::set('donation_id')) -- donate-payment.php, donate-verify.php
 * and donate-receipt.php all read the donation id ONLY from the
 * session, never from a query string, the same ownership pattern
 * register.php/payment.php already use for membership payments. There
 * is no login to gate these pages any other way for an anonymous
 * donor, and no email/mobile is collected in this unit, so the
 * session is the only way a donor can reach their own receipt --
 * closing the browser/session means the receipt page is no longer
 * reachable (same trade-off as any anonymous one-time checkout link).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Donate';
$errors    = [];
$clean     = ['donor_name' => '', 'purpose' => '', 'amount' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $clean['donor_name'] = Sanitize::string($_POST['donor_name'] ?? '', 200);
    $clean['purpose']    = Sanitize::string($_POST['purpose'] ?? '', 255);
    $clean['amount']     = (string) ($_POST['amount'] ?? '');

    if ($clean['donor_name'] === '') {
        $errors[] = 'Please enter your name.';
    }

    $amount = Sanitize::amount($_POST['amount'] ?? null);
    if ($amount === false) {
        $errors[] = 'Please enter a valid donation amount greater than zero.';
    }

    if (empty($errors)) {
        $result = Donation::create($clean['donor_name'], $clean['purpose'], (float) $amount);

        if ($result['success']) {
            Session::set('donation_id', $result['donation_id']);
            header('Location: /donate-payment.php');
            exit;
        }

        $errors[] = $result['error'] ?? 'Could not start your donation. Please try again.';
    }
}

require __DIR__ . '/includes/partials/header.php';
?>

<h1 class="page-title">Support the Association</h1>
<p class="page-subtitle">Make a voluntary donation to <?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?>. No login required.</p>

<div class="card form-narrow">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">
            <?= implode('<br>', array_map('Sanitize::html', $errors)) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/donate.php" autocomplete="off">
        <?= CSRF::htmlField() ?>
        <div class="form-group">
            <label for="donor_name">Your Name</label>
            <input type="text" id="donor_name" name="donor_name" required maxlength="200" value="<?= Sanitize::attr($clean['donor_name']) ?>">
        </div>
        <div class="form-group">
            <label for="purpose">Purpose (optional)</label>
            <input type="text" id="purpose" name="purpose" maxlength="255" placeholder="e.g. General Fund" value="<?= Sanitize::attr($clean['purpose']) ?>">
        </div>
        <div class="form-group">
            <label for="amount">Donation Amount (&#8377;)</label>
            <input type="number" id="amount" name="amount" required min="1" step="0.01" value="<?= Sanitize::attr($clean['amount']) ?>">
        </div>
        <p class="form-hint">You will be redirected to Razorpay's secure checkout to complete payment.</p>
        <button type="submit" class="btn btn-teal" style="width:100%; justify-content:center; margin-top:8px;">Continue to Payment</button>
    </form>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
