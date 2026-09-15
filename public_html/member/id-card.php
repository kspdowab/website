<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card
 * ============================================================
 * Section 8: Digital ID Card (Front & Back, .jpg & .pdf download, print)
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$pageTitle  = 'Digital ID Card';
$activeMenu = 'id-card';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Digital ID', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$profile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]) ?: [];
$currentYear = Membership::getCurrentYear();
$fy = $currentYear['financial_year'] ?? date('Y') . '-' . (date('y') + 1);

$photoUrl = null;
if (!empty($portalMember['photo_path']) && is_file(PUBLIC_HTML . '/' . ltrim($portalMember['photo_path'], '/'))) {
    $photoUrl = '/' . ltrim($portalMember['photo_path'], '/');
}
?>

<!-- Load html2canvas for instant 300-DPI JPG export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<style>
/* Card Display Styles */
.id-card-wrapper {
    width: 100%;
    max-width: 440px;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 16px 40px -8px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(226, 232, 240, 0.8);
    overflow: hidden;
    position: relative;
    user-select: none;
    transition: transform 0.2s, box-shadow 0.2s;
}
.id-card-wrapper:hover {
    box-shadow: 0 20px 48px -8px rgba(15, 23, 42, 0.24);
}
.id-card-header-gradient {
    background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 40%, #2563eb 80%, #4f46e5 100%);
    color: #ffffff;
    padding: 18px 20px 14px;
    text-align: center;
    position: relative;
    border-bottom: 3px solid #facc15; /* Gold stripe */
}
.id-card-header-title {
    font-size: 0.96rem;
    font-weight: 800;
    letter-spacing: 0.4px;
    line-height: 1.25;
    text-transform: uppercase;
}
.id-card-header-sub {
    font-size: 0.68rem;
    color: #e0e7ff;
    margin-top: 3px;
    letter-spacing: 0.3px;
}
.id-card-body {
    padding: 20px 22px;
    background: #ffffff;
    position: relative;
}
.id-card-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-25deg);
    font-size: 3.5rem;
    font-weight: 900;
    color: rgba(30, 64, 175, 0.04);
    pointer-events: none;
    white-space: nowrap;
    letter-spacing: 4px;
}
.id-photo-container {
    width: 96px;
    height: 116px;
    border-radius: 10px;
    background: #f8fafc;
    border: 2px solid #cbd5e1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
}
.id-photo-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.id-grid-table {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px 12px;
    font-size: 0.81rem;
    background: #f8fafc;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}
.id-grid-item-label {
    color: #64748b;
    font-size: 0.7rem;
    display: block;
    margin-bottom: 1px;
}
.id-grid-item-val {
    color: #0f172a;
    font-weight: 700;
    word-break: break-word;
}
.id-card-footer {
    background: #f1f5f9;
    border-top: 1px solid #e2e8f0;
    padding: 10px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.72rem;
    color: #64748b;
}

/* Back Side Styles */
.id-back-header {
    background: #0f172a;
    color: #ffffff;
    padding: 14px 18px;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #3b82f6;
}
.id-back-body {
    padding: 18px 20px;
    font-size: 0.78rem;
    color: #33415c;
    line-height: 1.45;
}

/* Print CSS */
@media print {
    body * { visibility: hidden; }
    #printableCardArea, #printableCardArea * { visibility: visible; }
    #printableCardArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        display: flex;
        flex-direction: row;
        gap: 20px;
        justify-content: center;
        background: none;
        box-shadow: none;
    }
    .no-print { display: none !important; }
}
</style>

<div class="page-header-row no-print">
    <div>
        <h1 class="page-heading-title">Official Digital ID Card</h1>
        <p class="page-heading-subtitle">Verified membership identity card for Karnataka State PDO Welfare Association</p>
    </div>

    <!-- Download & Print Action Buttons -->
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <!-- 1. Download JPG -->
        <button type="button" class="btn btn-primary" onclick="downloadCardAsJpg();" id="btnDownloadJpg" style="background:#059669; border-color:#059669; display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download Image (.JPG)
        </button>

        <!-- 2. Download PDF -->
        <a href="/member/id-card-pdf.php?dl=1" class="btn btn-primary" style="background:#2563eb; border-color:#2563eb; display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Download PDF (.PDF)
        </a>

        <!-- 3. Print -->
        <button type="button" class="btn btn-outline" onclick="window.print();" style="display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Card
        </button>
    </div>
