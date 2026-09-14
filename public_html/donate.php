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

<span class="eyebrow">Voluntary Contribution</span>
<h1 class="page-title">Support the Association</h1>
<p class="page-subtitle">Make a voluntary donation to <?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?>. No login required.</p>

<div class="card form-narrow" style="padding:32px 30px;">
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid var(--border-soft);">
        <span class="icon-badge blue" style="background:var(--red-100); color:var(--brand-red);" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
        </span>
        <div>
            <h2 style="margin:0; font-size:1.15rem; color:var(--primary-navy); border-bottom:none; padding-bottom:0;">Online Donation</h2>
            <span style="font-size:0.8rem; color:var(--text-secondary);">Direct voluntary contribution via secure gateway</span>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">
            <?= implode('<br>', array_map('Sanitize::html', $errors)) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/donate.php" autocomplete="off">
        <?= CSRF::htmlField() ?>
        <div class="form-group">
            <label for="donor_name">Your Full Name (ಪೂರ್ಣ ಹೆಸರು)</label>
            <input type="text" id="donor_name" name="donor_name" required maxlength="200" value="<?= Sanitize::attr($clean['donor_name']) ?>" placeholder="Enter donor name">
        </div>
        <div class="form-group">
            <label for="purpose">Purpose / Remarks (ಉದ್ದೇಶ - ಐಚ್ಛಿಕ)</label>
            <input type="text" id="purpose" name="purpose" maxlength="255" placeholder="e.g. General Welfare Fund" value="<?= Sanitize::attr($clean['purpose']) ?>">
        </div>
        <div class="form-group">
            <label for="amount">Donation Amount (ದೇಣಿಗೆ ಮೊತ್ತ - &#8377;)</label>
            <input type="number" id="amount" name="amount" required min="1" step="0.01" value="<?= Sanitize::attr($clean['amount']) ?>" placeholder="Amount in INR">
        </div>
        <p class="form-hint" style="display:flex; align-items:center; gap:6px; margin:12px 0 18px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--prof-blue); flex-shrink:0;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span>You will be redirected to secure checkout to complete payment.</span>
        </p>
        <button type="submit" class="btn" style="width:100%; justify-content:center;">
            <span>Continue to Payment</span>
            <span aria-hidden="true">&rarr;</span>
        </button>
    </form>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
