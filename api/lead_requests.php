<?php
// api/lead_requests.php — cross-desk "Request Lead" flow.
//
// GET  -> pending requests waiting on ME to confirm/reject, PLUS my own
//         outstanding requests (so I can see "waiting on the Owner desk" state).
// POST action=create  { client_id } -> I (broker/owner desk) am requesting
//         a lead currently owned by the OTHER desk. Never creates a
//         duplicate lead — this only ever moves an existing one.
// POST action=respond { request_id, decision: confirm|reject } -> the
//         current owner (or admin) accepts/declines. On confirm, the lead
//         is auto-reassigned to the requester — same effect as manual
//         Reassign, just triggered by the other side agreeing.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/reassign_lib.php';

function desk_of($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT desk FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Pending requests always show (need action / are being waited on).
    // Resolved (Confirmed/Rejected) ones also show for a few days afterward
    // so the requester actually finds out what happened to their request —
    // the notification bell (frontend) lets either side dismiss/clear them
    // early instead of waiting for this window to lapse on its own.
    $stmt = $pdo->prepare(
        "SELECT r.*, c.lead_code, c.name AS client_name,
                fu.name AS from_name, tu.name AS to_name,
                TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS created_ago,
                TIMESTAMPDIFF(SECOND, r.resolved_at, NOW()) AS resolved_ago
         FROM lead_transfer_requests r
         JOIN clients c ON c.id = r.client_id
         JOIN users fu ON fu.id = r.from_user_id
         JOIN users tu ON tu.id = r.to_user_id
         WHERE (r.to_user_id = ? OR r.from_user_id = ?)
           AND (
                r.status = 'Pending'
                OR (r.status IN ('Confirmed','Rejected') AND r.resolved_at >= (NOW() - INTERVAL 3 DAY))
           )
         ORDER BY COALESCE(r.resolved_at, r.created_at) DESC"
    );
    $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    $action = $d['action'] ?? '';

    // -------------------- create --------------------
    if ($action === 'create') {
        $clientId = $d['client_id'] ?? null;
        if (!$clientId) { http_response_code(400); echo json_encode(['error' => 'client_id is required']); exit; }

        $myDesk = $CURRENT_USER_DESK;
        if (!in_array($myDesk, ['broker', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Only the Broker or Owner desk can request a lead']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, salesperson_id, lead_code, name FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) { http_response_code(404); echo json_encode(['error' => 'Lead not found']); exit; }

        $ownerId = (int)$client['salesperson_id'];
        if ($ownerId === (int)$CURRENT_USER_ID) {
            http_response_code(400);
            echo json_encode(['error' => 'This lead is already on your desk']);
            exit;
        }

        // Never let a duplicate pending request pile up for the same lead/requester.
        $existingReq = $pdo->prepare(
            "SELECT id FROM lead_transfer_requests WHERE client_id = ? AND from_user_id = ? AND status = 'Pending'"
        );
        $existingReq->execute([$clientId, $CURRENT_USER_ID]);
        if ($existingReq->fetchColumn()) {
            echo json_encode(['success' => true, 'already_pending' => true]);
            exit;
        }

        $pdo->prepare(
            'INSERT INTO lead_transfer_requests (client_id, from_user_id, to_user_id) VALUES (?, ?, ?)'
        )->execute([$clientId, $CURRENT_USER_ID, $ownerId]);

        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------- respond --------------------
    if ($action === 'respond') {
        $requestId = $d['request_id'] ?? null;
        $decision = $d['decision'] ?? '';
        if (!$requestId || !in_array($decision, ['confirm', 'reject'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'request_id and a valid decision are required']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT r.*, c.name AS client_name, c.notes, fu.name AS from_name, tu.name AS to_name
             FROM lead_transfer_requests r
             JOIN clients c ON c.id = r.client_id
             JOIN users fu ON fu.id = r.from_user_id
             JOIN users tu ON tu.id = r.to_user_id
             WHERE r.id = ?"
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) { http_response_code(404); echo json_encode(['error' => 'Request not found']); exit; }
        if ($req['status'] !== 'Pending') {
            echo json_encode(['error' => 'This request has already been ' . strtolower($req['status'])]);
            exit;
        }

        $canRespond = is_admin() || (int)$req['to_user_id'] === (int)$CURRENT_USER_ID;
        if (!$canRespond) {
            http_response_code(403);
            echo json_encode(['error' => 'Only the current lead owner can respond to this request']);
            exit;
        }

        $newStatus = $decision === 'confirm' ? 'Confirmed' : 'Rejected';
        $pdo->prepare('UPDATE lead_transfer_requests SET status = ?, resolved_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $requestId]);

        if ($decision === 'confirm') {
            $note = "[" . date('d M Y H:i') . "] Lead request confirmed by {$req['to_name']} — auto-reassigned from {$req['to_name']} to {$req['from_name']}.";
            $updatedNotes = $req['notes'] ? ($req['notes'] . "\n" . $note) : $note;
            // Stage is deliberately NOT touched — the lead continues from its current stage.
            $pdo->prepare('UPDATE clients SET salesperson_id = ?, notes = ? WHERE id = ?')
                ->execute([$req['from_user_id'], $updatedNotes, $req['client_id']]);
            carry_over_reassigned_lead($pdo, $req['client_id'], $req['from_user_id'], $req['to_name'], $req['from_name'], $req['to_name']);
        }

        echo json_encode(['success' => true, 'status' => $newStatus]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
