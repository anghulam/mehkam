<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();

$oid = (int)$_SESSION['office_id'];
$page_title = 'مفاتيح API';

if (!hasFeature($conn, $oid, 'has_api')) {
    header("Location: profile.php?tab=upgrade&feature=api"); exit;
}

// جدول المفاتيح
$conn->query("CREATE TABLE IF NOT EXISTS api_keys (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    office_id      INT NOT NULL,
    key_name       VARCHAR(100) DEFAULT 'مفتاح API',
    api_key        VARCHAR(64)  NOT NULL UNIQUE,
    is_active      TINYINT(1)   DEFAULT 1,
    last_used      DATETIME     DEFAULT NULL,
    requests_count INT          DEFAULT 0,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX (office_id), INDEX (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$msg     = '';
$new_key = '';

/* ── إنشاء مفتاح جديد ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $key_count = (int)$conn->query("SELECT COUNT(*) c FROM api_keys WHERE office_id=$oid AND is_active=1")->fetch_assoc()['c'];
    if ($key_count >= 5) {
        $msg = 'الحد الأقصى 5 مفاتيح نشطة';
    } else {
        $name    = $conn->real_escape_string(trim($_POST['key_name'] ?? 'مفتاح API'));
        $new_key = bin2hex(random_bytes(32)); // 64 hex chars
        $key_e   = $conn->real_escape_string($new_key);
        $conn->query("INSERT INTO api_keys (office_id,key_name,api_key) VALUES ($oid,'$name','$key_e')");
    }
}

/* ── تعطيل / حذف مفتاح ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    $kid = (int)($_POST['key_id'] ?? 0);
    $conn->query("UPDATE api_keys SET is_active=0 WHERE id=$kid AND office_id=$oid");
    header("Location: api_keys.php?msg=revoked"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $kid = (int)($_POST['key_id'] ?? 0);
    $conn->query("DELETE FROM api_keys WHERE id=$kid AND office_id=$oid");
    header("Location: api_keys.php?msg=deleted"); exit;
}

$keys = $conn->query("SELECT * FROM api_keys WHERE office_id=$oid ORDER BY created_at DESC");

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

include '../includes/office_header.php';
?>

<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-code"></i> مفاتيح API</div>
    <div class="mk-page-sub">إدارة مفاتيح الوصول البرمجي للنظام</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="fas fa-plus me-1"></i>إنشاء مفتاح جديد
  </button>
</div>

<?php if (isset($_GET['msg'])): ?>
<?php $msgs = ['revoked'=>['warning','تم تعطيل المفتاح'],'deleted'=>['danger','تم حذف المفتاح']]; [$ac,$at] = $msgs[$_GET['msg']] ?? ['info','تم']; ?>
<div class="alert alert-<?= $ac ?> alert-dismissible fade show py-2">
  <i class="fas fa-info-circle me-2"></i><?= $at ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle me-2"></i><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($new_key): ?>
<div class="alert alert-success border-success" style="border-width:2px">
  <div class="fw-bold mb-1"><i class="fas fa-key me-2"></i>تم إنشاء المفتاح بنجاح — احفظه الآن، لن يظهر مرة أخرى</div>
  <div class="d-flex align-items-center gap-2 mt-2">
    <code id="newKeyCode" class="px-3 py-2 rounded flex-fill"
          style="background:#f0fdf4;color:#166534;font-size:13px;word-break:break-all;border:1px solid #86efac">
      <?= e($new_key) ?>
    </code>
    <button class="btn btn-outline-success btn-sm" onclick="copyKey()">
      <i class="fas fa-copy me-1"></i>نسخ
    </button>
  </div>
</div>
<?php endif; ?>

<!-- المفاتيح -->
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="fas fa-list me-2"></i>مفاتيحك</span>
    <span class="badge bg-secondary-subtle text-secondary"><?= $keys->num_rows ?> / 5</span>
  </div>
  <div class="card-body p-0">
    <?php if ($keys->num_rows === 0): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-key fa-2x mb-2 d-block text-secondary"></i>
      لا توجد مفاتيح — أنشئ أول مفتاح لبدء استخدام API
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead>
          <tr><th>الاسم</th><th>المفتاح</th><th>الحالة</th><th>آخر استخدام</th><th>الطلبات</th><th>إجراءات</th></tr>
        </thead>
        <tbody>
        <?php while ($k = $keys->fetch_assoc()): ?>
        <tr>
          <td class="fw-semibold"><?= e($k['key_name']) ?></td>
          <td>
            <code class="text-muted" style="font-size:12px">
              <?= substr($k['api_key'], 0, 8) ?>••••••••<?= substr($k['api_key'], -6) ?>
            </code>
          </td>
          <td>
            <?php if ($k['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">
              <i class="fas fa-circle me-1" style="font-size:8px"></i>نشط
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary">
              <i class="fas fa-ban me-1"></i>معطّل
            </span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#6b7280">
            <?= $k['last_used'] ? date('d/m/Y H:i', strtotime($k['last_used'])) : 'لم يُستخدم' ?>
          </td>
          <td style="font-size:13px"><?= number_format($k['requests_count']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php if ($k['is_active']): ?>
              <form method="POST" onsubmit="return confirm('تعطيل هذا المفتاح؟')">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                <button class="btn btn-sm btn-outline-warning" title="تعطيل"><i class="fas fa-ban"></i></button>
              </form>
              <?php endif; ?>
              <form method="POST" onsubmit="return confirm('حذف هذا المفتاح نهائياً؟')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="حذف"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- توثيق API -->
<div class="card">
  <div class="card-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border:none">
    <i class="fas fa-book me-2 text-warning"></i><span class="fw-bold">توثيق API — مِحكام v1</span>
  </div>
  <div class="card-body">

    <div class="alert alert-info py-2 mb-3" style="font-size:13px">
      <i class="fas fa-info-circle me-2"></i>
      أرسل المفتاح في الهيدر: <code>X-API-Key: YOUR_KEY</code> أو <code>Authorization: Bearer YOUR_KEY</code>
    </div>

    <!-- Base URL -->
    <div class="mb-4">
      <h6 class="fw-bold text-muted mb-2">Base URL</h6>
      <code class="d-block p-2 rounded" style="background:#1e293b;color:#7dd3fc"><?= $base_url ?>/api/v1/</code>
    </div>

    <?php
    $endpoints = [
        ['القضايا', 'cases', [
            ['GET',    'cases.php',         'قائمة القضايا (دعم: status, q, page, per_page)'],
            ['GET',    'cases.php?id={id}',  'تفاصيل قضية'],
            ['POST',   'cases.php',          'إنشاء قضية'],
            ['PUT',    'cases.php?id={id}',  'تعديل قضية'],
            ['DELETE', 'cases.php?id={id}',  'حذف قضية'],
        ]],
        ['العملاء', 'clients', [
            ['GET',    'clients.php',         'قائمة العملاء (دعم: q, type, page, per_page)'],
            ['GET',    'clients.php?id={id}', 'تفاصيل عميل + قضاياه'],
            ['POST',   'clients.php',         'إنشاء عميل'],
            ['PUT',    'clients.php?id={id}', 'تعديل عميل'],
            ['DELETE', 'clients.php?id={id}', 'حذف عميل'],
        ]],
        ['الفواتير', 'invoices', [
            ['GET',    'invoices.php',         'قائمة الفواتير (دعم: status, direction, from, to, page)'],
            ['GET',    'invoices.php?id={id}', 'تفاصيل فاتورة'],
            ['POST',   'invoices.php',         'إنشاء فاتورة'],
            ['PUT',    'invoices.php?id={id}', 'تعديل حالة / تاريخ دفع'],
        ]],
        ['الإحصائيات', 'stats', [
            ['GET', 'stats.php', 'إحصائيات لوحة التحكم (القضايا، المالية، الجلسات القادمة)'],
        ]],
    ];

    $method_colors = ['GET'=>'#0891b2','POST'=>'#16a34a','PUT'=>'#d97706','DELETE'=>'#dc2626'];
    foreach ($endpoints as [$section, $icon, $routes]):
    ?>
    <h6 class="fw-bold mt-4 mb-2 d-flex align-items-center gap-2">
      <span style="width:8px;height:8px;border-radius:50%;background:#2563eb;display:inline-block"></span>
      <?= $section ?>
    </h6>
    <div class="table-responsive mb-2">
      <table class="table table-sm mb-0" style="font-size:13px">
        <tbody>
        <?php foreach ($routes as [$meth, $path, $desc]): ?>
        <tr>
          <td style="width:70px">
            <span class="badge rounded-pill" style="background:<?= $method_colors[$meth] ?? '#6b7280' ?>;font-size:11px;min-width:55px;text-align:center">
              <?= $meth ?>
            </span>
          </td>
          <td><code style="color:#0c1b36;font-size:12px"><?= $base_url ?>/api/v1/<?= $path ?></code></td>
          <td class="text-muted"><?= $desc ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>

    <!-- مثال عملي -->
    <h6 class="fw-bold mt-4 mb-2">مثال — جلب قائمة القضايا</h6>
    <pre class="p-3 rounded mb-2" style="background:#1e293b;color:#e2e8f0;font-size:12px;overflow-x:auto">curl -X GET "<?= $base_url ?>/api/v1/cases.php?status=active&page=1" \
  -H "X-API-Key: YOUR_API_KEY"</pre>

    <h6 class="fw-bold mt-3 mb-2">مثال — إنشاء عميل جديد</h6>
    <pre class="p-3 rounded mb-2" style="background:#1e293b;color:#e2e8f0;font-size:12px;overflow-x:auto">curl -X POST "<?= $base_url ?>/api/v1/clients.php" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"full_name":"أحمد العمري","phone":"0501234567","client_type":"individual"}'</pre>

    <h6 class="fw-bold mt-3 mb-2">شكل الاستجابة</h6>
    <pre class="p-3 rounded" style="background:#1e293b;color:#e2e8f0;font-size:12px">{
  "success": true,
  "message": "ok",
  "data": {
    "items": [...],
    "pagination": { "page": 1, "per_page": 20, "total": 45, "last_page": 3 }
  }
}</pre>

  </div>
</div>

<!-- Modal إنشاء مفتاح -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-key me-2 text-primary"></i>إنشاء مفتاح API جديد</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="create">
        <div class="modal-body">
          <label class="form-label fw-semibold">اسم المفتاح</label>
          <input type="text" name="key_name" class="form-control" placeholder="مثال: تطبيق الموبايل، تكامل CRM..." maxlength="100">
          <div class="form-text">اختر اسماً يذكّرك بالتطبيق الذي سيستخدم هذا المفتاح.</div>
          <div class="alert alert-warning mt-3 py-2" style="font-size:13px">
            <i class="fas fa-exclamation-triangle me-1"></i>
            المفتاح يظهر مرة واحدة فقط — تأكد من نسخه وحفظه فور إنشائه.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i>إنشاء المفتاح</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function copyKey() {
  var code = document.getElementById('newKeyCode');
  if (!code) return;
  navigator.clipboard.writeText(code.textContent.trim()).then(function() {
    var btn = code.nextElementSibling;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>تم النسخ';
    btn.classList.replace('btn-outline-success','btn-success');
    setTimeout(function(){
      btn.innerHTML = '<i class="fas fa-copy me-1"></i>نسخ';
      btn.classList.replace('btn-success','btn-outline-success');
    }, 2000);
  });
}
</script>

<?php include '../includes/office_footer.php'; ?>
