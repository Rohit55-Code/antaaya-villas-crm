<?php
// api/villas.php — Villa Inventory (admin-facing)
// GET  -> list all villas with current status + linked client (if any)
// POST -> manually set a villa's status (admin override; normal flow is
//         automatic via resolve_and_sync_villa() in clients.php)
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        'SELECT v.*, c.name AS client_name, c.lead_code
         FROM villas v
         LEFT JOIN clients c ON c.id = v.linked_client_id
         ORDER BY v.name'
    );
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin + sales team can update; Entry Desk and the Legal / Accounts desks stay view-only —
    // their stage moves keep the inventory in sync automatically (resolve_and_sync_villa).
    if (!is_admin() && ($CURRENT_USER_DESK === 'entry' || is_post_sales())) {
        http_response_code(403);
        echo json_encode(['error' => 'Not allowed to update villa inventory']);
        exit;
    }
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? null;
    $status = $d['status'] ?? null;
    $allowed = ['available', 'blocked', 'negotiation', 'booked', 'sold'];
    if (!$id || !in_array($status, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'id and a valid status are required']);
        exit;
    }
    $pdo->prepare('UPDATE villas SET status = ?, linked_client_id = ? WHERE id = ?')
        ->execute([$status, $d['linked_client_id'] ?? null, $id]);
    echo json_encode(['success' => true]);
    exit;
}
