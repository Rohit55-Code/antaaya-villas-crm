<?php
// api/clients.php
// GET  -> list clients (sales role sees only their own, admin sees all,
//         Legal / Accounts desks see every lead transferred to Post Sales)
// POST -> create or update a client
require __DIR__ . '/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sv_followup_lib.php';
require_once __DIR__ . '/legal_docs_lib.php';
require_once __DIR__ . '/reassign_lib.php';
require_once __DIR__ . '/notif_log_lib.php';
require_once __DIR__ . '/payment_lib.php';

// Post Sales columns (booking, legal/KYC, payments, possession). Entry Desk never receives
// these; the Legal / Accounts desks may only change these (plus stage / history).
// "2,35,00,000" / "23500000" / "₹2.35 Cr" / "85 Lakh" → 23500000 etc. (0 if unreadable).
function inr_to_number($v) {
    $t = preg_replace('/[₹,\s]|rs\.?|inr/u', '', mb_strtolower((string)$v));
    if (!preg_match('/^(\d+(?:\.\d+)?)(cr|crore|crores|l|lac|lacs|lakh|lakhs|k)?$/', $t, $m)) return 0;
    $mul = ['cr' => 1e7, 'crore' => 1e7, 'crores' => 1e7, 'l' => 1e5, 'lac' => 1e5, 'lacs' => 1e5, 'lakh' => 1e5, 'lakhs' => 1e5, 'k' => 1e3][$m[2] ?? ''] ?? 1;
    return round((float)$m[1] * $mul, 2);
}

const POST_SALES_COLS = [
    'final_villa_price', 'salevalue', 'confirmation_date', 'bookingamount', 'bookingdate',
    'agreement_date', 'agreement_notes', 'registration', 'registration_date', 'received', 'duedate',
    'dueamount', 'loan', 'construction', 'fitout', 'expected_possession_date', 'inspection',
    'snagging', 'posletter', 'posdate', 'handover', 'keys_handed',
    'booking_payment_mode', 'booking_txn_ref', 'booking_form_status',
    'applicant_type', 'applicant_pan', 'applicant_aadhaar_last4', 'applicant_dob', 'applicant_occupation', 'applicant_address',
    'co_applicant_name', 'co_applicant_relation', 'co_applicant_pan', 'co_applicant_aadhaar_last4',
    'kyc_status', 'kyc_verified_date', 'kyc_note', 'allotment_letter', 'allotment_date',
    'draft_agreement_date', 'stamp_duty_amount', 'registration_fee', 'stamp_duty_paid',
    'registration_doc_no', 'sub_registrar_office', 'payment_plan', 'construction_milestone',
    'construction_update_date', 'last_payment_date', 'loan_bank', 'pay_demanded',
];
// Construction & Payment + Possession columns — the Accounts desk's side of Post Sales.
// Everything else in POST_SALES_COLS (booking, KYC, agreement, registration) is the Legal desk's.
const ACCOUNTS_COLS = [
    'received', 'duedate', 'dueamount', 'loan', 'construction', 'fitout', 'expected_possession_date',
    'inspection', 'snagging', 'posletter', 'posdate', 'handover', 'keys_handed', 'allotment_letter', 'allotment_date',
    'payment_plan', 'construction_milestone', 'construction_update_date', 'last_payment_date', 'loan_bank',
];
// Possession details — admin only (the Accounts desk sees them read-only).
const POSSESSION_COLS = ['expected_possession_date', 'inspection', 'snagging', 'posletter', 'posdate', 'handover', 'keys_handed', 'allotment_letter', 'allotment_date'];

// Keeps the "Next Follow-up" date on a lead reflected as a real row in the
// followups table, so it actually shows up on the Follow-ups tab and the
// dashboard's upcoming-followups panel — not just sitting as a date field
// on the client record where nothing else ever sees it.
const AUTO_SYNC_TAG = '(Auto-synced from lead\'s Next Follow-up field)';
function sync_next_followup($pdo, $clientId, $nextDate, $currentUserId, $salespersonId) {
    if (!$nextDate) return; // date cleared — leave existing followup rows alone

    $stmt = $pdo->prepare(
        "SELECT id FROM followups WHERE client_id = ? AND status = 'Pending' AND discussion = ? LIMIT 1"
    );
    $stmt->execute([$clientId, AUTO_SYNC_TAG]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE followups SET next_date = ? WHERE id = ?')->execute([$nextDate, $existingId]);
    } else {
        $pdo->prepare(
            'INSERT INTO followups (client_id, salesperson_id, followup_date, mode, interest, next_date, status, discussion)
             VALUES (?, ?, CURDATE(), "Call", "WARM", ?, "Pending", ?)'
        )->execute([$clientId, $salespersonId ?: $currentUserId, $nextDate, AUTO_SYNC_TAG]);
    }
}

// Mirrors sync_next_followup: when the lead's own Site Visit tab is marked
// "Done" with a date, keep that reflected as a real row in the `visits`
// table too, so it actually shows up on the Site Visits tab/log — not just
// sitting as a status field on the client record where nothing else sees it.
// Matched on client_id + visit_date (one synced row per lead per visit day);
// a manually-logged visit for that same lead/date is updated in place rather
// than duplicated.
function sync_site_visit($pdo, $clientId, $status, $date, $time, $villa, $visitors, $outcome, $feedback) {
    if ($status !== 'Done' || !$date) return;

    $stmt = $pdo->prepare("SELECT id FROM visits WHERE client_id = ? AND visit_date = ? AND kind = 'site' LIMIT 1");
    $stmt->execute([$clientId, $date]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE visits SET visit_time = ?, villa = ?, visitors = ?, status = "Completed", outcome = ?, feedback = ? WHERE id = ?')
            ->execute([$time, $villa, $visitors ?: 1, $outcome, $feedback, $existingId]);
    } else {
        $pdo->prepare(
            'INSERT INTO visits (client_id, kind, visit_date, visit_time, villa, visitors, status, outcome, feedback)
             VALUES (?, "site", ?, ?, ?, ?, "Completed", ?, ?)'
        )->execute([$clientId, $date, $time, $villa, $visitors ?: 1, $outcome, $feedback]);
    }
}

// Mirrors sync_site_visit for the Re-Visit Completed checkpoint (revisit_*
// columns) — same client_id+visit_date+kind matching, its own `kind` so the
// Site Visits page can tell the two apart (see migration_v28.sql).
function sync_revisit_visit($pdo, $clientId, $status, $date, $time, $villa, $visitors, $outcome, $feedback) {
    if ($status !== 'Done' || !$date) return;

    $stmt = $pdo->prepare("SELECT id FROM visits WHERE client_id = ? AND visit_date = ? AND kind = 'revisit' LIMIT 1");
    $stmt->execute([$clientId, $date]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE visits SET visit_time = ?, villa = ?, visitors = ?, status = "Completed", outcome = ?, feedback = ? WHERE id = ?')
            ->execute([$time, $villa, $visitors ?: 1, $outcome, $feedback, $existingId]);
    } else {
        $pdo->prepare(
            'INSERT INTO visits (client_id, kind, visit_date, visit_time, villa, visitors, status, outcome, feedback)
             VALUES (?, "revisit", ?, ?, ?, ?, "Completed", ?, ?)'
        )->execute([$clientId, $date, $time, $villa, $visitors ?: 1, $outcome, $feedback]);
    }
}

