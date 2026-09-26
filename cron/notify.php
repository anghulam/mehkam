<?php
// cron/notify.php  —  مرسل تنبيهات تيليغرام (تذكير الجلسات + الملخّص اليومي)
// ──────────────────────────────────────────────────────────────
//  شغّله كل 20 دقيقة عبر cPanel → Cron Jobs. جدول مقترح:
//      0,20,40 * * * *   php /home/USER/public_html/cron/notify.php
//
//  أو عبر رابط (لو ما يدعم CLI):
//      0,20,40 * * * *   curl -s "https://نطاقك/cron/notify.php?key=CRON_SECRET"
//  (CRON_SECRET من: لوحة الإدارة ← إعدادات المنصة)
// ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/content_helper.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// أعمدة القنوات إن لزمت
foreach ([
    "ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(25) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN notify_whatsapp TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE users ADD COLUMN wa_callmebot_key VARCHAR(40) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE cases ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0",
] as $_wc) { try { $conn->query($_wc); } catch (\Throwable $e) {} }

$EMAIL_ON = (sc($conn, 'smtp_enabled', '0') === '1');
$SITE_NAME = sc($conn, 'site_name', 'مِحكام');

/** إرسال بريد تنبيه لمستخدم (لا شيء إن كان SMTP معطّلاً أو التنبيهات موقوفة) */
function ntf_mail($conn, $user, $title, $body_text) {
    global $EMAIL_ON, $SITE_NAME;
    if (!$EMAIL_ON) return false;
    if (empty($user['email'])) return false;
    if (isset($user['notify_email']) && !$user['notify_email']) return false;
    $html = mailHtml($SITE_NAME, $title, nl2br(htmlspecialchars($body_text, ENT_QUOTES, 'UTF-8')));
    $r = sendMail($conn, $user['email'], $user['full_name'] ?? '', $title, $html);
    return !empty($r['ok']);
}

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = tg_get($conn, 'cron_secret');
    if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403); echo 'forbidden'; exit;
    }
}

if (!tg_configured($conn) && !wa_configured($conn) && !$EMAIL_ON) { echo "no notification channel configured\n"; exit; }

