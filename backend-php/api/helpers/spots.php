<?php
/**
 * WingCoach — founding-spot accounting
 *
 * Single source of truth for "how many of the 10 spots are gone".
 *
 * Counted from the submissions table itself rather than the old
 * config.spots_taken counter, which drifted: it had been incremented by
 * Michi's own test purchases and by a 157 EUR generic Tricktionary invoice
 * that a pre-guard webhook mis-filed as a coaching submission, so the public
 * page advertised 6 spots left when 9 were genuinely available.
 *
 * A row opts out of the count with counts_toward_spots = 0.
 */

function getSpots(PDO $db): array {
    $total = (int) $db->query("SELECT value FROM config WHERE `key` = 'total_spots'")->fetchColumn();
    $taken = (int) $db->query('SELECT COUNT(*) FROM submissions WHERE counts_toward_spots = 1')->fetchColumn();

    return [
        'total'     => $total,
        'taken'     => $taken,
        'remaining' => max(0, $total - $taken),
    ];
}
