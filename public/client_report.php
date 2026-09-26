<?php
/** public/client_report.php — تقرير دوري للعميل (بدون تسجيل دخول) */
require_once '../config/db.php';

$tok = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');
$rep = null;
try {
    if (strlen($tok) >= 32) {
        $stmt = $conn->prepare("SELECT * FROM client_reports WHERE token=? LIMIT 1");
        $stmt->bind_param('s', $tok);
        $stmt->execute();
        $rep = $stmt->get_result()->fetch_assoc();
    }
} catch (\Throwable $e) { $rep = null; }

if (!$rep) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Tahoma;text-align:center;margin-top:80px;color:#64748b">الرابط غير صحيح.</div>';
    exit;
}
$oid = (int)$rep['office_id']; $cid = (int)$rep['client_id'];
$office = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc() ?: [];
$client = $conn->query("SELECT full_name FROM clients WHERE id=$cid")->fetch_assoc() ?: [];
$from = $rep['period_from']; $to = $rep['period_to'];

$cases = [];
$cr = $conn->query("SELECT id, case_number, title, status, case_type, next_session FROM cases WHERE office_id=$oid AND client_id=$cid
    AND created_at <= '$to 23:59:59' ORDER BY id DESC");
if ($cr) while ($x = $cr->fetch_assoc()) $cases[] = $x;

$sessions_count = 0; $case_ids = array_map(fn($c) => (int)$c['id'], $cases);
if ($case_ids) {
    $ids = implode(',', $case_ids);
    $sessions_count = (int)$conn->query("SELECT COUNT(*) c FROM sessions WHERE case_id IN ($ids) AND session_date BETWEEN '$from' AND '$to 23:59:59'")->fetch_assoc()['c'];
}
$_stMap = ['active'=>'نشطة','closed'=>'مغلقة','suspended'=>'موقوفة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوّاة'];
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>تقرير <?= h($client['full_name'] ?? '') ?> — <?= h($office['name'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#f3f5f9;font-family:Tajawal,Tahoma,sans-serif;color:#0c1b36}
.w{max-width:720px;margin:0 auto;padding:16px}
.hd{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border-radius:16px;padding:22px}
.hd small{color:#c9a227;font-weight:700}.hd h1{margin:6px 0 4px;font-size:20px}.hd p{margin:0;font-size:13px;opacity:.85}
.kpis{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.kpi{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;flex:1 1 100px;text-align:center}
.kpi b{display:block;font-size:20px}
.card{background:#fff;border:1px solid #e6e9f0;border-radius:14px;padding:14px 16px;margin-top:12px}
.badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:#eef2fb;color:#1e3a8a}
.ft{text-align:center;color:#94a3b8;font-size:11px;margin:20px 0}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:8px 4px;border-bottom:1px solid #eef1f6;text-align:right}
</style></head><body><div class="w">
<div class="hd"><small><?= h($office['name'] ?? '') ?></small><h1>تقرير حالة القضايا</h1>
<p>العميل: <?= h($client['full_name'] ?? '') ?> — الفترة من <?= h($from) ?> إلى <?= h($to) ?></p>
<div class="kpis">
  <div class="kpi"><b><?= count($cases) ?></b>إجمالي القضايا</div>
  <div class="kpi"><b><?= count(array_filter($cases, fn($c) => $c['status']==='active')) ?></b>قضايا نشطة</div>
  <div class="kpi"><b><?= $sessions_count ?></b>جلسات خلال الفترة</div>
</div></div>

<div class="card">
<table><thead><tr><th>رقم القضية</th><th>الموضوع</th><th>النوع</th><th>الحالة</th><th>الجلسة القادمة</th></tr></thead><tbody>
<?php if (!$cases): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px">لا توجد قضايا مسجّلة</td></tr>
<?php else: foreach ($cases as $c): ?>
<tr><td><?= h($c['case_number']) ?></td><td><?= h(mb_substr($c['title'],0,40)) ?></td><td><?= h($c['case_type'] ?: '—') ?></td>
<td><span class="badge"><?= h($_stMap[$c['status']] ?? $c['status']) ?></span></td>
<td><?= $c['next_session'] ? h(date('Y-m-d', strtotime($c['next_session']))) : '—' ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table>
</div>
<div class="ft">تقرير آلي من <?= h($office['name'] ?? '') ?> · تم التوليد بتاريخ <?= h(date('Y-m-d', strtotime($rep['created_at']))) ?></div>
</div></body></html>
