<?php
// api/source_intake.php — PUBLIC endpoint for website / social-media forms.
//
// Workflow (per business rule): a form submission NEVER writes into `clients`
// directly and NEVER gets auto-assigned to a salesperson. It only lands in
// `source_leads` ("Source Leads" tab — Entry Desk + Admin only). A human
// reviews it, cross-checks it, and either:
//   - Assigns it  -> api/source_leads.php promotes it into a real `clients`
//                    row with a real Lead ID, then it behaves like any
//                    other lead.
//   - Rejects it  -> stays only in source_leads, clients/CRM flow untouched.
//
// Field matching: each known form sends its own field names. We map every
// name we recognise onto our fixed source_leads columns. Any field the form
// sends that we DON'T have a column for (e.g. a PAN card tab) is simply
// skipped for structured storage — it is still kept in raw_data as JSON so
// nothing is silently lost, but it never becomes/affects a CRM column.
//
// Cross-domain: akrutideveloper.com posting here (a different domain than
// antaayavillas.com/crm) needs CORS allowed, same as public_intake.php.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/../config/db.php';
require __DIR__ . '/notif_log_lib.php';

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$site = in_array($d['site'] ?? '', ['antaaya', 'akruti'], true) ? $d['site'] : 'antaaya';
$formType = $d['form_type'] ?? 'unknown';

// ---------- flexible field matching ----------
// Each canonical column lists every alias seen across all forms wired in so
// far (3 Antaaya site forms + the Akruti-hosted brochure form). Add more
// aliases here later instead of touching the forms themselves.
$ALIASES = [
    'name'      => ['clientName', 'client_name', 'brokerName', 'name', 'full_name', 'fullName', 'fullname'],
    'mobile'    => ['clientContact', 'client_contact', 'contact', 'mobile', 'phone', 'contact_number'],
    'alt_mobile'=> ['client_alt_contact', 'alt_mobile', 'altContact', 'alternate_number'],
    'email'     => ['email', 'clientEmail', 'client_email'],
    'city'      => ['clientLocation', 'client_city', 'city', 'location'],
    'source'    => ['source', 'source_other', 'sourceOther', 'reference'],
    'subsource' => ['referredBy', 'referredByOther', 'brokerName', 'company'],
    'budget'    => ['budget', 'clientBudget'],
    'config'    => ['villaType', 'villaTypeOther', 'requirement', 'requirement_other'],
    'purpose'   => ['investmentPurpose', 'purpose', 'purpose_other', 'intendedUse'],
];

// Fields we deliberately never store anywhere — not as a column, not in
// notes, not in raw_data — even though the form collects them. Example:
// booking.html has a PAN Number tab; our CRM has no such column, and PAN is
// sensitive enough that we don't want it sitting in a text field either.
$IGNORE_FIELDS = ['pan'];
foreach ($IGNORE_FIELDS as $f) { unset($d[$f]); }

function firstMatch($d, $keys) {
    foreach ($keys as $k) {
        if (isset($d[$k]) && trim((string)$d[$k]) !== '') return trim((string)$d[$k]);
    }
    return null;
}

$mapped = [];
foreach ($ALIASES as $col => $keys) {
    $mapped[$col] = firstMatch($d, $keys);
}

// Several forms (index.html, contact.html, the Akruti brochure form) send
// First Name + Last Name as two separate fields instead of one combined
// name — build the full name from those when a single "name" alias wasn't
// matched above.
if (!$mapped['name']) {
    $first = trim((string)($d['first_name'] ?? $d['firstName'] ?? ''));
    $last = trim((string)($d['last_name'] ?? $d['lastName'] ?? ''));
    $combined = trim("$first $last");
    if ($combined !== '') $mapped['name'] = $combined;
}

// Category: only meaningful for Antaaya Enquiry/Broker/Site-Visit forms;
// other forms (contact, booking, brochure) default to null — Entry Desk
// classifies these as Broker/Owner at review time instead.
$type = $d['clientType'] ?? ($d['user-type'] ?? '');
$mapped['category'] = $formType === 'broker' ? 'Broker'
    : ($type === 'Broker' || stripos((string)$type, 'agent') !== false || stripos((string)$type, 'channel partner') !== false ? 'Broker'
    : (in_array($formType, ['enquiry', 'sitevisit'], true) ? 'Owner' : null));

if (!$mapped['name'] && !$mapped['mobile']) {
    http_response_code(400);
    echo json_encode(['error' => 'At least a name or contact number is required']);
    exit;
}

// Human-readable note of anything submitted that isn't one of our fixed
// columns (e.g. message/enquiryDetails/feedback text) so the reviewer sees
// context without it being a structured field.
$knownKeys = array_merge(['site', 'form_type', 'clientType', 'user-type', 'first_name', 'firstName', 'last_name', 'lastName'], ...array_values($ALIASES));
$extraLines = [];
foreach ($d as $k => $v) {
    if (in_array($k, $knownKeys, true)) continue;
    if ($v === null || trim((string)$v) === '') continue;
    $extraLines[] = "$k: $v";
}
$notes = $extraLines ? implode(' | ', $extraLines) : null;

$stmt = $pdo->prepare(
    'INSERT INTO source_leads (site, form_type, name, mobile, alt_mobile, email, city, source, subsource, budget, config, purpose, category, notes, raw_data, status)
     VALUES (:site, :form_type, :name, :mobile, :alt_mobile, :email, :city, :source, :subsource, :budget, :config, :purpose, :category, :notes, :raw_data, "New")'
);
$stmt->execute([
    'site' => $site, 'form_type' => $formType,
    'name' => $mapped['name'], 'mobile' => $mapped['mobile'], 'alt_mobile' => $mapped['alt_mobile'],
    'email' => $mapped['email'], 'city' => $mapped['city'], 'source' => $mapped['source'],
    'subsource' => $mapped['subsource'], 'budget' => $mapped['budget'], 'config' => $mapped['config'],
    'purpose' => $mapped['purpose'], 'category' => $mapped['category'], 'notes' => $notes,
    'raw_data' => json_encode($d),
]);

$newSourceId = (int)$pdo->lastInsertId();
// Permanent record in the admin Notification Log — no client/salesperson
// yet, so both stay NULL; $label carries the name so the log reads sensibly
// before this is ever reviewed.
log_notification($pdo, 'source_new', null, null, null, $newSourceId, $mapped['name'] ?: '(no name given)');

echo json_encode(['success' => true, 'id' => $newSourceId]);
