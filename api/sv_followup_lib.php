<?php
// api/sv_followup_lib.php — shared helpers for the three follow-up stages
// (used by clients.php and followups.php). Defines constants + functions only.

// One entry per follow-up stage:
//   prefix — column prefix ({prefix}_followup_date/_mode/_note, {prefix}_refollow, {prefix}_refollow_date)
//   type   — label stored on the `followups` row (shown in the Type column / Add Follow-up dropdown)
//   label  — wording used in Lead History
//   from   — the stage a lead is moved out of when a follow-up is logged from the Add Follow-up form
const FOLLOWUP_STAGES = [
    'Site Visit Follow-up'       => ['prefix' => 'sv',  'type' => 'After Scheduled Site Visit',   'label' => 'Site visit follow-up',       'from' => 'Site Visit Scheduled'],
    'Post Site Visit Follow-up'  => ['prefix' => 'psv', 'type' => 'Post Site Visit Follow-up',    'label' => 'Post site visit follow-up',  'from' => 'Site Visit Completed'],
    'Re-Visit Follow-up'         => ['prefix' => 'rv',  'type' => 'Re-Visit Follow-up',           'label' => 'Re-visit follow-up',         'from' => 'Re-Visit Scheduled'],
    'Negotiation Visit Follow-up' => ['prefix' => 'nv', 'type' => 'Negotiation Visit Follow-up',  'label' => 'Negotiation visit follow-up', 'from' => 'Negotiation Visit Scheduled'],
    'Villa Blocked Follow-up'    => ['prefix' => 'vb',  'type' => 'Villa Blocked Follow-up',      'label' => 'Villa blocked follow-up',    'from' => 'Unit Selection'],
];

// Pipeline order from the first visit stage onward (same order as STAGE_GROUPS in app.js).
const FOLLOWUP_PIPELINE = [
    'Site Visit Scheduled', 'Site Visit Follow-up', 'Site Visit Completed',
    'Post Site Visit Follow-up', 'Re-Visit Scheduled', 'Re-Visit Follow-up', 'Re-Visit Completed',
    'Negotiation Visit Scheduled', 'Negotiation Visit Follow-up', 'Unit Selection', 'Villa Blocked Follow-up', 'Unit Blocked',
    'Booking Initiated', 'KYC Verification', 'Booking Confirmed', 'Agreement In Process', 'Registered',
    'Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed', 'Brickwork Completed',
    'Plaster & Flooring Completed', 'Fittings Completed', 'Possession Due', 'Possession Offered', 'Handover Completed',
];

// Post Sales phase (Booking & Legal, Construction & Payment, Possession) — admin-only.
// Keep in step with POST_SALES_KEYS in app.js.
const POST_SALES_STAGES = [
    'Booking Initiated', 'KYC Verification', 'Booking Confirmed', 'Agreement In Process', 'Registered',
    'Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed', 'Brickwork Completed',
    'Plaster & Flooring Completed', 'Fittings Completed', 'Possession Due', 'Possession Offered', 'Handover Completed',
];

// A lead is "with Post Sales" once sales has transferred it (sales_handover_at) or it's
// already in a Post Sales stage. Legal / Accounts desks see and work only these leads.
function lead_with_post_sales($row) {
    return !empty($row['sales_handover_at']) || in_array($row['stage'] ?? '', POST_SALES_STAGES, true);
}

// Post Sales split (migration_v35): Legal desk works Booking & Legal, Accounts desk works
// Construction & Payment + Possession. Keep in step with LEGAL_KEYS / ACCOUNTS_KEYS in app.js.
const LEGAL_STAGES = ['Booking Initiated', 'KYC Verification', 'Booking Confirmed', 'Agreement In Process', 'Registered'];
// Construction & Payment checkpoints = the construction milestones of the payment schedule
// (migration_v37 — each one raises that stage's payment demand, see api/payment_lib.php).
const ACCOUNTS_STAGES = [
    'Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed', 'Brickwork Completed',
    'Plaster & Flooring Completed', 'Fittings Completed', 'Possession Due', 'Possession Offered', 'Handover Completed',
];
// Possession is handled by admin only. The Accounts desk still sees these leads (payments can
// still be due / the final 5% is its demand) but can't move them or edit possession details.
const POSSESSION_STAGES = ['Possession Due', 'Possession Offered', 'Handover Completed'];

