<?php
// api/visits.php — GET list, POST create
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sv_followup_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (is_admin()) {
        $stmt = $pdo->query(
            'SELECT v.*, c.name AS client_name FROM visits v
             JOIN clients c ON c.id = v.client_id ORDER BY v.id DESC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT v.*, c.name AS client_name FROM visits v
             JOIN clients c ON c.id = v.client_id
             WHERE c.salesperson_id = ? OR c.created_by = ? ORDER BY v.id DESC'
        );
        $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID]);
    }
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Entry Desk is view-only on site visits.
    if (!is_admin() && $CURRENT_USER_DESK === 'entry') {
        http_response_code(403);
        echo json_encode(['error' => 'Entry Desk cannot schedule site visits']);
        exit;
    }
    $d = json_decode(file_get_contents('php://input'), true);

    if (empty($d['client']) || empty($d['date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Client and date are required']);
        exit;
    }

    // Visit type is auto-detected from the lead's current stage (same idea as the Add
    // Follow-up form): Negotiation Visit Scheduled and later -> negotiation, Re-Visit
    // Scheduled and later -> revisit, otherwise a site visit. Status is always Scheduled.
    $st = $pdo->prepare('SELECT stage, salesperson_id FROM clients WHERE id = ?');
    $st->execute([$d['client']]);
    $lead = $st->fetch();
    if (!$lead) {
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
        exit;
    }
    // Same rule as Add Follow-up (api/followups.php): only the lead's own salesperson or an admin.
    if (!is_admin() && (int)$lead['salesperson_id'] !== (int)$CURRENT_USER_ID) {
        http_response_code(403);
        echo json_encode(['error' => 'Not your lead']);
        exit;
    }
    $stage = $lead['stage'];
    $kind = stage_reached($stage, 'Negotiation Visit Scheduled') ? 'negotiation'
        : (stage_reached($stage, 'Re-Visit Scheduled') ? 'revisit' : 'site');
    $statusLabel = 'Scheduled';

    $stmt = $pdo->prepare(
        'INSERT INTO visits (client_id, kind, visit_date, visit_time, visitors, villa, status, outcome, feedback)
         VALUES (:client_id, :kind, :visit_date, :visit_time, :visitors, :villa, :status, :outcome, :feedback)'
    );
    $stmt->execute([
        'client_id' => $d['client'], 'kind' => $kind, 'visit_date' => $d['date'], 'visit_time' => ($d['time'] ?? '') ?: null,
        'visitors' => ($d['visitors'] ?? 1) ?: 1, 'villa' => $d['villa'] ?? null,
        'status' => $statusLabel, 'outcome' => $d['outcome'] ?? null,
        'feedback' => $d['feedback'] ?? null,
    ]);

    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
