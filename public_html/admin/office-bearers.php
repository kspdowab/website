<?php
/**
 * KSPDOWA — Admin: Office Bearers Management
 * ============================================================
 * Add / edit / activate / terminate / delete State, District,
 * and Taluk office bearers. Gated by RBAC permission
 * 'office_bearers.manage' (Phase 0).
 *
 * Includes Designation Master management with official
 * Kannada designations per Bye-laws:
 * - State: ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 46 ರ ಪ್ರಕಾರ ರಾಜ್ಯ ಸಂಘದ ಸಮಿತಿ
 * - District: ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 26 ರ ಪ್ರಕಾರ ಜಿಲ್ಲಾ ಸಂಘದ ಸಮಿತಿ
 * - Taluk: ತಾಲ್ಲೂಕು ಸಮಿತಿ
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'office_bearers', 'manage');

// ---------------------------------------------------------------------------
// POST Handlers
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $action = Sanitize::string($_POST['action'] ?? '', 40);

    // ── OFFICE BEARER: Save Drag and Drop Order ───────────────────────────
    if ($action === 'save_ob_drag_order') {
        $orderedIds = $_POST['ordered_ids'] ?? [];
        if (is_array($orderedIds) && !empty($orderedIds)) {
            $newOrder = 1;
            foreach ($orderedIds as $obId) {
                $id = Sanitize::positiveInt($obId);
                if ($id !== false) {
                    Database::execute(
                        "UPDATE office_bearers SET sort_order = ?, updated_at = NOW() WHERE id = ?",
                        [$newOrder, $id]
                    );
                    $newOrder++;
                }
            }
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || !empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        Session::flash('success', 'Office bearer display orders saved.');
        $redirectUrl = '/admin/office-bearers.php';
        if (!empty($_POST['redirect_ob_level'])) {
            $redirectUrl .= '?ob_level=' . urlencode($_POST['redirect_ob_level']);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // ── OFFICE BEARER: Terminate ───────────────────────────────────────────
    if ($action === 'terminate') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id === false) {
            Session::flash('error', 'Invalid office bearer reference.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'Office bearer not found.');
            } else {
                Database::execute(
                    "UPDATE office_bearers 
                     SET status = 'former', 
                         term_end = COALESCE(term_end, CURDATE()), 
                         updated_at = NOW() 
                     WHERE id = ?",
                    [$id]
                );
                AuditLogger::log('UPDATE', 'office_bearers', $id, $existing, [
                    'status' => 'former',
                    'term_end' => $existing['term_end'] ?? date('Y-m-d')
                ]);
                Session::flash('success', 'Office bearer marked as terminated (former).');
            }
        }
        header('Location: /admin/office-bearers.php');
        exit;
    }

    // ── OFFICE BEARER: Reactivate ──────────────────────────────────────────
    if ($action === 'reactivate') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id === false) {
            Session::flash('error', 'Invalid office bearer reference.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'Office bearer not found.');
            } else {
                Database::execute(
                    "UPDATE office_bearers SET status = 'active', updated_at = NOW() WHERE id = ?",
                    [$id]
                );
                AuditLogger::log('UPDATE', 'office_bearers', $id, $existing, ['status' => 'active']);
                Session::flash('success', 'Office bearer reactivated successfully.');
            }
        }
        header('Location: /admin/office-bearers.php');
        exit;
    }

    // ── OFFICE BEARER: Delete ──────────────────────────────────────────────
    if ($action === 'delete') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id === false) {
            Session::flash('error', 'Invalid office bearer reference.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'Office bearer not found.');
            } else {
                if (!empty($existing['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($existing['photo_path'], '/'))) {
                    @unlink(PUBLIC_HTML . '/' . ltrim($existing['photo_path'], '/'));
                }
                Database::execute('DELETE FROM office_bearers WHERE id = ?', [$id]);
                AuditLogger::log('DELETE', 'office_bearers', $id, $existing, null);
                Session::flash('success', 'Office bearer deleted permanently.');
            }
        }
        header('Location: /admin/office-bearers.php');
        exit;
    }

    // ── OFFICE BEARER: Toggle status (backward compat) ─────────────────────
    if ($action === 'toggle_status') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id === false) {
            Session::flash('error', 'Invalid office bearer reference.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'Office bearer not found.');
            } else {
                $newStatus = $existing['status'] === 'active' ? 'former' : 'active';
                Database::execute(
                    'UPDATE office_bearers SET status = ?, updated_at = NOW() WHERE id = ?',
                    [$newStatus, $id]
                );
                AuditLogger::log('UPDATE', 'office_bearers', $id, $existing, ['status' => $newStatus]);
                Session::flash('success', 'Status updated.');
            }
        }
        header('Location: /admin/office-bearers.php');
        exit;
    }

    // ── OFFICE BEARER: Assign State Position from District/Taluk ────────────
    if ($action === 'assign_state_position') {
        $sourceObId = Sanitize::positiveInt($_POST['source_ob_id'] ?? null);
        $stateDesig = trim(Sanitize::string($_POST['state_association_designation'] ?? '', 200));
        $termStart  = Sanitize::date($_POST['term_start'] ?? '') ?: null;
        $termEnd    = Sanitize::date($_POST['term_end'] ?? '') ?: null;

        if (!$sourceObId || $stateDesig === '') {
            Session::flash('error', 'Please select a valid office bearer and state committee designation.');
            header('Location: /admin/office-bearers.php');
            exit;
        }

        $sourceOb = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$sourceObId]);
        if (!$sourceOb) {
            Session::flash('error', 'Office bearer not found.');
            header('Location: /admin/office-bearers.php');
            exit;
        }

        // Check if person already has an active position in State Committee
        $alreadyState = Database::fetchOne(
            "SELECT id, association_designation FROM office_bearers 
             WHERE district_id IS NULL AND taluk_id IS NULL AND name = ? AND status = 'active'",
            [$sourceOb['name']]
        );
        if ($alreadyState) {
            Session::flash('error', 'Office bearer "' . $sourceOb['name'] . '" already holds an active position in State Committee as "' . $alreadyState['association_designation'] . '".');
            header('Location: /admin/office-bearers.php');
            exit;
        }

        $maxStateSort = (int) Database::fetchScalar(
            "SELECT COALESCE(MAX(sort_order), 0) FROM office_bearers WHERE district_id IS NULL AND taluk_id IS NULL"
        );

        Database::execute(
            'INSERT INTO office_bearers
                (name, association_designation, official_designation, contact_number, district_id, taluk_id,
                 photo_path, term_start, term_end, status, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $sourceOb['name'],
                $stateDesig,
                $sourceOb['official_designation'],
                $sourceOb['contact_number'],
                $sourceOb['photo_path'],
                $termStart ?: $sourceOb['term_start'],
                $termEnd ?: $sourceOb['term_end'],
                'active',
                $maxStateSort + 1,
            ]
        );

        $stateObId = (int) Database::lastInsertId();
        AuditLogger::log('CREATE', 'office_bearers', $stateObId, null, [
            'name'                    => $sourceOb['name'],
            'association_designation' => $stateDesig,
            'level'                   => 'state',
            'linked_from_ob_id'       => $sourceObId,
        ]);

        Session::flash('success', 'Office bearer "' . $sourceOb['name'] . '" has been successfully added to State Committee (' . $stateDesig . ').');
        header('Location: /admin/office-bearers.php');
        exit;
    }

    // ── OFFICE BEARER: Create / Update ─────────────────────────────────────
    if ($action === 'create' || $action === 'update') {
        $id                     = $action === 'update' ? Sanitize::positiveInt($_POST['id'] ?? null) : null;
        $name                   = trim(Sanitize::string($_POST['name'] ?? '', 200));
        $associationDesignation = trim(Sanitize::string($_POST['association_designation'] ?? '', 200));
        $officialDesignationRaw = trim(Sanitize::string($_POST['official_designation'] ?? '', 200));
        $officialDesignation    = $officialDesignationRaw !== '' ? $officialDesignationRaw : null;
        $contactNumberRaw       = trim(Sanitize::string($_POST['contact_number'] ?? '', 20));
        $contactNumber          = $contactNumberRaw !== '' ? $contactNumberRaw : null;
        $level                       = Sanitize::inArray($_POST['level'] ?? '', ['state', 'district', 'taluk']);
        $hasStatePosition            = Sanitize::inArray($_POST['has_state_position'] ?? '0', ['0', '1']) === '1';
        $stateAssociationDesignation = trim(Sanitize::string($_POST['state_association_designation'] ?? '', 200));
        $sortOrder                   = Sanitize::nonNegativeInt($_POST['sort_order'] ?? 0);
        $termStartRaw           = trim((string) ($_POST['term_start'] ?? ''));
        $termEndRaw             = trim((string) ($_POST['term_end'] ?? ''));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($associationDesignation === '') {
            $errors[] = 'Association designation is required.';
        }
        if ($level === false) {
            $errors[] = 'Please choose a valid level (State / District / Taluk).';
        }
        if ($sortOrder === false || $sortOrder === 0) {
            if ($action === 'create') {
                $maxSort = (int) Database::fetchScalar("SELECT COALESCE(MAX(sort_order), 0) FROM office_bearers");
                $sortOrder = $maxSort + 1;
            } else {
                $sortOrder = 0;
            }
        }

        $termStart = null;
        if ($termStartRaw !== '') {
            $termStart = Sanitize::date($termStartRaw);
            if ($termStart === false) {
                $errors[] = 'Term start date is invalid.';
                $termStart = null;
            }
        }

        $termEnd = null;
        if ($termEndRaw !== '') {
            $termEnd = Sanitize::date($termEndRaw);
            if ($termEnd === false) {
                $errors[] = 'Term end date is invalid.';
                $termEnd = null;
            }
        }

        if ($termStart !== null && $termEnd !== null && $termStart > $termEnd) {
            $errors[] = 'Term end date cannot be before term start date.';
        }

        $districtId = null;
        $talukId    = null;

        if ($level === 'district') {
            $districtId = Sanitize::positiveInt($_POST['district_id'] ?? null);
            if ($districtId === false) {
                $errors[] = 'Please choose a district.';
                $districtId = null;
            } else {
                $district = Database::fetchOne(
                    "SELECT id FROM districts WHERE id = ? AND status = 'active'",
                    [$districtId]
                );
                if (!$district) {
                    $errors[] = 'Selected district was not found.';
                    $districtId = null;
                }
            }
        } elseif ($level === 'taluk') {
            $talukId = Sanitize::positiveInt($_POST['taluk_id'] ?? null);
            if ($talukId === false) {
                $errors[] = 'Please choose a taluk.';
                $talukId = null;
            } else {
                $taluk = Database::fetchOne(
                    "SELECT id, district_id FROM taluks WHERE id = ? AND status = 'active'",
                    [$talukId]
                );
                if (!$taluk) {
                    $errors[] = 'Selected taluk was not found.';
                    $talukId = null;
                } else {
                    $districtId = (int) $taluk['district_id'];
                }
            }
        }

        if ($action === 'update' && $id === false) {
            $errors[] = 'Invalid office bearer reference.';
        }

        $existing = null;
        if ($action === 'update') {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);
            if (!$existing) {
                $errors[] = 'Office bearer not found.';
            }
        }

        // Photo upload / removal handling
        $photoPath = $existing ? ($existing['photo_path'] ?? null) : null;
        if ($action === 'update' && !empty($_POST['remove_photo'])) {
            if ($photoPath && file_exists(PUBLIC_HTML . '/' . ltrim($photoPath, '/'))) {
                @unlink(PUBLIC_HTML . '/' . ltrim($photoPath, '/'));
            }
            $photoPath = null;
        }

        $photoFile = $_FILES['photo'] ?? null;
        if ($photoFile && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadErrors = Sanitize::fileUpload(
                $photoFile,
                ['image/jpeg', 'image/png', 'image/webp'],
                2 * 1024 * 1024 // 2MB
            );
            if (!empty($uploadErrors)) {
                $errors[] = 'Photo upload: ' . implode(' ', $uploadErrors);
            } else {
                $safeName = 'ob_' . Sanitize::safeUploadFilename($photoFile['name']);
                $targetDir = PUBLIC_HTML . '/assets/images/office-bearers';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                $destAbs = $targetDir . '/' . $safeName;
                if (move_uploaded_file($photoFile['tmp_name'], $destAbs)) {
                    if ($photoPath && file_exists(PUBLIC_HTML . '/' . ltrim($photoPath, '/'))) {
                        @unlink(PUBLIC_HTML . '/' . ltrim($photoPath, '/'));
                    }
                    $photoPath = 'assets/images/office-bearers/' . $safeName;
                } else {
                    $errors[] = 'Failed to save uploaded photo file.';
                }
            }
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
            header('Location: /admin/office-bearers.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        if ($action === 'update') {
            $contactVal  = array_key_exists('contact_number', $_POST) ? $contactNumber : ($existing['contact_number'] ?? null);
            $officialVal = array_key_exists('official_designation', $_POST) ? $officialDesignation : ($existing['official_designation'] ?? null);
        } else {
            $contactVal  = $contactNumber;
            $officialVal = $officialDesignation;
        }

        $data = [
            'name'                     => $name,
            'association_designation'  => $associationDesignation,
            'official_designation'     => $officialVal,
            'contact_number'           => $contactVal,
            'district_id'              => $districtId,
            'taluk_id'                 => $talukId,
            'photo_path'               => $photoPath,
            'term_start'               => $termStart,
            'term_end'                 => $termEnd,
            'sort_order'               => ($action === 'update' && ($sortOrder === 0 || $sortOrder === false)) ? (int) ($existing['sort_order'] ?? 0) : $sortOrder,
        ];

        if ($action === 'create') {
            Database::execute(
                'INSERT INTO office_bearers
                    (name, association_designation, official_designation, contact_number, district_id, taluk_id,
                     photo_path, term_start, term_end, status, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $data['name'], $data['association_designation'], $data['official_designation'],
                    $data['contact_number'], $data['district_id'], $data['taluk_id'], $data['photo_path'],
                    $data['term_start'], $data['term_end'], 'active', $data['sort_order'],
                ]
            );
            $newId = (int) Database::lastInsertId();
            AuditLogger::log('CREATE', 'office_bearers', $newId, null, $data);

            // If district or taluk office bearer has position in state committee
            if ($level !== 'state' && $hasStatePosition && $stateAssociationDesignation !== '') {
                $maxStateSort = (int) Database::fetchScalar(
                    "SELECT COALESCE(MAX(sort_order), 0) FROM office_bearers WHERE district_id IS NULL AND taluk_id IS NULL"
                );
                Database::execute(
                    'INSERT INTO office_bearers
                        (name, association_designation, official_designation, contact_number, district_id, taluk_id,
                         photo_path, term_start, term_end, status, sort_order, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        $data['name'], $stateAssociationDesignation, $data['official_designation'],
                        $data['contact_number'], $data['photo_path'],
                        $data['term_start'], $data['term_end'], 'active', $maxStateSort + 1,
                    ]
                );
                $stateObId = (int) Database::lastInsertId();
                AuditLogger::log('CREATE', 'office_bearers', $stateObId, null, array_merge($data, [
                    'association_designation' => $stateAssociationDesignation,
                    'district_id'             => null,
                    'taluk_id'                => null,
                ]));
                Session::flash('success', 'Office bearer added to ' . ucfirst($level) . ' Committee and also added to State Committee (' . $stateAssociationDesignation . ').');
            } else {
                Session::flash('success', 'Office bearer added successfully.');
            }
        } else {
            Database::execute(
                'UPDATE office_bearers SET
                    name = ?, association_designation = ?, official_designation = ?, contact_number = ?,
                    district_id = ?, taluk_id = ?, photo_path = ?, term_start = ?, term_end = ?,
                    sort_order = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $data['name'], $data['association_designation'], $data['official_designation'],
                    $data['contact_number'], $data['district_id'], $data['taluk_id'], $data['photo_path'],
                    $data['term_start'], $data['term_end'], $data['sort_order'], $id,
                ]
            );
            AuditLogger::log('UPDATE', 'office_bearers', $id, $existing, $data);

            // If district or taluk office bearer was updated and marked to have state position
            if ($level !== 'state' && $hasStatePosition && $stateAssociationDesignation !== '') {
                $alreadyState = Database::fetchOne(
                    "SELECT id FROM office_bearers WHERE district_id IS NULL AND taluk_id IS NULL AND name = ? AND status = 'active'",
                    [$data['name']]
                );
                if (!$alreadyState) {
                    $maxStateSort = (int) Database::fetchScalar(
                        "SELECT COALESCE(MAX(sort_order), 0) FROM office_bearers WHERE district_id IS NULL AND taluk_id IS NULL"
                    );
                    Database::execute(
                        'INSERT INTO office_bearers
                            (name, association_designation, official_designation, contact_number, district_id, taluk_id,
                             photo_path, term_start, term_end, status, sort_order, created_at, updated_at)
                         VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $data['name'], $stateAssociationDesignation, $data['official_designation'],
                            $data['contact_number'], $data['photo_path'],
                            $data['term_start'], $data['term_end'], 'active', $maxStateSort + 1,
                        ]
                    );
                    $stateObId = (int) Database::lastInsertId();
                    AuditLogger::log('CREATE', 'office_bearers', $stateObId, null, [
                        'name'                    => $data['name'],
                        'association_designation' => $stateAssociationDesignation,
                        'level'                   => 'state',
                        'linked_from_ob_id'       => $id,
                    ]);
                    Session::flash('success', 'Office bearer updated and also added to State Committee (' . $stateAssociationDesignation . ').');
                } else {
                    Session::flash('success', 'Office bearer updated successfully.');
                }
            } else {
                Session::flash('success', 'Office bearer updated successfully.');
            }
        }

        header('Location: /admin/office-bearers.php');
        exit;
    }

    // ── DESIGNATION: Add ───────────────────────────────────────────────────
    if ($action === 'add_designation') {
        $level         = Sanitize::inArray($_POST['desig_level'] ?? '', ['state', 'district', 'taluk']);
        $designationKn = trim(Sanitize::string($_POST['designation_kn'] ?? '', 255));
        $designationEn = trim(Sanitize::string($_POST['designation_en'] ?? '', 255));
        $seats         = max(1, Sanitize::positiveInt($_POST['seats'] ?? 1) ?: 1);
        $sortOrder     = Sanitize::nonNegativeInt($_POST['sort_order'] ?? 0);

        if (!$level) {
            Session::flash('error', 'Valid committee level is required.');
        } elseif ($designationKn === '') {
            Session::flash('error', 'Designation (Kannada) is required.');
        } else {
            if ($sortOrder === 0 || $sortOrder === false) {
                $maxSort = (int) Database::fetchScalar(
                    "SELECT COALESCE(MAX(sort_order), 0) FROM office_bearer_designations WHERE level = ?",
                    [$level]
                );
                $sortOrder = $maxSort + 1;
            }

            Database::execute(
                "INSERT INTO office_bearer_designations 
                 (level, designation_kn, designation_en, seats, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())",
                [$level, $designationKn, $designationEn !== '' ? $designationEn : null, $seats, $sortOrder]
            );
            $newDesigId = (int) Database::lastInsertId();
            AuditLogger::log('CREATE', 'office_bearer_designations', $newDesigId, null, [
                'level' => $level, 'designation_kn' => $designationKn, 'designation_en' => $designationEn, 'seats' => $seats
            ]);
            Session::flash('success', 'New designation added successfully.');
        }

        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($level ?: 'all'));
        exit;
    }

    // ── DESIGNATION: Update ────────────────────────────────────────────────
    if ($action === 'update_designation') {
        $id            = Sanitize::positiveInt($_POST['desig_id'] ?? null);
        $level         = Sanitize::inArray($_POST['desig_level'] ?? '', ['state', 'district', 'taluk']);
        $designationKn = trim(Sanitize::string($_POST['designation_kn'] ?? '', 255));
        $designationEn = trim(Sanitize::string($_POST['designation_en'] ?? '', 255));
        $seats         = max(1, Sanitize::positiveInt($_POST['seats'] ?? 1) ?: 1);
        $sortOrder     = isset($_POST['sort_order']) && $_POST['sort_order'] !== ''
            ? Sanitize::nonNegativeInt($_POST['sort_order'])
            : (isset($_POST['desig_sort_order']) && $_POST['desig_sort_order'] !== ''
                ? Sanitize::nonNegativeInt($_POST['desig_sort_order'])
                : null);
        $status        = Sanitize::inArray($_POST['status'] ?? 'active', ['active', 'inactive']) ?: 'active';

        if (!$id || !$level || $designationKn === '') {
            Session::flash('error', 'Invalid designation data.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearer_designations WHERE id = ?', [$id]);
            if ($existing) {
                if ($sortOrder === null || $sortOrder === false || $sortOrder === 0) {
                    $sortOrder = (int) ($existing['sort_order'] ?? 0);
                }
                Database::execute(
                    "UPDATE office_bearer_designations 
                     SET level = ?, designation_kn = ?, designation_en = ?, seats = ?, sort_order = ?, status = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$level, $designationKn, $designationEn !== '' ? $designationEn : null, $seats, $sortOrder, $status, $id]
                );
                AuditLogger::log('UPDATE', 'office_bearer_designations', $id, $existing, [
                    'level' => $level, 'designation_kn' => $designationKn, 'seats' => $seats, 'status' => $status
                ]);
                Session::flash('success', 'Designation updated successfully.');
            }
        }

        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($level ?: 'all'));
        exit;
    }

    // ── DESIGNATION: Move Up / Down ────────────────────────────────────────
    if ($action === 'move_designation') {
        $id  = Sanitize::positiveInt($_POST['id'] ?? null);
        $dir = Sanitize::inArray($_POST['dir'] ?? '', ['up', 'down']);

        if ($id && $dir) {
            $curr = Database::fetchOne('SELECT * FROM office_bearer_designations WHERE id = ?', [$id]);
            if ($curr) {
                $level = $curr['level'];
                if ($dir === 'up') {
                    $other = Database::fetchOne(
                        "SELECT * FROM office_bearer_designations 
                         WHERE level = ? AND sort_order < ? 
                         ORDER BY sort_order DESC, id DESC LIMIT 1",
                        [$level, $curr['sort_order']]
                    );
                } else {
                    $other = Database::fetchOne(
                        "SELECT * FROM office_bearer_designations 
                         WHERE level = ? AND sort_order > ? 
                         ORDER BY sort_order ASC, id ASC LIMIT 1",
                        [$level, $curr['sort_order']]
                    );
                }

                if ($other) {
                    // Swap orders
                    Database::execute(
                        "UPDATE office_bearer_designations SET sort_order = ? WHERE id = ?",
                        [$other['sort_order'], $curr['id']]
                    );
                    Database::execute(
                        "UPDATE office_bearer_designations SET sort_order = ? WHERE id = ?",
                        [$curr['sort_order'], $other['id']]
                    );
                    Session::flash('success', 'Designation order updated.');
                }
            }
        }
        $filterLevel = $_POST['redirect_level'] ?? 'all';
        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($filterLevel));
        exit;
    }

    // ── DESIGNATION: Save Drag and Drop Order ──────────────────────────────
    if ($action === 'save_drag_order') {
        $orderedIds = $_POST['ordered_ids'] ?? [];
        if (is_array($orderedIds) && !empty($orderedIds)) {
            $newOrder = 1;
            foreach ($orderedIds as $desigId) {
                $dId = Sanitize::positiveInt($desigId);
                if ($dId !== false) {
                    Database::execute(
                        "UPDATE office_bearer_designations SET sort_order = ?, updated_at = NOW() WHERE id = ?",
                        [$newOrder, $dId]
                    );
                    $newOrder++;
                }
            }
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || !empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        Session::flash('success', 'Designation display orders saved.');
        $filterLevel = $_POST['redirect_level'] ?? 'all';
        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($filterLevel));
        exit;
    }

    // ── DESIGNATION: Bulk Save Order ───────────────────────────────────────
    if ($action === 'save_orders') {
        $orders = $_POST['orders'] ?? [];
        if (is_array($orders)) {
            foreach ($orders as $desigId => $sortVal) {
                $dId = Sanitize::positiveInt($desigId);
                $sVal = Sanitize::nonNegativeInt($sortVal);
                if ($dId !== false && $sVal !== false) {
                    Database::execute(
                        "UPDATE office_bearer_designations SET sort_order = ?, updated_at = NOW() WHERE id = ?",
                        [$sVal, $dId]
                    );
                }
            }
            Session::flash('success', 'Designation display orders saved.');
        }
        $filterLevel = $_POST['redirect_level'] ?? 'all';
        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($filterLevel));
        exit;
    }

    // ── DESIGNATION: Toggle Status ─────────────────────────────────────────
    if ($action === 'toggle_desig_status') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $existing = Database::fetchOne('SELECT * FROM office_bearer_designations WHERE id = ?', [$id]);
            if ($existing) {
                $newStatus = $existing['status'] === 'active' ? 'inactive' : 'active';
                Database::execute(
                    "UPDATE office_bearer_designations SET status = ?, updated_at = NOW() WHERE id = ?",
                    [$newStatus, $id]
                );
                Session::flash('success', 'Designation status updated to ' . $newStatus . '.');
            }
        }
        $filterLevel = $_POST['redirect_level'] ?? 'all';
        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($filterLevel));
        exit;
    }

    // ── DESIGNATION: Delete ────────────────────────────────────────────────
    if ($action === 'delete_designation') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $existing = Database::fetchOne('SELECT * FROM office_bearer_designations WHERE id = ?', [$id]);
            if ($existing) {
                Database::execute('DELETE FROM office_bearer_designations WHERE id = ?', [$id]);
                AuditLogger::log('DELETE', 'office_bearer_designations', $id, $existing, null);
                Session::flash('success', 'Designation removed.');
            }
        }
        $filterLevel = $_POST['redirect_level'] ?? 'all';
        header('Location: /admin/office-bearers.php?view=designations&desig_level=' . urlencode($filterLevel));
        exit;
    }

    ErrorHandler::abort(400, 'Unknown action.');
}

// ---------------------------------------------------------------------------
// GET Data
// ---------------------------------------------------------------------------
$currentTab = ($_GET['view'] ?? '') === 'designations' ? 'designations' : 'bearers';

$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status = 'active' ORDER BY name");
$taluks    = Database::fetchAll(
    "SELECT t.id, t.name, t.district_id, d.name AS district_name
     FROM taluks t
     JOIN districts d ON d.id = t.district_id
     WHERE t.status = 'active' AND d.status = 'active'
     ORDER BY d.name, t.name"
);

$obLevelFilter = Sanitize::inArray($_GET['ob_level'] ?? 'all', ['all', 'state', 'district', 'taluk']) ?: 'all';
$obWhere = "";
if ($obLevelFilter === 'state') {
    $obWhere = "WHERE ob.district_id IS NULL AND ob.taluk_id IS NULL";
} elseif ($obLevelFilter === 'district') {
    $obWhere = "WHERE ob.district_id IS NOT NULL AND ob.taluk_id IS NULL";
} elseif ($obLevelFilter === 'taluk') {
    $obWhere = "WHERE ob.taluk_id IS NOT NULL";
}

$totalBearersCount = (int) Database::fetchScalar("SELECT COUNT(*) FROM office_bearers");

$officeBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name, t.name AS taluk_name
     FROM office_bearers ob
     LEFT JOIN districts d ON d.id = ob.district_id
     LEFT JOIN taluks t    ON t.id = ob.taluk_id
     $obWhere
     ORDER BY
        CASE WHEN ob.taluk_id IS NOT NULL THEN 3
             WHEN ob.district_id IS NOT NULL THEN 2
             ELSE 1 END,
        ob.sort_order, ob.name"
);

$activeStateBearers = Database::fetchAll(
    "SELECT id, name, association_designation FROM office_bearers WHERE district_id IS NULL AND taluk_id IS NULL AND status = 'active'"
);
$activeStateBearerMap = [];
foreach ($activeStateBearers as $sb) {
    $activeStateBearerMap[mb_strtolower(trim($sb['name']))] = $sb['association_designation'];
}

$editRow = null;
$editId  = Sanitize::positiveInt($_GET['edit'] ?? null);
if ($editId !== false && $editId) {
    $editRow = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$editId]);
}
$existingStatePosition = null;
if ($editRow && in_array(ob_level($editRow), ['district', 'taluk'])) {
    $existingStatePosition = $activeStateBearerMap[mb_strtolower(trim($editRow['name']))] ?? null;
}

$fromMember = null;
$fromMemberId = Sanitize::positiveInt($_GET['member_id'] ?? null);
if ($fromMemberId && !$editRow) {
    $m = Database::fetchOne(
        "SELECT m.*, mp.personal_mobile FROM members m LEFT JOIN member_profiles mp ON mp.member_id = m.id WHERE m.id = ?",
        [$fromMemberId]
    );
    if ($m) {
        $fromMember = [
            'id'                      => null,
            'name'                    => $m['name'],
            'contact_number'          => $m['personal_mobile'] ?? '',
            'district_id'             => $m['district_id'],
            'taluk_id'                => $m['taluk_id'],
            'member_id'               => (int)$m['id'],
            'association_designation' => '',
            'term_start'              => '2026-05-17',
            'term_end'                => '2029-05-16',
        ];
    }
}
$initialRow = $editRow ?: $fromMember;
$isFormExpanded = ($editRow !== null) || ($fromMember !== null);

// Designations master data
$desigLevelFilter = Sanitize::inArray($_GET['desig_level'] ?? 'all', ['all', 'state', 'district', 'taluk']) ?: 'all';
if ($desigLevelFilter !== 'all') {
    $allDesignations = Database::fetchAll(
        "SELECT * FROM office_bearer_designations WHERE level = ? ORDER BY level, sort_order, id",
        [$desigLevelFilter]
    );
} else {
    $allDesignations = Database::fetchAll(
        "SELECT * FROM office_bearer_designations ORDER BY level, sort_order, id"
    );
}

$activeDesignations = Database::fetchAll("SELECT * FROM office_bearer_designations WHERE status = 'active' ORDER BY level, sort_order, id");
$designationsByLevel = [
    'state'    => [],
    'district' => [],
    'taluk'    => [],
];
foreach ($activeDesignations as $des) {
    $designationsByLevel[$des['level']][] = [
        'id'             => (int) $des['id'],
        'designation_kn' => $des['designation_kn'],
        'designation_en' => $des['designation_en'] ?? '',
        'seats'          => (int) $des['seats'],
        'sort_order'     => (int) $des['sort_order'],
    ];
}
$designationsJson = json_encode($designationsByLevel, JSON_UNESCAPED_UNICODE);

// Edit designation record
$editDesigRow = null;
$editDesigId = Sanitize::positiveInt($_GET['edit_desig'] ?? null);
if ($editDesigId !== false && $editDesigId) {
    $editDesigRow = Database::fetchOne('SELECT * FROM office_bearer_designations WHERE id = ?', [$editDesigId]);
}

function ob_level(array $row): string
{
    if (!empty($row['taluk_id']))    return 'taluk';
    if (!empty($row['district_id'])) return 'district';
    return 'state';
}

$pageTitle   = ($currentTab === 'designations') ? 'Office Bearer Designations' : 'Office Bearers';
$activeMenu  = 'office_bearers';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Office Bearers', 'url' => '/admin/office-bearers.php'],
];
if ($currentTab === 'designations') {
    $breadcrumbs[] = ['label' => 'Manage Designations', 'url' => ''];
}

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .sub-nav {
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
        padding: 0 24px;
        display: flex;
        gap: 24px;
        margin-bottom: 24px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .sub-nav a {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 4px;
        color: #64748b;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.88rem;
        border-bottom: 3px solid transparent;
        transition: all 0.15s ease;
    }
    .sub-nav a:hover { color: #1e40af; }
    .sub-nav a.active {
        color: #1e40af;
        border-bottom-color: #1e40af;
    }
    .sub-nav .nav-count {
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 9999px;
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
    }
    .sub-nav a.active .nav-count {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .panel {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 6px rgba(26,58,107,0.08);
        padding: 24px;
        margin-bottom: 24px;
    }
    .panel-header-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .panel h2 { font-size: 1.08rem; color: #1a3a6b; margin: 0; }
    
    .msg {
        padding: 12px 16px;
        border-radius: 6px;
        font-size: 0.86rem;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
    .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }

    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 5px; }
    input[type="text"], input[type="number"], input[type="date"], select {
        width: 100%; padding: 8px 11px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.92rem;
        transition: border-color 0.15s ease;
    }
    input[type="text"]:focus, input[type="number"]:focus, input[type="date"]:focus, select:focus {
        border-color: #2563eb; outline: none; box-shadow: 0 0 0 2px rgba(37,99,235,0.12);
    }
    .row { display: flex; gap: 16px; flex-wrap: wrap; }
    .row > div { flex: 1; min-width: 210px; }
    .level-choice { display: flex; gap: 20px; margin: 12px 0 6px; flex-wrap: wrap; }
    .level-choice label { display: flex; align-items: center; gap: 6px; font-weight: 500; margin: 0; cursor: pointer; }
    .hint { font-size: 0.75rem; color: #64748b; margin-top: 4px; }
    
    button, .btn {
        background: #1a3a6b; color: #fff; border: none; border-radius: 6px;
        padding: 9px 18px; font-size: 0.88rem; font-weight: 600; cursor: pointer; text-decoration: none;
        display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s ease;
    }
    button:hover, .btn:hover { background: #142c52; }
    .btn-secondary { background: #64748b; color: #fff; }
    .btn-secondary:hover { background: #475569; }
    .btn-outline { background: #fff; color: #1a3a6b; border: 1px solid #cbd5e1; }
    .btn-outline:hover { background: #f8fafc; border-color: #1a3a6b; }
    .btn-sm { padding: 4px 10px; font-size: 0.78rem; border-radius: 4px; }

    .table-wrap {
        overflow-x: auto;
        background: #ffffff;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        margin-top: 14px;
    }
    table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
    th, td { text-align: left; padding: 11px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    th {
        color: #ffffff;
        font-weight: 600;
        background: var(--blue-800, #1e40af);
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: none;
        white-space: nowrap;
    }
    tr:hover { background: #f8fafc; }
    
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 600;
    }
    .badge.active { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .badge.former, .badge.inactive { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
    .badge.state { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .badge.district { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
    .badge.taluk { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    
    .level-tag { font-size: 0.72rem; color: #1a3a6b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Actions dropdown styling */
    .act-select {
        width: auto !important;
        min-width: 115px;
        padding: 5px 10px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #1e293b;
        background-color: #f8fafc;
        cursor: pointer;
        outline: none;
        transition: all 0.15s ease;
    }
    .act-select:hover, .act-select:focus {
        border-color: #2563eb;
        background-color: #ffffff;
        box-shadow: 0 0 0 2px rgba(37,99,235,0.1);
    }

    /* Filter pills */
    .filter-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .filter-pill {
        padding: 6px 14px;
        border-radius: 9999px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .filter-pill:hover { background: #f1f5f9; color: #1e40af; border-color: #94a3b8; }
    .filter-pill.active { background: #1e40af; color: #fff; border-color: #1e40af; }

    /* Bye-law info banner */
    .byelaw-banner {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #2563eb;
        border-radius: 6px;
        padding: 12px 16px;
        margin-bottom: 18px;
        font-size: 0.84rem;
        color: #334155;
    }
    .byelaw-banner strong { color: #1e3a8a; }

    /* Drag & Drop Reorder Styling */
    .draggable-row {
        cursor: grab;
        transition: background-color 0.15s ease;
    }
    .draggable-row.is-dragging {
        opacity: 0.4;
        background-color: #eff6ff !important;
    }
    .draggable-row.drag-over-top {
        border-top: 3px solid #2563eb !important;
    }
    .draggable-row.drag-over-bottom {
        border-bottom: 3px solid #2563eb !important;
    }
    .drag-handle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 12px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        cursor: grab;
        user-select: none;
        transition: all 0.15s ease;
    }
    .drag-handle:hover {
        background: #e2e8f0;
        border-color: #2563eb;
    }
    .drag-handle:active {
        cursor: grabbing;
    }
    .drag-icon {
        font-size: 1rem;
        line-height: 1;
        color: #64748b;
        letter-spacing: -2px;
        display: inline-block;
    }
    .order-badge {
        font-weight: 700;
        font-size: 0.82rem;
        color: #1e40af;
        min-width: 18px;
        text-align: center;
    }
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(2px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .modal-overlay.open {
        display: flex;
    }
    .modal-box {
        background: #ffffff;
        border-radius: 12px;
        max-width: 540px;
        width: 100%;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
        padding: 24px;
        position: relative;
        animation: modalFadeIn 0.2s ease-out;
    }
    @keyframes modalFadeIn {
        from { opacity: 0; transform: translateY(-10px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
</style>

<!-- Sub Navigation Tabs -->
<div class="sub-nav">
    <a href="/admin/office-bearers.php" class="<?= $currentTab === 'bearers' ? 'active' : '' ?>">
        <span>👥 Office Bearers (ಪದಾಧಿಕಾರಿಗಳು)</span>
        <span class="nav-count"><?= $totalBearersCount ?></span>
    </a>
    <a href="/admin/office-bearers.php?view=designations" class="<?= $currentTab === 'designations' ? 'active' : '' ?>">
        <span>🏷️ Manage Designations (ಹುದ್ದೆಗಳ ನಿರ್ವಹಣೆ)</span>
        <span class="nav-count"><?= count($allDesignations) ?></span>
    </a>
</div>

<?php if ($successMsg): ?>
    <div class="msg success" role="alert">✓ <?= Sanitize::html($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="msg error" role="alert">⚠ <?= Sanitize::html($errorMsg) ?></div>
<?php endif; ?>

<!-- Hidden form for office bearer dropdown actions -->
<form id="obActionForm" method="post" action="/admin/office-bearers.php" style="display:none;">
    <?= CSRF::htmlField() ?>
    <input type="hidden" name="action" id="obActionVal" value="">
    <input type="hidden" name="id" id="obActionId" value="">
</form>

<!-- Hidden form for designation dropdown actions -->
<form id="desigActionForm" method="post" action="/admin/office-bearers.php" style="display:none;">
    <?= CSRF::htmlField() ?>
    <input type="hidden" name="action" id="desigActionVal" value="">
    <input type="hidden" name="id" id="desigActionId" value="">
    <input type="hidden" name="dir" id="desigActionDir" value="">
    <input type="hidden" name="redirect_level" value="<?= Sanitize::attr($desigLevelFilter) ?>">
</form>

<?php if ($currentTab === 'bearers'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB 1: OFFICE BEARERS MANAGEMENT
     ═══════════════════════════════════════════════════════════════════════════ -->
    <div class="panel">
        <div class="panel-header-flex" style="cursor: pointer; margin-bottom: <?= $isFormExpanded ? '18px' : '0' ?>;" onclick="toggleAddObForm()">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="addObToggleIcon" style="font-size: 0.9rem; color: #1a3a6b; display: inline-block;"><?= $isFormExpanded ? '▼' : '▶' ?></span>
                <h2 style="margin: 0;"><?= $editRow ? 'Edit Office Bearer' : 'Add New Office Bearer' ?></h2>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn btn-sm btn-outline" onclick="event.stopPropagation(); toggleAddObForm();">
                    <span id="addObBtnText"><?= $isFormExpanded ? '✕ Close Form' : '+ Expand Form' ?></span>
                </button>
                <a href="/admin/office-bearers.php?view=designations" class="btn btn-outline btn-sm" onclick="event.stopPropagation();">
                    🏷️ Manage Designations (ಹುದ್ದೆಗಳ ನಿರ್ವಹಣೆ)
                </a>
            </div>
        </div>

        <div id="addObFormBody" style="<?= $isFormExpanded ? 'display: block;' : 'display: none;' ?>">
            <?php if (empty($districts)): ?>
                <div class="msg error" role="alert" style="margin-top: 15px;">
                    No districts are set up yet, so only State-level entries can be added right now.
                    District/Taluk geography master data needs to be imported first.
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/office-bearers.php" enctype="multipart/form-data" style="margin-top: 15px;">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>

                <?php
                if ($editRow) {
                    $currentLevel = ob_level($editRow);
                } elseif ($fromMember) {
                    $currentLevel = !empty($fromMember['taluk_id']) ? 'taluk' : (!empty($fromMember['district_id']) ? 'district' : 'state');
                } else {
                    $currentLevel = 'state';
                }
                ?>
                <label>Committee Level *</label>
                <div class="level-choice">
                    <label>
                        <input type="radio" name="level" value="state" onchange="obUpdateLevel()"
                            <?= $currentLevel === 'state' ? 'checked' : '' ?>> 
                        <strong>State Committee</strong> (ರಾಜ್ಯ ಸಂಘ)
                    </label>
                    <label>
                        <input type="radio" name="level" value="district" onchange="obUpdateLevel()"
                            <?= empty($districts) ? 'disabled' : '' ?>
                            <?= $currentLevel === 'district' ? 'checked' : '' ?>> 
                        <strong>District Committee</strong> (ಜಿಲ್ಲಾ ಸಂಘ)
                    </label>
                    <label>
                        <input type="radio" name="level" value="taluk" onchange="obUpdateLevel()"
                            <?= empty($taluks) ? 'disabled' : '' ?>
                            <?= $currentLevel === 'taluk' ? 'checked' : '' ?>> 
                        <strong>Taluk Committee</strong> (ತಾಲ್ಲೂಕು ಸಂಘ)
                    </label>
                </div>

                <div id="ob-district-field" style="display:none;">
                    <label for="district_id">District *</label>
                    <select name="district_id" id="district_id">
                        <option value="">— Select district —</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"
                                <?= ($initialRow && (int) ($initialRow['district_id'] ?? 0) === (int) $d['id']) ? 'selected' : '' ?>>
                                <?= Sanitize::html($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="ob-taluk-field" style="display:none;">
                    <label for="taluk_id">Taluk *</label>
                    <select name="taluk_id" id="taluk_id">
                        <option value="">— Select taluk —</option>
                        <?php foreach ($taluks as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"
                                <?= ($initialRow && (int) ($initialRow['taluk_id'] ?? 0) === (int) $t['id']) ? 'selected' : '' ?>>
                                <?= Sanitize::html($t['name']) ?> — <?= Sanitize::html($t['district_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row">
                    <div>
                        <label for="name">Name (Kannada) *</label>
                        <input type="text" id="name" name="name" required lang="kn"
                               value="<?= Sanitize::attr($initialRow['name'] ?? '') ?>"
                               placeholder="ಉದಾ: ದಿಲೀಪ್ ಕುಮಾರ ಬಿ.ಎಂ">
                        <div class="hint">ಪದಾಧಿಕಾರಿಯ ಪೂರ್ಣ ಹೆಸರು ಕನ್ನಡದಲ್ಲಿ ನಮೂದಿಸಿ</div>
                    </div>
                    <div>
                        <label for="designation_select">
                            Association Designation (Kannada) *
                        </label>
                        <!-- Quick Dropdown for Official Kannada Designations -->
                        <select id="designation_select" onchange="onDesignationSelect(this)" style="margin-bottom:6px;">
                            <option value="">— Select Designation (Kannada) —</option>
                        </select>

                        <!-- Text input with Kannada designation (synced or custom) -->
                        <input type="text" id="association_designation" name="association_designation" required lang="kn"
                               value="<?= Sanitize::attr($initialRow['association_designation'] ?? '') ?>"
                               placeholder="ಉದಾ: ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ">
                        <div class="hint" id="designation-hint">
                            ಆಯ್ಕೆ ಮಾಡಿ ಅಥವಾ ಕಸ್ಟಮ್ ಹುದ್ದೆ ನಮೂದಿಸಿ | 
                            <a href="/admin/office-bearers.php?view=designations" target="_blank" style="color:#2563eb; text-decoration:underline;">
                                ಹುದ್ದೆಗಳ ಪಟ್ಟಿ ನಿರ್ವಹಿಸಿ
                            </a>
                        </div>
                    </div>
                </div>

                <!-- State Committee Position Option (for District / Taluk Committees) -->
                <div id="state-position-box" style="display: <?= in_array($currentLevel, ['district', 'taluk']) ? 'block' : 'none' ?>; background: #f0f7ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 16px; margin: 12px 0 16px;">
                    <label style="font-weight: 600; color: #0369a1; margin-bottom: 6px; display: block;">
                        Have a Position in State Committee? (ರಾಜ್ಯ ಸಂಘದಲ್ಲಿ ಹುದ್ದೆ ಹೊಂದಿದ್ದಾರೆಯೇ?)
                    </label>
                    <?php if (!empty($existingStatePosition)): ?>
                        <div style="background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 6px; padding: 10px 14px; color: #0369a1; font-size: 0.9rem; display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 1.25rem;">🏛️</span>
                            <div>
                                <strong>Currently holds State Committee position:</strong><br>
                                <span style="font-family: 'Noto Sans Kannada', sans-serif; font-weight: 700; color: #1e3a8a;"><?= Sanitize::html($existingStatePosition) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 8px;">
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500;">
                                <input type="radio" name="has_state_position" value="0" checked onchange="toggleStateDesigDropdown(this.value)">
                                No (ಇಲ್ಲ)
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500;">
                                <input type="radio" name="has_state_position" value="1" onchange="toggleStateDesigDropdown(this.value)">
                                Yes (ಹೌದು)
                            </label>
                        </div>
                        <div id="state-desig-dropdown-wrap" style="display: none; margin-top: 8px;">
                            <label for="state_association_designation" style="font-size: 0.85rem; font-weight: 600; color: #1e3a8a;">
                                State Committee Designation (ರಾಜ್ಯ ಸಂಘದ ಹುದ್ದೆ) *
                            </label>
                            <select name="state_association_designation" id="state_association_designation" style="width: 100%; max-width: 450px; margin-top: 4px;">
                                <option value="">— Select State Committee Designation —</option>
                                <?php foreach (($designationsByLevel['state'] ?? []) as $sDes): ?>
                                    <option value="<?= Sanitize::attr($sDes['designation_kn']) ?>">
                                        <?= Sanitize::html($sDes['designation_kn']) ?>
                                        <?= !empty($sDes['designation_en']) ? ' [' . Sanitize::html($sDes['designation_en']) . ']' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint" style="color: #0369a1;">
                                💡 When selected, this person will also be automatically added to the State Committee with this designation. (ದ್ವಿಮುಖ ದಾಖಲಾತಿ ತಪ್ಪಿಸಲು)
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row">
                    <div>
                        <label for="contact_number">Contact Number (ಸಂಪರ್ಕ ಸಂಖ್ಯೆ) — optional</label>
                        <input type="text" id="contact_number" name="contact_number" maxlength="20"
                               value="<?= Sanitize::attr($initialRow['contact_number'] ?? '') ?>"
                               placeholder="ಉದಾ: 9876543210">
                        <div class="hint">🔒 This contact number will only be displayed to logged-in members (login required)</div>
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="term_start">Term Start (ಆರಂಭ ದಿನಾಂಕ) — optional</label>
                        <input type="date" id="term_start" name="term_start"
                               value="<?= Sanitize::attr($initialRow['term_start'] ?? '2026-05-17') ?>">
                        <div class="hint">ರಾಜ್ಯ ಸಂಘದ ಪ್ರಥಮ ಸಭೆಯ ದಿನಾಂಕ: 17-05-2026</div>
                    </div>
                    <div>
                        <label for="term_end">Term End (ಅಂತ್ಯ ದಿನಾಂಕ) — optional</label>
                        <input type="date" id="term_end" name="term_end"
                               value="<?= Sanitize::attr($initialRow['term_end'] ?? '2029-05-16') ?>">
                        <div class="hint">3 ವರ್ಷಗಳ ಅವಧಿ: 17-05-2026 ರಿಂದ 16-05-2029</div>
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="photo">Photo (ಭಾವಚಿತ್ರ) — optional</label>
                        <?php if (!empty($editRow['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($editRow['photo_path'], '/'))): ?>
                            <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px; background:#f8fafc; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;">
                                <img src="/<?= ltrim(Sanitize::attr($editRow['photo_path']), '/') ?>" 
                                     alt="Current photo" 
                                     style="width:48px; height:48px; object-fit:cover; border-radius:50%; border:2px solid #2563eb;">
                                <div>
                                    <div style="font-size:0.82rem; font-weight:600; color:#1e293b;">Current Photo</div>
                                    <label style="font-size:0.75rem; color:#b91c1c; font-weight:500; margin:4px 0 0; display:inline-flex; align-items:center; gap:4px; cursor:pointer;">
                                        <input type="checkbox" name="remove_photo" value="1"> ✕ Remove photo
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                        <div class="hint">Recommended: Clear portrait photo (JPG, PNG, WebP, max 2MB)</div>
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit"><?= $editRow ? 'Save Changes' : 'Add Office Bearer' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn btn-secondary" href="/admin/office-bearers.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Office Bearers Table Panel -->
    <div class="panel">
        <div class="panel-header-flex">
            <div>
                <h2 style="margin-bottom:4px;">Existing Office Bearers (<?= count($officeBearers) ?>)</h2>
                <div class="hint">Drag and drop rows using the <strong>⋮⋮</strong> handle to change display sequence. Changes save automatically.</div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span id="ob-reorder-status" style="display:none; font-size:0.85rem; font-weight:600; padding:5px 12px; border-radius:6px; transition:all 0.3s ease;"></span>
            </div>
        </div>

        <!-- Level Filter Pills for Office Bearers -->
        <div class="filter-pills">
            <a href="/admin/office-bearers.php?ob_level=all" 
               class="filter-pill <?= $obLevelFilter === 'all' ? 'active' : '' ?>">All Levels</a>
            <a href="/admin/office-bearers.php?ob_level=state" 
               class="filter-pill <?= $obLevelFilter === 'state' ? 'active' : '' ?>">State</a>
            <a href="/admin/office-bearers.php?ob_level=district" 
               class="filter-pill <?= $obLevelFilter === 'district' ? 'active' : '' ?>">District</a>
            <a href="/admin/office-bearers.php?ob_level=taluk" 
               class="filter-pill <?= $obLevelFilter === 'taluk' ? 'active' : '' ?>">Taluk</a>
        </div>

        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:120px;">Level</th>
                    <th>Name</th>
                    <th>Designation (Kannada)</th>
                    <th>Contact</th>
                    <th>Term</th>
                    <th style="width:110px; text-align:center;">Reorder</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:130px; text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="obTableBody">
                <?php foreach ($officeBearers as $row): $level = ob_level($row); ?>
                <tr class="draggable-row" draggable="true" data-id="<?= (int)$row['id'] ?>">
                    <td data-label="Level">
                        <span class="badge <?= $level ?>"><?= strtoupper($level) ?></span>
                        <?php if ($level === 'district'): ?>
                            <div style="font-size:0.8rem; font-weight:600; color:#334155; margin-top:3px;"><?= Sanitize::html($row['district_name']) ?></div>
                        <?php elseif ($level === 'taluk'): ?>
                            <div style="font-size:0.8rem; font-weight:600; color:#334155; margin-top:3px;"><?= Sanitize::html($row['taluk_name']) ?></div>
                            <div class="hint"><?= Sanitize::html($row['district_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Name">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($row['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($row['photo_path'], '/'))): ?>
                                <img src="/<?= ltrim(Sanitize::attr($row['photo_path']), '/') ?>" 
                                     alt="<?= Sanitize::attr($row['name']) ?>" 
                                     style="width:42px; height:42px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0;">
                            <?php else: ?>
                                <div style="width:42px; height:42px; border-radius:50%; background:#e2e8f0; color:#475569; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0;">
                                    👤
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong><?= Sanitize::html($row['name']) ?></strong>
                            </div>
                        </div>
                    </td>
                    <td data-label="Designation">
                        <span style="font-weight:600; color:#1e3a8a; font-family:'Noto Sans Kannada', sans-serif;">
                            <?= Sanitize::html($row['association_designation']) ?>
                        </span>
                        <?php if ($level !== 'state' && isset($activeStateBearerMap[mb_strtolower(trim($row['name']))])): ?>
                            <div style="font-size:0.75rem; color:#0369a1; margin-top:3px; display:inline-flex; align-items:center; gap:4px; background:#e0f2fe; padding:2px 8px; border-radius:4px; font-weight:600;">
                                🏛️ ರಾಜ್ಯ: <?= Sanitize::html($activeStateBearerMap[mb_strtolower(trim($row['name']))]) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Contact" style="white-space:nowrap;">
                        <?php if (!empty($row['contact_number'])): ?>
                            <a href="tel:<?= Sanitize::attr($row['contact_number']) ?>" style="color:#2563eb; text-decoration:none; font-weight:500;">
                                📞 <?= Sanitize::html($row['contact_number']) ?>
                            </a>
                        <?php else: ?>
                            <span class="hint">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Term" style="white-space:nowrap;">
                        <?= Sanitize::html($row['term_start'] ?? '—') ?> to <?= Sanitize::html($row['term_end'] ?? '—') ?>
                    </td>
                    <td data-label="Reorder" style="text-align:center;">
                        <div class="drag-handle" title="Click and drag to reorder">
                            <span class="drag-icon">⋮⋮</span>
                            <span class="order-badge"><?= (int)$row['sort_order'] ?></span>
                        </div>
                    </td>
                    <td data-label="Status">
                        <span class="badge <?= $row['status'] === 'active' ? 'active' : 'former' ?>">
                            <?= $row['status'] === 'active' ? 'Active' : 'Former' ?>
                        </span>
                    </td>
                    <td data-label="Actions" style="text-align:center;">
                        <?php 
                            $isAlreadyInState = isset($activeStateBearerMap[mb_strtolower(trim($row['name']))]);
                            $locInfo = '';
                            if ($level === 'taluk') {
                                $locInfo = ($row['taluk_name'] ?? '') . ' ತಾಲ್ಲೂಕು (' . ($row['district_name'] ?? '') . ')';
                            } elseif ($level === 'district') {
                                $locInfo = ($row['district_name'] ?? '') . ' ಜಿಲ್ಲೆ';
                            }
                        ?>
                        <!-- Single Actions Dropdown per Row -->
                        <select class="act-select" onchange="handleObAction(this, <?= (int)$row['id'] ?>, '<?= Sanitize::attr($row['name']) ?>', '<?= $row['status'] ?>')">
                            <option value="">Actions ▾</option>
                            <option value="edit">Edit Details</option>
                            <?php if (in_array($level, ['district', 'taluk'])): ?>
                                <?php if ($isAlreadyInState): ?>
                                    <option value="" disabled style="color:#059669; font-style:italic;">✓ In State: <?= Sanitize::attr($activeStateBearerMap[mb_strtolower(trim($row['name']))]) ?></option>
                                <?php else: ?>
                                    <option value="state_position"
                                            data-id="<?= (int)$row['id'] ?>"
                                            data-name="<?= Sanitize::attr($row['name']) ?>"
                                            data-level="<?= $level ?>"
                                            data-location="<?= Sanitize::attr($locInfo) ?>"
                                            data-desig="<?= Sanitize::attr($row['association_designation']) ?>"
                                            data-term-start="<?= Sanitize::attr($row['term_start'] ?? '') ?>"
                                            data-term-end="<?= Sanitize::attr($row['term_end'] ?? '') ?>">
                                        🏛️ Have Position in State Committee?
                                    </option>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($row['status'] === 'active'): ?>
                                <option value="terminate">⛔ Terminate (Former)</option>
                            <?php else: ?>
                                <option value="reactivate">✓ Reactivate (Active)</option>
                            <?php endif; ?>
                            <option value="delete" style="color:#b91c1c;">✕ Delete Bearer</option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($officeBearers)): ?>
                <tr><td colspan="8" style="text-align:center; padding:24px; color:#64748b;">No office bearers added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB 2: DESIGNATIONS MASTER MANAGEMENT
     ═══════════════════════════════════════════════════════════════════════════ -->
    <div class="byelaw-banner">
        <div><strong>ಸಂಘದ ಬೈಲಾ ಆಧಾರಿತ ಅಧಿಕೃತ ಹುದ್ದೆಗಳ ವಿವರ:</strong></div>
        <div style="margin-top:4px;">
            • <strong>ರಾಜ್ಯ ಸಂಘದ ಸಮಿತಿ:</strong> ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 46 ರ ಪ್ರಕಾರ ಚುನಾಯಿಸಬೇಕಾದ ಹುದ್ದೆಗಳು (ಅಧ್ಯಕ್ಷ, ಉಪಾಧ್ಯಕ್ಷ, ಕಾರ್ಯಾಧ್ಯಕ್ಷ, ಸಂಘಟನಾ ಕಾರ್ಯದರ್ಶಿ, ಇತ್ಯಾದಿ).<br>
            • <strong>ಜಿಲ್ಲಾ ಸಂಘದ ಸಮಿತಿ:</strong> ಸಂಘದ ಉಪನಿಯಮ ಸಂ: 26 ರ ಪ್ರಕಾರ ಜಿಲ್ಲಾವಾರು ಚುನಾಯಿಸಬೇಕಾದ ಹುದ್ದೆಗಳು (ಅಧ್ಯಕ್ಷ, ಉಪಾಧ್ಯಕ್ಷ, ಖಜಾಂಚಿ, ರಾಜ್ಯ ಪರಿಷತ್ ಸದಸ್ಯ, ಇತ್ಯಾದಿ).
        </div>
    </div>

    <!-- Add / Edit Designation Form -->
    <div class="panel">
        <div class="panel-header-flex" style="cursor: pointer; margin-bottom: <?= $editDesigRow ? '18px' : '0' ?>;" onclick="toggleAddDesigForm()">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="addDesigToggleIcon" style="font-size: 0.9rem; color: #1a3a6b; display: inline-block;"><?= $editDesigRow ? '▼' : '▶' ?></span>
                <h2 style="margin: 0;"><?= $editDesigRow ? 'Edit Designation' : 'Add New Designation (ಹೊಸ ಹುದ್ದೆ ಸೇರಿಸಿ)' ?></h2>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn btn-sm btn-outline" onclick="event.stopPropagation(); toggleAddDesigForm();">
                    <span id="addDesigBtnText"><?= $editDesigRow ? '✕ Close Form' : '+ Expand Form' ?></span>
                </button>
                <?php if ($editDesigRow): ?>
                    <a href="/admin/office-bearers.php?view=designations" class="btn btn-secondary btn-sm" onclick="event.stopPropagation();">✕ Cancel Edit</a>
                <?php endif; ?>
            </div>
        </div>

        <div id="addDesigFormBody" style="<?= $editDesigRow ? 'display: block;' : 'display: none;' ?>">
            <form method="post" action="/admin/office-bearers.php" style="margin-top: 15px;">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="action" value="<?= $editDesigRow ? 'update_designation' : 'add_designation' ?>">
                <?php if ($editDesigRow): ?>
                    <input type="hidden" name="desig_id" value="<?= (int) $editDesigRow['id'] ?>">
                <?php endif; ?>

                <div class="row">
                    <div>
                        <label for="desig_level">Committee Level *</label>
                        <select name="desig_level" id="desig_level" required>
                            <option value="state" <?= ($editDesigRow && $editDesigRow['level'] === 'state') || (!$editDesigRow && $desigLevelFilter === 'state') ? 'selected' : '' ?>>State Committee (ರಾಜ್ಯ ಸಂಘ)</option>
                            <option value="district" <?= ($editDesigRow && $editDesigRow['level'] === 'district') || (!$editDesigRow && $desigLevelFilter === 'district') ? 'selected' : '' ?>>District Committee (ಜಿಲ್ಲಾ ಸಂಘ)</option>
                            <option value="taluk" <?= ($editDesigRow && $editDesigRow['level'] === 'taluk') || (!$editDesigRow && $desigLevelFilter === 'taluk') ? 'selected' : '' ?>>Taluk Committee (ತಾಲ್ಲೂಕು ಸಂಘ)</option>
                        </select>
                    </div>
                    <div>
                        <label for="designation_kn">Designation (Kannada) *</label>
                        <input type="text" id="designation_kn" name="designation_kn" required lang="kn"
                               value="<?= Sanitize::attr($editDesigRow['designation_kn'] ?? '') ?>"
                               placeholder="ಉದಾ: ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ">
                    </div>
                    <div>
                        <label for="designation_en">Designation (English) — optional</label>
                        <input type="text" id="designation_en" name="designation_en"
                               value="<?= Sanitize::attr($editDesigRow['designation_en'] ?? '') ?>"
                               placeholder="e.g. President">
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="seats">Seats / Posts Count (ಸ್ಥಾನಗಳ ಸಂಖ್ಯೆ)</label>
                        <input type="number" id="seats" name="seats" min="1" max="50"
                               value="<?= (int) ($editDesigRow['seats'] ?? 1) ?>">
                        <div class="hint">ಉದಾ: 01 ಅಥವಾ 04</div>
                    </div>
                    <?php if ($editDesigRow): ?>
                    <div>
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="active" <?= $editDesigRow['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $editDesigRow['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 18px;">
                    <button type="submit"><?= $editDesigRow ? 'Save Designation Changes' : '+ Add Designation' ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Designations List & Reorder Panel -->
    <div class="panel">
        <div class="panel-header-flex">
            <div>
                <h2 style="margin-bottom:4px;">Designations Master List (ಹುದ್ದೆಗಳ ಪಟ್ಟಿ)</h2>
                <div class="hint">Drag and drop rows using the <strong>⋮⋮</strong> handle to change display sequence. Changes save automatically.</div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span id="reorder-status" style="display:none; font-size:0.85rem; font-weight:600; padding:5px 12px; border-radius:6px; transition:all 0.3s ease;"></span>
            </div>
        </div>

        <!-- Level Filter Pills -->
        <div class="filter-pills">
            <a href="/admin/office-bearers.php?view=designations&desig_level=all" 
               class="filter-pill <?= $desigLevelFilter === 'all' ? 'active' : '' ?>">All Levels</a>
            <a href="/admin/office-bearers.php?view=designations&desig_level=state" 
               class="filter-pill <?= $desigLevelFilter === 'state' ? 'active' : '' ?>">State (ರಾಜ್ಯ ಸಂಘ)</a>
            <a href="/admin/office-bearers.php?view=designations&desig_level=district" 
               class="filter-pill <?= $desigLevelFilter === 'district' ? 'active' : '' ?>">District (ಜಿಲ್ಲಾ ಸಂಘ)</a>
            <a href="/admin/office-bearers.php?view=designations&desig_level=taluk" 
               class="filter-pill <?= $desigLevelFilter === 'taluk' ? 'active' : '' ?>">Taluk (ತಾಲ್ಲೂಕು ಸಂಘ)</a>
        </div>

        <form id="bulkOrderForm" method="post" action="/admin/office-bearers.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="save_drag_order">
            <input type="hidden" name="redirect_level" value="<?= Sanitize::attr($desigLevelFilter) ?>">

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:110px;">Level</th>
                        <th>Designation (Kannada)</th>
                        <th>Designation (English)</th>
                        <th style="width:90px; text-align:center;">Seats</th>
                        <th style="width:110px; text-align:center;">Reorder</th>
                        <th style="width:90px;">Status</th>
                        <th style="width:130px; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="desigTableBody">
                    <?php foreach ($allDesignations as $des): ?>
                    <tr class="draggable-row" draggable="true" data-id="<?= (int)$des['id'] ?>">
                        <td data-label="Level">
                            <span class="badge <?= $des['level'] ?>"><?= strtoupper($des['level']) ?></span>
                        </td>
                        <td data-label="Kannada" style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a;">
                            <?= Sanitize::html($des['designation_kn']) ?>
                        </td>
                        <td data-label="English">
                            <?= !empty($des['designation_en']) ? Sanitize::html($des['designation_en']) : '<span class="hint">—</span>' ?>
                        </td>
                        <td data-label="Seats" style="text-align:center; font-weight:700;">
                            <?= sprintf('%02d', (int)$des['seats']) ?>
                        </td>
                        <td data-label="Reorder" style="text-align:center;">
                            <div class="drag-handle" title="Click and drag to reorder">
                                <span class="drag-icon">⋮⋮</span>
                                <span class="order-badge"><?= (int)$des['sort_order'] ?></span>
                            </div>
                        </td>
                        <td data-label="Status">
                            <span class="badge <?= $des['status'] === 'active' ? 'active' : 'inactive' ?>">
                                <?= ucfirst($des['status']) ?>
                            </span>
                        </td>
                        <td data-label="Actions" style="text-align:center;">
                            <select class="act-select" onchange="handleDesigAction(this, <?= (int)$des['id'] ?>, '<?= Sanitize::attr($des['designation_kn']) ?>')">
                                <option value="">Actions ▾</option>
                                <option value="edit">Edit</option>
                                <option value="toggle"><?= $des['status'] === 'active' ? 'Deactivate' : 'Activate' ?></option>
                                <option value="delete" style="color:#b91c1c;">Delete</option>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allDesignations)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:24px; color:#64748b;">No designations found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Modal for Assigning State Committee Position to District/Taluk Office Bearer -->
<div class="modal-overlay" id="statePosModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
            <div>
                <h3 style="margin:0 0 4px; color:#1e3a8a; font-size:1.2rem;">
                    🏛️ Have a Position in State Committee?
                </h3>
                <div style="font-size:0.86rem; color:#475569;">ರಾಜ್ಯ ಸಂಘದಲ್ಲಿ ಹುದ್ದೆ ಹೊಂದಿದ್ದಾರೆಯೇ?</div>
            </div>
            <button type="button" onclick="closeStatePosModal()" style="background:transparent; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; line-height:1; padding:2px 6px;">✕</button>
        </div>

        <div style="background:#f0f7ff; border:1px solid #bae6fd; border-radius:8px; padding:12px 16px; margin-bottom:18px;">
            <div style="font-size:0.8rem; color:#0369a1; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Office Bearer Details</div>
            <div style="font-size:1.1rem; font-weight:700; color:#0f172a;" id="modalSpName">—</div>
            <div style="display:flex; gap:8px; align-items:center; margin-top:6px; flex-wrap:wrap;">
                <span id="modalSpLevelBadge" class="badge"></span>
                <span style="font-size:0.86rem; color:#334155; font-weight:600;" id="modalSpLocation"></span>
                <span style="color:#94a3b8;">•</span>
                <span style="font-size:0.86rem; color:#1e3a8a; font-weight:600;" id="modalSpDesig"></span>
            </div>
        </div>

        <form method="post" action="/admin/office-bearers.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="assign_state_position">
            <input type="hidden" name="source_ob_id" id="modalSpObId" value="">

            <div style="margin-bottom:16px;">
                <label for="modal_state_association_designation" style="display:block; font-weight:600; color:#1e293b; margin-bottom:6px;">
                    State Committee Designation (ರಾಜ್ಯ ಸಂಘದ ಹುದ್ದೆ) <span style="color:#dc2626;">*</span>
                </label>
                <select name="state_association_designation" id="modal_state_association_designation" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.95rem;">
                    <option value="">— Select State Committee Designation —</option>
                    <?php foreach (($designationsByLevel['state'] ?? []) as $sDes): ?>
                        <option value="<?= Sanitize::attr($sDes['designation_kn']) ?>">
                            <?= Sanitize::html($sDes['designation_kn']) ?>
                            <?= !empty($sDes['designation_en']) ? ' [' . Sanitize::html($sDes['designation_en']) . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="hint" style="font-size:0.8rem; color:#64748b; margin-top:4px;">
                    💡 This office bearer will be automatically created in the State Committee with this designation, copying photo, contact number, and term dates.
                </div>
            </div>

            <div class="row" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
                <div>
                    <label for="modal_term_start" style="font-size:0.85rem; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Term Start (ಆರಂಭ)</label>
                    <input type="date" name="term_start" id="modal_term_start" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                </div>
                <div>
                    <label for="modal_term_end" style="font-size:0.85rem; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Term End (ಅಂತ್ಯ)</label>
                    <input type="date" name="term_end" id="modal_term_end" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:12px;">
                <button type="button" onclick="closeStatePosModal()" class="btn btn-secondary" style="padding:9px 16px; font-weight:600;">
                    Cancel (ರದ್ದುಮಾಡಿ)
                </button>
                <button type="submit" style="background:#1e40af; color:#ffffff; border:none; border-radius:6px; padding:9px 20px; font-weight:600; cursor:pointer;">
                    ✓ Add to State Committee (ರಾಜ್ಯ ಸಂಘಕ್ಕೆ ಸೇರಿಸಿ)
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Action Forms for OB and Desig Row Actions -->
<form id="obActionForm" method="post" action="/admin/office-bearers.php" style="display:none;">
    <?= CSRF::htmlField() ?>
    <input type="hidden" name="action" id="obActionVal" value="">
    <input type="hidden" name="id" id="obActionId" value="">
</form>

<form id="desigActionForm" method="post" action="/admin/office-bearers.php" style="display:none;">
    <?= CSRF::htmlField() ?>
    <input type="hidden" name="action" id="desigActionVal" value="">
    <input type="hidden" name="desig_id" id="desigActionId" value="">
    <input type="hidden" name="direction" id="desigActionDir" value="">
    <input type="hidden" name="redirect_level" value="<?= Sanitize::attr($desigLevelFilter) ?>">
</form>

<script>
// Designation master records per level
var desigMaster = <?= $designationsJson ?>;
var currentEditDesignation = <?= json_encode($editRow['association_designation'] ?? '') ?>;
var currentEditSortOrder = <?= (int)($editRow['sort_order'] ?? 0) ?>;

function obUpdateLevel() {
    var radio = document.querySelector('input[name="level"]:checked');
    if (!radio) return;
    var level = radio.value;

    // Show/hide geography fields
    var distField = document.getElementById('ob-district-field');
    var talukField = document.getElementById('ob-taluk-field');
    if (distField) distField.style.display = (level === 'district' || level === 'taluk') ? 'block' : 'none';
    if (talukField) talukField.style.display = (level === 'taluk') ? 'block' : 'none';

    // Show/hide State Committee position option (for District & Taluk committee addition)
    var statePosBox = document.getElementById('state-position-box');
    if (statePosBox) {
        var isEdit = <?= $editRow ? 'true' : 'false' ?>;
        if (!isEdit && (level === 'district' || level === 'taluk')) {
            statePosBox.style.display = 'block';
        } else {
            statePosBox.style.display = 'none';
        }
    }

    // Populate designations dropdown for this level
    populateDesignationsDropdown(level);
}

function toggleStateDesigDropdown(val) {
    var wrap = document.getElementById('state-desig-dropdown-wrap');
    var select = document.getElementById('state_association_designation');
    if (wrap) {
        wrap.style.display = (val === '1') ? 'block' : 'none';
    }
    if (select && val !== '1') {
        select.value = '';
    }
}

function populateDesignationsDropdown(level) {
    var select = document.getElementById('designation_select');
    var textInput = document.getElementById('association_designation');
    if (!select) return;

    var list = desigMaster[level] || [];
    var currentVal = textInput ? textInput.value.trim() : '';

    var html = '<option value="">— Select Official Designation (Kannada) —</option>';
    var matched = false;

    list.forEach(function(item) {
        var isSelected = (currentVal === item.designation_kn);
        if (isSelected) matched = true;
        
        var seatsStr = (item.seats > 0) ? ' (' + String(item.seats).padStart(2, '0') + ' Posts)' : '';
        var enStr = item.designation_en ? ' [' + item.designation_en + ']' : '';
        
        html += '<option value="' + item.designation_kn.replace(/"/g, '&quot;') + '"' +
                ' data-order="' + item.sort_order + '"' +
                ' data-seats="' + item.seats + '"' +
                (isSelected ? ' selected' : '') + '>' +
                item.designation_kn + enStr + seatsStr +
                '</option>';
    });

    html += '<option value="__custom__"' + (!matched && currentVal !== '' ? ' selected' : '') + '>✍️ Custom Designation / ಇತರೆ...</option>';
    select.innerHTML = html;
}

function onDesignationSelect(sel) {
    var textInput = document.getElementById('association_designation');
    var sortInput = document.getElementById('sort_order');
    var val = sel.value;

    if (val === '__custom__') {
        if (textInput) {
            textInput.focus();
        }
        return;
    }

    if (val !== '') {
        if (textInput) textInput.value = val;
        
        // Auto-assign default sort order if current is empty or 0
        var opt = sel.options[sel.selectedIndex];
        var order = opt.getAttribute('data-order');
        if (sortInput && order && (parseInt(sortInput.value, 10) === 0 || !sortInput.value)) {
            sortInput.value = order;
        }
    }
}

// Actions dropdown handler for office bearers
function handleObAction(sel, id, name, status) {
    var val = sel.value;
    if (!val) return;

    if (val === 'state_position') {
        var opt = sel.options[sel.selectedIndex];
        var obId = opt.getAttribute('data-id') || id;
        var obName = opt.getAttribute('data-name') || name;
        var obLevel = opt.getAttribute('data-level') || 'district';
        var obLocation = opt.getAttribute('data-location') || '';
        var obDesig = opt.getAttribute('data-desig') || '';
        var termStart = opt.getAttribute('data-term-start') || '2026-05-17';
        var termEnd = opt.getAttribute('data-term-end') || '2029-05-16';
        sel.value = '';
        openStatePositionModal(obId, obName, obLevel, obLocation, obDesig, termStart, termEnd);
        return;
    }

    sel.value = ''; // Reset immediately

    if (val === 'edit') {
        window.location.href = '/admin/office-bearers.php?edit=' + id;
        return;
    }
    if (val === 'terminate') {
        if (confirm('Are you sure you want to terminate office bearer "' + name + '"?\nThis will mark their status as Former.')) {
            submitObForm('terminate', id);
        }
        return;
    }
    if (val === 'reactivate') {
        if (confirm('Reactivate office bearer "' + name + '" as Active?')) {
            submitObForm('reactivate', id);
        }
        return;
    }
    if (val === 'delete') {
        if (confirm('Are you sure you want to PERMANENTLY delete office bearer "' + name + '"?\nThis action cannot be undone.')) {
            submitObForm('delete', id);
        }
        return;
    }
}

function openStatePositionModal(id, name, level, locationName, currentDesig, termStart, termEnd) {
    document.getElementById('modalSpObId').value = id;
    document.getElementById('modalSpName').textContent = name;
    var badge = document.getElementById('modalSpLevelBadge');
    badge.className = 'badge ' + level;
    badge.textContent = level.toUpperCase();
    document.getElementById('modalSpLocation').textContent = locationName || '';
    document.getElementById('modalSpDesig').textContent = currentDesig || '';
    document.getElementById('modal_term_start').value = termStart || '2026-05-17';
    document.getElementById('modal_term_end').value = termEnd || '2029-05-16';
    document.getElementById('modal_state_association_designation').value = '';
    
    var modal = document.getElementById('statePosModal');
    if (modal) modal.classList.add('open');
}

function closeStatePosModal() {
    var modal = document.getElementById('statePosModal');
    if (modal) modal.classList.remove('open');
}

document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('statePosModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeStatePosModal();
        });
    }
});

function submitObForm(action, id) {
    var form = document.getElementById('obActionForm');
    document.getElementById('obActionVal').value = action;
    document.getElementById('obActionId').value = id;
    form.submit();
}

// Actions dropdown handler for designations
function handleDesigAction(sel, id, name) {
    var val = sel.value;
    if (!val) return;
    sel.value = '';

    if (val === 'edit') {
        window.location.href = '/admin/office-bearers.php?view=designations&edit_desig=' + id;
        return;
    }
    if (val === 'toggle') {
        submitDesigForm('toggle_desig_status', id);
        return;
    }
    if (val === 'delete') {
        if (confirm('Delete designation "' + name + '"?')) {
            submitDesigForm('delete_designation', id);
        }
        return;
    }
}

function moveDesignation(id, dir) {
    var form = document.getElementById('desigActionForm');
    document.getElementById('desigActionVal').value = 'move_designation';
    document.getElementById('desigActionId').value = id;
    document.getElementById('desigActionDir').value = dir;
    form.submit();
}

function submitDesigForm(action, id) {
    var form = document.getElementById('desigActionForm');
    document.getElementById('desigActionVal').value = action;
    document.getElementById('desigActionId').value = id;
    form.submit();
}

// Drag and Drop Table Reordering
function setupTableDragAndDrop(tbodyId, statusSpanId, actionName, redirectParamName, redirectParamVal) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    var rows = tbody.querySelectorAll('.draggable-row');
    var draggedRow = null;

    rows.forEach(function(row) {
        row.setAttribute('draggable', 'true');

        row.addEventListener('dragstart', function(e) {
            draggedRow = this;
            this.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.getAttribute('data-id') || '');
        });

        row.addEventListener('dragend', function() {
            this.classList.remove('is-dragging');
            rows.forEach(function(r) {
                r.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            draggedRow = null;
        });

        row.addEventListener('dragover', function(e) {
            e.preventDefault();
            if (!draggedRow || draggedRow === this) return;
            e.dataTransfer.dropEffect = 'move';

            var rect = this.getBoundingClientRect();
            var offset = e.clientY - rect.top;
            var middle = rect.height / 2;

            if (offset < middle) {
                this.classList.add('drag-over-top');
                this.classList.remove('drag-over-bottom');
            } else {
                this.classList.add('drag-over-bottom');
                this.classList.remove('drag-over-top');
            }
        });

        row.addEventListener('dragleave', function() {
            this.classList.remove('drag-over-top', 'drag-over-bottom');
        });

        row.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over-top', 'drag-over-bottom');

            if (!draggedRow || draggedRow === this) return;

            var rect = this.getBoundingClientRect();
            var offset = e.clientY - rect.top;
            var middle = rect.height / 2;

            if (offset < middle) {
                tbody.insertBefore(draggedRow, this);
            } else {
                tbody.insertBefore(draggedRow, this.nextSibling);
            }

            saveReorderedTable(tbodyId, statusSpanId, actionName, redirectParamName, redirectParamVal);
        });
    });
}

function saveReorderedTable(tbodyId, statusSpanId, actionName, redirectParamName, redirectParamVal) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    var rows = tbody.querySelectorAll('.draggable-row');
    var orderedIds = [];

    rows.forEach(function(row, index) {
        var newOrder = index + 1;
        var badge = row.querySelector('.order-badge');
        if (badge) {
            badge.textContent = newOrder;
        }
        var rowId = row.getAttribute('data-id');
        if (rowId) {
            orderedIds.push(rowId);
        }
    });

    if (orderedIds.length === 0) return;

    var statusSpan = document.getElementById(statusSpanId);
    if (statusSpan) {
        statusSpan.style.display = 'inline-block';
        statusSpan.style.background = '#eff6ff';
        statusSpan.style.color = '#1d4ed8';
        statusSpan.style.border = '1px solid #bfdbfe';
        statusSpan.textContent = '⏳ Saving order...';
    }

    var csrfInput = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    var formData = new FormData();
    formData.append('action', actionName);
    formData.append('csrf_token', csrfToken);
    formData.append('ajax', '1');
    if (redirectParamName && redirectParamVal) {
        formData.append(redirectParamName, redirectParamVal);
    }
    orderedIds.forEach(function(id) {
        formData.append('ordered_ids[]', id);
    });

    fetch('/admin/office-bearers.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (statusSpan) {
            if (data && data.success) {
                statusSpan.style.background = '#dcfce7';
                statusSpan.style.color = '#15803d';
                statusSpan.style.border = '1px solid #86efac';
                statusSpan.textContent = '✓ Order saved!';
                setTimeout(function() {
                    statusSpan.style.display = 'none';
                }, 2500);
            } else {
                statusSpan.style.background = '#fee2e2';
                statusSpan.style.color = '#b91c1c';
                statusSpan.style.border = '1px solid #fca5a5';
                statusSpan.textContent = '✕ Save failed';
            }
        }
    })
    .catch(function() {
        if (statusSpan) {
            statusSpan.style.background = '#fee2e2';
            statusSpan.style.color = '#b91c1c';
            statusSpan.style.border = '1px solid #fca5a5';
            statusSpan.textContent = '✕ Connection error.';
        }
    });
}

function toggleAddObForm() {
    var body = document.getElementById('addObFormBody');
    var icon = document.getElementById('addObToggleIcon');
    var btnText = document.getElementById('addObBtnText');
    if (!body) return;
    if (body.style.display === 'none' || body.style.display === '') {
        body.style.display = 'block';
        if (icon) icon.textContent = '▼';
        if (btnText) btnText.textContent = '✕ Close Form';
    } else {
        body.style.display = 'none';
        if (icon) icon.textContent = '▶';
        if (btnText) btnText.textContent = '+ Expand Form';
    }
}

function toggleAddDesigForm() {
    var body = document.getElementById('addDesigFormBody');
    var icon = document.getElementById('addDesigToggleIcon');
    var btnText = document.getElementById('addDesigBtnText');
    if (!body) return;
    if (body.style.display === 'none' || body.style.display === '') {
        body.style.display = 'block';
        if (icon) icon.textContent = '▼';
        if (btnText) btnText.textContent = '✕ Close Form';
    } else {
        body.style.display = 'none';
        if (icon) icon.textContent = '▶';
        if (btnText) btnText.textContent = '+ Expand Form';
    }
}

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', function() {
    obUpdateLevel();
    setupTableDragAndDrop('desigTableBody', 'reorder-status', 'save_drag_order', 'redirect_level', <?= json_encode($desigLevelFilter) ?>);
    setupTableDragAndDrop('obTableBody', 'ob-reorder-status', 'save_ob_drag_order', 'redirect_ob_level', <?= json_encode($obLevelFilter) ?>);
});
</script>
<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
