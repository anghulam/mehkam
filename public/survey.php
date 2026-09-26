<?php
require_once '../includes/functions.php';
require_once '../config/db.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
$req = $token !== '' ? $conn->query("SELECT s.*, o.name office_name FROM client_survey_requests s
    LEFT JOIN offices o ON s.office_id=o.id WHERE s.token='".$conn->real_escape_string($token)."' LIMIT 1")->fetch_assoc() : null;

if (!$req) { http_response_code(404); $error = 'رابط الاستطلاع غير صحيح أو منتهي'; }

if ($req && $_SERVER['REQUEST_METHOD'] === 'POST' && $req['status'] === 'pending') {
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
    $comment = $conn->real_escape_string(trim($_POST['comment'] ?? ''));
    if ($rating > 0) {
        $conn->query("UPDATE client_survey_requests SET status='completed', rating=$rating, comment='$comment', completed_at=NOW() WHERE id=".(int)$req['id']);
        header("Location: survey.php?t=".urlencode($token)); exit;
    }
}
if ($req) $req = $conn->query("SELECT s.*, o.name office_name FROM client_survey_requests s LEFT JOIN offices o ON s.office_id=o.id WHERE s.id=".(int)$req['id'])->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>استطلاع رأي — مِحكام</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
body{font-family:'Tajawal',sans-serif;background:#f4f6fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.sv-card{background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.06);max-width:480px;width:100%;overflow:hidden}
.sv-hdr{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;padding:22px 24px;text-align:center}
.sv-body{padding:28px 24px}
.sv-stars{display:flex;justify-content:center;gap:10px;font-size:34px;margin:16px 0}
.sv-star{color:#e2e8f0;cursor:pointer;transition:color .15s}
.sv-star.active{color:#f59e0b}
</style>
</head>
<body>
<div class="sv-card">
  <div class="sv-hdr">
    <div style="font-weight:800;font-size:17px"><i class="fas fa-star-half-stroke me-2" style="color:#e8c040"></i>استطلاع رأي</div>
    <div style="font-size:12px;opacity:.75"><?= e($req['office_name'] ?? 'مِحكام') ?></div>
  </div>
  <div class="sv-body">
  <?php if (!$req): ?>
    <div class="text-center text-muted py-4"><i class="fas fa-triangle-exclamation fa-2x mb-3 d-block text-warning"></i><?= e($error) ?></div>
  <?php elseif ($req['status'] === 'completed'): ?>
    <div class="text-center py-3"><i class="fas fa-circle-check fa-2x text-success mb-2 d-block"></i>
      <div class="fw-bold mb-2">شكراً لتقييمك!</div>
      <div class="sv-stars">
        <?php for ($i=1;$i<=5;$i++): ?><i class="fas fa-star sv-star <?= $i<=$req['rating']?'active':'' ?>"></i><?php endfor; ?>
      </div>
      <?php if ($req['comment']): ?><div class="text-muted mt-2" style="font-size:13px">"<?= e($req['comment']) ?>"</div><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="text-center mb-2" style="font-size:14px;color:#374151">كيف كانت تجربتك معنا؟</div>
    <form method="POST" id="svForm">
      <input type="hidden" name="rating" id="sv_rating_val" value="0">
      <div class="sv-stars" id="sv_stars">
        <?php for ($i=1;$i<=5;$i++): ?><i class="fas fa-star sv-star" data-val="<?= $i ?>" onclick="svSetRating(<?= $i ?>)"></i><?php endfor; ?>
      </div>
      <textarea name="comment" class="form-control mb-3" rows="3" placeholder="أي ملاحظات إضافية؟ (اختياري)"></textarea>
      <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="fas fa-paper-plane me-1"></i>إرسال التقييم</button>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php if ($req && $req['status'] === 'pending'): ?>
<script>
function svSetRating(v) {
  document.getElementById('sv_rating_val').value = v;
  document.querySelectorAll('#sv_stars .sv-star').forEach(function (s) {
    s.classList.toggle('active', parseInt(s.dataset.val) <= v);
  });
}
document.getElementById('svForm').addEventListener('submit', function (e) {
  if (parseInt(document.getElementById('sv_rating_val').value) < 1) { e.preventDefault(); alert('يرجى اختيار تقييم أولاً'); }
});
</script>
<?php endif; ?>
</body>
</html>
