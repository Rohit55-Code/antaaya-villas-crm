<?php
// api/profile_photo.php — logged-in user uploads, replaces, or deletes their own profile photo.
// POST multipart/form-data with field "photo"  -> upload/replace
// POST { action: "delete" } (JSON)              -> remove current photo
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';

$AVATAR_DIR = __DIR__ . '/../image/avatars';
$AVATAR_URL = 'image/avatars';

function current_photo($pdo, $id) {
    $stmt = $pdo->prepare('SELECT photo FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}

function delete_photo_file($AVATAR_DIR, $photo) {
    if ($photo) {
        $path = $AVATAR_DIR . '/' . basename($photo);
        if (is_file($path)) unlink($path);
    }
}

// ---- DELETE current photo ----
$rawInput = null;
if (empty($_FILES)) {
    $rawInput = json_decode(file_get_contents('php://input'), true);
}

if ($rawInput && ($rawInput['action'] ?? '') === 'delete') {
    $old = current_photo($pdo, $CURRENT_USER_ID);
    delete_photo_file($AVATAR_DIR, $old);

    $stmt = $pdo->prepare('UPDATE users SET photo = NULL WHERE id = ?');
    $stmt->execute([$CURRENT_USER_ID]);

    $_SESSION['photo'] = null;
    echo json_encode(['success' => true, 'photo' => null]);
    exit;
}

// ---- UPLOAD / REPLACE photo ----
if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No photo uploaded']);
    exit;
}

$file = $_FILES['photo'];

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$mime = mime_content_type($file['tmp_name']);
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPG, PNG or WEBP images are allowed']);
    exit;
}

if ($file['size'] > 3 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Image must be under 3MB']);
    exit;
}

if (!is_dir($AVATAR_DIR)) mkdir($AVATAR_DIR, 0755, true);

$ext = $allowed[$mime];
$filename = 'user_' . $CURRENT_USER_ID . '_' . time() . '.' . $ext;
$dest = $AVATAR_DIR . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save photo']);
    exit;
}

// remove the old file (if any) now that the new one is safely saved
$old = current_photo($pdo, $CURRENT_USER_ID);
delete_photo_file($AVATAR_DIR, $old);

$stmt = $pdo->prepare('UPDATE users SET photo = ? WHERE id = ?');
$stmt->execute([$filename, $CURRENT_USER_ID]);

// Verify the write actually persisted in the database instead of trusting
// it blindly — a silently failed UPDATE (e.g. the "photo" column missing
// because migration_v8.sql was never run) would otherwise only be caught
// after logout, since the session was already showing the "saved" photo.
$check = current_photo($pdo, $CURRENT_USER_ID);
if ($check !== $filename) {
    unlink($dest); // don't leave an orphaned file if the DB write didn't stick
    http_response_code(500);
    echo json_encode(['error' => 'Photo was not saved to the database. Make sure migration_v8.sql (adds the "photo" column to the users table) has been run.']);
    exit;
}

$_SESSION['photo'] = $AVATAR_URL . '/' . $filename;
echo json_encode(['success' => true, 'photo' => $AVATAR_URL . '/' . $filename]);
