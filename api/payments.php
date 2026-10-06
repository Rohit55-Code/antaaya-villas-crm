<?php
// api/payments.php — Accounts desk: payment schedule, construction-linked demands and payments.
//
// GET  ?client_id=ID                  -> everything the Construction & Payment screen needs (JSON);
//                                        makes the payment schedule automatically the first time
// GET  ?action=letter&id=DEMAND       -> printable demand letter (HTML — Print / Save as PDF); &pdf=1 -> the A4 PDF
// GET  ?action=receipt&id=PAYMENT     -> printable payment receipt (HTML); &pdf=1 -> the A4 PDF
// POST {action:'update_status', client_id, stage, milestone_date, due_date}  -> construction status + its demand, one step
// POST {action:'undo_status', client_id}                     -> undo the last status update
// POST {action:'add_payment', client_id, pay_date, amount, mode, ref_no, note} (+ optional file, multipart)
// POST {action:'delete_payment', id}
// POST {action:'send_email', id}                             -> demand / reminder email to the client, A4 letter PDF attached
// POST {action:'whatsapp_sent', id, shared:1}                -> logs the WhatsApp send. shared=1 (the app now): the browser
//                                                               shared the A4 letter PDF itself as a file (made via
//                                                               ?action=letter&pdf=1) — nothing to make here. Without it
//                                                               (older pages): makes the PDF + its public link (letter.php)
// POST {action:'letter_opened', id, reminder}                -> Letter / Reminder opened to print: Lead History entry
//                                                               ("Demand note #6 opened to print…") for the Demand Log
// Everything is on the Final Villa Price only — no GST / TDS (GST is collected manually).
// View: admin, Accounts desk (leads transferred to Accounts), Legal desk (read-only).
// Edit: admin and the Accounts desk, only on leads transferred to Accounts.
// Every change writes its own Lead History entry and keeps the lead's payment columns in sync.
date_default_timezone_set('Asia/Kolkata');
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/site.php';
require_once __DIR__ . '/payment_lib.php';
require_once __DIR__ . '/legal_docs_lib.php';

function pfail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }

function pay_access($pdo, $clientId) {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$clientId]);
    $c = $st->fetch();
    if (!$c) pfail(404, 'Lead not found');
    $withAcc = lead_with_accounts($c);
    $edit = (is_admin() || is_accounts_desk()) && $withAcc;
    $view = $edit || is_admin() || (is_legal_desk() && lead_with_post_sales($c));
    if (!$view) pfail(403, 'Not allowed');
    return [$c, $edit];
}
function pay_data($pdo, $clientId) {
    try { return pay_load($pdo, $clientId); }
    catch (PDOException $e) { pfail(500, 'Payment tables missing — run migration_v37.sql in phpMyAdmin first.'); }
}
function pay_payload($pdo, $c, $edit, $data = null) {
    $data = $data ?: pay_data($pdo, $c['id']);
    return array_merge($data, [
        'client_id' => (int)$c['id'],
        'can_edit' => $edit,
        'price' => pay_price_of($c),
        'stage' => $c['stage'],
        'today' => date('Y-m-d'),
        'booking' => [
            'amount' => (float)($c['bookingamount'] ?? 0), 'date' => $c['bookingdate'] ?? null, 'token' => (float)($c['token_amount'] ?? 0),
            'mode' => $c['booking_payment_mode'] ?? null, 'ref' => $c['booking_txn_ref'] ?? null,
        ],
    ]);
}
// After a change: lead columns synced, fresh payload + the Lead History entries it wrote.
function pay_respond($pdo, $clientId, $edit, $entries, $extra = []) {
    global $CURRENT_USER_NAME;
    $data = pay_data($pdo, $clientId);
    pay_sync_client($pdo, $clientId, $data);
    [$logEntries, $log] = $entries ? pay_history($pdo, $clientId, $entries, $CURRENT_USER_NAME) : [[], null];
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$clientId]);
    $c = $st->fetch();
    echo json_encode(array_merge([
        'success' => true,
        'data' => pay_payload($pdo, $c, $edit, $data),
        'log_entries' => $logEntries,
        'activity_log' => $log ?? $c['activity_log'],
        'client' => [
            'stage' => $c['stage'], 'received' => $c['received'], 'pay_demanded' => $c['pay_demanded'], 'dueamount' => $c['dueamount'],
            'duedate' => $c['duedate'], 'last_payment_date' => $c['last_payment_date'], 'payment_plan' => $c['payment_plan'],
            'construction' => $c['construction'], 'construction_milestone' => $c['construction_milestone'],
            'construction_update_date' => $c['construction_update_date'],
        ],
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
// Construction checkpoints move forward on their own when their demand is raised (the stage of
// work is done). Never moves a lead back, never touches a lead that isn't with Accounts.
function pay_advance_stage($pdo, $c, $target) {
    $ci = array_search($c['stage'], FOLLOWUP_PIPELINE, true);
    $ti = array_search($target, FOLLOWUP_PIPELINE, true);
    if ($ci === false || $ti === false || $ci >= $ti || !lead_with_accounts($c)) return null;
    $pdo->prepare('UPDATE clients SET stage = ? WHERE id = ?')->execute([$target, $c['id']]);
    return $target;
}
// The schedule is made automatically (standard % of the Final Villa Price, booking from Legal)
// the first time Accounts / admin opens a lead that Legal has transferred.
function pay_ensure_plan($pdo, $c) {
    global $CURRENT_USER_ID;
    $data = pay_data($pdo, $c['id']);
    if ($data['plan']) return $data;
    $price = pay_price_of($c);
    if ($price <= 0) pfail(400, 'Final Villa Price is not set (Booking Initiated) — ask the Legal team or an admin to fill it in first.');
    $amounts = []; $sum = 0;
    foreach (PAY_TEMPLATE as $i => $t) { $amounts[$i] = round($price * $t['pct'] / 100, 2); $sum += $amounts[$i]; }
    $amounts[count($amounts) - 1] = round($amounts[count($amounts) - 1] + ($price - $sum), 2); // rounding paise go to the last instalment
    $bookAmt = (float)($c['bookingamount'] ?? 0) ?: $amounts[0];
    $bookDate = $c['bookingdate'] ?: date('Y-m-d');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO payment_plans (client_id, base_price, due_days, plan_name, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$c['id'], $price, PAY_DEFAULT_DUE_DAYS, 'Construction Linked', $CURRENT_USER_ID]);
        $ins = $pdo->prepare('INSERT INTO payment_schedule (client_id, seq, code, label, pct, amount, milestone_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (PAY_TEMPLATE as $i => $t) $ins->execute([$c['id'], $i + 1, $t['code'], $t['label'], $t['pct'], $amounts[$i], $t['code'] === 'booking' ? $bookDate : null]);
        $pdo->prepare("INSERT INTO client_payments (client_id, pay_date, amount, mode, ref_no, note, source, created_by) VALUES (?, ?, ?, ?, ?, ?, 'booking', ?)")
            ->execute([$c['id'], $bookDate, min($bookAmt, $price), $c['booking_payment_mode'] ?: null, $c['booking_txn_ref'] ?: null, 'Booking amount — collected by the Legal team', $CURRENT_USER_ID]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int)$e->getCode() === 23000) return pay_data($pdo, $c['id']); // made at the same moment by another request
        pfail(500, 'Could not set up the payment schedule: ' . $e->getMessage());
    }
    $data = pay_data($pdo, $c['id']);
    pay_sync_client($pdo, $c['id'], $data);
    return $data;
}
function pay_milestone_label($code) {
    return $code === 'agreement' ? 'Agreement executed' : ($code === 'possession' ? 'OC / CC received' : 'Stage completed');
}
function find_row($data, $key, $val) {
    foreach ($data['schedule'] as $r) if ((string)$r[$key] === (string)$val) return $r;
    return null;
}
function find_demand($data, $id) {
    foreach ($data['demands'] as $d) if ((int)$d['id'] === (int)$id) return $d;
    return null;
}
function load_open_demand($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM payment_demands WHERE id = ? AND status = 'Open'");
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) pfail(404, 'Demand not found (it may have been withdrawn).');
    return $d;
}

