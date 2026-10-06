<?php
// api/visit_invite.php
// POST {id, kind: site|revisit|negotiation, visit_date, visit_time, visit_pickup?, email?, only_if_changed?, client_requested?}
// Emails the client a Site Visit / Re-Visit / Negotiation Visit calendar invite (.ics, METHOD:REQUEST) so
// Gmail/Outlook/Apple show Accept / Decline and add it to their calendar
// with reminders. Re-sending after a reschedule updates the same event
// (same UID, higher SEQUENCE). Sent from the lead's assigned salesperson's own Gmail (SMTP +
// their saved App Password); the sender is the lead's assigned salesperson, not the
// logged-in user; salespeople without one fall back to MAIL_FALLBACK_FROM via
// PHP mail() with Reply-To/organizer set to them.
//
// client_requested: true when the salesperson updated the date/time above
// because the client proposed a different one (Google Calendar's own
// "Propose a new time" reply lands only in the salesperson's Gmail, never in
// the CRM, so this is how it gets confirmed here). Same send, just: worded as
// a reschedule instead of a fresh booking, and every admin ("sales team") also
// gets an email + a permanent CRM notification (bell + Notification Log) about it.
date_default_timezone_set('Asia/Kolkata'); // sent-at shown in the CRM is IST
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/mail.php';
require __DIR__ . '/../config/site.php';
require __DIR__ . '/notif_log_lib.php';
require __DIR__ . '/../lib/phpmailer/Exception.php';
require __DIR__ . '/../lib/phpmailer/PHPMailer.php';
require __DIR__ . '/../lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

// ---- Edit if needed (the location lives in config/site.php) ----
const VISIT_MINUTES   = 90;                         // event length

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$id   = (int)($d['id'] ?? 0);

// Which visit this invite is for. Column names come from this fixed map only.
$KINDS = [
    'site'        => ['title' => 'Site Visit',        'noun' => 'site visit',        'date' => 'visit_date',             'time' => 'visit_time',             'pickup' => 'visit_pickup', 'inv' => 'visit_invite'],
    'revisit'     => ['title' => 'Re-Visit',          'noun' => 're-visit',          'date' => 'revisit_date',           'time' => 'revisit_time',           'pickup' => null,           'inv' => 'revisit_invite'],
    'negotiation' => ['title' => 'Negotiation Visit', 'noun' => 'negotiation visit', 'date' => 'negotiation_visit_date', 'time' => 'negotiation_visit_time', 'pickup' => null,           'inv' => 'negotiation_invite'],
];
$kind = $d['kind'] ?? 'site';
if (!isset($KINDS[$kind])) { http_response_code(400); echo json_encode(['error' => 'Unknown visit type']); exit; }
$K = $KINDS[$kind];
$noun = $K['noun']; $Noun = ucfirst($noun); $title = $K['title'];
$date = trim($d['visit_date'] ?? '');
$time = trim($d['visit_time'] ?? '');
$clientRequested = !empty($d['client_requested']);

if (!$id) { http_response_code(400); echo json_encode(['error' => 'Save the lead first, then send the invite.']); exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}/', $time)) {
    http_response_code(400); echo json_encode(['error' => 'Set both Visit Date and Visit Time first.']); exit;
}
$time = substr($time, 0, 5);

$stmt = $pdo->prepare(
    'SELECT c.*, u.name AS sp_name, u.email AS sp_email
     FROM clients c JOIN users u ON u.id = c.salesperson_id WHERE c.id = ?'
);
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) { http_response_code(404); echo json_encode(['error' => 'Client not found']); exit; }
if (!is_admin() && (int)$c['salesperson_id'] !== (int)$CURRENT_USER_ID) {
    http_response_code(403); echo json_encode(['error' => 'Not your lead']); exit;
}

