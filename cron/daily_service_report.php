<?php
/**
 * cron/daily_service_report.php
 * تقرير الخدمات الرقمية اليومي — يُرسَل PDF لبريد مالك كل مكتب.
 *
 *  جدولة مقترحة (cPanel → Cron Jobs) الساعة 23:59:
 *      59 23 * * *   curl -s "https://mehkam.app/cron/daily_service_report.php?key=CRON_SECRET"
 *  (CRON_SECRET من: لوحة الإدارة ← إعدادات المنصة)
 *
 *  للاختبار الفوري: أضِف &force=1
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/content_helper.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/pdf.php';

$cli   = (php_sapi_name() === 'cli');
$force = isset($_GET['force']);

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = sc($conn, 'cron_secret', '');
    if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}

// نشتغل قرب منتصف الليل فقط (ما لم يُطلب التجاوز)
if (!$force && !$cli && date('H:i') < '23:00') {
    echo "not yet\n";
    exit;
}
if (sc($conn, 'smtp_enabled', '0') !== '1') { echo "smtp disabled\n"; exit; }

// جدول أعلام الإرسال (لمنع التكرار)
$conn->query("CREATE TABLE IF NOT EXISTS site_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$today = date('Y-m-d');
$navy  = '#0c1b36';
$nf = fn($v) => number_format((float) $v, 2);

// المكاتب التي تستخدم الميزة
$offices = [];
$res = $conn->query("SELECT DISTINCT office_id FROM office_services
    UNION SELECT DISTINCT office_id FROM service_requests");
if ($res) while ($r = $res->fetch_assoc()) $offices[] = (int) $r['office_id'];

$sent = 0; $skipped = 0;

foreach ($offices as $oid) {
    if (!$oid) continue;

    // علم منع التكرار
    if (!$force) {
        $flag = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='svc_rpt_$oid' LIMIT 1");
        if ($flag && ($fr = $flag->fetch_assoc()) && $fr['setting_value'] === $today) { $skipped++; continue; }
    }

    $office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
    if (!$office) continue;
    $settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];
    $owner    = $conn->query("SELECT email, full_name FROM users
        WHERE office_id=$oid AND role='office_owner' AND is_active=1 AND email<>'' LIMIT 1")->fetch_assoc();
    if (!$owner || empty($owner['email'])) { $skipped++; continue; }

    $rows = [];
    $rr = $conn->query("SELECT sr.*, cl.full_name client_name, cl.id_number client_idn, c.case_number
        FROM service_requests sr
        LEFT JOIN clients cl ON sr.client_id = cl.id
        LEFT JOIN cases c    ON sr.case_id  = c.id
        WHERE sr.office_id=$oid AND DATE(sr.created_at)=CURDATE()
        ORDER BY sr.created_at ASC");
    if ($rr) while ($x = $rr->fetch_assoc()) $rows[] = $x;

    $s_lbl = ['new' => 'جديد', 'in_progress' => 'قيد التنفيذ', 'completed' => 'مكتمل', 'cancelled' => 'ملغى'];
    $sumA = 0; $sumV = 0; $sumT = 0;

    ob_start(); ?>
    <style>
      body { font-family: amiri; font-size: 10pt; color: #22303f; }
      .h1 { text-align:center; font-size:16pt; font-weight:bold; color:<?= $navy ?>; }
      .sub { text-align:center; color:#6b7280; font-size:9pt; margin-bottom:10px; }
    </style>
    <br>
    <div class="h1">تقرير الخدمات الرقمية اليومي</div>
    <div class="sub"><?= e($office['name']) ?> &nbsp;•&nbsp; <span dir="ltr"><?= e($today) ?></span></div>
    <br>
    <?php if (!$rows): ?>
      <div style="text-align:center;padding:30px;border:0.5pt solid #dde3ec;color:#94a3b8">لا توجد طلبات اليوم</div>
    <?php else: ?>
    <table width="100%" cellpadding="6" cellspacing="0">
      <tr>
        <th width="5%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">#</th>
        <th width="11%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">الوقت</th>
        <th width="22%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">العميل</th>
        <th width="22%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">الخدمة</th>
        <th width="10%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">المبلغ</th>
        <th width="9%"  style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">الضريبة</th>
        <th width="11%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">الإجمالي</th>
        <th width="10%" style="background-color:<?= $navy ?>;color:#fff;font-size:8pt">الحالة</th>
      </tr>
      <?php foreach ($rows as $i => $r):
        $sumA += $r['amount']; $sumV += $r['vat_amount']; $sumT += $r['total']; ?>
      <tr>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt;text-align:center;color:#94a3b8"><?= $i + 1 ?></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt;text-align:center" dir="ltr"><?= e(date('H:i', strtotime($r['created_at']))) ?></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt"><?= e($r['client_name'] ?? '—') ?><br><span dir="ltr" style="color:#94a3b8;font-size:7pt"><?= e($r['client_idn'] ?? '') ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt"><?= e($r['service_name']) ?><?= $r['case_number'] ? '<br><span style="color:#94a3b8;font-size:7pt">قضية ' . e($r['case_number']) . '</span>' : '' ?></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt;text-align:left"><span dir="ltr"><?= $nf($r['amount']) ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt;text-align:left"><span dir="ltr"><?= $nf($r['vat_amount']) ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt;text-align:left;font-weight:bold"><span dir="ltr"><?= $nf($r['total']) ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;font-size:8pt;text-align:center"><?= e($s_lbl[$r['status']] ?? $r['status']) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr>
        <td colspan="4" style="border:0.4pt solid #e1e5ec;background-color:#f4f6fa;font-weight:bold;font-size:8.5pt">الإجمالي — <span dir="ltr"><?= count($rows) ?></span> طلب</td>
        <td style="border:0.4pt solid #e1e5ec;background-color:#f4f6fa;font-weight:bold;text-align:left"><span dir="ltr"><?= $nf($sumA) ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;background-color:#f4f6fa;font-weight:bold;text-align:left"><span dir="ltr"><?= $nf($sumV) ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;background-color:#f4f6fa;font-weight:bold;text-align:left"><span dir="ltr"><?= $nf($sumT) ?></span></td>
        <td style="border:0.4pt solid #e1e5ec;background-color:#f4f6fa"></td>
      </tr>
    </table>
    <?php endif; ?>
    <br>
    <div style="color:#7a8698;font-size:7.5pt;border-top:0.5pt solid #dde3ec;padding-top:5px">
      تقرير آلي من نظام مِحكام — تاريخ الإصدار <span dir="ltr"><?= e(date('Y-m-d H:i')) ?></span>. جميع المبالغ بالريال السعودي.
    </div>
    <?php
    $html = ob_get_clean();

    $fb = '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1.5pt solid ' . $navy . '">'
        . '<tr><td style="font-size:13pt;font-weight:bold;color:' . $navy . '">' . e($office['name']) . '</td>'
        . '<td style="text-align:left;font-size:8pt;color:#6b7686">تقرير الخدمات الرقمية · ' . e($today) . '</td></tr></table>';

    try {
        $pdfBytes = mehkam_make_pdf($settings, $html, "تقرير-الخدمات-$today.pdf", [
            'dest'  => 'S',
            'title' => 'تقرير الخدمات الرقمية — ' . $today,
            'size'  => 10,
            'header_fallback_html' => $fb,
        ]);
    } catch (\Throwable $e) {
        echo "office $oid: pdf error — " . $e->getMessage() . "\n";
        continue;
    }

    $emailHtml = mailHtml(
        sc($conn, 'site_name', 'مِحكام'),
        'تقرير الخدمات الرقمية — ' . $today,
        '<p>مرفق تقرير طلبات الخدمات الرقمية لمكتب <strong>' . e($office['name']) . '</strong> ليوم ' . e($today) . '.</p>'
        . '<p>عدد الطلبات: <strong>' . count($rows) . '</strong>' . ($rows ? ' — الإجمالي: <strong>' . $nf($sumT ?? 0) . ' ﷼</strong>' : '') . '</p>',
        'تقرير آلي يومي',
        function_exists('mail_logo_abs') ? mail_logo_abs($conn) : ''
    );

    $r = sendMail($conn, $owner['email'], $owner['full_name'] ?? '', 'تقرير الخدمات الرقمية — ' . $today, $emailHtml, [
        ['name' => "تقرير-الخدمات-$today.pdf", 'data' => $pdfBytes, 'mime' => 'application/pdf'],
    ]);

    if (!empty($r['ok'])) {
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('svc_rpt_$oid','$today')
            ON DUPLICATE KEY UPDATE setting_value='$today'");
        $sent++;
        echo "office $oid: sent to {$owner['email']}\n";
    } else {
        echo "office $oid: send failed — " . ($r['error'] ?? '') . "\n";
    }
}

echo "done — sent=$sent skipped=$skipped\n";
