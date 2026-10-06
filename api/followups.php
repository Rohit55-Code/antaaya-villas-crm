<?php
// api/followups.php — GET list, POST create
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sv_followup_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (is_admin()) {
        $stmt = $pdo->query(
            'SELECT f.*, c.name AS client_name FROM followups f
             JOIN clients c ON c.id = f.client_id ORDER BY f.id DESC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT f.*, c.name AS client_name FROM followups f
             JOIN clients c ON c.id = f.client_id
             WHERE f.salesperson_id = ? OR c.created_by = ? ORDER BY f.id DESC'
        );
        $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID]);
    }
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The Add Follow-up form auto-detects the follow-up type from the lead's current
    // stage (Site Visit / Post Site Visit / Re-Visit / Negotiation Visit / Villa Blocked — see
    // followup_stage_for()). It writes into that stage's own fields on the lead (so Lead
    // Summary, Lead History and the stage screen stay in sync) and upserts the one follow-up
    // row for that type. Interest/status are set automatically.
    $d = json_decode(file_get_contents('php://input'), true);

    if (empty($d['client']) || empty($d['date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Client and date are required']);
        exit;
    }
    $refollow = ($d['refollow'] ?? '') === 'Yes' ? 'Yes' : 'No';
    $reDate = $refollow === 'Yes' ? ($d['refollow_date'] ?? '') : '';
    if ($refollow === 'Yes' && !$reDate) {
        http_response_code(400);
        echo json_encode(['error' => 'Please pick the re-follow-up date']);
        exit;
    }
    $mode = $d['mode'] ?? null;

    $c = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $c->execute([$d['client']]);
    $client = $c->fetch();
    if (!$client) { http_response_code(404); echo json_encode(['error' => 'Client not found']); exit; }
    if (!is_admin() && (int)$client['salesperson_id'] !== (int)$CURRENT_USER_ID) {
        http_response_code(403); echo json_encode(['error' => 'Not your lead']); exit;
    }
    if (!is_admin() && !empty($client['sales_handover_at'])) {
        http_response_code(403); echo json_encode(['error' => 'This lead has been transferred to the Post Sales team.']); exit;
    }

    $fuStage = followup_stage_for($client['stage']);
    $cfg = FOLLOWUP_STAGES[$fuStage];
    $p = $cfg['prefix']; // column prefix — from the fixed map above, never user input

    // Lead History entries (date/time come from the browser, same as the rest of the history).
    $ld = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['ldate'] ?? '') ? $d['ldate'] : date('Y-m-d');
    $lt = preg_match('/^\d{2}:\d{2}$/', $d['ltime'] ?? '') ? $d['ltime'] : date('H:i');
    $log = json_decode((string)$client['activity_log'], true);
    if (!is_array($log)) $log = [];

    $stage = $client['stage'];
    if ($stage === $cfg['from']) { // e.g. Site Visit Completed → Post Site Visit Follow-up
        $stage = $fuStage;
        $log[] = ['date' => $ld, 'time' => $lt, 'type' => 'Stage Change', 'note' => 'Moved to ' . $fuStage, 'by' => $CURRENT_USER_NAME];
    }
    $note = $cfg['label'] . ' — ' . $d['date'] . ($mode ? ' via ' . $mode : '');
    $note .= $refollow === 'Yes' ? ' · Re-follow-up on ' . $reDate : ' · No re-follow-up needed';
    $log[] = ['date' => $ld, 'time' => $lt, 'type' => 'Follow-up', 'note' => $note, 'by' => $CURRENT_USER_NAME];

    $pdo->prepare(
        "UPDATE clients SET {$p}_followup_date = ?, {$p}_followup_mode = ?, {$p}_refollow = ?, {$p}_refollow_date = ?, stage = ?, activity_log = ? WHERE id = ?"
    )->execute([$d['date'], $mode ?: null, $refollow, $reDate ?: null, $stage, json_encode($log, JSON_UNESCAPED_UNICODE), $client['id']]);

    sync_stage_followup($pdo, $fuStage, $client['id'], $d['date'], $mode, $client[$p . '_followup_note'], $refollow, $reDate, $CURRENT_USER_ID, $client['salesperson_id']);
    complete_stage_followups_if_past($pdo, $client['id'], $stage);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
