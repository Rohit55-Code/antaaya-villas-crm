<?php
// api/legal_docs_lib.php — Booking & Legal document catalog + mandatory-document
// gate. Constants/functions only (no auth, no output) so index.php can also
// include it to hand the same catalog to app.js (window.LEGAL_DOCS).
require_once __DIR__ . '/sv_followup_lib.php';

// Every uploadable document type. 'stage' = which Booking & Legal checkpoint shows it.
const LEGAL_DOC_TYPES = [
    // Booking Initiated
    'booking_form'      => ['label' => 'Booking Application Form (signed)', 'stage' => 'Booking Initiated'],
    'booking_receipt'   => ['label' => 'Booking Amount Receipt', 'stage' => 'Booking Initiated'],
    'cost_sheet'        => ['label' => 'Cost Sheet (signed)', 'stage' => 'Booking Initiated'],
    // KYC Verification — primary applicant (which of these apply depends on applicant type)
    'pan'               => ['label' => 'Applicant PAN Card', 'stage' => 'KYC Verification'],
    'aadhaar'           => ['label' => 'Applicant Aadhaar Card', 'stage' => 'KYC Verification'],
    'photo'             => ['label' => 'Applicant Passport-size Photo', 'stage' => 'KYC Verification'],
    'address_proof'     => ['label' => 'Address Proof (if different from Aadhaar)', 'stage' => 'KYC Verification'],
    'passport'          => ['label' => 'Passport', 'stage' => 'KYC Verification'],
    'visa_oci'          => ['label' => 'Visa / OCI / PIO Card', 'stage' => 'KYC Verification'],
    'overseas_address'  => ['label' => 'Overseas Address Proof', 'stage' => 'KYC Verification'],
    'entity_pan'        => ['label' => 'Company / Firm PAN', 'stage' => 'KYC Verification'],
    'incorporation_cert' => ['label' => 'Certificate of Incorporation / Registration', 'stage' => 'KYC Verification'],
    'board_resolution'  => ['label' => 'Board Resolution / Authority Letter', 'stage' => 'KYC Verification'],
    'signatory_pan'     => ['label' => 'Authorised Signatory PAN', 'stage' => 'KYC Verification'],
    'signatory_aadhaar' => ['label' => 'Authorised Signatory Aadhaar', 'stage' => 'KYC Verification'],
    'huf_pan'           => ['label' => 'HUF PAN', 'stage' => 'KYC Verification'],
    'huf_declaration'   => ['label' => 'HUF Declaration / Deed', 'stage' => 'KYC Verification'],
    // KYC Verification — co-applicant (only when a co-applicant name is filled in)
    'co_pan'            => ['label' => 'Co-applicant PAN Card', 'stage' => 'KYC Verification'],
    'co_aadhaar'        => ['label' => 'Co-applicant Aadhaar Card', 'stage' => 'KYC Verification'],
    'co_photo'          => ['label' => 'Co-applicant Photo', 'stage' => 'KYC Verification'],
    // Handover Completed (Possession tab) — moved here from Booking Confirmed
    'allotment_letter'  => ['label' => 'Allotment Letter', 'stage' => 'Handover Completed'],
    // Agreement In Process
    'draft_agreement'   => ['label' => 'Draft Agreement for Sale', 'stage' => 'Agreement In Process'],
    'stamp_duty_receipt' => ['label' => 'Stamp Duty Receipt (GRAS Challan)', 'stage' => 'Agreement In Process'],
    'registration_fee_receipt' => ['label' => 'Registration Fee Receipt', 'stage' => 'Agreement In Process'],
    // Registered
    'registered_agreement' => ['label' => 'Registered Agreement for Sale', 'stage' => 'Registered'],
    'index_ii'          => ['label' => 'Index II', 'stage' => 'Registered'],
    // Construction & Payment / Possession (Accounts desk, migration_v37) — all optional
    'architect_certificate' => ['label' => "Architect's Stage Completion Certificate", 'stage' => 'Plinth Completed'],
    'site_photos'       => ['label' => 'Site Progress Photos', 'stage' => 'Plinth Completed'],
    'demand_letter_signed' => ['label' => 'Demand Letter (signed copy)', 'stage' => 'Construction Customer'],
    'oc_certificate'    => ['label' => 'Occupancy / Completion Certificate (PMRDA)', 'stage' => 'Possession Due'],
    'possession_letter' => ['label' => 'Possession Letter', 'stage' => 'Possession Offered'],
    'payment_proof'     => ['label' => 'Payment Proof', 'stage' => 'Construction Customer'],
    // Any stage
    'other'             => ['label' => 'Other Document', 'stage' => '*'],
];
// Construction checkpoints — each shows the same optional set next to its payment demand.
const ACCOUNTS_MILESTONE_DOCS = ['architect_certificate', 'site_photos', 'demand_letter_signed'];
// Construction stages whose documents are kept stage by stage (Construction & Payment → Documents).
// Possession Due = the final "OC / CC Received" demand (its OC certificate goes along with that demand email).
const ACCOUNTS_MILESTONE_STAGES = ['Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed',
    'Brickwork Completed', 'Plaster & Flooring Completed', 'Fittings Completed', 'Possession Due'];