// A lead is "with Accounts" once Legal has transferred it (accounts_handover_at, stage stays
// Registered) or it's already in a Construction / Payment / Possession stage.
function lead_with_accounts($row) {
    return !empty($row['accounts_handover_at']) || in_array($row['stage'] ?? '', ACCOUNTS_STAGES, true);
}

// True once a lead's current stage has reached (or passed) $target in the
// fixed pipeline order — e.g. stage_reached('Unit Blocked', 'Unit Selection').
// Used to gate one-time side effects (like logging a completed visit) on a
// stage rather than a separate manually-set status flag.
function stage_reached($stage, $target) {
    $i = array_search($stage, FOLLOWUP_PIPELINE, true);
    $t = array_search($target, FOLLOWUP_PIPELINE, true);
    return $i !== false && $t !== false && $i >= $t;
}

// Which follow-up stage a lead's follow-up belongs to: the latest one whose "from" stage the
// lead has reached (Site Visit Scheduled/Follow-up → Site Visit Follow-up, Site Visit Completed/
// Post Site Visit Follow-up → Post Site Visit Follow-up, Re-Visit Scheduled and later → Re-Visit
// Follow-up). Leads before Site Visit Scheduled default to Site Visit Follow-up.
function followup_stage_for($stage) {
    $found = 'Site Visit Follow-up';
    $idx = array_search($stage, FOLLOWUP_PIPELINE, true);
    if ($idx === false) return $found;
    foreach (FOLLOWUP_STAGES as $name => $cfg) {
        if ($idx >= array_search($cfg['from'], FOLLOWUP_PIPELINE, true)) $found = $name;
    }
    return $found;
}

// Keeps a lead's follow-up for one of the stages above reflected as ONE row
// in `followups` (typed, so the Follow-ups page/dashboard can label it).
// Date = when to follow up; "Re-follow-up required = Yes" moves the due date to
// the re-follow date and keeps it Pending. Interest is copied from the lead.
// An already-Completed row stays Completed unless its dates actually changed.
function sync_stage_followup($pdo, $fuStage, $clientId, $date, $mode, $note, $refollow, $reDate, $currentUserId, $salespersonId) {
    if (!$date || !isset(FOLLOWUP_STAGES[$fuStage])) return;
    $type = FOLLOWUP_STAGES[$fuStage]['type'];
    $re = ($refollow === 'Yes' && $reDate) ? $reDate : null;
    $status = ($re || $date >= date('Y-m-d')) ? 'Pending' : 'Completed';

    $i = $pdo->prepare('SELECT interest FROM clients WHERE id = ?');
    $i->execute([$clientId]);
    $interest = $i->fetchColumn();
    if (!in_array($interest, ['HOT', 'WARM', 'COLD'], true)) $interest = 'WARM';

    $stmt = $pdo->prepare('SELECT id, followup_date, next_date, status FROM followups WHERE client_id = ? AND followup_type = ? LIMIT 1');
    $stmt->execute([$clientId, $type]);
    $row = $stmt->fetch();

    if ($row) {
        $unchanged = $row['followup_date'] === $date && ($row['next_date'] ?: null) === $re;
        if ($unchanged && $row['status'] === 'Completed') $status = 'Completed';
        $pdo->prepare('UPDATE followups SET followup_date = ?, mode = ?, interest = ?, next_date = ?, discussion = ?, status = ? WHERE id = ?')
            ->execute([$date, $mode ?: null, $interest, $re, $note ?: null, $status, $row['id']]);
    } else {
        $pdo->prepare(
            'INSERT INTO followups (client_id, salesperson_id, followup_date, mode, followup_type, interest, next_date, status, discussion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$clientId, $salespersonId ?: $currentUserId, $date, $mode ?: null, $type, $interest, $re, $status, $note ?: null]);
    }
}

// Lead moved past a follow-up stage (or was Closed/Cancelled) → that stage's pending follow-up auto-completes.
function complete_stage_followups_if_past($pdo, $clientId, $stage) {
    $idx = array_search($stage, FOLLOWUP_PIPELINE, true);
    $terminal = in_array($stage, ['Closed', 'Cancelled'], true);
    foreach (FOLLOWUP_STAGES as $name => $cfg) {
        if (!$terminal && ($idx === false || $idx <= array_search($name, FOLLOWUP_PIPELINE, true))) continue;
        $pdo->prepare("UPDATE followups SET status = 'Completed' WHERE client_id = ? AND followup_type = ? AND status = 'Pending'")
            ->execute([$clientId, $cfg['type']]);
    }
}