// ---------- Demand letter / receipt (inline-styled HTML: used for the email body and the print view) ----------
function pay_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pay_letter_head($title, $refLine) {
    $h = 'pay_h';
    return '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #1f5c45;margin-bottom:14px"><tr>'
        . '<td style="padding:0 0 10px"><div style="font-size:19px;font-weight:700;color:#1f5c45">' . $h(COMPANY_NAME) . '</div>'
        . '<div style="font-size:13px;color:#444">' . $h(PROJECT_NAME) . '</div>'
        . (COMPANY_ADDRESS ? '<div style="font-size:11.5px;color:#666">' . $h(COMPANY_ADDRESS) . '</div>' : '')
        . (MAHARERA_NO ? '<div style="font-size:11.5px;color:#666">MahaRERA Reg. No. ' . $h(MAHARERA_NO) . '</div>' : '')
        . '</td><td style="padding:0 0 10px;text-align:right;vertical-align:bottom"><div style="font-size:15px;font-weight:700;letter-spacing:.5px;color:#222">' . $h($title) . '</div>'
        . '<div style="font-size:12px;color:#555">' . $refLine . '</div></td></tr></table>';
}
function pay_row_html($label, $value, $bold = false, $top = false) {
    $b = $bold ? 'font-weight:700;' : '';
    $t = $top ? 'border-top:1px solid #999;' : '';
    return '<tr><td style="padding:5px 10px;' . $b . $t . '">' . $label . '</td><td style="padding:5px 10px;text-align:right;white-space:nowrap;' . $b . $t . '">' . $value . '</td></tr>';
}
function pay_bank_html() {
    if (!defined('PAY_BANK') || empty(PAY_BANK['account'])) return '';
    $b = PAY_BANK; $h = 'pay_h';
    $rows = '';
    foreach ([['Account Name', $b['name']], ['Bank', $b['bank']], ['Account No.', $b['account']], ['IFSC', $b['ifsc']], ['Branch', $b['branch']]] as [$l, $v]) {
        if ($v) $rows .= '<tr><td style="padding:3px 10px;color:#555">' . $l . '</td><td style="padding:3px 10px;font-weight:600">' . $h($v) . '</td></tr>';
    }
    return '<p style="margin:14px 0 4px;font-weight:700">Bank details for payment</p><table style="border-collapse:collapse;font-size:13px;background:#f6f8f7;border-radius:6px">' . $rows . '</table>';
}
// What's still to pay on a demand right now: the demand's total minus money received after it was raised
// (the latest demand = everything due now; an older one = its own live balance).
function pay_still_due($data, $d) {
    $isLatest = $data['demands'] && (int)end($data['demands'])['id'] === (int)$d['id'];
    return max(0, round($isLatest ? $data['summary']['due_now'] : $d['balance'], 2));
}
function pay_letter_ref($c, $d) { return ($c['lead_code'] ?: ('L' . $c['id'])) . '/' . str_pad((string)$d['demand_no'], 2, '0', STR_PAD_LEFT); }
function pay_letter_html($c, $data, $d, $reminder) {
    $h = 'pay_h';
    $s = $data['summary']; $plan = $data['plan'];
    $villa = trim((string)$c['villa']) ?: 'your villa';
    $name = trim((string)$c['name']);
    $code = $d['code'];
    $what = $code === 'agreement' ? 'the Agreement for Sale for <b>' . $h($villa) . '</b> has been executed'
        : ($code === 'possession' ? 'the Occupancy / Completion Certificate has been received from PMRDA and <b>' . $h($villa) . '</b> is ready for possession'
        : 'the <b>' . $h($d['short']) . '</b> stage of work of <b>' . $h($villa) . '</b> has been completed');
    $ref = 'Demand No. ' . $h(pay_letter_ref($c, $d))
        . '<br>Date: ' . $h(pay_day($reminder ? date('Y-m-d') : $d['demand_date']));

    $to = '<p style="margin:0 0 12px;font-size:13.5px;line-height:1.5">To,<br><b>' . $h($name) . '</b>'
        . (trim((string)$c['co_applicant_name']) ? ' &amp; ' . $h($c['co_applicant_name']) : '')
        . (trim((string)$c['applicant_address']) ? '<br>' . nl2br($h($c['applicant_address'])) : '')
        . ($c['mobile'] ? '<br>Mobile: ' . $h($c['mobile']) : '') . '</p>';

    // Payments received after the demand was raised come off the amount payable (the demand row itself keeps
    // what was demanded; the letter / email / WhatsApp always ask for the live balance).
    $stillDue = pay_still_due($data, $d);
    $diff = round($d['total_due'] - $stillDue, 2);
    $adjRow = $diff > PAY_ROUND ? pay_row_html('Less: received after this demand (till ' . $h(pay_day(date('Y-m-d'))) . ')', '− ' . pay_inr($diff))
        : ($diff < -PAY_ROUND ? pay_row_html('Add: payment entries reversed since this demand', pay_inr(-$diff)) : '');
    $calc = '<table style="border-collapse:collapse;width:100%;max-width:560px;font-size:13.5px;margin:6px 0 4px;border:1px solid #ddd">'
        . '<tr style="background:#f1f6f3"><th style="text-align:left;padding:6px 10px">Particulars</th><th style="text-align:right;padding:6px 10px">Amount</th></tr>'
        . pay_row_html($h($d['label']) . ' — ' . pay_pct_txt($d['pct']) . ' of ' . pay_inr($plan['base_price']), pay_inr($d['instalment']))
        . ($d['carried_forward'] > 0 ? pay_row_html('Add: unpaid from earlier demand(s)', pay_inr($d['carried_forward'])) : '')
        . ($d['advance_adjusted'] > 0 ? pay_row_html('Less: paid in advance', '− ' . pay_inr($d['advance_adjusted'])) : '')
        . $adjRow
        . pay_row_html('Amount payable', $stillDue > 0 ? pay_inr($stillDue) : 'Nil — paid in full', true, true)
        . '</table><div style="font-size:12px;color:#555;margin-bottom:10px">' . ($stillDue > 0 ? $h(pay_words($stillDue)) : '') . '</div>';

    $reminderLine = '';
    if ($reminder) {
        $reminderLine = '<p style="margin:10px 0;padding:10px 12px;background:#fff4e5;border-left:3px solid #b54708;font-size:13.5px">'
            . 'This is a reminder that the payment below ' . ($d['due_date'] < date('Y-m-d') ? 'was' : 'is') . ' due on <b>' . $h(pay_day($d['due_date'])) . '</b>. '
            . 'After adjusting payments received so far, <b>' . pay_inr($stillDue) . '</b>'
            . ' is still due as on ' . $h(pay_day(date('Y-m-d'))) . '. Please ignore this if you have already paid.</p>';
    }
    $summary = '<table style="border-collapse:collapse;font-size:12.5px;margin-top:6px">'
        . '<tr><td style="padding:3px 10px 3px 0;color:#555">Villa price (total consideration)</td><td style="padding:3px 0;text-align:right">' . pay_inr($plan['base_price']) . '</td></tr>'
        . '<tr><td style="padding:3px 10px 3px 0;color:#555">Received till date</td><td style="padding:3px 0;text-align:right">' . pay_inr($s['credited']) . ' (' . pay_pct_txt($s['received_pct']) . ')</td></tr>'
        . '<tr><td style="padding:3px 10px 3px 0;color:#555">Demanded till date</td><td style="padding:3px 0;text-align:right">' . pay_inr($s['demanded']) . ' (' . pay_pct_txt($s['demanded_pct']) . ')</td></tr>'
        . '<tr><td style="padding:3px 10px 3px 0;color:#555">Balance payable at later stages</td><td style="padding:3px 0;text-align:right">' . pay_inr(max(0, $plan['base_price'] - $s['demanded'])) . '</td></tr></table>';
    $notes = '<ul style="font-size:12px;color:#444;padding-left:18px;margin:12px 0">'
        . '<li>Please mention <b>' . $h($villa) . '</b> / ' . $h($c['lead_code'] ?: '') . ' as the payment reference and share the transaction details with us.</li>'
        . '<li>Payments made after the due date attract interest as per your Agreement for Sale and the MahaRERA rules.</li>'
        . '</ul>';
    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#222;max-width:700px;font-size:14px">'
        . pay_letter_head($reminder ? 'PAYMENT REMINDER' : 'DEMAND NOTE', $ref) . $to
        . '<p style="margin:0 0 10px"><b>Sub: Payment due for ' . $h($d['short']) . ' (' . pay_pct_txt($d['pct']) . ') — ' . $h($villa) . ', ' . $h(PROJECT_NAME) . '</b></p>'
        . '<p style="margin:0 0 10px">Dear ' . $h($name) . ',</p>'
        . $reminderLine
        . '<p style="margin:0 0 8px;line-height:1.5">We are pleased to inform you that ' . $what
        . ($d['milestone_date'] ? ' (on ' . $h(pay_day($d['milestone_date'])) . ')' : '') . '. As per the payment schedule of your Agreement for Sale, the following amount is now payable:</p>'
        . $calc
        . '<p style="margin:8px 0;font-size:14.5px">Kindly pay on or before <b>' . $h(pay_day($d['due_date'])) . '</b>.</p>'
        . pay_bank_html() . $summary . $notes
        . '<p style="margin:16px 0 0">Thanking you,<br><b>For ' . $h(COMPANY_NAME) . '</b><br>Accounts Team' . (ACCOUNTS_PHONE ? ' · ' . $h(ACCOUNTS_PHONE) : '') . '</p>'
        . '</div>';
}
function pay_receipt_html($c, $data, $p) {
    $h = 'pay_h';
    $credited = $p['amount'];
    $ref = 'Receipt No. ' . $h(($c['lead_code'] ?: ('L' . $c['id'])) . '/R' . str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT)) . '<br>Date: ' . $h(pay_day($p['pay_date']));
    $rows = pay_row_html('Amount received towards the villa price' . ($p['mode'] ? ' (' . $h($p['mode']) . ')' : ''), pay_inr($credited), true);
    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#222;max-width:700px;font-size:14px">'
        . pay_letter_head('PAYMENT RECEIPT', $ref)
        . '<p style="line-height:1.6">Received with thanks from <b>' . $h($c['name']) . '</b>'
        . (trim((string)$c['co_applicant_name']) ? ' &amp; <b>' . $h($c['co_applicant_name']) . '</b>' : '')
        . ' the sum of <b>' . pay_inr($credited) . '</b> (' . $h(pay_words($credited)) . ')'
        . ' towards <b>' . $h(trim((string)$c['villa']) ?: 'the villa') . '</b>, ' . $h(PROJECT_NAME) . '.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:560px;font-size:13.5px;border:1px solid #ddd">' . $rows . '</table>'
        . '<p style="font-size:13px;color:#444">' . ($p['ref_no'] ? 'Cheque / UTR / Ref. No.: <b>' . $h($p['ref_no']) . '</b><br>' : '')
        . 'Total received till date: <b>' . pay_inr($data['summary']['credited']) . '</b> of ' . pay_inr($data['plan']['base_price'])
        . ' (' . pay_pct_txt($data['summary']['received_pct']) . ')</p>'
        . '<p style="font-size:12px;color:#666">Cheque payments are subject to realisation.</p>'
        . '<p style="margin:22px 0 0"><b>For ' . $h(COMPANY_NAME) . '</b><br><br>Authorised Signatory</p></div>';
}
// Short covering message for the demand / reminder email — the full letter goes along as the A4 PDF.
function pay_cover_html($c, $data, $d, $reminder) {
    $h = 'pay_h';
    $villa = trim((string)$c['villa']) ?: 'your villa';
    $amt = pay_still_due($data, $d);
    $was = $d['due_date'] < date('Y-m-d') ? 'was' : 'is';
    $intro = $reminder
        ? 'This is a gentle reminder that the payment for <b>' . $h($villa) . '</b> (Demand No. ' . $h(pay_letter_ref($c, $d)) . ') ' . $was . ' due on <b>'
            . $h(pay_day($d['due_date'])) . '</b>. Please find attached the payment reminder letter.'
        : 'Please find attached our Demand Note No. <b>' . $h(pay_letter_ref($c, $d)) . '</b> dated ' . $h(pay_day($d['demand_date']))
            . ' for the <b>' . $h($d['short']) . ' (' . pay_pct_txt($d['pct']) . ')</b> instalment of <b>' . $h($villa) . '</b>, ' . $h(PROJECT_NAME) . '.';
    $td = 'padding:8px 14px;border-bottom:1px solid #e3ece7';
    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#222;max-width:640px;font-size:14px;line-height:1.55">'
        . '<p style="margin:0 0 12px">Dear ' . $h(trim((string)$c['name'])) . ',</p>'
        . '<p style="margin:0 0 12px">' . $intro . '</p>'
        . '<table style="border-collapse:collapse;margin:0 0 12px;border:1px solid #d6e4dc;background:#f6fbf8;font-size:14px">'
        . '<tr><td style="' . $td . ';color:#555">Amount payable</td><td style="' . $td . ';font-weight:700;font-size:16px;color:#1f5c45;text-align:right">' . pay_inr($amt) . '</td></tr>'
        . '<tr><td style="padding:8px 14px;color:#555">Kindly pay on or before</td><td style="padding:8px 14px;font-weight:700;text-align:right">' . $h(pay_day($d['due_date'])) . '</td></tr></table>'
        . pay_bank_html()
        . '<p style="margin:12px 0 0">Please mention <b>' . $h($villa) . '</b>' . ($c['lead_code'] ? ' / ' . $h($c['lead_code']) : '') . ' as the payment reference and share the transaction details with us.'
        . ($reminder ? ' Please ignore this if you have already paid.' : '') . '</p>'
        . '<p style="margin:16px 0 0">Thanking you,<br><b>For ' . $h(COMPANY_NAME) . '</b><br>Accounts Team' . (ACCOUNTS_PHONE ? ' · ' . $h(ACCOUNTS_PHONE) : '') . '</p>'
        . '</div>';
}

