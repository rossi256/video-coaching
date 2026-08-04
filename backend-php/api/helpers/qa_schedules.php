<?php
/**
 * WingCoach — Q&A reminder schedules (single source of truth)
 *
 * Used by:
 *   - admin.php  (validate the reminder_schedule chosen on create/update)
 *   - static/admin.html (the <select> labels mirror qaReminderScheduleLabels())
 *   - cron/qa-reminders.php (compute which offsets are due for each session)
 *
 * A "schedule" is an ordered list of offsets before scheduled_at. Each offset
 * has a stable `key` (logged in qa_reminder_log so re-runs never double-send)
 * and `hours` before the session start.
 */

function qaReminderSchedules(): array {
    return [
        'off'       => [],
        '24h'       => [
            ['key' => '24h', 'hours' => 24],
        ],
        '24h_1h'    => [
            ['key' => '24h', 'hours' => 24],
            ['key' => '1h',  'hours' => 1],
        ],
        '7d_24h_1h' => [
            ['key' => '7d',  'hours' => 168],
            ['key' => '24h', 'hours' => 24],
            ['key' => '1h',  'hours' => 1],
            // 0h = "we're live" mail, sent AT start time (15 min catch-up window)
            ['key' => 'live', 'hours' => 0],
        ],
    ];
}

/** Human labels for the admin <select>. Keys must match qaReminderSchedules(). */
function qaReminderScheduleLabels(): array {
    return [
        'off'       => 'Off — no reminders',
        '24h'       => '24 hours before',
        '24h_1h'    => '24 hours + 1 hour before',
        '7d_24h_1h' => '7 days + 24 hours + 1 hour before',
    ];
}

/** Normalise an incoming value to a valid schedule key, defaulting to 'off'. */
function qaNormalizeSchedule(?string $key): string {
    $key = trim((string) $key);
    return array_key_exists($key, qaReminderSchedules()) ? $key : 'off';
}

/** Friendly "starts in 24 hours" / "starts in 1 hour" phrasing for an offset key. */
function qaOffsetPhrase(string $offsetKey): string {
    switch ($offsetKey) {
        case '7d':  return 'in 7 days';
        case '24h': return 'in 24 hours';
        case '1h':  return 'in about an hour';
        case 'live': return 'right now';
        default:    return 'soon';
    }
}