// Negotiation Visit has no separate "Completed" checkpoint of its own — the
// visit only really wraps up once the client has settled on a villa, so the
// caller only marks this "Done" once the lead has reached Unit Selection (or
// beyond), not merely Negotiation Visit Follow-up. Reuses the final selected
// villa and the Unit Selection discussion note as this visit's record.
function sync_negotiation_visit($pdo, $clientId, $status, $date, $time, $villa, $note) {
    if ($status !== 'Done' || !$date) return;

    $stmt = $pdo->prepare("SELECT id FROM visits WHERE client_id = ? AND visit_date = ? AND kind = 'negotiation' LIMIT 1");
    $stmt->execute([$clientId, $date]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE visits SET visit_time = ?, villa = ?, status = "Completed", feedback = ? WHERE id = ?')
            ->execute([$time, $villa, $note, $existingId]);
    } else {
        $pdo->prepare(
            'INSERT INTO visits (client_id, kind, visit_date, visit_time, villa, visitors, status, feedback)
             VALUES (?, "negotiation", ?, ?, ?, 1, "Completed", ?)'
        )->execute([$clientId, $date, $time, $villa, $note]);
    }
}

// Villa Inventory status a lead's stage implies for its final villa:
//   Unit Selection / Villa Blocked Follow-up -> negotiation, Unit Blocked / Booking Initiated / KYC Verification -> blocked,
//   Booking Confirmed / Agreement In Process -> booked, Registered and later -> sold (the buyer
//   is final once registration is done). Anything else (earlier stages, Cancelled,
//   Closed) -> null, meaning the lead should not be holding any villa.
function villa_target_status($stage) {
    if (in_array($stage, ['Unit Selection', 'Villa Blocked Follow-up'], true)) return 'negotiation';
    if (in_array($stage, ['Unit Blocked', 'Booking Initiated', 'KYC Verification'], true)) return 'blocked';
    if (in_array($stage, ['Booking Confirmed', 'Agreement In Process'], true)) return 'booked';
    if ($stage === 'Registered' || in_array($stage, ACCOUNTS_STAGES, true)) return 'sold';
    return null;
}

// The Villa field holds "NAME - SERIAL" (from the picker), but plain name or
// serial (older saved values) still resolve.
function find_villa($pdo, $villaInput) {
    $villaInput = trim((string)$villaInput);
    if ($villaInput === '') return null;
    $stmt = $pdo->prepare("SELECT id, name, serial, status, linked_client_id FROM villas WHERE name = ? OR serial = ? OR CONCAT(name, ' - ', serial) = ? LIMIT 1");
    $stmt->execute([$villaInput, $villaInput, $villaInput]);
    return $stmt->fetch() ?: null;
}

// The Villa Selected (Final) field can hold several villas (a client buying
// two or more), stored comma-separated: "ALARA 1 - A1-01, ALARA 2 - A1-02".
// A villa's serial can itself contain a comma (EMIRA combos: "A4-1,2"), so two
// neighbouring pieces are joined back together whenever the joined text is a known
// villa ("EMIRA 1 - A4-1" + "2" -> "EMIRA 1 - A4-1,2"). Same rule as splitVillaText() in app.js.
function villa_list($pdo, $villaInput) {
    $parts = array_map('trim', explode(',', (string)$villaInput));
    $out = [];
    for ($i = 0, $n = count($parts); $i < $n; $i++) {
        $v = $parts[$i];
        if ($i + 1 < $n && $parts[$i + 1] !== '' && find_villa($pdo, $v . ',' . $parts[$i + 1])) { $v .= ',' . $parts[$i + 1]; $i++; }
        if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
    }
    return $out;
}

// Keeps Villa Inventory in step with the lead's stage (see villa_target_status).
// A villa this lead previously held is released (back to available) when the
// lead moves off it, drops it from its final selection, or drops below Unit
// Selection / is Cancelled / Closed. Never touches a villa held by a different lead.
function resolve_and_sync_villa($pdo, $clientId, $villaInput, $stage) {
    $target = villa_target_status($stage);
    $villas = [];
    foreach (villa_list($pdo, $villaInput) as $v) { if ($row = find_villa($pdo, $v)) $villas[(int)$row['id']] = $row; }

    $held = $pdo->prepare("SELECT id FROM villas WHERE linked_client_id = ? AND status IN ('negotiation','blocked','booked','sold')");
    $held->execute([$clientId]);
    foreach ($held->fetchAll() as $h) {
        if (!$target || !isset($villas[(int)$h['id']])) {
            $pdo->prepare('UPDATE villas SET status = "available", linked_client_id = NULL WHERE id = ?')->execute([$h['id']]);
        }
    }
    if (!$target) return; // no villa-holding stage — nothing to set

    foreach ($villas as $villa) {
        $mine = (int)$villa['linked_client_id'] === (int)$clientId;
        $free = $villa['linked_client_id'] === null && $villa['status'] === 'available';
        if ($target === 'booked' || $target === 'sold') $set = true; // conflicts were already checked (or admin-overridden) in villa_conflict()
        elseif ($target === 'blocked') $set = $mine || $free || $villa['status'] === 'negotiation';
        else $set = $mine || $free; // negotiation: don't take over another lead's hold
        if ($set) {
            $pdo->prepare('UPDATE villas SET status = ?, linked_client_id = ? WHERE id = ?')->execute([$target, $clientId, $villa['id']]);
        }
    }
}

