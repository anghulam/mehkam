<?php
require_once '../includes/functions.php';
require_once '../config/db.php';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="mehkam.ics"');

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$feed = $token !== '' ? $conn->query("SELECT * FROM calendar_feeds WHERE token='".$conn->real_escape_string($token)."' LIMIT 1")->fetch_assoc() : null;

function ics_escape($s) {
    return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], (string)$s);
}
function ics_dt($datetime) {
    $ts = strtotime($datetime);
    return $ts ? gmdate('Ymd\THis\Z', $ts) : '';
}

$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//Mehkam//Calendar Feed//AR';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'X-WR-CALNAME:مِحكام — جلساتي ومهامي';
$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT4H';

if ($feed) {
    $uid = (int)$feed['user_id'];
    $oid = (int)$feed['office_id'];
    $user = $conn->query("SELECT role, full_name FROM users WHERE id=$uid AND office_id=$oid LIMIT 1")->fetch_assoc();

    if ($user) {
        $restricted = !in_array($user['role'], ['office_owner','admin'], true);
        $caseScope = $restricted ? " AND c.id IN (SELECT case_id FROM case_assignments WHERE user_id=$uid)" : "";

        // الجلسات القادمة
        $sr = $conn->query("SELECT s.*, c.case_number, c.title case_title FROM sessions s JOIN cases c ON s.case_id=c.id
            WHERE s.office_id=$oid AND s.status='scheduled' AND s.session_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)$caseScope
            ORDER BY s.session_date LIMIT 500");
        if ($sr) while ($s = $sr->fetch_assoc()) {
            $start = ics_dt($s['session_date']);
            if (!$start) continue;
            $end = gmdate('Ymd\THis\Z', strtotime($s['session_date']) + 3600);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:mehkam-session-' . $s['id'] . '@mehkam.app';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $start;
            $lines[] = 'DTEND:' . $end;
            $lines[] = 'SUMMARY:' . ics_escape('جلسة: ' . $s['case_number'] . ' — ' . $s['case_title']);
            if ($s['description']) $lines[] = 'DESCRIPTION:' . ics_escape($s['description']);
            $lines[] = 'END:VEVENT';
        }

        // المهام المستحقة
        $taskScope = $restricted ? " AND (assigned_to_id=$uid OR (assigned_to_id IS NULL AND assigned_to='".$conn->real_escape_string($user['full_name'])."'))" : "";
        $tr = $conn->query("SELECT * FROM tasks WHERE office_id=$oid AND status IN ('pending','in_progress') AND due_date IS NOT NULL$taskScope ORDER BY due_date LIMIT 500");
        if ($tr) while ($t = $tr->fetch_assoc()) {
            $start = ics_dt($t['due_date']);
            if (!$start) continue;
            $end = gmdate('Ymd\THis\Z', strtotime($t['due_date']) + 1800);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:mehkam-task-' . $t['id'] . '@mehkam.app';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $start;
            $lines[] = 'DTEND:' . $end;
            $lines[] = 'SUMMARY:' . ics_escape('مهمة: ' . $t['title']);
            if ($t['description']) $lines[] = 'DESCRIPTION:' . ics_escape($t['description']);
            $lines[] = 'END:VEVENT';
        }
    }
}

$lines[] = 'END:VCALENDAR';
echo implode("\r\n", $lines);
