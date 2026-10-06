<?php
// api/notif_log_lib.php — shared helper for writing to the assignment_log
// table, which (since migration_v30.sql) is the single, permanent,
// admin-only Notification Log: every lead assignment/reassignment AND every
// source-lead event (new submission, assigned, rejected). Salespeople's own
// bell notifications still come from a subset of the same table
// (assignment_log.php's default GET, unaffected by this).
// $type one of: assigned, reassigned, source_new, source_assigned, source_rejected,
// reschedule_proposed, post_sales_transfer (to = null → Legal desk), accounts_transfer
// (to = null → Accounts desk; Legal → Accounts handover at Registered),
// booking_cancelled (to = the lead's salesperson; admins see it too).
// $clientId / $toUserId may be null (a brand-new source lead has neither yet).
// $sourceLeadId links back to source_leads for the site/form_type shown in the log.
// $label is a plain-text fallback name shown when there's no client row to join (yet).
function log_notification($pdo, string $type, $fromUserId, $toUserId, $clientId, $sourceLeadId, $label) {
    try {
        $pdo->prepare(
            'INSERT INTO assignment_log (client_id, source_lead_id, from_user_id, to_user_id, type, label)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$clientId, $sourceLeadId, $fromUserId, $toUserId, $type, $label]);
    } catch (PDOException $e) {
        // migration_v30.sql not run yet — skip logging rather than break the
        // action that triggered it (same fail-soft pattern used elsewhere).
    }
}