</div>

<!-- Helpful guidance notice -->
<div class="no-print" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:12px 18px; border-radius:10px; margin-bottom:24px; font-size:0.86rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
        💡 <strong>Card Guidance:</strong> Your digital ID card is formatted for official identification. If your photo or blood group is missing, update it in <a href="/member/profile.php" style="color:#1d4ed8; text-decoration:underline; font-weight:700;">My Profile</a>.
    </div>
    <a href="/member/profile.php" class="btn btn-outline btn-sm" style="background:#ffffff;">Go to Profile &rarr;</a>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     INTERACTIVE CARDS CONTAINER (FRONT & BACK DISPLAY)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div id="printableCardArea" style="display:flex; justify-content:center; gap:32px; padding:10px 0 40px; flex-wrap:wrap;">

    <!-- ───────────────────────────────────────────────────────────────────
         SIDE 1: FRONT SIDE (ID CARD)
         ─────────────────────────────────────────────────────────────────── -->
    <div>
        <div style="text-align:center; margin-bottom:8px; font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;" class="no-print">
            Front Face (ಮುಂಭಾಗ)
        </div>

        <div class="id-card-wrapper" id="idCardFront">
            <!-- Header with Blue & Gold accents -->
            <div class="id-card-header-gradient">
                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:1.5px; opacity:0.9;">Karnataka State</div>
                <div class="id-card-header-title">
                    Panchayat Development Officer<br>Welfare Association (R)
                </div>
                <div class="id-card-header-sub">
                    Reg. No. DRB/SOR/534/2012-13 • Bengaluru • kspdowa.org
                </div>
            </div>

            <!-- Card Body -->
            <div class="id-card-body">
                <div class="id-card-watermark">KSPDOWA</div>

                <!-- Top Row: Photo + Name & Designation -->
                <div style="display:flex; gap:16px; align-items:center; margin-bottom:16px; position:relative; z-index:1;">
                    <!-- Portrait Photo -->
                    <div class="id-photo-container">
                        <?php if ($photoUrl): ?>
                            <img src="<?= Sanitize::attr($photoUrl) ?>" alt="Member Photo">
                        <?php else: ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <span style="font-size:0.62rem; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-top:2px;">Photo</span>
                        <?php endif; ?>
                    </div>

                    <div style="flex:1;">
                        <div style="font-size:1.2rem; font-weight:800; color:#0f172a; line-height:1.25;">
                            <?= Sanitize::html($portalMember['name'] ?? '') ?>
                        </div>
                        <div style="font-size:0.86rem; font-weight:700; color:#2563eb; margin-top:3px;">
                            <?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?>
                        </div>
                        <div style="margin-top:7px; display:flex; align-items:center; gap:8px;">
                            <span style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-family:monospace; font-weight:800; font-size:0.85rem; padding:2px 8px; border-radius:6px;">
                                <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
                            </span>
                            <span style="background:#dcfce7; color:#166534; font-size:0.68rem; font-weight:700; padding:2px 6px; border-radius:4px;">
                                ✓ VERIFIED
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Detail Grid -->
                <div class="id-grid-table" style="position:relative; z-index:1;">
                    <div>
                        <span class="id-grid-item-label">KGID No:</span>
                        <span class="id-grid-item-val"><?= Sanitize::html($profile['kgid_no'] ?? '—') ?></span>
                    </div>

                    <div>
                        <span class="id-grid-item-label">Blood Group:</span>
                        <span class="id-grid-item-val" style="color:#dc2626; font-size:0.88rem;"><?= Sanitize::html($profile['blood_group'] ?? '—') ?></span>
                    </div>

                    <div>
                        <span class="id-grid-item-label">District:</span>
                        <span class="id-grid-item-val"><?= Sanitize::html($portalMember['district_name'] ?? '—') ?></span>
                    </div>

                    <div>
                        <span class="id-grid-item-label">Taluk:</span>
                        <span class="id-grid-item-val"><?= Sanitize::html($portalMember['taluk_name'] ?? '—') ?></span>
                    </div>

                    <div>
                        <span class="id-grid-item-label">Gram Panchayati:</span>
                        <span class="id-grid-item-val"><?= Sanitize::html($portalMember['gp_name'] ?? '—') ?></span>
                    </div>

                    <div>
                        <span class="id-grid-item-label">Valid For FY:</span>
                        <span class="id-grid-item-val" style="color:#15803d;"><?= Sanitize::html($fy) ?></span>
                    </div>

                    <?php if (!empty($profile['highest_qualification'])): ?>
                    <div style="grid-column: 1 / -1; border-top:1px dashed #e2e8f0; padding-top:6px; margin-top:2px;">
                        <span class="id-grid-item-label">Qualification:</span>
                        <span class="id-grid-item-val"><?= Sanitize::html($profile['highest_qualification']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card Footer -->
            <div class="id-card-footer">
                <div>● Official Welfare Association Member</div>
                <div style="font-weight:800; color:#1e40af; letter-spacing:0.5px;">ACTIVE STATUS</div>
            </div>
        </div>
    </div>

    <!-- ───────────────────────────────────────────────────────────────────
         SIDE 2: BACK SIDE (TERMS & EMERGENCY)
         ─────────────────────────────────────────────────────────────────── -->
    <div>
        <div style="text-align:center; margin-bottom:8px; font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;" class="no-print">
            Back Face (ಹಿಂಭಾಗ)
        </div>

        <div class="id-card-wrapper" id="idCardBack">
            <div class="id-back-header">
                MEMBERSHIP TERMS &amp; ASSOCIATION CONTACT
            </div>

            <div class="id-back-body">
                <div style="font-weight:700; color:#1e40af; font-size:0.8rem; margin-bottom:6px; text-transform:uppercase;">
                    Emergency Contact Information
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; margin-bottom:12px;">
                    <div>
                        <span style="color:#64748b; font-size:0.7rem; display:block;">Registered Mobile:</span>
                        <strong><?= Sanitize::html($profile['personal_mobile'] ?? '—') ?></strong>
                    </div>
                    <div>
                        <span style="color:#64748b; font-size:0.7rem; display:block;">Native District:</span>
                        <strong><?= Sanitize::html($profile['native_district'] ?? '—') ?></strong>
                    </div>
                </div>

                <div style="border-top:1px dashed #e2e8f0; padding-top:10px; margin-bottom:12px;">
                    <div style="font-weight:700; color:#1e40af; font-size:0.78rem; margin-bottom:4px;">
                        Association Central Office:
                    </div>
                    <div style="color:#475569; font-size:0.75rem; line-height:1.4;">
                        Karnataka State Panchayat Development Officer Welfare Association (R)<br>
                        Bengaluru, Karnataka • Helpline: 9036880026 • Web: kspdowa.org
                    </div>
                </div>

                <div style="border-top:1px dashed #e2e8f0; padding-top:8px; color:#94a3b8; font-size:0.68rem; line-height:1.4;">
                    1. This identity card is the property of KSPDOWA and is non-transferable.<br>
                    2. If found, please return to the nearest Taluk/District Association unit.<br>
                    3. Validity is subject to annual membership renewal.
                </div>

                <!-- Seal / Signatory -->
                <div style="margin-top:16px; display:flex; justify-content:flex-end; text-align:center;">
                    <div>
                        <div style="font-size:0.72rem; font-weight:800; color:#0f172a;">Sd/-</div>
                        <div style="font-size:0.72rem; font-weight:700; color:#1e40af;">General Secretary</div>
                        <div style="font-size:0.65rem; color:#64748b;">KSPDOWA State Committee</div>
                    </div>
                </div>
            </div>

            <div class="id-card-footer" style="background:#f8fafc; font-size:0.68rem;">
                <div>DRB/SOR/534/2012-13</div>
                <div>STATE OF KARNATAKA</div>
            </div>
        </div>
    </div>
</div>

<script>
// High-resolution 300-DPI JPG Image Download
function downloadCardAsJpg() {
    const cardEl = document.getElementById('idCardFront');
    const btn = document.getElementById('btnDownloadJpg');
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = 'Generating High-Res Image...';

    html2canvas(cardEl, {
        scale: 3, // 300 DPI high resolution
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff'
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = 'KSPDOWA-ID-<?= Sanitize::attr($portalMember['member_no'] ?? 'CARD') ?>.jpg';
        link.href = canvas.toDataURL('image/jpeg', 0.95);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        btn.disabled = false;
        btn.innerHTML = originalText;
    }).catch(err => {
        alert('Could not generate JPG image: ' + err);
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
