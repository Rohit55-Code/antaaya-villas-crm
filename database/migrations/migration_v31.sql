-- migration_v31.sql — run ONCE in phpMyAdmin, after migration_v30.sql.
--
-- Edit Client pipeline split: "Booking & Payment" + "Possession" become
--   Booking & Legal        : Booking Initiated, KYC Verification (NEW), Booking Confirmed,
--                            Agreement In Process, Registered
--   Construction & Payment : Construction Customer, Payment In Progress (moved here)
--   Possession             : Possession Due, Possession Offered, Handover Completed
-- Stage names are stored as plain text in clients.stage, so no existing
-- stage value changes — only new columns + the documents table are added.

-- Booking Initiated
ALTER TABLE clients ADD COLUMN booking_payment_mode VARCHAR(30) NULL;
ALTER TABLE clients ADD COLUMN booking_txn_ref VARCHAR(100) NULL;
ALTER TABLE clients ADD COLUMN booking_form_status VARCHAR(20) NULL;

-- KYC Verification
ALTER TABLE clients ADD COLUMN applicant_type VARCHAR(40) NULL;
ALTER TABLE clients ADD COLUMN applicant_pan VARCHAR(10) NULL;
ALTER TABLE clients ADD COLUMN applicant_aadhaar_last4 VARCHAR(4) NULL;
ALTER TABLE clients ADD COLUMN applicant_dob DATE NULL;
ALTER TABLE clients ADD COLUMN applicant_occupation VARCHAR(100) NULL;
ALTER TABLE clients ADD COLUMN applicant_address TEXT NULL;
ALTER TABLE clients ADD COLUMN co_applicant_name VARCHAR(150) NULL;
ALTER TABLE clients ADD COLUMN co_applicant_relation VARCHAR(50) NULL;
ALTER TABLE clients ADD COLUMN co_applicant_pan VARCHAR(10) NULL;
ALTER TABLE clients ADD COLUMN co_applicant_aadhaar_last4 VARCHAR(4) NULL;
ALTER TABLE clients ADD COLUMN kyc_status VARCHAR(20) NULL;
ALTER TABLE clients ADD COLUMN kyc_verified_date DATE NULL;
ALTER TABLE clients ADD COLUMN kyc_note TEXT NULL;

-- Booking Confirmed
ALTER TABLE clients ADD COLUMN allotment_letter VARCHAR(20) NULL;
ALTER TABLE clients ADD COLUMN allotment_date DATE NULL;

-- Agreement In Process
ALTER TABLE clients ADD COLUMN draft_agreement_date DATE NULL;
ALTER TABLE clients ADD COLUMN stamp_duty_amount DECIMAL(14,2) NULL;
ALTER TABLE clients ADD COLUMN registration_fee DECIMAL(14,2) NULL;
ALTER TABLE clients ADD COLUMN stamp_duty_paid VARCHAR(10) NULL;

-- Registered
ALTER TABLE clients ADD COLUMN registration_doc_no VARCHAR(100) NULL;
ALTER TABLE clients ADD COLUMN sub_registrar_office VARCHAR(150) NULL;

-- Construction & Payment (basic — construction-linked payment demands come later)
ALTER TABLE clients ADD COLUMN payment_plan VARCHAR(40) NULL;
ALTER TABLE clients ADD COLUMN construction_milestone VARCHAR(60) NULL;
ALTER TABLE clients ADD COLUMN construction_update_date DATE NULL;
ALTER TABLE clients ADD COLUMN last_payment_date DATE NULL;
ALTER TABLE clients ADD COLUMN loan_bank VARCHAR(100) NULL;

-- Uploaded booking/legal documents (files live in /uploads/client_docs/<client_id>/,
-- served only through api/client_documents.php — never directly).
CREATE TABLE IF NOT EXISTS client_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    doc_type VARCHAR(40) NOT NULL,
    doc_label VARCHAR(191) NULL,
    stage VARCHAR(50) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    size INT NULL,
    uploaded_by INT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_doc (client_id, doc_type),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);
