<?php
/** public/collab.php — بوابة المحامي الاستشاري الخارجي (بدون تسجيل دخول، وصول مؤقت محدود) */
require_once '../config/db.php';

$tok = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');
$collab = null;
try {
    if (strlen($tok) >= 32) {
        $stmt = $conn->prepare("SELECT * FROM external_collaborators WHERE token=? LIMIT 1");
        $stmt->bind_param('s', $tok);
        $stmt->execute();
        $collab = $stmt->get_result()->fetch_assoc();
    }
} catch (\Throwable $e) { $collab = null; }

$active = $collab && $collab['status'] === 'active' && $collab['expires_at'] >= date('Y-m-d');
if (!$collab) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Tahoma;text-align:center;margin-top:80px;color:#64748b">الرابط غير صحيح.</div>';
    exit;
}
$oid = (int)$collab['office_id']; $cid = (int)$collab['case_id'];
$office = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc() ?: [];
$case = $conn->query("SELECT case_number, title, status, court_name, case_type, next_session, description FROM cases WHERE id=$cid")->fetch_assoc() ?: [];
$flash = '';

if ($active && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'note') {
    $note = trim($_POST['note'] ?? '');
    if ($note !== '') {
        $ne = $conn->real_escape_string(mb_substr($note, 0, 1000));
        $conn->query("INSERT INTO external_collab_notes (collaborator_id,note) VALUES (" . (int)$collab['id'] . ",'$ne')");
        $flash = 'تم إرسال ملاحظتك لفريق المكتب.';
    }
}

$sessions = [];
if ($active) {
    $sr = $conn->query("SELECT session_date, status, description FROM sessions WHERE case_id=$cid ORDER BY session_date DESC LIMIT 10");
    if ($sr) while ($x = $sr->fetch_assoc()) $sessions[] = $x;
}
$notes = [];
$nr = $conn->query("SELECT note, created_at FROM external_collab_notes WHERE collaborator_id=" . (int)$collab['id'] . " ORDER BY id DESC LIMIT 20");
if ($nr) while ($x = $nr->fetch_assoc()) $notes[] = $x;
$_stMap = ['active'=>'نشطة','closed'=>'مغلقة','suspended'=>'موقوفة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوّاة'];
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>تعاون على قضية — <?= h($office['name'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#f3f5f9;font-family:Tajawal,Tahoma,sans-serif;color:#0c1b36}
.w{max-width:640px;margin:0 auto;padding:16px}
.hd{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border-radius:16px;padding:22px}
.hd small{color:#c9a227;font-weight:700}.hd h1{margin:6px 0 4px;font-size:19px}.hd p{margin:0;font-size:13px;opacity:.85}
.card{background:#fff;border:1px solid #e6e9f0;border-radius:14px;padding:14px 16px;margin-top:12px}
.badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:#eef2fb;color:#1e3a8a}
textarea{width:100%;border:1px solid #d8dee8;border-radius:10px;padding:10px;font-family:inherit;font-size:13px;box-sizing:border-box}
.btn{display:inline-block;margin-top:8px;background:#0c1b36;color:#fff;border:0;border-radius:10px;padding:9px 18px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer}
.fl{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;padding:10px 14px;margin-top:12px;font-size:13px}
.note{border-bottom:1px solid #eef1f6;padding:6px 0;font-size:12.5px}
.ft{text-align:center;color:#94a3b8;font-size:11px;margin:20px 0}
</style></head><body><div class="w">
<div class="hd"><small><?= h($office['name'] ?? '') ?></small><h1>دعوة تعاون على قضية</h1>
<p>أهلاً <?= h($collab['name']) ?> — وصول مؤقت للاطّلاع على القضية وإرسال ملاحظاتك حتى <?= h($collab['expires_at']) ?></p></div>

<?php if (!$active): ?>
<div class="fl" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">انتهت صلاحية هذه الدعوة أو أُلغيت — تواصل مع المكتب لتجديدها.</div>
<?php else: ?>
<?php if ($flash): ?><div class="fl"><?= h($flash) ?></div><?php endif; ?>

<div class="card">
  <div class="badge"><?= h($_stMap[$case['status']] ?? $case['status']) ?></div>
  <h3 style="margin:8px 0 4px"><?= h($case['case_number']) ?> — <?= h($case['title']) ?></h3>
  <div style="font-size:12.5px;color:#64748b">النوع: <?= h($case['case_type'] ?: '—') ?> · المحكمة: <?= h($case['court_name'] ?: '—') ?><?= $case['next_session'] ? ' · الجلسة القادمة: '.h(date('Y-m-d', strtotime($case['next_session']))) : '' ?></div>
  <?php if ($case['description']): ?><div style="font-size:13px;margin-top:8px"><?= nl2br(h($case['description'])) ?></div><?php endif; ?>
</div>

<div class="card">
  <b style="font-size:13px">آخر الجلسات</b>
  <?php if (!$sessions): ?><div style="font-size:12.5px;color:#94a3b8;margin-top:6px">لا توجد جلسات مسجّلة</div>
  <?php else: foreach ($sessions as $s): ?>
  <div class="note"><b><?= h(date('Y-m-d', strtotime($s['session_date']))) ?></b> — <?= h($s['description'] ?: '') ?></div>
  <?php endforeach; endif; ?>
</div>

<div class="card">
  <b style="font-size:13px">أضف ملاحظة لفريق المكتب</b>
  <form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="note">
    <textarea name="note" rows="3" placeholder="اكتب ملاحظتك أو رأيك حول القضية..." required></textarea>
    <button class="btn" type="submit">إرسال الملاحظة</button>
  </form>
  <?php if ($notes): ?><div style="margin-top:10px">
    <?php foreach ($notes as $n): ?><div class="note"><?= nl2br(h($n['note'])) ?><div style="color:#94a3b8;font-size:11px"><?= h(date('Y-m-d H:i', strtotime($n['created_at']))) ?></div></div><?php endforeach; ?>
  </div><?php endif; ?>
</div>
<?php endif; ?>
<div class="ft">وصول مؤقت ومحدود بقضية واحدة · <?= h($office['name'] ?? '') ?></div>
</div></body></html>
