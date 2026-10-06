<?php
// api/complete_followup.php — POST { id, date?, time?, spawn_next? }
// spawn_next: true = "Complete, but re-follow-up still pending" — closes this
// row AND opens a new Pending follow-up row on the row's own next_date (the
// re-follow-up date), so it keeps showing on the Follow-ups page, Dashboard
// and Lead Summary until it's done too. Only meaningful when the row has a
// next_date; ignored otherwise (plain complete, same as before).
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sv_followup_lib.php';

$d = json_decode(file_get_contents('php://input'), true);
$id = $d['id'] ?? null;
if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

// Typed follow-ups (e.g. "After Scheduled Site Visit") also leave a line in the
// lead's Lead History when completed. date/time come from the browser so the
// entry matches the rest of the history's clock.
$row = $pdo->prepare('SELECT client_id, salesperson_id, followup_date, mode, followup_type, interest, next_date, status FROM followups WHERE id = ?');
$row->execute([$id]);
$fu = $row->fetch();

if (is_admin()) {
    $stmt = $pdo->prepare('UPDATE followups SET status = "Completed" WHERE id = ?');
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare('UPDATE followups SET status = "Completed" WHERE id = ? AND salesperson_id = ?');
    $stmt->execute([$id, $CURRENT_USER_ID]);
}

$spawnNext = !empty($d['spawn_next']) && $fu && $fu['next_date'];

if ($fu && $fu['followup_type'] && $fu['status'] !== 'Completed' && $stmt->rowCount() > 0) {
    if ($spawnNext) {
        // Open the next occurrence: same type/client/salesperson, due on the
        // re-follow-up date, starting fresh (no re-follow of its own yet).
        $pdo->prepare(
            'INSERT INTO followups (client_id, salesperson_id, followup_date, mode, followup_type, interest, status)
             VALUES (?, ?, ?, ?, ?, ?, "Pending")'
        )->execute([$fu['client_id'], $fu['salesperson_id'], $fu['next_date'], $fu['mode'], $fu['followup_type'], $fu['interest']]);

        // Mirror onto the lead's own stage fields so the Edit Client stage-section
        // and Lead Summary reflect this as the new upcoming follow-up, not the old one.
        foreach (FOLLOWUP_STAGES as $cfg) {
            if ($cfg['type'] !== $fu['followup_type']) continue;
            $p = $cfg['prefix'];
            $pdo->prepare("UPDATE clients SET {$p}_followup_date = ?, {$p}_followup_mode = ?, {$p}_refollow = 'No', {$p}_refollow_date = NULL WHERE id = ?")
                ->execute([$fu['next_date'], $fu['mode'], $fu['client_id']]);
            break;
        }
    }

    $c = $pdo->prepare('SELECT activity_log FROM clients WHERE id = ?');
    $c->execute([$fu['client_id']]);
    $log = json_decode((string)$c->fetchColumn(), true);
    if (!is_array($log)) $log = [];
    $log[] = [
        'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'] ?? '') ? $d['date'] : date('Y-m-d'),
        'time' => preg_match('/^\d{2}:\d{2}$/', $d['time'] ?? '') ? $d['time'] : date('H:i'),
        'type' => 'Follow-up', 'by' => $CURRENT_USER_NAME,
        'note' => 'Follow-up completed — ' . $fu['followup_type']
            . ($spawnNext ? ' · Re-follow-up still pending on ' . $fu['next_date'] : ''),
    ];
    $pdo->prepare('UPDATE clients SET activity_log = ? WHERE id = ?')
        ->execute([json_encode($log, JSON_UNESCAPED_UNICODE), $fu['client_id']]);
}

echo json_encode(['success' => true]);
