<?php
// api/call_log.php — "Call" button (tel: link opens the phone's dialer).
// POST JSON { client_id, context? } -> { success, log_entry, activity_log }
// Writes a "Call" entry straight into the lead's Lead History (same pattern as document uploads),
// so it's kept even if Edit Client is closed without saving.
// Allowed for anyone who can see the lead — same rule as the lead list in api/clients.php:
// admin all · Accounts desk leads transferred to Accounts · Legal desk leads with Post Sales ·
// everyone else their own leads (assigned to them or created by them).
date_default_timezone_set('Asia/Kolkata'); // same clock as the payment entries in Lead History
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sv_followup_lib.php';

function fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Method not allowed');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$clientId = (int)($in['client_id'] ?? 0);
if ($clientId <= 0) fail(400, 'Lead missing');

// Where the call was placed from, e.g. "Contact Attempted", "Site Visit Follow-up", "Payment due".
$context = trim(preg_replace('/\s+/', ' ', strip_tags((string)($in['context'] ?? ''))));
$context = mb_substr($context, 0, 60);

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ? FOR UPDATE');
    $st->execute([$clientId]);
    $c = $st->fetch();
    if (!$c) { $pdo->rollBack(); fail(404, 'Lead not found'); }

    $canSee = is_admin()
        || (is_accounts_desk() ? lead_with_accounts($c)
        : (is_post_sales() ? lead_with_post_sales($c)
        : ((int)$c['salesperson_id'] === (int)$CURRENT_USER_ID || (int)$c['created_by'] === (int)$CURRENT_USER_ID)));
    if (!$canSee) { $pdo->rollBack(); fail(403, 'You cannot access this lead'); }

    $number = trim((string)($c['mobile'] ?? '')) ?: trim((string)($c['alt_mobile'] ?? ''));
    if ($number === '') { $pdo->rollBack(); fail(400, 'No mobile number on this lead'); }

    $entry = [
        'date' => date('Y-m-d'), 'time' => date('H:i'), 'type' => 'Call',
        'note' => 'Called ' . $number . ($context !== '' ? ' · ' . $context : ''),
        'by'   => $CURRENT_USER_NAME,
    ];
    $log = json_decode((string)$c['activity_log'], true);
    if (!is_array($log)) $log = [];
    $log[] = $entry;
    $json = json_encode($log, JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE clients SET activity_log = ? WHERE id = ?')->execute([$json, $clientId]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail(500, 'Could not log the call.');
}

echo json_encode(['success' => true, 'log_entry' => $entry, 'activity_log' => $json]);
