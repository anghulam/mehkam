<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('clients', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid  = (int)$_SESSION['office_id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$page_title = 'دليل الاتصال';

/* ── إنشاء الجدول ── */
$conn->query("CREATE TABLE IF NOT EXISTS office_contacts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    office_id  INT NOT NULL,
    full_name  VARCHAR(200) NOT NULL,
    phone      VARCHAR(40)  DEFAULT NULL,
    email      VARCHAR(150) DEFAULT NULL,
    notes      TEXT         DEFAULT NULL,
    created_by INT          DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── تنزيل قالب CSV/Excel ── */
if (isset($_GET['template'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_contacts_mehkam.csv"');
    echo "\xEF\xBB\xBF";  // BOM — Excel يحتاجه لقراءة العربية
    $out = fopen('php://output', 'w');
    fputcsv($out, ['الاسم الكامل', 'رقم الجوال', 'البريد الإلكتروني', 'ملاحظات']);
    fputcsv($out, ['أحمد العمري', '0501234567', 'ahmed@example.com', 'ملاحظة اختيارية']);
    fclose($out);
    exit;
}

/* ── استيراد CSV ── */
$import_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'import') {
    if (!can('clients', 'add')) { header('Location: contacts.php?msg=denied'); exit; }
    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $inserted = 0; $skipped = 0; $row_num = 0;
        while (($row = fgetcsv($handle)) !== false) {
            // تخطي أول سطر (العناوين) وأي صف فارغ
            $row_num++;
            if ($row_num === 1) {
                // إزالة BOM من أول خلية إن وُجد
                $row[0] = ltrim($row[0], "\xEF\xBB\xBF");
                // تحقق: إذا كان صف العناوين، تخطَّه
                if (mb_strpos(trim($row[0]), 'اسم') !== false) continue;
            }
            $name  = trim($row[0] ?? '');
            $phone = trim($row[1] ?? '');
            $email = trim($row[2] ?? '');
            $notes = trim($row[3] ?? '');
            if ($name === '') { $skipped++; continue; }
            if ($inserted >= 500) { $skipped++; continue; } // حد 500 صف لكل استيراد
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
            $n = $conn->real_escape_string(mb_substr($name, 0, 200));
            $p = $conn->real_escape_string(mb_substr($phone, 0, 40));
            $e = $conn->real_escape_string(mb_substr($email, 0, 150));
            $o = $conn->real_escape_string(mb_substr($notes, 0, 1000));
            $conn->query("INSERT INTO office_contacts (office_id,full_name,phone,email,notes,created_by)
                VALUES ($oid,'$n','$p','$e','$o',".($uid ?: 'NULL').")");
            $inserted++;
        }
        fclose($handle);
        $import_msg = "تم استيراد {$inserted} جهة اتصال" . ($skipped ? "، تم تخطي {$skipped} صف" : '') . '.';
        header("Location: contacts.php?msg_import=" . urlencode($import_msg)); exit;
    }
    header('Location: contacts.php?msg=no_file'); exit;
}

/* ── حذف ── */
if (isset($_GET['delete']) && can('clients', 'delete')) {
    $conn->query("DELETE FROM office_contacts WHERE id=" . (int)$_GET['delete'] . " AND office_id=$oid");
    header('Location: contacts.php?msg=deleted'); exit;
}

/* ── إضافة / تعديل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'save') {
    $action = empty($_POST['id']) ? 'add' : 'edit';
    if (!can('clients', $action)) { header('Location: contacts.php?msg=denied'); exit; }
    $name  = $conn->real_escape_string(mb_substr(trim($_POST['full_name'] ?? ''), 0, 200));
    $phone = $conn->real_escape_string(mb_substr(trim($_POST['phone']     ?? ''), 0, 40));
    $email = $conn->real_escape_string(mb_substr(trim($_POST['email']     ?? ''), 0, 150));
    $notes = $conn->real_escape_string(mb_substr(trim($_POST['notes']     ?? ''), 0, 1000));
    if ($name === '') { header('Location: contacts.php?msg=invalid'); exit; }
    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE office_contacts SET full_name='$name',phone='$phone',email='$email',notes='$notes' WHERE id=$id AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO office_contacts (office_id,full_name,phone,email,notes,created_by) VALUES ($oid,'$name','$phone','$email','$notes',".($uid ?: 'NULL').")");
    }
    header('Location: contacts.php?msg=saved'); exit;
}

/* ── استعلام البيانات ── */
$contacts = [];
$res = $conn->query("SELECT * FROM office_contacts WHERE office_id=$oid ORDER BY full_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $contacts[] = $r;
$total = count($contacts);

$edit = null;
if (!empty($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM office_contacts WHERE id=" . (int)$_GET['edit'] . " AND office_id=$oid")->fetch_assoc();
}

include '../includes/office_header.php';
?>

<!-- رسائل الحالة -->
<?php if (!empty($_GET['msg'])): ?>
  <?php $msgs = ['saved'=>['success','تم الحفظ بنجاح'],'deleted'=>['warning','تم الحذف'],'denied'=>['danger','ليس لديك صلاحية'],'invalid'=>['danger','يرجى إدخال اسم الجهة'],'no_file'=>['danger','لم يتم اختيار ملف']]; $m=$msgs[$_GET['msg']]??null; if($m): ?>
  <div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><i class="fas fa-circle-info me-1"></i><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>
<?php endif; ?>
<?php if (!empty($_GET['msg_import'])): ?>
  <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-file-import me-1"></i><?= e($_GET['msg_import']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- عنوان الصفحة + أدوات -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="me-auto">
    <div class="mk-page-title mb-0"><i class="fas fa-address-book"></i> دليل الاتصال</div>
    <div class="mk-page-sub">إدارة جهات الاتصال — الاسم، الجوال، البريد الإلكتروني</div>
  </div>
  <?php if (can('clients','add')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#contactModal">
    <i class="fas fa-plus me-1"></i>إضافة جهة
  </button>
  <?php endif; ?>
  <a href="contacts.php?template=1" class="btn btn-outline-success btn-sm">
    <i class="fas fa-file-excel me-1"></i>تنزيل قالب Excel
  </a>
  <?php if (can('clients','add')): ?>
  <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
    <i class="fas fa-file-upload me-1"></i>استيراد من Excel
  </button>
  <?php endif; ?>
</div>

<!-- شريط البحث + الإحصاء -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <div class="row align-items-center g-2">
      <div class="col-md-6">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="fas fa-search"></i></span>
          <input type="search" id="liveSearch" class="form-control" placeholder="ابحث بالاسم أو الجوال أو الإيميل…" autocomplete="off" dir="auto">
        </div>
      </div>
      <div class="col-md-6 text-md-end">
        <span class="text-muted" style="font-size:13px">
          إجمالي جهات الاتصال: <strong id="visibleCount"><?= $total ?></strong> من <strong><?= $total ?></strong>
        </span>
      </div>
    </div>
  </div>
</div>

<!-- جدول جهات الاتصال -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="contactsTable" style="font-size:13.5px">
      <thead class="table-light">
        <tr>
          <th style="width:40px">#</th>
          <th>الاسم الكامل</th>
          <th>رقم الجوال</th>
          <th>البريد الإلكتروني</th>
          <th>ملاحظات</th>
          <th style="width:90px">تاريخ الإضافة</th>
          <?php if (can('clients','edit') || can('clients','delete')): ?>
          <th style="width:90px" class="text-center">إجراءات</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody id="contactsBody">
        <?php if (!$contacts): ?>
        <tr id="emptyRow">
          <td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-address-book fa-2x mb-2 d-block opacity-25"></i>
            لا توجد جهات اتصال بعد — أضف جهة أو استورد من Excel
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($contacts as $i => $c): ?>
        <tr class="contact-row"
            data-name="<?= e(mb_strtolower($c['full_name'])) ?>"
            data-phone="<?= e($c['phone']) ?>"
            data-email="<?= e(mb_strtolower($c['email'])) ?>">
          <td class="text-muted"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= e($c['full_name']) ?></td>
          <td>
            <?php if ($c['phone']): ?>
              <a href="tel:<?= e($c['phone']) ?>" class="text-decoration-none text-body" dir="ltr"><?= e($c['phone']) ?></a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($c['email']): ?>
              <a href="mailto:<?= e($c['email']) ?>" class="text-decoration-none text-body" dir="ltr"><?= e($c['email']) ?></a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($c['notes']) ?>">
            <?= $c['notes'] ? e(mb_substr($c['notes'], 0, 50)) . (mb_strlen($c['notes']) > 50 ? '…' : '') : '—' ?>
          </td>
          <td class="text-muted"><?= date('Y-m-d', strtotime($c['created_at'])) ?></td>
          <?php if (can('clients','edit') || can('clients','delete')): ?>
          <td class="text-center">
            <?php if (can('clients','edit')): ?>
            <a href="contacts.php?edit=<?= $c['id'] ?>#contactModal"
               class="btn btn-sm btn-outline-primary py-0 px-2 open-edit"
               data-id="<?= $c['id'] ?>"
               data-name="<?= e($c['full_name']) ?>"
               data-phone="<?= e($c['phone']) ?>"
               data-email="<?= e($c['email']) ?>"
               data-notes="<?= e($c['notes']) ?>"
               title="تعديل">
              <i class="fas fa-pen fa-xs"></i>
            </a>
            <?php endif; ?>
            <?php if (can('clients','delete')): ?>
            <a href="contacts.php?delete=<?= $c['id'] ?>"
               class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
               onclick="return confirm('تأكيد حذف جهة الاتصال؟')"
               title="حذف">
              <i class="fas fa-trash fa-xs"></i>
            </a>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($contacts): ?>
  <div class="card-footer text-muted py-2" style="font-size:12px">
    <span id="noResultsMsg" class="text-danger d-none"><i class="fas fa-magnifying-glass me-1"></i>لا توجد نتائج مطابقة للبحث</span>
    <span id="footerCount"><?= $total ?> جهة اتصال</span>
  </div>
  <?php endif; ?>
</div>

<!-- ── مودال إضافة / تعديل ── -->
<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="contacts.php" class="modal-content">
      <input type="hidden" name="form_type" value="save">
      <input type="hidden" name="id" id="modalId" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="contactModalLabel"><i class="fas fa-address-card me-2"></i><span id="modalTitleText">إضافة جهة اتصال</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">الاسم الكامل <span class="text-danger">*</span></label>
          <input type="text" name="full_name" id="modalName" class="form-control" required maxlength="200" placeholder="مثال: أحمد محمد العمري">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">رقم الجوال</label>
          <input type="tel" name="phone" id="modalPhone" class="form-control" maxlength="40" placeholder="05XXXXXXXX" dir="ltr">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">البريد الإلكتروني</label>
          <input type="email" name="email" id="modalEmail" class="form-control" maxlength="150" placeholder="example@domain.com" dir="ltr">
        </div>
        <div class="mb-1">
          <label class="form-label fw-semibold">ملاحظات</label>
          <textarea name="notes" id="modalNotes" class="form-control" rows="2" maxlength="1000" placeholder="اختياري…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>حفظ</button>
      </div>
    </form>
  </div>
</div>

<!-- ── مودال استيراد CSV ── -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="contacts.php" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="form_type" value="import">
      <div class="modal-header">
        <h5 class="modal-title" id="importModalLabel"><i class="fas fa-file-upload me-2"></i>استيراد من Excel / CSV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 px-3" style="font-size:12.5px">
          <i class="fas fa-circle-info me-1"></i>
          نزّل <a href="contacts.php?template=1" class="alert-link">قالب Excel</a> أولاً، عبّئ البيانات، ثم ارفعه هنا.
          الملف المدعوم: <strong>CSV</strong> (احفظ من Excel بصيغة CSV UTF-8).
          <br>الحد الأقصى للاستيراد: <strong>500 صف</strong> لكل عملية.
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">اختر الملف</label>
          <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-file-import me-1"></i>استيراد</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Live Search ── */
(function () {
  const input     = document.getElementById('liveSearch');
  const rows      = document.querySelectorAll('#contactsBody .contact-row');
  const countEl   = document.getElementById('visibleCount');
  const footerEl  = document.getElementById('footerCount');
  const noMsg     = document.getElementById('noResultsMsg');
  const total     = rows.length;

  if (!input) return;

  input.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;

    rows.forEach(function (row) {
      const name  = row.dataset.name  || '';
      const phone = row.dataset.phone || '';
      const email = row.dataset.email || '';
      const match = !q || name.includes(q) || phone.includes(q) || email.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });

    if (countEl)  countEl.textContent = visible;
    if (footerEl) footerEl.textContent = visible + ' جهة اتصال';
    if (noMsg) {
      if (q && visible === 0) { noMsg.classList.remove('d-none'); if(footerEl) footerEl.classList.add('d-none'); }
      else { noMsg.classList.add('d-none'); if(footerEl) footerEl.classList.remove('d-none'); }
    }
  });
})();

/* ── فتح مودال التعديل بالبيانات ── */
document.querySelectorAll('.open-edit').forEach(function (btn) {
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    document.getElementById('modalId').value    = this.dataset.id;
    document.getElementById('modalName').value  = this.dataset.name;
    document.getElementById('modalPhone').value = this.dataset.phone;
    document.getElementById('modalEmail').value = this.dataset.email;
    document.getElementById('modalNotes').value = this.dataset.notes;
    document.getElementById('modalTitleText').textContent = 'تعديل جهة اتصال';
    var modal = new bootstrap.Modal(document.getElementById('contactModal'));
    modal.show();
  });
});

/* ── إعادة ضبط المودال عند الإضافة ── */
document.querySelector('[data-bs-target="#contactModal"]')?.addEventListener('click', function () {
  document.getElementById('modalId').value    = '';
  document.getElementById('modalName').value  = '';
  document.getElementById('modalPhone').value = '';
  document.getElementById('modalEmail').value = '';
  document.getElementById('modalNotes').value = '';
  document.getElementById('modalTitleText').textContent = 'إضافة جهة اتصال';
});
</script>

<?php
/* فتح مودال التعديل تلقائياً عند وجود ?edit= ── */
if ($edit): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('modalId').value    = '<?= (int)$edit['id'] ?>';
  document.getElementById('modalName').value  = <?= json_encode($edit['full_name'], JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('modalPhone').value = <?= json_encode($edit['phone'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('modalEmail').value = <?= json_encode($edit['email'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('modalNotes').value = <?= json_encode($edit['notes'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('modalTitleText').textContent = 'تعديل جهة اتصال';
  new bootstrap.Modal(document.getElementById('contactModal')).show();
});
</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
