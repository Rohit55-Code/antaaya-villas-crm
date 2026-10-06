<?php
// api/auth.php — session guard, included at the top of every protected API file.
session_start();
header('Content-Type: application/json');
// Every response here carries data scoped to whoever's session made the
// request (their own leads, their own name, etc). If any caching layer
// between the server and the browser were to cache one of these JSON
// responses and later replay it to a different logged-in session, that
// person would see someone else's data. These headers forbid that outright.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$CURRENT_USER_ID   = $_SESSION['user_id'];
$CURRENT_USER_ROLE = $_SESSION['role'];
$CURRENT_USER_NAME = $_SESSION['name'];
$CURRENT_USER_DESK = $_SESSION['desk'] ?? null;

// IT has full admin access (and more, to be added later).
function is_admin() {
    global $CURRENT_USER_ROLE;
    return in_array($CURRENT_USER_ROLE, ['admin', 'it'], true);
}

// Post Sales team desks (Legal / Accounts). They work every lead that sales has
// transferred to Post Sales — Booking & Legal onward — but never the Sales stages.
const POST_SALES_DESKS = ['legal', 'accounts'];
function is_post_sales() {
    global $CURRENT_USER_DESK;
    return !is_admin() && in_array($CURRENT_USER_DESK, POST_SALES_DESKS, true);
}
// IT role — everything admin can do (is_admin() is true for IT too); IT-only powers go through this.
function is_it_user() {
    global $CURRENT_USER_ROLE;
    return $CURRENT_USER_ROLE === 'it';
}
// Legal desk: Booking & Legal (Booking Initiated → Registered), then "Transfer to Accounts".
function is_legal_desk() {
    global $CURRENT_USER_DESK;
    return !is_admin() && $CURRENT_USER_DESK === 'legal';
}
// Accounts desk: Construction & Payment + Possession — only leads transferred by the Legal desk.
function is_accounts_desk() {
    global $CURRENT_USER_DESK;
    return !is_admin() && $CURRENT_USER_DESK === 'accounts';
}
