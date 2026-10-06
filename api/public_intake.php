<?php
// api/public_intake.php — CRM quick-add (login required).
//
// (Its old PUBLIC MODE for the website forms is closed — those forms post to
//  api/source_intake.php instead, which stages them in Source Leads for review.)
//
// CRM QUICK-ADD MODE (logged in): called from inside the CRM when
//    Entry / Broker / Owner desk users or admin use the role-specific "+ Add Lead"
//    form. Same field mapping and assignment rules as public mode —
//    just records entry_channel = 'CRM' and created_by = the logged-in
//    user, so admin can always see who added what and from where.
//
// Assignment rule (by design, see README): the auto-assigned salesperson is
// only set when a NEW client is created. If a submission matches an
// EXISTING client (by mobile number), we log the new information but never
// silently reassign that client away from whoever already owns the lead.
// An admin can always reassign manually inside the CRM.

// Login required: the public website forms post to api/source_intake.php (Source Leads review)
// and never come here, so an anonymous call can't create leads or add notes / visits to them.
require __DIR__ . '/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
// Same rule as api/clients.php: the Post Sales team (Legal / Accounts) doesn't add new leads.
if (is_post_sales()) {
    http_response_code(403);
    echo json_encode(['error' => 'The Post Sales team cannot add new leads.']);
    exit;
}

require __DIR__ . '/../config/db.php';

// Always a CRM quick-add now (logged-in user) — kept as variables so the code below reads the same.
$IS_CRM_CALL = true;
$ENTRY_CHANNEL = 'CRM';
$CREATED_BY = (int)$CURRENT_USER_ID;

$d = json_decode(file_get_contents('php://input'), true);
$formType = $d['form_type'] ?? '';