// ---------- A4 PDF (Dompdf, bundled in lib/dompdf — no Composer needed on the server) ----------
// Attached to the demand / reminder email and linked in the WhatsApp message (WhatsApp chat links
// can't carry a file, so the client opens the PDF from a private link — see letter.php).
const PAY_PDF_DIR = __DIR__ . '/../uploads/demand_letters';
function pay_pdf_name($c, $d, $reminder) {
    return ($reminder ? 'Payment-Reminder-' : 'Demand-Note-') . trim(preg_replace('/[^A-Za-z0-9]+/', '-', pay_letter_ref($c, $d)), '-') . '.pdf';
}
function pay_pdf($bodyHtml) {
    require_once __DIR__ . '/../lib/dompdf/autoload.inc.php';
    // Text prints in Helvetica (same look as Arial in the print view). The few characters Helvetica
    // doesn't have (₹, −) switch to DejaVu Sans, which does.
    $body = preg_replace('/[^\x{0000}-\x{00FF}\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2026}]+/u', '<span style="font-family:\'DejaVu Sans\'">$0</span>', $bodyHtml);
    $cache = PAY_PDF_DIR . '/.fontcache';
    if (!is_dir($cache)) @mkdir($cache, 0755, true);
    $opt = new Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'Helvetica');
    $opt->set('defaultPaperSize', 'a4');
    if (is_dir($cache) && is_writable($cache)) { $opt->set('fontCache', $cache); $opt->set('tempDir', $cache); }
    $pdf = new Dompdf\Dompdf($opt);
    $pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4 portrait;margin:16mm 16mm 14mm}'
        . 'body{margin:0;font-family:Helvetica,Arial,sans-serif}</style></head><body>' . $body . '</body></html>', 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return $pdf->output();
}
// Saves a PDF for its public link; returns the 32-character random code that opens it.
function pay_pdf_store($clientId, $name, $bytes) {
    $dir = PAY_PDF_DIR . '/' . (int)$clientId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) pfail(500, 'Could not create the uploads/demand_letters folder — nothing was sent.');
    $code = bin2hex(random_bytes(16));
    if (file_put_contents("$dir/{$code}__$name", $bytes) === false) pfail(500, 'Could not save the letter PDF — nothing was sent.');
    return $code;
}
// Full address of a page in the CRM folder, e.g. https://antaayavillas.com/crm/letter.php?t=…
function pay_public_url($path) {
    if (defined('CRM_PUBLIC_URL') && CRM_PUBLIC_URL) return rtrim(CRM_PUBLIC_URL, '/') . '/' . $path;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/'); // this file is <crm>/api/payments.php
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base . '/' . $path;
}
function pay_pdf_out($name, $bodyHtml) {
    try { $bytes = pay_pdf($bodyHtml); }
    catch (Throwable $e) { header('Content-Type: text/plain; charset=utf-8'); http_response_code(500); echo 'Could not make the PDF: ' . $e->getMessage(); exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

function pay_print_page($title, $body, $pdfHref = '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . pay_h($title) . '</title>'
        . '<style>body{margin:0;background:#eef1ef;font-family:Arial,sans-serif}.sheet{background:#fff;max-width:760px;margin:24px auto;padding:36px 40px;box-shadow:0 2px 14px rgba(0,0,0,.08)}'
        . '.bar{max-width:760px;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-end}.bar button,.bar a{border:0;border-radius:8px;padding:9px 16px;font-weight:700;font-size:13.3px;cursor:pointer;background:#1f5c45;color:#fff;text-decoration:none;font-family:inherit}'
        . '.bar .alt{background:#fff;color:#1f5c45;border:1px solid #1f5c45}'
        . '@page{size:A4;margin:16mm}@media print{body{background:#fff}.bar{display:none}.sheet{box-shadow:none;margin:0;padding:0;max-width:none}}</style></head><body>'
        . '<div class="bar"><button class="alt" onclick="window.close()">Close</button>'
        . ($pdfHref ? '<a class="alt" href="' . pay_h($pdfHref) . '" target="_blank" rel="noopener">Download PDF (A4)</a>' : '')
        . '<button onclick="window.print()">Print / Save as PDF</button></div>'
        . '<div class="sheet">' . $body . '</div></body></html>';
    exit;
}

// ---------- GET ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'letter') {
        $st = $pdo->prepare("SELECT client_id FROM payment_demands WHERE id = ? AND status = 'Open'");
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $cid = $st->fetchColumn();
        if (!$cid) pfail(404, 'Demand not found');
        [$c] = pay_access($pdo, (int)$cid);
        $data = pay_data($pdo, $cid);
        $d = find_demand($data, (int)$_GET['id']);
        $reminder = !empty($_GET['reminder']);
        if (!empty($_GET['pdf'])) pay_pdf_out(pay_pdf_name($c, $d, $reminder), pay_letter_html($c, $data, $d, $reminder));
        pay_print_page(($reminder ? 'Payment Reminder' : 'Demand Note') . ' #' . $d['demand_no'] . ' — ' . $c['name'], pay_letter_html($c, $data, $d, $reminder),
            'payments.php?action=letter&id=' . (int)$d['id'] . ($reminder ? '&reminder=1' : '') . '&pdf=1');
    }
    if ($action === 'receipt') {
        $st = $pdo->prepare('SELECT * FROM client_payments WHERE id = ?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $p = $st->fetch();
        if (!$p) pfail(404, 'Payment not found');
        [$c] = pay_access($pdo, (int)$p['client_id']);
        $data = pay_data($pdo, $p['client_id']);
        $p['amount'] = (float)$p['amount'];
        if (!empty($_GET['pdf'])) pay_pdf_out('Receipt-' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', ($c['lead_code'] ?: ('L' . $c['id'])) . '-R' . str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT)), '-') . '.pdf', pay_receipt_html($c, $data, $p));
        pay_print_page('Receipt — ' . $c['name'], pay_receipt_html($c, $data, $p), 'payments.php?action=receipt&id=' . (int)$p['id'] . '&pdf=1');
    }
    $clientId = (int)($_GET['client_id'] ?? 0);
    if (!$clientId) pfail(400, 'client_id required');
    [$c, $edit] = pay_access($pdo, $clientId);
    $data = ($edit && pay_price_of($c) > 0) ? pay_ensure_plan($pdo, $c) : null;
    echo json_encode(pay_payload($pdo, $c, $edit, $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pfail(405, 'Method not allowed');

$in = !empty($_POST['action']) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
$action = $in['action'] ?? '';
if ($action === '' && empty($_FILES) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) pfail(400, 'Nothing received — the file may be larger than the server upload limit.');

// ---------- Construction Status update = raise that stage's demand (one step) ----------
// Picking a later status also covers any stages in between (each gets its own demand row, the
// last one carries everything still unpaid). The lead moves to that stage straight away.
if ($action === 'update_status') {
    [$c, $edit] = pay_access($pdo, (int)($in['client_id'] ?? 0));
    if (!$edit) pfail(403, 'Only the Accounts team or an admin can update the construction status.');
    $data = pay_ensure_plan($pdo, $c);
    $target = null;
    foreach ($data['schedule'] as $r) if ($r['seq'] >= 2 && $r['stage'] === ($in['stage'] ?? '')) $target = $r;
    if (!$target) pfail(400, 'Unknown construction status.');
    if ($target['demanded']) pfail(400, "\"{$target['stage']}\" is already done — pick a later status.");
    $today = date('Y-m-d');
    $mDate = trim((string)($in['milestone_date'] ?? ''));
    $due = trim((string)($in['due_date'] ?? '')) ?: date('Y-m-d', strtotime("$today +{$data['plan']['due_days']} days"));
    if (!valid_date($mDate)) pfail(400, 'Pick the date this stage was completed.');
    if ($mDate > $today) pfail(400, 'Completed date can\'t be in the future.');
    if (!valid_date($due)) pfail(400, 'Pay-by date is not valid.');
    if ($due < $today) pfail(400, 'Pay-by date can\'t be in the past.');

    $rows = array_values(array_filter($data['schedule'], fn($r) => $r['seq'] >= 2 && $r['seq'] <= $target['seq'] && !$r['demanded']));
    $credited = $data['summary']['credited'];
    $no = (int)$pdo->query('SELECT COALESCE(MAX(demand_no), 0) + 1 FROM payment_demands WHERE client_id = ' . (int)$c['id'])->fetchColumn();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            // Carry forward: everything before this instalment that's still unpaid is added to it;
            // anything paid ahead of schedule is taken off it.
            $prevDue = 0;
            foreach ($data['schedule'] as $r) if ($r['seq'] < $row['seq']) $prevDue += $r['amount'];
            $carried = max(0, round($prevDue - $credited, 2));
            $advance = min(max(0, round($credited - $prevDue, 2)), $row['amount']);
            $total = max(0, round($row['amount'] + $carried - $advance, 2));
            $pdo->prepare('INSERT INTO payment_demands (client_id, schedule_id, demand_no, demand_date, due_date, instalment, carried_forward, advance_adjusted, total_due, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$c['id'], $row['id'], $no, $today, $due, $row['amount'], $carried, $advance, $total, $CURRENT_USER_ID]);
            $pdo->prepare('UPDATE payment_schedule SET milestone_date = ? WHERE id = ?')->execute([$mDate, $row['id']]);
            $no++;
        }
        $moved = pay_advance_stage($pdo, $c, $target['stage']);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        pfail(500, 'Could not update the status: ' . $e->getMessage());
    }
    $also = count($rows) > 1 ? ' · Also covers: ' . implode(', ', array_map(fn($r) => $r['short'], array_slice($rows, 0, -1))) : '';
    $entries = [];
    if ($moved) $entries[] = ['Stage Change', "Moved to {$target['stage']}"];
    $entries[] = [$target['stage'], "{$target['stage']} — Demand #" . ($no - 1) . ' raised: ' . pay_inr($total)
        . ' · Instalment: ' . pay_inr($target['amount']) . ' (' . pay_pct_txt($target['pct']) . ')'
        . ($carried > 0 ? ' · Unpaid from earlier: ' . pay_inr($carried) : '') . ($advance > 0 ? ' · Advance adjusted: ' . pay_inr($advance) : '')
        . ' · Pay by: ' . pay_day($due) . ' · ' . pay_milestone_label($target['code']) . ': ' . pay_day($mDate) . $also];
    pay_respond($pdo, $c['id'], $edit, $entries, ['moved_to' => $moved]);
}

// ---------- Undo the last status update (raised by mistake) ----------
// Withdraws the latest demand and moves the lead back to the stage before it.
if ($action === 'undo_status') {
    [$c, $edit] = pay_access($pdo, (int)($in['client_id'] ?? 0));
    if (!$edit) pfail(403, 'Only the Accounts team or an admin can do this.');
    $data = pay_data($pdo, $c['id']);
    $d = $data['demands'] ? end($data['demands']) : null;
    if (!$d) pfail(400, 'Nothing to undo.');
    if ($c['stage'] !== $d['stage']) pfail(400, "The lead has moved on from \"{$d['stage']}\" — this can't be undone now.");
    $prevStage = 'Registered';
    foreach ($data['demands'] as $x) if ((int)$x['id'] !== (int)$d['id']) $prevStage = $x['stage'];
    $pdo->prepare("UPDATE payment_demands SET status = 'Withdrawn' WHERE id = ?")->execute([$d['id']]);
    $pdo->prepare('UPDATE payment_schedule SET milestone_date = NULL WHERE id = ?')->execute([$d['schedule_id']]);
    $moved = null;
    if ($prevStage !== $c['stage']) { $pdo->prepare('UPDATE clients SET stage = ? WHERE id = ?')->execute([$prevStage, $c['id']]); $moved = $prevStage; }
    $entries = [[$d['stage'], "{$d['stage']} — Status update undone · Demand #{$d['demand_no']} withdrawn: " . pay_inr($d['total_due'])]];
    if ($moved) $entries[] = ['Stage Change', "Moved to $moved"];
    pay_respond($pdo, $c['id'], $edit, $entries, ['moved_to' => $moved]);
}

// ---------- Log a payment received ----------
if ($action === 'add_payment') {
    [$c, $edit] = pay_access($pdo, (int)($in['client_id'] ?? 0));
    if (!$edit) pfail(403, 'Only the Accounts team or an admin can log payments.');
    $data = pay_ensure_plan($pdo, $c);
    $date = trim((string)($in['pay_date'] ?? ''));
    if (!valid_date($date)) pfail(400, 'Payment date is required.');
    if ($date > date('Y-m-d')) pfail(400, 'Payment date can\'t be in the future.');
    $amt = round((float)($in['amount'] ?? 0), 2);
    if ($amt <= 0) pfail(400, 'Enter the amount received.');
    $s = $data['summary'];
    if ($s['credited'] + $amt > $s['base_price'] + 1) {
        pfail(400, 'This is more than the balance of ' . pay_inr($s['balance']) . ' left on the villa price — please check the amount.');
    }
    $mode = trim((string)($in['mode'] ?? ''));
    if ($mode !== '' && !in_array($mode, PAY_MODES, true)) pfail(400, 'Unknown payment mode.');
    $refNo = mb_substr(trim((string)($in['ref_no'] ?? '')), 0, 100);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);
    $demandId = null; $demandNo = null;
    foreach ($data['demands'] as $d) if ($d['balance'] > 0) { $demandId = (int)$d['id']; $demandNo = $d['demand_no']; break; }
    if (!$demandId && $data['demands']) { $last = end($data['demands']); $demandId = (int)$last['id']; $demandNo = $last['demand_no']; }

    // Optional proof (bank screenshot / cheque scan / Form 16B) — kept with the lead's documents.
    $docId = null; $docEntry = null;
    $f = $_FILES['file'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK) pfail(400, in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'File is larger than the server upload limit.' : 'Upload failed — please try again.');
        if ($f['size'] > 10 * 1024 * 1024) pfail(400, 'File must be under 10 MB');
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset($allowed[$mime])) pfail(400, 'Payment proof must be a PDF, JPG, PNG or WEBP file.');
        $docType = 'payment_proof';
        $dir = __DIR__ . '/../uploads/client_docs/' . (int)$c['id'];
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) pfail(500, 'Could not create upload folder');
        $stored = $docType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) pfail(500, 'Failed to save file');
        $docStage = in_array($c['stage'], ACCOUNTS_STAGES, true) ? $c['stage'] : 'Construction Customer';
        $label = LEGAL_DOC_TYPES[$docType]['label'] . ' — ' . pay_day($date) . ' · ' . pay_inr($amt);
        $orig = mb_substr(basename($f['name']), 0, 200);
        $pdo->prepare('INSERT INTO client_documents (client_id, doc_type, doc_label, stage, original_name, stored_name, mime, size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$c['id'], $docType, $label, $docStage, $orig, $stored, $mime, (int)$f['size'], $CURRENT_USER_ID]);
        $docId = (int)$pdo->lastInsertId();
        $docEntry = ['Document', "Document uploaded — $label ($orig) · $docStage"];
    }
    $pdo->prepare("INSERT INTO client_payments (client_id, demand_id, pay_date, amount, mode, ref_no, note, source, doc_id, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'accounts', ?, ?)")
        ->execute([$c['id'], $demandId, $date, $amt, $mode ?: null, $refNo ?: null, $note ?: null, $docId, $CURRENT_USER_ID]);
    $entries = [['Payment Received', 'Payment received — ' . pay_inr($amt)
        . ($mode ? " · $mode" : '') . ($refNo ? " · Ref $refNo" : '') . ($demandNo ? " · against Demand #$demandNo" : '') . ' · ' . pay_day($date)]];
    if ($docEntry) $entries[] = $docEntry;
    pay_respond($pdo, $c['id'], $edit, $entries);
}