// Checked BEFORE the client row is saved, so a conflicting hold never gets
// written at all — resolve_and_sync_villa() (above) runs only after this has
// already given the green light. Every villa in the final selection is checked.
//
//  1. Villa already booked/sold and linked to a DIFFERENT lead — blocked for
//     everyone; admin may pass override_villa_conflict:true to force it.
//  2. Villa booked/sold with NO linked lead (sold before this CRM) — blocked for
//     regular salespeople, admins may attach a backfilled lead to it.
//  3. Moving to a blocked stage on a villa another lead has already blocked.
// (Negotiation on a villa another lead is negotiating is allowed — it just
// doesn't take over the inventory link.)
function villa_conflict($pdo, $villaInput, $clientId, $stage, $override) {
    if (!villa_target_status($stage)) return null; // not holding a villa at this stage
    foreach (villa_list($pdo, $villaInput) as $v) {
        $err = single_villa_conflict($pdo, find_villa($pdo, $v), $clientId, $stage, $override);
        if ($err) return $err;
    }
    return null;
}
function single_villa_conflict($pdo, $villa, $clientId, $stage, $override) {
    if (!$villa) return null; // typed text doesn't match a known villa — nothing to check

    $stmt = $pdo->prepare('SELECT c.name AS client_name, c.lead_code FROM clients c WHERE c.id = ?');
    $stmt->execute([$villa['linked_client_id']]);
    $other = $stmt->fetch();
    $who = $other ? "{$other['client_name']} ({$other['lead_code']})" : 'another lead';

    $taken = in_array($villa['status'], ['booked', 'sold'], true);
    $heldByOther = $villa['linked_client_id'] !== null && (int)$villa['linked_client_id'] !== (int)$clientId;

    if ($taken) {
        if ($heldByOther) {
            if (is_admin() && $override) return null;
            return "{$villa['name']} ({$villa['serial']}) is already {$villa['status']} — linked to $who. Cannot assign to this lead.";
        }
        if ($villa['linked_client_id'] === null) {
            if (is_admin()) return null; // admin backfilling a pre-CRM sale onto a lead
            return "{$villa['name']} ({$villa['serial']}) is already marked {$villa['status']} in the inventory (no CRM lead on record). Ask an admin to link it.";
        }
        return null;
    }
    if (villa_target_status($stage) === 'blocked' && $villa['status'] === 'blocked' && $heldByOther) {
        return "{$villa['name']} ({$villa['serial']}) is already blocked by $who.";
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (is_admin()) {
        $stmt = $pdo->query(
            'SELECT c.*, u.name AS salesperson_name, cb.name AS created_by_name
             FROM clients c
             JOIN users u ON u.id = c.salesperson_id
             LEFT JOIN users cb ON cb.id = c.created_by
             ORDER BY c.updated_at DESC, c.id DESC'
        );
    } elseif (is_accounts_desk()) {
        // Accounts desk: only leads the Legal desk has transferred (or already in Construction onward).
        $ph = implode(',', array_fill(0, count(ACCOUNTS_STAGES), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.*, u.name AS salesperson_name, cb.name AS created_by_name
             FROM clients c
             JOIN users u ON u.id = c.salesperson_id
             LEFT JOIN users cb ON cb.id = c.created_by
             WHERE c.accounts_handover_at IS NOT NULL OR c.stage IN ($ph)
             ORDER BY c.updated_at DESC, c.id DESC"
        );
        $stmt->execute(ACCOUNTS_STAGES);
    } elseif (is_post_sales()) {
        // Legal desk: every lead transferred to Post Sales (any salesperson) — leads already with
        // Accounts stay listed read-only so Legal can follow their progress.
        $ph = implode(',', array_fill(0, count(POST_SALES_STAGES), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.*, u.name AS salesperson_name, cb.name AS created_by_name
             FROM clients c
             JOIN users u ON u.id = c.salesperson_id
             LEFT JOIN users cb ON cb.id = c.created_by
             WHERE c.sales_handover_at IS NOT NULL OR c.stage IN ($ph)
             ORDER BY c.updated_at DESC, c.id DESC"
        );
        $stmt->execute(POST_SALES_STAGES);
    } else {
        $stmt = $pdo->prepare(
            'SELECT c.*, u.name AS salesperson_name, cb.name AS created_by_name
             FROM clients c
             JOIN users u ON u.id = c.salesperson_id
             LEFT JOIN users cb ON cb.id = c.created_by
             WHERE c.salesperson_id = ? OR c.created_by = ?
             ORDER BY c.updated_at DESC, c.id DESC'
        );
        $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID]);
    }

    $rows = $stmt->fetchAll();
    // Entry Desk never receives Post Sales data (booking, legal/KYC, payments, possession).
    if (!is_admin() && $CURRENT_USER_DESK === 'entry') {
        $postSalesCols = POST_SALES_COLS;
        foreach ($rows as &$r) {
            foreach ($postSalesCols as $col) unset($r[$col]);
        }
        unset($r);
    }

    echo json_encode($rows);
    exit;
}

// Column => the raw submitted field name it's populated from, for every
// column that lives inside a lockable Edit Client stage-section (so its
// <input>/<select> is disabled — and entirely absent from the submission —
// whenever that checkpoint isn't reached yet or was locked again by a
// backward stage correction). Used on UPDATE only: a column is left out of
// the SQL entirely (preserving whatever's already stored) unless its own
// source key was actually present in this particular request. Deliberately
// excludes the handful of canonical fields that live outside any
// stage-section (name/mobile/salesperson_id/stage/interest/visit_status/
// agreement/registration/activity_log/category) — those are never
// disabled, always submitted, and should always apply as before.
const CLIENT_FIELD_SOURCE_KEYS = [
    'alt_mobile' => 'alt', 'whatsapp' => 'whatsapp', 'email' => 'email', 'city' => 'city',
    'source' => 'source', 'subsource' => 'subsource', 'broker_name' => 'broker_name',
    'broker_contact' => 'broker_contact', 'broker_email' => 'broker_email',
    'created_date' => 'created', 'purpose' => 'purpose', 'config' => 'config', 'budget' => 'budget',
    'budget_confirmed' => 'budget_confirmed', 'decision_maker' => 'decision_maker', 'finance' => 'finance',
    'req_whatsapp_message' => 'req_whatsapp_message', 'req_whatsapp_sent' => 'req_whatsapp_sent',
    'firstcall_date' => 'firstcall', 'call_attempt_result' => 'call_attempt_result',
    'attempt_time' => 'attempt_time', 'lastcontact_date' => 'lastcontact', 'nextfollow_date' => 'nextfollow',
    'followup_mode' => 'fumode', 'notes' => 'notes', 'nextaction' => 'nextaction',
    'brochure_date' => 'brochure_date', 'brochure_mode' => 'brochure_mode',
    'brochure_whatsapp_message' => 'brochure_whatsapp_message', 'brochure_whatsapp_sent' => 'brochure_whatsapp_sent',
    'brochure_bypass' => 'brochure_bypass',
    'visit_date' => 'visit_date', 'visit_time' => 'visit_time', 'visit_villa' => 'visit_villa',
    'visit_pickup' => 'visit_pickup', 'visit_whatsapp_sent' => 'visit_whatsapp_sent', 'visit_visitors' => 'visit_visitors', 'visit_outcome' => 'visit_outcome',
    'revisit_date' => 'revisit_date', 'revisit_time' => 'revisit_time', 'revisit_outcome' => 'revisit_outcome',
    'revisit_villa' => 'revisit_villa', 'revisit_visitors' => 'revisit_visitors', 'revisit_feedback' => 'revisit_feedback',
    'revisit_whatsapp_sent' => 'revisit_whatsapp_sent', 'negotiation_visit_date' => 'negotiation_visit_date',
    'negotiation_visit_time' => 'negotiation_visit_time', 'negotiation_whatsapp_sent' => 'negotiation_whatsapp_sent',
    'sv_followup_date' => 'sv_followup_date', 'sv_followup_mode' => 'sv_followup_mode', 'sv_followup_note' => 'sv_followup_note',
    'sv_refollow' => 'sv_refollow', 'sv_refollow_date' => 'sv_refollow_date',
    'psv_followup_date' => 'psv_followup_date', 'psv_followup_mode' => 'psv_followup_mode', 'psv_followup_note' => 'psv_followup_note',
    'psv_refollow' => 'psv_refollow', 'psv_refollow_date' => 'psv_refollow_date',
    'rv_followup_date' => 'rv_followup_date', 'rv_followup_mode' => 'rv_followup_mode', 'rv_followup_note' => 'rv_followup_note',
    'rv_refollow' => 'rv_refollow', 'rv_refollow_date' => 'rv_refollow_date',
    'nv_followup_date' => 'nv_followup_date', 'nv_followup_mode' => 'nv_followup_mode', 'nv_followup_note' => 'nv_followup_note',
    'nv_refollow' => 'nv_refollow', 'nv_refollow_date' => 'nv_refollow_date',
    'vb_followup_date' => 'vb_followup_date', 'vb_followup_mode' => 'vb_followup_mode', 'vb_followup_note' => 'vb_followup_note',
    'vb_refollow' => 'vb_refollow', 'vb_refollow_date' => 'vb_refollow_date', 'villa_offers' => 'villa_offers',
    'visit_feedback' => 'visit_feedback', 'offered_price' => 'offered_price', 'negotiation_notes' => 'negotiation_notes',
    'villa' => 'villa', 'villa_shortlist' => 'villa_shortlist', 'villa_selected' => 'villa_selected', 'block_date' => 'block_date',
    'token_amount' => 'token_amount', 'final_villa_price' => 'final_villa_price', 'block_note' => 'block_note', 'salevalue' => 'salevalue', 'confirmation_date' => 'confirmation_date',
    'bookingamount' => 'bookingamount', 'bookingdate' => 'bookingdate',
    'agreement_date' => 'agreement_date', 'agreement_notes' => 'agreement_notes',
    'registration' => 'registration', 'registration_date' => 'registration_date', 'received' => 'received', 'duedate' => 'duedate',
    'dueamount' => 'dueamount', 'loan' => 'loan', 'construction' => 'construction', 'fitout' => 'fitout',
    'expected_possession_date' => 'expected_possession_date', 'inspection' => 'inspection',
    'snagging' => 'snagging', 'posletter' => 'posletter', 'posdate' => 'posdate', 'handover' => 'handover',
    'keys_handed' => 'keys', 'cancelled_reason' => 'cancelled_reason',
    // Booking & Legal / Construction & Payment (migration_v31)
    'booking_payment_mode' => 'booking_payment_mode', 'booking_txn_ref' => 'booking_txn_ref', 'booking_form_status' => 'booking_form_status',
    'applicant_type' => 'applicant_type', 'applicant_pan' => 'applicant_pan', 'applicant_aadhaar_last4' => 'applicant_aadhaar_last4',
    'applicant_dob' => 'applicant_dob', 'applicant_occupation' => 'applicant_occupation', 'applicant_address' => 'applicant_address',
    'co_applicant_name' => 'co_applicant_name', 'co_applicant_relation' => 'co_applicant_relation',
    'co_applicant_pan' => 'co_applicant_pan', 'co_applicant_aadhaar_last4' => 'co_applicant_aadhaar_last4',
    'kyc_status' => 'kyc_status', 'kyc_verified_date' => 'kyc_verified_date', 'kyc_note' => 'kyc_note',
    'allotment_letter' => 'allotment_letter', 'allotment_date' => 'allotment_date',
    'draft_agreement_date' => 'draft_agreement_date', 'stamp_duty_amount' => 'stamp_duty_amount',
    'registration_fee' => 'registration_fee', 'stamp_duty_paid' => 'stamp_duty_paid',
    'registration_doc_no' => 'registration_doc_no', 'sub_registrar_office' => 'sub_registrar_office',
    'payment_plan' => 'payment_plan', 'construction_milestone' => 'construction_milestone',
    'construction_update_date' => 'construction_update_date', 'last_payment_date' => 'last_payment_date', 'loan_bank' => 'loan_bank',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);

    if (empty($d['name']) || empty($d['mobile'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Name and mobile are required']);
        exit;
    }

    $id = $d['id'] ?? null;

    // Legal / Accounts desks only work leads already transferred by sales — no new leads.
    if (!$id && is_post_sales()) {
        http_response_code(403);
        echo json_encode(['error' => 'The Post Sales team cannot add new leads.']);
        exit;
    }

    // Non-admins can only assign leads to themselves; admins may reassign.
    $salesperson_id = is_admin() && !empty($d['salesperson_id'])
        ? (int)$d['salesperson_id']
        : $CURRENT_USER_ID;

    $fields = [
        'name' => $d['name'], 'mobile' => $d['mobile'], 'alt_mobile' => $d['alt'] ?? null,
        'whatsapp' => $d['whatsapp'] ?? null, 'email' => $d['email'] ?? null, 'city' => $d['city'] ?? null,
        'source' => $d['source'] ?? null, 'subsource' => $d['subsource'] ?? null,
        'broker_name' => $d['broker_name'] ?? null, 'broker_contact' => $d['broker_contact'] ?? null,
        'broker_email' => $d['broker_email'] ?? null,
        'salesperson_id' => $salesperson_id, 'created_date' => ($d['created'] ?? null) ?: null,
        'purpose' => $d['purpose'] ?? null, 'config' => $d['config'] ?? null, 'budget' => $d['budget'] ?? null,
        'budget_confirmed' => $d['budget_confirmed'] ?? null, 'decision_maker' => $d['decision_maker'] ?? null,
        'finance' => $d['finance'] ?? null,
        // ENUM column: a blank / unknown value would fail the save on a strict-mode MySQL server.
        'interest' => in_array(strtoupper((string)($d['interest'] ?? '')), ['HOT', 'WARM', 'COLD'], true) ? strtoupper($d['interest']) : 'WARM',
        'req_whatsapp_message' => $d['req_whatsapp_message'] ?? null, 'req_whatsapp_sent' => $d['req_whatsapp_sent'] ?? null,
        'stage' => $d['stage'] ?? 'New Lead', 'firstcall_date' => ($d['firstcall'] ?? null) ?: null,
        'call_attempt_result' => $d['call_attempt_result'] ?? null, 'attempt_time' => ($d['attempt_time'] ?? null) ?: null,
        'lastcontact_date' => ($d['lastcontact'] ?? null) ?: null, 'nextfollow_date' => ($d['nextfollow'] ?? null) ?: null,
        'followup_mode' => $d['fumode'] ?? null, 'notes' => $d['notes'] ?? null,
        'activity_log' => $d['activity_log'] ?? null, 'nextaction' => $d['nextaction'] ?? null,
        'brochure_date' => ($d['brochure_date'] ?? null) ?: null, 'brochure_mode' => $d['brochure_mode'] ?? null,
        'brochure_whatsapp_message' => $d['brochure_whatsapp_message'] ?? null, 'brochure_whatsapp_sent' => $d['brochure_whatsapp_sent'] ?? null,
        'brochure_bypass' => $d['brochure_bypass'] ?? null,
        'visit_status' => $d['visit_status'] ?? 'Not Scheduled', 'visit_date' => ($d['visit_date'] ?? null) ?: null,
        'visit_time' => ($d['visit_time'] ?? null) ?: null, 'visit_villa' => $d['visit_villa'] ?? null,
        'visit_pickup' => $d['visit_pickup'] ?? null, 'visit_whatsapp_sent' => $d['visit_whatsapp_sent'] ?? null,
        'visit_visitors' => ($d['visit_visitors'] ?? 1) ?: 1,
        'visit_outcome' => $d['visit_outcome'] ?? null, 'revisit_date' => ($d['revisit_date'] ?? null) ?: null,
        'revisit_time' => ($d['revisit_time'] ?? null) ?: null, 'revisit_outcome' => $d['revisit_outcome'] ?? null,
        'revisit_villa' => $d['revisit_villa'] ?? null, 'revisit_visitors' => ($d['revisit_visitors'] ?? 1) ?: 1,
        'revisit_feedback' => $d['revisit_feedback'] ?? null,
        'revisit_whatsapp_sent' => $d['revisit_whatsapp_sent'] ?? null,
        'negotiation_visit_date' => ($d['negotiation_visit_date'] ?? null) ?: null, 'negotiation_visit_time' => ($d['negotiation_visit_time'] ?? null) ?: null,
        'negotiation_whatsapp_sent' => $d['negotiation_whatsapp_sent'] ?? null,
        'sv_followup_date' => ($d['sv_followup_date'] ?? null) ?: null, 'sv_followup_mode' => $d['sv_followup_mode'] ?? null,
        'sv_followup_note' => $d['sv_followup_note'] ?? null, 'sv_refollow' => $d['sv_refollow'] ?? null,
        'sv_refollow_date' => ($d['sv_refollow_date'] ?? null) ?: null,
        'psv_followup_date' => ($d['psv_followup_date'] ?? null) ?: null, 'psv_followup_mode' => $d['psv_followup_mode'] ?? null,
        'psv_followup_note' => $d['psv_followup_note'] ?? null, 'psv_refollow' => $d['psv_refollow'] ?? null,
        'psv_refollow_date' => ($d['psv_refollow_date'] ?? null) ?: null,
        'rv_followup_date' => ($d['rv_followup_date'] ?? null) ?: null, 'rv_followup_mode' => $d['rv_followup_mode'] ?? null,
        'rv_followup_note' => $d['rv_followup_note'] ?? null, 'rv_refollow' => $d['rv_refollow'] ?? null,
        'rv_refollow_date' => ($d['rv_refollow_date'] ?? null) ?: null,
        'nv_followup_date' => ($d['nv_followup_date'] ?? null) ?: null, 'nv_followup_mode' => $d['nv_followup_mode'] ?? null,
        'nv_followup_note' => $d['nv_followup_note'] ?? null, 'nv_refollow' => $d['nv_refollow'] ?? null,
        'nv_refollow_date' => ($d['nv_refollow_date'] ?? null) ?: null,
        'vb_followup_date' => ($d['vb_followup_date'] ?? null) ?: null, 'vb_followup_mode' => $d['vb_followup_mode'] ?? null,
        'vb_followup_note' => $d['vb_followup_note'] ?? null, 'vb_refollow' => $d['vb_refollow'] ?? null,
        'vb_refollow_date' => ($d['vb_refollow_date'] ?? null) ?: null,
        'villa_offers' => $d['villa_offers'] ?? null,
        'visit_feedback' => $d['visit_feedback'] ?? null,
        'offered_price' => $d['offered_price'] ?? null, 'negotiation_notes' => $d['negotiation_notes'] ?? null,
        'villa' => $d['villa'] ?? null, 'villa_shortlist' => $d['villa_shortlist'] ?? null, 'villa_selected' => $d['villa_selected'] ?? null,
        'block_date' => ($d['block_date'] ?? null) ?: null, 'token_amount' => ($d['token_amount'] ?? 0) ?: 0,
        'final_villa_price' => $d['final_villa_price'] ?? null, 'block_note' => $d['block_note'] ?? null,
        'salevalue' => ($d['salevalue'] ?? 0) ?: 0, 'confirmation_date' => ($d['confirmation_date'] ?? null) ?: null,
        'bookingamount' => ($d['bookingamount'] ?? 0) ?: 0,
        'bookingdate' => ($d['bookingdate'] ?? null) ?: null, 'agreement' => $d['agreement'] ?? 'Pending',
        'agreement_date' => ($d['agreement_date'] ?? null) ?: null, 'agreement_notes' => $d['agreement_notes'] ?? null,
        'registration' => $d['registration'] ?? 'Pending', 'registration_date' => ($d['registration_date'] ?? null) ?: null,
        'received' => ($d['received'] ?? 0) ?: 0,
        'duedate' => ($d['duedate'] ?? null) ?: null, 'dueamount' => ($d['dueamount'] ?? 0) ?: 0, 'loan' => $d['loan'] ?? 'N/A',
        'construction' => $d['construction'] ?? 'Not Started', 'fitout' => $d['fitout'] ?? 'Pending',
        'expected_possession_date' => ($d['expected_possession_date'] ?? null) ?: null,
        'inspection' => $d['inspection'] ?? 'Pending', 'snagging' => $d['snagging'] ?? 'Pending',
        'posletter' => $d['posletter'] ?? 'Pending', 'posdate' => ($d['posdate'] ?? null) ?: null,
        'handover' => ($d['handover'] ?? null) ?: null, 'keys_handed' => $d['keys'] ?? 'No',
        'cancelled_reason' => $d['cancelled_reason'] ?? null,
        // Booking & Legal / Construction & Payment (migration_v31)
        'booking_payment_mode' => $d['booking_payment_mode'] ?? null, 'booking_txn_ref' => $d['booking_txn_ref'] ?? null,
        'booking_form_status' => $d['booking_form_status'] ?? null,
        'applicant_type' => $d['applicant_type'] ?? null,
        'applicant_pan' => strtoupper(trim($d['applicant_pan'] ?? '')) ?: null,
        'applicant_aadhaar_last4' => preg_replace('/\D/', '', $d['applicant_aadhaar_last4'] ?? '') ?: null,
        'applicant_dob' => ($d['applicant_dob'] ?? null) ?: null, 'applicant_occupation' => $d['applicant_occupation'] ?? null,
        'applicant_address' => $d['applicant_address'] ?? null,
        'co_applicant_name' => trim($d['co_applicant_name'] ?? '') ?: null, 'co_applicant_relation' => $d['co_applicant_relation'] ?? null,
        'co_applicant_pan' => strtoupper(trim($d['co_applicant_pan'] ?? '')) ?: null,
        'co_applicant_aadhaar_last4' => preg_replace('/\D/', '', $d['co_applicant_aadhaar_last4'] ?? '') ?: null,
        'kyc_status' => $d['kyc_status'] ?? null, 'kyc_verified_date' => ($d['kyc_verified_date'] ?? null) ?: null,
        'kyc_note' => $d['kyc_note'] ?? null,
        'allotment_letter' => $d['allotment_letter'] ?? null, 'allotment_date' => ($d['allotment_date'] ?? null) ?: null,
        'draft_agreement_date' => ($d['draft_agreement_date'] ?? null) ?: null,
        'stamp_duty_amount' => ($d['stamp_duty_amount'] ?? null) ?: null, 'registration_fee' => ($d['registration_fee'] ?? null) ?: null,
        'stamp_duty_paid' => $d['stamp_duty_paid'] ?? null,
        'registration_doc_no' => $d['registration_doc_no'] ?? null, 'sub_registrar_office' => $d['sub_registrar_office'] ?? null,
        'payment_plan' => $d['payment_plan'] ?? null, 'construction_milestone' => $d['construction_milestone'] ?? null,
        'construction_update_date' => ($d['construction_update_date'] ?? null) ?: null,
        'last_payment_date' => ($d['last_payment_date'] ?? null) ?: null, 'loan_bank' => $d['loan_bank'] ?? null,
    ];

    // Final Villa Price is also the Final Sale Value (one field in the UI since v101): store it as a
    // plain number and mirror it into salevalue, which Payments / outstanding use. $d['salevalue'] is
    // set too so the "only update submitted fields" filter below keeps it. Only when the price was
    // actually submitted (Booking Initiated unlocked) — otherwise both stay as stored.
    if (array_key_exists('final_villa_price', $d)) {
        $fvpRaw = trim((string)($d['final_villa_price'] ?? ''));
        $fvpNum = inr_to_number($fvpRaw);
        $fields['final_villa_price'] = $fvpNum ? rtrim(rtrim(number_format($fvpNum, 2, '.', ''), '0'), '.') : ($fvpRaw !== '' ? mb_substr($fvpRaw, 0, 50) : null);
        $d['salevalue'] = $fields['salevalue'] = $fvpNum ?: 0;
    }

    // Category auto-derives from whether broker details are present, so it
    // stays correct whether the lead started as a plain broker visit and
    // later got a client attached, or vice versa — checked on every save
    // (create or edit), not just at creation. Falls back to whatever the
    // Category dropdown was set to when no broker details are given.
    //
    // A pure broker visit (no separate client) has its name/mobile fields
    // filled with the broker's OWN name/contact as a fallback identity (see
    // public_intake.php's Broker Entry handling). That fallback identity is
    // NOT a real client — so if name/mobile still just mirror
    // broker_name/broker_contact, this must stay "Broker", not flip to
    // "Broker Reference" just because those fields aren't blank. Only a
    // name/mobile that actually differs from the broker's own counts as a
    // real client having been added.
    $normMobile = fn($m) => preg_replace('/\D/', '', (string)$m);
    $hasBroker = trim($d['broker_name'] ?? '') !== '' || trim($d['broker_contact'] ?? '') !== '';
    $isBrokerFallbackIdentity = $hasBroker
        && trim($d['name'] ?? '') === trim($d['broker_name'] ?? '')
        && $normMobile($d['mobile'] ?? '') === $normMobile($d['broker_contact'] ?? '');
    $hasClient = trim($d['name'] ?? '') !== '' && trim($d['mobile'] ?? '') !== '' && !$isBrokerFallbackIdentity;
    if ($hasBroker) {
        $fields['category'] = $hasClient ? 'Broker Reference' : 'Broker';
    } else {
        // "Not set" sends a blank — store NULL (a blank in this ENUM column fails the save on a
        // strict-mode MySQL server, and is stored as an odd empty value on a non-strict one).
        $fields['category'] = in_array($d['category'] ?? '', ['Broker', 'Broker Reference', 'Owner'], true) ? $d['category'] : null;
    }

    if (!$id && !is_admin() && in_array($fields['stage'], POST_SALES_STAGES, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Post Sales stages (Booking & Legal onward) are handled by admin only.']);
        exit;
    }

    if (!$id) {
        // Only set on creation — these describe how/where the lead originated,
        // and shouldn't change on later edits.
        $fields['entry_channel'] = 'CRM';
        $fields['entry_type'] = 'Manual';
        $fields['created_by'] = $CURRENT_USER_ID;
    }

    if ($id) {
        // Update — non-admins may only update their own client.
        $check = $pdo->prepare('SELECT salesperson_id FROM clients WHERE id = ?');
        $check->execute([$id]);
        $owner = $check->fetchColumn();
        if ($owner === false) { http_response_code(404); echo json_encode(['error' => 'Client not found']); exit; }
        if (!is_admin() && !is_post_sales() && (int)$owner !== (int)$CURRENT_USER_ID) {
            http_response_code(403); echo json_encode(['error' => 'Not your lead']); exit;
        }

        // Booking & Legal gate: can't move forward past a legal checkpoint until
        // its mandatory documents are uploaded (and KYC is Verified). Uses the
        // submitted KYC values when present, else what's already stored.
        $prev = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $prev->execute([$id]);
        $prevRow = $prev->fetch() ?: [];
        // Post Sales is admin-only: a salesperson can't move a lead into it
        // (Unit Blocked → Booking Initiated) or change a lead's stage once it's there.
        $prevStage = $prevRow['stage'] ?? 'New Lead';
        // Legal / Accounts desks: only leads that are with Post Sales, only Post Sales stages
        // (from Booking Initiated on, or Closed / Cancelled) — never back into the Sales stages.
        // The lead stays on its salesperson and its villa can't change from here.
        if (is_post_sales()) {
            if (!lead_with_post_sales($prevRow)) {
                http_response_code(403); echo json_encode(['error' => 'This lead is still with the Sales team.']); exit;
            }
            $stageMoved = $fields['stage'] !== $prevStage;
            if (is_accounts_desk()) {
                // Accounts desk: only leads Legal has transferred; Construction Customer onward only.
                if (!lead_with_accounts($prevRow)) {
                    http_response_code(403); echo json_encode(['error' => 'This lead is still with the Legal team.']); exit;
                }
                if ($stageMoved && !in_array($fields['stage'], ACCOUNTS_STAGES, true) && $fields['stage'] !== 'Closed') {
                    http_response_code(403); echo json_encode(['error' => 'The Accounts team cannot move a lead back into Booking & Legal.']); exit;
                }
                // Possession is handled by admin: no moving into, out of or within the Possession stages from here
                // (the final "OC / CC Received" demand in Construction & Payment moves a lead to Possession Due).
                if ($stageMoved && (in_array($fields['stage'], POSSESSION_STAGES, true) || in_array($prevStage, POSSESSION_STAGES, true))) {
                    http_response_code(403); echo json_encode(['error' => 'Possession is handled by the admin — the Accounts team can\'t change a Possession stage.']); exit;
                }
            } else {
                // Legal desk: Booking & Legal only; once transferred to Accounts it's read-only here.
                if (lead_with_accounts($prevRow)) {
                    http_response_code(403); echo json_encode(['error' => 'This lead has been transferred to the Accounts team. Only an admin can edit it now.']); exit;
                }
                if ($stageMoved && !in_array($fields['stage'], LEGAL_STAGES, true) && !in_array($fields['stage'], ['Closed', 'Cancelled'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => in_array($fields['stage'], ACCOUNTS_STAGES, true)
                        ? 'Construction & Payment is handled by the Accounts team — use "Transfer to Accounts" at Registered.'
                        : 'The Legal team cannot move a lead back into the Sales stages.']);
                    exit;
                }
            }
            $fields['salesperson_id'] = (int)$owner;
            $fields['villa'] = $prevRow['villa'] ?? null;
        }
        if (!is_admin() && !is_post_sales() && $prevStage !== $fields['stage']
            && (in_array($prevStage, POST_SALES_STAGES, true) || in_array($fields['stage'], POST_SALES_STAGES, true))) {
            http_response_code(403);
            echo json_encode(['error' => 'Post Sales stages (Booking & Legal onward) are handled by admin only.']);
            exit;
        }

        // Once sales has handed the lead over to Post Sales (or it's already in a Post Sales stage,
        // e.g. an older lead moved there by admin), only admin and the Post Sales desks can edit it.
        if (!is_admin() && !is_post_sales() && lead_with_post_sales($prevRow)) {
            http_response_code(403);
            echo json_encode(['error' => 'This lead has been transferred to the Post Sales team. Only an admin can edit it now.']);
            exit;
        }

        $kv = fn($k) => array_key_exists($k, $d) ? $d[$k] : ($prevRow[$k] ?? null);

        // "Complete Sales & Transfer to Post Sales" (end of Unit Blocked). Stage stays
        // Unit Blocked — Booking Initiated is still Post Sales' own first step — but the
        // lead is marked as handed over. Moving it back into an earlier sales stage clears it.
        $handoverSet = null; // null = leave as is, true = set now, false = clear
        if (($d['sales_handover'] ?? '') === '1' && empty($prevRow['sales_handover_at'])) {
            if ($fields['stage'] !== 'Unit Blocked') {
                http_response_code(400); echo json_encode(['error' => 'A lead can only be transferred to Post Sales from the Unit Blocked stage.']); exit;
            }
            if (!trim((string)$kv('villa')) || !$kv('block_date')) {
                http_response_code(400); echo json_encode(['error' => 'Fill in the final villa (Unit Selection) and Block Date (Unit Blocked) before transferring to Post Sales.']); exit;
            }
            $handoverSet = true;
        } elseif (!empty($prevRow['sales_handover_at']) && $fields['stage'] !== 'Unit Blocked'
            && in_array($fields['stage'], FOLLOWUP_PIPELINE, true) && !in_array($fields['stage'], POST_SALES_STAGES, true)) {
            $handoverSet = false;
        } elseif (!empty($prevRow['sales_handover_at']) && !in_array($fields['stage'], FOLLOWUP_PIPELINE, true)
            && !in_array($fields['stage'], ['Closed', 'Cancelled'], true)) {
            $handoverSet = false; // moved back before Site Visit Scheduled
        }

        // "Legal Completed & Transfer to Accounts" (end of Registered). Stage stays Registered —
        // Construction Customer is the Accounts desk's own first step. Admin moving the lead back
        // before Registered clears it again.
        $accountsSet = null; // null = leave as is, true = set now, false = clear
        if (($d['accounts_handover'] ?? '') === '1' && empty($prevRow['accounts_handover_at'])) {
            if (!is_admin() && !is_legal_desk()) {
                http_response_code(403); echo json_encode(['error' => 'Only the Legal team or an admin can transfer a lead to Accounts.']); exit;
            }
            if ($fields['stage'] !== 'Registered') {
                http_response_code(400); echo json_encode(['error' => 'A lead can only be transferred to Accounts from the Registered stage.']); exit;
            }
            if (!$kv('registration_date')) {
                http_response_code(400); echo json_encode(['error' => 'Fill in the Registration Date (Registered) before transferring to Accounts.']); exit;
            }
            $accErr = legal_gate_error($pdo, $id, 'Registered', 'Construction Customer',
                $kv('applicant_type') ?: 'Resident Individual', trim((string)$kv('co_applicant_name')) !== '', $kv('kyc_status'));
            if ($accErr) { http_response_code(400); echo json_encode(['error' => $accErr]); exit; }
            $accountsSet = true;
        } elseif (!empty($prevRow['accounts_handover_at']) && !stage_reached($fields['stage'], 'Registered')
            && !in_array($fields['stage'], ['Closed', 'Cancelled'], true)) {
            $accountsSet = false;
        }
        $gateErr = legal_gate_error($pdo, $id, $prevRow['stage'] ?? 'New Lead', $fields['stage'],
            $kv('applicant_type') ?: 'Resident Individual', trim((string)$kv('co_applicant_name')) !== '', $kv('kyc_status'));
        if ($gateErr) { http_response_code(400); echo json_encode(['error' => $gateErr]); exit; }
        // Construction & Payment gate: each construction checkpoint needs its payment demand raised
        // (or already paid in advance) before moving on; handover needs full payment (api/payment_lib.php).
        $payErr = pay_gate_error($pdo, $id, $prevRow['stage'] ?? 'New Lead', $fields['stage']);
        if ($payErr) { http_response_code(400); echo json_encode(['error' => $payErr]); exit; }

        // Booking cancellation: not possible once registration is done (the buyer is final for
        // that villa). A cancel of a lead that's with Post Sales notifies its salesperson + admin.
        $isCancelNow = $fields['stage'] === 'Cancelled' && $prevStage !== 'Cancelled';
        if ($isCancelNow && stage_reached($prevStage, 'Registered')) {
            http_response_code(400);
            echo json_encode(['error' => 'Registration is completed — this client is the final buyer of the villa, so the booking can\'t be cancelled.']);
            exit;
        }
        $bookingCancelled = $isCancelNow && lead_with_post_sales($prevRow);

        $conflict = villa_conflict($pdo, $fields['villa'], $id, $fields['stage'], $d['override_villa_conflict'] ?? false);
        if ($conflict) { http_response_code(409); echo json_encode(['error' => $conflict]); exit; }

        // Values needed for the post-update side-effects below, captured
        // BEFORE the never-submitted-field filtering right after this —
        // those functions still need to see what was actually sent this
        // time even for a field that's about to be left out of the UPDATE
        // itself.
        $visitStatusVal = $fields['visit_status']; $visitDateVal = $fields['visit_date'];
        $visitTimeVal = $fields['visit_time']; $visitVillaVal = $fields['visit_villa'];
        $visitVisitorsVal = $fields['visit_visitors']; $visitOutcomeVal = $fields['visit_outcome'];
        $visitFeedbackVal = $fields['visit_feedback']; $villaVal = $fields['villa'];
        $revisitDateVal = $fields['revisit_date']; $revisitTimeVal = $fields['revisit_time'];
        $revisitVillaVal = $fields['revisit_villa']; $revisitVisitorsVal = $fields['revisit_visitors'];
        $revisitOutcomeVal = $fields['revisit_outcome']; $revisitFeedbackVal = $fields['revisit_feedback'];
        $negDateVal = $fields['negotiation_visit_date']; $negTimeVal = $fields['negotiation_visit_time'];
        $negNoteVal = $fields['negotiation_notes'];
        $revisitDoneVal = stage_reached($fields['stage'], 'Re-Visit Completed') ? 'Done' : '';
        $negotiationDoneVal = stage_reached($fields['stage'], 'Unit Selection') ? 'Done' : '';

        // Only touch a column in this UPDATE if its form field was actually
        // part of the submitted payload. A checkpoint's fields are disabled
        // (and so entirely absent from the submission) while its
        // stage-section is locked — e.g. right after using "Go to stage" to
        // correct an earlier checkpoint, every section ahead of it locks
        // again. Without this guard, saving from that state would silently
        // overwrite all of those now-locked fields with null/blank,
        // permanently erasing data that was already filled in. Leaving an
        // absent column out of the SQL entirely just preserves whatever is
        // already stored for it.
        foreach (CLIENT_FIELD_SOURCE_KEYS as $col => $srcKey) {
            if (!array_key_exists($srcKey, $d)) unset($fields[$col]);
        }
        // Legal desk: only booking / KYC / agreement / registration details (+ stage / history).
        // Accounts desk: only construction / payment / possession details (+ stage / history).
        if (is_legal_desk()) {
            $keep = array_merge(array_diff(POST_SALES_COLS, ACCOUNTS_COLS), ['stage', 'activity_log', 'agreement', 'registration', 'cancelled_reason', 'salesperson_id']);
            $fields = array_intersect_key($fields, array_flip($keep));
        } elseif (is_accounts_desk()) {
            $keep = array_merge(array_diff(ACCOUNTS_COLS, POSSESSION_COLS), ['stage', 'activity_log', 'salesperson_id']);
            $fields = array_intersect_key($fields, array_flip($keep));
        }

        if ($handoverSet === true) {
            $fields['sales_handover_at'] = date('Y-m-d H:i:s');
            $fields['sales_handover_by'] = $CURRENT_USER_ID;
        } elseif ($handoverSet === false) {
            $fields['sales_handover_at'] = null;
            $fields['sales_handover_by'] = null;
        }
        if ($accountsSet === true) {
            $fields['accounts_handover_at'] = date('Y-m-d H:i:s');
            $fields['accounts_handover_by'] = $CURRENT_USER_ID;
        } elseif ($accountsSet === false) {
            $fields['accounts_handover_at'] = null;
            $fields['accounts_handover_by'] = null;
        }

        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $fields['id'] = $id;
        try {
            $stmt = $pdo->prepare("UPDATE clients SET $set WHERE id = :id");
            $stmt->execute($fields);
            // Visit logging must use what's actually STORED, not just what this
            // request submitted: a checkpoint's fields can be absent from the
            // payload (locked/disabled section), which used to leave the values
            // null here so the visit was never logged Completed.
            $sv = $pdo->prepare('SELECT visit_status, visit_date, visit_time, visit_villa, visit_visitors, visit_outcome, visit_feedback, revisit_date, revisit_time, revisit_villa, revisit_visitors, revisit_outcome, revisit_feedback, negotiation_visit_date, negotiation_visit_time, negotiation_notes, villa FROM clients WHERE id = ?');
            $sv->execute([$id]);
            if ($st = $sv->fetch(PDO::FETCH_ASSOC)) {
                $visitStatusVal = $st['visit_status']; $visitDateVal = $st['visit_date']; $visitTimeVal = $st['visit_time']; $visitVillaVal = $st['visit_villa'];
                $visitVisitorsVal = $st['visit_visitors']; $visitOutcomeVal = $st['visit_outcome']; $visitFeedbackVal = $st['visit_feedback'];
                $revisitDateVal = $st['revisit_date']; $revisitTimeVal = $st['revisit_time']; $revisitVillaVal = $st['revisit_villa'];
                $revisitVisitorsVal = $st['revisit_visitors']; $revisitOutcomeVal = $st['revisit_outcome']; $revisitFeedbackVal = $st['revisit_feedback'];
                $negDateVal = $st['negotiation_visit_date']; $negTimeVal = $st['negotiation_visit_time'];
                $negNoteVal = $st['negotiation_notes']; $villaVal = $st['villa'];
            }
            if (!is_post_sales()) { // Sales-side syncs — the Post Sales desks never touch these
                sync_next_followup($pdo, $id, $d['nextfollow'] ?? null, $CURRENT_USER_ID, $d['salesperson_id'] ?? $owner);
                foreach (FOLLOWUP_STAGES as $fuStage => $fuCfg) { // Site Visit / Post Site Visit / Re-Visit follow-ups
                    $p = $fuCfg['prefix'];
                    sync_stage_followup($pdo, $fuStage, $id, $d[$p . '_followup_date'] ?? null, $d[$p . '_followup_mode'] ?? null, $d[$p . '_followup_note'] ?? null, $d[$p . '_refollow'] ?? null, $d[$p . '_refollow_date'] ?? null, $CURRENT_USER_ID, $fields['salesperson_id']);
                }
            }
            complete_stage_followups_if_past($pdo, $id, $fields['stage']); // lead moved on — follow-up is done
            if (!is_post_sales()) {
                sync_site_visit($pdo, $id, $visitStatusVal, $visitDateVal, $visitTimeVal, $visitVillaVal, $visitVisitorsVal, $visitOutcomeVal, $visitFeedbackVal);
                sync_revisit_visit($pdo, $id, $revisitDoneVal, $revisitDateVal, $revisitTimeVal, $revisitVillaVal, $revisitVisitorsVal, $revisitOutcomeVal, $revisitFeedbackVal);
                sync_negotiation_visit($pdo, $id, $negotiationDoneVal, $negDateVal, $negTimeVal, $villaVal, $negNoteVal);
            }
            // "Closed" on a lead whose booking is already confirmed / registered keeps its villa booked / sold —
            // only Cancelled releases a booking. Earlier holds (negotiation / blocked) are still released.
            $villaStage = $fields['stage'] === 'Closed' && in_array(villa_target_status($prevStage), ['booked', 'sold'], true)
                ? $prevStage : $fields['stage'];
            resolve_and_sync_villa($pdo, $id, $villaVal, $villaStage);
            // Sales → Post Sales transfer: one notification for the Post Sales team (their bell)
            // and a permanent row in admin's Notification Log.
            if ($handoverSet === true) {
                log_notification($pdo, 'post_sales_transfer', $CURRENT_USER_ID, null, $id, null, $villaVal ?: null);
            }
            // Legal → Accounts transfer: the Accounts desk's bell + admin's Notification Log.
            if ($accountsSet === true) {
                log_notification($pdo, 'accounts_transfer', $CURRENT_USER_ID, null, $id, null, $villaVal ?: null);
            }
            // Booking cancelled: to the lead's salesperson (their bell), admins' bells + Notification Log.
            // Its villa(s) went back to Available just above.
            if ($bookingCancelled) {
                log_notification($pdo, 'booking_cancelled', $CURRENT_USER_ID, (int)$owner, $id, null,
                    ($villaVal ? "$villaVal released — now Available" : 'Booking cancelled'));
            }
            // Notify the new owner if this edit also reassigned the lead.
            if ((int)$fields['salesperson_id'] !== (int)$owner) {
                carry_over_reassigned_lead($pdo, $id, $fields['salesperson_id']); // follow-ups move with the lead
                try {
                    $pdo->prepare('INSERT INTO assignment_log (client_id, from_user_id, to_user_id, type) VALUES (?, ?, ?, \'reassigned\')')
                        ->execute([$id, $CURRENT_USER_ID, $fields['salesperson_id']]);
                } catch (PDOException $e) { /* migration_v10/v11.sql not run yet — ignore */ }
            }
        } catch (PDOException $e) {
            http_response_code(500);
            // Most likely cause: migration_v5.sql (Site Visit columns) hasn't
            // been run against this database yet.
            echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'id' => (int)$id]);
    } else {
        $conflict = villa_conflict($pdo, $fields['villa'], 0, $fields['stage'], $d['override_villa_conflict'] ?? false);
        if ($conflict) { http_response_code(409); echo json_encode(['error' => $conflict]); exit; }

        $cols = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        try {
            $stmt = $pdo->prepare("INSERT INTO clients ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);
            $newId = $pdo->lastInsertId();

            // Generate a display lead code like L001 from the real auto-increment id.
            $code = 'L' . str_pad($newId, 3, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE clients SET lead_code = ? WHERE id = ?')->execute([$code, $newId]);
            sync_next_followup($pdo, $newId, $d['nextfollow'] ?? null, $CURRENT_USER_ID, $salesperson_id);
            sync_site_visit($pdo, $newId, $fields['visit_status'], $fields['visit_date'], $fields['visit_time'], $fields['visit_villa'], $fields['visit_visitors'], $fields['visit_outcome'], $fields['visit_feedback']);
            sync_revisit_visit($pdo, $newId, stage_reached($fields['stage'], 'Re-Visit Completed') ? 'Done' : '', $fields['revisit_date'], $fields['revisit_time'], $fields['revisit_villa'], $fields['revisit_visitors'], $fields['revisit_outcome'], $fields['revisit_feedback']);
            sync_negotiation_visit($pdo, $newId, stage_reached($fields['stage'], 'Unit Selection') ? 'Done' : '', $fields['negotiation_visit_date'], $fields['negotiation_visit_time'], $fields['villa'], $fields['negotiation_notes']);
            resolve_and_sync_villa($pdo, $newId, $fields['villa'], $fields['stage']);
            // Notify the assignee if this new lead was handed straight to someone else.
            if ((int)$salesperson_id !== (int)$CURRENT_USER_ID) {
                try {
                    $pdo->prepare('INSERT INTO assignment_log (client_id, from_user_id, to_user_id, type) VALUES (?, ?, ?, \'assigned\')')
                        ->execute([$newId, $CURRENT_USER_ID, $salesperson_id]);
                } catch (PDOException $e) { /* migration_v10/v11.sql not run yet — ignore */ }
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
            exit;
        }

        echo json_encode(['success' => true, 'id' => (int)$newId, 'lead_code' => $code]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Permanent removal — separate from the "Cancelled" stage, which just
    // marks a lead as closed-out while keeping its history. Admin-only:
    // deleting a lead a salesperson doesn't want to look at anymore is a
    // business decision, not something each salesperson should self-serve.
    if (!is_admin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Only an admin can delete a lead']);
        exit;
    }

    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    $who = $pdo->prepare('SELECT name, lead_code FROM clients WHERE id = ?');
    $who->execute([$id]);
    $lead = $who->fetch();
    if (!$lead) { http_response_code(404); echo json_encode(['error' => 'Lead not found']); exit; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM followups WHERE client_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM visits WHERE client_id = ?')->execute([$id]);
        try { $pdo->prepare('DELETE FROM client_documents WHERE client_id = ?')->execute([$id]); } catch (PDOException $e) { /* pre-v31 DB */ }
        foreach (['client_payments', 'payment_demands', 'payment_schedule', 'payment_plans'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE client_id = ?")->execute([$id]); } catch (PDOException $e) { /* pre-v37 DB */ }
        }
        // Any villa this lead was holding goes back to Available (otherwise it stays blocked / booked / sold with no lead).
        $pdo->prepare("UPDATE villas SET status = 'available', linked_client_id = NULL WHERE linked_client_id = ?")->execute([$id]);
        // The Notification Log is the permanent audit trail: keep its rows, just detach them from the
        // deleted lead and keep its name in the label (rows with no lead show the label).
        try {
            $pdo->prepare("UPDATE assignment_log SET label = LEFT(CONCAT(?, CASE WHEN type IN ('reschedule_proposed', 'post_sales_transfer', 'accounts_transfer', 'booking_cancelled')
                               AND label IS NOT NULL AND label <> '' THEN CONCAT(' — ', label) ELSE '' END, ' · lead deleted'), 191), client_id = NULL
                           WHERE client_id = ?")
                ->execute([$lead['name'] . ($lead['lead_code'] ? " ({$lead['lead_code']})" : ''), $id]);
        } catch (PDOException $e) { /* pre-v30 DB (client_id not nullable yet) */ }
        // A Source Lead that was assigned to this lead stays "Assigned" in Source Leads, just no longer linked.
        try { $pdo->prepare('UPDATE source_leads SET assigned_lead_id = NULL WHERE assigned_lead_id = ?')->execute([$id]); } catch (PDOException $e) { /* pre-v6 DB */ }
        try { $pdo->prepare('DELETE FROM lead_transfer_requests WHERE client_id = ?')->execute([$id]); } catch (PDOException $e) { /* pre-v9 DB */ }
        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Could not delete this lead: ' . $e->getMessage()]);
        exit;
    }
    // Remove this lead's uploaded files too: Booking & Legal / payment documents and the demand-letter
    // PDFs (whose public WhatsApp links in letter.php stop working with them).
    foreach (['client_docs', 'demand_letters'] as $sub) {
        $docDir = __DIR__ . '/../uploads/' . $sub . '/' . (int)$id;
        if (is_dir($docDir)) { foreach (glob($docDir . '/*') ?: [] as $fp) { if (is_file($fp)) unlink($fp); } @rmdir($docDir); }
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
