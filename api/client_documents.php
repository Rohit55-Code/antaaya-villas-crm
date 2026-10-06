<?php
// api/client_documents.php — Booking & Legal documents for a lead.
// GET  ?client_id=ID                     -> list documents (JSON)
// GET  ?action=download&id=DOC[&inline=1] -> stream the file (inline=1 opens it in the browser)
// POST multipart: client_id, doc_type, doc_label?, file -> upload
// POST JSON { action: "delete", id }      -> delete
// View/download: admin, the assigned salesperson, or whoever created the lead.
// Upload/delete: admin or the assigned salesperson until the lead goes to Post Sales (same rule as editing the lead),
// plus the Legal desk on Booking & Legal documents (until the lead goes to Accounts)
// and the Accounts desk on Construction & Payment / Possession documents (leads transferred to Accounts).
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/legal_docs_lib.php';
require_once __DIR__ . '/sv_followup_lib.php';

$DOC_DIR = __DIR__ . '/../uploads/client_docs';
const DOC_MAX_BYTES = 10 * 1024 * 1024;
const DOC_ALLOWED = [
    'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

function fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

function client_access($pdo, $clientId) {
    global $CURRENT_USER_ID;
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$clientId]);
    $c = $st->fetch();
    if (!$c) fail(404, 'Lead not found');
    global $CURRENT_USER_DESK;
    // Legal desk: edits while the lead is in Booking & Legal, view-only once it's with Accounts.
    // Accounts desk: only leads transferred to Accounts (Handover Completed documents).
    $withAcc = lead_with_accounts($c);
    // The salesperson only until sales hands the lead over to Post Sales (same rule as editing the lead).
    $edit = is_admin() || ((int)$c['salesperson_id'] === (int)$CURRENT_USER_ID && !lead_with_post_sales($c))
        || (is_legal_desk() && lead_with_post_sales($c) && !$withAcc)
        || (is_accounts_desk() && $withAcc);

    // Post Sales documents are off-limits to the Entry Desk.
    $view = $edit || (int)$c['salesperson_id'] === (int)$CURRENT_USER_ID
        || ((int)$c['created_by'] === (int)$CURRENT_USER_ID && $CURRENT_USER_DESK !== 'entry')
        || (is_legal_desk() && lead_with_post_sales($c));
    return ['view' => $view, 'edit' => $edit];
}

// Legal desk works the Booking & Legal documents, Accounts desk the Construction & Payment /
// Possession ones (payment proofs, TDS certificates, architect certificates, OC, allotment letter…).
function desk_doc_stage_ok($stage) {
    if (is_legal_desk()) return !in_array($stage, ACCOUNTS_STAGES, true);
    // Accounts: construction stages + Possession Due's "OC / CC Received" demand documents; the rest of Possession is admin's.
    if (is_accounts_desk()) return in_array($stage, ACCOUNTS_MILESTONE_STAGES, true);
    return true;
}

// Lead History entry for a document upload/delete, written straight to the lead so it's
// kept even if Edit Client is closed without saving. Returns [entry, full log JSON].
function doc_history($pdo, $clientId, $note) {
    global $CURRENT_USER_NAME;
    $entry = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'type' => 'Document', 'note' => $note, 'by' => $CURRENT_USER_NAME];
    $st = $pdo->prepare('SELECT activity_log FROM clients WHERE id = ?');
    $st->execute([$clientId]);
    $log = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($log)) $log = [];
    $log[] = $entry;
    $json = json_encode($log, JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE clients SET activity_log = ? WHERE id = ?')->execute([$json, $clientId]);
    return [$entry, $json];
}
function doc_title($doc) {
    $label = $doc['doc_type'] === 'other' && !empty($doc['doc_label']) ? $doc['doc_label'] : (LEGAL_DOC_TYPES[$doc['doc_type']]['label'] ?? 'Document');
    return $label . ' (' . $doc['original_name'] . ')';
}

function load_doc($pdo, $id) {
    try {
        $st = $pdo->prepare('SELECT * FROM client_documents WHERE id = ?');
        $st->execute([$id]);
    } catch (PDOException $e) { fail(500, 'Documents table missing — run migration_v31.sql in phpMyAdmin first.'); }
    $d = $st->fetch();
    if (!$d) fail(404, 'Document not found');
    return $d;
}

