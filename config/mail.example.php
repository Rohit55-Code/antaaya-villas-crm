<?php
// Copy this file to config/mail.php and set your own values. config/mail.php is gitignored.
// config/mail.php — outgoing-mail settings (site-visit calendar invites).
//
// Each salesperson's own Gmail is used as the sender when an admin has saved
// that user's Gmail App Password (Users > Edit). Users with no App Password
// saved fall back to MAIL_FALLBACK_FROM (Reply-To/organizer stay theirs).

const MAIL_FALLBACK_FROM = 'crm@example.com';
const MAIL_SECRET = 'replace-with-a-long-random-string'; // e.g. php -r "echo bin2hex(random_bytes(24));" // encrypts saved App Passwords. Change ONCE before saving any; changing later invalidates saved ones.

function mail_encrypt(string $plain): string {
    $key = hash('sha256', MAIL_SECRET, true);
    $iv  = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
}
function mail_decrypt(?string $stored): string {
    if (!$stored) return '';
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $key = hash('sha256', MAIL_SECRET, true);
    $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $out === false ? '' : $out;
}
