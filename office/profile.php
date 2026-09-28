<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/telegram.php';
require_once '../includes/whatsapp.php';
require_once '../includes/module_helper.php';
requireOffice();
$page_title = 'الملف الشخصي';
$oid  = (int)$_SESSION['office_id'];
$uid  = (int)$_SESSION['user_id'];
$tab  = $_GET['tab'] ?? 'profile';
$msg  = '';

/* ── تيليغرام: توليد رابط الربط (AJAX) ── */
if (isset($_GET['tg_gen'])) {
    header('Content-Type: application/json; charset=utf-8');
    foreach ([
        "ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(40) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN notify_telegram TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE users ADD COLUMN tg_link_token VARCHAR(40) DEFAULT NULL",
    ] as $_d) { try { $conn->query($_d); } catch (\Throwable $e) {} }
    $tok = bin2hex(random_bytes(12));
    $te = $conn->real_escape_string($tok);
    $conn->query("UPDATE users SET tg_link_token='$te' WHERE id=$uid");
    $url = tg_link_url($conn, $tok);
    echo json_encode(['ok' => $url !== '', 'url' => $url,
        'msg' => $url === '' ? 'خدمة تيليغرام غير مفعّلة على مستوى المنصة بعد.' : '']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'tg_unlink') {
    $conn->query("UPDATE users SET telegram_chat_id=NULL, tg_link_token=NULL WHERE id=$uid");
    header("Location: profile.php?tab=profile&msg=updated"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'tg_toggle') {
    $v = !empty($_POST['notify_telegram']) ? 1 : 0;
    $conn->query("UPDATE users SET notify_telegram=$v WHERE id=$uid");
    header("Location: profile.php?tab=profile&msg=updated"); exit;
}
/* ── قناة تنبيه البريد ── */
try { $conn->query("ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'email_toggle') {
    $ev = !empty($_POST['notify_email']) ? 1 : 0;
    $conn->query("UPDATE users SET notify_email=$ev WHERE id=$uid");
    header("Location: profile.php?tab=profile&msg=updated"); exit;
}

/* ── تحديث بيانات المستخدم ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {

    if ($_POST['form_type'] === 'user_info') {
        foreach ([
            "ALTER TABLE users ADD COLUMN calendar_pref ENUM('gregorian','hijri','both') NOT NULL DEFAULT 'gregorian'",
        ] as $_ddl) { try { $conn->query($_ddl); } catch (\Throwable $e) {} }
        $full_name = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
        $email     = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $phone     = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $cal       = in_array($_POST['calendar_pref'] ?? '', ['gregorian','hijri','both'], true) ? $_POST['calendar_pref'] : 'gregorian';
        $conn->query("UPDATE users SET full_name='$full_name',email='$email',phone='$phone',calendar_pref='$cal' WHERE id=$uid");
        $_SESSION['full_name']     = $full_name;
        $_SESSION['calendar_pref'] = $cal;
        header("Location: profile.php?tab=profile&msg=updated"); exit;
    }

    if ($_POST['form_type'] === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $con = $_POST['confirm_password'] ?? '';
        $user = $conn->query("SELECT password FROM users WHERE id=$uid")->fetch_assoc();
        if (!password_verify($old, $user['password'])) {
            $msg = 'كلمة المرور القديمة غير صحيحة';
        } elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)) {
            $msg = 'كلمة المرور يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، ورمز خاص';
        } elseif ($new !== $con) {
            $msg = 'كلمتا المرور غير متطابقتين';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $conn->query("UPDATE users SET password='$hash' WHERE id=$uid");
            header("Location: profile.php?tab=security&msg=saved"); exit;
        }
        $tab = 'security';
    }

    if ($_POST['form_type'] === 'office_settings') {
        $address    = $conn->real_escape_string($_POST['address'] ?? '');
        $website    = $conn->real_escape_string($_POST['website'] ?? '');
        $tax_number = $conn->real_escape_string(trim($_POST['tax_number'] ?? ''));
        $cr_number  = $conn->real_escape_string(trim($_POST['cr_number'] ?? ''));
        $bank_name  = $conn->real_escape_string($_POST['bank_name'] ?? '');
        $bank_iban  = $conn->real_escape_string($_POST['bank_iban'] ?? '');
        $inv_prefix = $conn->real_escape_string(strtoupper(trim($_POST['invoice_prefix'] ?? 'INV')));
        $inv_footer = $conn->real_escape_string($_POST['invoice_footer'] ?? '');
        // إضافة أعمدة إن لم تكن موجودة
        foreach ([
            "ALTER TABLE office_settings ADD COLUMN cr_number VARCHAR(20) DEFAULT NULL",
            "ALTER TABLE office_settings ADD COLUMN office_logo VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE office_settings ADD COLUMN letterhead_path VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE office_settings ADD COLUMN letterhead_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE office_settings ADD COLUMN letterhead_top INT NOT NULL DEFAULT 42",
            "ALTER TABLE office_settings ADD COLUMN letterhead_bottom INT NOT NULL DEFAULT 26",
            "ALTER TABLE office_settings ADD COLUMN letterhead_side INT NOT NULL DEFAULT 18",
        ] as $_oc) { try { $conn->query($_oc); } catch (\Throwable $e) {} }

        // رفع شعار المكتب
        $logo_col = '';
        if (!empty($_FILES['office_logo']['tmp_name'])) {
            $lext = strtolower(pathinfo($_FILES['office_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($lext, ['jpg','jpeg','png','webp','svg']) && $_FILES['office_logo']['size'] <= 2 * 1024 * 1024) {
                $ldir = '../uploads/logos/';
                if (!is_dir($ldir)) mkdir($ldir, 0755, true);
                $lfn  = 'logo_' . $oid . '.' . $lext;
                if (move_uploaded_file($_FILES['office_logo']['tmp_name'], $ldir . $lfn)) {
                    $logo_col = ",office_logo='" . $conn->real_escape_string('uploads/logos/' . $lfn) . "'";
                }
            }
        }

        // رفع الليتر هيد (صورة بمقاس A4)
        $lh_col = '';
        if (!empty($_POST['rm_letterhead'])) {
            $lh_col .= ",letterhead_path=NULL";
        }
        if (!empty($_FILES['letterhead_file']['tmp_name'])) {
            $hext = strtolower(pathinfo($_FILES['letterhead_file']['name'], PATHINFO_EXTENSION));
            if (in_array($hext, ['jpg','jpeg','png','webp']) && $_FILES['letterhead_file']['size'] <= 6 * 1024 * 1024) {
                $hdir = '../uploads/letterheads/';
                if (!is_dir($hdir)) mkdir($hdir, 0755, true);
                $hfn = 'lh_' . $oid . '_' . time() . '.' . $hext;
                if (move_uploaded_file($_FILES['letterhead_file']['tmp_name'], $hdir . $hfn)) {
                    $lh_col .= ",letterhead_path='" . $conn->real_escape_string('uploads/letterheads/' . $hfn) . "'";
                }
            }
        }
        $lh_on   = !empty($_POST['letterhead_enabled']) ? 1 : 0;
        $lh_top  = max(0, min(150, (int)($_POST['letterhead_top'] ?? 42)));
        $lh_bot  = max(0, min(150, (int)($_POST['letterhead_bottom'] ?? 26)));
        $lh_side = max(0, min(70,  (int)($_POST['letterhead_side'] ?? 18)));
        $lh_col .= ",letterhead_enabled=$lh_on,letterhead_top=$lh_top,letterhead_bottom=$lh_bot,letterhead_side=$lh_side";

        $conn->query("INSERT INTO office_settings (office_id,address,website,tax_number,cr_number,bank_name,bank_iban,invoice_prefix,invoice_footer)
            VALUES ($oid,'$address','$website','$tax_number','$cr_number','$bank_name','$bank_iban','$inv_prefix','$inv_footer')
            ON DUPLICATE KEY UPDATE
            address='$address',website='$website',tax_number='$tax_number',cr_number='$cr_number',
            bank_name='$bank_name',bank_iban='$bank_iban',
            invoice_prefix='$inv_prefix',invoice_footer='$inv_footer'$logo_col$lh_col");
        header("Location: profile.php?tab=office&msg=saved"); exit;
    }

    /* ── إعدادات تخزين وتشفير الملفات ── */
    if ($_POST['form_type'] === 'storage_settings' && currentRole() === 'office_owner') {
        foreach ([
            "ALTER TABLE office_settings ADD COLUMN storage_driver ENUM('server','gdrive') NOT NULL DEFAULT 'server'",
            "ALTER TABLE office_settings ADD COLUMN encrypt_files TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE office_settings ADD COLUMN storage_salt VARCHAR(64) DEFAULT NULL",
            "ALTER TABLE office_settings ADD COLUMN gdrive_refresh_token TEXT",
        ] as $_ddl) { try { $conn->query($_ddl); } catch (\Throwable $e) {} }

        require_once '../includes/gdrive_helper.php';
        require_once '../includes/crypto_helper.php';

        $encrypt = !empty($_POST['encrypt_files']) ? 1 : 0;

        // مكان التخزين: gdrive فقط إذا كان المكتب مربوطاً فعلاً بحساب
        $wants_gd = ($_POST['storage_driver'] ?? 'server') === 'gdrive';
        $connected = gd_office_connected($conn, $oid);
        $sdriver = ($wants_gd && $connected) ? 'gdrive' : 'server';

        // ملح المكتب — يُولَّد مرة واحدة ويبقى ثابتاً
        $cur = $conn->query("SELECT storage_salt FROM office_settings WHERE office_id=$oid LIMIT 1");
        $salt = ($cur && ($r = $cur->fetch_assoc()) && !empty($r['storage_salt'])) ? $r['storage_salt'] : mehkam_new_salt();
        $salt_e = $conn->real_escape_string($salt);

        $conn->query("INSERT INTO office_settings (office_id,storage_driver,encrypt_files,storage_salt)
            VALUES ($oid,'$sdriver',$encrypt,'$salt_e')
            ON DUPLICATE KEY UPDATE
            storage_driver='$sdriver',
            encrypt_files=$encrypt,
            storage_salt=COALESCE(storage_salt,'$salt_e')");

        $extra = ($wants_gd && !$connected) ? '&msg=gd_needconnect' : '&msg=saved';
        header("Location: profile.php?tab=office" . $extra); exit;
    }
}

/* ── اختبار ربط Google Drive للمكتب (AJAX) ── */
if (isset($_GET['gdrive_test'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (is_file('../includes/gdrive_helper.php')) require_once '../includes/gdrive_helper.php';
    echo json_encode(function_exists('gd_office_test')
        ? gd_office_test($conn, $oid)
        : ['ok' => false, 'msg' => 'حدّث ملف gdrive_helper.php']);
    exit;
}

/* ── إنشاء جدول طلبات الباقة إن لم يوجد ── */
$conn->query("CREATE TABLE IF NOT EXISTS package_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL, current_package_id INT DEFAULT NULL, requested_package_id INT NOT NULL,
    reason TEXT, commitment TINYINT(1) DEFAULT 0,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note TEXT, payment_proof VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, processed_at DATETIME DEFAULT NULL,
    INDEX (office_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE package_requests ADD COLUMN payment_proof VARCHAR(500) DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN is_custom TINYINT(1) DEFAULT 0"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN custom_features JSON DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN custom_limits JSON DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN custom_price_monthly DECIMAL(10,2) DEFAULT 0"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN admin_final_price DECIMAL(10,2) DEFAULT NULL"); } catch (\Exception $e) {}

/* ── معالجة طلب تغيير الباقة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'package_request') {
    $req_pkg  = (int)($_POST['requested_package_id'] ?? 0);
    $reason   = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
    $commit   = isset($_POST['commitment']) ? 1 : 0;
    $cur_pkg  = (int)($conn->query("SELECT package_id FROM offices WHERE id=$oid LIMIT 1")->fetch_assoc()['package_id'] ?? 0);

    if ($req_pkg && $req_pkg !== $cur_pkg && $commit) {
        // تحقق: هل يوجد طلب معلق بالفعل؟
        $exists = $conn->query("SELECT id FROM package_requests WHERE office_id=$oid AND status='pending'")->num_rows;
        if (!$exists) {
            // رفع مستند الدفع
            $proof_path_e = '';
            if (!empty($_FILES['payment_proof']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','pdf','webp'];
                if (in_array($ext, $allowed) && $_FILES['payment_proof']['size'] <= 5 * 1024 * 1024) {
                    $upload_dir = '../uploads/payment_proofs/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filename = 'proof_' . $oid . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $filename)) {
                        $proof_path_e = $conn->real_escape_string('uploads/payment_proofs/' . $filename);
                    }
                }
            }
            $conn->query("INSERT INTO package_requests (office_id,current_package_id,requested_package_id,reason,commitment,payment_proof)
                VALUES ($oid,$cur_pkg,$req_pkg,'$reason',$commit,'$proof_path_e')");
        }
        header("Location: profile.php?tab=subscription&msg=req_sent"); exit;
    }
    $msg = 'يرجى اختيار باقة مختلفة والموافقة على التعهد';
    $tab = 'subscription';
}

/* ── معالجة طلب الباقة المخصصة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'custom_request') {
    $reason  = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
    $commit  = isset($_POST['commitment']) ? 1 : 0;
    $cur_pkg = (int)($conn->query("SELECT package_id FROM offices WHERE id=$oid LIMIT 1")->fetch_assoc()['package_id'] ?? 0);

    $feat_keys_all = ['has_finance','has_invoices','has_contracts','has_poa','has_correspondence','has_library','has_archive','has_ai','has_reports','has_api','has_precedents','has_digital_services'];
    $sel_features = [];
    foreach (custom_pkg_modules($conn) as $_m) $feat_keys_all[] = 'mod_' . $_m['module_key'];
    foreach ($feat_keys_all as $k) $sel_features[$k] = isset($_POST['feat'][$k]);

    $c_users   = max(1, (int)($_POST['c_users'] ?? 3));
    $c_cases   = max(1, (int)($_POST['c_cases'] ?? 50));
    $c_storage = max(0, (int)($_POST['c_storage_mb'] ?? 0));
    $c_price   = max(0, (float)($_POST['custom_price_monthly'] ?? 0));

    if ($commit && $reason) {
        $exists = $conn->query("SELECT id FROM package_requests WHERE office_id=$oid AND status='pending'")->num_rows;
        if (!$exists) {
            $proof_path_e = '';
            if (!empty($_FILES['payment_proof']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','pdf','webp']) && $_FILES['payment_proof']['size'] <= 5 * 1024 * 1024) {
                    $upload_dir = '../uploads/payment_proofs/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $fn = 'proof_' . $oid . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $fn))
                        $proof_path_e = $conn->real_escape_string('uploads/payment_proofs/' . $fn);
                }
            }
            $cf = $conn->real_escape_string(json_encode($sel_features));
            $cl = $conn->real_escape_string(json_encode(['users'=>$c_users,'cases'=>$c_cases,'storage_mb'=>$c_storage]));
            $conn->query("INSERT INTO package_requests (office_id,current_package_id,requested_package_id,reason,commitment,payment_proof,is_custom,custom_features,custom_limits,custom_price_monthly)
                VALUES ($oid,$cur_pkg,0,'$reason',$commit,'$proof_path_e',1,'$cf','$cl',$c_price)");
        }
        header("Location: profile.php?tab=subscription&msg=req_sent"); exit;
    }
    $msg = 'يرجى إكمال تعبئة النموذج والموافقة على التعهد';
    $tab = 'subscription';
}

/* ── جلب البيانات ── */
$user = $conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
$office = $conn->query("SELECT o.*,p.name pkg_name,p.price_monthly,p.price_yearly
    FROM offices o LEFT JOIN packages p ON o.package_id=p.id
    WHERE o.id=$oid")->fetch_assoc();

$pkg_features = [];
$q = $conn->query("SELECT feature_key,feature_value FROM package_features WHERE package_id=".((int)$office['package_id']));
if ($q) while ($r = $q->fetch_assoc()) $pkg_features[$r['feature_key']] = $r['feature_value'];

$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?? [];

// إعدادات التخزين + حالة ربط Google Drive
$gd_oauth_ready = false;
$gd_connected   = false;
$gd_email       = '';
if (is_file('../includes/gdrive_helper.php')) {
    require_once '../includes/gdrive_helper.php';
    if (function_exists('gd_oauth_ready'))     $gd_oauth_ready = gd_oauth_ready();
    if (function_exists('gd_office_connected')) $gd_connected   = gd_office_connected($conn, $oid);
    if (function_exists('gd_office_email'))     $gd_email       = gd_office_email($conn, $oid);
}
$st_encrypt = !empty($settings['encrypt_files']);
$gd_connect_error = $_SESSION['gdrive_connect_error'] ?? '';
unset($_SESSION['gdrive_connect_error']);

$storage_mb  = (int)($pkg_features['storage_mb'] ?? 0);
$used_mb     = $storage_mb > 0 ? getStorageUsedMB($conn, $oid) : 0;
$storage_pct = $storage_mb > 0 ? min(100, round($used_mb / $storage_mb * 100)) : 0;

$days_left = 0;
if ($office['subscription_end']) {
    $days_left = max(0, (int)((strtotime($office['subscription_end']) - time()) / 86400));
}

// جميع الباقات للترقية
$all_packages = $conn->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price_yearly ASC");

// طلب الباقة المعلق
$pending_request = $conn->query("SELECT pr.*,p.name req_pkg_name FROM package_requests pr LEFT JOIN packages p ON pr.requested_package_id=p.id WHERE pr.office_id=$oid ORDER BY pr.created_at DESC LIMIT 1")->fetch_assoc();

// أسعار الميزات للباقة المخصصة
$fp = [];
$fp_res = $conn->query("SELECT * FROM feature_prices ORDER BY sort_order");
if ($fp_res) while ($r = $fp_res->fetch_assoc()) $fp[$r['feature_key']] = $r;
custom_pkg_inject_modules($conn, $fp);
$fp_base_users = max(1,(int)sc($conn,'custom_base_users','3'));
$fp_base_cases = max(1,(int)sc($conn,'custom_base_cases','50'));
$fp_base_price = max(0,(float)sc($conn,'custom_base_price','0'));
$custom_pkg_enabled = sc($conn,'custom_package_enabled','1') === '1';
$_session_pkg_id = (int)($office['package_id'] ?? 0);

include '../includes/office_header.php';
?>

<div class="mk-page-hdr mb-3">
  <div>
    <div class="mk-page-title"><i class="fas fa-user-circle"></i> الملف الشخصي</div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link <?= $tab==='profile'?'active':'' ?>" href="?tab=profile">
    <i class="fas fa-user me-1"></i>بياناتي
  </a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='office'?'active':'' ?>" href="?tab=office">
    <i class="fas fa-building me-1"></i>المكتب
  </a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='subscription'?'active':'' ?>" href="?tab=subscription">
    <i class="fas fa-crown me-1"></i>الاشتراك
  </a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='security'?'active':'' ?>" href="?tab=security">
    <i class="fas fa-lock me-1"></i>الأمان
  </a></li>
  <?php if ($tab === 'upgrade'): ?>
  <li class="nav-item"><a class="nav-link active" href="?tab=upgrade">
    <i class="fas fa-arrow-up me-1"></i>ترقية الباقة
  </a></li>
  <?php endif; ?>
</ul>

<?php if ($msg): ?>
<div class="alert alert-danger mb-3"><i class="fas fa-times-circle me-2"></i><?= e($msg) ?></div>
<?php endif; ?>

<!-- ═══ TAB: بياناتي ═══ -->
<?php if ($tab === 'profile'): ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card text-center" style="padding:32px 24px">
      <div class="mx-auto mb-3" style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--mk-navy2),var(--mk-navy4));border:3px solid var(--mk-gold-bd);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:var(--mk-gold4)">
        <?= mb_substr($user['full_name'] ?? 'U', 0, 1, 'UTF-8') ?>
      </div>
      <h5 class="fw-bold mb-1"><?= e($user['full_name']) ?></h5>
      <div class="text-muted" style="font-size:13px"><?= e($user['email']) ?></div>
      <div class="mt-2">
        <?= statusBadge($user['role']) ?>
      </div>
      <hr>
      <div style="font-size:12.5px;color:var(--mk-t4)">
        <div><i class="fas fa-building me-1"></i><?= e($office['name']) ?></div>
        <div class="mt-1"><i class="fas fa-crown me-1"></i>باقة <?= e($office['pkg_name'] ?? '—') ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">تعديل بياناتي</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="form_type" value="user_info">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">الاسم الكامل</label>
              <input type="text" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">اسم المستخدم</label>
              <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
              <div class="form-text">لا يمكن تغيير اسم المستخدم</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">البريد الإلكتروني</label>
              <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">رقم الجوال</label>
              <input type="text" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fas fa-calendar-day me-1 text-primary"></i>التقويم المفضّل</label>
              <?php $cp = $user['calendar_pref'] ?? 'gregorian'; ?>
              <select name="calendar_pref" class="form-select">
                <option value="gregorian" <?= $cp==='gregorian'?'selected':'' ?>>ميلادي فقط</option>
                <option value="hijri"     <?= $cp==='hijri'?'selected':'' ?>>هجري فقط</option>
                <option value="both"      <?= $cp==='both'?'selected':'' ?>>ميلادي والهجري معاً</option>
              </select>
              <div class="form-text">
                يؤثّر على عرض كل التواريخ في حسابك — العرض الحالي:
                <strong><?= dDate(date('Y-m-d')) ?></strong>
              </div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i>حفظ التغييرات
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ═══ تنبيهات البريد الإلكتروني (مجاني — يعمل تلقائياً) ═══ -->
<?php $em_on = !isset($user['notify_email']) || $user['notify_email']; $smtp_ok = sc($conn,'smtp_enabled','0')==='1'; ?>
<div class="card mt-3">
  <div class="card-header"><i class="fas fa-envelope me-2 text-primary"></i>تنبيهات البريد الإلكتروني <span class="badge bg-success ms-1">مجاني — الأسهل</span></div>
  <div class="card-body">
    <?php if (!$smtp_ok): ?>
      <div class="alert alert-warning py-2 mb-0" style="font-size:12.5px">إرسال البريد غير مُفعّل على مستوى المنصة بعد — يضبطه المدير من إعدادات المنصة (SMTP).</div>
    <?php elseif (empty($user['email'])): ?>
      <div class="alert alert-warning py-2 mb-0" style="font-size:12.5px">أضِف بريدك الإلكتروني في «بياناتي» أعلاه لتصلك التنبيهات.</div>
    <?php else: ?>
      <p class="text-muted mb-2" style="font-size:13px">تصلك تذكيرات الجلسات والمواعيد والمهام المتأخرة والملخّص اليومي على <b><?= e($user['email']) ?></b> — بدون أي إعداد.</p>
      <form method="POST" class="d-inline">
        <input type="hidden" name="form_type" value="email_toggle">
        <input type="hidden" name="notify_email" value="<?= $em_on ? '0' : '1' ?>">
        <button class="btn btn-sm <?= $em_on ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
          <?= $em_on ? '🔕 إيقاف تنبيهات البريد' : '🔔 تفعيل تنبيهات البريد' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ ربط تيليغرام ═══ -->
<?php $tg_linked = !empty($user['telegram_chat_id']); $tg_on = !isset($user['notify_telegram']) || $user['notify_telegram']; ?>
<div class="card mt-3">
  <div class="card-header"><i class="fab fa-telegram me-2" style="color:#229ED9"></i>تنبيهات تيليغرام</div>
  <div class="card-body">
    <?php if (!tg_configured($conn)): ?>
      <div class="alert alert-warning py-2 mb-0" style="font-size:12.5px">
        خدمة تيليغرام غير مفعّلة على مستوى المنصة بعد. يضبطها المدير من لوحة الإدارة ← إعدادات المنصة.
      </div>
    <?php elseif ($tg_linked): ?>
      <div class="alert alert-success py-2" style="font-size:13px">
        <i class="fas fa-check-circle me-1"></i>حسابك مربوط. تصلك تنبيهات الجلسات والمواعيد على تيليغرام.
      </div>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <form method="POST" class="d-inline">
          <input type="hidden" name="form_type" value="tg_toggle">
          <input type="hidden" name="notify_telegram" value="<?= $tg_on ? '0' : '1' ?>">
          <button class="btn btn-sm <?= $tg_on ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
            <?= $tg_on ? '🔕 إيقاف التنبيهات' : '🔔 تفعيل التنبيهات' ?>
          </button>
        </form>
        <form method="POST" class="d-inline" onsubmit="return confirm('فصل تيليغرام؟')">
          <input type="hidden" name="form_type" value="tg_unlink">
          <button class="btn btn-sm btn-outline-danger"><i class="fas fa-link-slash me-1"></i>فصل الربط</button>
        </form>
      </div>
    <?php else: ?>
      <p class="text-muted" style="font-size:13px">اربط حساب تيليغرام لتصلك تذكيرات الجلسات وملخّص يومك.</p>
      <button type="button" class="btn btn-sm" style="background:#229ED9;color:#fff" onclick="tgLink(this)">
        <i class="fab fa-telegram me-1"></i>ربط حساب تيليغرام
      </button>
      <div id="tgLinkBox" class="mt-3" style="display:none">
        <p style="font-size:13px;margin-bottom:6px">اضغط الرابط، ثم اضغط <b>Start / بدء</b> داخل تيليغرام:</p>
        <a id="tgLinkA" href="#" target="_blank" class="btn btn-outline-primary btn-sm font-monospace"></a>
        <div class="form-text mt-1">لو ما فتح تلقائياً، انسخ الرابط وافتحه بتيليغرام. صالح لبضع دقائق.</div>
      </div>
      <div id="tgLinkErr" class="text-danger mt-2" style="font-size:12.5px"></div>
    <?php endif; ?>
  </div>
</div>

<script>
function tgLink(btn){
  btn.disabled = true; btn.textContent = 'جارٍ…';
  fetch('profile.php?tg_gen=1').then(r=>r.json()).then(d=>{
    btn.disabled = false; btn.innerHTML = '<i class="fab fa-telegram me-1"></i>ربط حساب تيليغرام';
    if (d.ok) {
      var a = document.getElementById('tgLinkA');
      a.href = d.url; a.textContent = d.url;
      document.getElementById('tgLinkBox').style.display = 'block';
      window.open(d.url, '_blank');
    } else {
      document.getElementById('tgLinkErr').textContent = d.msg || 'تعذّر توليد الرابط';
    }
  }).catch(()=>{ btn.disabled=false; document.getElementById('tgLinkErr').textContent='خطأ في الاتصال'; });
}
</script>

<!-- ═══ TAB: المكتب ═══ -->
<?php elseif ($tab === 'office'): ?>
<?php if (currentRole() !== 'office_owner'): ?>
<div class="alert alert-warning"><i class="fas fa-lock me-2"></i>صلاحية تعديل إعدادات المكتب متاحة لمالك المكتب فقط.</div>
<?php else: ?>
<div class="card">
  <div class="card-header">إعدادات المكتب والفواتير</div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="form_type" value="office_settings">
      <div class="row g-3">

        <!-- شعار المكتب -->
        <div class="col-12">
          <label class="form-label fw-semibold"><i class="fas fa-image me-1 text-primary"></i>شعار المكتب</label>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div id="logo-preview-wrap" style="width:90px;height:90px;border-radius:12px;border:2px dashed #d1d5db;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f8fafc">
              <?php if (!empty($settings['office_logo'])): ?>
              <img src="../<?= e($settings['office_logo']) ?>?v=<?= time() ?>" id="logo-preview-img" style="max-width:100%;max-height:100%;object-fit:contain">
              <?php else: ?>
              <i class="fas fa-building text-muted" style="font-size:30px" id="logo-placeholder"></i>
              <?php endif; ?>
            </div>
            <div>
              <input type="file" name="office_logo" id="office_logo_inp" class="form-control form-control-sm"
                     accept=".jpg,.jpeg,.png,.webp,.svg" style="max-width:260px"
                     onchange="previewLogo(this)">
              <div class="form-text">JPG, PNG, SVG — 2 MB كحد أقصى — يظهر في الفواتير والواجهة</div>
            </div>
          </div>
        </div>

        <!-- ═══ الليتر هيد (ورق رسمي للطباعة) ═══ -->
        <div class="col-12">
          <div class="p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0">
            <label class="form-label fw-semibold"><i class="fas fa-file-lines me-1 text-primary"></i>الورق الرسمي (Letterhead) للطباعة</label>
            <p class="text-muted mb-2" style="font-size:12px">
              ارفع تصميم ورقتك الرسمية <b>كصورة بمقاس A4 عمودي</b> (يفضّل PNG، 2480×3508 بكسل تقريباً).
              يُطبع خلف <b>الفواتير والعقود والتقارير</b>، وعند بلوغ المحتوى الهامش السفلي <b>ينتقل الباقي تلقائياً إلى صفحة جديدة</b> بنفس الليتر هيد.
            </p>
            <div class="row g-3 align-items-start">
              <div class="col-md-4">
                <div style="border:2px dashed #cbd5e1;border-radius:10px;overflow:hidden;background:#fff;aspect-ratio:1/1.414;display:flex;align-items:center;justify-content:center">
                  <?php if (!empty($settings['letterhead_path']) && file_exists('../' . $settings['letterhead_path'])): ?>
                  <img src="../<?= e($settings['letterhead_path']) ?>?v=<?= @filemtime('../' . $settings['letterhead_path']) ?>" style="max-width:100%;max-height:100%;object-fit:contain">
                  <?php else: ?>
                  <span class="text-muted" style="font-size:12px">لا يوجد ليتر هيد</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="col-md-8">
                <input type="file" name="letterhead_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp">
                <div class="form-text">JPG / PNG / WEBP — 6 MB كحد أقصى</div>

                <div class="form-check form-switch mt-2">
                  <input class="form-check-input" type="checkbox" name="letterhead_enabled" id="lh_en" value="1" <?= !empty($settings['letterhead_enabled'])?'checked':'' ?>>
                  <label class="form-check-label fw-semibold" for="lh_en">تفعيل الطباعة على الليتر هيد</label>
                </div>

                <div class="fw-semibold mt-3 mb-1" style="font-size:12.5px"><i class="fas fa-ruler-combined me-1 text-muted"></i>هوامش الكتابة داخل الورقة (مم)</div>
                <div class="row g-2">
                  <div class="col-4">
                    <label class="form-label mb-0" style="font-size:11.5px">من الأعلى</label>
                    <input type="number" name="letterhead_top" class="form-control form-control-sm" min="0" max="150"
                           value="<?= (int)($settings['letterhead_top'] ?? 42) ?>">
                    <div class="form-text" style="font-size:10px">تحت الترويسة</div>
                  </div>
                  <div class="col-4">
                    <label class="form-label mb-0" style="font-size:11.5px">من الأسفل</label>
                    <input type="number" name="letterhead_bottom" class="form-control form-control-sm" min="0" max="150"
                           value="<?= (int)($settings['letterhead_bottom'] ?? 26) ?>">
                    <div class="form-text" style="font-size:10px">فوق التذييل</div>
                  </div>
                  <div class="col-4">
                    <label class="form-label mb-0" style="font-size:11.5px">الجانبين</label>
                    <input type="number" name="letterhead_side" class="form-control form-control-sm" min="0" max="70"
                           value="<?= (int)($settings['letterhead_side'] ?? 18) ?>">
                    <div class="form-text" style="font-size:10px">يمين + يسار</div>
                  </div>
                </div>
                <div class="form-text" style="font-size:10.5px">الورقة A4 كاملة (210×297مم). هذه القيم تحدّد أين يبدأ النص وأين ينتهي قبل الانتقال لصفحة جديدة.</div>

                <?php if (!empty($settings['letterhead_path'])): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="rm_letterhead" id="lh_rm" value="1">
                  <label class="form-check-label text-danger" for="lh_rm" style="font-size:12.5px">حذف الليتر هيد الحالي</label>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">اسم المكتب</label>
          <input type="text" class="form-control" value="<?= e($office['name']) ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label">رقم الرخصة</label>
          <input type="text" class="form-control" value="<?= e($office['license_number'] ?? '') ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label">العنوان</label>
          <textarea name="address" class="form-control" rows="2"><?= e($settings['address'] ?? '') ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">الموقع الإلكتروني</label>
          <input type="url" name="website" class="form-control" value="<?= e($settings['website'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">
            الرقم الضريبي (VAT Number)
            <span style="font-size:10px;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;margin-right:4px">ZATCA مطلوب</span>
          </label>
          <input type="text" name="tax_number" class="form-control font-monospace"
                 placeholder="3XXXXXXXXXXXXXXXXXXX3 (15 رقماً)"
                 value="<?= e($settings['tax_number'] ?? '') ?>">
          <div class="form-text">يبدأ وينتهي بالرقم 3 — يظهر في QR Code الفاتورة</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">
            رقم السجل التجاري (CR Number)
            <span style="font-size:10px;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;margin-right:4px">ZATCA</span>
          </label>
          <input type="text" name="cr_number" class="form-control font-monospace"
                 placeholder="1XXXXXXXXX (10 أرقام)"
                 value="<?= e($settings['cr_number'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">بادئة رقم الفاتورة</label>
          <input type="text" name="invoice_prefix" class="form-control"
                 value="<?= e($settings['invoice_prefix'] ?? 'INV') ?>" maxlength="10">
        </div>
        <div class="col-md-6">
          <label class="form-label">اسم البنك</label>
          <input type="text" name="bank_name" class="form-control" value="<?= e($settings['bank_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">رقم الآيبان IBAN</label>
          <input type="text" name="bank_iban" class="form-control" value="<?= e($settings['bank_iban'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">نص تذييل الفاتورة</label>
          <textarea name="invoice_footer" class="form-control" rows="2"
                    placeholder="مثال: شكراً لثقتكم، يرجى الدفع خلال 30 يوماً"><?= e($settings['invoice_footer'] ?? '') ?></textarea>
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>حفظ الإعدادات
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══ تخزين الملفات وحمايتها ═══ -->
<div class="card mt-3">
  <div class="card-header"><i class="fas fa-shield-halved me-2 text-success"></i>تخزين الملفات وحمايتها</div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">
      تحكّم في مكان حفظ ملفات مكتبك (القضايا، العقود، المرفقات…) ومستوى سريّتها.
    </p>

    <?php if ($gd_connect_error): ?>
    <div class="alert alert-danger py-2" style="font-size:13px"><i class="fas fa-times-circle me-1"></i><?= e($gd_connect_error) ?></div>
    <?php endif; ?>

    <!-- ربط Google Drive -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="fab fa-google-drive text-success" style="font-size:20px"></i>
        <strong>Google Drive الخاص بمكتبك</strong>
      </div>
      <?php if ($gd_connected): ?>
        <div class="alert alert-success py-2 mb-2" style="font-size:13px">
          <i class="fas fa-check-circle me-1"></i>
          مربوط<?= $gd_email ? ' — ' . e($gd_email) : '' ?>.
          ملفاتك الجديدة تُحفظ في مجلد <strong>«مِحكام»</strong> داخل درايفك.
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-outline-success btn-sm" onclick="gdTest()">
            <i class="fas fa-plug me-1"></i>تجربة الاتصال
          </button>
          <form method="POST" action="gdrive_disconnect.php" class="d-inline"
                onsubmit="return confirm('فصل Google Drive؟ الملفات المرفوعة تبقى في درايفك، لكن الملفات الجديدة ترجع لخادم المنصة.')">
            <button class="btn btn-outline-danger btn-sm"><i class="fas fa-link-slash me-1"></i>فصل الربط</button>
          </form>
          <span id="gd_test_result" style="font-size:12.5px"></span>
        </div>
        <script>
        function gdTest(){
          var b=document.getElementById('gd_test_result');
          b.innerHTML='<span class="text-muted">جاري التجربة…</span>';
          fetch('profile.php?gdrive_test=1').then(r=>r.json()).then(d=>{
            b.innerHTML=(d.ok?'<span class="text-success"><i class="fas fa-check-circle me-1"></i>':'<span class="text-danger"><i class="fas fa-times-circle me-1"></i>')+(d.msg||'')+'</span>';
          }).catch(()=>b.innerHTML='<span class="text-danger">تعذّر الاتصال بالخادم</span>');
        }
        </script>
      <?php elseif (!$gd_oauth_ready): ?>
        <div class="alert alert-warning py-2 mb-0" style="font-size:12.5px">
          خاصية Google Drive غير مُفعّلة على مستوى المنصة بعد. يضبطها المدير من
          <strong>لوحة الإدارة ← إعدادات المنصة</strong>.
        </div>
      <?php else: ?>
        <p class="text-muted mb-2" style="font-size:12.5px">
          اضغط الزر، سجّل دخول حساب Google الخاص بمكتبك، ووافق. لن نصل إلا للملفات التي ننشئها نحن.
        </p>
        <a href="gdrive_connect.php" class="btn btn-success btn-sm"><i class="fab fa-google me-1"></i>ربط حساب Google Drive</a>
      <?php endif; ?>
    </div>

    <form method="POST">
      <input type="hidden" name="form_type" value="storage_settings">

      <div class="mb-3">
        <label class="form-label fw-semibold">مكان حفظ الملفات الجديدة</label>
        <input type="hidden" name="storage_driver" value="gdrive">
        <div class="alert alert-light border py-2 mb-0" style="font-size:12.5px">
          <i class="fab fa-google-drive text-success me-1"></i>
          <?= $gd_connected
              ? 'Google Drive الخاص بمكتبك — جميع الملفات الجديدة تُحفظ فيه تلقائياً.'
              : 'اربط حساب Google Drive أعلاه ليبدأ حفظ ملفات مكتبك الجديدة فيه تلقائياً. قبل الربط تُحفظ مؤقتاً على خادم المنصة.' ?>
        </div>
      </div>

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="encrypt_files" id="enc_files" value="1"
               <?= $st_encrypt ? 'checked' : '' ?>>
        <label class="form-check-label" for="enc_files">
          <i class="fas fa-lock text-warning me-1"></i>
          تشفير الملفات المرفوعة (AES-256) — لا تُقرأ إلا عبر المنصة بمفتاح مكتبك
        </label>
      </div>

      <div class="alert alert-light border" style="font-size:12px">
        <i class="fas fa-circle-info me-1 text-primary"></i>
        الإعداد يسري على <strong>الملفات الجديدة فقط</strong>. الملفات المرفوعة سابقاً تبقى كما هي.
        جرّب رفع ملف تجريبي وتأكد من فتحه قبل الاعتماد الكامل.
      </div>

      <div class="text-end">
        <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ إعدادات التخزين</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ═══ TAB: الاشتراك ═══ -->
<?php elseif ($tab === 'subscription' || $tab === 'upgrade'): ?>
<div class="row g-3">

  <!-- بطاقة الاشتراك الحالي -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-crown text-warning me-2"></i>اشتراكك الحالي</div>
      <div class="card-body">
        <div class="text-center mb-3">
          <div style="font-size:32px;font-weight:900;color:var(--mk-gold2)"><?= e($office['pkg_name'] ?? '—') ?></div>
          <div class="text-muted" style="font-size:13px">الباقة المفعّلة</div>
        </div>
        <div class="mk-sub-card mb-2">
          <div class="mk-sub-dot <?= $office['status'] ?>"></div>
          <div>
            <div class="fw-semibold" style="font-size:13px"><?= statusBadge($office['status']) ?></div>
            <?php if ($office['subscription_end']): ?>
            <div style="font-size:12px;color:var(--mk-t4)">
              ينتهي: <?= dDate($office['subscription_end']) ?>
              <?php if ($days_left <= 30 && $days_left >= 0): ?>
              <span class="badge bg-warning-subtle text-warning ms-1"><?= $days_left ?> يوم متبقي</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <hr>
        <div style="font-size:13px">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">سعر الباقة:</span>
            <span class="fw-bold"><?= number_format($office['price_yearly'] ?? 0) ?> ر.س/سنة</span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">المدينة:</span>
            <span><?= e($office['city']) ?></span>
          </div>
        </div>
        <?php if ($storage_mb > 0): ?>
        <hr>
        <div style="font-size:13px">
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">التخزين المستخدم:</span>
            <span><?= $used_mb ?> / <?= $storage_mb ?> MB</span>
          </div>
          <div class="progress" style="height:8px">
            <div class="progress-bar bg-<?= $storage_pct>80?'danger':($storage_pct>60?'warning':'primary') ?>"
                 style="width:<?= $storage_pct ?>%"></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- مزايا الباقة -->
    <div class="card mt-3">
      <div class="card-header">مزايا باقتك</div>
      <div class="card-body p-0">
        <?php
        $feat_labels = [
            'has_finance'       => 'الشؤون المالية',
            'has_contracts'     => 'العقود والوكالات',
            'has_library'       => 'المكتبة القانونية',
            'has_archive'       => 'الأرشيف الإلكتروني',
            'has_correspondence'=> 'الصادر والوارد',
            'has_ai'            => 'المساعد الذكي AI',
            'has_invoices'      => 'الفواتير',
            'has_reports'       => 'التقارير المتقدمة',
        ];
        ?>
        <ul class="list-group list-group-flush">
        <?php foreach ($feat_labels as $key => $label):
            $enabled = ($pkg_features[$key] ?? '0') !== '0';
        ?>
          <li class="list-group-item d-flex align-items-center gap-2 py-2">
            <i class="fas fa-<?= $enabled?'check-circle text-success':'times-circle text-muted' ?>"></i>
            <span style="font-size:13px;<?= $enabled?'':'color:var(--mk-t4)' ?>"><?= $label ?></span>
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <!-- تغيير الباقة -->
  <div class="col-lg-8">

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'req_sent'): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
      <i class="fas fa-check-circle fa-lg"></i>
      <div>تم إرسال طلبك بنجاح! سيراجعه الإدارة ويردون عليك قريباً.</div>
    </div>
    <?php endif; ?>

    <?php if ($pending_request): ?>
    <!-- حالة الطلب الأخير -->
    <?php
    $req_status = $pending_request['status'];
    $req_colors = ['pending'=>['warning','clock','معلق — في انتظار مراجعة الإدارة'],'approved'=>['success','check-circle','تمت الموافقة على طلبك'],'rejected'=>['danger','times-circle','تم رفض الطلب']];
    [$rc,$ri,$rl] = $req_colors[$req_status] ?? ['secondary','circle','—'];
    ?>
    <div class="alert alert-<?=$rc?>-subtle border-<?=$rc?> d-flex align-items-start gap-3 mb-3" style="border-width:1px;border-style:solid">
      <i class="fas fa-<?=$ri?> fa-lg text-<?=$rc?> mt-1"></i>
      <div style="flex:1">
        <div class="fw-bold text-<?=$rc?>"><?=$rl?></div>
        <div style="font-size:13px;margin-top:4px">
          طلبت التغيير إلى باقة <strong><?= e($pending_request['req_pkg_name']) ?></strong>
          — بتاريخ <?= dDate($pending_request['created_at']) ?>
        </div>
        <?php if ($req_status === 'rejected' && $pending_request['admin_note']): ?>
        <div class="mt-2 p-2 rounded" style="background:rgba(239,68,68,.08);font-size:12px;color:#7f1d1d">
          <i class="fas fa-comment-alt me-1"></i><strong>ملاحظة الإدارة:</strong> <?= e($pending_request['admin_note']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><i class="fas fa-exchange-alt text-primary me-2"></i>طلب تغيير الباقة</div>
      <div class="card-body">

        <?php if ($pending_request && $pending_request['status'] === 'pending'): ?>
        <!-- يوجد طلب معلق -->
        <div class="text-center py-4">
          <i class="fas fa-hourglass-half fa-3x text-warning mb-3"></i>
          <h6 class="fw-bold">طلبك قيد المراجعة</h6>
          <p class="text-muted" style="font-size:13px">لا يمكن إرسال طلب جديد حتى يُعالَج الطلب الحالي.</p>
        </div>
        <?php else: ?>

        <!-- عرض الباقات -->
        <p class="text-muted mb-3" style="font-size:13px">
          <i class="fas fa-info-circle me-1 text-info"></i>
          اختر الباقة التي تريد التغيير إليها، ثم أكمل تعبئة نموذج الطلب.
        </p>

        <div class="row g-3 mb-3" id="pkg-cards">
        <?php
        $pkg_icons  = ['seedling','star','building','gem'];
        $pkg_colors = ['#64748b','#d97706','#1d4ed8','#7c3aed'];
        $i = 0;
        $all_packages->data_seek(0);
        while ($pkg = $all_packages->fetch_assoc()):
          $is_current = $pkg['id'] == $office['package_id'];
          $ic  = $pkg_icons[$i % 4];
          $clr = $pkg_colors[$i % 4];
          $fl  = array_filter(array_map('trim', explode(',', $pkg['features'] ?? '')));
          $i++;
        ?>
        <div class="col-md-4">
          <label class="pkg-card-label <?= $is_current?'pkg-card-current':'' ?>" style="cursor:<?= $is_current?'default':'pointer' ?>">
            <?php if (!$is_current): ?>
            <input type="radio" name="_pkg_pick" value="<?= $pkg['id'] ?>" class="d-none pkg-radio"
                   onchange="pickPackage(<?= $pkg['id'] ?>, '<?= e($pkg['name']) ?>')">
            <?php endif; ?>
            <div class="card h-100 pkg-card-inner <?= $is_current?'border-primary':'' ?>" style="position:relative;transition:.2s">
              <?php if ($is_current): ?>
              <div class="position-absolute" style="top:-9px;right:12px;z-index:1">
                <span class="badge bg-primary">باقتك الحالية</span>
              </div>
              <?php endif; ?>
              <div class="card-body text-center p-3">
                <div style="width:44px;height:44px;border-radius:12px;background:<?=$clr?>;display:flex;align-items:center;justify-content:center;margin:0 auto 10px">
                  <i class="fas fa-<?=$ic?>" style="color:#fff;font-size:18px"></i>
                </div>
                <h6 class="fw-bold mb-1" style="font-size:14px"><?= e($pkg['name']) ?></h6>
                <div style="font-size:22px;font-weight:900;color:var(--mk-t1)"><?= (int)$pkg['price_yearly'] ?></div>
                <div style="font-size:11px;color:var(--mk-t4)">ر.س / سنة</div>
                <hr style="margin:10px 0">
                <ul class="list-unstyled mb-0" style="font-size:12px;text-align:right;line-height:2">
                  <li><i class="fas fa-users text-primary" style="width:16px;text-align:center;margin-left:6px"></i><?= ((int)$pkg['max_users']==0||(int)$pkg['max_users']>=999) ? 'مستخدمون غير محدودين' : 'حتى '.(int)$pkg['max_users'].' مستخدمين' ?></li>
                  <li><i class="fas fa-briefcase text-success" style="width:16px;text-align:center;margin-left:6px"></i><?= ((int)$pkg['max_cases']==0||(int)$pkg['max_cases']>=999) ? 'قضايا غير محدودة' : 'حتى '.(int)$pkg['max_cases'].' قضية' ?></li>
                  <?php foreach ($fl as $f): ?>
                  <li><i class="fas fa-check-circle text-success" style="width:16px;text-align:center;margin-left:6px"></i><?= e($f) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <div class="pkg-check-overlay" style="display:none;position:absolute;inset:0;border-radius:inherit;background:rgba(29,78,216,.07);border:2px solid #1d4ed8;pointer-events:none">
                <div style="position:absolute;top:8px;left:8px;background:#1d4ed8;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center">
                  <i class="fas fa-check" style="color:#fff;font-size:11px"></i>
                </div>
              </div>
            </div>
          </label>
        </div>
        <?php endwhile; ?>

        <?php if ($custom_pkg_enabled): ?>
        <!-- الباقة المخصصة -->
        <div class="col-12 mt-2">
          <div class="card" id="custom-pkg-card"
               style="border:2px dashed #a78bfa;background:#faf5ff;cursor:pointer;transition:.2s"
               onclick="showCustomBuilder()"
               onmouseenter="this.style.borderColor='#7c3aed'"
               onmouseleave="this.style.borderColor='#a78bfa'">
            <div class="card-body d-flex align-items-center gap-3 py-3">
              <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#a855f7);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-puzzle-piece" style="color:#fff;font-size:20px"></i>
              </div>
              <div>
                <div class="fw-bold mb-0" style="color:#5b21b6;font-size:15px">باقة مخصصة</div>
                <div class="text-muted" style="font-size:12px">ابنِ باقتك بنفسك — اختر الميزات والحدود التي تناسبك بالضبط</div>
              </div>
              <div class="ms-auto">
                <i class="fas fa-chevron-down text-muted" id="custom-pkg-chevron" style="transition:.2s"></i>
              </div>
            </div>
          </div>
        </div>

        <?php endif; // custom_pkg_enabled ?>
        </div><!-- /row -->

        <!-- بناء الباقة المخصصة -->
        <?php if ($custom_pkg_enabled): ?>
        <div id="custom-builder-wrap" style="display:none;margin-top:16px">
        <?php if (!empty($fp)): ?>
          <div class="card" style="border:2px solid #a78bfa">
            <div class="card-header d-flex align-items-center gap-2" style="background:linear-gradient(135deg,#7c3aed,#a855f7);border:none;border-radius:calc(.375rem - 2px) calc(.375rem - 2px) 0 0">
              <i class="fas fa-puzzle-piece text-white"></i>
              <span class="text-white fw-bold">بناء الباقة المخصصة</span>
              <button type="button" class="btn-close btn-close-white ms-auto" style="font-size:12px" onclick="hideCustomBuilder()"></button>
            </div>
            <div class="card-body">
              <div class="row g-4">
                <!-- قائمة الميزات -->
                <div class="col-lg-7">
                  <h6 class="fw-bold mb-3" style="color:#5b21b6"><i class="fas fa-list-check me-2"></i>اختر الميزات المطلوبة</h6>
                  <div class="row g-2" id="feat-grid">
                  <?php
                  $feat_keys_ui = ['has_finance','has_invoices','has_contracts','has_poa','has_correspondence','has_library','has_archive','has_ai','has_reports','has_api','has_precedents','has_digital_services'];
                  foreach ($fp as $_fk => $_fv) if (!empty($_fv['is_module'])) $feat_keys_ui[] = $_fk;
                  $mod_head_done = false;
                  foreach ($feat_keys_ui as $fk):
                    if (!isset($fp[$fk])) continue;
                    $f = $fp[$fk];
                    if (!$mod_head_done && strpos($fk, 'mod_') === 0):
                      $mod_head_done = true;
                  ?>
                  <div class="col-12"><h6 class="fw-bold mt-2 mb-0" style="color:#5b21b6;font-size:13px"><i class="fas fa-puzzle-piece me-2"></i>موديولات إضافية</h6></div>
                  <?php endif; ?>
                  <div class="col-sm-6">
                    <label class="feat-lbl d-flex align-items-center gap-2 p-2 rounded border"
                           style="cursor:pointer;transition:.15s;border-color:#e5e7eb !important"
                           data-py="<?= (float)$f['price_yearly'] ?>">
                      <input type="checkbox" class="custom-feat-chk" data-key="<?= $fk ?>"
                             style="width:16px;height:16px;accent-color:#7c3aed;cursor:pointer;flex-shrink:0"
                             onchange="calcCustom()">
                      <i class="fas fa-<?= e($f['feature_icon']) ?>" style="color:#7c3aed;width:14px;text-align:center;flex-shrink:0"></i>
                      <div style="flex:1;min-width:0">
                        <div style="font-size:12px;font-weight:600;line-height:1.3"><?= e($f['feature_label']) ?></div>
                        <?php if ((float)$f['price_yearly'] > 0): ?>
                        <div style="font-size:10px;color:#7c3aed"><?= number_format((float)$f['price_yearly'],0) ?> ر.س/سنة</div>
                        <?php endif; ?>
                      </div>
                    </label>
                  </div>
                  <?php endforeach; ?>
                  </div>
                </div>

                <!-- الحدود والسعر -->
                <div class="col-lg-5">
                  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;position:sticky;top:20px">
                    <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-sliders-h me-2"></i>الحدود والسعة</h6>

                    <div class="mb-3">
                      <label class="form-label fw-semibold" style="font-size:12px">
                        <i class="fas fa-users me-1 text-primary"></i>عدد المستخدمين
                      </label>
                      <div class="d-flex align-items-center gap-2">
                        <input type="number" id="c_users_inp" min="1" value="<?= $fp_base_users ?>"
                               class="form-control form-control-sm" style="width:85px" oninput="calcCustom()">
                        <div style="font-size:11px;color:#64748b;flex:1">
                          أساسي: <?= $fp_base_users ?>
                          <?php if (!empty($fp['per_user']) && (float)$fp['per_user']['price_yearly'] > 0): ?>
                          <br>إضافي: <strong><?= number_format((float)$fp['per_user']['price_yearly'],0) ?> ر.س/مستخدم/سنة</strong>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold" style="font-size:12px">
                        <i class="fas fa-briefcase me-1 text-success"></i>عدد القضايا
                      </label>
                      <div class="d-flex align-items-center gap-2">
                        <input type="number" id="c_cases_inp" min="1" value="<?= $fp_base_cases ?>"
                               class="form-control form-control-sm" style="width:85px" oninput="calcCustom()">
                        <div style="font-size:11px;color:#64748b;flex:1">
                          أساسي: <?= $fp_base_cases ?>
                          <?php if (!empty($fp['per_100_cases']) && (float)$fp['per_100_cases']['price_yearly'] > 0): ?>
                          <br>إضافي: <strong><?= number_format((float)$fp['per_100_cases']['price_yearly'],0) ?> ر.س/100 قضية/سنة</strong>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <!-- عرض السعر -->
                    <div style="background:linear-gradient(135deg,#7c3aed,#a855f7);border-radius:10px;padding:14px 16px;color:#fff;text-align:center">
                      <div style="font-size:11px;opacity:.8;margin-bottom:2px">السعر السنوي المقدر</div>
                      <div style="font-size:30px;font-weight:900;line-height:1.1" id="custom_price_display">0</div>
                      <div style="font-size:11px;opacity:.8">ر.س / سنة</div>
                      <div style="font-size:10px;opacity:.65;margin-top:4px">* السعر النهائي يُحدده الإدارة عند الموافقة</div>
                    </div>

                    <button type="button" class="btn btn-outline-primary w-100 mt-3" id="build-submit-btn"
                            style="display:none" onclick="showCustomForm()">
                      <i class="fas fa-paper-plane me-1"></i>أرسل طلب الباقة المخصصة
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- نموذج الإرسال للباقة المخصصة -->
          <div id="custom-form-wrap" style="display:none;margin-top:14px">
            <div class="card">
              <div class="card-body">
                <?php
                $plat_bank_name2 = sc($conn,'platform_bank_name','');
                $plat_bank_iban2 = sc($conn,'platform_bank_iban','');
                $plat_bank_acct2 = sc($conn,'platform_bank_account','');
                $plat_pay_instr2 = sc($conn,'platform_payment_instructions','');
                $has_bank2 = ($plat_bank_iban2 || $plat_bank_name2);
                ?>

                <form method="POST" enctype="multipart/form-data" id="customReqForm">
                  <input type="hidden" name="form_type" value="custom_request">
                  <input type="hidden" name="custom_price_monthly" id="custom_price_val" value="0">
                  <div id="feat-hidden-wrap"></div>

                  <div class="mb-3 p-3 rounded" style="background:#f5f3ff;border:1px solid #c4b5fd">
                    <div style="font-size:13px;font-weight:600;color:#5b21b6">
                      <i class="fas fa-puzzle-piece me-2"></i>باقة مخصصة — الميزات المختارة: <strong id="custom_summary_feat">—</strong>
                    </div>
                    <div style="font-size:12px;color:#6d28d9;margin-top:4px">
                      مستخدمون: <strong id="custom_summary_users">—</strong> &bull;
                      قضايا: <strong id="custom_summary_cases">—</strong>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">ملاحظات إضافية للطلب <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" required
                              placeholder="أي متطلبات خاصة أو تفاصيل إضافية تريد إبلاغ الإدارة بها..."></textarea>
                  </div>

                  <!-- طريقة الدفع -->
                  <div class="mb-3">
                    <label class="form-label fw-semibold"><i class="fas fa-credit-card me-1 text-primary"></i>طريقة الدفع</label>
                    <div class="d-flex gap-3 flex-wrap">
                      <div class="pay-method-card2 selected2" id="pm2_bank" onclick="selectPayMethod2('bank')"
                           style="border:2px solid #7c3aed;background:#f5f3ff;border-radius:10px;padding:12px 18px;cursor:pointer;transition:.15s;min-width:160px">
                        <i class="fas fa-university mb-1 d-block" style="font-size:18px;color:#7c3aed"></i>
                        <div style="font-size:13px;font-weight:600">تحويل بنكي</div>
                        <div style="font-size:11px;color:#6b7280">أرفق إيصال التحويل</div>
                      </div>
                      <div class="pay-method-card2" id="pm2_online" onclick="selectPayMethod2('online')"
                           style="border:2px solid #e5e7eb;background:#fff;border-radius:10px;padding:12px 18px;cursor:pointer;transition:.15s;min-width:160px">
                        <i class="fas fa-bolt text-warning mb-1 d-block" style="font-size:18px"></i>
                        <div style="font-size:13px;font-weight:600">دفع إلكتروني</div>
                        <div style="font-size:11px;color:#6b7280">بطاقة / مدى / Apple Pay</div>
                      </div>
                    </div>
                    <input type="hidden" name="payment_method" id="payment_method2_val" value="bank">
                  </div>

                  <!-- قسم التحويل البنكي -->
                  <div id="pm2_bank_section">
                    <?php if ($has_bank2): ?>
                    <div class="p-3 rounded mb-3" style="background:#f0fdf4;border:1px solid #86efac">
                      <h6 class="fw-bold mb-2" style="color:#15803d"><i class="fas fa-university me-2"></i>بيانات التحويل البنكي</h6>
                      <div class="row g-2" style="font-size:13px">
                        <?php if ($plat_bank_name2): ?><div class="col-sm-4"><span class="text-muted">البنك:</span> <strong><?= e($plat_bank_name2) ?></strong></div><?php endif; ?>
                        <?php if ($plat_bank_iban2): ?><div class="col-sm-4"><span class="text-muted">IBAN:</span> <strong class="font-monospace"><?= e($plat_bank_iban2) ?></strong></div><?php endif; ?>
                        <?php if ($plat_bank_acct2): ?><div class="col-sm-4"><span class="text-muted">رقم الحساب:</span> <strong class="font-monospace"><?= e($plat_bank_acct2) ?></strong></div><?php endif; ?>
                      </div>
                      <?php if ($plat_pay_instr2): ?>
                      <div class="mt-2 p-2 rounded" style="background:#dcfce7;font-size:12px;color:#166534"><i class="fas fa-info-circle me-1"></i><?= e($plat_pay_instr2) ?></div>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="fas fa-file-upload me-1 text-primary"></i>إيصال التحويل البنكي <span class="text-danger">*</span>
                        <span class="text-muted fw-normal" style="font-size:12px">(JPG, PNG, PDF — 5 MB)</span>
                      </label>
                      <input type="file" name="payment_proof" id="c_payment_proof_inp" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp">
                    </div>
                  </div>

                  <!-- قسم الدفع الإلكتروني -->
                  <div id="pm2_online_section" style="display:none">
                    <div class="p-4 rounded mb-3 text-center" style="background:#fffbeb;border:2px dashed #f59e0b">
                      <i class="fas fa-bolt text-warning fa-2x mb-2"></i>
                      <h6 class="fw-bold mb-1">الدفع الإلكتروني قريباً</h6>
                      <p class="text-muted mb-0" style="font-size:13px">سيتوفر الدفع بالبطاقة ومدى وApple Pay قريباً.</p>
                    </div>
                  </div>

                  <div class="p-3 rounded mb-3" style="background:#fffbeb;border:1px solid #fde68a">
                    <h6 class="fw-bold mb-2" style="color:#92400e"><i class="fas fa-file-signature me-2"></i>تعهد الطلب</h6>
                    <div style="font-size:13px;color:#78350f;line-height:1.8">
                      أتعهد بأن الباقة المخصصة المطلوبة تمثل احتياجاتي الفعلية وأوافق على السعر النهائي الذي تُحدده الإدارة.
                    </div>
                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" name="commitment" id="custom_commit_chk" required>
                      <label class="form-check-label fw-semibold" for="custom_commit_chk" style="font-size:13px;color:#92400e">
                        أوافق على التعهد أعلاه وأؤكد طلب الباقة المخصصة
                      </label>
                    </div>
                  </div>

                  <!-- hidden capacity inputs (submitted from builder values) -->
                  <input type="hidden" name="c_users" id="h_c_users" value="<?= $fp_base_users ?>">
                  <input type="hidden" name="c_cases" id="h_c_cases" value="<?= $fp_base_cases ?>">
                  <input type="hidden" name="c_storage_mb" id="h_c_storage" value="0">

                  <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">
                      <i class="fas fa-paper-plane me-1"></i>إرسال طلب الباقة المخصصة
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="hideCustomForm()">إلغاء</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>لم تُحدَّد أسعار الميزات بعد. تواصل مع الإدارة.</div>
        <?php endif; ?>
        </div><!-- /custom-builder-wrap -->
        <?php endif; // custom_pkg_enabled ?>

        <!-- نموذج الطلب (الباقات الجاهزة) -->
        <div id="pkg-form-wrap" style="display:none">
          <hr>
          <?php
          $plat_bank_name  = sc($conn,'platform_bank_name','');
          $plat_bank_iban  = sc($conn,'platform_bank_iban','');
          $plat_bank_acct  = sc($conn,'platform_bank_account','');
          $plat_pay_instr  = sc($conn,'platform_payment_instructions','');
          $has_bank = ($plat_bank_iban || $plat_bank_name);
          ?>

          <form method="POST" enctype="multipart/form-data" id="pkgReqForm">
            <input type="hidden" name="form_type" value="package_request">
            <input type="hidden" name="requested_package_id" id="req_pkg_id" value="">

            <div class="mb-3 p-3 rounded" style="background:#eff6ff;border:1px solid #bfdbfe">
              <div style="font-size:13px;font-weight:600;color:#1d4ed8">
                <i class="fas fa-exchange-alt me-2"></i>
                الانتقال من: <strong><?= e($office['pkg_name'] ?? '—') ?></strong>
                &nbsp;→&nbsp;
                باقة: <strong id="req_pkg_label">—</strong>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">سبب طلب التغيير <span class="text-danger">*</span></label>
              <textarea name="reason" class="form-control" rows="3" required
                        placeholder="اشرح باختصار سبب رغبتك في تغيير الباقة..."></textarea>
            </div>

            <!-- طريقة الدفع -->
            <div class="mb-3">
              <label class="form-label fw-semibold"><i class="fas fa-credit-card me-1 text-primary"></i>طريقة الدفع</label>
              <div class="d-flex gap-3 flex-wrap">
                <?php if ($has_bank): ?>
                <div class="pay-method-card selected" id="pm_bank" onclick="selectPayMethod('bank')"
                     style="border:2px solid #1d4ed8;background:#eff6ff;border-radius:10px;padding:12px 18px;cursor:pointer;transition:.15s;min-width:160px">
                  <i class="fas fa-university text-primary mb-1 d-block" style="font-size:18px"></i>
                  <div style="font-size:13px;font-weight:600">تحويل بنكي</div>
                  <div style="font-size:11px;color:#6b7280">IBAN / حوالة بنكية</div>
                </div>
                <?php else: ?>
                <div class="pay-method-card selected" id="pm_bank" onclick="selectPayMethod('bank')"
                     style="border:2px solid #1d4ed8;background:#eff6ff;border-radius:10px;padding:12px 18px;cursor:pointer;transition:.15s;min-width:160px">
                  <i class="fas fa-university text-primary mb-1 d-block" style="font-size:18px"></i>
                  <div style="font-size:13px;font-weight:600">تحويل بنكي</div>
                  <div style="font-size:11px;color:#6b7280">أرفق إيصال التحويل</div>
                </div>
                <?php endif; ?>
                <div class="pay-method-card" id="pm_online" onclick="selectPayMethod('online')"
                     style="border:2px solid #e5e7eb;background:#fff;border-radius:10px;padding:12px 18px;cursor:pointer;transition:.15s;min-width:160px">
                  <i class="fas fa-bolt text-warning mb-1 d-block" style="font-size:18px"></i>
                  <div style="font-size:13px;font-weight:600">دفع إلكتروني</div>
                  <div style="font-size:11px;color:#6b7280">بطاقة / مدى / Apple Pay</div>
                </div>
              </div>
              <input type="hidden" name="payment_method" id="payment_method_val" value="bank">
            </div>

            <!-- قسم التحويل البنكي -->
            <div id="pm_bank_section">
              <?php if ($has_bank): ?>
              <div class="p-3 rounded mb-3" style="background:#f0fdf4;border:1px solid #86efac">
                <h6 class="fw-bold mb-2" style="color:#15803d"><i class="fas fa-university me-2"></i>بيانات التحويل البنكي</h6>
                <div class="row g-2" style="font-size:13px">
                  <?php if ($plat_bank_name): ?>
                  <div class="col-sm-4"><span class="text-muted">البنك:</span> <strong><?= e($plat_bank_name) ?></strong></div>
                  <?php endif; ?>
                  <?php if ($plat_bank_iban): ?>
                  <div class="col-sm-4"><span class="text-muted">IBAN:</span> <strong class="font-monospace"><?= e($plat_bank_iban) ?></strong></div>
                  <?php endif; ?>
                  <?php if ($plat_bank_acct): ?>
                  <div class="col-sm-4"><span class="text-muted">رقم الحساب:</span> <strong class="font-monospace"><?= e($plat_bank_acct) ?></strong></div>
                  <?php endif; ?>
                </div>
                <?php if ($plat_pay_instr): ?>
                <div class="mt-2 p-2 rounded" style="background:#dcfce7;font-size:12px;color:#166534">
                  <i class="fas fa-info-circle me-1"></i><?= e($plat_pay_instr) ?>
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <div class="mb-3">
                <label class="form-label fw-semibold">
                  <i class="fas fa-file-upload me-1 text-primary"></i>إيصال التحويل البنكي <span class="text-danger">*</span>
                  <span class="text-muted fw-normal" style="font-size:12px">(JPG, PNG, PDF — 5 MB)</span>
                </label>
                <input type="file" name="payment_proof" id="payment_proof_inp" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp">
                <div class="form-text">ارفع صورة / PDF الإيصال لتسريع الموافقة على طلبك.</div>
              </div>
            </div>

            <!-- قسم الدفع الإلكتروني -->
            <div id="pm_online_section" style="display:none">
              <div class="p-4 rounded mb-3 text-center" style="background:#fffbeb;border:2px dashed #f59e0b">
                <i class="fas fa-bolt text-warning fa-2x mb-2"></i>
                <h6 class="fw-bold mb-1">الدفع الإلكتروني قريباً</h6>
                <p class="text-muted mb-0" style="font-size:13px">
                  سيتوفر الدفع بالبطاقة ومدى وApple Pay قريباً.<br>
                  في الوقت الحالي يرجى التحويل البنكي أو التواصل مع الإدارة.
                </p>
              </div>
            </div>

            <!-- التعهد -->
            <div class="p-3 rounded mb-3" style="background:#fffbeb;border:1px solid #fde68a">
              <h6 class="fw-bold mb-2" style="color:#92400e"><i class="fas fa-file-signature me-2"></i>تعهد التغيير</h6>
              <div style="font-size:13px;color:#78350f;line-height:1.8">
                أتعهد أنا مالك / مدير المكتب بما يلي:
                <ol class="mt-2 mb-2">
                  <li>أن طلب تغيير الباقة تم بمحض إرادتي الحرة.</li>
                  <li>أنني اطلعت على مزايا الباقة المطلوبة وأوافق على رسومها.</li>
                  <li>أن تغيير الباقة سيسري فور موافقة الإدارة.</li>
                  <li>في حال التخفيض إلى باقة أقل، قد تُحذف البيانات التي تتجاوز حدود الباقة الجديدة.</li>
                </ol>
              </div>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="commitment" id="commitment_chk" required>
                <label class="form-check-label fw-semibold" for="commitment_chk" style="font-size:13px;color:#92400e">
                  أوافق على جميع بنود التعهد أعلاه وأؤكد طلب التغيير
                </label>
              </div>
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane me-1"></i>إرسال طلب التغيير
              </button>
              <button type="button" class="btn btn-outline-secondary" onclick="cancelPick()">إلغاء</button>
            </div>
          </form>
        </div><!-- /pkg-form-wrap -->

        <?php endif; ?>
      </div>
    </div>
  </div><!-- /col -->
</div>

<style>
.pkg-card-label { display:block; }
.pkg-card-label * { cursor:inherit; }
.pkg-card-label:not(.pkg-card-current):hover .pkg-card-inner {
  border-color:#1d4ed8 !important;
  box-shadow:0 0 0 3px rgba(29,78,216,.12);
}
.pkg-card-label.selected .pkg-card-inner { border-color:#1d4ed8 !important; box-shadow:0 0 0 3px rgba(29,78,216,.18); }
.pkg-card-label.selected .pkg-check-overlay { display:block !important; }
</style>

<script>
function pickPackage(id, name) {
  // إزالة التحديد السابق
  document.querySelectorAll('.pkg-card-label').forEach(function(l){ l.classList.remove('selected'); });
  // تحديد الحالي
  var lbl = document.querySelector('input[value="'+id+'"]');
  if (lbl) lbl.closest('.pkg-card-label').classList.add('selected');
  // ملء الفورم
  document.getElementById('req_pkg_id').value  = id;
  document.getElementById('req_pkg_label').textContent = name;
  document.getElementById('pkg-form-wrap').style.display = 'block';
  document.getElementById('pkg-form-wrap').scrollIntoView({behavior:'smooth', block:'start'});
}
function cancelPick() {
  document.querySelectorAll('.pkg-card-label').forEach(function(l){ l.classList.remove('selected'); });
  document.querySelectorAll('.pkg-radio').forEach(function(r){ r.checked = false; });
  document.getElementById('pkg-form-wrap').style.display = 'none';
}

/* ── الباقة المخصصة ── */
var _fp_py  = <?= json_encode(array_map('floatval', array_column($fp, 'price_yearly', 'feature_key'))) ?>;
var _fp_lbl = <?= json_encode(array_column($fp, 'feature_label', 'feature_key')) ?>;
var _fp_base_users   = <?= $fp_base_users ?>;
var _fp_base_cases   = <?= $fp_base_cases ?>;
var _fp_base_price   = <?= $fp_base_price ?>;

function showCustomBuilder() {
  var wrap = document.getElementById('custom-builder-wrap');
  var ic   = document.getElementById('custom-pkg-chevron');
  if (wrap.style.display === 'none') {
    wrap.style.display = '';
    ic.style.transform = 'rotate(180deg)';
    // hide standard form if open
    document.getElementById('pkg-form-wrap').style.display = 'none';
    cancelPick();
    wrap.scrollIntoView({behavior:'smooth', block:'start'});
  } else {
    hideCustomBuilder();
  }
}

function hideCustomBuilder() {
  document.getElementById('custom-builder-wrap').style.display = 'none';
  document.getElementById('custom-pkg-chevron').style.transform = '';
  hideCustomForm();
}

function calcCustom() {
  var total = _fp_base_price;
  var selLabels = [];

  document.querySelectorAll('.custom-feat-chk').forEach(function(chk) {
    var lbl = chk.closest('.feat-lbl');
    if (chk.checked) {
      total += parseFloat(lbl.dataset.py || 0);
      var key = chk.dataset.key;
      if (_fp_lbl[key]) selLabels.push(_fp_lbl[key]);
      lbl.style.borderColor = '#7c3aed';
      lbl.style.background  = '#f5f3ff';
    } else {
      lbl.style.borderColor = '#e5e7eb';
      lbl.style.background  = '';
    }
  });

  var users   = parseInt(document.getElementById('c_users_inp').value)   || _fp_base_users;
  var cases   = parseInt(document.getElementById('c_cases_inp').value)   || _fp_base_cases;

  var extra_users    = Math.max(0, users - _fp_base_users);
  var extra_100cases = Math.ceil(Math.max(0, cases - _fp_base_cases) / 100);

  if (_fp_py['per_user'])     total += extra_users    * (_fp_py['per_user'] || 0);
  if (_fp_py['per_100_cases'])total += extra_100cases * (_fp_py['per_100_cases'] || 0);

  total = Math.max(0, Math.round(total * 100) / 100);

  document.getElementById('custom_price_display').textContent = total.toLocaleString('ar-SA', {maximumFractionDigits:0});
  document.getElementById('custom_price_val').value = total.toFixed(2);

  var hasAny = selLabels.length > 0 || users > _fp_base_users || cases > _fp_base_cases;
  document.getElementById('build-submit-btn').style.display = hasAny ? '' : 'none';

  // update summary
  document.getElementById('custom_summary_feat').textContent   = selLabels.length ? selLabels.join('، ') : 'لم تُختَر ميزات';
  document.getElementById('custom_summary_users').textContent  = users;
  document.getElementById('custom_summary_cases').textContent  = cases;

  // sync hidden capacity inputs
  document.getElementById('h_c_users').value   = users;
  document.getElementById('h_c_cases').value   = cases;
}

function showCustomForm() {
  // inject hidden feature checkboxes into the form
  var wrap = document.getElementById('feat-hidden-wrap');
  wrap.innerHTML = '';
  document.querySelectorAll('.custom-feat-chk').forEach(function(chk) {
    if (chk.checked) {
      var h = document.createElement('input');
      h.type  = 'hidden';
      h.name  = 'feat[' + chk.dataset.key + ']';
      h.value = '1';
      wrap.appendChild(h);
    }
  });
  document.getElementById('custom-form-wrap').style.display = '';
  document.getElementById('custom-form-wrap').scrollIntoView({behavior:'smooth', block:'start'});
}

function hideCustomForm() {
  document.getElementById('custom-form-wrap').style.display = 'none';
}

/* ── طرق الدفع (الباقات الجاهزة) ── */
function selectPayMethod(method) {
  document.getElementById('payment_method_val').value = method;
  var isBank = (method === 'bank');
  // بطاقات الاختيار
  ['bank','online'].forEach(function(m) {
    var card = document.getElementById('pm_' + m);
    if (!card) return;
    if (m === method) {
      card.style.borderColor = '#1d4ed8';
      card.style.background  = '#eff6ff';
    } else {
      card.style.borderColor = '#e5e7eb';
      card.style.background  = '#fff';
    }
  });
  document.getElementById('pm_bank_section').style.display   = isBank ? '' : 'none';
  document.getElementById('pm_online_section').style.display = isBank ? 'none' : '';
}

/* ── طرق الدفع (الباقة المخصصة) ── */
function selectPayMethod2(method) {
  document.getElementById('payment_method2_val').value = method;
  var isBank = (method === 'bank');
  ['bank','online'].forEach(function(m) {
    var card = document.getElementById('pm2_' + m);
    if (!card) return;
    if (m === method) {
      card.style.borderColor = '#7c3aed';
      card.style.background  = '#f5f3ff';
    } else {
      card.style.borderColor = '#e5e7eb';
      card.style.background  = '#fff';
    }
  });
  document.getElementById('pm2_bank_section').style.display   = isBank ? '' : 'none';
  document.getElementById('pm2_online_section').style.display = isBank ? 'none' : '';
}
</script>

<script>
function previewLogo(inp) {
  if (!inp.files || !inp.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var wrap = document.getElementById('logo-preview-wrap');
    var ph   = document.getElementById('logo-placeholder');
    if (ph) ph.style.display = 'none';
    var img = document.getElementById('logo-preview-img');
    if (!img) { img = document.createElement('img'); img.id='logo-preview-img'; img.style='max-width:100%;max-height:100%;object-fit:contain'; wrap.appendChild(img); }
    img.src = e.target.result;
  };
  reader.readAsDataURL(inp.files[0]);
}
</script>

<!-- ═══ TAB: الأمان ═══ -->
<?php elseif ($tab === 'security'): ?>
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="fas fa-key text-warning me-2"></i>تغيير كلمة المرور</div>
      <div class="card-body">
        <form method="POST" onsubmit="return profValidatePwd()">
          <input type="hidden" name="form_type" value="change_password">
          <div class="mb-3">
            <label class="form-label">كلمة المرور الحالية</label>
            <div style="position:relative">
              <input type="password" name="old_password" id="prof_old" class="form-control" required>
              <button type="button" onclick="profToggle('prof_old',this)"
                      style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer">
                <i class="fas fa-eye" style="font-size:13px"></i>
              </button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">كلمة المرور الجديدة</label>
            <div style="position:relative">
              <input type="password" name="new_password" id="prof_new" class="form-control" required oninput="profCheckRules()">
              <button type="button" onclick="profToggle('prof_new',this)"
                      style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer">
                <i class="fas fa-eye" style="font-size:13px"></i>
              </button>
            </div>
            <!-- مؤشر القوة -->
            <div style="margin-top:5px">
              <div style="height:4px;background:#e2e8f0;border-radius:4px;overflow:hidden">
                <div id="profPwdBar" style="height:100%;width:0%;transition:width .3s,background .3s;border-radius:4px"></div>
              </div>
              <span id="profPwdLbl" style="font-size:11px;color:#94a3b8"></span>
            </div>
            <!-- القواعد -->
            <ul style="list-style:none;padding:5px 0 0;margin:0;font-size:12px;columns:2">
              <li id="pr_len"   style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:9px"></i>8 أحرف على الأقل</li>
              <li id="pr_upper" style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:9px"></i>حرف كبير (A-Z)</li>
              <li id="pr_lower" style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:9px"></i>حرف صغير (a-z)</li>
              <li id="pr_num"   style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:9px"></i>رقم (0-9)</li>
              <li id="pr_sym"   style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:9px"></i>رمز خاص (!@#$%...)</li>
            </ul>
          </div>
          <div class="mb-3">
            <label class="form-label">تأكيد كلمة المرور الجديدة</label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="prof_confirm" class="form-control" required oninput="profCheckMatch()">
              <button type="button" onclick="profToggle('prof_confirm',this)"
                      style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer">
                <i class="fas fa-eye" style="font-size:13px"></i>
              </button>
            </div>
            <div id="profMatchMsg" style="font-size:12px;margin-top:4px;min-height:18px"></div>
          </div>
          <!-- خطأ inline -->
          <div id="profPwdError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#991b1b">
            <i class="fas fa-exclamation-circle me-2"></i><span id="profPwdErrorMsg"></span>
          </div>
          <button type="submit" class="btn btn-warning w-100">
            <i class="fas fa-lock me-1"></i>تغيير كلمة المرور
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function profToggle(id, btn) {
  var inp = document.getElementById(id);
  var ic  = btn.querySelector('i');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  ic.className = (inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash');
  ic.style.fontSize = '13px';
}
function profSetRule(id, ok) {
  var li = document.getElementById(id); if (!li) return;
  var ic = li.querySelector('i');
  li.style.color = ok ? '#16a34a' : '#94a3b8';
  ic.className   = (ok ? 'fas fa-circle-check' : 'fas fa-circle-xmark') + ' me-1';
  ic.style.fontSize = '9px';
}
function profCheckRules() {
  var val = (document.getElementById('prof_new') || {}).value || '';
  var ok = [val.length>=8, /[A-Z]/.test(val), /[a-z]/.test(val), /[0-9]/.test(val), /[^A-Za-z0-9]/.test(val)];
  ['pr_len','pr_upper','pr_lower','pr_num','pr_sym'].forEach(function(id,i){ profSetRule(id, ok[i]); });
  var score = ok.filter(Boolean).length;
  var cfgs  = [['0%','#dc2626',''],['20%','#dc2626','ضعيفة جداً'],['40%','#f97316','ضعيفة'],['60%','#d97706','متوسطة'],['80%','#2563eb','جيدة'],['100%','#16a34a','قوية جداً']];
  var bar   = document.getElementById('profPwdBar');
  var lbl   = document.getElementById('profPwdLbl');
  if (bar) { bar.style.width = cfgs[score][0]; bar.style.background = cfgs[score][1]; }
  if (lbl) { lbl.textContent = val.length ? cfgs[score][2] : ''; lbl.style.color = cfgs[score][1]; }
  if (document.getElementById('prof_confirm').value) profCheckMatch();
  return ok.every(Boolean);
}
function profCheckMatch() {
  var p1  = (document.getElementById('prof_new')     || {}).value || '';
  var p2  = (document.getElementById('prof_confirm') || {}).value || '';
  var msg = document.getElementById('profMatchMsg'); if (!msg || !p2) return;
  if (p1 === p2) {
    msg.innerHTML = '<i class="fas fa-check-circle me-1" style="color:#16a34a"></i><span style="color:#16a34a">كلمتا المرور متطابقتان</span>';
  } else {
    msg.innerHTML = '<i class="fas fa-times-circle me-1" style="color:#dc2626"></i><span style="color:#dc2626">كلمتا المرور غير متطابقتين</span>';
  }
}
function profValidatePwd() {
  var newP = (document.getElementById('prof_new')     || {}).value || '';
  var conP = (document.getElementById('prof_confirm') || {}).value || '';
  var errBox = document.getElementById('profPwdError');
  var errMsg = document.getElementById('profPwdErrorMsg');
  var hide = function(){ if(errBox) errBox.style.display='none'; };
  var show = function(m){ if(errBox&&errMsg){ errMsg.textContent=m; errBox.style.display='block'; errBox.scrollIntoView({behavior:'smooth',block:'center'}); } };

  hide();
  var checks = [
    [newP.length>=8,          '8 أحرف على الأقل'],
    [/[A-Z]/.test(newP),      'حرف كبير (A-Z)'],
    [/[a-z]/.test(newP),      'حرف صغير (a-z)'],
    [/[0-9]/.test(newP),      'رقم (0-9)'],
    [/[^A-Za-z0-9]/.test(newP),'رمز خاص (!@#$%)'],
    [newP === conP,            'تطابق كلمتَي المرور'],
  ];
  for (var i = 0; i < checks.length; i++) {
    if (!checks[i][0]) { show('مطلوب: ' + checks[i][1]); return false; }
  }
  return true;
}
</script>

<?php include '../includes/office_footer.php'; ?>
