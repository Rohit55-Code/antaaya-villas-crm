<?php
// api/users.php — GET list of users (id, name, role, desk, photo) for dropdowns
// and the Team Overview cards.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';

$stmt = $pdo->query('SELECT id, name, role, desk, photo FROM users ORDER BY name');
echo json_encode($stmt->fetchAll());
