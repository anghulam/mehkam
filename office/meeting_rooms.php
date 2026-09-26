<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('meeting_rooms','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'meeting_rooms')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'حجز قاعات الاجتماعات';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('meeting_rooms','add'); $_canDel = can('meeting_rooms','delete');
$_canApprove = can('meeting_rooms','approve'); // مستوى المدير: يدير القاعات نفسها ويلغي أي حجز

$conn->query("CREATE TABLE IF NOT EXISTS office_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    capacity INT DEFAULT NULL,
    location VARCHAR(200) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS room_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    room_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    booked_by INT DEFAULT NULL,
    status ENUM('confirmed','cancelled') DEFAULT 'confirmed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── إدارة القاعات (مستوى المدير) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'room') {
    if (!$_canApprove) { header('Location: meeting_rooms.php?msg=denied'); exit; }
    $rid = (int)($_POST['id'] ?? 0);
    $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $cap = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : 'NULL';
    $loc = $conn->real_escape_string(trim($_POST['location'] ?? ''));
    if ($rid) $conn->query("UPDATE office_rooms SET name='$name',capacity=$cap,location='$loc' WHERE id=$rid AND office_id=$oid");
    else $conn->query("INSERT INTO office_rooms (office_id,name,capacity,location) VALUES ($oid,'$name',$cap,'$loc')");
    header("Location: meeting_rooms.php?msg=saved"); exit;
}
if (isset($_GET['toggle_room']) && $_canApprove) {
    $conn->query("UPDATE office_rooms SET is_active=1-is_active WHERE id=".(int)$_GET['toggle_room']." AND office_id=$oid");
    header("Location: meeting_rooms.php?msg=saved"); exit;
}
if (isset($_GET['delete_room']) && $_canApprove) {
    $rrid = (int)$_GET['delete_room'];
    $conn->query("DELETE FROM room_bookings WHERE room_id=$rrid AND office_id=$oid");
    $conn->query("DELETE FROM office_rooms WHERE id=$rrid AND office_id=$oid");
    header("Location: meeting_rooms.php?msg=deleted"); exit;
}

/* ── حجز جديد ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'booking') {
    requirePerm('meeting_rooms', 'add', 'meeting_rooms.php?msg=denied');
    $room_id = (int)($_POST['room_id'] ?? 0);
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $start = $conn->real_escape_string(trim($_POST['start_time'] ?? ''));
    $end = $conn->real_escape_string(trim($_POST['end_time'] ?? ''));
    if ($room_id && $title && $start && $end && strtotime($end) > strtotime($start)) {
        $conflict = $conn->query("SELECT id FROM room_bookings WHERE room_id=$room_id AND office_id=$oid AND status='confirmed'
            AND start_time < '$end' AND end_time > '$start' LIMIT 1")->fetch_assoc();
        if (!$conflict) {
            $conn->query("INSERT INTO room_bookings (office_id,room_id,title,start_time,end_time,booked_by) VALUES ($oid,$room_id,'$title','$start','$end',".($uid ?: 'NULL').")");
            header("Location: meeting_rooms.php?msg=saved"); exit;
        }
        header("Location: meeting_rooms.php?msg=conflict"); exit;
    }
    header("Location: meeting_rooms.php?msg=invalid"); exit;
}

/* ── إلغاء حجز ── */
if (isset($_GET['cancel'])) {
    $bid = (int)$_GET['cancel'];
    $own = $_canApprove ? "" : " AND booked_by=$uid";
    if ($_canDel || $_canApprove) $conn->query("UPDATE room_bookings SET status='cancelled' WHERE id=$bid AND office_id=$oid$own");
    header("Location: meeting_rooms.php?msg=deleted"); exit;
}

/* ── بيانات ── */
$rooms = [];
$rr = $conn->query("SELECT * FROM office_rooms WHERE office_id=$oid ORDER BY is_active DESC, name ASC");
if ($rr) while ($r = $rr->fetch_assoc()) $rooms[$r['id']] = $r;

$bookings = [];
$br = $conn->query("SELECT b.*, r.name room_name, u.full_name booker_name FROM room_bookings b
    JOIN office_rooms r ON b.room_id=r.id LEFT JOIN users u ON b.booked_by=u.id
    WHERE b.office_id=$oid AND b.status='confirmed' AND b.end_time >= NOW()
    ORDER BY b.start_time ASC LIMIT 300");
if ($br) while ($r = $br->fetch_assoc()) $bookings[] = $r;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-door-closed"></i> حجز قاعات الاجتماعات</div>
<div class="mk-page-sub mb-3">جدولة قاعات ومصادر المكتب وتفادي تعارض الحجوزات تلقائياً</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم الحفظ بنجاح'],'deleted'=>['success','تم الإلغاء/الحذف بنجاح'],
         'conflict'=>['danger','هذه القاعة محجوزة بالفعل في هذا الوقت'],'invalid'=>['danger','بيانات الحجز غير صحيحة'],
         'denied'=>['danger','ليست لديك صلاحية لهذا الإجراء']];
  $m = $mm[$_GET['msg']] ?? ['success','تم بنجاح'];
?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><i class="fas fa-circle-info me-2"></i><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fas fa-door-closed me-1"></i>القاعات</span>
        <?php if ($_canApprove): ?><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="roomNew()"><i class="fas fa-plus"></i></button><?php endif; ?>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$rooms): ?><div class="list-group-item text-muted text-center py-4">لا توجد قاعات بعد</div>
        <?php else: foreach ($rooms as $r): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center <?= !$r['is_active']?'opacity-50':'' ?>">
          <div>
            <div class="fw-semibold"><?= e($r['name']) ?></div>
            <div class="text-muted" style="font-size:11px"><?php if ($r['capacity']): ?><i class="fas fa-users me-1"></i><?= $r['capacity'] ?><?php endif; ?> <?= e($r['location']) ?></div>
          </div>
          <?php if ($_canApprove): ?>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-primary" onclick='roomEdit(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
            <a href="meeting_rooms.php?toggle_room=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-power-off"></i></a>
            <a href="meeting_rooms.php?delete_room=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه القاعة وكل حجوزاتها؟')"><i class="fas fa-trash"></i></a>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="fw-bold"><i class="fas fa-calendar-check me-1"></i>الحجوزات القادمة</div>
      <?php if ($_canAdd): ?>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bookModal" <?= !$rooms?'disabled':'' ?>><i class="fas fa-plus me-1"></i>حجز جديد</button>
      <?php endif; ?>
    </div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>القاعة</th><th>العنوان</th><th>من</th><th>إلى</th><th>بواسطة</th><th></th></tr></thead>
        <tbody>
        <?php if (!$bookings): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد حجوزات قادمة</td></tr>
        <?php else: foreach ($bookings as $b): ?>
        <tr>
          <td class="fw-semibold"><?= e($b['room_name']) ?></td>
          <td><?= e($b['title']) ?></td>
          <td style="font-size:12px"><?= date('Y-m-d H:i', strtotime($b['start_time'])) ?></td>
          <td style="font-size:12px"><?= date('Y-m-d H:i', strtotime($b['end_time'])) ?></td>
          <td style="font-size:12px"><?= e($b['booker_name'] ?: '—') ?></td>
          <td><?php if ($_canApprove || ($_canDel && $b['booked_by']==$uid)): ?><a href="meeting_rooms.php?cancel=<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('إلغاء هذا الحجز؟')"><i class="fas fa-xmark"></i></a><?php endif; ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div></div></div>
  </div>
