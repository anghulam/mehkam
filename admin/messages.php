<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
$page_title = 'رسائل اتصل بنا';

// إنشاء الجدول إن لم يكن موجوداً
$conn->query("CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    email       VARCHAR(200) NOT NULL,
    phone       VARCHAR(50)  DEFAULT '',
    subject     VARCHAR(200) DEFAULT '',
    message     TEXT         NOT NULL,
    is_read     TINYINT(1)   DEFAULT 0,
    status      ENUM('new','read','replied','archived') DEFAULT 'new',
    admin_notes TEXT,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

/* ── إجراءات ── */
if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM contact_messages WHERE id=".(int)$_GET['delete']);
    header("Location: messages.php?msg=deleted"); exit;
}
if (isset($_GET['archive'])) {
    $conn->query("UPDATE contact_messages SET status='archived' WHERE id=".(int)$_GET['archive']);
    header("Location: messages.php?msg=archived"); exit;
}
if (isset($_GET['mark_replied'])) {
    $conn->query("UPDATE contact_messages SET status='replied',is_read=1 WHERE id=".(int)$_GET['mark_replied']);
    header("Location: messages.php?msg=replied"); exit;
}
if (isset($_GET['read_all'])) {
    $conn->query("UPDATE contact_messages SET is_read=1,status='read' WHERE status='new'");
    header("Location: messages.php"); exit;
}

// حفظ ملاحظة أدمن
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['note_id'])) {
    $nid  = (int)$_POST['note_id'];
    $note = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    $conn->query("UPDATE contact_messages SET admin_notes='$note',is_read=1,status='read' WHERE id=$nid");
    header("Location: messages.php?msg=note_saved&open=$nid"); exit;
}

// تعيين رسالة مقروءة عند الفتح
if (isset($_GET['open'])) {
    $conn->query("UPDATE contact_messages SET is_read=1, status=IF(status='new','read',status) WHERE id=".(int)$_GET['open']);
}

/* ── فلاتر ── */
$where = "1=1";
if (!empty($_GET['status_f']) && in_array($_GET['status_f'],['new','read','replied','archived'])) {
    $sf = $_GET['status_f'];
    $where .= " AND status='$sf'";
}
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (name LIKE '%$q%' OR email LIKE '%$q%' OR subject LIKE '%$q%' OR message LIKE '%$q%')";
}
if (!empty($_GET['from'])) $where .= " AND DATE(created_at) >= '".$conn->real_escape_string($_GET['from'])."'";
if (!empty($_GET['to']))   $where .= " AND DATE(created_at) <= '".$conn->real_escape_string($_GET['to'])."'";

