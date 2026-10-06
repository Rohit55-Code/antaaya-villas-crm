-- migration_v14.sql
-- Run this once in phpMyAdmin against the live database.
-- Adds the new per-checkpoint detail fields used by the redesigned Edit
-- Client modal (fields are now grouped and locked by lead stage).

ALTER TABLE clients
  ADD COLUMN budget_confirmed VARCHAR(20) NULL AFTER budget,
  ADD COLUMN decision_maker VARCHAR(10) NULL AFTER budget_confirmed,
  ADD COLUMN call_attempt_result VARCHAR(30) NULL AFTER firstcall_date,
  ADD COLUMN attempt_time TIME NULL AFTER call_attempt_result,
  ADD COLUMN brochure_date DATE NULL AFTER nextaction,
  ADD COLUMN brochure_mode VARCHAR(30) NULL AFTER brochure_date,
  ADD COLUMN visit_pickup VARCHAR(10) NULL AFTER visit_villa,
  ADD COLUMN revisit_time TIME NULL AFTER revisit_date,
  ADD COLUMN revisit_outcome VARCHAR(50) NULL AFTER revisit_time,
  ADD COLUMN offered_price VARCHAR(50) NULL AFTER visit_feedback,
  ADD COLUMN negotiation_notes TEXT NULL AFTER offered_price,
  ADD COLUMN villa_selected VARCHAR(50) NULL AFTER villa,
  ADD COLUMN block_date DATE NULL AFTER villa_selected,
  ADD COLUMN token_amount DECIMAL(14,2) DEFAULT 0 AFTER block_date,
  ADD COLUMN confirmation_date DATE NULL AFTER salevalue,
  ADD COLUMN agreement_date DATE NULL AFTER agreement,
  ADD COLUMN agreement_notes TEXT NULL AFTER agreement_date,
  ADD COLUMN registration_date DATE NULL AFTER registration,
  ADD COLUMN expected_possession_date DATE NULL AFTER fitout;
