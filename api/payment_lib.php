<?php
// api/payment_lib.php — Accounts desk: construction-linked payment schedule.
// Constants/functions only (no auth, no output) so clients.php can use the stage gate
// and index.php can hand the same template to app.js.
//
// How the money works (one lead = one schedule, all amounts on the Final Villa Price):
//  - Schedule rows = the "Percentage of total consideration" table of the Agreement for Sale.
//    Row 1 (booking 10%) is collected by the Legal desk; Accounts demands rows 2–9, each at the
//    construction checkpoint in 'stage' below.
//  - Every payment received is applied to the rows in order (oldest first). So a row can be Paid, Partially paid, Due / Overdue, Paid in
//    advance (money arrived before its demand) or Upcoming (stage of work not done yet).
//  - Carry forward: when a new demand is raised, whatever is still unpaid from the earlier
//    demands is added to it (and any advance is taken off it) — stored on the demand, so the
//    letter shows "Instalment + Unpaid from earlier = Total payable".
//  - The Final Villa Price is the final price: demands and payments are strictly on it — no GST or
//    TDS is added or tracked here (GST is collected manually; in the CRM it's only on stamp duty).
require_once __DIR__ . '/sv_followup_lib.php';

const PAY_TEMPLATE = [
    ['code' => 'booking',    'pct' => 10, 'stage' => 'Booking Initiated',            'short' => 'Booking',                      'label' => 'Booking amount (advance payment)'],
    ['code' => 'agreement',  'pct' => 20, 'stage' => 'Construction Customer',        'short' => 'Agreement',                    'label' => 'On execution of the Agreement for Sale'],
    ['code' => 'plinth',     'pct' => 15, 'stage' => 'Plinth Completed',             'short' => 'Plinth',                       'label' => 'On completion of the Plinth'],
    ['code' => 'slab1',      'pct' => 15, 'stage' => 'First Slab Completed',         'short' => 'First Slab',                   'label' => 'On completion of the First Slab'],
    ['code' => 'slab2',      'pct' => 10, 'stage' => 'Second Slab Completed',        'short' => 'Second Slab',                  'label' => 'On completion of the Second Slab'],
    ['code' => 'walls',      'pct' => 10, 'stage' => 'Brickwork Completed',          'short' => 'Brickwork',                    'label' => 'On completion of the walls (brickwork)'],
    ['code' => 'plaster',    'pct' => 10, 'stage' => 'Plaster & Flooring Completed', 'short' => 'Plaster, Flooring & Plumbing', 'label' => 'On completion of internal & external plaster, floorings & plumbing'],
    ['code' => 'fittings',   'pct' => 5,  'stage' => 'Fittings Completed',           'short' => 'Doors, Windows & Fittings',    'label' => 'On completion of doors & windows, electrical fittings and sanitary fittings'],
    ['code' => 'possession', 'pct' => 5,  'stage' => 'Possession Due',               'short' => 'Possession',                   'label' => 'At possession — on receipt of the Occupancy / Completion Certificate from PMRDA'],
];

// Moving forward past one of these needs its instalment demanded (or already paid in advance);
// past Construction Customer also needs the schedule itself. Handover needs full payment.
const PAY_GATED_STAGES = [
    'Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed',
    'Brickwork Completed', 'Plaster & Flooring Completed', 'Fittings Completed', 'Possession Due', 'Possession Offered',
];
const PAY_DEFAULT_DUE_DAYS = 15;
const PAY_MODES = ['NEFT / RTGS', 'UPI', 'Cheque', 'Demand Draft', 'Home Loan Disbursement', 'Cash'];
const PAY_ROUND = 0.5; // anything under 50 paise counts as settled

function pay_template_row($code) {
    foreach (PAY_TEMPLATE as $t) if ($t['code'] === $code) return $t;
    return null;
}

// Lead's villa price as a number (Final Villa Price, falling back to the old Final Sale Value).
function pay_price_of($c) {
    $t = preg_replace('/[₹,\s]|rs\.?|inr/u', '', mb_strtolower((string)($c['final_villa_price'] ?? '')));
    $n = 0;
    if (preg_match('/^(\d+(?:\.\d+)?)(cr|crore|crores|l|lac|lacs|lakh|lakhs|k)?$/', $t, $m)) {
        $mul = ['cr' => 1e7, 'crore' => 1e7, 'crores' => 1e7, 'l' => 1e5, 'lac' => 1e5, 'lacs' => 1e5, 'lakh' => 1e5, 'lakhs' => 1e5, 'k' => 1e3][$m[2] ?? ''] ?? 1;
        $n = round((float)$m[1] * $mul, 2);
    }
    return $n ?: (float)($c['salevalue'] ?? 0);
}

