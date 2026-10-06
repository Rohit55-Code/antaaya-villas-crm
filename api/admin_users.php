<?php
// api/admin_users.php — admin-only. GET lists users; POST creates or updates one.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/mail.php';

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT id, name, email, role, desk, (smtp_app_password IS NOT NULL AND smtp_app_password <> '') AS has_smtp FROM users ORDER BY name");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? null;
    $name = trim($d['name'] ?? '');
    $email = trim($d['email'] ?? '');
    $role = $d['role'] ?? 'sales';
    $desk = ($d['desk'] ?? '') ?: null;
    $password = $d['password'] ?? '';
    $appPwd = preg_replace('/\s+/', '', $d['smtp_app_password'] ?? ''); // Google shows it with spaces

    if (!$name || !$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Name and email are required']);
        exit;
    }
    if (!in_array($role, ['admin', 'sales', 'legal', 'accounts', 'it'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Role must be Admin, Sales, Legal, Accounts or IT']);
        exit;
    }
    // IT sits above admin: only an IT user can give the IT role or change an IT user.
    if (!is_it_user()) {
        $cur = null;
        if ($id) { $q = $pdo->prepare('SELECT role FROM users WHERE id = ?'); $q->execute([$id]); $cur = $q->fetchColumn(); }
        if ($role === 'it' || $cur === 'it') {
            http_response_code(403);
            echo json_encode(['error' => 'Only an IT user can add or change IT users.']);
            exit;
        }
    }
    // Desk follows the role: Legal / Accounts always sit on their own Post Sales desk (the Post
    // Sales screens check the desk), Admin and IT have none, Sales picks Entry / Broker / Owner.
    if (in_array($role, ['legal', 'accounts'], true)) $desk = $role;
    elseif ($role !== 'sales') $desk = null;
    elseif ($desk !== null && !in_array($desk, ['entry', 'broker', 'owner'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Sales users can only be on the Entry, Broker or Owner desk']);
        exit;
    }

    try {
        if ($id) {
            if ($password) {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, desk = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$name, $email, $role, $desk, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, desk = ? WHERE id = ?');
                $stmt->execute([$name, $email, $role, $desk, $id]);
            }
            if ($appPwd !== '') $pdo->prepare('UPDATE users SET smtp_app_password = ? WHERE id = ?')->execute([mail_encrypt($appPwd), $id]);
            echo json_encode(['success' => true, 'id' => (int)$id]);
        } else {
            if (!$password) {
                http_response_code(400);
                echo json_encode(['error' => 'Password is required for a new user']);
                exit;
            }
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, desk) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $desk]);
            $newId = (int)$pdo->lastInsertId();
            if ($appPwd !== '') $pdo->prepare('UPDATE users SET smtp_app_password = ? WHERE id = ?')->execute([mail_encrypt($appPwd), $newId]);
            echo json_encode(['success' => true, 'id' => $newId]);
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => str_contains($e->getMessage(), 'Duplicate') ? 'That email is already in use.' : 'Could not save user.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    if ((int)$id === (int)$CURRENT_USER_ID) {
        http_response_code(400);
        echo json_encode(['error' => "You can't delete your own account while logged in as it."]);
        exit;
    }

    $q = $pdo->prepare('SELECT role FROM users WHERE id = ?'); $q->execute([$id]);
    if ($q->fetchColumn() === 'it' && !is_it_user()) {
        http_response_code(403);
        echo json_encode(['error' => 'Only an IT user can delete an IT user.']);
        exit;
    }

    $check = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE salesperson_id = ? OR created_by = ?');
    $check->execute([$id, $id]);
    if ($check->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'This user has leads assigned to them or added by them. Reassign those leads first (Manage Leads > Reassign), then delete the user.']);
        exit;
    }

    // Other history (follow-ups, Notification Log, lead requests, Source Leads reviews) still points at
    // this user — the database refuses the delete then, so say why instead of failing with HTTP 500.
    $pdo->beginTransaction();
    try {
        try { $pdo->prepare('DELETE FROM notification_clears WHERE user_id = ?')->execute([$id]); } catch (PDOException $e) { /* pre-v11 DB */ }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'This user can\'t be deleted because they still have history in the CRM (follow-ups, Notification Log entries, lead requests or Source Leads they reviewed). To stop them logging in, edit the user and set a new password instead.']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
