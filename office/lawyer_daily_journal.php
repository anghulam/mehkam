<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('lawyer_daily_journal','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'lawyer_daily_journal')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'التقرير اليومي';
$uid  = (int)($_SESSION['user_id'] ?? 0);
$_canAdd     = can('lawyer_daily_journal','add');
$_canApprove = can('lawyer_daily_journal','approve');

/* ── ترقية الجدول ── */
$conn->query("CREATE TABLE IF NOT EXISTS daily_journal (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    office_id          INT NOT NULL,
    user_id            INT NOT NULL,
    entry_date         DATE NOT NULL,
    notes              TEXT NOT NULL,
    contacted_numbers  TEXT DEFAULT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_date (user_id, entry_date),
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE daily_journal MODIFY notes TEXT NOT NULL"); } catch(\Throwable $e){}
try { $conn->query("ALTER TABLE daily_journal ADD COLUMN contacted_numbers TEXT DEFAULT NULL"); } catch(\Throwable $e){}

/* ── تصدير CSV ── */
if (isset($_GET['export_csv'])) {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
    $scope = $_canApprove ? '' : " AND j.user_id=$uid";
    $res = $conn->query("SELECT j.*, u.full_name FROM daily_journal j
        LEFT JOIN users u ON u.id=j.user_id
        WHERE j.office_id=$oid AND j.entry_date BETWEEN '$from' AND '$to'$scope
        ORDER BY j.entry_date DESC, u.full_name");
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="daily_report_'.$from.'_'.$to.'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out, ['التاريخ','الموظف','ماذا أنجز','الأرقام التي تم التواصل معها']);
    if ($res) while ($r = $res->fetch_assoc()) {
        $nums = '';
        if (!empty($r['contacted_numbers'])) {
            $arr = json_decode($r['contacted_numbers'], true) ?: [];
            $parts = [];
            foreach ($arr as $cn) {
                $n = trim($cn['name']  ?? ''); $p = trim($cn['phone'] ?? '');
                if ($n || $p) $parts[] = $n . ($n && $p ? ' — ' : '') . $p;
            }
            $nums = implode(' | ', $parts);
        }
        fputcsv($out, [$r['entry_date'], $r['full_name'] ?: '—', $r['notes'], $nums]);
    }
    fclose($out); exit;
}

/* ── حفظ التقرير ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'entry') {
    requirePerm('lawyer_daily_journal','add','lawyer_daily_journal.php?msg=denied');
    $notes = trim($_POST['notes'] ?? '');
    if ($notes === '') { header('Location: lawyer_daily_journal.php?msg=empty'); exit; }
    $ne = $conn->real_escape_string(mb_substr($notes, 0, 2000));

    /* أرقام التواصل — مصفوفة {name, phone} */
    $contacted = [];
    $cnames  = $_POST['c_name']  ?? [];
    $cphones = $_POST['c_phone'] ?? [];
    foreach ((array)$cnames as $i => $cn) {
        $n = mb_substr(trim($cn), 0, 100);
        $p = mb_substr(trim($cphones[$i] ?? ''), 0, 40);
        if ($n !== '' || $p !== '') $contacted[] = ['name' => $n, 'phone' => $p];
    }
    $cj = $conn->real_escape_string(json_encode($contacted, JSON_UNESCAPED_UNICODE));

    $date = date('Y-m-d');
    $conn->query("INSERT INTO daily_journal (office_id,user_id,entry_date,notes,contacted_numbers)
        VALUES ($oid,$uid,'$date','$ne','$cj')
        ON DUPLICATE KEY UPDATE notes='$ne', contacted_numbers='$cj'");
    header('Location: lawyer_daily_journal.php?msg=saved'); exit;
}

/* ── قراءة البيانات ── */
$today = $conn->query("SELECT * FROM daily_journal WHERE office_id=$oid AND user_id=$uid AND entry_date=CURDATE() LIMIT 1")->fetch_assoc();
$todayContacts = !empty($today['contacted_numbers']) ? (json_decode($today['contacted_numbers'], true) ?: []) : [];

$from_f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to_f   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$scope  = $_canApprove ? '' : " AND j.user_id=$uid";
$records = [];
$rr = $conn->query("SELECT j.*, u.full_name FROM daily_journal j
    LEFT JOIN users u ON u.id=j.user_id
    WHERE j.office_id=$oid AND j.entry_date BETWEEN '$from_f' AND '$to_f'$scope
    ORDER BY j.entry_date DESC, u.full_name LIMIT 300");
if ($rr) while ($x = $rr->fetch_assoc()) $records[] = $x;

include '../includes/office_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="me-auto">
    <div class="mk-page-title mb-0"><i class="fas fa-clipboard-list"></i> التقرير اليومي</div>
    <div class="mk-page-sub">سجّل إنجازاتك اليومية والأرقام التي تواصلت معها</div>
  </div>
  <a href="lawyer_daily_journal.php?export_csv=1&from=<?= e($from_f) ?>&to=<?= e($to_f) ?>" class="btn btn-outline-success btn-sm">
    <i class="fas fa-file-excel me-1"></i>تصدير Excel
  </a>
</div>

<?php if (isset($_GET['msg'])): ?>
  <?php $msgs = ['saved'=>['success','تم حفظ تقريرك اليوم ✓'],'empty'=>['warning','يرجى كتابة ماذا أنجزت'],'denied'=>['danger','ليس لديك صلاحية']]; $m=$msgs[$_GET['msg']]??null; if($m): ?>
  <div class="alert alert-<?= $m[0] ?> alert-dismissible fade show py-2"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; endif; ?>

<?php if ($_canAdd): ?>
<!-- ── نموذج اليوم ── -->
<div class="card mb-4">
  <div class="card-header fw-bold d-flex align-items-center gap-2">
    <i class="fas fa-pen-to-square text-primary"></i>
    تقريرك اليوم
    <span class="badge bg-light text-secondary fw-normal ms-auto" style="font-size:12px"><?= date('Y-m-d') ?></span>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="form_type" value="entry">

      <!-- ماذا أنجزت -->
      <div class="mb-4">
        <label class="form-label fw-semibold"><i class="fas fa-check-circle text-success me-1"></i>ماذا أنجزت اليوم؟ <span class="text-danger">*</span></label>
        <textarea name="notes" class="form-control" rows="4"
          placeholder="مثال: راجعت ملف قضية رقم 1023، حضرت جلسة محكمة الاستئناف، أرسلت مراسلة للعميل أبو فهد..."
          required><?= e($today['notes'] ?? '') ?></textarea>
      </div>

      <!-- أرقام التواصل -->
      <div class="mb-3">
        <label class="form-label fw-semibold"><i class="fas fa-phone text-primary me-1"></i>الأرقام التي تواصلت معها اليوم</label>
        <div id="contactsWrap">
          <?php
          $initRows = $todayContacts ?: [['name'=>'','phone'=>'']];
          foreach ($initRows as $cr): ?>
          <div class="row g-2 mb-2 contact-entry">
            <div class="col-5">
              <input type="text" name="c_name[]" class="form-control form-control-sm" placeholder="الاسم (اختياري)" value="<?= e($cr['name'] ?? '') ?>">
            </div>
            <div class="col-5">
              <input type="tel" name="c_phone[]" class="form-control form-control-sm" placeholder="رقم الجوال" value="<?= e($cr['phone'] ?? '') ?>" dir="ltr">
            </div>
            <div class="col-2 d-flex align-items-center">
              <button type="button" class="btn btn-sm btn-outline-danger remove-contact py-0 px-2" title="حذف"><i class="fas fa-times"></i></button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" id="addContact" class="btn btn-sm btn-outline-secondary mt-1">
          <i class="fas fa-plus me-1"></i>إضافة رقم
        </button>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $today ? 'تحديث التقرير' : 'حفظ التقرير' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── فلتر التاريخ + عرض السجلات ── -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label form-label-sm mb-1">من</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from_f) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label form-label-sm mb-1">إلى</label>
        <input type="date" name="to"   class="form-control form-control-sm" value="<?= e($to_f) ?>">
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>عرض</button></div>
      <div class="col-auto me-auto">
        <a href="lawyer_daily_journal.php?export_csv=1&from=<?= e($from_f) ?>&to=<?= e($to_f) ?>" class="btn btn-sm btn-outline-success">
          <i class="fas fa-download me-1"></i>تصدير الفترة
        </a>
      </div>
    </form>
  </div>
</div>

<!-- ── جدول السجلات ── -->
<div class="card">
  <div class="card-header fw-bold d-flex align-items-center gap-2">
    <i class="fas fa-calendar-days me-1"></i>
    السجلات
    <span class="badge bg-primary ms-auto"><?= count($records) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (!$records): ?>
    <div class="text-center text-muted py-5">
      <i class="fas fa-clipboard fa-2x mb-2 d-block" style="color:#ccc"></i>
      لا توجد تقارير في هذه الفترة
    </div>
    <?php else: ?>
    <div class="accordion accordion-flush" id="journalAccordion">
    <?php foreach ($records as $i => $r):
      $rContacts = !empty($r['contacted_numbers']) ? (json_decode($r['contacted_numbers'], true) ?: []) : [];
      $rContacts = array_filter($rContacts, fn($c) => ($c['name'] ?? '') !== '' || ($c['phone'] ?? '') !== '');
    ?>
    <div class="accordion-item border-0 border-bottom">
      <h2 class="accordion-header">
        <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?> py-3" type="button"
                data-bs-toggle="collapse" data-bs-target="#j<?= $i ?>">
          <div class="d-flex align-items-center gap-3 w-100">
            <span class="badge bg-light text-secondary border" style="font-size:12px;min-width:90px"><?= e($r['entry_date']) ?></span>
            <?php if ($_canApprove): ?>
            <span class="fw-semibold text-dark"><?= e($r['full_name'] ?: '—') ?></span>
            <?php endif; ?>
            <span class="text-muted text-truncate" style="font-size:13px;max-width:380px"><?= e(mb_substr($r['notes'],0,80)) ?><?= mb_strlen($r['notes'])>80?'…':'' ?></span>
            <?php if ($rContacts): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle ms-auto me-2" style="font-size:11px">
              <i class="fas fa-phone fa-xs me-1"></i><?= count($rContacts) ?> رقم
            </span>
            <?php endif; ?>
          </div>
        </button>
      </h2>
      <div id="j<?= $i ?>" class="accordion-collapse collapse <?= $i===0?'show':'' ?>">
        <div class="accordion-body py-3">
          <!-- الإنجازات -->
          <div class="mb-3">
            <div class="text-muted small mb-1"><i class="fas fa-check-circle text-success me-1"></i>ما تم إنجازه</div>
            <div style="white-space:pre-wrap;font-size:14px;line-height:1.7"><?= e($r['notes']) ?></div>
          </div>
          <!-- الأرقام -->
          <?php if ($rContacts): ?>
          <div>
            <div class="text-muted small mb-2"><i class="fas fa-phone text-primary me-1"></i>الأرقام التي تم التواصل معها</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($rContacts as $cn): ?>
              <div class="d-flex align-items-center gap-2 px-3 py-1 rounded-pill border bg-light" style="font-size:13px">
                <?php if ($cn['name'] ?? ''): ?><span class="fw-semibold"><?= e($cn['name']) ?></span><?php endif; ?>
                <?php if ($cn['phone'] ?? ''): ?>
                <a href="tel:<?= e($cn['phone']) ?>" class="text-decoration-none" dir="ltr"><?= e($cn['phone']) ?></a>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* إضافة صف رقم جديد */
document.getElementById('addContact')?.addEventListener('click', function() {
  const wrap = document.getElementById('contactsWrap');
  const row = document.createElement('div');
  row.className = 'row g-2 mb-2 contact-entry';
  row.innerHTML = `
    <div class="col-5"><input type="text"  name="c_name[]"  class="form-control form-control-sm" placeholder="الاسم (اختياري)"></div>
    <div class="col-5"><input type="tel"   name="c_phone[]" class="form-control form-control-sm" placeholder="رقم الجوال" dir="ltr"></div>
    <div class="col-2 d-flex align-items-center">
      <button type="button" class="btn btn-sm btn-outline-danger remove-contact py-0 px-2" title="حذف"><i class="fas fa-times"></i></button>
    </div>`;
  wrap.appendChild(row);
  row.querySelector('input').focus();
});

/* حذف صف */
document.getElementById('contactsWrap')?.addEventListener('click', function(e) {
  const btn = e.target.closest('.remove-contact');
  if (!btn) return;
  const rows = this.querySelectorAll('.contact-entry');
  if (rows.length > 1) btn.closest('.contact-entry').remove();
  else { btn.closest('.contact-entry').querySelectorAll('input').forEach(i => i.value = ''); }
});
</script>

<?php include '../includes/office_footer.php'; ?>
