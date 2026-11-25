<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/whatsapp.php';
requireOffice();
if (!can('clients','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
$uid = (int)($_SESSION['user_id'] ?? 0);
$page_title = 'بث واتساب — دليل الاتصال';

/* ── إعدادات واتساب الخاصة بالمكتب ── */
$conn->query("CREATE TABLE IF NOT EXISTS office_wa_settings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    office_id  INT NOT NULL UNIQUE,
    provider   ENUM('greenapi','ultramsg','meta') NOT NULL DEFAULT 'greenapi',
    instance   VARCHAR(200) DEFAULT NULL,
    token      VARCHAR(500) DEFAULT NULL,
    extra      VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$wa_cfg = ['provider'=>'','instance'=>'','token'=>'','extra'=>''];
$wr = $conn->query("SELECT provider,instance,token,extra FROM office_wa_settings WHERE office_id=$oid");
if ($wr && $wrow = $wr->fetch_assoc()) $wa_cfg = $wrow;
$wa_ready = ($wa_cfg['instance'] !== '' && $wa_cfg['token'] !== '');

/* ── جدول سجل الإرسال ── */
$conn->query("CREATE TABLE IF NOT EXISTS wa_broadcast_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    office_id   INT NOT NULL,
    contact_id  INT DEFAULT NULL,
    phone       VARCHAR(40) NOT NULL,
    contact_name VARCHAR(200) DEFAULT NULL,
    message     TEXT NOT NULL,
    status      ENUM('sent','failed') DEFAULT 'failed',
    provider_msg VARCHAR(255) DEFAULT NULL,
    sent_by     INT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id),
    INDEX idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── إرسال AJAX ── */
if (isset($_GET['ajax_send']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!can('clients','add')) { echo json_encode(['ok'=>false,'msg'=>'لا صلاحية']); exit; }

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $phone      = trim($_POST['phone'] ?? '');
    $name       = mb_substr(trim($_POST['name'] ?? ''), 0, 200);
    $tpl        = mb_substr(trim($_POST['message'] ?? ''), 0, 1000);

    if ($phone === '' || $tpl === '') { echo json_encode(['ok'=>false,'msg'=>'بيانات ناقصة']); exit; }

    /* استبدال {الاسم} بالاسم الفعلي */
    $msg = str_replace('{الاسم}', $name ?: 'عزيزي العميل', $tpl);

    /* قراءة إعدادات المكتب */
    $wr2 = $conn->query("SELECT provider,instance,token FROM office_wa_settings WHERE office_id=$oid");
    $wcfg = ($wr2 && $wrow2=$wr2->fetch_assoc()) ? $wrow2 : [];
    if (empty($wcfg['instance']) || empty($wcfg['token'])) {
        echo json_encode(['ok'=>false,'msg'=>'واتساب غير مهيَّأ — اضبط الإعدادات أولاً']);
        exit;
    }

    $to_normalized = wa_normalize_phone($phone);
    $prov = $wcfg['provider'];
    $inst = $wcfg['instance'];
    $tok  = $wcfg['token'];

    if ($prov === 'greenapi') {
        $base = 'https://api.green-api.com';
        $r = wa_http('POST', "{$base}/waInstance{$inst}/sendMessage/{$tok}",
            ['Content-Type: application/json'],
            ['chatId' => $to_normalized . '@c.us', 'message' => $msg]);
        $ok_send = $r['ok'] && is_array($r['data']) && !empty($r['data']['idMessage']);
        $res = ['ok' => $ok_send, 'msg' => $ok_send ? 'أُرسلت' : ('فشل: ' . json_encode($r['data'] ?? $r['error'], JSON_UNESCAPED_UNICODE))];
    } elseif ($prov === 'ultramsg') {
        $r = wa_http('POST', "https://api.ultramsg.com/{$inst}/messages/chat",
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['token'=>$tok,'to'=>$to_normalized,'body'=>$msg]), false);
        $ok_send = $r['ok'] && is_array($r['data']) && (($r['data']['sent']??'') === 'true' || ($r['data']['sent']??false) === true);
        $res = ['ok'=>$ok_send,'msg'=>$ok_send?'أُرسلت':('فشل: '.($r['data']['error']??json_encode($r['data'],JSON_UNESCAPED_UNICODE)))];
    } elseif ($prov === 'meta') {
        $r = wa_http('POST', "https://graph.facebook.com/v21.0/{$inst}/messages",
            ['Authorization: Bearer '.$tok,'Content-Type: application/json'],
            ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to_normalized,'type'=>'text','text'=>['preview_url'=>false,'body'=>$msg]]);
        $ok_send = $r['ok'] && is_array($r['data']) && !empty($r['data']['messages']);
        $res = ['ok'=>$ok_send,'msg'=>$ok_send?'أُرسلت':('فشل: '.($r['data']['error']['message']??json_encode($r['data'],JSON_UNESCAPED_UNICODE)))];
    } else {
        $res = ['ok'=>false,'msg'=>'مزوّد غير معروف'];
    }

    $ne = $conn->real_escape_string($name);
    $pe = $conn->real_escape_string($phone);
    $me = $conn->real_escape_string(mb_substr($msg, 0, 1000));
    $st = $res['ok'] ? 'sent' : 'failed';
    $pm = $conn->real_escape_string(mb_substr($res['msg'] ?? '', 0, 255));
    $conn->query("INSERT INTO wa_broadcast_log (office_id,contact_id,phone,contact_name,message,status,provider_msg,sent_by)
        VALUES ($oid,".($contact_id ?: 'NULL').",'$pe','$ne','$me','$st','$pm',".($uid ?: 'NULL').")");

    echo json_encode(['ok'=>$res['ok'], 'msg'=>$res['msg']]); exit;
}

/* ── قائمة جهات الاتصال (فقط اللي عندهم جوال) ── */
$contacts = [];
$res = $conn->query("SELECT id, full_name, phone, email FROM office_contacts WHERE office_id=$oid AND phone IS NOT NULL AND phone<>'' ORDER BY full_name");
if ($res) while ($r = $res->fetch_assoc()) $contacts[] = $r;

/* ── سجل آخر إرسال ── */
$logs = [];
$lr = $conn->query("SELECT l.*, u.full_name AS sender FROM wa_broadcast_log l LEFT JOIN users u ON u.id=l.sent_by WHERE l.office_id=$oid ORDER BY l.id DESC LIMIT 50");
if ($lr) while ($x = $lr->fetch_assoc()) $logs[] = $x;

include '../includes/office_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="me-auto">
    <div class="mk-page-title mb-0"><i class="fab fa-whatsapp" style="color:#25d366"></i> إرسال واتساب جماعي</div>
    <div class="mk-page-sub">أرسل رسائل دعائية أو عروض لجهات دليل الاتصال عبر واتساب</div>
  </div>
  <a href="contacts.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-right me-1"></i>دليل الاتصال</a>
</div>

<?php if (!$wa_ready): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start">
  <i class="fas fa-triangle-exclamation mt-1 flex-shrink-0"></i>
  <div>
    واتساب غير مهيَّأ بعد.
    <a href="wa_settings.php" class="alert-link fw-bold">اضبط إعدادات واتساب الآن</a>
    واربط رقم جوالك أو رقم المكتب.
  </div>
</div>
<?php endif; ?>

<?php if (!$contacts): ?>
<div class="alert alert-info">
  <i class="fas fa-circle-info me-1"></i>
  لا توجد جهات اتصال تحتوي على رقم جوال.
  <a href="contacts.php" class="alert-link">أضف جهات اتصال</a> أولاً.
</div>
<?php else: ?>

<div class="row g-4">
  <!-- العمود الأيسر: الرسالة + الأزرار -->
  <div class="col-lg-4">
    <div class="card sticky-top" style="top:80px">
      <div class="card-header fw-bold"><i class="fas fa-message me-1"></i>الرسالة</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">نص الرسالة <span class="text-danger">*</span></label>
          <textarea id="msgTpl" class="form-control" rows="6" maxlength="1000"
            placeholder="اكتب رسالتك هنا...&#10;استخدم {الاسم} ليُستبدل باسم كل شخص تلقائياً."></textarea>
          <div class="text-muted mt-1" style="font-size:11px"><i class="fas fa-info-circle me-1"></i>تلميح: {الاسم} يُستبدل باسم كل جهة اتصال</div>
        </div>

        <!-- الإجراءات -->
        <div class="d-flex flex-column gap-2">
          <button class="btn btn-success" id="btnSendAll" onclick="sendAll()">
            <i class="fab fa-whatsapp me-1"></i>إرسال للكل المحددين
            (<span id="selectedCount">0</span>)
          </button>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="selectAll(true)">تحديد الكل</button>
            <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="selectAll(false)">إلغاء الكل</button>
          </div>
        </div>

        <!-- شريط التقدم -->
        <div id="progressWrap" class="d-none mt-3">
          <div class="d-flex justify-content-between mb-1" style="font-size:12px">
            <span id="progressLabel">جاري الإرسال…</span>
            <span id="progressPct">0%</span>
          </div>
          <div class="progress" style="height:6px">
            <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="progressBar" style="width:0%"></div>
          </div>
          <div class="d-flex gap-3 mt-2" style="font-size:12px">
            <span class="text-success"><i class="fas fa-check me-1"></i><span id="cntSent">0</span> أُرسلت</span>
            <span class="text-danger"><i class="fas fa-times me-1"></i><span id="cntFail">0</span> فشل</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- العمود الأيمن: قائمة جهات الاتصال -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <span class="fw-bold"><i class="fas fa-address-book me-1"></i>جهات الاتصال</span>
        <span class="badge bg-primary"><?= count($contacts) ?></span>
        <div class="ms-auto">
          <div class="position-relative">
            <i class="fas fa-search position-absolute text-muted" style="top:50%;left:10px;transform:translateY(-50%);font-size:12px;pointer-events:none"></i>
            <input type="search" id="searchContacts" class="form-control form-control-sm" placeholder="بحث…" style="padding-left:30px;width:160px" dir="rtl">
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="list-group list-group-flush" id="contactList">
        <?php foreach ($contacts as $c):
          $initials = mb_substr($c['full_name'], 0, 1, 'UTF-8');
          $colors   = ['#1a6eb5','#2e7d5e','#7b3fa0','#c0621f','#b5341a','#1a7eb5'];
          $color    = $colors[$c['id'] % count($colors)];
        ?>
        <label class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 contact-row"
               data-name="<?= e(mb_strtolower($c['full_name'])) ?>" style="cursor:pointer">
          <input type="checkbox" class="form-check-input contact-chk flex-shrink-0" value="<?= $c['id'] ?>"
                 data-phone="<?= e($c['phone']) ?>" data-name="<?= e($c['full_name']) ?>">
          <div class="d-flex align-items-center justify-content-center rounded-circle text-white fw-bold flex-shrink-0"
               style="width:36px;height:36px;font-size:15px;background:<?= $color ?>">
            <?= e($initials) ?>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold" style="font-size:14px"><?= e($c['full_name']) ?></div>
            <div class="text-muted" style="font-size:12px" dir="ltr"><?= e($c['phone']) ?></div>
          </div>
          <!-- حالة الإرسال لهذا السجل -->
          <span class="send-status" style="font-size:12px;min-width:60px;text-align:center"></span>
        </label>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ── سجل آخر 50 إرسالة ── -->
<?php if ($logs): ?>
<div class="card mt-4">
  <div class="card-header fw-bold d-flex align-items-center gap-2">
    <i class="fas fa-clock-rotate-left me-1"></i>آخر عمليات الإرسال
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle" style="font-size:12.5px">
      <thead class="table-light">
        <tr><th>الاسم</th><th>الرقم</th><th>الحالة</th><th>المُرسِل</th><th>الوقت</th></tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= e($l['contact_name'] ?: '—') ?></td>
        <td dir="ltr"><?= e($l['phone']) ?></td>
        <td>
          <?php if ($l['status'] === 'sent'): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check fa-xs me-1"></i>أُرسلت</span>
          <?php else: ?>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="<?= e($l['provider_msg']) ?>"><i class="fas fa-times fa-xs me-1"></i>فشل</span>
          <?php endif; ?>
        </td>
        <td><?= e($l['sender'] ?: '—') ?></td>
        <td class="text-muted"><?= date('Y-m-d H:i', strtotime($l['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
/* ── تحديد / إلغاء الكل ── */
function selectAll(val) {
  document.querySelectorAll('.contact-chk').forEach(c => c.checked = val);
  updateCount();
}

function updateCount() {
  const n = document.querySelectorAll('.contact-chk:checked').length;
  document.getElementById('selectedCount').textContent = n;
}

document.querySelectorAll('.contact-chk').forEach(c => c.addEventListener('change', updateCount));

/* ── بحث سريع ── */
document.getElementById('searchContacts')?.addEventListener('input', function() {
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('#contactList .contact-row').forEach(row => {
    row.style.display = (!q || row.dataset.name.includes(q)) ? '' : 'none';
  });
});

/* ── إرسال جماعي ── */
async function sendAll() {
  const msg = document.getElementById('msgTpl').value.trim();
  if (!msg) { alert('يرجى كتابة الرسالة أولاً'); return; }

  const checked = [...document.querySelectorAll('.contact-chk:checked')];
  if (!checked.length) { alert('لم تحدد أي جهة اتصال'); return; }

  if (!confirm(`سيتم إرسال رسالة واتساب لـ ${checked.length} جهة اتصال. متأكد؟`)) return;

  document.getElementById('progressWrap').classList.remove('d-none');
  document.getElementById('btnSendAll').disabled = true;

  let sent = 0, fail = 0;

  for (let i = 0; i < checked.length; i++) {
    const chk   = checked[i];
    const phone = chk.dataset.phone;
    const name  = chk.dataset.name;
    const cid   = chk.value;
    const statusEl = chk.closest('label').querySelector('.send-status');

    statusEl.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin fa-xs"></i></span>';

    const form = new FormData();
    form.append('contact_id', cid);
    form.append('phone',  phone);
    form.append('name',   name);
    form.append('message', msg);

    try {
      const r = await fetch('wa_broadcast.php?ajax_send=1', { method:'POST', body: form });
      const data = await r.json();
      if (data.ok) {
        sent++;
        statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check fa-xs"></i> أُرسلت</span>';
      } else {
        fail++;
        statusEl.innerHTML = `<span class="text-danger" title="${data.msg}"><i class="fas fa-times fa-xs"></i> فشل</span>`;
      }
    } catch(e) {
      fail++;
      statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times fa-xs"></i> خطأ</span>';
    }

    const pct = Math.round(((i+1)/checked.length)*100);
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressPct').textContent = pct + '%';
    document.getElementById('cntSent').textContent = sent;
    document.getElementById('cntFail').textContent = fail;

    /* تأخير 1.2 ثانية بين الرسائل لتجنب الحظر */
    if (i < checked.length - 1) await new Promise(r => setTimeout(r, 1200));
  }

  document.getElementById('progressLabel').textContent = `اكتمل — ${sent} أُرسلت، ${fail} فشل`;
  document.getElementById('progressBar').classList.remove('progress-bar-animated');
  document.getElementById('btnSendAll').disabled = false;
}
</script>

<?php include '../includes/office_footer.php'; ?>
