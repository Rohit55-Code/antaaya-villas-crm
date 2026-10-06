<?php
// api/check_mobile.php — GET ?mobile=... — read-only duplicate lookup. Never creates or changes data.
// Login required: it returns a lead's name and Lead ID, so it must not answer anonymous callers.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';

$mobile = preg_replace('/\D/', '', $_GET['mobile'] ?? '');
if (!$mobile) { echo json_encode(['found' => false]); exit; }

$stmt = $pdo->prepare(
    "SELECT id, lead_code, name, salesperson_id FROM clients
     WHERE REPLACE(REPLACE(mobile,' ',''),'-','') = ? LIMIT 1"
);
$stmt->execute([$mobile]);
$match = $stmt->fetch();

echo json_encode($match
    ? ['found' => true, 'id' => (int)$match['id'], 'lead_code' => $match['lead_code'], 'name' => $match['name']]
    : ['found' => false]
);