// Non-KYC checkpoints: which slots show, which are mandatory to move past it.
const LEGAL_STAGE_DOCS = [
    'Booking Initiated'    => ['show' => ['booking_form', 'booking_receipt', 'cost_sheet'], 'required' => ['booking_form', 'booking_receipt']],
    'Handover Completed'   => ['show' => ['allotment_letter'], 'required' => ['allotment_letter']],
    'Agreement In Process' => ['show' => ['draft_agreement', 'stamp_duty_receipt', 'registration_fee_receipt'], 'required' => ['stamp_duty_receipt', 'registration_fee_receipt']],
    'Registered'           => ['show' => ['registered_agreement', 'index_ii'], 'required' => ['registered_agreement', 'index_ii']],
    // Accounts desk (nothing mandatory — the payment demand is what gates these checkpoints)
    'Construction Customer'        => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'Plinth Completed'             => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'First Slab Completed'         => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'Second Slab Completed'        => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'Brickwork Completed'          => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'Plaster & Flooring Completed' => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'Fittings Completed'           => ['show' => ACCOUNTS_MILESTONE_DOCS, 'required' => []],
    'Possession Due'               => ['show' => ['oc_certificate', 'demand_letter_signed'], 'required' => []],
    'Possession Offered'           => ['show' => ['possession_letter'], 'required' => []],
];

// KYC Verification slots per applicant type (first entry = default when not set).
const LEGAL_KYC_BY_TYPE = [
    'Resident Individual'  => ['show' => ['pan', 'aadhaar', 'photo', 'address_proof'], 'required' => ['pan', 'aadhaar', 'photo']],
    'NRI'                  => ['show' => ['pan', 'passport', 'visa_oci', 'overseas_address', 'photo', 'aadhaar'], 'required' => ['pan', 'passport', 'visa_oci', 'overseas_address', 'photo']],
    'Company / LLP / Firm' => ['show' => ['entity_pan', 'incorporation_cert', 'board_resolution', 'signatory_pan', 'signatory_aadhaar', 'photo'], 'required' => ['entity_pan', 'incorporation_cert', 'board_resolution', 'signatory_pan', 'signatory_aadhaar', 'photo']],
    'HUF'                  => ['show' => ['huf_pan', 'huf_declaration', 'pan', 'aadhaar', 'photo'], 'required' => ['huf_pan', 'huf_declaration', 'pan', 'aadhaar', 'photo']],
];
const LEGAL_CO_DOCS = ['co_pan', 'co_aadhaar', 'co_photo'];

