<?php
// config/site.php — the ONE site location used everywhere:
//  - calendar invite (api/visit_invite.php -> event LOCATION)
//  - WhatsApp visit message (index.php passes it to the browser)
// Use a place name / address, or paste a Google Maps link (Share -> Copy link).

const VISIT_LOCATION = 'Antaaya Villas, Lonavala';

// ---- Accounts desk: payment demand letters, receipts, demand emails / WhatsApp ----
// Printed on every demand letter and payment receipt. Fill in the blanks once.
const COMPANY_NAME    = 'Akruti Developer Ltd.';
const PROJECT_NAME    = 'Antaaya Villas, Lonavala';
const COMPANY_ADDRESS = '';   // registered / site office address, one line
const MAHARERA_NO     = '';   // project MahaRERA registration no. (shown on letters if filled)
const ACCOUNTS_PHONE  = '';   // Accounts team contact number for clients
// Address of the CRM folder used in the letter-PDF link sent on WhatsApp (letter.php?t=…).
// Leave empty to work it out automatically (e.g. https://antaayavillas.com/crm).
const CRM_PUBLIC_URL  = '';
// Collection account (the project's RERA designated account) printed on demand letters,
// the demand email and the WhatsApp message. Leave 'account' empty to hide bank details.
const PAY_BANK = [
    'name'    => '',  // account holder, e.g. "Akruti Developer Ltd. – Antaaya Villas Collection A/c"
    'bank'    => '',  // e.g. "HDFC Bank"
    'account' => '',  // account number
    'ifsc'    => '',
    'branch'  => '',
];
