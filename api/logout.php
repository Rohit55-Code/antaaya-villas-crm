<?php
// api/logout.php
session_start();
$_SESSION = [];
// Clear the session cookie itself, not just the server-side data — leaving
// the old cookie in the browser after logout means the very next
// session_start() on this same browser could revive it if anything ever
// writes to that same session ID again.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo json_encode(['success' => true]);