// Checkpoints a lead can't move past until their mandatory documents are uploaded
// (KYC Verification additionally needs KYC Status = Verified). Pipeline order.
// Booking Confirmed has no mandatory document any more (Allotment Letter moved to
// Handover Completed — the last stage, so there's nothing after it to gate).
const LEGAL_GATED_STAGES = ['Booking Initiated', 'KYC Verification', 'Agreement In Process', 'Registered'];

// Every checkpoint that shows a Documents block (incl. "Other Documents" uploads).
const LEGAL_DOC_STAGES = ['Booking Initiated', 'KYC Verification', 'Booking Confirmed', 'Agreement In Process', 'Registered',
    'Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed', 'Brickwork Completed',
    'Plaster & Flooring Completed', 'Fittings Completed', 'Possession Due', 'Possession Offered', 'Handover Completed'];

// [ ['type'=>..., 'label'=>..., 'required'=>bool], ... ] for one checkpoint.
function legal_doc_slots($stage, $applicantType, $hasCo) {
    if ($stage === 'KYC Verification') {
        $cfg = LEGAL_KYC_BY_TYPE[$applicantType] ?? LEGAL_KYC_BY_TYPE['Resident Individual'];
        $show = $cfg['show']; $req = $cfg['required'];
        if ($hasCo) { $show = array_merge($show, LEGAL_CO_DOCS); $req = array_merge($req, LEGAL_CO_DOCS); }
    } elseif (isset(LEGAL_STAGE_DOCS[$stage])) {
        $show = LEGAL_STAGE_DOCS[$stage]['show']; $req = LEGAL_STAGE_DOCS[$stage]['required'];
    } else {
        return [];
    }
    return array_map(fn($t) => ['type' => $t, 'label' => LEGAL_DOC_TYPES[$t]['label'], 'required' => in_array($t, $req, true)], $show);
}

// Returns an error string if this save would move the lead forward past a
// gated checkpoint whose requirements aren't met, else null. Only forward
// moves are checked — leads already past a checkpoint are never re-blocked.
function legal_gate_error($pdo, $clientId, $oldStage, $newStage, $applicantType, $hasCo, $kycStatus) {
    $newIdx = array_search($newStage, FOLLOWUP_PIPELINE, true);
    if ($newIdx === false) return null; // Closed / Cancelled / early stages
    $oldIdx = array_search($oldStage, FOLLOWUP_PIPELINE, true);
    if ($oldIdx === false) $oldIdx = -1;

    $uploaded = null;
    foreach (LEGAL_GATED_STAGES as $g) {
        $gIdx = array_search($g, FOLLOWUP_PIPELINE, true);
        if (!($oldIdx <= $gIdx && $newIdx > $gIdx)) continue;
        if ($uploaded === null) {
            try {
                $st = $pdo->prepare('SELECT DISTINCT doc_type FROM client_documents WHERE client_id = ?');
                $st->execute([$clientId]);
                $uploaded = $st->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                return 'Documents table missing — run migration_v31.sql in phpMyAdmin first.';
            }
        }
        $missing = [];
        foreach (legal_doc_slots($g, $applicantType, $hasCo) as $s) {
            if ($s['required'] && !in_array($s['type'], $uploaded, true)) $missing[] = $s['label'];
        }
        if ($g === 'KYC Verification' && $kycStatus !== 'Verified') $missing[] = 'KYC Status = Verified';
        if ($missing) return "Can't move past \"$g\" yet — still needed: " . implode(', ', $missing) . '.';
    }
    return null;
}

// Catalog handed to app.js so the UI uses the exact same rules.
function legal_docs_js_config() {
    return [
        'types' => array_map(fn($t) => $t['label'], LEGAL_DOC_TYPES),
        'stageDocs' => LEGAL_STAGE_DOCS,
        'kycByType' => LEGAL_KYC_BY_TYPE,
        'coDocs' => LEGAL_CO_DOCS,
        'gated' => LEGAL_GATED_STAGES,
    ];
}
