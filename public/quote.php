<?php
/** public/quote.php — عرض أتعاب للعميل المحتمل (بدون تسجيل دخول) */
require_once '../config/db.php';

$tok = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');
$q = null;
try {
    if (strlen($tok) >= 32) {
        $stmt = $conn->prepare("SELECT * FROM fee_quotes WHERE token=? LIMIT 1");
        $stmt->bind_param('s', $tok);
        $stmt->execute();
        $q = $stmt->get_result()->fetch_assoc();
    }
} catch (\Throwable $e) { $q = null; }

if (!$q) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Tahoma;text-align:center;margin-top:80px;color:#64748b">الرابط غير صحيح.</div>';
    exit;
}
$oid = (int)$q['office_id'];
$office = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc() ?: [];

if ($q['status'] === 'sent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'accept') {
        $cne = $conn->real_escape_string($q['client_name']);
        $cph = $conn->real_escape_string($q['client_phone'] ?: '');
        $cl = $q['client_phone'] ? $conn->query("SELECT id FROM clients WHERE office_id=$oid AND phone='$cph' LIMIT 1")->fetch_assoc() : null;
        if ($cl) { $client_id = (int)$cl['id']; }
        else { $conn->query("INSERT INTO clients (office_id,full_name,phone) VALUES ($oid,'$cne','$cph')"); $client_id = (int)$conn->insert_id; }
        $title = $conn->real_escape_string('اتفاقية أتعاب — ' . $q['client_name']);
        $desc = $conn->real_escape_string($q['description'] ?: '');
        $conn->query("INSERT INTO contracts (office_id,client_id,title,contract_type,value,status,description) VALUES ($oid,$client_id,'$title','اتفاقية أتعاب',".(float)$q['amount'].",'draft','$desc')");
        $contract_id = (int)$conn->insert_id;
        $conn->query("UPDATE fee_quotes SET status='accepted', resulting_contract_id=$contract_id WHERE id=".(int)$q['id']);
        header('Location: quote.php?t=' . urlencode($tok)); exit;
    } elseif ($action === 'decline') {
        $conn->query("UPDATE fee_quotes SET status='declined' WHERE id=".(int)$q['id']);
        header('Location: quote.php?t=' . urlencode($tok)); exit;
    }
    $q = $conn->query("SELECT * FROM fee_quotes WHERE id=".(int)$q['id'])->fetch_assoc();
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>عرض أتعاب — <?= h($office['name'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#f3f5f9;font-family:Tajawal,Tahoma,sans-serif;color:#0c1b36}
.w{max-width:520px;margin:0 auto;padding:16px}
.hd{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border-radius:16px;padding:22px;text-align:center}
.hd small{color:#c9a227;font-weight:700}.hd h1{margin:8px 0 0;font-size:32px}
.card{background:#fff;border:1px solid #e6e9f0;border-radius:14px;padding:16px;margin-top:14px}
.btn{display:inline-block;border:0;border-radius:10px;padding:11px 20px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;width:100%;margin-top:8px}
.btn-a{background:#16a34a;color:#fff}.btn-d{background:#fff;color:#b91c1c;border:1px solid #fecaca}
.fl{border-radius:10px;padding:14px;margin-top:12px;font-size:13px;text-align:center}
.fl-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.fl-no{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
</style></head><body><div class="w">
<div class="hd"><small><?= h($office['name'] ?? '') ?></small><h1><?= number_format((float)$q['amount'],2) ?> <span style="font-size:16px">ر.س</span></h1></div>
<div class="card">
  <b>عرض أتعاب مقدّم لـ: <?= h($q['client_name']) ?></b>
  <?php if ($q['description']): ?><div style="margin-top:10px;font-size:14px;white-space:pre-line"><?= h($q['description']) ?></div><?php endif; ?>
</div>
<?php if ($q['status'] === 'sent'): ?>
<form method="POST"><input type="hidden" name="action" value="accept"><button class="btn btn-a" type="submit">أوافق على العرض</button></form>
<form method="POST" onsubmit="return confirm('رفض هذا العرض؟')"><input type="hidden" name="action" value="decline"><button class="btn btn-d" type="submit">أرفض العرض</button></form>
<?php elseif ($q['status'] === 'accepted'): ?>
<div class="fl fl-ok">✔ تمت موافقتك على العرض — سيتواصل معك المكتب لاستكمال الإجراءات.</div>
<?php else: ?>
<div class="fl fl-no">تم رفض هذا العرض.</div>
<?php endif; ?>
</div></body></html>
