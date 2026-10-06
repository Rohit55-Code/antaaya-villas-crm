<?php
// api/reassign.php — POST { id, salesperson_id }
// Changes ONLY who a lead is assigned to. Every other field (stage, notes,
// booking/payment/possession progress — everything the current owner has
// already filled in) stays exactly as-is. This is intentional: reassigning
// a lead should hand over the fully worked lead, not reset it.
//
// Who can reassign:
//   - admin: any lead
//   - a desk='entry' user: only leads THEY originally created
//   - a desk='broker'/'owner' user: only a lead CURRENTLY
//     assigned to themselves, and only over to the other sales desk — lets
//     them self-service move a lead across to their counterpart without
//     needing admin/entry to do it for them.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/reassign_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
$id = $d['id'] ?? null;
$newSalespersonId = $d['salesperson_id'] ?? null;
if (!$id || !$newSalespersonId) {
    http_response_code(400);
    echo json_encode(['error' => 'id and salesperson_id are required']);
    exit;
}

$stmt = $pdo->prepare('SELECT c.*, u.name AS old_salesperson_name FROM clients c JOIN users u ON u.id = c.salesperson_id WHERE c.id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) { http_response_code(404); echo json_encode(['error' => 'Lead not found']); exit; }

$canReassign = is_admin()
    || ($CURRENT_USER_ROLE === 'sales' && $CURRENT_USER_DESK === 'entry' && (int)$client['created_by'] === (int)$CURRENT_USER_ID)
    || ($CURRENT_USER_ROLE === 'sales' && in_array($CURRENT_USER_DESK, ['broker', 'owner'], true) && (int)$client['salesperson_id'] === (int)$CURRENT_USER_ID);
if (!$canReassign) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only reassign leads you originally added']);
    exit;
}

$newUser = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$newUser->execute([$newSalespersonId]);
$newName = $newUser->fetchColumn();
if (!$newName) { http_response_code(400); echo json_encode(['error' => 'Invalid salesperson']); exit; }

$note = "[".date('d M Y H:i')."] Reassigned from {$client['old_salesperson_name']} to {$newName} by {$CURRENT_USER_NAME}.";
$updatedNotes = $client['notes'] ? ($client['notes'] . "\n" . $note) : $note;

// Stage is deliberately NOT in this UPDATE — the lead continues from its current stage.
$pdo->prepare('UPDATE clients SET salesperson_id = ?, notes = ? WHERE id = ?')
    ->execute([$newSalespersonId, $updatedNotes, $id]);
carry_over_reassigned_lead($pdo, $id, $newSalespersonId, $client['old_salesperson_name'], $newName, $CURRENT_USER_NAME);

// Log it so the new owner gets a "X assigned lead Y to you" notification.
// Wrapped so a reassign still succeeds even if migration_v10.sql hasn't
// been run yet on this database.
try {
    $pdo->prepare('INSERT INTO assignment_log (client_id, from_user_id, to_user_id, type) VALUES (?, ?, ?, \'reassigned\')')
        ->execute([$id, $CURRENT_USER_ID, $newSalespersonId]);
} catch (PDOException $e) { /* table not created yet — ignore */ }

echo json_encode(['success' => true]);
