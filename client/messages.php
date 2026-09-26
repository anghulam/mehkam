<?php
$page_title = 'تواصل مع المكتب';
require_once __DIR__ . '/../includes/client_portal_header.php';

try { $conn->query("ALTER TABLE client_interactions ADD COLUMN via_portal TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'message') {
    $msg = $conn->real_escape_string(trim($_POST['message'] ?? ''));
    if ($msg !== '') {
        $name = $conn->real_escape_string($_cp_client['full_name']);
        $conn->query("INSERT INTO client_interactions (office_id,client_id,type,interaction_date,summary,created_by_name,via_portal)
            VALUES ($_cp_oid,$_cp_id,'note',CURDATE(),'$msg','$name',1)");
    }
    header("Location: messages.php"); exit;
}

$messages = [];
$mr = $conn->query("SELECT * FROM client_interactions WHERE office_id=$_cp_oid AND client_id=$_cp_id ORDER BY id DESC LIMIT 50");
if ($mr) while ($r = $mr->fetch_assoc()) $messages[] = $r;
?>
<div class="card mb-3">
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="form_type" value="message">
      <label class="form-label fw-semibold">راسل مكتبك</label>
      <textarea name="message" class="form-control mb-2" rows="3" placeholder="اكتب رسالتك هنا..." required></textarea>
      <button class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i>إرسال</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-clock-rotate-left me-2 text-muted"></i>سجل التواصل</div>
  <div class="card-body p-0">
    <?php if (!$messages): ?>
    <div class="text-muted text-center py-4">لا توجد رسائل بعد</div>
    <?php else: foreach ($messages as $m): ?>
    <div class="p-3 border-bottom">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="fw-semibold" style="font-size:12px">
          <?= !empty($m['via_portal']) ? '<i class="fas fa-user me-1"></i>أنت' : '<i class="fas fa-building me-1"></i>' . e($m['created_by_name'] ?: 'المكتب') ?>
        </span>
        <span class="text-muted" style="font-size:11px"><?= dDate($m['interaction_date'], false, false) ?></span>
      </div>
      <div style="font-size:13px;white-space:pre-wrap"><?= e($m['summary']) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/client_portal_footer.php'; ?>
