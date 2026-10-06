<?php
// api/villa-status.php — PUBLIC read-only feed for antaayavillas.com/configure/
// Deliberately outside auth.php: the public website calls this with no
// session. It only ever returns status, never writes anything.
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://antaayavillas.com'); // same-origin call — this header is a no-op here but kept correct in case the configurator ever moves to a different subdomain
header('Cache-Control: no-store');

// booked/sold/negotiation/blocked all read as "soldout" on the public site —
// the website only understands available vs soldout today.
$stmt = $pdo->query('SELECT name, serial, status FROM villas');
$out = [];
foreach ($stmt->fetchAll() as $row) {
    $out[] = [
        'name'   => $row['name'],
        'serial' => $row['serial'],
        'status' => $row['status'] === 'available' ? 'available' : 'soldout',
    ];
}
echo json_encode($out);
