<?php
// api/reassign_lib.php — shared "carry the lead over as-is" logic for every
// reassignment path (Reassign button, cross-desk transfer confirm, admin
// changing Sales Person in Edit Lead).
//
// The lead's stage and every filled-in field are NEVER touched here — the new
// owner picks the lead up exactly where it currently is. What used to be left
// behind was the lead's follow-ups (followups.salesperson_id stayed on the old
// owner), so the new owner couldn't see or complete them and the old owner
// kept seeing them. This moves them over too, and logs the handover (with the
// carried-over stage) in the lead's Lead History.

function carry_over_reassigned_lead($pdo, $clientId, $newSalespersonId, $fromName = null, $toName = null, $byName = null) {
    $pdo->prepare('UPDATE followups SET salesperson_id = ? WHERE client_id = ?')
        ->execute([$newSalespersonId, $clientId]);

    if ($fromName === null && $toName === null) return; // follow-ups only (Lead History written by the caller's own form save)

    $st = $pdo->prepare('SELECT stage, activity_log FROM clients WHERE id = ?');
    $st->execute([$clientId]);
    $row = $st->fetch();
    if (!$row) return;
    $log = json_decode($row['activity_log'] ?: '[]', true);
    if (!is_array($log)) $log = [];
    $log[] = [
        'date' => date('Y-m-d'), 'time' => date('H:i'), 'type' => 'Reassigned', 'by' => $byName,
        'note' => "Reassigned from {$fromName} to {$toName}" . ($byName ? " by {$byName}" : '')
            . " — continues from current stage: {$row['stage']}",
    ];
    $pdo->prepare('UPDATE clients SET activity_log = ? WHERE id = ?')
        ->execute([json_encode($log, JSON_UNESCAPED_UNICODE), $clientId]);
}