// ---------- GET ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') === 'download') {
        $doc = load_doc($pdo, (int)($_GET['id'] ?? 0));
        if (!client_access($pdo, $doc['client_id'])['view']) fail(403, 'Not allowed');
        $path = $DOC_DIR . '/' . (int)$doc['client_id'] . '/' . basename($doc['stored_name']);
        if (!is_file($path)) fail(404, 'File missing on server');

        $name = preg_replace('/[\r\n"]+/', '', $doc['original_name']);
        $disp = !empty($_GET['inline']) ? 'inline' : 'attachment';
        header_remove('Pragma');
        header('Content-Type: ' . ($doc['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: $disp; filename=\"" . preg_replace('/[^\x20-\x7E]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    $clientId = (int)($_GET['client_id'] ?? 0);
    if (!$clientId) fail(400, 'client_id required');
    if (!client_access($pdo, $clientId)['view']) fail(403, 'Not allowed');
    try {
        $st = $pdo->prepare(
            'SELECT d.id, d.doc_type, d.doc_label, d.stage, d.original_name, d.mime, d.size, d.uploaded_at, u.name AS uploaded_by_name
             FROM client_documents d LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.client_id = ? ORDER BY d.uploaded_at, d.id'
        );
        $st->execute([$clientId]);
    } catch (PDOException $e) { fail(500, 'Documents table missing — run migration_v31.sql in phpMyAdmin first.'); }
    echo json_encode($st->fetchAll());
    exit;
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete
    if (empty($_FILES)) {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if (($in['action'] ?? '') !== 'delete') {
            // An empty $_FILES on a real upload usually means the file was bigger than post_max_size.
            fail(400, 'No file received — it may be larger than the server upload limit.');
        }
        $doc = load_doc($pdo, (int)($in['id'] ?? 0));
        if (!client_access($pdo, $doc['client_id'])['edit']) fail(403, 'You can\'t delete documents on this lead');
        if (!desk_doc_stage_ok($doc['stage'] ?? '')) fail(403, 'This document belongs to the other Post Sales desk.');
        $path = $DOC_DIR . '/' . (int)$doc['client_id'] . '/' . basename($doc['stored_name']);
        $pdo->prepare('DELETE FROM client_documents WHERE id = ?')->execute([$doc['id']]);
        if (is_file($path)) unlink($path);
        [$entry, $log] = doc_history($pdo, (int)$doc['client_id'], 'Document deleted — ' . doc_title($doc));
        echo json_encode(['success' => true, 'log_entry' => $entry, 'activity_log' => $log]);
        exit;
    }

    // Upload
    $clientId = (int)($_POST['client_id'] ?? 0);
    $type = $_POST['doc_type'] ?? '';
    if (!$clientId) fail(400, 'client_id required');
    if (!isset(LEGAL_DOC_TYPES[$type])) fail(400, 'Unknown document type');
    if (!client_access($pdo, $clientId)['edit']) fail(403, 'You can\'t upload documents on this lead');

    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $tooBig = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        fail(400, $tooBig ? 'File is larger than the server upload limit.' : 'Upload failed — please try again.');
    }
    if ($f['size'] > DOC_MAX_BYTES) fail(400, 'File must be under 10 MB');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $origExt = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    // .docx is a zip container — some servers report it as application/zip.
    if ($origExt === 'docx' && in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    if (!isset(DOC_ALLOWED[$mime])) fail(400, 'Only PDF, JPG, PNG, WEBP, DOC or DOCX files are allowed');
    $ext = DOC_ALLOWED[$mime];

    $dir = $DOC_DIR . '/' . $clientId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail(500, 'Could not create upload folder');
    $stored = $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) fail(500, 'Failed to save file');

    $label = trim($_POST['doc_label'] ?? '');
    $docStage = in_array($_POST['stage'] ?? '', LEGAL_DOC_STAGES, true) ? $_POST['stage'] : LEGAL_DOC_TYPES[$type]['stage'];
    if (!desk_doc_stage_ok($docStage)) fail(403, 'This document belongs to the other Post Sales desk.');
    // Construction stages keep their own documents: any stage reached so far (earlier ones stay open), never a future one.
    $stgIdx = array_search($docStage, FOLLOWUP_PIPELINE, true);
    if ($stgIdx !== false && in_array($docStage, ACCOUNTS_MILESTONE_STAGES, true)) {
        $cs = $pdo->prepare('SELECT stage FROM clients WHERE id = ?');
        $cs->execute([$clientId]);
        $leadIdx = array_search((string)$cs->fetchColumn(), FOLLOWUP_PIPELINE, true);
        if ($docStage !== 'Construction Customer' && ($leadIdx === false || $stgIdx > $leadIdx)) {
            unlink($dir . '/' . $stored);
            fail(400, "\"$docStage\" isn't reached yet — documents can be added once the construction status is updated to it.");
        }
    }
    $orig = mb_substr(basename($f['name']), 0, 200);
    try {
        $pdo->prepare(
            'INSERT INTO client_documents (client_id, doc_type, doc_label, stage, original_name, stored_name, mime, size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$clientId, $type, $label !== '' ? mb_substr($label, 0, 190) : null, $docStage, $orig, $stored, $mime, (int)$f['size'], $CURRENT_USER_ID]);
    } catch (PDOException $e) {
        unlink($dir . '/' . $stored);
        fail(500, 'Documents table missing — run migration_v31.sql in phpMyAdmin first.');
    }
    $newId = (int)$pdo->lastInsertId();
    $stageNote = ($docStage && $docStage !== '*') ? " · $docStage" : '';
    [$entry, $log] = doc_history($pdo, $clientId, 'Document uploaded — '
        . doc_title(['doc_type' => $type, 'doc_label' => $label, 'original_name' => $orig]) . $stageNote);
    echo json_encode(['success' => true, 'id' => $newId, 'log_entry' => $entry, 'activity_log' => $log]);
    exit;
}

fail(405, 'Method not allowed');