$email = trim($d['email'] ?? '') ?: trim($c['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(['error' => 'This client has no valid email address.']); exit;
}

$sentFor = "$date $time|" . strtolower($email);
if (!empty($d['only_if_changed']) && $c[$K['inv'] . '_for'] === $sentFor) {
    echo json_encode(['success' => true, 'skipped' => true]); exit;
}

// Keep the previously-scheduled slot around (for the reschedule wording/admin
// email below) before it gets overwritten by the new one just below.
$origDate = $c[$K['date']] ?? '';
$origTime = $c[$K['time']] ?? '';

// Persist the visit slot that's being invited, so DB and invite always match.
$pickup = false;
if ($K['pickup']) {
    $pickupVal = $d['visit_pickup'] ?? $c[$K['pickup']];
    $pdo->prepare("UPDATE clients SET {$K['date']} = ?, {$K['time']} = ?, {$K['pickup']} = ? WHERE id = ?")
        ->execute([$date, $time, $pickupVal, $id]);
    $pickup = $pickupVal === 'Yes';
} else {
    $pdo->prepare("UPDATE clients SET {$K['date']} = ?, {$K['time']} = ? WHERE id = ?")->execute([$date, $time, $id]);
}

// ---- Build the event ----
$clean = fn($s) => trim(preg_replace('/[\r\n]+/', ' ', (string)$s));
$clientName = $clean($c['name']);

// Sender = the salesperson this lead is ASSIGNED to, whoever clicks Send/Save
// (so an admin sending on a lead goes out from that lead's salesperson).
$me = $pdo->prepare('SELECT name, email, smtp_app_password FROM users WHERE id = ?');
$me->execute([$c['salesperson_id']]);
$me = $me->fetch();
$spName  = $clean($me['name'] ?? $c['sp_name']);
$spEmail = filter_var($me['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $me['email'] : $c['sp_email'];
$appPwd  = mail_decrypt($me['smtp_app_password'] ?? '');

$uid = $c[$K['inv'] . '_uid'] ?: ('antaaya-' . $kind . '-' . $id . '-' . bin2hex(random_bytes(4)) . '@antaayavillas.com');
$seq = (int)$c[$K['inv'] . '_seq'] + ($c[$K['inv'] . '_sent_at'] ? 1 : 0);

$tz    = new DateTimeZone('Asia/Kolkata');
$start = new DateTime("$date $time:00", $tz);
$end   = (clone $start)->modify('+' . VISIT_MINUTES . ' minutes');
$utc   = new DateTimeZone('UTC');
$fmt   = fn(DateTime $t) => (clone $t)->setTimezone($utc)->format('Ymd\THis\Z');

$esc = fn($s) => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], (string)$s);
$fold = function ($line) {           // RFC 5545: fold at 75 octets
    $cut = function_exists('mb_strcut') ? fn($s) => mb_strcut($s, 0, 74, 'UTF-8') : fn($s) => substr($s, 0, 74);
    $out = ''; while (strlen($line) > 75) { $head = $cut($line); $out .= $head . "\r\n "; $line = substr($line, strlen($head)); }
    return $out . $line;
};

$desc = "$Noun to " . VISIT_LOCATION . ".\n"
      . ($pickup ? "Pickup: our team will call you to confirm the pickup point.\n" : '')
      . "Your contact: $spName";

$lines = [
    'BEGIN:VCALENDAR',
    'PRODID:-//Antaaya Villas//CRM//EN',
    'VERSION:2.0',
    'CALSCALE:GREGORIAN',
    'METHOD:REQUEST',
    'BEGIN:VEVENT',
    "UID:$uid",
    "SEQUENCE:$seq",
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $fmt($start),
    'DTEND:' . $fmt($end),
    'SUMMARY:' . $esc("$title – Antaaya Villas, Lonavala"),
    'LOCATION:' . $esc(VISIT_LOCATION),
    'DESCRIPTION:' . $esc($desc),
    'ORGANIZER;CN="' . str_replace('"', '', $spName) . '":mailto:' . $spEmail,
    'ATTENDEE;CN="' . str_replace('"', '', $clientName) . '";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $email,
    'STATUS:CONFIRMED',
    'TRANSP:OPAQUE',
    'BEGIN:VALARM', 'ACTION:DISPLAY', "DESCRIPTION:$Noun tomorrow", 'TRIGGER:-P1D', 'END:VALARM',
    'BEGIN:VALARM', 'ACTION:DISPLAY', "DESCRIPTION:$Noun in 2 hours", 'TRIGGER:-PT2H', 'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];
$ics = implode("\r\n", array_map($fold, $lines)) . "\r\n";

// ---- Build the email ----
$niceDate = $start->format('l, d F Y');
$niceTime = $start->format('g:i A');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if ($clientRequested) {
    $subject = "$title Rescheduled – Antaaya Villas, Lonavala ($niceDate, $niceTime)";
    $plain = "Hi $clientName,\r\n\r\nAs per your request, we've updated your $noun to Antaaya Villas, Lonavala:\r\n\r\n"
           . "New Date: $niceDate\r\nNew Time: $niceTime\r\n"
           . ($pickup ? "Pickup: our team will call you to confirm the pickup point.\r\n" : '')
           . "\r\nPlease accept the attached updated calendar invite so it replaces the earlier one on your calendar.\r\n\r\n"
           . "Your contact: $spName\r\n";
    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222;max-width:520px">'
          . '<h2 style="color:#1f6b45;margin:0 0 12px">Your ' . $h($title) . ' Has Been Rescheduled 🔄</h2>'
          . '<p>Hi ' . $h($clientName) . ',</p>'
          . '<p>As per your request, we\'ve updated your visit to <b>Antaaya Villas, Lonavala</b>:</p>'
          . '<table style="border-collapse:collapse;margin:12px 0">'
          . '<tr><td style="padding:4px 14px 4px 0;color:#666">New Date</td><td><b>' . $h($niceDate) . '</b></td></tr>'
          . '<tr><td style="padding:4px 14px 4px 0;color:#666">New Time</td><td><b>' . $h($niceTime) . '</b></td></tr>'
          . ($pickup ? '<tr><td style="padding:4px 14px 4px 0;color:#666">Pickup</td><td>Our team will call to confirm the pickup point</td></tr>' : '')
          . '</table>'
          . '<p>Please <b>accept the updated calendar invite</b> in this email — it replaces the earlier one on your calendar.</p>'
          . '<p style="color:#666">Your contact: ' . $h($spName) . '</p></div>';
} else {
    $subject = "$title Confirmed – Antaaya Villas, Lonavala ($niceDate, $niceTime)";
    $plain = "Hi $clientName,\r\n\r\nYour $noun to Antaaya Villas, Lonavala is scheduled:\r\n\r\n"
           . "Date: $niceDate\r\nTime: $niceTime\r\n"
           . ($pickup ? "Pickup: our team will call you to confirm the pickup point.\r\n" : '')
           . "\r\nPlease accept the attached calendar invite so it's saved in your calendar with a reminder.\r\n\r\n"
           . "Your contact: $spName\r\n";
    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222;max-width:520px">'
          . '<h2 style="color:#1f6b45;margin:0 0 12px">Your ' . $h($title) . ' is Scheduled 🏡</h2>'
          . '<p>Hi ' . $h($clientName) . ',</p>'
          . '<p>We look forward to showing you <b>Antaaya Villas, Lonavala</b>.</p>'
          . '<table style="border-collapse:collapse;margin:12px 0">'
          . '<tr><td style="padding:4px 14px 4px 0;color:#666">Date</td><td><b>' . $h($niceDate) . '</b></td></tr>'
          . '<tr><td style="padding:4px 14px 4px 0;color:#666">Time</td><td><b>' . $h($niceTime) . '</b></td></tr>'
          . ($pickup ? '<tr><td style="padding:4px 14px 4px 0;color:#666">Pickup</td><td>Our team will call to confirm the pickup point</td></tr>' : '')
          . '</table>'
          . '<p>Please <b>accept the calendar invite</b> in this email — it saves the visit to your calendar with a reminder.</p>'
          . '<p style="color:#666">Your contact: ' . $h($spName) . '</p></div>';
}

$mail = new PHPMailer(true);
try {
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = PHPMailer::ENCODING_BASE64; // keep non-ASCII (– — names) intact through any mail server
    $mail->Timeout = 15;
    if ($appPwd !== '') {                       // send as the salesperson's own Gmail
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth   = true;
        $mail->Username   = $spEmail;
        $mail->Password   = $appPwd;
        $mail->setFrom($spEmail, $spName . ' – Antaaya Villas');
    } else {                                    // fallback: domain sender, replies go to the salesperson
        $mail->isMail();
        $mail->Sender = MAIL_FALLBACK_FROM;
        $mail->setFrom(MAIL_FALLBACK_FROM, $spName . ' – Antaaya Villas');
        $mail->addReplyTo($spEmail, $spName);
    }
    $mail->addAddress($email, $clientName);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $plain;
    $mail->Ical    = $ics;                      // text/calendar; method=REQUEST part (Accept/Decline buttons)
    $mail->addStringAttachment($ics, 'invite.ics', 'base64', 'application/ics');
    $mail->send();
} catch (Throwable $e) {
    http_response_code(500);
    $why = $appPwd !== '' ? 'Could not send via Gmail — check this user\'s Gmail App Password (Users > Edit), and that the server allows SMTP on port 587.' : 'Mail could not be sent. (Invites only send from the live server, not local XAMPP.)';
    echo json_encode(['error' => $why . ' [' . $mail->ErrorInfo . ']']); exit;
}

// Lead History entry (same JSON log the Edit Client modal uses).
$log = json_decode($c['activity_log'] ?? '', true);
if (!is_array($log)) $log = [];
if ($clientRequested) {
    $niceOrig = ($origDate && $origTime) ? (new DateTime("$origDate $origTime:00", $tz))->format('d M Y, g:i A') : 'not set';
    $entry = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'type' => 'Reschedule Confirmed', 'by' => $CURRENT_USER_NAME,
              'note' => "Rescheduled $noun to $niceDate, $niceTime per client's request (was $niceOrig) — updated invite emailed to $email"];
} else {
    $entry = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'type' => 'Email Sent', 'by' => $CURRENT_USER_NAME,
              'note' => "Calendar invite emailed to $email — $noun $niceDate, $niceTime"];
}
$log[] = $entry;

