<?php
/**
 * KSPDOWA — Admin: Member Details & Lifecycle
 * ============================================================
 * Gated by RBAC 'members.manage'.
 * Displays complete member data and handles Lifecycle actions
 * (Retirement, Termination) and Transfer Approvals.
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage'); 

$id = Sanitize::positiveInt($_GET['id'] ?? null);
if (!$id) {
    ErrorHandler::abort(404, 'Member not found.');
}

// Ensure scope
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    $m = Database::fetchOne("SELECT district_id, taluk_id FROM members WHERE id = ?", [$id]);
    if ($unit && $m) {
        if ($unit['unit_type'] === 'district' && $m['district_id'] != $unit['district_id']) {
            ErrorHandler::abort(403, 'Member is outside your authorized district.');
        }
        if ($unit['unit_type'] === 'taluk' && $m['taluk_id'] != $unit['taluk_id']) {
            ErrorHandler::abort(403, 'Member is outside your authorized taluk.');
        }
    }
}

$isStateAdmin = (RBAC::getHighestScopeType($currentUserId) === 'state');

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);
    
    if ($action === 'lifecycle_update' && $isStateAdmin) {
        $lAction = Sanitize::inArray($_POST['lifecycle_action'] ?? '', ['retired', 'terminated']);
        $effDate = Sanitize::date($_POST['effective_date'] ?? '');
        $reason  = trim(Sanitize::string($_POST['reason_category'] ?? '', 100));
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 1000));
        
        if ($lAction && $effDate && $reason !== '') {
            Database::execute(
                "INSERT INTO member_lifecycle_events (member_id, action, effective_date, reason_category, remarks, approved_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$id, $lAction, $effDate, $reason, $remarks !== '' ? $remarks : null, $currentUserId]
            );
            Database::execute(
                "UPDATE members SET membership_status = ? WHERE id = ?",
                [$lAction, $id]
            );
            AuditLogger::log('UPDATE', 'members', $id, null, ['lifecycle' => $lAction, 'reason' => $reason]);
            Session::flash('success', 'Lifecycle action recorded successfully.');
        } else {
            Session::flash('error', 'Please fill all required lifecycle fields.');
        }
        header("Location: /admin/member-view.php?id=$id");
        exit;
    }
    
    if ($action === 'approve_transfer' && $isStateAdmin) {
        $transferId = Sanitize::positiveInt($_POST['transfer_id'] ?? null);
        $decision = Sanitize::inArray($_POST['decision'] ?? '', ['approved', 'rejected']);
        
        if ($transferId && $decision) {
            $t = Database::fetchOne("SELECT * FROM member_transfers WHERE id = ? AND status = 'pending'", [$transferId]);
            if ($t) {
                Database::execute(
                    "UPDATE member_transfers SET status = ?, approved_by = ?, actioned_at = NOW() WHERE id = ?",
                    [$decision, $currentUserId, $transferId]
                );
                
                if ($decision === 'approved') {
                    // Update location, but DO NOT assign new membership number yet (per rules)
                    Database::execute(
                        "UPDATE members SET district_id = ?, taluk_id = ?, gp_id = ? WHERE id = ?",
                        [$t['new_district_id'], $t['new_taluk_id'], $t['new_gp_id'], $id]
                    );
                }
                AuditLogger::log('UPDATE', 'member_transfers', $transferId, null, ['decision' => $decision]);
                Session::flash('success', 'Transfer request ' . $decision . '.');
            }
        }
        header("Location: /admin/member-view.php?id=$id");
        exit;
    }
}

// Fetch member data
$member = Database::fetchOne(
    "SELECT m.*, mp.*, d.name AS district_name, t.name AS taluk_name, gp.name AS gp_name
     FROM members m
     LEFT JOIN member_profiles mp ON mp.member_id = m.id
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t ON t.id = m.taluk_id
     LEFT JOIN gram_panchayatis gp ON gp.id = m.gp_id
     WHERE m.id = ?",
    [$id]
);

$payments = Database::fetchAll(
    "SELECT p.*, y.financial_year, pr.receipt_no
     FROM membership_payments p
     JOIN membership_years y ON y.id = p.membership_year_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.member_id = ?
     ORDER BY y.start_date DESC",
    [$id]
);

$transfers = Database::fetchAll(
    "SELECT t.*, d1.name as old_d, t1.name as old_t, d2.name as new_d, t2.name as new_t, u.username as requested_by_name
     FROM member_transfers t
     LEFT JOIN districts d1 ON d1.id = t.old_district_id
     LEFT JOIN taluks t1 ON t1.id = t.old_taluk_id
     LEFT JOIN districts d2 ON d2.id = t.new_district_id
     LEFT JOIN taluks t2 ON t2.id = t.new_taluk_id
     LEFT JOIN users u ON u.id = t.requested_by
     WHERE t.member_id = ?
     ORDER BY t.requested_at DESC",
    [$id]
);

$lifecycleEvents = Database::fetchAll(
    "SELECT l.*, u.username as approved_by_name
     FROM member_lifecycle_events l
     JOIN users u ON u.id = l.approved_by
     WHERE l.member_id = ?
     ORDER BY l.created_at DESC",
    [$id]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member View — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f7fa; color: #1a1a2e; margin: 0; padding: 0 0 60px; }
        header { background: #1a3a6b; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        header h1 { font-size: 1.1rem; margin: 0; }
        header nav a { color: #cfe0ff; text-decoration: none; font-size: 0.85rem; margin-left: 14px; }
        header nav a.active { color: #fff; font-weight: 700; text-decoration: underline; }
        
        main { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
        .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
        .panel h2 { font-size: 1.2rem; color: #1a3a6b; margin: 0 0 16px; border-bottom: 2px solid #eef1f5; padding-bottom: 12px; }
        .panel h3 { font-size: 1.05rem; color: #33415c; margin: 0 0 12px; }
        
        .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
        
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 16px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f5; }
        th { color: #556; font-weight: 600; background: #f8fafc; }
        
        .btn { display: inline-block; background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #142c52; }
        
        .row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
        .row > div { flex: 1; min-width: 200px; }
        label { font-weight: 600; font-size: 0.8rem; color: #556; display: block; margin-bottom: 4px; }
        .val { font-size: 0.95rem; color: #1a1a2e; }
        
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; }
        
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; }
        .badge.active { background: #e7f6ec; color: #1e6b3a; }
        .badge.retired, .badge.resigned { background: #fdf6e8; color: #8a5a22; }
        .badge.terminated, .badge.deceased { background: #fdecea; color: #a12622; }
    </style>
</head>
<body>
<header>
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — Member View</h1>
    <nav>
        <a href="/admin/members.php">← Back to Members List</a>
    </nav>
</header>
<main>
    <?php if ($successMsg): ?><div class="msg success"><?= Sanitize::html($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="msg error"><?= Sanitize::html($errorMsg) ?></div><?php endif; ?>

    <div class="panel">
        <h2><?= Sanitize::html($member['name']) ?> <span style="font-size: 0.9rem; color: #666; font-weight:normal;">(#<?= Sanitize::html($member['member_no']) ?>)</span></h2>
        
        <div class="row">
            <div><label>Membership Status</label><div class="val"><span class="badge <?= Sanitize::html($member['membership_status']) ?>"><?= Sanitize::html(strtoupper($member['membership_status'])) ?></span></div></div>
            <div><label>Designation</label><div class="val"><?= Sanitize::html($member['designation'] ?: '—') ?></div></div>
            <div><label>KGID Number</label><div class="val"><?= Sanitize::html($member['kgid_no'] ?? '—') ?></div></div>
        </div>
        <div class="row">
            <div><label>Personal Mobile</label><div class="val"><?= Sanitize::html($member['personal_mobile'] ?? '—') ?></div></div>
            <div><label>Personal Email</label><div class="val"><?= Sanitize::html($member['personal_email'] ?? '—') ?></div></div>
            <div><label>Date of Birth</label><div class="val"><?= Sanitize::html($member['date_of_birth'] ?? '—') ?></div></div>
            <div><label>Blood Group</label><div class="val"><?= Sanitize::html($member['blood_group'] ?? '—') ?></div></div>
        </div>
        <div class="row">
            <div><label>District</label><div class="val"><?= Sanitize::html($member['district_name'] ?? '—') ?></div></div>
            <div><label>Taluk</label><div class="val"><?= Sanitize::html($member['taluk_name'] ?? '—') ?></div></div>
            <div><label>Gram Panchayat</label><div class="val"><?= Sanitize::html($member['gp_name'] ?? '—') ?></div></div>
        </div>
        <div style="margin-top: 16px;">
            <a href="/admin/members.php?edit=<?= $id ?>" class="btn" style="background:#556;">Edit Profile</a>
        </div>
    </div>

    <div class="panel">
        <h3>Payment History</h3>
        <?php if (empty($payments)): ?>
            <p class="hint">No payments recorded for this member.</p>
        <?php else: ?>
        <table>
            <tr><th>Financial Year</th><th>Amount</th><th>Status</th><th>Mode</th><th>Reference</th><th>Receipt</th></tr>
            <?php foreach($payments as $p): ?>
            <tr>
                <td><?= Sanitize::html($p['financial_year']) ?></td>
                <td>₹<?= number_format((float)$p['amount'], 2) ?></td>
                <td><span class="badge active"><?= Sanitize::html(ucfirst($p['status'])) ?></span></td>
                <td><?= Sanitize::html(($p['payment_mode'] === 'online' || $p['payment_mode'] === 'razorpay') ? 'Razorpay' : ucfirst($p['payment_mode'])) ?></td>
                <td><?= Sanitize::html($p['gateway_payment_id'] ?? $p['offline_reference'] ?? '—') ?></td>
                <td><?= $p['receipt_no'] ? '<a href="/admin/receipt.php?id='.$p['id'].'" target="_blank" style="color:#1a3a6b;">'.$p['receipt_no'].'</a>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h3>Location Transfer Requests</h3>
        <?php if (empty($transfers)): ?>
            <p class="hint" style="color:#888;">No transfer history.</p>
        <?php else: ?>
        <table>
            <tr><th>Date</th><th>From</th><th>To</th><th>Status</th><th>Actions</th></tr>
            <?php foreach($transfers as $t): ?>
            <tr>
                <td><?= Sanitize::html(date('Y-m-d', strtotime($t['requested_at']))) ?></td>
                <td><?= Sanitize::html(($t['old_d'] ?? '—').' / '.($t['old_t'] ?? '—')) ?></td>
                <td><?= Sanitize::html(($t['new_d'] ?? '—').' / '.($t['new_t'] ?? '—')) ?></td>
                <td><span class="badge <?= $t['status'] === 'approved' ? 'active' : ($t['status'] === 'rejected' ? 'terminated' : 'inactive') ?>"><?= Sanitize::html(ucfirst($t['status'])) ?></span></td>
                <td>
                    <?php if ($t['status'] === 'pending' && $isStateAdmin): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Confirm decision for this transfer?');">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="approve_transfer">
                        <input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                        <button type="submit" name="decision" value="approved" class="btn" style="background:#1e6b3a; padding:4px 8px; font-size:0.8rem;">Approve</button>
                        <button type="submit" name="decision" value="rejected" class="btn" style="background:#a12622; padding:4px 8px; font-size:0.8rem;">Reject</button>
                    </form>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h3>Lifecycle Events</h3>
        <?php if (empty($lifecycleEvents)): ?>
            <p class="hint" style="color:#888;">No lifecycle events recorded.</p>
        <?php else: ?>
        <table>
            <tr><th>Effective Date</th><th>Action</th><th>Reason</th><th>Remarks</th><th>Approved By</th></tr>
            <?php foreach($lifecycleEvents as $l): ?>
            <tr>
                <td><?= Sanitize::html($l['effective_date']) ?></td>
                <td><span class="badge <?= $l['action'] === 'retired' ? 'retired' : 'terminated' ?>"><?= Sanitize::html(ucfirst($l['action'])) ?></span></td>
                <td><?= Sanitize::html($l['reason_category']) ?></td>
                <td><?= Sanitize::html($l['remarks'] ?? '—') ?></td>
                <td><?= Sanitize::html($l['approved_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <?php if ($isStateAdmin && in_array($member['membership_status'], ['active', 'inactive'])): ?>
        <div style="background: #fdf6e8; padding: 16px; border: 1px solid #f2e2c4; border-radius: 6px; margin-top: 24px;">
            <h4 style="margin-top:0; color: #8a5a22;">Record Retirement or Termination</h4>
            <p style="font-size: 0.85rem; color: #666; margin-bottom: 16px;">This action will immediately revoke the member's portal access and lock their membership status. This action is auditable.</p>
            <form method="post" onsubmit="return confirm('Are you sure you want to change the lifecycle status of this member? This action is permanent.');">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="action" value="lifecycle_update">
                <div class="row">
                    <div>
                        <label>Action</label>
                        <select name="lifecycle_action" required>
                            <option value="">— Select —</option>
                            <option value="retired">Retire Member</option>
                            <option value="terminated">Terminate Member</option>
                        </select>
                    </div>
                    <div>
                        <label>Effective Date</label>
                        <input type="date" name="effective_date" required max="<?= date('Y-m-d') ?>">
                    </div>
                    <div>
                        <label>Reason / Category</label>
                        <input type="text" name="reason_category" required placeholder="e.g. Superannuation">
                    </div>
                </div>
                <div class="row">
                    <div style="flex:2;">
                        <label>Remarks</label>
                        <textarea name="remarks" rows="2" placeholder="Any additional details..."></textarea>
                    </div>
                </div>
                <button type="submit" class="btn" style="background: #a12622;">Submit Lifecycle Action</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