if (!in_array($formType, ['enquiry', 'broker', 'sitevisit'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown or missing form_type']);
    exit;
}

// ---------- helpers ----------
function normalizeMobile($raw) {
    return preg_replace('/\D/', '', (string)$raw);
}

function findClientByMobile($pdo, $mobile) {
    if (!$mobile) return null;
    // Exact match on normalized digits only — NOT a LIKE '%...%' substring
    // match. A substring match was matching unrelated leads whose mobile
    // number merely happened to contain this one's digits (e.g. a short or
    // partial number), surfacing the wrong "already exists" lead entirely.
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE REPLACE(REPLACE(mobile,' ',''),'-','') = ?");
    $stmt->execute([$mobile]);
    return $stmt->fetch();
}

function desk_user_id($pdo, $desk) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE desk = ? LIMIT 1");
    $stmt->execute([$desk]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    // Fallback: no desk configured yet — assign to first admin so nothing is lost.
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    return (int)$stmt->fetchColumn();
}

function current_user_desk($pdo, $userId) {
    if (!$userId) return null;
    $stmt = $pdo->prepare("SELECT desk FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $desk = $stmt->fetchColumn();
    return $desk ?: null;
}

// If the logged-in CRM user submitting this lead is themselves a sales
// desk (broker or owner) — not entry-desk, not admin — always assign the
// lead straight to them, regardless of what classification/category was
// picked on the form. This is what makes self-add reliable: a Broker desk salesperson adding
// his own lead shouldn't depend on him picking the "right" client type,
// and it works the same from whichever form he uses. Entry-desk and admin
// submissions (no personal sales desk of their own) still fall through to
// the normal classification-based desk routing.
function resolve_salesperson($pdo, $isCrmCall, $createdBy, $targetDesk) {
    if ($isCrmCall && $createdBy) {
        $myDesk = current_user_desk($pdo, $createdBy);
        if (in_array($myDesk, ['broker', 'owner'], true)) return (int)$createdBy;
    }
    return desk_user_id($pdo, $targetDesk);
}

function appendNote($existing, $line) {
    $stamp = date('d M Y H:i');
    $entry = "[$stamp] $line";
    return $existing ? ($existing . "\n" . $entry) : $entry;
}

function createClient($pdo, $fields) {
    $fields['lead_code'] = null;
    $cols = implode(', ', array_keys($fields));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
    $stmt = $pdo->prepare("INSERT INTO clients ($cols) VALUES ($ph)");
    $stmt->execute($fields);
    $id = $pdo->lastInsertId();
    $code = 'L' . str_pad($id, 3, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE clients SET lead_code = ? WHERE id = ?')->execute([$code, $id]);
    return (int)$id;
}

function updateClientNotes($pdo, $id, $currentNotes, $newLine) {
    $stmt = $pdo->prepare('UPDATE clients SET notes = ? WHERE id = ?');
    $stmt->execute([appendNote($currentNotes, $newLine), $id]);
}

// Tells the CRM frontend who currently owns a duplicate match, so it can
// offer "Request Lead" instead of a dead-end message when it belongs to
// the OTHER sales desk than whoever just tried to add it.
function ownerInfo($pdo, $existing) {
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $stmt->execute([$existing['salesperson_id']]);
    $owner = $stmt->fetch();
    return ['salesperson_id' => (int)$existing['salesperson_id'], 'salesperson_name' => $owner['name'] ?? null];
}

// ============================================================
// Everything below is wrapped so a real error (bad column, DB issue,
// unexpected value) always comes back as visible JSON instead of a
// blank/broken response that looks like success to the caller.
// ============================================================
try {

// ============================================================
// ENQUIRY FORM
// ============================================================
if ($formType === 'enquiry') {
    $mobile = normalizeMobile($d['clientContact'] ?? '');
    if (!($d['clientName'] ?? '') || !$mobile) {
        http_response_code(400);
        echo json_encode(['error' => 'Client name and contact are required']);
        exit;
    }

    $type = $d['clientType'] ?? '';
    $category = $type === 'Broker' ? 'Broker' : 'Owner'; // Investor / Other -> Owner desk
    $source = $d['source'] === 'Other' ? ($d['sourceOther'] ?? 'Other') : ($d['source'] ?? null);
    $subsource = ($d['referredBy'] ?? '') === 'Other' ? ($d['referredByOther'] ?? null) : ($d['referredBy'] ?? null);
    $config = $d['villaType'] === 'Other' ? ($d['villaTypeOther'] ?? null) : ($d['villaType'] ?? null);
    $leadStatus = $d['leadStatus'] === 'Other' ? ($d['leadStatusOther'] ?? null) : ($d['leadStatus'] ?? null);
    $interest = in_array($leadStatus, ['Hot', 'Warm', 'Cold'], true) ? strtoupper($leadStatus) : 'WARM';
    // "Site Visit Scheduled" on the form starts at New Lead (there's no such thing as the old
    // "Site Visit Planned" stage) — the salesperson then schedules the visit with its date and time.
    $stage = $leadStatus === 'Cancelled' ? 'Cancelled' : 'New Lead';

    $existing = findClientByMobile($pdo, $mobile);
    $noteLine = "Enquiry form: " . trim(($d['enquiryDetails'] ?? '') . ' | Purpose: ' . ($d['investmentPurpose'] ?? ''))
        . ($leadStatus === 'Site Visit Scheduled' ? ' | Lead status on form: Site Visit Scheduled' : '');

    if ($existing) {
        if (!$IS_CRM_CALL) {
            // Public website submission — still fine to auto-log, no human is
            // standing by to "handle it manually" for a real site visitor.
            updateClientNotes($pdo, $existing['id'], $existing['notes'], $noteLine);
        }
        // CRM-side: nothing is written. The salesperson reviews and edits
        // the existing lead themselves — see the "duplicate" flag below.
        echo json_encode(array_merge(
            ['success' => true, 'duplicate' => $IS_CRM_CALL, 'matched_existing' => true, 'id' => (int)$existing['id'], 'lead_code' => $existing['lead_code'], 'name' => $existing['name']],
            $IS_CRM_CALL ? ownerInfo($pdo, $existing) : []
        ));
    } else {
        $id = createClient($pdo, [
            'name' => $d['clientName'], 'mobile' => $mobile, 'email' => $d['email'] ?? null,
            'city' => $d['clientLocation'] ?? null,
            'source' => $source, 'subsource' => $subsource, 'salesperson_id' => resolve_salesperson($pdo, $IS_CRM_CALL, $CREATED_BY, $category === 'Broker' ? 'broker' : 'owner'),
            'created_date' => date('Y-m-d'), 'config' => $config, 'budget' => $d['budget'] ?? null, 'interest' => $interest,
            'stage' => $stage, 'notes' => "[".date('d M Y H:i')."] $noteLine",
            'category' => $category, 'entry_channel' => $ENTRY_CHANNEL, 'entry_type' => 'Enquiry',
            'created_by' => $CREATED_BY,
        ]);
        echo json_encode(['success' => true, 'matched_existing' => false, 'id' => $id]);
    }
    exit;
}

// ============================================================
// BROKER FORM (client brought is optional)
// ============================================================
if ($formType === 'broker') {
    $mobile = normalizeMobile($d['clientContact'] ?? '');
    $brokerMobile = normalizeMobile($d['contact'] ?? '');
    $brokerLine = "Broker visit — Broker: " . ($d['brokerName'] ?? '—') . ' (' . ($d['company'] ?? 'independent') . '), '
        . 'Contact: ' . ($d['contact'] ?? '—') . ($d['email'] ? ', ' . $d['email'] : '') . '. Occupation: ' . ($d['occupation'] ?? '—')
        . '. Visit type: ' . ($d['visitType'] ?? '—') . '. Reference: ' . ($d['reference'] ?? ($d['sourceOther'] ?? '—'))
        . ($d['referredBy'] ? '. Referred by: ' . $d['referredBy'] : '')
        . ($d['clientBudget'] ? '. Client budget: ' . $d['clientBudget'] : '')
        . '. RERA: ' . ($d['reraRegistered'] ?? '—') . ($d['reraNumber'] ? ' (' . $d['reraNumber'] . ')' : '')
        . '. Feedback: ' . ($d['feedback'] ?? '—');

    $clientNameGiven = trim($d['clientName'] ?? '') !== '';

    // "Client brought" stays optional on the form. But when it's left
    // blank, a lead should still be created — not silently dropped — so
    // fall back to the broker's own name/contact (Broker Name/Contact are
    // required fields on this form, so this is always available). If a
    // client WAS given, that's the lead; otherwise the broker's own entry
    // becomes the lead.
    $leadName = $clientNameGiven ? $d['clientName'] : ($d['brokerName'] ?? 'Unnamed Broker Lead');
    $leadMobile = $mobile ?: $brokerMobile;
    $interest = in_array($d['interest'] ?? '', ['Hot', 'Warm', 'Cold'], true) ? strtoupper($d['interest']) : 'WARM';

    $existing = $leadMobile ? findClientByMobile($pdo, $leadMobile) : null;
    if ($existing) {
        if (!$IS_CRM_CALL) {
            updateClientNotes($pdo, $existing['id'], $existing['notes'], $brokerLine);
        }
        echo json_encode(array_merge(
            ['success' => true, 'duplicate' => $IS_CRM_CALL, 'matched_existing' => true, 'id' => (int)$existing['id'], 'lead_code' => $existing['lead_code'], 'name' => $existing['name']],
            $IS_CRM_CALL ? ownerInfo($pdo, $existing) : []
        ));
    } else {
        $id = createClient($pdo, [
            'name' => $leadName, 'mobile' => $leadMobile ?: '', 'source' => 'Broker', 'budget' => $d['clientBudget'] ?? null,
            'broker_name' => $d['brokerName'] ?? null, 'broker_contact' => $d['contact'] ?? null, 'broker_email' => $d['email'] ?? null,
            'salesperson_id' => resolve_salesperson($pdo, $IS_CRM_CALL, $CREATED_BY, 'broker'),
            'created_date' => date('Y-m-d'), 'interest' => $interest, 'stage' => 'New Lead',
            'notes' => "[".date('d M Y H:i')."] $brokerLine",
            // Client brought along with the broker -> Broker Reference; a broker's own visit (no client) -> Broker.
            'category' => $clientNameGiven ? 'Broker Reference' : 'Broker',
            'entry_channel' => $ENTRY_CHANNEL, 'entry_type' => 'Broker Visit',
            'created_by' => $CREATED_BY,
        ]);
        echo json_encode(['success' => true, 'matched_existing' => false, 'id' => $id]);
    }
    exit;
}

// ============================================================
// SITE VISIT FORMS — two flavours (visit_purpose):
//   'new'  — New Lead form: just the client's basic details + requirement; lead starts at New Lead.
//   'done' — Walk-in Site Visit form (default — also what the public website Site Visit form
//            sends): the client came straight to site. The lead is created directly at
//            "Site Visit Completed" — Contact Attempted / Contacted are skipped, Qualified is
//            auto-marked Yes, Requirement Understood is filled from the form (editable later),
//            the post-contact WhatsApp greeting is skipped, Brochure Sent is marked "send after
//            the site visit", and Site Visit Scheduled / Site Visit Follow-up are skipped.
// Assignment: the logged-in Broker/Owner desk salesperson keeps it; otherwise the Owner desk.
// ============================================================
if ($formType === 'sitevisit') {
    $mobile = normalizeMobile($d['client_contact'] ?? ''); // country code kept out of the match key
    if (!($d['client_name'] ?? '') || !$mobile) {
        http_response_code(400);
        echo json_encode(['error' => 'Client name and contact are required']);
        exit;
    }

    $source = ($d['source'] ?? '') === 'Other' ? ($d['source_other'] ?? 'Other') : ($d['source'] ?? null);
    $config = ($d['requirement'] ?? '') === 'Other' ? ($d['requirement_other'] ?? null) : ($d['requirement'] ?? null);
    $purpose = ($d['purpose'] ?? '') === 'Other' ? ($d['purpose_other'] ?? null) : ($d['purpose'] ?? null);
    $leadStatus = $d['lead_status'] ?? null;
    $interest = in_array(strtoupper((string)$leadStatus), ['HOT', 'WARM', 'COLD'], true) ? strtoupper($leadStatus) : 'WARM';

    // Default stays 'done' when the field isn't sent at all, so the real website
    // Site Visit form (an actual visitor) is treated as a walk-in site visit.
    $isActualVisit = ($d['visit_purpose'] ?? 'done') !== 'new';

    $visitDate = ($d['date'] ?? '') ?: date('Y-m-d');
    $visitTime = ($d['time_in'] ?? '') ?: null;
    $visitors = max(1, (int)($d['visitors'] ?? 1));
    $villaShown = trim($d['villa_shown'] ?? '') ?: null;
    $outcome = $d['visit_outcome'] ?? null;
    $feedback = $d['feedback'] ?? null;

    $existing = findClientByMobile($pdo, $mobile);
    $execNote = $isActualVisit
        ? "Walk-in site visit — " . $visitDate . ($visitTime ? " $visitTime" : '') . ", $visitors visitor(s)"
            . ($villaShown ? ", villa shown: $villaShown" : '') . ($outcome ? ", outcome: $outcome" : '')
            . (!empty($d['assigned_executive']) ? '. Assigned Executive on form: ' . $d['assigned_executive'] : '')
            . '. Feedback: ' . ($feedback ?: '—')
        : "New lead added" . ($feedback ? " — Notes: $feedback" : '');

    $isDuplicate = false;
    if ($existing) {
        $clientId = (int)$existing['id'];
        $isDuplicate = $IS_CRM_CALL;
        if (!$IS_CRM_CALL) {
            // Public website visitor who's already a lead: note it and log the visit on the Site Visits tab.
            updateClientNotes($pdo, $clientId, $existing['notes'], $execNote);
            if ($isActualVisit) {
                $pdo->prepare('INSERT INTO visits (client_id, kind, visit_date, visit_time, villa, visitors, status, outcome, feedback) VALUES (?, "site", ?, ?, ?, ?, "Completed", ?, ?)')
                    ->execute([$clientId, $visitDate, $visitTime, $villaShown, $visitors, $outcome, $feedback]);
            }
        }
    } else {
        $by = $IS_CRM_CALL ? ($_SESSION['name'] ?? '') : 'Website Form';
        $now = date('H:i');
        $today = date('Y-m-d');
        $log = [['date' => $today, 'time' => $now, 'type' => 'Lead Generated', 'note' => 'Lead added to the system', 'by' => $by]];
        $fields = [
            'name' => $d['client_name'], 'mobile' => $mobile, 'alt_mobile' => $d['client_alt_contact'] ?? null,
            'email' => $d['client_email'] ?? null, 'city' => $d['client_city'] ?? null,
            'source' => $source, 'subsource' => $d['reference'] ?? null,
            'salesperson_id' => resolve_salesperson($pdo, $IS_CRM_CALL, $CREATED_BY, 'owner'),
            'created_date' => $today, 'purpose' => $purpose, 'config' => $config,
            'budget' => $d['budget'] ?? null, 'interest' => $interest,
            'stage' => 'New Lead',
            'notes' => "[" . date('d M Y H:i') . "] $execNote",
            'category' => 'Owner', 'entry_channel' => $ENTRY_CHANNEL,
            'entry_type' => $isActualVisit ? 'Site Visit' : 'Enquiry',
            'created_by' => $CREATED_BY,
        ];
        if ($isActualVisit) {
            $fields = array_merge($fields, [
                'stage' => 'Site Visit Completed',
                'firstcall_date' => $visitDate, 'lastcontact_date' => $visitDate,
                // Qualified — auto Yes (the client is standing at the site)
                'budget_confirmed' => 'Yes', 'decision_maker' => 'Yes',
                // Requirement Understood — from the form, editable later
                'finance' => ($d['finance'] ?? '') ?: null,
                // Brochure Sent — skipped; sent after the site visit instead
                'brochure_bypass' => 'Yes',
                // Site Visit Completed
                'visit_status' => 'Done', 'visit_date' => $visitDate, 'visit_time' => $visitTime,
                'visit_villa' => $villaShown, 'visit_visitors' => $visitors,
                'visit_outcome' => $outcome, 'visit_feedback' => $feedback,
            ]);
            $when = $visitDate . ($visitTime ? ' ' . substr($visitTime, 0, 5) : '');
            $log[] = ['date' => $today, 'time' => $now, 'type' => 'Stage Change', 'by' => $by,
                'note' => 'Walk-in site visit — Contact Attempted, Contacted, Site Visit Scheduled and Site Visit Follow-up skipped'];
            $log[] = ['date' => $today, 'time' => $now, 'type' => 'Stage Change', 'by' => $by,
                'note' => 'Qualified — auto-marked (Budget Confirmed: Yes, Decision Maker: Yes)'];
            $log[] = ['date' => $today, 'time' => $now, 'type' => 'Stage Change', 'by' => $by,
                'note' => 'Requirement Understood — ' . implode(', ', array_filter([$purpose, $config, $d['budget'] ?? null, $d['finance'] ?? null])) . ' (post-contact WhatsApp greeting skipped)'];
            $log[] = ['date' => $today, 'time' => $now, 'type' => 'Stage Change', 'by' => $by,
                'note' => 'Brochure Sent — skipped, to be sent after the site visit'];
            $log[] = ['date' => $today, 'time' => $now, 'type' => 'Stage Change', 'by' => $by,
                'note' => 'Moved to Site Visit Completed'];
            $log[] = ['date' => $today, 'time' => $now, 'type' => 'Site Visit', 'by' => $by,
                'note' => "Site visit completed — $when · $visitors visitor(s)" . ($villaShown ? " · Villa shown: $villaShown" : '')
                    . ($outcome ? " · Outcome: $outcome" : '') . ($feedback ? " · Feedback: $feedback" : '')];
        }
        $fields['activity_log'] = json_encode($log, JSON_UNESCAPED_UNICODE);
        $clientId = createClient($pdo, $fields);

        // Log it on the Site Visits tab (same shape as sync_site_visit in clients.php).
        if ($isActualVisit) {
            $pdo->prepare('INSERT INTO visits (client_id, kind, visit_date, visit_time, villa, visitors, status, outcome, feedback) VALUES (?, "site", ?, ?, ?, ?, "Completed", ?, ?)')
                ->execute([$clientId, $visitDate, $visitTime, $villaShown, $visitors, $outcome, $feedback]);
        }
    }

    echo json_encode(array_merge(
        [
            'success' => true, 'duplicate' => $isDuplicate, 'matched_existing' => (bool)$existing,
            'id' => $clientId, 'lead_code' => $existing['lead_code'] ?? null, 'name' => $existing['name'] ?? $d['client_name'],
            'walkin' => $isActualVisit && !$existing,
        ],
        ($isDuplicate && $existing) ? ownerInfo($pdo, $existing) : []
    ));
    exit;
}

} catch (Throwable $e) {
    // Anything unexpected (bad column, DB constraint, etc.) — always come
    // back as visible JSON with a real status code, never a silent 200.
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['error' => 'Server error while saving this entry.', 'debug' => $e->getMessage()]);
    exit;
}