// ---------- Delete a payment entry (logged by mistake) ----------
if ($action === 'delete_payment') {
    $st = $pdo->prepare('SELECT * FROM client_payments WHERE id = ?');
    $st->execute([(int)($in['id'] ?? 0)]);
    $p = $st->fetch();
    if (!$p) pfail(404, 'Payment not found');
    [$c, $edit] = pay_access($pdo, (int)$p['client_id']);
    if (!$edit) pfail(403, 'Only the Accounts team or an admin can delete payments.');
    $pdo->prepare('DELETE FROM client_payments WHERE id = ?')->execute([$p['id']]);
    if ($p['doc_id']) {
        $ds = $pdo->prepare('SELECT * FROM client_documents WHERE id = ?');
        $ds->execute([$p['doc_id']]);
        if ($doc = $ds->fetch()) {
            $pdo->prepare('DELETE FROM client_documents WHERE id = ?')->execute([$doc['id']]);
            $fp = __DIR__ . '/../uploads/client_docs/' . (int)$doc['client_id'] . '/' . basename($doc['stored_name']);
            if (is_file($fp)) unlink($fp);
        }
    }
    pay_respond($pdo, $c['id'], $edit, [['Payment Deleted', 'Payment entry deleted — ' . pay_inr((float)$p['amount']) . ' of ' . pay_day($p['pay_date'])
        . ($p['source'] === 'booking' ? ' (booking amount from Legal)' : '')]]);
}

