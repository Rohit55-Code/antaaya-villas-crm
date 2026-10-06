<?php
// letter.php — PUBLIC link to a demand / payment-reminder letter PDF, sent to the client on WhatsApp
// (WhatsApp chat links can't carry a file). No login: the 32-character random code in the link is the key.
// The PDFs are made by api/payments.php (action whatsapp_sent) and kept in
// uploads/demand_letters/<lead id>/<code>__<file name>.pdf — uploads/ itself is closed to the web.
$t = $_GET['t'] ?? '';
$hits = (is_string($t) && preg_match('/^[a-f0-9]{32}$/', $t)) ? glob(__DIR__ . '/uploads/demand_letters/*/' . $t . '__*.pdf') : [];
if (!$hits || !is_file($hits[0])) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Letter not found</title>'
        . '<p style="font:15px Arial,sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#333">This letter link is not available. Please contact the Accounts team.</p>';
    exit;
}
$fp = $hits[0];
$name = substr(basename($fp), 34); // after "<code>__"
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($fp));
header('Cache-Control: private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
readfile($fp);