// إنشاء جدول التتبّع إن لزم
try {
    $conn->query("CREATE TABLE IF NOT EXISTS notification_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        kind VARCHAR(30) NOT NULL,
        ref  VARCHAR(80) NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_notif (user_id, kind, ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$sent = 0;

/**
 * إرسال موحّد: تيليغرام + إشعار داخل المنصة، مع منع التكرار.
 */
function notify_user($conn, $user, $kind, $ref, $title, $body) {
    if (!tg_should_send($conn, $user['id'], $kind, $ref)) return false;
    // إشعار داخل المنصة
    try {
        $t = $conn->real_escape_string(mb_substr($title, 0, 300));
        $m = $conn->real_escape_string(mb_substr(strip_tags(str_replace(['<b>','</b>',"\n"], ['','',' — '], $body)), 0, 1000));
        $conn->query("INSERT INTO notifications (user_id,office_id,title,message,type)
            VALUES (" . (int)$user['id'] . "," . (int)$user['office_id'] . ",'$t','$m','info')");
    } catch (\Throwable $e) {}
    $ok = false;
    // تيليغرام
    if (!empty($user['telegram_chat_id']) && (!isset($user['notify_telegram']) || $user['notify_telegram'])) {
        $ok = tg_send($conn, $user['telegram_chat_id'], "<b>" . htmlspecialchars($title) . "</b>\n" . $body) || $ok;
    }
    // واتساب
    if (!empty($user['whatsapp_number']) && wa_configured($conn)) {
        $r = wa_send_user($conn, $user, "*" . wa_from_html($title) . "*\n" . wa_from_html($body));
        $ok = !empty($r['ok']) || $ok;
    }
    // بريد إلكتروني
    if (ntf_mail($conn, $user, $title, wa_from_html($body))) $ok = true;
    return $ok ?: true;
}

/** مستلمو تنبيهات قضية: المخصَّصون لها (+ الملّاك) أو كل المكتب إن لا تخصيص */
function case_recipients($conn, $case_id, $office_id) {
    $cid = (int)$case_id; $oid = (int)$office_id;
    $has = false;
    try { $r = $conn->query("SELECT 1 FROM case_assignments WHERE case_id=$cid LIMIT 1"); $has = $r && $r->num_rows; } catch (\Throwable $e) {}
    $cols = "id, office_id, full_name, email, telegram_chat_id, notify_telegram, whatsapp_number, notify_whatsapp, wa_callmebot_key, notify_email, calendar_pref";
    $reach = "(notify_telegram=1 OR notify_whatsapp=1 OR notify_email=1)";
    if ($has) {
        $sql = "SELECT $cols FROM users
                WHERE office_id=$oid AND is_active=1 AND $reach
                  AND (role='office_owner' OR id IN (SELECT user_id FROM case_assignments WHERE case_id=$cid))";
    } else {
        $sql = "SELECT $cols FROM users
                WHERE office_id=$oid AND is_active=1 AND $reach";
    }
    return $conn->query($sql);
}

/* ══ 1) تذكير الجلسات قبل موعدها ══ */
$leads = array_filter(array_map('intval', explode(',', tg_get($conn, 'telegram_lead_hours', '24,3'))));
if (!$leads) $leads = [24, 3];

foreach ($leads as $H) {
    if ($H < 1) continue;
    $kind = 'sess_' . $H . 'h';

    $rs = $conn->query("SELECT s.id sid, s.session_date, s.case_id, s.office_id, c.case_number, c.title
        FROM sessions s JOIN cases c ON s.case_id = c.id
        WHERE c.is_archived=0 AND s.status='scheduled' AND s.session_date > NOW() AND s.session_date <= DATE_ADD(NOW(), INTERVAL $H HOUR)");
    if ($rs) while ($s = $rs->fetch_assoc()) {
        $ref = 'session:' . $s['sid'];
        $left = $H >= 24 ? 'غداً' : ('بعد ~' . $H . ' ساعات');
        $rc = case_recipients($conn, $s['case_id'], $s['office_id']);
        if ($rc) while ($u = $rc->fetch_assoc()) {
            $when = tg_fmt_date($s['session_date'], $u['calendar_pref'] ?? 'gregorian');
            $body = "القضية: " . htmlspecialchars($s['case_number'] . ' — ' . mb_substr($s['title'], 0, 90)) . "\n"
                  . "الموعد: " . htmlspecialchars($when);
            if (notify_user($conn, $u, $kind, $ref, "⏰ تذكير بجلسة ($left)", $body)) $sent++;
        }
    }

    $days = (int)ceil($H / 24);
    $rc2 = $conn->query("SELECT c.id cid, c.next_session, c.office_id, c.case_number, c.title FROM cases c
        WHERE c.status='active' AND c.next_session IS NOT NULL AND c.next_session >= CURDATE()
          AND DATE(c.next_session) <= DATE_ADD(CURDATE(), INTERVAL $days DAY)
          AND NOT EXISTS (SELECT 1 FROM sessions s WHERE s.case_id=c.id AND DATE(s.session_date)=DATE(c.next_session))");
    if ($rc2) while ($c = $rc2->fetch_assoc()) {
        $ref = 'case:' . $c['cid'] . ':' . $c['next_session'];
        $rc = case_recipients($conn, $c['cid'], $c['office_id']);
        if ($rc) while ($u = $rc->fetch_assoc()) {
            $when = tg_fmt_date($c['next_session'] . ' 09:00:00', $u['calendar_pref'] ?? 'gregorian', false);
            $body = "القضية: " . htmlspecialchars($c['case_number'] . ' — ' . mb_substr($c['title'], 0, 90)) . "\n"
                  . "التاريخ: " . htmlspecialchars($when);
            if (notify_user($conn, $u, $kind, $ref, "⏰ جلسة قادمة", $body)) $sent++;
        }
    }
}

/* ══ 1-ب) تنبيه المهام المتأخرة ══ */
$ot = $conn->query("SELECT t.id, t.title, t.due_date, t.assigned_to_id,
        u.id uid, u.office_id, u.full_name, u.email, u.telegram_chat_id, u.notify_telegram, u.whatsapp_number, u.notify_whatsapp, u.wa_callmebot_key, u.notify_email, u.calendar_pref
    FROM tasks t JOIN users u ON u.id = t.assigned_to_id
    WHERE t.status IN('pending','in_progress') AND t.due_date < CURDATE()
      AND u.is_active=1 AND (u.notify_telegram=1 OR u.notify_whatsapp=1 OR u.notify_email=1)");
if ($ot) while ($t = $ot->fetch_assoc()) {
    $ref = 'task_overdue:' . $t['id'] . ':' . date('Y-m-d');
    $u = ['id'=>$t['uid'], 'office_id'=>$t['office_id'], 'full_name'=>$t['full_name'], 'email'=>$t['email'], 'telegram_chat_id'=>$t['telegram_chat_id'], 'notify_telegram'=>$t['notify_telegram'], 'whatsapp_number'=>$t['whatsapp_number'], 'notify_whatsapp'=>$t['notify_whatsapp'], 'wa_callmebot_key'=>$t['wa_callmebot_key'], 'notify_email'=>$t['notify_email'], 'calendar_pref'=>$t['calendar_pref']];
    $body = "المهمة: " . htmlspecialchars(mb_substr($t['title'], 0, 100)) . "\n"
          . "كان موعدها: " . htmlspecialchars(tg_fmt_date($t['due_date'] . ' 00:00:00', $t['calendar_pref'] ?? 'gregorian', false));
    if (notify_user($conn, $u, 'task_overdue', $ref, "⚠️ مهمة متأخرة", $body)) $sent++;
}

/* ══ 2) الملخّص اليومي ══ */
$digestHour = (int)tg_get($conn, 'telegram_digest_hour', '7');
if ((int)date('G') === $digestHour) {
    $today = date('Y-m-d');
    // الملخّص عبر تيليغرام/واتساب فقط (لتجنّب إغراق جرس الإشعارات كل صباح)
    $us = $conn->query("SELECT id, office_id, telegram_chat_id, whatsapp_number, wa_callmebot_key, email, notify_telegram, notify_whatsapp, notify_email, calendar_pref, full_name FROM users
        WHERE is_active=1
          AND ((notify_telegram=1 AND telegram_chat_id IS NOT NULL AND telegram_chat_id<>'')
            OR (notify_whatsapp=1 AND whatsapp_number IS NOT NULL AND whatsapp_number<>'')
            OR (notify_email=1 AND email IS NOT NULL AND email<>''))");
    if ($us) while ($u = $us->fetch_assoc()) {
        if (!tg_should_send($conn, $u['id'], 'digest', $today)) continue;
        $oid = (int)$u['office_id'];

        $lines = [];
        $sq = $conn->query("SELECT s.session_date, c.case_number, c.title
            FROM sessions s JOIN cases c ON s.case_id=c.id
            WHERE c.is_archived=0 AND s.office_id=$oid AND s.status='scheduled' AND DATE(s.session_date)='$today'
            ORDER BY s.session_date ASC");
        if ($sq) while ($s = $sq->fetch_assoc()) {
            $lines[] = '• ' . date('H:i', strtotime($s['session_date'])) . ' — '
                . htmlspecialchars($s['case_number'] . ' ' . mb_substr($s['title'], 0, 60));
        }
        $cq = $conn->query("SELECT case_number, title FROM cases
            WHERE office_id=$oid AND status='active' AND DATE(next_session)='$today'");
        if ($cq) while ($c = $cq->fetch_assoc()) {
            $lines[] = '• ' . htmlspecialchars($c['case_number'] . ' ' . mb_substr($c['title'], 0, 60));
        }
        $tq = $conn->query("SELECT title FROM tasks
            WHERE office_id=$oid AND status IN('pending','in_progress') AND DATE(due_date)='$today'");
        $tasks = [];
        if ($tq) while ($t = $tq->fetch_assoc()) $tasks[] = '• ' . htmlspecialchars(mb_substr($t['title'], 0, 70));

        if (!$lines && !$tasks) {
            $body = "لا جلسات ولا مهام مستحقة اليوم. يوم موفّق ✨";
        } else {
            $body = '';
            if ($lines) $body .= "🏛️ <b>جلسات اليوم:</b>\n" . implode("\n", $lines) . "\n\n";
            if ($tasks) $body .= "✅ <b>مهام اليوم:</b>\n" . implode("\n", $tasks);
        }
        $hd = tg_fmt_date($today . ' 00:00:00', $u['calendar_pref'] ?? 'gregorian', false);
        if (!empty($u['telegram_chat_id']) && !empty($u['notify_telegram'])) {
            tg_send($conn, $u['telegram_chat_id'], "☀️ <b>ملخّص اليوم</b> — " . htmlspecialchars($hd) . "\n\n" . trim($body));
        }
        if (!empty($u['whatsapp_number']) && !empty($u['notify_whatsapp']) && wa_configured($conn)) {
            wa_send_user($conn, $u, "☀️ *ملخّص اليوم* — " . $hd . "\n\n" . wa_from_html($body));
        }
        ntf_mail($conn, $u, "☀️ ملخّص اليوم — " . $hd, wa_from_html($body));
        $sent++;
    }
}

echo "done. sent=$sent\n";