// ---------- Demand / reminder email ----------
if ($action === 'send_email') {
    $dRow = load_open_demand($pdo, (int)($in['id'] ?? 0));
    [$c, $edit] = pay_access($pdo, (int)$dRow['client_id']);
    if (!$edit) pfail(403, 'Only the Accounts team or an admin can send demands.');
    $data = pay_data($pdo, $c['id']);
    $d = find_demand($data, $dRow['id']);
    if ($d['state'] === 'Paid') pfail(400, "Demand #{$d['demand_no']} is already fully paid.");
    if ($d['state'] === 'Carried forward') pfail(400, "What's unpaid on Demand #{$d['demand_no']} has moved into Demand #{$d['carried_to']} — send that one instead.");
    $email = trim((string)($in['email'] ?? '')) ?: trim((string)$c['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) pfail(400, 'This client has no valid email address — add it in the lead\'s basic details.');
    $reminder = $d['email_count'] > 0 || $d['due_date'] < date('Y-m-d');
    // The letter itself goes as an A4 PDF attachment; the email body is a short covering message.
    $pdfName = pay_pdf_name($c, $d, $reminder);
    try { $pdfBytes = pay_pdf(pay_letter_html($c, $data, $d, $reminder)); }
    catch (Throwable $e) { pfail(500, 'Could not make the letter PDF — nothing was sent. [' . $e->getMessage() . ']'); }
    $html = pay_cover_html($c, $data, $d, $reminder);
    $stillDue = pay_still_due($data, $d);
    $subject = ($reminder ? 'Payment Reminder' : 'Payment Due') . " – {$d['short']} (" . pay_pct_txt($d['pct']) . ') – ' . (trim((string)$c['villa']) ?: PROJECT_NAME) . ' – ' . PROJECT_NAME;
    $plain = "Dear {$c['name']},\r\n\r\n" . ($reminder ? "This is a reminder for your payment due on " . pay_day($d['due_date']) . ".\r\n" : '')
        . "Stage: {$d['label']}\r\nInstalment: " . pay_inr($d['instalment']) . "\r\n"
        . ($d['carried_forward'] > 0 ? 'Unpaid from earlier: ' . pay_inr($d['carried_forward']) . "\r\n" : '')
        . ($d['advance_adjusted'] > 0 ? 'Paid in advance: -' . pay_inr($d['advance_adjusted']) . "\r\n" : '')
        . ($d['total_due'] - $stillDue > PAY_ROUND ? 'Received after this demand: -' . pay_inr($d['total_due'] - $stillDue) . "\r\n" : '')
        . 'Amount payable: ' . pay_inr($stillDue) . "\r\n"
        . 'Please pay by ' . pay_day($d['due_date']) . ".\r\n\r\nThe " . ($reminder ? 'payment reminder' : 'demand note') . " is attached ($pdfName).\r\n\r\nAccounts Team, " . COMPANY_NAME . "\r\n";

    require_once __DIR__ . '/../config/mail.php';
    require_once __DIR__ . '/../lib/phpmailer/Exception.php';
    require_once __DIR__ . '/../lib/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/../lib/phpmailer/SMTP.php';
    // Sent as whoever clicks Send (their own Gmail if an App Password is saved for them in Users > Edit).
    $me = $pdo->prepare('SELECT name, email, smtp_app_password FROM users WHERE id = ?');
    $me->execute([$CURRENT_USER_ID]);
    $me = $me->fetch() ?: [];
    $fromName = trim(($me['name'] ?? 'Accounts') . ' – Accounts, ' . PROJECT_NAME);
    $fromEmail = filter_var($me['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $me['email'] : MAIL_FALLBACK_FROM;
    $appPwd = mail_decrypt($me['smtp_app_password'] ?? '');
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
        $mail->Timeout = 15;
        if ($appPwd !== '') {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->Port = 587;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            $mail->Username = $fromEmail;
            $mail->Password = $appPwd;
            $mail->setFrom($fromEmail, $fromName);
        } else {
            $mail->isMail();
            $mail->Sender = MAIL_FALLBACK_FROM;
            $mail->setFrom(MAIL_FALLBACK_FROM, $fromName);
            if ($fromEmail !== MAIL_FALLBACK_FROM) $mail->addReplyTo($fromEmail, $me['name'] ?? 'Accounts');
        }
        $mail->addAddress($email, $c['name']);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plain;
        $mail->addStringAttachment($pdfBytes, $pdfName, PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, 'application/pdf');
        // The stage's certificate (architect / OC), if uploaded, goes along with the demand.
        $att = $pdo->prepare("SELECT * FROM client_documents WHERE client_id = ? AND stage = ? AND doc_type IN ('architect_certificate', 'oc_certificate') ORDER BY id");
        $att->execute([$c['id'], $d['stage']]);
        $bytes = strlen($pdfBytes); $attached = [$pdfName];
        foreach ($att->fetchAll() as $doc) {
            $fp = __DIR__ . '/../uploads/client_docs/' . (int)$c['id'] . '/' . basename($doc['stored_name']);
            if (!is_file($fp) || ($bytes += filesize($fp)) > 15 * 1024 * 1024) continue;
            $mail->addAttachment($fp, $doc['original_name']);
            $attached[] = $doc['original_name'];
        }
        $mail->send();
    } catch (Throwable $e) {
        pfail(500, ($appPwd !== '' ? 'Could not send via Gmail — check your Gmail App Password (Users > Edit) and that the server allows SMTP on port 587.'
            : 'Mail could not be sent. (Emails only send from the live server, not local XAMPP.)') . ' [' . $mail->ErrorInfo . ']');
    }
    $pdo->prepare('UPDATE payment_demands SET email_sent_at = ?, email_to = ?, email_count = email_count + 1 WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $email, $d['id']]);
    pay_respond($pdo, $c['id'], $edit, [['Email Sent', ($reminder ? 'Payment reminder' : 'Payment demand') . " #{$d['demand_no']} emailed to $email — "
        . pay_inr($stillDue) . ' due by ' . pay_day($d['due_date']) . ($attached ? ' · attached: ' . implode(', ', $attached) : '')]],
        ['sent_from' => $appPwd !== '' ? $fromEmail : MAIL_FALLBACK_FROM]);
}

// ---------- WhatsApp message marked as sent ----------
if ($action === 'whatsapp_sent') {
    $dRow = load_open_demand($pdo, (int)($in['id'] ?? 0));
    [$c, $edit] = pay_access($pdo, (int)$dRow['client_id']);
    if (!$edit) pfail(403, 'Only the Accounts team or an admin can do this.');
    $data = pay_data($pdo, $c['id']);
    $d = find_demand($data, $dRow['id']);
    $reminder = !empty($d['whatsapp_sent_at']) || $d['due_date'] < date('Y-m-d');
    $pdfName = pay_pdf_name($c, $d, $reminder);
    $shared = !empty($in['shared']); // the PDF went along as a file from the browser — no link needed
    $pdfUrl = null;
    if (!$shared) {
        // Older pages: WhatsApp chat links can't carry a file — the message carries a private link to the A4 PDF.
        try { $pdfBytes = pay_pdf(pay_letter_html($c, $data, $d, $reminder)); }
        catch (Throwable $e) { pfail(500, 'Could not make the letter PDF — nothing was sent. [' . $e->getMessage() . ']'); }
        $pdfUrl = pay_public_url('letter.php?t=' . pay_pdf_store($c['id'], $pdfName, $pdfBytes));
    }
    $pdo->prepare('UPDATE payment_demands SET whatsapp_sent_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $d['id']]);
    pay_respond($pdo, $c['id'], $edit, [['WhatsApp Sent', ($reminder ? 'Payment reminder' : 'Payment demand') . " #{$d['demand_no']} sent on WhatsApp — "
        . pay_inr(pay_still_due($data, $d)) . ' due by ' . pay_day($d['due_date']) . ($shared ? " · letter PDF attached: $pdfName" : " · letter PDF: $pdfName")]],
        ['pdf_url' => $pdfUrl, 'reminder' => $reminder]);
}

// ---------- Letter / Reminder opened to print (Demand Log) ----------
// The print page itself is a plain GET link; the click also posts here so it's logged once per click.
// Only the Accounts team / admin (who work the demand) are logged; anyone else just gets the page.
if ($action === 'letter_opened') {
    $dRow = load_open_demand($pdo, (int)($in['id'] ?? 0));
    [$c, $edit] = pay_access($pdo, (int)$dRow['client_id']);
    if (!$edit) { echo json_encode(['success' => true, 'skipped' => true]); exit; }
    $data = pay_data($pdo, $c['id']);
    $d = find_demand($data, $dRow['id']);
    $reminder = !empty($in['reminder']);
    pay_respond($pdo, $c['id'], $edit, [['Letter Printed', ($reminder ? 'Payment reminder' : 'Demand note') . " #{$d['demand_no']} opened to print / PDF — "
        . pay_inr(pay_still_due($data, $d)) . ' due by ' . pay_day($d['due_date'])]]);
}

pfail(400, 'Unknown action');
