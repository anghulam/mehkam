<?php
require_once '../includes/functions.php';
require_once '../config/db.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
$req = $token !== '' ? $conn->query("SELECT * FROM esign_requests WHERE token='".$conn->real_escape_string($token)."' LIMIT 1")->fetch_assoc() : null;

if (!$req) {
    http_response_code(404);
    $error = 'رابط التوقيع غير صحيح أو منتهي';
}

if ($req && $_SERVER['REQUEST_METHOD'] === 'POST' && $req['status'] === 'pending') {
    $action = $_POST['action'] ?? '';
    if ($action === 'sign') {
        $sig = trim($_POST['signature_data'] ?? '');
        if ($sig !== '' && str_starts_with($sig, 'data:image/png;base64,')) {
            $sigEsc = $conn->real_escape_string($sig);
            $conn->query("UPDATE esign_requests SET status='signed', signature_data='$sigEsc', signed_at=NOW() WHERE id=".(int)$req['id']);
            header("Location: sign.php?t=".urlencode($token)); exit;
        }
    } elseif ($action === 'decline') {
        $conn->query("UPDATE esign_requests SET status='declined' WHERE id=".(int)$req['id']);
        header("Location: sign.php?t=".urlencode($token)); exit;
    }
    if ($req) $req = $conn->query("SELECT * FROM esign_requests WHERE id=".(int)$req['id'])->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($req['title'] ?? 'التوقيع الإلكتروني') ?> — مِحكام</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
body{font-family:'Tajawal',sans-serif;background:#f4f6fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.sg-card{background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.06);max-width:520px;width:100%;overflow:hidden}
.sg-hdr{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;padding:22px 24px}
.sg-body{padding:24px}
#sigPad{border:2px dashed #cbd5e1;border-radius:10px;width:100%;height:200px;cursor:crosshair;touch-action:none;background:#fafbff}
</style>
</head>
<body>
<div class="sg-card">
  <div class="sg-hdr">
    <div style="font-weight:800;font-size:17px"><i class="fas fa-signature me-2" style="color:#e8c040"></i>التوقيع الإلكتروني</div>
    <div style="font-size:12px;opacity:.75">مِحكام</div>
  </div>
  <div class="sg-body">
  <?php if (!$req): ?>
    <div class="text-center text-muted py-4"><i class="fas fa-triangle-exclamation fa-2x mb-3 d-block text-warning"></i><?= e($error) ?></div>
  <?php elseif ($req['status'] === 'signed'): ?>
    <div class="text-center mb-3"><i class="fas fa-circle-check fa-2x text-success mb-2 d-block"></i><div class="fw-bold">تم توقيع هذا المستند بنجاح</div><div class="text-muted" style="font-size:12px">بتاريخ <?= dDate($req['signed_at'], true) ?></div></div>
    <div class="fw-semibold mb-1" style="font-size:13px">المستند: <?= e($req['title']) ?></div>
    <?php if ($req['signature_data']): ?><img src="<?= e($req['signature_data']) ?>" style="max-width:100%;border:1px solid #eef1f6;border-radius:8px;margin-top:8px"><?php endif; ?>
  <?php elseif ($req['status'] === 'declined'): ?>
    <div class="text-center text-muted py-4"><i class="fas fa-circle-xmark fa-2x mb-3 d-block text-danger"></i>تم رفض توقيع هذا المستند</div>
  <?php else: ?>
    <div class="mb-3">
      <div class="fw-bold mb-1"><?= e($req['title']) ?></div>
      <div class="text-muted" style="font-size:13px">مرحباً <?= e($req['signer_name']) ?>، يُطلب توقيعك على هذا المستند إلكترونياً.</div>
    </div>
    <form method="POST" id="signForm">
      <input type="hidden" name="action" value="sign">
      <input type="hidden" name="signature_data" id="sigData">
      <label class="form-label fw-semibold" style="font-size:13px">وقّع بخط يدك في الصندوق أدناه</label>
      <canvas id="sigPad"></canvas>
      <div class="d-flex gap-2 mt-2 mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sigClear()"><i class="fas fa-eraser me-1"></i>مسح</button>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="agreeChk" required>
        <label class="form-check-label" for="agreeChk" style="font-size:12.5px">أوافق على أن هذا التوقيع الإلكتروني له نفس القوة القانونية للتوقيع اليدوي على هذا المستند</label>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1" id="signBtn" disabled><i class="fas fa-signature me-1"></i>توقيع وإرسال</button>
      </div>
    </form>
    <form method="POST" class="mt-2">
      <input type="hidden" name="action" value="decline">
      <button type="submit" class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('رفض توقيع هذا المستند؟')">رفض التوقيع</button>
    </form>
  <?php endif; ?>
  </div>
</div>

<?php if ($req && $req['status'] === 'pending'): ?>
<script>
var canvas = document.getElementById('sigPad');
var ctx = canvas.getContext('2d');
function resize() { var r = canvas.getBoundingClientRect(); canvas.width = r.width; canvas.height = r.height; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0c1b36'; }
resize();
var drawing = false, hasDrawn = false;
function pos(e) {
  var r = canvas.getBoundingClientRect();
  var x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
  var y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
  return [x, y];
}
function start(e) { drawing = true; hasDrawn = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); document.getElementById('signBtn').disabled = false; e.preventDefault(); }
function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); e.preventDefault(); }
function end() { drawing = false; }
canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
canvas.addEventListener('touchstart', start); canvas.addEventListener('touchmove', move); canvas.addEventListener('touchend', end);
function sigClear() { ctx.clearRect(0, 0, canvas.width, canvas.height); hasDrawn = false; document.getElementById('signBtn').disabled = true; }
document.getElementById('signForm').addEventListener('submit', function (e) {
  if (!hasDrawn) { e.preventDefault(); alert('يرجى التوقيع أولاً'); return; }
  document.getElementById('sigData').value = canvas.toDataURL('image/png');
});
</script>
<?php endif; ?>
</body>
</html>
