<?php
/**
 * KSPDOWA — Public Events & Summits
 * ============================================================
 * Displays upcoming assemblies, conferences, rallies, and state
 * conventions with date cards, location, and details.
 * Public access displays 'public' events; logged in members
 * additionally view 'member' level events.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$isLoggedIn = Auth::isLoggedIn();
$currentUserId = Auth::getCurrentUserId();

// Build access query
$allowedLevels = ["'public'"];
if ($isLoggedIn) {
    $allowedLevels[] = "'member'";
    if (RBAC::hasRole($currentUserId, 'State Super Admin') || RBAC::hasRole($currentUserId, 'State Admin')) {
        $allowedLevels[] = "'officer'";
        $allowedLevels[] = "'admin'";
    }
}
$levelsSql = implode(',', $allowedLevels);

// Fetch upcoming & ongoing events
$upcomingEvents = Database::fetchAll(
    "SELECT * FROM events 
     WHERE access_level IN ({$levelsSql}) 
       AND status IN ('upcoming', 'ongoing')
     ORDER BY event_date ASC"
);

// Fetch completed past events
$pastEvents = Database::fetchAll(
    "SELECT * FROM events 
     WHERE access_level IN ({$levelsSql}) 
       AND status = 'completed'
     ORDER BY event_date DESC 
     LIMIT 12"
);

$pageTitle = 'Association Events & Conventions — KSPDOWA';
require_once __DIR__ . '/includes/partials/header.php';
?>

<div class="page-banner" style="background:linear-gradient(135deg, var(--navy-900, #0f172a) 0%, var(--blue-900, #1e3a8a) 100%); color:#fff; padding:48px 20px; text-align:center;">
    <div class="container" style="max-width:1100px; margin:0 auto;">
        <span style="display:inline-block; padding:4px 14px; background:rgba(255,255,255,0.12); border-radius:20px; font-size:0.82rem; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Official Calendar</span>
        <h1 style="font-size:2.4rem; font-weight:800; margin:0 0 10px 0; letter-spacing:-0.5px;">Association Events &amp; Conventions</h1>
        <p style="font-size:1.05rem; opacity:0.85; max-width:680px; margin:0 auto; line-height:1.6;">
            State assemblies, regional delegate conferences, workshops, and official welfare programs across Karnataka.
        </p>
    </div>
</div>

<main class="container" style="max-width:1100px; margin:40px auto; padding:0 20px;">
    <!-- UPCOMING EVENTS SECTION -->
    <div style="margin-bottom:50px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid var(--border-color, #e2e8f0); padding-bottom:12px; margin-bottom:24px;">
            <h2 style="font-size:1.5rem; font-weight:700; color:var(--text-main, #0f172a); margin:0;">
                Upcoming &amp; Active Events
                <span style="font-size:0.9rem; font-weight:500; color:var(--blue-600, #2563eb); margin-left:8px;">(<?= count($upcomingEvents) ?> Scheduled)</span>
            </h2>
        </div>

        <?php if (empty($upcomingEvents)): ?>
            <div style="text-align:center; padding:50px 20px; background:#f8fafc; border-radius:12px; border:1px dashed #cbd5e1;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <h3 style="margin:0 0 6px 0; font-size:1.15rem; color:#475569;">No Upcoming Events Scheduled</h3>
                <p style="margin:0; color:#64748b; font-size:0.9rem;">Please check back soon for updates on state committee summits and district rallies.</p>
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:24px;">
                <?php foreach ($upcomingEvents as $ev): 
                    $ts = strtotime($ev['event_date'] ?? 'now');
                    $day = date('d', $ts);
                    $mon = date('M', $ts);
                    $time = date('h:i A', $ts);
                    $year = date('Y', $ts);
                    $isOngoing = ($ev['status'] ?? '') === 'ongoing';
                ?>
                <div style="background:#fff; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden; display:flex; flex-direction:column; transition:transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='none'; this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';">
                    <div style="display:flex; border-bottom:1px solid #f1f5f9;">
                        <!-- Date box -->
                        <div style="background:<?= $isOngoing ? '#059669' : '#1e3a8a' ?>; color:#fff; padding:18px 16px; text-align:center; min-width:85px; display:flex; flex-direction:column; justify-content:center;">
                            <span style="font-size:1.6rem; font-weight:800; line-height:1;"><?= $day ?></span>
                            <span style="font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-top:2px;"><?= $mon ?></span>
                            <span style="font-size:0.75rem; opacity:0.8;"><?= $year ?></span>
                        </div>
                        <div style="padding:16px; flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                                <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:<?= $isOngoing ? '#059669' : '#2563eb' ?>;">
                                    <?= $isOngoing ? '● Ongoing Today' : 'Upcoming' ?>
                                </span>
                                <span style="font-size:0.72rem; padding:2px 8px; border-radius:12px; background:#f1f5f9; color:#475569; text-transform:capitalize;">
                                    <?= Sanitize::html($ev['access_level']) ?>
                                </span>
                            </div>
                            <h3 style="font-size:1.05rem; font-weight:700; margin:0 0 6px 0; color:#0f172a; line-height:1.35;">
                                <?= Sanitize::html($ev['title']) ?>
                            </h3>
                            <div style="font-size:0.82rem; color:#64748b; display:flex; align-items:center; gap:4px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?= $time ?>
                            </div>
                        </div>
                    </div>
                    <div style="padding:16px; flex:1; display:flex; flex-direction:column; justify-content:space-between; background:#fafafa;">
                        <div style="margin-bottom:12px;">
                            <?php if (!empty($ev['location'])): ?>
                            <div style="font-size:0.84rem; color:#334155; margin-bottom:8px; display:flex; align-items:flex-start; gap:6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2563eb" style="flex-shrink:0; margin-top:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span><?= Sanitize::html($ev['location']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($ev['description'])): ?>
                            <p style="font-size:0.85rem; color:#475569; margin:0; line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;">
                                <?= Sanitize::html($ev['description']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- RECENT CONCLUDED EVENTS SECTION -->
    <?php if (!empty($pastEvents)): ?>
    <div style="margin-top:50px;">
        <div style="border-bottom:2px solid var(--border-color, #e2e8f0); padding-bottom:12px; margin-bottom:24px;">
            <h2 style="font-size:1.35rem; font-weight:700; color:#475569; margin:0;">
                Concluded Association Assemblies &amp; Summits
            </h2>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:18px;">
            <?php foreach ($pastEvents as $pe): 
                $pts = strtotime($pe['event_date'] ?? 'now');
            ?>
            <div style="background:#fff; border-radius:8px; border:1px solid #e2e8f0; padding:16px; opacity:0.9;">
                <div style="font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom:4px;">
                    <?= date('d M Y', $pts) ?> · <?= Sanitize::html($pe['location'] ?? 'Karnataka') ?>
                </div>
                <h4 style="font-size:0.95rem; font-weight:700; color:#1e293b; margin:0 0 6px 0;">
                    <?= Sanitize::html($pe['title']) ?>
                </h4>
                <?php if (!empty($pe['description'])): ?>
                <p style="font-size:0.8rem; color:#64748b; margin:0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                    <?= Sanitize::html($pe['description']) ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php
require_once __DIR__ . '/includes/partials/footer.php';