// Everything about a lead's payments, worked out: plan, schedule rows with their live status,
// demands with their live status, payments and the summary used by the tracker bar.
function pay_load($pdo, $clientId) {
    $out = ['plan' => null, 'schedule' => [], 'demands' => [], 'payments' => [], 'summary' => null];
    $st = $pdo->prepare('SELECT * FROM payment_plans WHERE client_id = ?');
    $st->execute([$clientId]);
    $plan = $st->fetch();
    if (!$plan) return $out;

    $st = $pdo->prepare('SELECT * FROM payment_schedule WHERE client_id = ? ORDER BY seq');
    $st->execute([$clientId]);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT d.*, u.name AS created_by_name FROM payment_demands d LEFT JOIN users u ON u.id = d.created_by
                         WHERE d.client_id = ? AND d.status = 'Open' ORDER BY d.demand_no, d.id");
    $st->execute([$clientId]);
    $demands = $st->fetchAll();
    $st = $pdo->prepare('SELECT p.*, u.name AS created_by_name, doc.original_name AS doc_name FROM client_payments p
                         LEFT JOIN users u ON u.id = p.created_by LEFT JOIN client_documents doc ON doc.id = p.doc_id
                         WHERE p.client_id = ? ORDER BY p.pay_date, p.id');
    $st->execute([$clientId]);
    $payments = $st->fetchAll();

    $today = date('Y-m-d');
    $base = (float)$plan['base_price'];
    $credited = 0; $lastPay = null;
    foreach ($payments as &$p) {
        $p['amount'] = (float)$p['amount'];
        $credited += $p['amount'];
        if (!$lastPay || $p['pay_date'] > $lastPay) $lastPay = $p['pay_date'];
    }
    unset($p);
    $credited = round($credited, 2);

    $demBySched = [];
    foreach ($demands as $d) $demBySched[(int)$d['schedule_id']] = $d;

    // Apply the money to the rows, oldest first.
    $remaining = $credited; $cum = 0; $demandedTotal = 0; $rowById = [];
    foreach ($rows as &$r) {
        $t = pay_template_row($r['code']) ?: ['stage' => '', 'short' => $r['label']];
        $r['seq'] = (int)$r['seq']; $r['pct'] = (float)$r['pct']; $r['amount'] = (float)$r['amount'];
        $r['stage'] = $t['stage']; $r['short'] = $t['short'];
        $dem = $demBySched[(int)$r['id']] ?? null;
        $r['demand_id'] = $dem ? (int)$dem['id'] : null;
        $r['demand_no'] = $dem ? (int)$dem['demand_no'] : null;
        $r['due_date'] = $dem ? $dem['due_date'] : null;
        $r['demanded'] = $r['seq'] === 1 || (bool)$dem; // booking was collected by Legal
        $alloc = min(max($remaining, 0), $r['amount']);
        $remaining -= $alloc;
        $r['received'] = round($alloc, 2);
        $r['balance'] = round($r['amount'] - $alloc, 2);
        $cum += $r['pct'];
        $r['cum_pct'] = round($cum, 3);
        if ($r['demanded']) $demandedTotal += $r['amount'];
        if ($r['balance'] <= PAY_ROUND) $r['status'] = $r['demanded'] ? 'Paid' : 'Paid in advance';
        elseif ($r['demanded']) {
            $r['status'] = $alloc > PAY_ROUND ? 'Partially paid' : 'Due';
            if ($r['due_date'] && $r['due_date'] < $today) $r['status'] = 'Overdue';
        } else $r['status'] = 'Upcoming';
        $r['carried_to'] = null;
        $rowById[(int)$r['id']] = &$r;
    }
    unset($r);
    $demandedTotal = round($demandedTotal, 2);

    // Demands: live status. cum_through = everything demanded up to and including this one.
    $n = count($demands); $nextDue = null; $overdueCum = 0; $overdueDays = 0;
    foreach ($demands as $i => &$d) {
        foreach (['instalment', 'carried_forward', 'advance_adjusted', 'total_due'] as $k) $d[$k] = (float)$d[$k];
        $d['demand_no'] = (int)$d['demand_no']; $d['email_count'] = (int)$d['email_count'];
        $row = $rowById[(int)$d['schedule_id']] ?? null;
        $d['code'] = $row['code'] ?? ''; $d['stage'] = $row['stage'] ?? ''; $d['short'] = $row['short'] ?? '';
        $d['label'] = $row['label'] ?? ''; $d['pct'] = $row['pct'] ?? 0; $d['seq'] = $row['seq'] ?? 0;
        $d['milestone_date'] = $row['milestone_date'] ?? null;
        $cumThrough = 0;
        foreach ($rows as $r2) if ($r2['seq'] <= $d['seq'] && ($r2['demanded'] || $r2['balance'] <= PAY_ROUND)) $cumThrough += $r2['amount'];
        $d['balance'] = max(0, round($cumThrough - $credited, 2));
        $d['overdue_days'] = 0;
        if ($d['balance'] <= PAY_ROUND) { $d['state'] = 'Paid'; $d['balance'] = 0; }
        elseif ($i < $n - 1) { $d['state'] = 'Carried forward'; $d['carried_to'] = $demands[$i + 1]['demand_no']; }
        else $d['state'] = $credited > $cumThrough - $d['instalment'] + PAY_ROUND ? 'Partially paid' : 'Due';
        if ($d['balance'] > 0) {
            if ($nextDue === null) $nextDue = $d['due_date'];
            if ($d['due_date'] < $today) {
                $overdueCum = $cumThrough;
                $days = (int)((strtotime($today) - strtotime($d['due_date'])) / 86400);
                if ($days > $overdueDays) $overdueDays = $days;
                if ($i === $n - 1) $d['overdue_days'] = $days;
            }
        }
        if ($row && $row['balance'] > PAY_ROUND && $i < $n - 1) $rowById[(int)$d['schedule_id']]['carried_to'] = $demands[$i + 1]['demand_no'];
    }
    unset($d);
    // Booking shortfall (collected by Legal) rides along into the first demand.
    if ($rows && $rows[0]['balance'] > PAY_ROUND && $demands) $rows[0]['carried_to'] = $demands[0]['demand_no'];

    $dueNow = max(0, round($demandedTotal - $credited, 2));
    $pct = fn($x) => $base > 0 ? round($x / $base * 100, 2) : 0;
    $recCapped = min($credited, $base);
    $nextRow = null; $lastDone = null;
    foreach ($rows as $r) {
        if ($r['seq'] >= 2 && !$r['demanded'] && !$nextRow) $nextRow = ['code' => $r['code'], 'short' => $r['short'], 'stage' => $r['stage'], 'pct' => $r['pct'], 'amount' => $r['amount']];
        if ($r['seq'] >= 2 && $r['demanded']) $lastDone = $r;
    }
    $out['summary'] = [
        'base_price' => $base,
        'credited' => $credited,
        'received_pct' => $pct($recCapped),
        'demanded' => $demandedTotal, 'demanded_pct' => $pct($demandedTotal),
        'due_now' => $dueNow, 'due_pct' => $pct($dueNow),
        'overdue' => max(0, round($overdueCum - $credited, 2)), 'overdue_days' => $overdueDays,
        'upcoming' => max(0, round($base - max($demandedTotal, $recCapped), 2)),
        'balance' => max(0, round($base - $credited, 2)),
        'advance' => max(0, round($credited - $demandedTotal, 2)),
        'fully_paid' => $credited >= $base - PAY_ROUND,
        'next_due_date' => $nextDue,
        'next_row' => $nextRow,
        'last_milestone' => $lastDone ? $lastDone['short'] : null,
        'last_payment_date' => $lastPay,
    ];
    $out['summary']['overdue_pct'] = $pct($out['summary']['overdue']);
    $out['summary']['upcoming_pct'] = $pct($out['summary']['upcoming']);
    $plan['base_price'] = (float)$plan['base_price'];
    $plan['due_days'] = (int)$plan['due_days'];
    $out['plan'] = $plan;
    $out['schedule'] = $rows;
    $out['demands'] = $demands;
    $out['payments'] = $payments;
    return $out;
}

// Keeps the lead's own payment columns (dashboards, tables, lead summary) in step with the ledger.
function pay_sync_client($pdo, $clientId, $data = null) {
    $data = $data ?: pay_load($pdo, $clientId);
    if (!$data['plan']) return;
    $s = $data['summary'];
    $construction = 'Not Started'; $milestone = null; $mdate = null;
    foreach ($data['schedule'] as $r) {
        if ($r['seq'] < 3 || !$r['demanded']) continue;
        $construction = $r['code'] === 'possession' ? 'Completed' : 'Under Construction';
        $milestone = $r['code'] === 'possession' ? 'Completed (OC / CC received)' : $r['short'];
        $mdate = $r['milestone_date'] ?: $mdate;
    }
    $pdo->prepare('UPDATE clients SET received = ?, pay_demanded = ?, dueamount = ?, duedate = ?, last_payment_date = ?,
                   payment_plan = ?, construction = ?, construction_milestone = ?, construction_update_date = COALESCE(?, construction_update_date)
                   WHERE id = ?')
        ->execute([$s['credited'], $s['demanded'], $s['due_now'], $s['next_due_date'], $s['last_payment_date'],
                   $data['plan']['plan_name'] ?: 'Construction Linked', $construction, $milestone, $mdate, $clientId]);
}

// What's still missing before a lead can move forward past $stage ([] = free to move on).
function pay_gate_missing($data, $stage) {
    if (!in_array($stage, PAY_GATED_STAGES, true)) return [];
    if (!$data['plan']) return ['payment schedule (create it in Construction Customer)'];
    if ($stage === 'Possession Offered') {
        $s = $data['summary'];
        return $s['fully_paid'] ? [] : ['full payment — ' . pay_inr($s['balance']) . ' (' . rtrim(rtrim(number_format(100 - $s['received_pct'], 2), '0'), '.') . '%) still to be received'];
    }
    $miss = [];
    foreach ($data['schedule'] as $r) {
        if ($r['seq'] >= 2 && $r['stage'] === $stage && !$r['demanded'] && $r['balance'] > PAY_ROUND) {
            $miss[] = "payment demand for {$r['short']} ({$r['pct']}%)";
        }
    }
    return $miss;
}

// Error string if this save would move the lead forward past a payment checkpoint that isn't done.
function pay_gate_error($pdo, $clientId, $oldStage, $newStage) {
    $newIdx = array_search($newStage, FOLLOWUP_PIPELINE, true);
    if ($newIdx === false) return null;
    $oldIdx = array_search($oldStage, FOLLOWUP_PIPELINE, true);
    if ($oldIdx === false) $oldIdx = -1;
    $data = null;
    foreach (PAY_GATED_STAGES as $g) {
        $gIdx = array_search($g, FOLLOWUP_PIPELINE, true);
        if (!($oldIdx <= $gIdx && $newIdx > $gIdx)) continue;
        if ($data === null) {
            try { $data = pay_load($pdo, $clientId); }
            catch (PDOException $e) { return 'Payment tables missing — run migration_v37.sql in phpMyAdmin first.'; }
        }
        $miss = pay_gate_missing($data, $g);
        if ($miss) return "Can't move past \"$g\" yet — still needed: " . implode(', ', $miss) . '.';
    }
    return null;
}

// ₹1,23,45,678 (Indian grouping).
function pay_inr($n, $symbol = true) {
    $n = round((float)$n, 2);
    $neg = $n < 0; $n = abs($n);
    $int = (string)(int)floor($n);
    $dec = (int)round(($n - floor($n)) * 100);
    if (strlen($int) > 3) {
        $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
    }
    return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $int . ($dec ? '.' . str_pad((string)$dec, 2, '0', STR_PAD_LEFT) : '');
}
function pay_words_int($n) {
    static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $n = (int)$n;
    $two = function ($x) use ($ones, $tens) { return $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]); };
    $parts = [];
    $crore = intdiv($n, 10000000); $n %= 10000000;
    $lakh = intdiv($n, 100000); $n %= 100000;
    $thou = intdiv($n, 1000); $n %= 1000;
    $hund = intdiv($n, 100); $n %= 100;
    if ($crore) $parts[] = pay_words_int($crore) . ' Crore';
    if ($lakh) $parts[] = $two($lakh) . ' Lakh';
    if ($thou) $parts[] = $two($thou) . ' Thousand';
    if ($hund) $parts[] = $ones[$hund] . ' Hundred';
    if ($n) $parts[] = $two($n);
    return implode(' ', $parts);
}
// "Rupees Thirty-Five Lakh Twenty-Five Thousand Only"
function pay_words($n) {
    $n = round((float)$n, 2);
    $r = (int)floor($n); $p = (int)round(($n - $r) * 100);
    return 'Rupees ' . (pay_words_int($r) ?: 'Zero') . ($p ? ' and ' . pay_words_int($p) . ' Paise' : '') . ' Only';
}
function pay_day($d) { return $d ? date('d M Y', strtotime($d)) : ''; }
function pay_pct_txt($p) { return rtrim(rtrim(number_format((float)$p, 3, '.', ''), '0'), '.') . '%'; }

// Appends Lead History entries straight to the lead (kept even if Edit Client is closed without
// saving — same as document uploads). Returns [entries, full log JSON].
function pay_history($pdo, $clientId, array $entries, $by) {
    $st = $pdo->prepare('SELECT activity_log FROM clients WHERE id = ?');
    $st->execute([$clientId]);
    $log = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($log)) $log = [];
    $out = [];
    foreach ($entries as $e) {
        $e = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'type' => $e[0], 'note' => $e[1], 'by' => $by];
        $log[] = $e; $out[] = $e;
    }
    $json = json_encode($log, JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE clients SET activity_log = ? WHERE id = ?')->execute([$json, $clientId]);
    return [$out, $json];
}

// Template handed to app.js (window.PAY_CONFIG).
function pay_js_config() {
    return [
        'template' => PAY_TEMPLATE, 'gated' => PAY_GATED_STAGES, 'defaultDueDays' => PAY_DEFAULT_DUE_DAYS, 'modes' => PAY_MODES,
        'bank' => defined('PAY_BANK') ? PAY_BANK : null,
    ];
}
