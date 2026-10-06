<?php
// api/assignment_log.php
// GET (default): notifications for the current user in the last 3 days — rows addressed to
// them (assigned/reassigned, booking_cancelled to the salesperson…), plus team-wide rows:
// post_sales_transfer for the Legal desk, accounts_transfer for the Accounts desk and
// booking_cancelled for admins. Admins / IT also get both desk transfers, so they can follow
// every desk's work from the bell —
// EXCLUDING any this user has already cleared (notification_clears). Powers the bell.
// GET ?action=log (admin only): full history, every user, never filtered by
// anyone's clear state — for the admin notification-log audit page. Accepts
// optional ?date=YYYY-MM-DD and ?salesperson_id=ID filters.
// POST {action:'clear', id}: persist a clear for the current user so that
// notification never reappears for them again, on any device.
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'log') {
        if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
        $where = [];
        $params = [];
        if (!empty($_GET['date'])) { $where[] = 'DATE(a.created_at) = ?'; $params[] = $_GET['date']; }
        if (!empty($_GET['salesperson_id'])) { $where[] = 'a.to_user_id = ?'; $params[] = $_GET['salesperson_id']; }
        if (!empty($_GET['type'])) { $where[] = 'a.type = ?'; $params[] = $_GET['type']; }
        // client_id/to_user_id can be NULL for a source-lead event that has no
        // real client or salesperson yet (migration_v30.sql) — LEFT JOINs so
        // those rows still come back, and source_leads is joined too so the
        // site/form_type is available for a source-lead row's own display.
        $sql = "SELECT a.*, c.lead_code, c.name AS client_name, fu.name AS from_name, tu.name AS to_name,
                       sl.site AS source_site, sl.form_type AS source_form_type,
                       TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS seconds_ago
                FROM assignment_log a
                LEFT JOIN clients c ON c.id = a.client_id
                LEFT JOIN users fu ON fu.id = a.from_user_id
                LEFT JOIN users tu ON tu.id = a.to_user_id
                LEFT JOIN source_leads sl ON sl.id = a.source_lead_id"
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                . ' ORDER BY a.created_at DESC';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            echo json_encode([]); // table/columns not migrated yet
        }
        exit;
    }

    // Team-wide rows: the Legal desk hears about every transfer from sales, the Accounts desk
    // about every transfer from Legal, admins about every booking cancellation — never their own action.
    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, c.lead_code, c.name AS client_name, fu.name AS from_name,
                    TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS seconds_ago
             FROM assignment_log a
             JOIN clients c ON c.id = a.client_id
             LEFT JOIN users fu ON fu.id = a.from_user_id
             LEFT JOIN notification_clears nc ON nc.assignment_log_id = a.id AND nc.user_id = ?
             WHERE nc.id IS NULL AND a.created_at >= (NOW() - INTERVAL 3 DAY)
               AND (a.to_user_id = ?
                    OR (? = 1 AND a.type = 'post_sales_transfer' AND COALESCE(a.from_user_id, 0) <> ?)
                    OR (? = 1 AND a.type = 'accounts_transfer' AND COALESCE(a.from_user_id, 0) <> ?)
                    OR (? = 1 AND a.type = 'booking_cancelled' AND COALESCE(a.from_user_id, 0) <> ?))
             ORDER BY a.created_at DESC"
        );
        $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, (is_legal_desk() || is_admin()) ? 1 : 0, $CURRENT_USER_ID,
            (is_accounts_desk() || is_admin()) ? 1 : 0, $CURRENT_USER_ID, is_admin() ? 1 : 0, $CURRENT_USER_ID]);
        echo json_encode($stmt->fetchAll());
    } catch (PDOException $e) {
        // notification_clears/type not created yet (migration_v11.sql not run) —
        // fail soft to the old un-filtered query so the bell still works.
        try {
            $stmt = $pdo->prepare(
                "SELECT a.*, c.lead_code, c.name AS client_name, fu.name AS from_name,
                        TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS seconds_ago
                 FROM assignment_log a
                 JOIN clients c ON c.id = a.client_id
                 LEFT JOIN users fu ON fu.id = a.from_user_id
                 WHERE a.to_user_id = ? AND a.created_at >= (NOW() - INTERVAL 3 DAY)
                 ORDER BY a.created_at DESC"
            );
            $stmt->execute([$CURRENT_USER_ID]);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e2) {
            echo json_encode([]);
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (($d['action'] ?? '') === 'clear') {
        $id = $d['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        try {
            $pdo->prepare('INSERT IGNORE INTO notification_clears (user_id, assignment_log_id) VALUES (?, ?)')
                ->execute([$CURRENT_USER_ID, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            // migration_v11.sql not run yet — nothing to persist, but don't error the UI.
            echo json_encode(['success' => false]);
        }
        exit;
    }
    if (($d['action'] ?? '') === 'delete') {
        if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
        $id = $d['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        try {
            $pdo->prepare('DELETE FROM notification_clears WHERE assignment_log_id = ?')->execute([$id]);
        } catch (PDOException $e) { /* table not migrated yet — ignore */ }
        $pdo->prepare('DELETE FROM assignment_log WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
