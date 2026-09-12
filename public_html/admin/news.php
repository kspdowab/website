<?php
/**
 * KSPDOWA — Admin: News Management
 * ============================================================
 * Add / edit / publish / archive news items. Gated by RBAC
 * permission 'news.manage' (seeded in Phase 0 — see
 * seeds/001_roles_permissions.sql).
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'news', 'manage');

function news_unique_slug(string $title, ?int $excludeId = null): string
{
    $base = Sanitize::slug($title, 250);
    if ($base === '') {
        $base = 'news';
    }

    $slug  = $base;
    $n     = 1;

    while (true) {
        $sql    = 'SELECT id FROM news WHERE slug = ?' . ($excludeId ? ' AND id != ?' : '');
        $params = $excludeId ? [$slug, $excludeId] : [$slug];
        $row    = Database::fetchOne($sql, $params);

        if (!$row) {
            return $slug;
        }

        $n++;
        $slug = $base . '-' . $n;
    }
}

// ---------------------------------------------------------------------------
// POST: create / update / set_status
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'set_status') {
        $id        = Sanitize::positiveInt($_POST['id'] ?? null);
        $newStatus = Sanitize::inArray($_POST['status'] ?? '', ['draft', 'published', 'archived']);

        if ($id === false || $newStatus === false) {
            Session::flash('error', 'Invalid request.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM news WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'News item not found.');
            } else {
                $publishedAt = $existing['published_at'];
                if ($newStatus === 'published' && $publishedAt === null) {
                    $publishedAt = date('Y-m-d H:i:s');
                }

                Database::execute(
                    'UPDATE news SET status = ?, published_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
                    [$newStatus, $publishedAt, $currentUserId, $id]
                );
                AuditLogger::log('UPDATE', 'news', $id, $existing, ['status' => $newStatus]);
                Session::flash('success', 'Status updated.');
            }
        }

        header('Location: /admin/news.php');
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $id         = $action === 'update' ? Sanitize::positiveInt($_POST['id'] ?? null) : null;
        $title      = trim(Sanitize::string($_POST['title'] ?? '', 300));
        $content    = trim((string) ($_POST['content'] ?? ''));
        $language   = Sanitize::inArray($_POST['language'] ?? '', ['en', 'kn']);
        $categoryId = Sanitize::positiveInt($_POST['category_id'] ?? null);
        $status     = Sanitize::inArray($_POST['status'] ?? '', ['draft', 'published', 'archived']);

        $errors = [];
        if ($title === '')                 $errors[] = 'Title is required.';
        if ($content === '')               $errors[] = 'Content is required.';
        if ($language === false)           $errors[] = 'Please choose a language.';
        if ($status === false)             $errors[] = 'Please choose a status.';
        if ($action === 'update' && $id === false) $errors[] = 'Invalid news reference.';

        if ($categoryId !== false) {
            $cat = Database::fetchOne("SELECT id FROM news_categories WHERE id = ? AND status = 'active'", [$categoryId]);
            if (!$cat) {
                $errors[] = 'Selected category was not found.';
                $categoryId = null;
            }
        } else {
            $categoryId = null;
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
            header('Location: /admin/news.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        if ($action === 'create') {
            $slug        = news_unique_slug($title);
            $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

            Database::execute(
                'INSERT INTO news (title, slug, content, language, category_id, status, published_at, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$title, $slug, $content, $language, $categoryId, $status, $publishedAt, $currentUserId]
            );
            $newId = (int) Database::lastInsertId();
            AuditLogger::log('CREATE', 'news', $newId, null, ['title' => $title, 'status' => $status]);
            Session::flash('success', 'News item created.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM news WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'News item not found.');
                header('Location: /admin/news.php');
                exit;
            }

            $slug        = $existing['title'] === $title ? $existing['slug'] : news_unique_slug($title, $id);
            $publishedAt = $existing['published_at'];
            if ($status === 'published' && $publishedAt === null) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            Database::execute(
                'UPDATE news SET title = ?, slug = ?, content = ?, language = ?, category_id = ?,
                    status = ?, published_at = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ?',
                [$title, $slug, $content, $language, $categoryId, $status, $publishedAt, $currentUserId, $id]
            );
            AuditLogger::log('UPDATE', 'news', $id, $existing, ['title' => $title, 'status' => $status]);
            Session::flash('success', 'News item updated.');
        }

        header('Location: /admin/news.php');
        exit;
    }

    ErrorHandler::abort(400, 'Unknown action.');
}

// ---------------------------------------------------------------------------
// GET: render list + form
// ---------------------------------------------------------------------------
$categories = Database::fetchAll("SELECT id, name FROM news_categories WHERE status = 'active' ORDER BY name");
$newsItems  = Database::fetchAll(
    "SELECT n.*, c.name AS category_name FROM news n
     LEFT JOIN news_categories c ON c.id = n.category_id
     ORDER BY n.created_at DESC"
);

$editRow = null;
$editId  = Sanitize::positiveInt($_GET['edit'] ?? null);
if ($editId !== false) {
    $editRow = Database::fetchOne('SELECT * FROM news WHERE id = ?', [$editId]);
}

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f7fa; color: #1a1a2e; margin: 0; padding: 0 0 60px; }
        header { background: #1a3a6b; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        header h1 { font-size: 1.1rem; margin: 0; }
        header .links a { color: #cfe0ff; text-decoration: none; font-size: 0.85rem; margin-left: 14px; }
        main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
        .panel h2 { font-size: 1rem; color: #1a3a6b; margin: 0 0 16px; }
        .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 4px; }
        input[type="text"], select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; font-family: inherit; }
        textarea { min-height: 160px; resize: vertical; }
        .row { display: flex; gap: 16px; flex-wrap: wrap; }
        .row > div { flex: 1; min-width: 200px; }
        button, .btn { background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 10px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; margin-top: 18px; }
        button:hover, .btn:hover { background: #142c52; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f5; vertical-align: top; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; }
        .badge.published { background: #e7f6ec; color: #1e6b3a; }
        .badge.draft     { background: #f1f2f4; color: #666; }
        .badge.archived  { background: #fdecea; color: #a12622; }
        .actions form { display: inline; margin-right: 8px; }
        .actions a, .actions button.link { font-size: 0.8rem; margin-right: 8px; background: none; color: #1a3a6b; border: none; padding: 0; font-weight: 600; cursor: pointer; text-decoration: underline; }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 640px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr { border-bottom: 2px solid #e2e6ec; padding: 10px 0; }
            td { border: none; padding: 4px 0; }
            td::before { content: attr(data-label) ": "; font-weight: 600; color: #556; }
        }
    </style>
</head>
<body>
<header>
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — News</h1>
    <div class="links">
        <a href="/admin/office-bearers.php">Office Bearers</a>
        <a href="/admin/members.php">Members</a>
        <a href="/admin/users.php">Users &amp; Roles</a>
        <a href="/admin/membership-setup.php">Membership Setup</a>
        <a href="/logout.php">Logout</a>
    </div>
</header>
<main>
    <?php if ($successMsg): ?><div class="msg success" role="status"><?= Sanitize::html($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="msg error" role="alert"><?= Sanitize::html($errorMsg) ?></div><?php endif; ?>

    <div class="panel">
        <h2><?= $editRow ? 'Edit News Item' : 'Add News Item' ?></h2>

        <?php if (empty($categories)): ?>
            <div class="msg error" role="alert">No news categories exist. Run seeds/005_news_categories.sql.</div>
        <?php endif; ?>

        <form method="post" action="/admin/news.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

            <label for="title">Title</label>
            <input type="text" id="title" name="title" required
                   value="<?= Sanitize::attr($editRow['title'] ?? '') ?>">

            <label for="content">Content</label>
            <textarea id="content" name="content" required><?= Sanitize::html($editRow['content'] ?? '') ?></textarea>

            <div class="row">
                <div>
                    <label for="language">Language</label>
                    <select name="language" id="language">
                        <?php $lang = $editRow['language'] ?? 'en'; ?>
                        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="kn" <?= $lang === 'kn' ? 'selected' : '' ?>>ಕನ್ನಡ (Kannada)</option>
                    </select>
                </div>
                <div>
                    <label for="category_id">Category</label>
                    <select name="category_id" id="category_id">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= ($editRow && (int) ($editRow['category_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                                <?= Sanitize::html($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="status">Status</label>
                    <?php $st = $editRow['status'] ?? 'draft'; ?>
                    <select name="status" id="status">
                        <option value="draft"     <?= $st === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>Published</option>
                        <option value="archived"  <?= $st === 'archived' ? 'selected' : '' ?>>Archived</option>
                    </select>
                </div>
            </div>

            <button type="submit"><?= $editRow ? 'Save Changes' : 'Add News Item' ?></button>
            <?php if ($editRow): ?>
                <a class="btn" href="/admin/news.php" style="background:#888; text-decoration:none; display:inline-block;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>All News (<?= count($newsItems) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Title</th><th>Category</th><th>Language</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($newsItems as $item): ?>
                <tr>
                    <td data-label="Title"><?= Sanitize::html($item['title']) ?></td>
                    <td data-label="Category"><?= Sanitize::html($item['category_name'] ?? '—') ?></td>
                    <td data-label="Language"><?= $item['language'] === 'kn' ? 'ಕನ್ನಡ' : 'English' ?></td>
                    <td data-label="Status"><span class="badge <?= Sanitize::html($item['status']) ?>"><?= Sanitize::html($item['status']) ?></span></td>
                    <td data-label="Actions" class="actions">
                        <a href="/admin/news.php?edit=<?= (int) $item['id'] ?>">Edit</a>
                        <?php foreach (['draft', 'published', 'archived'] as $s): ?>
                            <?php if ($s !== $item['status']): ?>
                            <form method="post" action="/admin/news.php">
                                <?= CSRF::htmlField() ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="status" value="<?= $s ?>">
                                <button type="submit" class="link"><?= ucfirst($s) ?></button>
                            </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($newsItems)): ?>
                    <tr><td colspan="5">No news items yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</main>
</body>
</html>
