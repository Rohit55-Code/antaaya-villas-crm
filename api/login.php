<?php
// api/login.php — POST { email, password } -> starts session
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password required']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, name, password_hash, role, desk, photo FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password']);
    exit;
}

// Issue a brand-new session ID on every successful login instead of reusing
// whatever session ID the browser happened to arrive with — closes off
// session fixation and makes sure this login can never inherit another
// person's already-established session data.
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['name']    = $user['name'];
$_SESSION['role']    = $user['role'];
$_SESSION['desk']    = $user['desk'];
$_SESSION['photo']   = $user['photo'] ? 'image/avatars/' . $user['photo'] : null;

echo json_encode(['success' => true, 'name' => $user['name'], 'role' => $user['role'], 'desk' => $user['desk'], 'photo' => $_SESSION['photo']]);