$pdo->prepare(
    "UPDATE clients SET {$K['inv']}_uid = ?, {$K['inv']}_seq = ?, {$K['inv']}_sent_at = ?, {$K['inv']}_for = ?, activity_log = ? WHERE id = ?"
)->execute([$uid, $seq, date('Y-m-d H:i:s'), $sentFor, json_encode($log, JSON_UNESCAPED_UNICODE), $id]);

// ---- Client-requested reschedule: also tell every admin ("sales team") ----
$mailWarning = null;
if ($clientRequested) {
    $admins = $pdo->query("SELECT id, name, email FROM users WHERE role IN ('admin', 'it')")->fetchAll();
    $adminEmails = array_values(array_filter($admins, fn($a) => filter_var($a['email'], FILTER_VALIDATE_EMAIL)));
    $niceOrig = ($origDate && $origTime) ? (new DateTime("$origDate $origTime:00", $tz))->format('d M Y, g:i A') : 'not set';
    if ($adminEmails) {
        $aHtml = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222;max-width:560px">'
              . '<h2 style="color:#b54708;margin:0 0 12px">Visit Rescheduled Per Client Request 🔄</h2>'
              . '<p><b>' . $h($clientName) . '</b> (' . $h($c['lead_code'] ?: '') . ') asked to move their <b>' . $h($title) . '</b>. An updated invite has already been sent.</p>'
              . '<table style="border-collapse:collapse;margin:12px 0">'
              . '<tr><td style="padding:4px 14px 4px 0;color:#666">Was</td><td>' . $h($niceOrig) . '</td></tr>'
              . '<tr><td style="padding:4px 14px 4px 0;color:#666">Now</td><td><b>' . $h($niceDate) . ', ' . $h($niceTime) . '</b></td></tr>'
              . '<tr><td style="padding:4px 14px 4px 0;color:#666">Salesperson</td><td>' . $h($spName) . '</td></tr>'
              . '</table></div>';
        $aPlain = "$clientName (" . ($c['lead_code'] ?: '') . ") asked to move their $noun.\r\nWas: $niceOrig\r\nNow: $niceDate, $niceTime\r\nSalesperson: $spName\r\n";
        $aMail = new PHPMailer(true);
        try {
            $aMail->CharSet = 'UTF-8';
            $aMail->Encoding = PHPMailer::ENCODING_BASE64;
            $aMail->Timeout = 15;
            $aMail->isMail();
            $aMail->Sender = MAIL_FALLBACK_FROM;
            $aMail->setFrom(MAIL_FALLBACK_FROM, 'Antaaya Villas CRM');
            if ($spEmail) $aMail->addReplyTo($spEmail, $spName);
            foreach ($adminEmails as $a) { $aMail->addAddress($a['email'], $a['name']); }
            $aMail->Subject = "Visit Rescheduled – $clientName (" . ($c['lead_code'] ?: '') . ')';
            $aMail->isHTML(true);
            $aMail->Body = $aHtml;
            $aMail->AltBody = $aPlain;
            $aMail->send();
        } catch (Throwable $e) {
            $mailWarning = 'the sales team could not be emailed [' . $aMail->ErrorInfo . ']';
        }
    } else {
        $mailWarning = 'no admin has an email address on file, so no one could be emailed';
    }
    // Permanent CRM notification: one row per admin so it hits each of their
    // bells and the admin Notification Log (same table as lead assignments
    // and source-lead events — see api/notif_log_lib.php).
    $label = "$Noun moved to $niceDate, $niceTime (was $niceOrig)";
    foreach ($admins as $a) {
        log_notification($pdo, 'reschedule_proposed', $CURRENT_USER_ID, (int)$a['id'], $id, null, $label);
    }
}

echo json_encode(['success' => true, 'log_entry' => $entry, 'from' => $spEmail, 'email' => $email, 'sent_at' => date('Y-m-d H:i:s'), 'sequence' => $seq, 'mail_warning' => $mailWarning]);