/* ── إحصائيات ── */
$total    = (int)$conn->query("SELECT COUNT(*) c FROM contact_messages")->fetch_assoc()['c'];
$unread   = (int)$conn->query("SELECT COUNT(*) c FROM contact_messages WHERE is_read=0")->fetch_assoc()['c'];
$replied  = (int)$conn->query("SELECT COUNT(*) c FROM contact_messages WHERE status='replied'")->fetch_assoc()['c'];
$today    = (int)$conn->query("SELECT COUNT(*) c FROM contact_messages WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'];

/* ── الرسائل ── */
$msgs = $conn->query("SELECT * FROM contact_messages WHERE $where ORDER BY created_at DESC");
if (!($msgs instanceof mysqli_result)) {
    $msgs = $conn->query("SELECT * FROM contact_messages ORDER BY created_at DESC");
}

/* ── رسالة مفتوحة ── */
$openMsg = null;
if (isset($_GET['open'])) {
    $openMsg = $conn->query("SELECT * FROM contact_messages WHERE id=".(int)$_GET['open'])->fetch_assoc();
}

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>
  <?= [
    'deleted'    => 'تم حذف الرسالة',
    'archived'   => 'تم أرشفة الرسالة',
    'replied'    => 'تم تعيين الرسالة كـ "تم الرد"',
    'note_saved' => 'تم حفظ الملاحظة',
  ][$_GET['msg']] ?? 'تم الإجراء' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- إحصائيات سريعة -->
<div class="row g-3 mb-4">
  <?php
  $stats = [
    ['الإجمالي',         $total,   'envelope',        '#4f46e5', '#eef2ff'],
    ['غير مقروءة',       $unread,  'envelope-open',   '#dc2626', '#fef2f2'],
    ['تم الرد',          $replied, 'reply',           '#16a34a', '#f0fdf4'],
    ['اليوم',            $today,   'calendar-day',    '#d97706', '#fffbeb'],
  ];
  foreach ($stats as [$lbl,$val,$ico,$clr,$bg]):
  ?>
  <div class="col-6 col-md-3">
    <div class="card" style="border:none;border-radius:14px;background:<?= $bg ?>;box-shadow:0 1px 8px <?= $clr ?>20">
      <div class="card-body d-flex align-items-center gap-3 p-3">
        <div style="width:44px;height:44px;border-radius:12px;background:<?= $clr ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-<?= $ico ?>" style="color:#fff;font-size:17px"></i>
        </div>
        <div>
          <div style="font-size:22px;font-weight:900;color:<?= $clr ?>;line-height:1"><?= $val ?></div>
          <div style="font-size:12px;color:#64748b;margin-top:2px"><?= $lbl ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- فلتر + أدوات -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="GET">
      <div style="flex:1 1 220px;min-width:220px">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="بحث بالاسم أو البريد أو الموضوع..." value="<?= e($_GET['q'] ?? '') ?>">
      </div>
      <div style="flex:1 1 170px;min-width:170px">
        <select name="status_f" class="form-select form-select-sm">
          <option value="">كل الرسائل</option>
          <option value="new"      <?= ($_GET['status_f']??'')==='new'?'selected':'' ?>>🔴 جديدة</option>
          <option value="read"     <?= ($_GET['status_f']??'')==='read'?'selected':'' ?>>رمادي مقروءة</option>
          <option value="replied"  <?= ($_GET['status_f']??'')==='replied'?'selected':'' ?>>🟢 تم الرد</option>
          <option value="archived" <?= ($_GET['status_f']??'')==='archived'?'selected':'' ?>>مؤرشفة</option>
        </select>
      </div>
      <div style="flex:1 1 240px;min-width:240px">
        <label class="form-label mb-1" style="font-size:11px;color:#64748b">من تاريخ</label>
        <input type="date" name="from" class="form-control form-control-sm mk-plain-date" value="<?= e($_GET['from'] ?? '') ?>" placeholder="من تاريخ">
      </div>
      <div style="flex:1 1 240px;min-width:240px">
        <label class="form-label mb-1" style="font-size:11px;color:#64748b">إلى تاريخ</label>
        <input type="date" name="to" class="form-control form-control-sm mk-plain-date" value="<?= e($_GET['to'] ?? '') ?>" placeholder="إلى تاريخ">
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="messages.php" class="btn btn-outline-secondary btn-sm">إعادة</a>
        <?php if ($unread > 0): ?>
        <a href="messages.php?read_all=1" class="btn btn-outline-info btn-sm ms-auto"
           onclick="return confirm('تعيين كل الجديدة كمقروءة؟')">
          <i class="fas fa-check-double me-1"></i>قراءة الكل
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- جدول الرسائل + العرض المنقسم -->
<div class="row g-3">

  <!-- قائمة الرسائل -->
  <div class="<?= $openMsg ? 'col-lg-5' : 'col-12' ?>">
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" style="font-size:13px">
            <thead>
              <tr style="background:#f8fafc">
                <th style="padding:10px 16px">المرسل</th>
                <th>الموضوع</th>
                <th class="<?= $openMsg ? 'd-none' : '' ?>">الرسالة</th>
                <th>التاريخ</th>
                <th>إجراء</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $statusConf = [
                'new'      => ['bg-danger',   'جديدة'],
                'read'     => ['bg-secondary','مقروءة'],
                'replied'  => ['bg-success',  'تم الرد'],
                'archived' => ['bg-dark',     'مؤرشفة'],
            ];
            $any = false;
            while ($row = $msgs->fetch_assoc()):
                $any = true;
                $isOpen = $openMsg && $openMsg['id'] == $row['id'];
                [$sc1,$scLabel] = $statusConf[$row['status']] ?? ['bg-secondary','—'];
            ?>
            <tr style="<?= $isOpen ? 'background:#eff6ff;' : '' ?><?= !$row['is_read'] ? 'font-weight:700;' : '' ?>">
              <td style="padding:10px 16px;vertical-align:middle">
                <div class="d-flex align-items-center gap-2">
                  <?php if (!$row['is_read']): ?>
                  <span style="width:8px;height:8px;border-radius:50%;background:#dc2626;flex-shrink:0"></span>
                  <?php endif; ?>
                  <div>
                    <div style="color:#0c1b36"><?= e($row['name']) ?></div>
                    <div style="font-size:11px;color:#94a3b8;font-weight:400"><?= e($row['email']) ?></div>
                  </div>
                </div>
              </td>
              <td style="vertical-align:middle">
                <span class="badge <?= $sc1 ?>" style="font-size:10px;margin-left:4px"><?= $scLabel ?></span>
                <?= e(mb_substr($row['subject'],0,20)) ?>
              </td>
              <td class="<?= $openMsg ? 'd-none' : '' ?>" style="vertical-align:middle;color:#64748b;max-width:220px">
                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= e(mb_substr($row['message'],0,60)) ?>…
                </div>
              </td>
              <td style="vertical-align:middle;color:#94a3b8;white-space:nowrap;font-weight:400">
                <?= date('d/m/Y', strtotime($row['created_at'])) ?>
                <div style="font-size:11px"><?= date('H:i', strtotime($row['created_at'])) ?></div>
              </td>
              <td style="vertical-align:middle;white-space:nowrap">
                <a href="messages.php?open=<?= $row['id'] ?><?= !empty($_GET['status_f'])?'&status_f='.e($_GET['status_f']):'' ?>"
                   class="btn btn-sm <?= $isOpen ? 'btn-primary' : 'btn-outline-primary' ?>" title="عرض">
                  <i class="fas fa-eye"></i>
                </a>
                <a href="messages.php?archive=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary" title="أرشفة"
                   onclick="return confirm('أرشفة هذه الرسالة؟')"><i class="fas fa-archive"></i></a>
                <a href="messages.php?delete=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" title="حذف"
                   onclick="return confirm('حذف هذه الرسالة نهائياً؟')"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if (!$any): ?>
            <tr><td colspan="5" class="text-center py-5 text-muted">
              <i class="fas fa-inbox fa-2x mb-2 d-block" style="opacity:.3"></i>
              لا توجد رسائل<?= !empty($_GET['q']) ? ' تطابق البحث' : '' ?>
            </td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- لوحة عرض الرسالة المفتوحة -->
  <?php if ($openMsg): ?>
  <div class="col-lg-7">
    <div class="card" style="border:none;box-shadow:0 2px 20px rgba(12,27,54,.08)">

      <!-- رأس البطاقة -->
      <div style="padding:20px 24px;border-bottom:1px solid #f0f4f8;display:flex;align-items:flex-start;gap:14px">
        <div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#e8c040;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;flex-shrink:0">
          <?= mb_substr($openMsg['name'],0,1) ?>
        </div>
        <div style="flex:1">
          <div class="fw-bold" style="color:#0c1b36;font-size:15px"><?= e($openMsg['name']) ?></div>
          <a href="mailto:<?= e($openMsg['email']) ?>" style="font-size:12px;color:#4f46e5;text-decoration:none">
            <i class="fas fa-envelope me-1"></i><?= e($openMsg['email']) ?>
          </a>
          <?php if ($openMsg['phone']): ?>
          <a href="tel:<?= e($openMsg['phone']) ?>" style="font-size:12px;color:#0891b2;text-decoration:none;margin-right:12px">
            <i class="fas fa-phone me-1"></i><?= e($openMsg['phone']) ?>
          </a>
          <?php endif; ?>
        </div>
        <div class="text-end" style="flex-shrink:0">
          <?php [$sc1,$scLabel] = $statusConf[$openMsg['status']] ?? ['bg-secondary','—']; ?>
          <span class="badge <?= $sc1 ?>"><?= $scLabel ?></span>
          <div style="font-size:11px;color:#94a3b8;margin-top:4px">
            <?= date('d/m/Y H:i', strtotime($openMsg['created_at'])) ?>
          </div>
        </div>
      </div>

      <!-- موضوع + نص الرسالة -->
      <div style="padding:20px 24px">
        <?php if ($openMsg['subject']): ?>
        <div style="font-size:11px;font-weight:800;color:#94a3b8;letter-spacing:.6px;text-transform:uppercase;margin-bottom:6px">الموضوع</div>
        <div style="font-size:15px;font-weight:700;color:#1e293b;margin-bottom:16px"><?= e($openMsg['subject']) ?></div>
        <?php endif; ?>

        <div style="font-size:11px;font-weight:800;color:#94a3b8;letter-spacing:.6px;text-transform:uppercase;margin-bottom:8px">الرسالة</div>
        <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;line-height:1.9;color:#374151;font-size:14px;white-space:pre-wrap;word-break:break-word">
          <?= e($openMsg['message']) ?>
        </div>

        <?php if ($openMsg['ip_address']): ?>
        <div style="font-size:11px;color:#cbd5e1;margin-top:8px"><i class="fas fa-globe me-1"></i>IP: <?= e($openMsg['ip_address']) ?></div>
        <?php endif; ?>
      </div>

      <!-- أزرار الرد والإجراءات -->
      <div style="padding:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="mailto:<?= e($openMsg['email']) ?>?subject=رداً على: <?= rawurlencode($openMsg['subject'] ?: 'رسالتك') ?>"
           class="btn btn-sm btn-primary" target="_blank">
          <i class="fas fa-reply me-1"></i>رد بالبريد
        </a>
        <?php if ($openMsg['phone']): ?>
        <a href="https://wa.me/966<?= ltrim(preg_replace('/\D/','',$openMsg['phone']),'0') ?>"
           class="btn btn-sm" style="background:#25D366;color:#fff;border:none" target="_blank">
          <i class="fab fa-whatsapp me-1"></i>واتساب
        </a>
        <?php endif; ?>
        <?php if ($openMsg['status'] !== 'replied'): ?>
        <a href="messages.php?mark_replied=<?= $openMsg['id'] ?>" class="btn btn-sm btn-outline-success"
           onclick="return confirm('تعيين كـ تم الرد؟')">
          <i class="fas fa-check me-1"></i>تم الرد
        </a>
        <?php endif; ?>
        <a href="messages.php?archive=<?= $openMsg['id'] ?>" class="btn btn-sm btn-outline-secondary"
           onclick="return confirm('أرشفة؟')"><i class="fas fa-archive me-1"></i>أرشفة</a>
        <a href="messages.php?delete=<?= $openMsg['id'] ?>" class="btn btn-sm btn-outline-danger ms-auto"
           onclick="return confirm('حذف نهائياً؟')"><i class="fas fa-trash"></i></a>
      </div>

      <!-- ملاحظة الأدمن -->
      <div style="padding:0 24px 24px">
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px 20px">
          <div style="font-size:12px;font-weight:800;color:#92400e;margin-bottom:8px">
            <i class="fas fa-sticky-note me-1"></i>ملاحظة داخلية (لا تُرسل للعميل)
          </div>
          <form method="POST">
            <input type="hidden" name="note_id" value="<?= $openMsg['id'] ?>">
            <textarea name="admin_notes" rows="3"
              style="width:100%;border:1px solid #fde68a;border-radius:8px;padding:10px;font-family:'Tajawal',sans-serif;font-size:13px;background:#fff;resize:vertical;outline:none"
              placeholder="اكتب ملاحظاتك الداخلية هنا..."><?= e($openMsg['admin_notes'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-sm mt-2" style="background:#f59e0b;color:#fff;border:none;border-radius:8px;padding:6px 16px">
              <i class="fas fa-save me-1"></i>حفظ الملاحظة
            </button>
          </form>
        </div>
      </div>

    </div>
  </div>
  <?php endif; ?>

</div><!-- /row -->

<?php include '../includes/admin_footer.php'; ?>
