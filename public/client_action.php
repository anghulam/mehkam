<?php
/**
 * public/client_action.php — صفحة إنجاز العميل (بدون تسجيل دخول)
 * الوصول عبر رمز غير قابل للتخمين: client_action.php?t=<token>
 */
require_once '../config/db.php';
require_once '../includes/storage.php';

$tok = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');
$link = null;
try {
    if (strlen($tok) >= 32) {
        $stmt = $conn->prepare("SELECT * FROM client_action_links WHERE token=? LIMIT 1");
        $stmt->bind_param('s', $tok);
        $stmt->execute();
        $link = $stmt->get_result()->fetch_assoc();
    }
} catch (\Throwable $e) { $link = null; }

if (!$link) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Tahoma;text-align:center;margin-top:80px;color:#64748b">الرابط غير صحيح أو منتهي.</div>';
    exit;
}

$lid = (int)$link['id'];
$oid = (int)$link['office_id'];
$expired = $link['expires_at'] && strtotime($link['expires_at']) < strtotime(date('Y-m-d'));
$office = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc() ?: [];
$client = $conn->query("SELECT full_name FROM clients WHERE id=" . (int)$link['client_id'])->fetch_assoc() ?: [];
$flash = '';

if (!$expired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $item = $conn->query("SELECT * FROM client_action_items WHERE id=$item_id AND link_id=$lid AND status='pending'")->fetch_assoc();
    if ($item) {
        if ($item['item_type'] === 'confirm' && ($_POST['action'] ?? '') === 'confirm') {
            $conn->query("UPDATE client_action_items SET status='done', done_at=NOW() WHERE id=$item_id");
            $flash = 'تم تسجيل موافقتك، شكراً لك.';
        } elseif ($item['item_type'] === 'upload' && ($_POST['action'] ?? '') === 'upload' && !empty($_FILES['f']['name'])) {
            if (($_FILES['f']['size'] ?? 0) > 10 * 1024 * 1024) {
                $flash = 'حجم الملف أكبر من 10 ميغابايت.';
            } else {
                $up = storage_upload($_FILES['f'], 'client_uploads', $oid, $office['name'] ?? '');
                if (!empty($up['success'])) {
                    $fn = $conn->real_escape_string($up['name']); $fp = $conn->real_escape_string($up['path']); $fd = $conn->real_escape_string($up['driver']);
                    $conn->query("UPDATE client_action_items SET status='done', done_at=NOW(), file_name='$fn', file_path='$fp', file_driver='$fd' WHERE id=$item_id");
                    $flash = 'تم رفع الملف بنجاح.';
                } else {
                    $flash = $up['error'] ?: 'تعذّر رفع الملف.';
                }
            }
        }
    }
}

$items = [];
$ir = $conn->query("SELECT it.*, inv.status inv_status, inv.public_token inv_token, esr.status sign_status, esr.token sign_token
    FROM client_action_items it
    LEFT JOIN invoices inv ON it.item_type='invoice' AND inv.id=it.ref_id
    LEFT JOIN esign_requests esr ON it.item_type='sign' AND esr.id=it.ref_id
    WHERE it.link_id=$lid ORDER BY it.id");
if (!$ir) { // جدول التواقيع غير موجود عند مكاتب لم تفعّل الموديول
    $ir = $conn->query("SELECT it.*, inv.status inv_status, inv.public_token inv_token, NULL sign_status, NULL sign_token
        FROM client_action_items it LEFT JOIN invoices inv ON it.item_type='invoice' AND inv.id=it.ref_id WHERE it.link_id=$lid ORDER BY it.id");
}
if ($ir) while ($x = $ir->fetch_assoc()) $items[] = $x;

$done = 0;
foreach ($items as &$x) {
    $x['is_done'] = $x['item_type']==='invoice' ? ($x['inv_status']==='paid') : ($x['item_type']==='sign' ? ($x['sign_status']==='signed') : ($x['status']==='done'));
    if ($x['is_done']) $done++;
}
unset($x);
$total = count($items);
$pct = $total ? round($done / $total * 100) : 0;
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= h($link['title']) ?> — <?= h($office['name'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#f3f5f9;font-family:Tajawal,Tahoma,sans-serif;color:#0c1b36}
.w{max-width:620px;margin:0 auto;padding:16px}
.hd{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border-radius:16px;padding:22px}
.hd small{color:#c9a227;font-weight:700}.hd h1{margin:6px 0 4px;font-size:20px}.hd p{margin:0;font-size:13px;opacity:.85}
.bar{height:8px;background:rgba(255,255,255,.2);border-radius:8px;margin-top:14px;overflow:hidden}.bar i{display:block;height:100%;background:#c9a227;width:<?= $pct ?>%}
.it{background:#fff;border:1px solid #e6e9f0;border-radius:14px;padding:14px 16px;margin-top:12px}
.it.ok{border-color:#86efac;background:#f0fdf4}
.row{display:flex;align-items:center;gap:10px}.row b{flex:1;font-size:14px}
.tag{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:#fef3c7;color:#b45309}.ok .tag{background:#dcfce7;color:#15803d}
.btn{display:inline-block;margin-top:10px;background:#0c1b36;color:#fff;border:0;border-radius:10px;padding:9px 18px;font-family:inherit;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
.btn.g{background:#15803d}
.fl{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;padding:10px 14px;margin-top:12px;font-size:13px}
input[type=file]{font-size:13px;margin-top:8px;max-width:100%}
.ft{text-align:center;color:#94a3b8;font-size:11px;margin:20px 0}
</style></head><body><div class="w">
<div class="hd"><small><?= h($office['name'] ?? '') ?></small><h1><?= h($link['title']) ?></h1>
<p>أهلاً <?= h($client['full_name'] ?? '') ?><?= $link['message'] ? ' — ' . nl2br(h($link['message'])) : '' ?></p>
<div class="bar"><i></i></div><p style="margin-top:6px;font-size:12px"><?= $done ?> من <?= $total ?> مكتمل</p></div>
<?php if ($flash): ?><div class="fl"><?= h($flash) ?></div><?php endif; ?>
<?php if ($expired): ?><div class="fl" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">انتهت صلاحية هذا الرابط — تواصل مع المكتب.</div><?php endif; ?>
<?php foreach ($items as $x): ?>
<div class="it <?= $x['is_done'] ? 'ok' : '' ?>">
  <div class="row"><b><?= h($x['label']) ?></b><span class="tag"><?= $x['is_done'] ? 'مكتمل ✓' : 'مطلوب' ?></span></div>
  <?php if (!$x['is_done'] && !$expired): ?>
    <?php if ($x['item_type']==='sign' && $x['sign_token']): ?><a class="btn" href="sign.php?t=<?= h($x['sign_token']) ?>">مراجعة وتوقيع</a>
    <?php elseif ($x['item_type']==='invoice' && $x['inv_token']): ?><a class="btn" href="invoice_view.php?t=<?= h($x['inv_token']) ?>">عرض الفاتورة</a>
    <?php elseif ($x['item_type']==='upload'): ?>
      <form method="POST" enctype="multipart/form-data"><input type="hidden" name="item_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="action" value="upload">
      <input type="file" name="f" required><br><button class="btn g">رفع الملف</button></form>
    <?php elseif ($x['item_type']==='confirm'): ?>
      <form method="POST"><input type="hidden" name="item_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="action" value="confirm"><button class="btn g">أوافق</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<div class="ft">صفحة آمنة خاصة بك · <?= h($office['name'] ?? '') ?></div>
</div></body></html>