</div>

<!-- Modal: قاعة -->
<div class="modal fade" id="roomModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="roomTitle">قاعة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="room"><input type="hidden" name="id" id="room_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">اسم القاعة *</label><input type="text" name="name" id="room_name_inp" class="form-control" required></div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">السعة</label><input type="number" name="capacity" id="room_capacity" class="form-control" min="1"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">الموقع</label><input type="text" name="location" id="room_location" class="form-control"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary">حفظ</button></div>
  </form>
</div></div></div>

<!-- Modal: حجز -->
<div class="modal fade" id="bookModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>حجز قاعة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="booking">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">القاعة *</label>
        <select name="room_id" class="form-select" required>
          <?php foreach ($rooms as $r): if (!$r['is_active']) continue; ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">عنوان الاجتماع *</label><input type="text" name="title" class="form-control" required></div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">من *</label><input type="datetime-local" name="start_time" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">إلى *</label><input type="datetime-local" name="end_time" class="form-control" required></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تأكيد الحجز</button></div>
  </form>
</div></div></div>

<script>
function roomNew(){document.getElementById('roomTitle').innerText='قاعة جديدة';document.getElementById('room_id').value='';document.getElementById('room_name_inp').value='';document.getElementById('room_capacity').value='';document.getElementById('room_location').value='';}
function roomEdit(r){document.getElementById('roomTitle').innerText='تعديل قاعة';document.getElementById('room_id').value=r.id;document.getElementById('room_name_inp').value=r.name;document.getElementById('room_capacity').value=r.capacity||'';document.getElementById('room_location').value=r.location||'';new bootstrap.Modal(document.getElementById('roomModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
