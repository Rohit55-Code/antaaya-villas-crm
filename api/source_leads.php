<?php
// api/source_leads.php — Entry Desk + Admin only.
// GET    -> list staged source leads (website/social submissions awaiting review)
// POST   -> { action: 'assign', id, salesperson_id? } confirms a source lead,
//           creates the real `clients` row (gets a real Lead ID) and puts it
//           into the normal desk pipeline. Only this action ever writes to
//           `clients` — everything else in this file stays isolated.
// DELETE -> { id } reject/remove a source lead. Never touches `clients`.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/notif_log_lib.php';

function can_manage_source_leads() {
    global $CURRENT_USER_DESK;
    return is_admin() || $CURRENT_USER_DESK === 'entry';
}

if (!can_manage_source_leads()) {
    http_response_code(403);
    echo json_encode(['error' => 'Only Entry Desk or Admin can access Source Leads']);
    exit;
}

function desk_user_id($pdo, $desk) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE desk = ? LIMIT 1");
    $stmt->execute([$desk]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    return (int)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        'SELECT sl.*, rb.name AS reviewed_by_name, ac.lead_code AS assigned_lead_code
         FROM source_leads sl
         LEFT JOIN users rb ON rb.id = sl.reviewed_by
         LEFT JOIN clients ac ON ac.id = sl.assigned_lead_id
         ORDER BY sl.created_at DESC'
    );
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    $action = $d['action'] ?? '';
    if (!in_array($action, ['assign', 'edit'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $id = $d['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    if ($action === 'edit') {
        // Re-edit the reviewer's corrected details before assigning — only
        // allowed while still New; never touches `clients`.
        $stmt = $pdo->prepare('SELECT status FROM source_leads WHERE id = ?');
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        if ($status === false) { http_response_code(404); echo json_encode(['error' => 'Source lead not found']); exit; }
        if ($status !== 'New') { http_response_code(409); echo json_encode(['error' => 'Only New entries can be edited']); exit; }

        $EDITABLE = ['name', 'mobile', 'alt_mobile', 'email', 'city', 'source', 'subsource', 'budget', 'config', 'purpose', 'category', 'notes'];
        $set = []; $params = ['id' => $id];
        foreach ($EDITABLE as $f) {
            if (array_key_exists($f, $d)) { $set[] = "$f = :$f"; $params[$f] = $d[$f] !== '' ? $d[$f] : null; }
        }
        if ($set) {
            $pdo->prepare('UPDATE source_leads SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM source_leads WHERE id = ?');
    $stmt->execute([$id]);
    $sl = $stmt->fetch();
    if (!$sl) { http_response_code(404); echo json_encode(['error' => 'Source lead not found']); exit; }
    if ($sl['status'] !== 'New') {
        http_response_code(409);
        echo json_encode(['error' => 'This source lead was already ' . strtolower($sl['status'])]);
        exit;
    }
    if (!$sl['name'] || !$sl['mobile']) {
        http_response_code(400);
        echo json_encode(['error' => 'This entry is missing a name or contact number and cannot be assigned — edit it, or delete it.']);
        exit;
    }

    // Both Entry Desk and Admin can hand-pick the salesperson; if left on
    // "Auto", fall back to the normal desk-routing rule (Broker -> broker
    // desk, else -> owner desk).
    $salesperson_id = !empty($d['salesperson_id'])
        ? (int)$d['salesperson_id']
        : desk_user_id($pdo, $sl['category'] === 'Broker' ? 'broker' : 'owner');

    $entryType = $sl['form_type'] === 'broker' ? 'Broker Visit'
        : ($sl['form_type'] === 'sitevisit' ? 'Site Visit'
        : ($sl['form_type'] === 'enquiry' ? 'Enquiry' : 'Manual'));

    $noteHeader = "[" . date('d M Y H:i') . "] Assigned from Source Leads (" . $sl['site'] . ' / ' . $sl['form_type'] . ')';
    $notes = $sl['notes'] ? "$noteHeader — " . $sl['notes'] : $noteHeader;

    $fields = [
        'name' => $sl['name'], 'mobile' => $sl['mobile'], 'alt_mobile' => $sl['alt_mobile'],
        'email' => $sl['email'], 'city' => $sl['city'], 'source' => $sl['source'] ?: ucfirst($sl['site']),
        'subsource' => $sl['subsource'], 'salesperson_id' => $salesperson_id, 'created_date' => date('Y-m-d'),
        'config' => $sl['config'], 'budget' => $sl['budget'], 'purpose' => $sl['purpose'],
        'interest' => 'WARM', 'stage' => 'New Lead', 'notes' => $notes,
        'category' => $sl['category'], 'entry_channel' => 'Form', 'entry_type' => $entryType,
        'created_by' => $CURRENT_USER_ID, 'lead_code' => null,
    ];

    $pdo->beginTransaction();
    $cols = implode(', ', array_keys($fields));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
    $stmt = $pdo->prepare("INSERT INTO clients ($cols) VALUES ($ph)");
    $stmt->execute($fields);
    $newId = $pdo->lastInsertId();
    $code = 'L' . str_pad($newId, 3, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE clients SET lead_code = ? WHERE id = ?')->execute([$code, $newId]);

    $pdo->prepare(
        "UPDATE source_leads SET status = 'Assigned', reviewed_by = ?, reviewed_at = NOW(),
         assigned_lead_id = ?, assigned_salesperson_id = ? WHERE id = ?"
    )->execute([$CURRENT_USER_ID, $newId, $salesperson_id, $id]);
    $pdo->commit();

    log_notification($pdo, 'source_assigned', $CURRENT_USER_ID, $salesperson_id, $newId, $id, $sl['name']);

    echo json_encode(['success' => true, 'id' => (int)$newId, 'lead_code' => $code]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    // Reject/remove — never touches `clients`. Kept as a status flip rather
    // than a hard row delete so there's still an audit trail of what was
    // discarded and by whom.
    $stmt = $pdo->prepare('SELECT status, name FROM source_leads WHERE id = ?');
    $stmt->execute([$id]);
    $sl = $stmt->fetch();
    if (!$sl) { http_response_code(404); echo json_encode(['error' => 'Source lead not found']); exit; }
    if ($sl['status'] === 'Assigned') {
        http_response_code(409);
        echo json_encode(['error' => 'Already assigned to a lead — cannot delete']);
        exit;
    }

    $pdo->prepare("UPDATE source_leads SET status = 'Rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([$CURRENT_USER_ID, $id]);
    log_notification($pdo, 'source_rejected', $CURRENT_USER_ID, null, null, $id, $sl['name']);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
