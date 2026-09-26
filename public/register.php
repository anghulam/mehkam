<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/payment_helper.php';


$pkg_id        = (int)($_GET['package'] ?? $_POST['package_id'] ?? 0);
$billing_cycle = in_array($_GET['billing'] ?? $_POST['billing_cycle'] ?? '', ['yearly']) ? 'yearly' : 'monthly';
$is_custom     = !empty($_GET['custom']) || !empty($_POST['is_custom']);

// بيانات الباقة المخصصة من URL
$custom_features_raw = trim($_GET['features'] ?? $_POST['custom_features_raw'] ?? '');
$custom_users        = max(1,(int)($_GET['users']   ?? $_POST['custom_users']   ?? 3));
$custom_cases        = max(1,(int)($_GET['cases']   ?? $_POST['custom_cases']   ?? 50));
$custom_storage      = max(0,(int)($_GET['storage'] ?? $_POST['custom_storage'] ?? 0));
$custom_price        = max(0,(float)($_GET['price'] ?? $_POST['custom_price']   ?? 0));

$feat_label_map = [
    'has_finance'=>'الشؤون المالية','has_invoices'=>'الفواتير','has_contracts'=>'العقود',
    'has_poa'=>'الوكالات','has_correspondence'=>'الصادر والوارد','has_library'=>'المكتبة القانونية',
    'has_archive'=>'الأرشيف','has_ai'=>'المساعد الذكي AI','has_reports'=>'التقارير المتقدمة',
    'has_api'=>'API','has_precedents'=>'السوابق القضائية','has_digital_services'=>'الخدمات الرقمية',
];

// تحميل أسعار الميزات للباقة المخصصة
$fp = []; $fp_base_u = 3; $fp_base_c = 50; $fp_base_p = 0;
try {
    $fpr = $conn->query("SELECT * FROM feature_prices ORDER BY sort_order");
    if ($fpr) while ($fr = $fpr->fetch_assoc()) $fp[$fr['feature_key']] = $fr;
    $r1 = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='custom_base_users' LIMIT 1");
    if ($r1 && $v=$r1->fetch_assoc()) $fp_base_u = max(1,(int)$v['setting_value']);
    $r2 = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='custom_base_cases' LIMIT 1");
    if ($r2 && $v=$r2->fetch_assoc()) $fp_base_c = max(1,(int)$v['setting_value']);
    $r3 = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='custom_base_price' LIMIT 1");
    if ($r3 && $v=$r3->fetch_assoc()) $fp_base_p = max(0,(float)$v['setting_value']);
} catch(\Exception $e) {}

// الموديولات الإضافية تظهر تلقائياً كخيارات في الباقة المخصصة
require_once '../includes/module_helper.php';
$feat_keys_builder = ['has_finance','has_invoices','has_contracts','has_poa','has_correspondence','has_library','has_archive','has_ai','has_reports','has_api','has_precedents','has_digital_services'];
custom_pkg_inject_modules($conn, $fp, $feat_keys_builder);
foreach ($fp as $_k => $_v) if (!empty($_v['is_module'])) $feat_label_map[$_k] = $_v['feature_label'];

// هل تم اختيار الميزات مسبقاً؟ (عبر URL من pricing.php أو POST)
$features_already_set = $is_custom && ($custom_features_raw !== '' || $custom_price > 0);

// التحقق من أن الباقة المخصصة مفعّلة
if ($is_custom && sc($conn,'custom_package_enabled','1') !== '1') {
    header("Location: pricing.php"); exit;
}

$pkg = null;
if ($pkg_id) {
    $r = $conn->query("SELECT * FROM packages WHERE id=$pkg_id AND is_active=1");
    if ($r) $pkg = $r->fetch_assoc();
}
// باقة سعرها 0 = مخصصة (يتفاوض عليها الأدمن)
if ($pkg && (float)$pkg['price_monthly'] == 0) {
    $is_custom = true;
}
if (!$is_custom && !$pkg) { header("Location: pricing.php"); exit; }

$error   = '';
$success = false;

// هل يوجد باقة مخصصة (سعرها 0) في قائمة الباقات؟ — يُعرَّف هنا لأنه يُستخدم في HTML قبل تعريف $show_feat_step
$has_custom_in_list = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $office_name = trim($_POST['office_name']    ?? '');
    $license     = trim($_POST['license_number'] ?? '');
    $owner_name  = trim($_POST['owner_name']     ?? '');
    $city        = trim($_POST['city']           ?? '');
    $phone       = trim($_POST['phone']          ?? '');
    $email       = trim($_POST['email']          ?? '');
    $username    = trim($_POST['username']       ?? '');
    $password    = trim($_POST['password']       ?? '');
    $password2   = trim($_POST['password2']      ?? '');
    $payment_ref = trim($_POST['payment_ref']    ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'bank');
    $agree       = !empty($_POST['agree']);
    $affiliate_code = trim($_POST['affiliate_code'] ?? '');
    if ($affiliate_code === '') {
        // لا يوجد كود مُدخَل يدوياً — نأخذه من رابط الإحالة (GET) أو الكوكي التي زرعها affiliate_capture_click()
        $affiliate_code = trim($_GET['ref'] ?? $_COOKIE['mk_aff_ref'] ?? '');
    }

    if (!function_exists('affiliate_code_valid')) require_once __DIR__ . '/../includes/affiliate_helper.php';
    $affiliate_row = $affiliate_code !== '' ? affiliate_code_valid($conn, $affiliate_code) : null;

    // Server-side validation
    if (!$agree) {
        $error = 'يجب الموافقة على شروط الاستخدام وسياسة الخصوصية';
    } elseif (!$office_name || !$owner_name || !$phone || !$email || !$username || !$password) {
        $error = 'يرجى ملء جميع الحقول المطلوبة';
    } elseif ($affiliate_code !== '' && !$affiliate_row) {
        $error = 'كود الأفلييت غير صحيح — تأكد منه مع من أحالك';
    } elseif ($password !== $password2) {
        $error = 'كلمتا المرور غير متطابقتين';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $error = 'كلمة المرور يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، ورمز خاص';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد الإلكتروني غير صالح';
    } elseif (!preg_match('/^05[0-9]{8}$/', $phone)) {
        $error = 'رقم الجوال يجب أن يبدأ بـ 05 ويكون 10 أرقام';
    } else {
        // Check username availability
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $chk->bind_param("s",$username); $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'اسم المستخدم مستخدم بالفعل، يرجى اختيار اسم آخر';
        } else {
            // Validate payment
            if ($payment_method === 'bank') {
                // Process receipt upload
                if (!empty($_FILES['bank_receipt']['tmp_name'])) {
                    $receipt_dir = dirname(dirname(__FILE__)).'/uploads/receipts/';
                    if (!is_dir($receipt_dir)) mkdir($receipt_dir, 0755, true);
                    $ext = strtolower(pathinfo($_FILES['bank_receipt']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','gif','pdf'])) {
                        $error = 'نوع الملف غير مسموح به. يُسمح بـ: JPG, PNG, PDF';
                    } elseif ($_FILES['bank_receipt']['size'] > 5 * 1024 * 1024) {
                        $error = 'حجم الملف يتجاوز 5 ميجابايت';
                    } else {
                        $receipt_name = 'receipt_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
                        if (move_uploaded_file($_FILES['bank_receipt']['tmp_name'], $receipt_dir.$receipt_name)) {
                            $payment_ref = 'uploads/receipts/'.$receipt_name;
                        } else {
                            $error = 'فشل رفع الإيصال، يرجى المحاولة مرة أخرى';
                        }
                    }
                } elseif (empty($payment_ref)) {
                    $error = 'يرجى رفع إيصال التحويل البنكي';
                }
            } elseif (empty($payment_ref)) {
                $error = 'مرجع الدفع مطلوب للدفع الإلكتروني';
            }
            if (!$error) {
                $on=$conn->real_escape_string($office_name);
                $li=$conn->real_escape_string($license);
                $ow=$conn->real_escape_string($owner_name);
                $ci=$conn->real_escape_string($city);
                $ph=$conn->real_escape_string($phone);
                $em=$conn->real_escape_string($email);
                $pr=$conn->real_escape_string($payment_ref);
                $pm=$conn->real_escape_string($payment_method);
                $trial_row = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='trial_days' LIMIT 1");
                $trial_days = ($trial_row && $td = $trial_row->fetch_assoc()) ? max(1,(int)$td['setting_value']) : 14;

                $bc = $conn->real_escape_string($_POST['billing_cycle'] ?? 'monthly');
                $bc = in_array($bc, ['monthly','yearly']) ? $bc : 'monthly';

                // ── التأكد من وجود أعمدة الدفع في جدول offices (توافق مع قواعد بيانات قديمة) ──
                // يُنفَّذ خارج المعاملة لأن أوامر ALTER تُنهي المعاملة ضمنياً في MySQL
                $office_cols = [];
                try {
                    if ($cr = $conn->query("SHOW COLUMNS FROM offices")) {
                        while ($cc = $cr->fetch_assoc()) $office_cols[$cc['Field']] = true;
                    }
                    $col_ddl = [
                        'billing_cycle'  => "ADD COLUMN billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly'",
                        'payment_method' => "ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'bank'",
                        'payment_ref'    => "ADD COLUMN payment_ref VARCHAR(500) DEFAULT NULL",
                    ];
                    $to_add = [];
                    foreach ($col_ddl as $col => $ddl) {
                        if (empty($office_cols[$col])) $to_add[] = $ddl;
                    }
                    if ($to_add) {
                        $conn->query("ALTER TABLE offices " . implode(', ', $to_add));
                        $office_cols = [];
                        if ($cr = $conn->query("SHOW COLUMNS FROM offices")) {
                            while ($cc = $cr->fetch_assoc()) $office_cols[$cc['Field']] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    // لا صلاحية ALTER أو ما شابه — نكمل بالأعمدة الموجودة فقط
                    error_log("register.php offices schema check: " . $e->getMessage());
                }

                // Fix: Custom packages get NULL package_id, validate regular packages
                $post_pkg_id = null;
                if (!$is_custom && $pkg_id > 0) {
                    $pkg_check = $conn->query("SELECT id FROM packages WHERE id=$pkg_id AND is_active=1 LIMIT 1");
                    if (!$pkg_check || $pkg_check->num_rows === 0) {
                        $error = 'الباقة المختارة غير صالحة أو غير مفعلة';
                    } else {
                        $post_pkg_id = $pkg_id;
                    }
                }

                if (!$error) {
                    $conn->autocommit(false); // Start transaction
                    try {
                        // Dynamic INSERT — الأعمدة الأساسية دائماً، والاختيارية فقط إن وُجدت
                        $fields = ['name', 'license_number', 'owner_name', 'city', 'phone', 'email', 'status', 'subscription_end'];
                        $values = ["'$on'", "'$li'", "'$ow'", "'$ci'", "'$ph'", "'$em'", "'trial'", "DATE_ADD(CURDATE(),INTERVAL $trial_days DAY)"];

                        $optional = [
                            'billing_cycle'  => "'$bc'",
                            'payment_method' => "'$pm'",
                            'payment_ref'    => "'$pr'",
                        ];
                        foreach ($optional as $col => $val) {
                            if (!empty($office_cols[$col])) { $fields[] = $col; $values[] = $val; }
                        }

                        if ($post_pkg_id !== null) {
                            $fields[] = 'package_id';
                            $values[] = $post_pkg_id;
                        }

                        $fields_str = implode(',', $fields);
                        $values_str = implode(',', $values);

                        $result = $conn->query("INSERT INTO offices($fields_str) VALUES($values_str)");
                        if (!$result) {
                            throw new Exception("Office insert failed: " . $conn->error);
                        }
                        $office_id = $conn->insert_id;
                        if (!function_exists('affiliate_track_signup')) require_once __DIR__ . '/../includes/affiliate_helper.php';
                        affiliate_track_signup($conn, $office_id, $office_name, $affiliate_code);

                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $un=$conn->real_escape_string($username);
                        $fn=$conn->real_escape_string($owner_name);
                        $e2=$conn->real_escape_string($email);
                        $user_result = $conn->query("INSERT INTO users(username,password,role,office_id,full_name,email,phone,is_active)
                            VALUES('$un','$hash','office_owner',$office_id,'$fn','$e2','$ph',1)");
                        if (!$user_result) {
                            throw new Exception("User insert failed: " . $conn->error);
                        }

                        if ($is_custom) {
                            // إنشاء طلب باقة مخصصة تلقائياً
                            $feats_raw = $_POST['custom_features_raw'] ?? '';
                            $feat_arr  = array_filter(explode(',', $feats_raw));
                            $cf = []; foreach ($feat_arr as $fk) $cf[trim($fk)] = true;
                            $c_users   = max(1,(int)($_POST['custom_users']   ?? 3));
                            $c_cases   = max(1,(int)($_POST['custom_cases']   ?? 50));
                            $c_storage = max(0,(int)($_POST['custom_storage'] ?? 0));
                            $c_price   = max(0,(float)($_POST['custom_price'] ?? 0));
                            $cf_json = $conn->real_escape_string(json_encode($cf));
                            $cl_json = $conn->real_escape_string(json_encode(['users'=>$c_users,'cases'=>$c_cases,'storage_mb'=>$c_storage]));
                            $req_result = $conn->query("INSERT INTO package_requests (office_id,current_package_id,requested_package_id,reason,commitment,is_custom,custom_features,custom_limits,custom_price_monthly)
                                VALUES ($office_id,NULL,0,'طلب تسجيل بباقة مخصصة — مرجع الدفع: ".($pr?:'-')."',1,1,'$cf_json','$cl_json',$c_price)");
                            if (!$req_result) {
                                throw new Exception("Package request insert failed: " . $conn->error);
                            }
                            $pkg_label = 'مخصصة';
                            $c_price_fmt = number_format($c_price);
                            $conn->query("INSERT INTO notifications(title,message,type)
                                VALUES('طلب باقة مخصصة جديد','مكتب \"$on\" يطلب باقة مخصصة بسعر $c_price_fmt ر.س/شهر','warning')");
                        } else {
                            $pkg_label = $pkg['name'] ?? '';
                            $conn->query("INSERT INTO notifications(title,message,type)
                                VALUES('طلب تفعيل مكتب جديد','مكتب \"$on\" - باقة: $pkg_label - مرجع الدفع: $pr','warning')");
                        }

                        $conn->commit();
                        $success = true;

                    } catch (\Throwable $e) {
                        $conn->rollback();
                        error_log("Registration transaction failed: " . $e->getMessage() . " | SQL: " . $conn->error);
                        $error = 'حدث خطأ أثناء إنشاء الحساب. يرجى المحاولة لاحقاً.';
                    }
                    $conn->autocommit(true); // End transaction

                    // ── إرسال الإيميلات بعد نجاح التسجيل (لا يؤثر فشلها على إنشاء الحساب) ──
                    if ($success) {
                        try {
                            require_once '../includes/mailer.php';
                            $pkg_label_for_email = $is_custom ? 'مخصصة' : ($pkg['name'] ?? '');

                            if (sc($conn,'email_verify_enabled','0') === '1') {
                                // إنشاء جدول التحقق إن لم يكن موجوداً
                                $conn->query("CREATE TABLE IF NOT EXISTS email_verifications (
                                    id         INT AUTO_INCREMENT PRIMARY KEY,
                                    user_id    INT NOT NULL,
                                    token      VARCHAR(128) NOT NULL UNIQUE,
                                    expires_at DATETIME NOT NULL,
                                    used       TINYINT(1) DEFAULT 0,
                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                    INDEX (token), INDEX (user_id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                $vtok = bin2hex(random_bytes(32));
                                $vtok_e = $conn->real_escape_string($vtok);
                                $vuid = (int)($conn->query("SELECT id FROM users WHERE username='$un' LIMIT 1")->fetch_assoc()['id'] ?? 0);
                                if ($vuid) {
                                    $conn->query("INSERT INTO email_verifications (user_id,token,expires_at) VALUES ($vuid,'$vtok_e',DATE_ADD(NOW(),INTERVAL 24 HOUR))");
                                    $base_url = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/public/verify_email.php';
                                    sendVerifyEmail($conn, $email, $owner_name, "$base_url?token=$vtok");
                                }
                            }

                            sendWelcomeEmail($conn, $email, $owner_name, $office_name, $pkg_label_for_email);
                        } catch (\Throwable $e) {
                            error_log("Registration email sending failed (account still created): " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
}

$page_title = 'تسجيل مكتب جديد | '.sc($conn,'site_name','Mehkam');
$packages_q = $conn->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price_monthly ASC");
$all_pkgs   = [];
if ($packages_q) while($p=$packages_q->fetch_assoc()) $all_pkgs[] = $p;

// كشف وجود باقة مخصصة (سعرها 0) في القائمة
foreach ($all_pkgs as $_ap) { if ((float)$_ap['price_monthly'] == 0) { $has_custom_in_list = true; break; } }

include 'includes/header.php';
?>

<script>
// الـ nav يبقى دائماً داكناً في صفحة التسجيل
var _nav = document.getElementById('mainNav') || document.getElementById('siteNav');
if (_nav) _nav.classList.add('scrolled');
</script>

<style>
/* ============================================
   REGISTER PAGE STYLES — standalone, no conflicts
   ============================================ */

/* wrapper pushed below fixed navbar */
.rp-wrapper {
  padding-top: 0;
  background: #f0f4f8;
  min-height: 100vh;
}

/* ── PAGE HERO — يطابق باقي الصفحات ── */
.rp-hero {
  background: linear-gradient(135deg, #0a1628 0%, #1a3a6e 100%);
  padding: 90px 0 0;
  position: relative;
  overflow: hidden;
}
@media (max-width: 575.98px) {
  .rp-hero { padding: 76px 0 0; }
  .rp-wrapper { overflow-x: hidden; }
  .rp-steps-inner { padding: 16px 12px 12px; }
  .rp-step-label { font-size: 10px; }
}
.rp-hero::before {
  content: '';
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 50px 50px;
}

/* ── STEPS PROGRESS BAR ── */
.rp-steps-bar {
  background: rgba(255,255,255,.08);
  border-bottom: none;
  border-top: 1px solid rgba(255,255,255,.12);
}
.rp-steps-inner {
  max-width: 560px;
  margin: 0 auto;
  padding: 20px 16px 16px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  position: relative;
}
/* single continuous line underneath all circles */
.rp-steps-inner::before {
  content: '';
  position: absolute;
  top: 37px;
  right: calc(12.5% + 15px);
  left:  calc(12.5% + 15px);
  height: 2px;
  background: rgba(255,255,255,.15);
  z-index: 0;
}
.rp-step {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 1;
  gap: 8px;
}
.rp-step-circle {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 800;
  background: rgba(255,255,255,.15);
  color: rgba(255,255,255,.5);
  border: 2.5px solid rgba(255,255,255,.2);
  position: relative;
  z-index: 2;
  transition: all .25s;
}
.rp-step-label {
  font-size: 11px;
  font-weight: 600;
  color: rgba(255,255,255,.5);
  white-space: nowrap;
}
/* DONE */
.rp-step.done .rp-step-circle {
  background: #22c55e;
  border-color: #22c55e;
  color: #fff;
}
.rp-step.done .rp-step-label { color: #4ade80; }
/* ACTIVE */
.rp-step.active .rp-step-circle {
  background: #c9a227;
  border-color: #c9a227;
  color: #0a1628;
  box-shadow: 0 0 0 5px rgba(201,162,39,.25);
}
.rp-step.active .rp-step-label {
  color: #e8c040;
  font-weight: 800;
}

/* ── MAIN CONTENT AREA ── */
.rp-container {
  max-width: 960px;
  margin: 0 auto;
  padding: 32px 16px 80px;
}

/* ── FORM CARD ── */
.rp-card {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 2px 16px rgba(0,0,0,.07);
  overflow: hidden;
}
.rp-card-head {
  background: linear-gradient(135deg, #0a1628 0%, #1a3a6e 100%);
  padding: 24px 28px;
  color: #fff;
}
.rp-card-head h2 {
  font-size: 18px;
  font-weight: 800;
  margin: 0 0 4px;
}
.rp-card-head p {
  font-size: 13px;
  opacity: .6;
  margin: 0;
}
.rp-card-body {
  padding: 28px;
}

/* ── SECTION DIVIDER LABEL ── */
.rp-section {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 11px;
  font-weight: 800;
  color: #64748b;
  letter-spacing: .8px;
  text-transform: uppercase;
  margin: 28px 0 16px;
}
.rp-section:first-child { margin-top: 0; }
.rp-section::before {
  content: '';
  width: 4px;
  height: 14px;
  background: #c9a227;
  border-radius: 2px;
  flex-shrink: 0;
}
.rp-section::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #f0f0f0;
}

/* ── FORM FIELDS ── */
.rp-label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: #374151;
  margin-bottom: 6px;
}
.rp-input, .rp-select {
  width: 100%;
  padding: 11px 14px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: 14px;
  font-family: 'Tajawal', sans-serif;
  color: #111827;
  background: #fff;
  transition: border-color .2s, box-shadow .2s;
  box-sizing: border-box;
}
.rp-input:focus, .rp-select:focus {
  outline: none;
  border-color: #1a3a6e;
  box-shadow: 0 0 0 3px rgba(26,58,110,.1);
}
.rp-req { color: #ef4444; margin-right: 2px; }

/* ── PACKAGE SELECTOR ── */
.rp-pkg-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 4px;
}
.rp-pkg-opt {
  border: 2px solid #e2e8f0;
  border-radius: 12px;
  padding: 14px 10px;
  text-align: center;
  cursor: pointer;
  transition: all .2s;
  user-select: none;
}
.rp-pkg-opt:hover { border-color: #1a3a6e; background: #f8faff; }
.rp-pkg-opt.active {
  border-color: #c9a227;
  background: rgba(201,162,39,.06);
}
.rp-pkg-opt input[type="radio"] { display: none; }
.rp-pkg-name  { font-size: 13px; font-weight: 800; color: #0a1628; margin-bottom: 4px; }
.rp-pkg-price { font-size: 19px; font-weight: 900; color: #c9a227; }
.rp-pkg-price small { font-size: 10px; color: #9ca3af; font-weight: 400; }

/* ── PAYMENT BOX ── */
.rp-pay-box {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 12px;
  padding: 18px 20px;
  margin-bottom: 12px;
}
.rp-pay-box h6 {
  font-size: 13px;
  font-weight: 800;
  color: #92400e;
  margin: 0 0 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.rp-bank-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 9px 0;
  border-bottom: 1px solid rgba(253,230,138,.6);
  font-size: 13px;
}
.rp-bank-row:last-child { border: none; }
.rp-bank-lbl { color: #78350f; font-weight: 600; }
.rp-bank-val { color: #0a1628; font-weight: 700; }

/* ── SUBMIT BUTTON ── */
.rp-btn-submit {
  width: 100%;
  padding: 15px;
  border: none;
  border-radius: 12px;
  background: linear-gradient(135deg, #0a1628, #1a3a6e);
  color: #fff;
  font-size: 16px;
  font-weight: 800;
  font-family: 'Tajawal', sans-serif;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  transition: all .2s;
}
.rp-btn-submit:hover {
  opacity: .92;
  transform: translateY(-2px);
  box-shadow: 0 8px 28px rgba(10,22,40,.35);
}
.rp-btn-submit:active { transform: translateY(0); }

/* ── SUB STEP TABS ── */
#subStepsHeader {
  background: #f8fafc;
}
#subTabInfo, #subTabPay {
  transition: all .2s;
  cursor: default;
}
#subTabInfo span, #subTabPay span {
  transition: all .2s;
}

/* زر "التالي للدفع" */
#btnNextToPay:hover {
  opacity: .9;
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(10,22,40,.25);
}

/* ── SUMMARY SIDEBAR ── */
.rp-sum-card {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 2px 16px rgba(0,0,0,.07);
  overflow: hidden;
  position: sticky;
  top: 88px;
}
.rp-sum-head {
  background: linear-gradient(135deg, #c9a227, #e8c040);
  padding: 20px 20px 16px;
  text-align: center;
}
.rp-sum-head .slbl { font-size: 11px; font-weight: 700; color: rgba(10,22,40,.6); margin-bottom: 4px; }
.rp-sum-head .sname { font-size: 20px; font-weight: 900; color: #0a1628; }
.rp-sum-body { padding: 18px 20px; }
.rp-sum-price { text-align: center; padding-bottom: 16px; border-bottom: 1px solid #f0f0f0; margin-bottom: 16px; }
.rp-sum-price .sp-amount { font-size: 36px; font-weight: 900; color: #0a1628; line-height: 1; }
.rp-sum-price .sp-unit   { font-size: 12px; color: #9ca3af; margin-top: 4px; }
.rp-sum-price .sp-yearly { font-size: 12px; color: #16a34a; font-weight: 600; margin-top: 4px; }
.rp-sum-feats { list-style: none; padding: 0; margin: 0 0 14px; }
.rp-sum-feats li {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 7px 0;
  border-bottom: 1px solid #f5f5f5;
  font-size: 13px;
  color: #374151;
}
.rp-sum-feats li:last-child { border: none; }
.rp-sum-feats li i { color: #22c55e; font-size: 11px; margin-top: 3px; flex-shrink: 0; }
.rp-sum-guarantee {
  background: #f0fdf4;
  border-radius: 10px;
  padding: 11px 14px;
  font-size: 12px;
  color: #16a34a;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── ERROR BANNER ── */
.rp-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 10px;
  padding: 13px 16px;
  color: #dc2626;
  font-size: 13px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
}

/* ── SUCCESS ── */
.rp-success {
  text-align: center;
  padding: 60px 28px;
}
.rp-success .si { width:76px; height:76px; border-radius:50%; background:#d1fae5; color:#059669; font-size:32px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; }
.rp-success h2  { font-size:22px; font-weight:900; color:#0a1628; margin-bottom:10px; }
.rp-success p   { font-size:14px; color:#6b7280; max-width:440px; margin:0 auto 24px; line-height:1.8; }
.rp-success-info { background:#f8fafc; border-radius:12px; padding:16px 20px; max-width:340px; margin:0 auto 24px; }
.rp-success-row  { display:flex; justify-content:space-between; font-size:13px; padding:5px 0; border-bottom:1px solid #f0f0f0; }
.rp-success-row:last-child { border:none; }
.rp-btn-back {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: linear-gradient(135deg,#0a1628,#1a3a6e);
  color: #fff;
  padding: 13px 28px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 700;
  text-decoration: none;
}

/* ══ FEATURE SELECTOR ══ */
.fs-wrap {
  max-width: 900px; margin: 0 auto;
}
.fs-card {
  background: #fff; border-radius: 20px;
  box-shadow: 0 4px 24px rgba(10,22,40,.08);
  overflow: hidden;
}
.fs-head {
  padding: 28px 32px 24px;
  border-bottom: 1px solid #f1f5f9;
}
.fs-head h2 {
  font-size: 20px; font-weight: 900; color: #0a1628; margin: 0 0 4px;
}
.fs-head p { font-size: 13px; color: #94a3b8; margin: 0; }
.fs-section-label {
  font-size: 11px; font-weight: 800; color: #94a3b8;
  text-transform: uppercase; letter-spacing: .6px;
  margin-bottom: 14px;
}
.fs-body { padding: 28px 32px; }

/* بطاقات الميزات */
.feat-card {
  display: block; cursor: pointer; border-radius: 14px;
  border: 2px solid #f1f5f9; background: #f8fafc;
  transition: all .18s; user-select: none; height: 100%;
}
.feat-card:hover {
  border-color: #c7d7ee; background: #fff;
  box-shadow: 0 2px 10px rgba(10,22,40,.07);
  transform: translateY(-1px);
}
.feat-card.selected {
  border-color: #1a3a6e; background: #fff;
  box-shadow: 0 4px 16px rgba(26,58,110,.14);
}
.feat-card-inner {
  display: flex; flex-direction: column; align-items: center;
  gap: 10px; padding: 20px 14px; text-align: center; position: relative;
}
.feat-check-badge {
  position: absolute; top: 10px; left: 10px;
  width: 20px; height: 20px; border-radius: 50%;
  border: 2px solid #e2e8f0; background: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 9px; color: transparent;
  transition: all .18s;
}
.feat-card.selected .feat-check-badge {
  background: #1a3a6e; border-color: #1a3a6e; color: #fff;
}
.feat-icon {
  width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
  background: #eef2f8; display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: #94a3b8; transition: all .18s;
}
.feat-card.selected .feat-icon { background: #0a1628; color: #c9a227; }
.feat-name { font-size: 13px; font-weight: 800; color: #1e293b; line-height: 1.3; }
.feat-price {
  font-size: 11px; color: #94a3b8; font-weight: 600;
  background: #f1f5f9; border-radius: 50px; padding: 2px 10px;
  margin-top: 2px;
}
.feat-card.selected .feat-price { background: #eff6ff; color: #2563eb; }
.feat-price-free { background: #f0fdf4 !important; color: #16a34a !important; }

/* عدادات الحدود */
.qty-row {
  background: #fff; border: 1.5px solid #edf0f7;
  border-radius: 20px; padding: 22px 16px 18px;
  text-align: center; transition: all .2s;
  box-shadow: 0 2px 12px rgba(10,22,40,.05);
  display: flex; flex-direction: column; align-items: center;
}
.qty-row:hover { border-color: #1a3a6e; box-shadow: 0 6px 20px rgba(26,58,110,.12); }
.qty-icon {
  width: 48px; height: 48px; border-radius: 14px; margin-bottom: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 18px;
  flex-shrink: 0;
}
.qty-icon-users  { background: linear-gradient(135deg,#dbeafe,#eff6ff); color: #2563eb; }
.qty-icon-cases  { background: linear-gradient(135deg,#fde68a,#fef3c7); color: #d97706; }
.qty-icon-storage{ background: linear-gradient(135deg,#bbf7d0,#f0fdf4); color: #16a34a; }
.qty-label {
  font-size: 12px; font-weight: 700; color: #374151;
  margin-bottom: 16px; text-align: center;
}
.qty-controls {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  width: 100%;
}
.qty-btn {
  width: 38px; height: 38px; border-radius: 12px;
  border: 1.5px solid #e2e8f0; background: #f1f5f9;
  font-size: 20px; font-weight: 600; color: #1a3a6e;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: all .15s; flex-shrink: 0; line-height: 1; user-select: none;
}
.qty-btn:hover { background: #1a3a6e; color: #fff; border-color: #1a3a6e; box-shadow: 0 3px 10px rgba(26,58,110,.25); }
.qty-btn:active { transform: scale(.88); }
.qty-input {
  width: 60px; text-align: center; border: none;
  font-size: 24px; font-weight: 900; color: #0a1628;
  background: transparent; font-family: 'Tajawal', sans-serif; padding: 0;
}
.qty-input:focus { outline: none; }
.qty-note {
  font-size: 10px; color: #94a3b8; margin-top: 14px;
  background: #f8fafc; border: 1px solid #f1f5f9;
  border-radius: 20px; padding: 3px 10px; display: inline-block;
  white-space: nowrap;
}

/* اختيار الباقة داخل خطوة الميزات */
.fs-pkg-grid { display: flex; gap: 12px; flex-wrap: wrap; }
.fs-pkg-opt {
  flex: 1; min-width: 140px; cursor: pointer;
  border: 2px solid #e8edf5; border-radius: 14px;
  padding: 14px 18px; text-align: center; transition: all .2s;
  background: #fff; user-select: none;
}
.fs-pkg-opt:hover { border-color: #1a3a6e; background: #f8fafc; }
.fs-pkg-opt.active { border-color: #1a3a6e; background: #eff6ff; }
.fs-pkg-opt input { display: none; }
.fs-pkg-opt-name { font-size: 13px; font-weight: 800; color: #0a1628; margin-bottom: 4px; }
.fs-pkg-opt-price { font-size: 18px; font-weight: 900; color: #c9a227; line-height: 1; }
.fs-pkg-opt-price small { font-size: 10px; color: #9ca3af; font-weight: 400; }
.fs-pkg-opt.active .fs-pkg-opt-price { color: #1a3a6e; }
.fs-pkg-opt-custom { font-size: 12px; color: #7c3aed; font-weight: 700; }
.fs-billing-toggle { display: flex; gap: 6px; }
.fs-bill-btn {
  flex: 1; padding: 7px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0;
  background: #f8fafc; font-size: 12px; font-weight: 700; color: #6b7280;
  cursor: pointer; font-family: 'Tajawal', sans-serif; transition: all .15s;
}
.fs-bill-btn.on { background: #0a1628; color: #fff; border-color: #0a1628; }

/* ملخص السعر */
.fs-summary {
  background: linear-gradient(135deg, #0a1628 0%, #1a3a6e 100%);
  border-radius: 16px; padding: 22px 26px;
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 16px; margin-top: 28px;
}
.fs-price-block .fs-price-label {
  font-size: 11px; color: rgba(255,255,255,.5); margin-bottom: 4px; font-weight: 600;
}
.fs-price-num {
  font-size: 42px; font-weight: 900; color: #c9a227; line-height: 1;
}
.fs-price-currency { font-size: 14px; color: rgba(255,255,255,.45); margin-right: 4px; }
.fs-price-note { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 6px; }
.fs-feats-tags {
  display: flex; flex-wrap: wrap; gap: 6px; max-width: 340px;
}
.fs-tag {
  background: rgba(255,255,255,.12); color: #fff;
  padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 600;
  border: 1px solid rgba(255,255,255,.1);
}
.fs-tag-empty { color: rgba(255,255,255,.25); font-size: 12px; }

/* زر التالي */
.fs-next-btn {
  display: inline-flex; align-items: center; gap: 10px;
  background: linear-gradient(135deg, #c9a227, #e8c040);
  color: #0a1628; font-size: 15px; font-weight: 800;
  padding: 14px 32px; border-radius: 12px; border: none;
  cursor: pointer; font-family: 'Tajawal', sans-serif;
  transition: all .2s; box-shadow: 0 4px 16px rgba(201,162,39,.3);
}
.fs-next-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(201,162,39,.4);
}
.fs-footer {
  padding: 20px 32px; border-top: 1px solid #f1f5f9;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.fs-footer-note { font-size: 12px; color: #94a3b8; }
@media (max-width:575px) {
  .fs-body { padding: 20px 16px; }
  .fs-head { padding: 20px 16px 16px; }
  .fs-footer { padding: 16px; }
  .feat-card-inner { padding: 16px 10px; }
  .fs-next-btn { width: 100%; justify-content: center; }
}

/* ── BILLING TOGGLE ── */
.rp-billing-wrap {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
  background: #f1f5f9;
  border-radius: 12px;
  padding: 5px;
  width: fit-content;
}
.rp-billing-btn {
  padding: 9px 22px;
  border-radius: 9px;
  border: none;
  font-size: 13px;
  font-weight: 700;
  font-family: 'Tajawal', sans-serif;
  cursor: pointer;
  color: #64748b;
  background: transparent;
  transition: all .2s;
  display: flex;
  align-items: center;
  gap: 6px;
  position: relative;
}
.rp-billing-btn.on {
  background: #fff;
  color: #0a1628;
  box-shadow: 0 2px 8px rgba(0,0,0,.1);
}
.rp-save-badge {
  background: linear-gradient(135deg,#16a34a,#22c55e);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 50px;
}

/* ── أزرار طريقة الدفع ── */
.rp-pm-btn {
  padding: 9px 18px; border-radius: 10px; border: 2px solid #e5e7eb;
  font-size: 13px; font-weight: 700; font-family: 'Tajawal', sans-serif;
  cursor: pointer; color: #64748b; background: #fff; transition: all .15s;
}
.rp-pm-btn:hover  { border-color: #1a3a6e; color: #1a3a6e; }
.rp-pm-btn.active { border-color: #1a3a6e; background: #1a3a6e; color: #fff; }

/* ── RESPONSIVE ── */
@media (max-width: 600px) {
  .rp-pkg-grid { grid-template-columns: 1fr; }
  .rp-card-body { padding: 20px 16px; }
}
@media (max-width: 768px) {
  .rp-sum-card { position: static; }
}
</style>

<div class="rp-wrapper">

  <!-- ═══ PAGE HERO ═══ -->
  <div class="rp-hero">
    <div class="container position-relative" style="z-index:1">
      <div class="text-center pb-0">
        <div class="section-label" style="justify-content:center;color:#c9a227">التسجيل</div>
        <h1 class="section-title" style="color:#fff;margin-bottom:8px">
          أنشئ حساب مكتبك <span class="hl">مجاناً</span>
        </h1>
        <p class="section-desc mx-auto" style="color:rgba(255,255,255,.65)">
          14 يوماً تجريبياً — لا حاجة لبطاقة ائتمان
        </p>
      </div>
    </div>

    <!-- ═══ STEPS BAR ═══ -->
    <div class="rp-steps-bar" style="margin-top:28px">
      <div class="rp-steps-inner" id="stepsBar">

        <!-- Step 1: اختيار الباقة — دائماً مكتمل -->
        <div class="rp-step done" id="stepBarPkg">
          <div class="rp-step-circle"><i class="fas fa-check" style="font-size:11px"></i></div>
          <div class="rp-step-label">الباقة</div>
        </div>

        <?php if ($is_custom || $has_custom_in_list): ?>
        <!-- Step 2: الميزات — للمخصصة فقط -->
        <div class="rp-step <?= $is_custom ? ($features_already_set ? 'done' : 'active') : '' ?>"
             id="stepBarFeats" <?= (!$is_custom) ? 'style="display:none"' : '' ?>>
          <div class="rp-step-circle" id="stepBarFeatsCircle">
            <?= ($is_custom && $features_already_set) ? '<i class="fas fa-check" style="font-size:11px"></i>' : '2' ?>
          </div>
          <div class="rp-step-label">الميزات</div>
        </div>
        <?php endif; ?>

        <!-- Step 3: بيانات المكتب -->
        <?php
        $infoActive = ($is_custom && $features_already_set) || !$is_custom;
        $infoNum = ($is_custom || $has_custom_in_list) ? 3 : 2;
        ?>
        <div class="rp-step <?= $infoActive && !$success ? 'active' : ($success ? 'done' : '') ?>" id="stepBarInfo">
          <div class="rp-step-circle" id="stepBarInfoCircle">
            <?= $success ? '<i class="fas fa-check" style="font-size:11px"></i>' : $infoNum ?>
          </div>
          <div class="rp-step-label">التسجيل</div>
        </div>

        <!-- Step 4: الدفع -->
        <?php $payNum = $infoNum + 1; ?>
        <div class="rp-step <?= $success ? 'done' : '' ?>" id="stepBarPay">
          <div class="rp-step-circle" id="stepBarPayCircle">
            <?= $success ? '<i class="fas fa-check" style="font-size:11px"></i>' : $payNum ?>
          </div>
          <div class="rp-step-label">الدفع</div>
        </div>

        <!-- Step 5: التأكيد -->
        <div class="rp-step <?= $success ? 'active' : '' ?>" id="stepBarConfirm">
          <div class="rp-step-circle"><?= $success ? '<i class="fas fa-check" style="font-size:11px"></i>' : ($payNum+1) ?></div>
          <div class="rp-step-label">التأكيد</div>
        </div>

      </div>
    </div>
  </div>

  <!-- ═══════════ MAIN ═══════════ -->
  <div class="rp-container">

    <?php $show_feat_step = !$success && ($is_custom || $has_custom_in_list); ?>
    <?php if ($show_feat_step): ?>
    <!-- ═══ STEP 1: اختيار الميزات ═══ -->
    <div id="featStep" <?= (!$is_custom || $features_already_set) ? 'style="display:none"' : '' ?>>
    <div class="fs-wrap">
    <div class="fs-card">

      <!-- رأس البطاقة -->
      <div class="fs-head">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,#0a1628,#1a3a6e);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-sliders" style="color:#c9a227;font-size:20px"></i>
          </div>
          <div>
            <h2>خصّص باقتك</h2>
            <p>اختر الميزات التي تحتاجها — سيتواصل فريقنا لتأكيد السعر النهائي</p>
          </div>
        </div>
      </div>

      <div class="fs-body">

        <!-- اختيار الباقة -->
        <div class="fs-section-label"><i class="fas fa-boxes-stacked me-2"></i>اختر الباقة</div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:6px">
          <div class="fs-pkg-grid" id="fsPkgGrid">
            <?php foreach ($all_pkgs as $po):
              $po_price = (float)$po['price_monthly'];
              $is_po_custom = $po_price == 0;
            ?>
            <label class="fs-pkg-opt <?= $po['id'] == $pkg_id ? 'active' : '' ?>" id="fsPkgOpt<?= $po['id'] ?>"
                   onclick="fsPkgSelect(<?= $po['id'] ?>, <?= $is_po_custom ? 'true' : 'false' ?>)">
              <input type="radio" name="_fspkg" value="<?= $po['id'] ?>"
                     <?= $po['id'] == $pkg_id ? 'checked' : '' ?>>
              <div class="fs-pkg-opt-name"><?= htmlspecialchars($po['name']) ?></div>
              <?php if ($is_po_custom): ?>
              <div class="fs-pkg-opt-custom"><i class="fas fa-handshake me-1"></i>بالتفاهم</div>
              <?php else: ?>
              <div class="fs-pkg-opt-price" id="fsPkgPrice<?= $po['id'] ?>">
                <?= number_format($po['price_monthly']) ?><small> ر.س/شهر</small>
              </div>
              <?php endif; ?>
            </label>
            <?php endforeach; ?>
          </div>
          <!-- دورة الفوترة -->
          <div class="fs-billing-toggle" id="fsBillingWrap">
            <button type="button" class="fs-bill-btn <?= $billing_cycle==='monthly'?'on':'' ?>" id="fsBillMonthly"
                    onclick="fsBilling('monthly')">شهري</button>
            <button type="button" class="fs-bill-btn <?= $billing_cycle==='yearly'?'on':'' ?>" id="fsBillYearly"
                    onclick="fsBilling('yearly')">
              سنوي <span style="font-size:9px;color:#16a34a;font-weight:800">وفّر</span>
            </button>
          </div>
        </div>

        <!-- شبكة الميزات (تظهر فقط للباقة المخصصة) -->
        <div id="fsCustomSection" <?= !$is_custom ? 'style="display:none"' : '' ?>>
        <div class="fs-section-label" style="margin-top:24px"><i class="fas fa-th me-2"></i>الميزات المتاحة — انقر لتفعيل أو إلغاء</div>
        <div class="row g-3" id="featGrid">
          <?php
          $feat_icons_builder = ['coins','file-invoice-dollar','file-signature','stamp','envelope-open-text','book-open','box-archive','robot','chart-bar','code','scale-balanced','hand-holding-dollar'];
          $mod_head_done = false;
          foreach ($feat_keys_builder as $fi => $fk):
            if (strpos($fk, 'mod_') === 0 && !$mod_head_done) {
                $mod_head_done = true;
                echo '<div class="col-12"><div class="fs-section-label" style="margin:14px 0 0"><i class="fas fa-puzzle-piece me-2"></i>موديولات إضافية</div></div>';
            }
            $fdata   = $fp[$fk] ?? null;
            $price_m = $fdata ? (float)$fdata['price_monthly'] : 0;
            $price_y = $fdata ? (float)$fdata['price_yearly']  : 0;
            // السعر السنوي per-month (للعرض)
            $price_y_pm = $price_y > 0 ? round($price_y / 12, 2) : 0;
            $label   = $feat_label_map[$fk] ?? $fk;
            $icon    = $feat_icons_builder[$fi] ?? ($fdata['feature_icon'] ?? 'puzzle-piece');
          ?>
          <div class="col-6 col-md-4 col-lg-3">
            <label class="feat-card" id="fc_<?= $fk ?>"
                   data-key="<?= $fk ?>"
                   data-price="<?= $price_m ?>"
                   data-price-yearly="<?= $price_y ?>"
                   onclick="toggleFeat(this)">
              <div class="feat-card-inner">
                <div class="feat-check-badge"><i class="fas fa-check"></i></div>
                <div class="feat-icon"><i class="fas fa-<?= $icon ?>"></i></div>
                <div class="feat-name"><?= $label ?></div>
                <div class="feat-price <?= $price_m == 0 ? 'feat-price-free' : '' ?>" id="fcp_<?= $fk ?>">
                  <?= $price_m > 0 ? '+'.number_format($price_m,0).' ر.س' : 'مجاناً' ?>
                </div>
              </div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- حدود الاستخدام -->
        <div class="fs-section-label" style="margin-top:28px;padding-top:24px;border-top:1px solid #f1f5f9">
          <i class="fas fa-gauge-high me-2"></i>حدود الاستخدام
        </div>
        <div class="row g-3">

          <div class="col-md-6">
            <div class="qty-row">
              <div class="qty-icon qty-icon-users"><i class="fas fa-users"></i></div>
              <div class="qty-label">عدد المستخدمين</div>
              <div class="qty-controls">
                <button type="button" class="qty-btn" onclick="adjQty('Users',-1)">−</button>
                <input type="number" id="qtyUsers" class="qty-input" value="<?= $custom_users ?>" min="1" max="50" readonly>
                <button type="button" class="qty-btn" onclick="adjQty('Users',1)">+</button>
              </div>
              <?php $pu = (float)($fp['per_user']['price_monthly'] ?? 0); ?>
              <div class="qty-note"><?= $pu > 0 ? "+{$pu} ر.س / مستخدم" : 'مجاناً' ?></div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="qty-row">
              <div class="qty-icon qty-icon-cases"><i class="fas fa-gavel"></i></div>
              <div class="qty-label">عدد القضايا</div>
              <div class="qty-controls">
                <button type="button" class="qty-btn" onclick="adjQty('Cases',-50)">−</button>
                <input type="number" id="qtyCases" class="qty-input" value="<?= $custom_cases ?>" min="<?= $fp_base_c ?>" step="50" readonly>
                <button type="button" class="qty-btn" onclick="adjQty('Cases',50)">+</button>
              </div>
              <?php $pc = (float)($fp['per_100_cases']['price_monthly'] ?? 0); ?>
              <div class="qty-note"><?= $pc > 0 ? "+{$pc} ر.س / كل 100" : 'مجاناً' ?></div>
            </div>
          </div>

        </div>

        <!-- ملخص السعر (للباقة المخصصة) -->
        <div class="fs-summary" style="margin-top:24px">
          <div class="fs-price-block">
            <div class="fs-price-label">السعر التقديري</div>
            <div style="display:flex;align-items:baseline;gap:6px">
              <span class="fs-price-num" id="totalPriceVal"><?= number_format($fp_base_p,0) ?></span>
              <span class="fs-price-currency">ر.س / شهر</span>
            </div>
            <div id="totalPriceYearly" style="font-size:11px;color:rgba(255,255,255,.35);margin-top:4px">
              أو 0 ر.س / سنوياً (وفّر 17%)
            </div>
            <div class="fs-price-note" style="margin-top:6px">* السعر النهائي يُحدده الفريق بعد المراجعة</div>
          </div>
          <div class="fs-feats-tags" id="selectedFeatsDisplay">
            <span class="fs-tag-empty">لم يُختر أي ميزة بعد</span>
          </div>
        </div>

        </div><!-- /fsCustomSection -->

        <!-- ملخص الباقة العادية -->
        <div id="fsRegularSummary" class="fs-summary" style="margin-top:24px;<?= $is_custom ? 'display:none' : '' ?>">
          <div class="fs-price-block">
            <div class="fs-price-label">سعر الباقة</div>
            <div style="display:flex;align-items:baseline;gap:8px">
              <span class="fs-price-num" id="fsRegPrice">
                <?php if (!$is_custom && $pkg): ?>
                  <?= $billing_cycle==='yearly' ? number_format(round($pkg['price_yearly']/12)) : number_format($pkg['price_monthly']) ?>
                <?php else: ?>0<?php endif; ?>
              </span>
              <span class="fs-price-currency">ر.س / شهر</span>
            </div>
            <div class="fs-price-note" id="fsRegNote">
              <?= (!$is_custom && $pkg && $billing_cycle==='yearly') ? 'إجمالي سنوي: '.number_format($pkg['price_yearly']).' ر.س' : '' ?>
            </div>
          </div>
          <div>
            <div style="color:rgba(255,255,255,.6);font-size:12px;margin-bottom:8px">ما يشمله الاشتراك</div>
            <div class="fs-feats-tags" id="fsRegFeats" style="max-width:280px"></div>
          </div>
        </div>

      </div><!-- /fs-body -->

      <!-- تذييل -->
      <div class="fs-footer">
        <div class="fs-footer-note">
          <i class="fas fa-info-circle me-1"></i>
          يمكنك تعديل الميزات لاحقاً بعد التواصل مع فريقنا
        </div>
        <button type="button" class="fs-next-btn" onclick="proceedToForm()">
          التالي — بيانات المكتب
          <i class="fas fa-arrow-left"></i>
        </button>
      </div>

    </div><!-- /fs-card -->
    </div><!-- /fs-wrap -->
    </div><!-- /featStep -->
    <?php endif; ?>

    <?php if ($success): ?>
    <!-- ───── SUCCESS ───── -->
    <div class="rp-card">
      <div class="rp-success">
        <div class="si"><i class="fas fa-check"></i></div>
        <h2>تم استلام طلبك بنجاح!</h2>
        <p>شكراً لاختيارك منصة مِحكام. تم إنشاء حسابك وهو في انتظار مراجعة وتأكيد الدفع من فريقنا. سيتم تفعيل حسابك وإشعارك خلال <strong>24–48 ساعة</strong>.</p>
        <div class="rp-success-info">
          <div class="rp-success-row">
            <span style="color:#9ca3af">الباقة</span>
            <strong><?= $is_custom ? 'مخصصة — '.number_format($custom_price,0).' ر.س/شهر' : htmlspecialchars($pkg['name'] ?? '') ?></strong>
          </div>
          <?php if ($is_custom): ?>
          <div class="rp-success-row"><span style="color:#9ca3af">الميزات المختارة</span><span><?= count(array_filter(explode(',',$custom_features_raw))) ?> ميزة</span></div>
          <?php endif; ?>
          <div class="rp-success-row"><span style="color:#9ca3af">الحالة</span><span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:2px 10px;border-radius:50px">في انتظار التفعيل</span></div>
        </div>
        <a href="../index.php" class="rp-btn-back"><i class="fas fa-sign-in-alt"></i>الذهاب لصفحة الدخول</a>
      </div>
    </div>

    <?php else: ?>
    <!-- ───── FORM ───── -->
    <div class="row g-4" id="mainFormWrap" <?= ($is_custom && !$features_already_set) ? 'style="display:none"' : '' ?>>

      <!-- MAIN FORM -->
      <div class="col-lg-8">
        <div class="rp-card">
          <!-- مؤشر الخطوة الفرعية -->
          <div style="display:flex;border-bottom:1px solid #f1f5f9" id="subStepsHeader">
            <div style="flex:1;padding:14px 20px;font-size:13px;font-weight:700;color:#1a3a6e;border-bottom:2px solid #1a3a6e;display:flex;align-items:center;gap:8px" id="subTabInfo">
              <span style="width:24px;height:24px;border-radius:50%;background:#1a3a6e;color:#fff;font-size:11px;font-weight:800;display:inline-flex;align-items:center;justify-content:center">✓</span>
              بيانات المكتب
            </div>
            <div style="flex:1;padding:14px 20px;font-size:13px;font-weight:600;color:#94a3b8;display:flex;align-items:center;gap:8px" id="subTabPay">
              <span style="width:24px;height:24px;border-radius:50%;background:#e2e8f0;color:#94a3b8;font-size:11px;font-weight:800;display:inline-flex;align-items:center;justify-content:center" id="subTabPayCircle"><?= ($is_custom || $has_custom_in_list) ? '4' : '3' ?></span>
              الدفع
            </div>
          </div>
          <div class="rp-card-head" id="officeStepHead">
            <h2><i class="fas fa-building me-2"></i>بيانات مكتب المحاماة</h2>
            <p>أدخل معلومات مكتبك — ستستخدمها للدخول لاحقاً</p>
          </div>
          <div class="rp-card-body">

            <?php if ($error): ?>
            <div class="rp-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="regForm" autocomplete="off" novalidate enctype="multipart/form-data">
            <!-- ══ الخطوة الفرعية: التسجيل ══ -->
            <div id="officeSubStep">
              <input type="hidden" name="package_id" id="pkgIdField" value="<?= $pkg_id ?>">
              <input type="hidden" name="billing_cycle" id="billingCycleField" value="<?= $billing_cycle ?>">
              <input type="hidden" name="is_custom" id="hidIsCustom" value="<?= $is_custom ? '1' : '0' ?>">
              <input type="hidden" name="custom_features_raw" id="hidCustomFeat"    value="<?= htmlspecialchars($custom_features_raw) ?>">
              <input type="hidden" name="custom_users"        id="hidCustomUsers"   value="<?= $custom_users ?>">
              <input type="hidden" name="custom_cases"        id="hidCustomCases"   value="<?= $custom_cases ?>">
              <input type="hidden" name="custom_storage"      id="hidCustomStorage" value="<?= $custom_storage ?>">
              <input type="hidden" name="custom_price"        id="hidCustomPrice"   value="<?= $custom_price ?>">


              <?php if (!$is_custom): ?>
              <!-- ① الباقة -->
              <div class="rp-section">اختيار الباقة والدورة</div>

              <!-- Billing Toggle -->
              <div class="rp-billing-wrap">
                <button type="button" class="rp-billing-btn <?= $billing_cycle==='monthly'?'on':'' ?>" id="btnMonthly" onclick="setBilling('monthly')">
                  <i class="fas fa-calendar-day me-1"></i>شهري
                </button>
                <button type="button" class="rp-billing-btn <?= $billing_cycle==='yearly'?'on':'' ?>" id="btnYearly" onclick="setBilling('yearly')">
                  <i class="fas fa-calendar-alt me-1"></i>سنوي
                  <span class="rp-save-badge">وفّر 17%</span>
                </button>
              </div>

              <div class="rp-pkg-grid">
                <?php foreach ($all_pkgs as $po): ?>
                <label class="rp-pkg-opt <?= $po['id']==$pkg_id?'active':'' ?>" id="pkgOpt<?= $po['id'] ?>">
                  <input type="radio" name="_pkg" value="<?= $po['id'] ?>"
                         <?= $po['id']==$pkg_id?'checked':'' ?>
                         onchange="selectPkg(<?= $po['id'] ?>)">
                  <div class="rp-pkg-name"><?= htmlspecialchars($po['name']) ?></div>
                  <div class="rp-pkg-price" id="pkgPrice<?= $po['id'] ?>">
                    <?= $billing_cycle==='yearly' ? number_format(round($po['price_yearly']/12)) : number_format($po['price_monthly']) ?>
                    <small>ر.س/شهر</small>
                  </div>
                  <div class="rp-pkg-billing-note" id="pkgNote<?= $po['id'] ?>" style="font-size:10px;color:#9ca3af;margin-top:2px">
                    <?= $billing_cycle==='yearly' ? '('.number_format($po['price_yearly']).' ر.س سنوياً)' : '' ?>
                  </div>
                </label>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- ② بيانات المكتب -->
              <div class="rp-section">بيانات المكتب</div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="rp-label"><span class="rp-req">*</span>اسم المكتب</label>
                  <input type="text" name="office_name" class="rp-input" required
                         autocomplete="off" placeholder="مكتب ... للمحاماة"
                         value="<?= htmlspecialchars($_POST['office_name']??'') ?>">
                </div>
                <div class="col-md-6">
                  <label class="rp-label">رقم الترخيص</label>
                  <input type="text" name="license_number" class="rp-input"
                         autocomplete="off" placeholder="LIC-XXXX-XXX"
                         value="<?= htmlspecialchars($_POST['license_number']??'') ?>">
                </div>
                <div class="col-md-6">
                  <label class="rp-label"><span class="rp-req">*</span>اسم مالك المكتب</label>
                  <input type="text" name="owner_name" class="rp-input" required
                         autocomplete="off"
                         value="<?= htmlspecialchars($_POST['owner_name']??'') ?>">
                </div>
                <div class="col-md-6">
                  <label class="rp-label"><span class="rp-req">*</span>المدينة</label>
                  <select name="city" class="rp-select">
                    <?php foreach(['الرياض','جدة','مكة المكرمة','المدينة المنورة','الدمام','الخبر','الأحساء','تبوك','أبها','القصيم','حائل','نجران','جازان'] as $c): ?>
                    <option <?= ($_POST['city']??'')===$c?'selected':'' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="rp-label"><span class="rp-req">*</span>رقم الجوال</label>
                  <input type="tel" name="phone" class="rp-input" required
                         autocomplete="off" placeholder="05XXXXXXXX"
                         value="<?= htmlspecialchars($_POST['phone']??'') ?>">
                </div>
                <div class="col-md-6">
                  <label class="rp-label"><span class="rp-req">*</span>البريد الإلكتروني</label>
                  <input type="email" name="email" class="rp-input" required
                         autocomplete="off"
                         value="<?= htmlspecialchars($_POST['email']??'') ?>">
                </div>
                <div class="col-md-6">
                  <label class="rp-label">كود الأفلييت <small class="text-muted fw-normal">(اختياري)</small></label>
                  <input type="text" name="affiliate_code" class="rp-input"
                         autocomplete="off" placeholder="كود من أحالك للتسجيل"
                         value="<?= htmlspecialchars($_POST['affiliate_code'] ?? $_GET['ref'] ?? $_COOKIE['mk_aff_ref'] ?? '') ?>">
                </div>
              </div>

              <!-- ③ بيانات الدخول -->
              <div class="rp-section">بيانات حساب الدخول</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="rp-label"><span class="rp-req">*</span>اسم المستخدم</label>
                  <input type="text" name="username" class="rp-input" required
                         autocomplete="new-password"
                         value="<?= htmlspecialchars($_POST['username']??'') ?>">
                </div>
                <div class="col-md-4">
                  <label class="rp-label"><span class="rp-req">*</span>كلمة المرور</label>
                  <div style="position:relative">
                    <input type="password" name="password" id="reg_password" class="rp-input" required
                           autocomplete="new-password" placeholder="أدخل كلمة المرور" oninput="checkPwdRules()">
                    <button type="button" onclick="togglePwd('reg_password',this)"
                            style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:4px">
                      <i class="fas fa-eye" style="font-size:14px"></i>
                    </button>
                  </div>
                  <!-- مؤشر القوة -->
                  <div style="margin-top:6px">
                    <div style="height:4px;background:#e2e8f0;border-radius:4px;overflow:hidden">
                      <div id="pwdStrengthBar" style="height:100%;width:0%;transition:width .3s,background .3s;border-radius:4px"></div>
                    </div>
                    <div id="pwdStrengthLabel" style="font-size:11px;color:#94a3b8;margin-top:3px;text-align:left"></div>
                  </div>
                  <!-- قواعد كلمة المرور -->
                  <ul id="pwdRules" style="list-style:none;padding:6px 0 0;margin:0;font-size:12px">
                    <li id="rule_len"     style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:10px"></i>8 أحرف على الأقل</li>
                    <li id="rule_upper"   style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:10px"></i>حرف كبير (A-Z)</li>
                    <li id="rule_lower"   style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:10px"></i>حرف صغير (a-z)</li>
                    <li id="rule_num"     style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:10px"></i>رقم (0-9)</li>
                    <li id="rule_symbol"  style="color:#94a3b8;margin-bottom:2px"><i class="fas fa-circle-xmark me-1" style="font-size:10px"></i>رمز خاص (!@#$%^&*...)</li>
                  </ul>
                </div>
                <div class="col-md-4">
                  <label class="rp-label"><span class="rp-req">*</span>تأكيد كلمة المرور</label>
                  <div style="position:relative">
                    <input type="password" name="password2" id="reg_password2" class="rp-input" required
                           autocomplete="new-password" placeholder="أعد إدخال كلمة المرور" oninput="checkPwdMatch()">
                    <button type="button" onclick="togglePwd('reg_password2',this)"
                            style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:4px">
                      <i class="fas fa-eye" style="font-size:14px"></i>
                    </button>
                  </div>
                  <div id="pwdMatchMsg" style="font-size:12px;margin-top:5px;min-height:18px"></div>
                </div>
              </div>

              <!-- خطأ مضمّن في خطوة المكتب -->
              <div id="officeInlineError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-top:16px;font-size:13px;color:#991b1b;align-items:center;gap:8px">
                <i class="fas fa-exclamation-circle flex-shrink-0"></i>
                <span id="officeInlineErrorMsg"></span>
              </div>

              <!-- زر التالي للدفع -->
              <div style="margin-top:28px;padding-top:20px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                <button type="button" onclick="goBackToFeats()"
                        style="padding:10px 20px;border:1.5px solid #e2e8f0;background:#fff;border-radius:10px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;display:flex;align-items:center;gap:8px">
                  <i class="fas fa-arrow-right"></i> السابق
                </button>
                <button type="button" id="btnNextToPay" onclick="goToPayStep()"
                        style="padding:12px 32px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:10px">
                  التالي — الدفع <i class="fas fa-arrow-left"></i>
                </button>
              </div>

            </div><!-- /officeSubStep -->

            <!-- ══ الخطوة الفرعية: الدفع ══ -->
            <div id="paymentSubStep" style="display:none">

              <!-- رأس خطوة الدفع -->
              <div style="padding:18px 20px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px">
                <div style="width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-credit-card" style="color:#c9a227;font-size:17px"></i>
                </div>
                <div>
                  <div style="font-size:15px;font-weight:800;color:#0c1b36">إتمام الدفع</div>
                  <div style="font-size:12px;color:#64748b">اختر طريقة الدفع المناسبة لإتمام تسجيلك</div>
                </div>
              </div>
              <div style="padding:20px 24px">

              <!-- ④ الدفع -->
              <div class="rp-section" style="margin-top:0">طريقة الدفع</div>

              <?php
              $pp_on = sc($conn,'paypal_enabled','0')==='1' && sc($conn,'paypal_client_id','')!=='';
              $pm_on = sc($conn,'paymob_enabled','0')==='1' && sc($conn,'paymob_api_key','')!=='';
              $bk_on = sc($conn,'bank_enabled','1')==='1';
              $pp_cid= sc($conn,'paypal_client_id','');
              $pp_mode=sc($conn,'paypal_mode','sandbox');
              $reg_amount = $is_custom ? (float)$custom_price : ($billing_cycle==='yearly' ? (float)($pkg['price_yearly']??0) : (float)($pkg['price_monthly']??0));
              // Determine default payment method
              $default_pm = $bk_on ? 'bank' : ($pm_on ? 'card' : ($pp_on ? 'paypal' : 'bank'));
              ?>

              <!-- اختيار طريقة الدفع -->
              <div class="d-flex gap-2 flex-wrap mb-3" id="rp-pay-method-btns">
                <?php if ($bk_on): ?>
                <button type="button" class="rp-pm-btn<?= $default_pm==='bank'?' active':'' ?>" id="rpPmBank" onclick="rpSelectPay('bank')">
                  <i class="fas fa-university me-1"></i>تحويل بنكي
                </button>
                <?php endif; ?>
                <?php if ($pm_on): ?>
                <button type="button" class="rp-pm-btn<?= $default_pm==='card'?' active':'' ?>" id="rpPmCard" onclick="rpSelectPay('card')">
                  <i class="fas fa-credit-card me-1"></i>بطاقة ائتمانية
                </button>
                <button type="button" class="rp-pm-btn" id="rpPmApple" onclick="rpSelectPay('applepay')">
                  <i class="fab fa-apple me-1"></i>Apple Pay
                </button>
                <?php endif; ?>
                <?php if ($pp_on): ?>
                <button type="button" class="rp-pm-btn<?= $default_pm==='paypal'?' active':'' ?>" id="rpPmPaypal" onclick="rpSelectPay('paypal')">
                  <i class="fab fa-paypal me-1"></i>PayPal
                </button>
                <?php endif; ?>
              </div>
              <input type="hidden" name="payment_method" id="rpPayMethodVal" value="<?= $default_pm ?>">
              <input type="hidden" name="payment_ref"    id="rpPayRefVal"    value="">

              <!-- قسم التحويل البنكي -->
              <?php if ($bk_on): ?>
              <div id="rp-section-bank" <?= $default_pm!=='bank'?'style="display:none"':'' ?>>
              <?php
                $bk_name = sc($conn,'platform_bank_name','');
                $bk_iban = sc($conn,'platform_bank_iban','');
                $bk_acct = sc($conn,'platform_bank_account','');
                $bk_note = sc($conn,'platform_payment_instructions','');
              ?>
              <?php if ($bk_name || $bk_iban): ?>
              <div class="rp-pay-box mb-3">
                <h6><i class="fas fa-university"></i>بيانات التحويل البنكي</h6>
                <?php if ($bk_name): ?>
                <div class="rp-bank-row">
                  <span class="rp-bank-lbl">البنك</span>
                  <span class="rp-bank-val"><?= e($bk_name) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($bk_iban): ?>
                <div class="rp-bank-row">
                  <span class="rp-bank-lbl">IBAN</span>
                  <span class="rp-bank-val" style="font-size:12px;letter-spacing:.5px"><?= e($bk_iban) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($bk_acct): ?>
                <div class="rp-bank-row">
                  <span class="rp-bank-lbl">رقم الحساب</span>
                  <span class="rp-bank-val font-monospace"><?= e($bk_acct) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!$is_custom): ?>
                <div class="rp-bank-row">
                  <span class="rp-bank-lbl">المبلغ المطلوب</span>
                  <span class="rp-bank-val" style="color:#c9a227" id="payAmount">
                    <?= $billing_cycle==='yearly'
                        ? number_format($pkg['price_yearly']).' ريال / سنة'
                        : number_format($pkg['price_monthly']).' ريال / شهر' ?>
                  </span>
                </div>
                <?php else: ?>
                <div class="rp-bank-row">
                  <span class="rp-bank-lbl">المبلغ المقدر</span>
                  <span class="rp-bank-val" style="color:#7c3aed" id="customPayAmount">
                    <?= $custom_price > 0 ? number_format($custom_price, 0).' ريال / شهر' : 'يُحدد من قِبل الفريق' ?>
                  </span>
                </div>
                <?php endif; ?>
                <?php if ($bk_note): ?>
                <div style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(253,230,138,.6);font-size:12px;color:#92400e">
                  <i class="fas fa-info-circle me-1 text-warning"></i><?= nl2br(e($bk_note)) ?>
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <div class="mb-3">
                <label class="rp-label">إيصال التحويل <span class="rp-req">*</span></label>
                <div style="border:2px dashed #cbd5e1;border-radius:10px;padding:16px;text-align:center;background:#f8fafc;cursor:pointer;transition:border-color .2s"
                     id="receiptDropZone" onclick="document.getElementById('receiptFile').click()">
                  <i class="fas fa-upload" style="font-size:22px;color:#94a3b8;display:block;margin-bottom:6px"></i>
                  <div style="font-size:13px;color:#64748b" id="receiptFileName">اضغط لرفع صورة إيصال التحويل</div>
                  <div style="font-size:11px;color:#94a3b8;margin-top:4px">PNG، JPG، PDF — بحد أقصى 5 ميجابايت</div>
                </div>
                <input type="file" id="receiptFile" name="bank_receipt" accept="image/*,application/pdf"
                       style="display:none" onchange="rpReceiptSelected(this)">
                <div style="font-size:12px;color:#94a3b8;margin-top:6px">
                  <i class="fas fa-info-circle me-1"></i><?= $is_custom
                    ? 'سيتواصل معك فريقنا خلال 24 ساعة لتأكيد الباقة والسعر النهائي'
                    : 'سيتم التحقق من الإيصال وتفعيل حسابك خلال 24–48 ساعة' ?>
                </div>
              </div>
              </div><!-- /rp-section-bank -->
              <?php endif; // bank_enabled ?>

              <!-- قسم Paymob (بطاقة / Apple Pay) -->
              <?php if ($pm_on): ?>
              <div id="rp-section-card" <?= $default_pm==='card'?'':'style="display:none"' ?>>
                <div class="rp-pay-box" style="background:#f0f9ff;border-color:#bae6fd">
                  <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="fas fa-shield-alt text-primary"></i>
                    <span style="font-size:13px;font-weight:600;color:#0c4a6e">دفع آمن عبر Paymob</span>
                  </div>
                  <div style="font-size:12px;color:#0369a1">
                    المبلغ: <strong id="pmAmountLbl"><?= number_format($reg_amount, 0) ?> ر.س</strong>
                  </div>
                </div>
                <button type="button" id="rpPaymobBtn"
                        onclick="rpInitPaymob()"
                        style="width:100%;padding:14px;background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;margin-bottom:8px">
                  <i class="fas fa-credit-card me-2"></i><span id="rpPaymobBtnTxt">ادفع الآن</span>
                </button>
              </div>
              <div id="rp-section-applepay" style="display:none">
                <div class="rp-pay-box" style="background:#f0f9ff;border-color:#bae6fd">
                  <div style="font-size:13px;font-weight:600;color:#0c4a6e">
                    <i class="fab fa-apple me-1"></i>Apple Pay عبر Paymob — المبلغ: <strong id="apAmountLbl"><?= number_format($reg_amount, 0) ?> ر.س</strong>
                  </div>
                </div>
                <button type="button" onclick="rpInitPaymob('applepay')"
                        style="width:100%;padding:14px;background:#000;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer">
                  <i class="fab fa-apple me-2"></i>Pay with Apple Pay
                </button>
              </div>
              <?php endif; ?>

              <!-- قسم PayPal -->
              <?php if ($pp_on): ?>
              <div id="rp-section-paypal" <?= $default_pm==='paypal'?'':'style="display:none"' ?>>
                <div class="rp-pay-box" style="background:#fef9e7;border-color:#fbbf24">
                  <div style="font-size:13px;color:#92400e">
                    <i class="fab fa-paypal me-1 text-primary"></i>الدفع عبر PayPal — المبلغ:
                    <strong id="ppAmountLbl"><?= number_format($reg_amount, 2) ?> SAR</strong>
                  </div>
                </div>
                <div id="paypal-button-container" style="margin-top:8px"></div>
              </div>
              <?php endif; ?>

              <!-- Paymob iframe modal -->
              <div id="rpPaymobModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);align-items:center;justify-content:center">
                <div style="background:#fff;border-radius:16px;overflow:hidden;width:min(520px,95vw);max-height:90vh;display:flex;flex-direction:column">
                  <div style="padding:14px 18px;background:#0c1b36;color:#fff;display:flex;align-items:center;justify-content:space-between">
                    <span style="font-weight:700"><i class="fas fa-lock me-2"></i>صفحة الدفع الآمن</span>
                    <button onclick="rpClosePaymob()" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">×</button>
                  </div>
                  <iframe id="rpPaymobFrame" src="" style="flex:1;border:none;min-height:500px"></iframe>
                </div>
              </div>

              <hr style="border-color:#f0f0f0;margin:24px 0">

              <div class="d-flex align-items-start gap-3 mb-4">
                <input type="checkbox" id="agreeChk" name="agree" required
                       style="width:16px;height:16px;margin-top:2px;flex-shrink:0;cursor:pointer">
                <label for="agreeChk" style="font-size:13px;color:#6b7280;cursor:pointer;line-height:1.7">
                  أوافق على
                  <a href="terms.php" target="_blank" style="color:#1a3a6e;font-weight:700">شروط الاستخدام</a>
                  و
                  <a href="privacy.php" target="_blank" style="color:#1a3a6e;font-weight:700">سياسة الخصوصية</a>
                </label>
              </div>

              <div id="regInlineError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#991b1b;align-items:center;gap:8px">
                <i class="fas fa-exclamation-circle flex-shrink-0"></i>
                <span id="regInlineErrorMsg"></span>
              </div>

              <div style="display:flex;gap:12px;align-items:center">
                <button type="button" onclick="goBackToOffice()"
                        style="padding:12px 20px;border:1.5px solid #e2e8f0;background:#fff;border-radius:12px;font-size:14px;font-weight:600;color:#64748b;cursor:pointer;display:flex;align-items:center;gap:8px;flex-shrink:0">
                  <i class="fas fa-arrow-right"></i> السابق
                </button>
                <button type="submit" class="rp-btn-submit" id="regSubmitBtn" style="flex:1">
                  <i class="fas fa-paper-plane"></i>
                  <span id="regBtnTxt">إرسال طلب التسجيل</span>
                </button>
              </div>

              </div><!-- /padding -->
            </div><!-- /paymentSubStep -->

            </form>
          </div>
        </div><!-- /rp-card -->
      </div><!-- /col-lg-8 -->

      <!-- SIDEBAR SUMMARY -->
      <div class="col-lg-4">
        <div class="rp-sum-card">
          <!-- ملخص الباقة المخصصة — يُعرض دائماً، مخفي عند الحاجة -->
          <div id="sumCustomBlock" <?= !$is_custom ? 'style="display:none"' : '' ?>>
            <div class="rp-sum-head" style="background:linear-gradient(135deg,#7c3aed,#a855f7)">
              <div class="slbl">نوع الباقة</div>
              <div class="sname"><i class="fas fa-puzzle-piece me-2"></i>باقة مخصصة</div>
            </div>
            <div class="rp-sum-body">
              <div class="rp-sum-price">
                <div class="sp-amount" style="color:#7c3aed" id="sumCustomAmount"><?= number_format($custom_price, 0) ?></div>
                <div class="sp-unit">ريال سعودي / شهر (مقدر)</div>
              </div>
              <ul class="rp-sum-feats" id="sumFeatsCustom">
                <li><i class="fas fa-users"></i>حتى <strong><?= $custom_users ?></strong> مستخدمين</li>
                <li><i class="fas fa-gavel"></i>حتى <strong><?= $custom_cases ?></strong> قضية</li>
                <?php
                $sel_feats = array_filter(explode(',', $custom_features_raw));
                foreach ($sel_feats as $fk):
                  $fk = trim($fk);
                  if (!$fk) continue;
                ?>
                <li><i class="fas fa-check-circle"></i><?= htmlspecialchars($feat_label_map[$fk] ?? $fk) ?></li>
                <?php endforeach; ?>
                <li><i class="fas fa-info-circle" style="color:#f59e0b"></i>السعر النهائي يُؤكده الإدارة</li>
              </ul>
              <div class="rp-sum-guarantee" style="background:rgba(124,58,237,.08);border-color:rgba(124,58,237,.2);color:#5b21b6">
                <i class="fas fa-shield-alt" style="color:#7c3aed"></i>سيتواصل معك فريقنا خلال 24 ساعة
              </div>
            </div>
          </div>
          <!-- ملخص الباقة العادية — يُعرض دائماً، مخفي عند الحاجة -->
          <div id="sumRegularBlock" <?= $is_custom ? 'style="display:none"' : '' ?>>
            <div class="rp-sum-head">
              <div class="slbl">الباقة المختارة</div>
              <div class="sname" id="sumName"><?= htmlspecialchars($pkg['name'] ?? '') ?></div>
            </div>
            <div class="rp-sum-body">
              <div class="rp-sum-price">
                <div class="sp-amount" id="sumAmount"><?= number_format($pkg['price_monthly'] ?? 0) ?></div>
                <div class="sp-unit">ريال سعودي / شهر</div>
                <div class="sp-yearly" id="sumYearly">أو <?= number_format($pkg['price_yearly'] ?? 0) ?> ر.س / سنوياً</div>
              </div>
              <ul class="rp-sum-feats" id="sumFeatsRegular">
                <li><i class="fas fa-check"></i><?= ((int)($pkg['max_users']??0)==0||(int)($pkg['max_users']??0)>=999) ? 'مستخدمون <strong>غير محدودين</strong>' : 'حتى <strong>'.(int)($pkg['max_users']??0).'</strong> مستخدمين' ?></li>
                <li><i class="fas fa-check"></i><?= ((int)($pkg['max_cases']??0)==0||(int)($pkg['max_cases']??0)>=999) ? 'قضايا <strong>غير محدودة</strong>' : 'حتى <strong>'.(int)($pkg['max_cases']??0).'</strong> قضية' ?></li>
                <?php foreach(array_filter(array_map('trim',explode(',',$pkg['features']??''))) as $f): ?>
                <li><i class="fas fa-check"></i><?= htmlspecialchars($f) ?></li>
                <?php endforeach; ?>
                <li><i class="fas fa-check"></i>تجربة مجانية 14 يوم</li>
              </ul>
              <div class="rp-sum-guarantee">
                <i class="fas fa-shield-alt"></i>ضمان استرداد كامل خلال 14 يوم
              </div>
            </div>
          </div>
        </div>
      </div><!-- /col-lg-4 -->

    </div><!-- /row -->
    <?php endif; ?>

  </div><!-- /rp-container -->
</div><!-- /rp-wrapper -->

<script>
/* ── package data injected from PHP ── */
var pkgData = <?php
  $pd = [];
  foreach ($all_pkgs as $p) {
    $feats = array_values(array_filter(array_map('trim', explode(',', $p['features'] ?? ''))));
    $pd[$p['id']] = [
      'name'    => $p['name'],
      'monthly' => (int)$p['price_monthly'],
      'yearly'  => (int)$p['price_yearly'],
      'perMo'   => (int)round($p['price_yearly']/12),
      'users'   => ((int)$p['max_users']==0||(int)$p['max_users']>=999) ? 'غير محدودين' : $p['max_users'],
      'cases'   => ((int)$p['max_cases']==0||(int)$p['max_cases']>=999) ? 'غير محدودة' : $p['max_cases'],
      'features'=> $feats,
    ];
  }
  echo json_encode($pd, JSON_UNESCAPED_UNICODE);
?>;

var currentBilling = '<?= $billing_cycle ?>';
var currentPkgId   = <?= $pkg_id ?>;
/* المبلغ الحالي للدفع — يُعرَّف دائماً (خارج أي شرط PHP) حتى لا يفشل زر "التالي — الدفع" */
var _rp_amount = <?= $is_custom
    ? (float)$custom_price
    : ($billing_cycle === 'yearly'
        ? (float)($pkg['price_yearly']  ?? 0)
        : (float)($pkg['price_monthly'] ?? 0)) ?>;

function setBilling(cycle) {
  currentBilling = cycle;
  document.getElementById('billingCycleField').value = cycle;

  // toggle buttons
  document.getElementById('btnMonthly').classList.toggle('on', cycle === 'monthly');
  document.getElementById('btnYearly').classList.toggle('on', cycle === 'yearly');

  // update all package cards prices
  Object.keys(pkgData).forEach(function(id) {
    var p = pkgData[id];
    var priceEl = document.getElementById('pkgPrice' + id);
    var noteEl  = document.getElementById('pkgNote'  + id);
    if (!priceEl) return;
    if (cycle === 'yearly') {
      priceEl.innerHTML = p.perMo.toLocaleString() + ' <small>ر.س/شهر</small>';
      if (noteEl) noteEl.textContent = '(' + p.yearly.toLocaleString() + ' ر.س سنوياً)';
    } else {
      priceEl.innerHTML = p.monthly.toLocaleString() + ' <small>ر.س/شهر</small>';
      if (noteEl) noteEl.textContent = '';
    }
  });

  // update sidebar & payment for current package
  updateSummary(currentPkgId);
}

function selectPkg(id) {
  currentPkgId = id;
  document.getElementById('pkgIdField').value = id;
  document.querySelectorAll('.rp-pkg-opt').forEach(function(el){ el.classList.remove('active'); });
  var opt = document.getElementById('pkgOpt' + id);
  if (opt) opt.classList.add('active');

  // هل الباقة المختارة مخصصة (سعرها 0)؟
  var p = pkgData[id];
  var isCustomPkg = p && p.monthly === 0;

  var featStep    = document.getElementById('featStep');
  var mainForm    = document.getElementById('mainFormWrap');
  var isCustomFld = document.getElementById('hidIsCustom');

  var stepFeats = document.getElementById('stepBarFeats');
  var stepInfo  = document.getElementById('stepBarInfo');

  if (isCustomPkg) {
    // إظهار خطوة اختيار الميزات وإخفاء النموذج
    if (featStep)  featStep.style.display  = '';
    if (mainForm)  mainForm.style.display  = 'none';
    if (isCustomFld) isCustomFld.value = '1';
    // شريط الخطوات: إظهار خطوة الميزات كـ active
    if (stepFeats) { stepFeats.style.display = ''; stepFeats.className = 'rp-step active'; stepFeats.querySelector('.rp-step-circle').textContent = '2'; }
    if (stepInfo)  { stepInfo.className = 'rp-step'; }
    if (typeof fsPkgSelect === 'function') fsPkgSelect(id, true);
    if (typeof calcTotal === 'function') calcTotal();
  } else {
    // إخفاء خطوة الميزات وإظهار النموذج
    if (featStep)  featStep.style.display  = 'none';
    if (mainForm)  mainForm.style.display  = '';
    if (isCustomFld) isCustomFld.value = '0';
    // شريط الخطوات: إخفاء خطوة الميزات، تنشيط بيانات المكتب
    if (stepFeats) { stepFeats.style.display = 'none'; }
    if (stepInfo)  { stepInfo.className = 'rp-step active'; }
    // مسح بيانات الميزات المخصصة
    var hf = document.getElementById('hidCustomFeat');  if (hf) hf.value = '';
    var hp = document.getElementById('hidCustomPrice'); if (hp) hp.value = '0';
    updateSummary(id);
  }
}

function updateSummary(id) {
  var p = pkgData[id];
  if (!p) return;
  var isYearly = currentBilling === 'yearly';

  // sidebar
  document.getElementById('sumName').textContent   = p.name;
  document.getElementById('sumAmount').textContent = isYearly
    ? Math.round(p.yearly / 12).toLocaleString()
    : p.monthly.toLocaleString();

  var yearlyEl = document.getElementById('sumYearly');
  if (yearlyEl) {
    yearlyEl.textContent = isYearly
      ? 'إجمالي سنوي: ' + p.yearly.toLocaleString() + ' ر.س (وفّر 17%)'
      : 'أو ' + p.yearly.toLocaleString() + ' ر.س / سنوياً';
    yearlyEl.style.color = isYearly ? '#16a34a' : '#9ca3af';
  }

  var unitEl = document.querySelector('#sumRegularBlock .sp-unit');
  if (unitEl) unitEl.textContent = isYearly ? 'ريال سعودي / شهر (مدفوع سنوياً)' : 'ريال سعودي / شهر';

  // payment section — skip for custom/zero-price packages (amount managed by proceedToForm)
  var newAmt = isYearly ? p.yearly : p.monthly;
  if (newAmt > 0) rpSetAmount(newAmt);

  var paEl = document.getElementById('payAmount');
  if (paEl) paEl.textContent = isYearly
    ? p.yearly.toLocaleString() + ' ريال / سنة'
    : p.monthly.toLocaleString() + ' ريال / شهر';

  var pbEl = document.getElementById('payBilling');
  if (pbEl) pbEl.textContent = isYearly ? 'سنوي (دفعة واحدة)' : 'شهري';

  // features
  var ul = document.getElementById('sumFeatsRegular');
  if (ul) {
    var usersText = (p.users === 'غير محدودين') ? 'مستخدمون <strong>غير محدودين</strong>' : 'حتى <strong>' + p.users + '</strong> مستخدمين';
    var casesText = (p.cases === 'غير محدودة')  ? 'قضايا <strong>غير محدودة</strong>'       : 'حتى <strong>' + p.cases + '</strong> قضية';
    var html = '<li><i class="fas fa-check"></i>' + usersText + '</li>';
    html    += '<li><i class="fas fa-check"></i>' + casesText + '</li>';
    p.features.forEach(function(f){ html += '<li><i class="fas fa-check"></i>' + f + '</li>'; });
    html    += '<li><i class="fas fa-check"></i>تجربة مجانية 14 يوم</li>';
    ul.innerHTML = html;
  }
}

/* ══ Password validation ══ */
function togglePwd(fieldId, btn) {
  var inp = document.getElementById(fieldId);
  var icon = btn.querySelector('i');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'fas fa-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'fas fa-eye';
  }
}

function checkPwdRules() {
  var val = document.getElementById('reg_password').value;

  var ok_len    = val.length >= 8;
  var ok_upper  = /[A-Z]/.test(val);
  var ok_lower  = /[a-z]/.test(val);
  var ok_num    = /[0-9]/.test(val);
  var ok_symbol = /[^A-Za-z0-9]/.test(val);

  setRule('rule_len',    ok_len);
  setRule('rule_upper',  ok_upper);
  setRule('rule_lower',  ok_lower);
  setRule('rule_num',    ok_num);
  setRule('rule_symbol', ok_symbol);

  // strength bar (5 criteria)
  var score = [ok_len, ok_upper, ok_lower, ok_num, ok_symbol].filter(Boolean).length;
  var configs = [
    ['0%',   '#dc2626', ''],
    ['20%',  '#dc2626', 'ضعيفة جداً'],
    ['40%',  '#f97316', 'ضعيفة'],
    ['60%',  '#d97706', 'متوسطة'],
    ['80%',  '#2563eb', 'جيدة'],
    ['100%', '#16a34a', 'قوية جداً'],
  ];
  var cfg = configs[score];
  var bar = document.getElementById('pwdStrengthBar');
  var lbl = document.getElementById('pwdStrengthLabel');
  if (bar) { bar.style.width = cfg[0]; bar.style.background = cfg[1]; }
  if (lbl) { lbl.textContent = val.length ? cfg[2] : ''; lbl.style.color = cfg[1]; }

  if (document.getElementById('reg_password2').value) checkPwdMatch();
  return ok_len && ok_upper && ok_lower && ok_num && ok_symbol;
}

function setRule(id, ok) {
  var li   = document.getElementById(id);
  var icon = li.querySelector('i');
  if (ok) {
    li.style.color   = '#16a34a';
    icon.className   = 'fas fa-circle-check me-1';
    icon.style.fontSize = '10px';
  } else {
    li.style.color   = '#94a3b8';
    icon.className   = 'fas fa-circle-xmark me-1';
    icon.style.fontSize = '10px';
  }
}

function checkPwdMatch() {
  var p1  = document.getElementById('reg_password').value;
  var p2  = document.getElementById('reg_password2').value;
  var msg = document.getElementById('pwdMatchMsg');
  if (!msg) return;
  if (!p2) { msg.textContent = ''; return; }
  if (p1 === p2) {
    msg.innerHTML = '<i class="fas fa-check-circle me-1" style="color:#16a34a"></i><span style="color:#16a34a">كلمتا المرور متطابقتان</span>';
  } else {
    msg.innerHTML = '<i class="fas fa-times-circle me-1" style="color:#dc2626"></i><span style="color:#dc2626">كلمتا المرور غير متطابقتين</span>';
  }
}

/* ══════════════════════════════════════════════
   إدارة الخطوات الفرعية: التسجيل ↔ الدفع
══════════════════════════════════════════════ */
var _onPayStep = false;
var _hasCustom = <?= ($is_custom || $has_custom_in_list) ? 'true' : 'false' ?>;

function goToPayStep() {
  /* تحقق من الحقول المطلوبة في خطوة التسجيل */
  var required = document.querySelectorAll('#officeSubStep [required]');
  var missing = false;
  required.forEach(function(f) {
    f.style.borderColor = '';
    if (!f.value.trim()) {
      f.style.borderColor = '#ef4444';
      if (!missing) { f.focus(); missing = true; }
    }
  });
  if (missing) {
    showInlineErrorOffice('يرجى تعبئة جميع الحقول المطلوبة قبل المتابعة');
    return;
  }

  /* تحقق من كلمة المرور */
  var p1 = document.getElementById('reg_password');
  var p2 = document.getElementById('reg_password2');
  if (p1 && p1.value.length < 8) {
    showInlineErrorOffice('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
    p1.focus(); return;
  }
  if (p2 && p1 && p1.value !== p2.value) {
    showInlineErrorOffice('كلمتا المرور غير متطابقتين');
    p2.focus(); return;
  }

  _onPayStep = true;

  /* مزامنة مبالغ الدفع مع القيمة الحالية (مع حماية إن لم تُضبط القيمة) */
  rpSetAmount(
    (typeof _rp_amount === 'number' && _rp_amount > 0)
      ? _rp_amount
      : (pkgData[currentPkgId]
          ? (currentBilling === 'yearly' ? pkgData[currentPkgId].yearly : pkgData[currentPkgId].monthly)
          : 0)
  );

  /* إخفاء أي خطأ مضمّن */
  var offErr = document.getElementById('officeInlineError');
  if (offErr) offErr.style.display = 'none';

  /* إخفاء التسجيل وإظهار الدفع */
  document.getElementById('officeSubStep').style.display  = 'none';
  document.getElementById('officeStepHead').style.display = 'none';
  document.getElementById('paymentSubStep').style.display = '';

  /* تحديث تاب الرأس */
  var tabInfo = document.getElementById('subTabInfo');
  var tabPay  = document.getElementById('subTabPay');
  if (tabInfo) {
    tabInfo.style.color       = '#94a3b8';
    tabInfo.style.borderBottom = '2px solid transparent';
    tabInfo.querySelector('span').style.background = '#e2e8f0';
    tabInfo.querySelector('span').style.color      = '#94a3b8';
    tabInfo.querySelector('span').textContent      = '✓';
  }
  if (tabPay) {
    tabPay.style.color        = '#1a3a6e';
    tabPay.style.borderBottom = '2px solid #1a3a6e';
    tabPay.style.fontWeight   = '700';
    var circ = document.getElementById('subTabPayCircle');
    if (circ) { circ.style.background = '#1a3a6e'; circ.style.color = '#fff'; }
  }

  /* تحديث شريط الخطوات الرئيسي */
  var stepInfo = document.getElementById('stepBarInfo');
  var stepPay  = document.getElementById('stepBarPay');
  if (stepInfo) {
    stepInfo.classList.remove('active');
    stepInfo.classList.add('done');
    document.getElementById('stepBarInfoCircle').innerHTML = '<i class="fas fa-check" style="font-size:11px"></i>';
  }
  if (stepPay) {
    stepPay.classList.add('active');
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBackToOffice() {
  _onPayStep = false;

  document.getElementById('paymentSubStep').style.display = 'none';
  document.getElementById('officeSubStep').style.display  = '';
  document.getElementById('officeStepHead').style.display = '';

  /* إعادة تاب الرأس */
  var tabInfo = document.getElementById('subTabInfo');
  var tabPay  = document.getElementById('subTabPay');
  if (tabInfo) {
    tabInfo.style.color       = '#1a3a6e';
    tabInfo.style.borderBottom = '2px solid #1a3a6e';
    tabInfo.style.fontWeight   = '700';
    tabInfo.querySelector('span').style.background = '#1a3a6e';
    tabInfo.querySelector('span').style.color      = '#fff';
  }
  if (tabPay) {
    tabPay.style.color        = '#94a3b8';
    tabPay.style.borderBottom = '2px solid transparent';
    tabPay.style.fontWeight   = '600';
    var circ = document.getElementById('subTabPayCircle');
    if (circ) { circ.style.background = '#e2e8f0'; circ.style.color = '#94a3b8'; }
  }

  /* إعادة شريط الخطوات */
  var stepInfo = document.getElementById('stepBarInfo');
  var stepPay  = document.getElementById('stepBarPay');
  if (stepInfo) {
    stepInfo.classList.remove('done');
    stepInfo.classList.add('active');
    document.getElementById('stepBarInfoCircle').innerHTML = _hasCustom ? '3' : '2';
  }
  if (stepPay) stepPay.classList.remove('active');

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBackToFeats() {
  /* يُستخدم زر السابق في خطوة التسجيل ليعود لخطوة الميزات أو لصفحة الأسعار */
  if (_hasCustom) {
    var featStep = document.getElementById('featStep');
    var mainForm = document.getElementById('mainFormWrap');
    if (featStep && mainForm) {
      featStep.style.display = '';
      mainForm.style.display = 'none';
      var sf = document.getElementById('stepBarFeats');
      var si = document.getElementById('stepBarInfo');
      if (sf) { sf.classList.remove('done'); sf.classList.add('active'); sf.querySelector('.rp-step-circle').innerHTML = '2'; }
      if (si) { si.classList.remove('active'); si.classList.remove('done'); }
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }
  }
  window.history.back();
}

function showInlineErrorOffice(msg) {
  var box = document.getElementById('officeInlineError');
  var txt = document.getElementById('officeInlineErrorMsg');
  if (box && txt) {
    txt.textContent   = msg;
    box.style.display = 'flex';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function showInlineError(msg) {
  var box = document.getElementById('regInlineError');
  var txt = document.getElementById('regInlineErrorMsg');
  if (box && txt) {
    txt.textContent = msg;
    box.style.display = 'flex';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function hideInlineError() {
  var box = document.getElementById('regInlineError');
  if (box) box.style.display = 'none';
}

/* submit validation */
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('form[method="POST"]');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    hideInlineError();

    var p1 = document.getElementById('reg_password');
    var p2 = document.getElementById('reg_password2');

    if (p1 && p2) {
      var val = p1.value;
      if (val.length < 8) {
        e.preventDefault(); showInlineError('كلمة المرور يجب أن تكون 8 أحرف على الأقل'); p1.focus(); return;
      }
      if (!/[A-Z]/.test(val)) {
        e.preventDefault(); showInlineError('كلمة المرور يجب أن تحتوي على حرف كبير (A-Z)'); p1.focus(); return;
      }
      if (!/[a-z]/.test(val)) {
        e.preventDefault(); showInlineError('كلمة المرور يجب أن تحتوي على حرف صغير (a-z)'); p1.focus(); return;
      }
      if (!/[0-9]/.test(val)) {
        e.preventDefault(); showInlineError('كلمة المرور يجب أن تحتوي على رقم (0-9)'); p1.focus(); return;
      }
      if (!/[^A-Za-z0-9]/.test(val)) {
        e.preventDefault(); showInlineError('كلمة المرور يجب أن تحتوي على رمز خاص مثل !@#$%'); p1.focus(); return;
      }
      if (val !== p2.value) {
        e.preventDefault(); showInlineError('كلمتا المرور غير متطابقتين'); p2.focus(); return;
      }
    }

    // منع الإرسال إذا اختار دفع إلكتروني لم يكتمل
    var method = document.getElementById('rpPayMethodVal').value;
    if (method !== 'bank' && !document.getElementById('rpPayRefVal').value) {
      e.preventDefault();
      showInlineError('يرجى إتمام عملية الدفع الإلكتروني أولاً');
      return;
    }

    // loading state
    var btn = document.getElementById('regSubmitBtn');
    var txt = document.getElementById('regBtnTxt');
    if (btn && txt) { txt.textContent = 'جاري الإرسال...'; btn.disabled = true; btn.style.opacity = '.7'; }
  });
});

/* ══ اختيار الميزات للباقة المخصصة ══ */
<?php if ($show_feat_step):
  // السعر السنوي للأساس (من إعداد الأدمن)
  $fp_base_disc = max(0, min(99, (float)(sc($conn,'custom_base_disc','0'))));
  $fp_base_py   = $fp_base_disc > 0
    ? round($fp_base_p * 12 * (1 - $fp_base_disc/100), 2)
    : round($fp_base_p * 12, 2);
?>
var _featLabels  = <?= json_encode($feat_label_map, JSON_UNESCAPED_UNICODE) ?>;
var _basePrice   = <?= (float)$fp_base_p ?>;
var _baseYearly  = <?= (float)$fp_base_py ?>;   // السعر السنوي الفعلي للأساس
var _baseUsers   = <?= (int)$fp_base_u ?>;
var _baseCases   = <?= (int)$fp_base_c ?>;
/* الطاقة الإضافية — شهري وسنوي */
var _perUser         = <?= (float)($fp['per_user']['price_monthly'] ?? 0) ?>;
var _perUserYearly   = <?= (float)($fp['per_user']['price_yearly']  ?? 0) ?>;
var _per100Cases     = <?= (float)($fp['per_100_cases']['price_monthly'] ?? 0) ?>;
var _per100CasesY    = <?= (float)($fp['per_100_cases']['price_yearly']  ?? 0) ?>;
var _selectedFeats = {};
/* _rp_amount مُعرَّف مسبقاً بالأعلى — هنا إعادة ضبط بقيمة خطوة الدفع الفعلية */
_rp_amount = <?= isset($reg_amount) ? (float)$reg_amount : 0 ?>;

function toggleFeat(el) {
  var key = el.dataset.key;
  el.classList.toggle('selected');
  if (el.classList.contains('selected')) {
    _selectedFeats[key] = true;
  } else {
    delete _selectedFeats[key];
  }
  calcTotal();
}

function adjQty(type, delta) {
  var el = document.getElementById('qty' + type.charAt(0).toUpperCase() + type.slice(1));
  var val = parseInt(el.value) + delta;
  var min = parseInt(el.min) || 1;
  if (val < min) val = min;
  el.value = val;
  calcTotal();
}

function calcTotal() {
  var isYearly     = _fsBilling === 'yearly';
  var totalMonthly = _basePrice;
  var totalYearly  = _baseYearly;

  // أسعار الميزات — نقرأ data-price (شهري) و data-price-yearly (سنوي) من البطاقة
  Object.keys(_selectedFeats).forEach(function(k) {
    var card = document.getElementById('fc_' + k);
    if (!card) return;
    var pm = parseFloat(card.dataset.price)       || 0;
    var py = parseFloat(card.dataset.priceYearly) || 0;
    // إذا لم يُحدد السنوي في DB استخدم شهري×12
    if (py === 0 && pm > 0) py = pm * 12;
    totalMonthly += pm;
    totalYearly  += py;
  });

  // مستخدمون إضافيون
  var users = parseInt(document.getElementById('qtyUsers').value) || _baseUsers;
  var extraUsers = Math.max(0, users - _baseUsers);
  if (extraUsers > 0) {
    totalMonthly += extraUsers * _perUser;
    var puY = _perUserYearly > 0 ? _perUserYearly : _perUser * 12;
    totalYearly  += extraUsers * puY;
  }

  // قضايا إضافية
  var cases = parseInt(document.getElementById('qtyCases').value) || _baseCases;
  var extraCaseUnits = Math.ceil(Math.max(0, cases - _baseCases) / 100);
  if (extraCaseUnits > 0) {
    totalMonthly += extraCaseUnits * _per100Cases;
    var pcY = _per100CasesY > 0 ? _per100CasesY : _per100Cases * 12;
    totalYearly  += extraCaseUnits * pcY;
  }

  totalMonthly = Math.round(totalMonthly);
  totalYearly  = Math.round(totalYearly);
  var displayMonthly = isYearly ? Math.round(totalYearly / 12) : totalMonthly;

  var priceEl = document.getElementById('totalPriceVal');
  if (priceEl) priceEl.textContent = displayMonthly.toLocaleString('ar-SA');

  var yearlyEl = document.getElementById('totalPriceYearly');
  if (yearlyEl) {
    if (isYearly) {
      yearlyEl.textContent = 'إجمالي سنوي: ' + totalYearly.toLocaleString('ar-SA') + ' ر.س';
      yearlyEl.style.color = '#4ade80';
    } else {
      yearlyEl.textContent = 'أو ' + totalYearly.toLocaleString('ar-SA') + ' ر.س / سنوياً';
      yearlyEl.style.color = 'rgba(255,255,255,.35)';
    }
  }

  window._customPriceMonthly = totalMonthly;
  window._customPriceYearly  = totalYearly;

  // تحديث الشريط الجانبي مباشرةً (بدون شرط > 0)
  var amtForPay = isYearly ? totalYearly : totalMonthly;
  var scEl = document.getElementById('sumCustomAmount');
  if (scEl) scEl.textContent = amtForPay.toLocaleString('ar-SA');

  var caEl = document.getElementById('customPayAmount');
  if (caEl) caEl.textContent = amtForPay.toLocaleString('ar-SA') + ' ريال / شهر (مقدر)';

  var hcp = document.getElementById('hidCustomPrice');
  if (hcp) hcp.value = amtForPay;

  if (amtForPay > 0) rpSetAmount(amtForPay);

  // Feature tags display
  var disp = document.getElementById('selectedFeatsDisplay');
  disp.innerHTML = '';
  Object.keys(_selectedFeats).forEach(function(k) {
    var tag = document.createElement('span');
    tag.className = 'fs-tag';
    tag.textContent = _featLabels[k] || k;
    disp.appendChild(tag);
  });
  if (!Object.keys(_selectedFeats).length) {
    disp.innerHTML = '<span class="fs-tag-empty">لم يُختر أي ميزة بعد</span>';
  }
}

function proceedToForm() {
  var isCustom = (document.getElementById('hidIsCustom') || {}).value === '1';
  var price = 0;

  if (isCustom) {
    var featsArr = Object.keys(_selectedFeats);
    var users   = parseInt(document.getElementById('qtyUsers').value);
    var cases   = parseInt(document.getElementById('qtyCases').value);
    // السعر المخزّن من calcTotal (شهري أو سنوي حسب الدورة)
    var monthlyPrice = window._customPriceMonthly || 0;
    var yearlyPrice  = window._customPriceYearly  || 0;
    price = _fsBilling === 'yearly' ? yearlyPrice : monthlyPrice;

    document.getElementById('hidCustomFeat').value    = featsArr.join(',');
    document.getElementById('hidCustomUsers').value   = users;
    document.getElementById('hidCustomCases').value   = cases;
    document.getElementById('hidCustomStorage').value = 0;
    document.getElementById('hidCustomPrice').value   = monthlyPrice;
    document.getElementById('billingCycleField').value = _fsBilling;

    // تحديث الشريط الجانبي للباقة المخصصة
    var sumCustom  = document.getElementById('sumCustomBlock');
    var sumRegular = document.getElementById('sumRegularBlock');
    if (sumCustom)  sumCustom.style.display  = '';
    if (sumRegular) sumRegular.style.display = 'none';
    var sumAmt = document.getElementById('sumCustomAmount');
    if (sumAmt) sumAmt.textContent = monthlyPrice.toLocaleString('ar-SA', {maximumFractionDigits:0});
    var sumList = document.getElementById('sumFeatsCustom');
    if (sumList) {
      var html = '<li><i class="fas fa-users"></i>حتى <strong>'+users+'</strong> مستخدمين</li>'
               + '<li><i class="fas fa-gavel"></i>حتى <strong>'+cases+'</strong> قضية</li>';
      featsArr.forEach(function(k) {
        html += '<li><i class="fas fa-check-circle"></i>'+(_featLabels[k]||k)+'</li>';
      });
      html += '<li><i class="fas fa-info-circle" style="color:#f59e0b"></i>السعر النهائي يُؤكده الإدارة</li>';
      sumList.innerHTML = html;
    }
  } else {
    // باقة عادية: تحديث billing
    document.getElementById('billingCycleField').value = _fsBilling;
    if (typeof setBilling === 'function') setBilling(_fsBilling);
    var p = pkgData[currentPkgId];
    if (p) price = _fsBilling === 'yearly' ? p.yearly : p.monthly;
  }

  // تحديث مبلغ الدفع
  rpSetAmount(price);

  // إخفاء خطوة الميزات وإظهار النموذج
  document.getElementById('featStep').style.display = 'none';
  document.getElementById('mainFormWrap').style.display = '';

  // تحديث شريط الخطوات
  var sf = document.getElementById('stepBarFeats');
  var si = document.getElementById('stepBarInfo');
  if (sf) { sf.classList.remove('active'); sf.classList.add('done'); sf.querySelector('.rp-step-circle').innerHTML = '<i class="fas fa-check" style="font-size:11px"></i>'; }
  if (si) { si.classList.add('active'); }

  window.scrollTo({top:0,behavior:'smooth'});
}

/* ── تبديل الباقة داخل featStep ── */
var _fsBilling = '<?= $billing_cycle ?>';

function fsBilling(cycle) {
  _fsBilling = cycle;
  document.getElementById('fsBillMonthly').classList.toggle('on', cycle === 'monthly');
  document.getElementById('fsBillYearly').classList.toggle('on', cycle === 'yearly');
  // تحديث سعر كل ميزة في الشبكة (استخدام الأسعار الفعلية من data attributes)
  document.querySelectorAll('.feat-card[data-key]').forEach(function(card) {
    var pm = parseFloat(card.dataset.price)       || 0;
    var py = parseFloat(card.dataset.priceYearly) || 0;
    var priceEl = card.querySelector('.feat-price');
    if (!priceEl || pm === 0) return;
    if (cycle === 'yearly') {
      var pypm = py > 0 ? Math.round(py / 12) : Math.round(pm);
      priceEl.textContent = '+' + pypm.toLocaleString('ar-SA') + ' ر.س';
    } else {
      priceEl.textContent = '+' + Math.round(pm).toLocaleString('ar-SA') + ' ر.س';
    }
  });
  // تحديث السعر المعروض للباقات
  Object.keys(pkgData).forEach(function(id) {
    var p = pkgData[id];
    var el = document.getElementById('fsPkgPrice' + id);
    if (el && p.monthly > 0) {
      var shown = cycle === 'yearly' ? Math.round(p.yearly / 12) : p.monthly;
      el.innerHTML = shown.toLocaleString() + '<small> ر.س/شهر</small>';
    }
  });
  // تحديث ملخص الباقة العادية أو المخصصة
  var activePkg = pkgData[currentPkgId];
  var isCustomActive = document.getElementById('hidIsCustom') && document.getElementById('hidIsCustom').value === '1';
  if (isCustomActive) {
    // إعادة حساب السعر المخصص مع مراعاة الدورة الجديدة
    if (typeof calcTotal === 'function') calcTotal();
  } else if (activePkg && activePkg.monthly > 0) {
    fsUpdateRegularSummary(currentPkgId, cycle);
  }
  // مزامنة مع الـ billing الرئيسي
  if (typeof setBilling === 'function') setBilling(cycle);
}

function fsPkgSelect(id, isCustom) {
  // تحديث التحديد المرئي
  document.querySelectorAll('.fs-pkg-opt').forEach(function(el){ el.classList.remove('active'); });
  var opt = document.getElementById('fsPkgOpt' + id);
  if (opt) opt.classList.add('active');

  currentPkgId = id;
  document.getElementById('pkgIdField').value = id;
  var isCustomFld = document.getElementById('hidIsCustom');
  if (isCustomFld) isCustomFld.value = isCustom ? '1' : '0';

  var customSection = document.getElementById('fsCustomSection');
  var regularSummary = document.getElementById('fsRegularSummary');
  var billingWrap = document.getElementById('fsBillingWrap');

  if (isCustom) {
    if (customSection)   customSection.style.display   = '';
    if (regularSummary)  regularSummary.style.display  = 'none';
    if (typeof calcTotal === 'function') calcTotal();
  } else {
    if (customSection)   customSection.style.display   = 'none';
    if (regularSummary)  regularSummary.style.display  = '';
    fsUpdateRegularSummary(id, _fsBilling);
    // مسح الميزات المخصصة
    var hf = document.getElementById('hidCustomFeat');  if (hf) hf.value = '';
    var hp = document.getElementById('hidCustomPrice'); if (hp) hp.value = '0';
  }
}

function fsUpdateRegularSummary(id, cycle) {
  var p = pkgData[id];
  if (!p) return;
  var price = cycle === 'yearly' ? Math.round(p.yearly / 12) : p.monthly;
  var priceEl = document.getElementById('fsRegPrice');
  var noteEl  = document.getElementById('fsRegNote');
  var featsEl = document.getElementById('fsRegFeats');
  if (priceEl) priceEl.textContent = price.toLocaleString();
  if (noteEl)  noteEl.textContent  = cycle === 'yearly' ? 'إجمالي سنوي: ' + p.yearly.toLocaleString() + ' ر.س' : '';
  if (featsEl) {
    featsEl.innerHTML = '';
    p.features.forEach(function(f) {
      var tag = document.createElement('span');
      tag.className = 'fs-tag';
      tag.textContent = f;
      featsEl.appendChild(tag);
    });
    if (!p.features.length) {
      featsEl.innerHTML = '<span class="fs-tag-empty">—</span>';
    }
  }
  rpSetAmount(cycle === 'yearly' ? p.yearly : p.monthly);
}

// تهيئة العرض
<?php if ($is_custom): ?>
// تحميل الميزات المُختارة مسبقاً من URL/POST إلى _selectedFeats
<?php if (!empty($custom_features_raw)): ?>
<?php foreach (array_filter(explode(',', $custom_features_raw)) as $cfk): ?>
_selectedFeats[<?= json_encode(trim($cfk)) ?>] = true;
<?php endforeach; ?>
<?php endif; ?>
calcTotal();
<?php else: ?>
// تهيئة ملخص الباقة العادية
if (pkgData[currentPkgId] && pkgData[currentPkgId].monthly > 0) {
  fsUpdateRegularSummary(currentPkgId, _fsBilling);
}
<?php endif; ?>
<?php endif; ?>

/* ══ طرق الدفع ══ */

function rpSetAmount(amount) {
  _rp_amount = amount;
  var fmt    = Math.round(amount).toLocaleString('ar-SA');
  var fmtSAR = fmt + ' ر.س';

  // Paymob / Apple Pay / PayPal labels
  var pmLbl = document.getElementById('pmAmountLbl');
  var apLbl = document.getElementById('apAmountLbl');
  var ppLbl = document.getElementById('ppAmountLbl');
  if (pmLbl) pmLbl.textContent = fmtSAR;
  if (apLbl) apLbl.textContent = fmtSAR;
  if (ppLbl) ppLbl.textContent = fmt + ' SAR';

  // Bank transfer — regular package row
  var paEl = document.getElementById('payAmount');
  if (paEl && amount > 0) {
    var isYearly = currentBilling === 'yearly';
    paEl.textContent = fmt + (isYearly ? ' ريال / سنة' : ' ريال / شهر');
  }

  // Bank transfer — custom package estimated amount
  var caEl = document.getElementById('customPayAmount');
  if (caEl && amount > 0) {
    caEl.textContent = fmt + ' ريال / شهر (مقدر)';
  }

  // Sidebar summary — custom package price
  var scEl = document.getElementById('sumCustomAmount');
  if (scEl && amount > 0) {
    scEl.textContent = Math.round(amount).toLocaleString('ar-SA');
  }
}

function rpSelectPay(method) {
  document.getElementById('rpPayMethodVal').value = method;
  // Show relevant section
  ['bank','card','paypal'].forEach(function(s) {
    var sec = document.getElementById('rp-section-' + s);
    if (sec) sec.style.display = 'none';
  });
  var show = (method === 'applepay') ? 'card' : method;
  var active = document.getElementById('rp-section-' + show);
  if (active) active.style.display = '';
  // Toggle button states
  ['bank','card','applepay','paypal'].forEach(function(m) {
    var btn = document.getElementById('rpPm' + m.charAt(0).toUpperCase() + m.slice(1));
    if (btn) btn.classList.toggle('active', m === method);
  });
}

function rpReceiptSelected(inp) {
  var f = inp.files[0];
  var lbl = document.getElementById('receiptFileName');
  var zone = document.getElementById('receiptDropZone');
  if (!f) return;
  if (f.size > 5 * 1024 * 1024) {
    alert('حجم الملف يتجاوز 5 ميجابايت');
    inp.value = '';
    return;
  }
  lbl.textContent = f.name;
  zone.style.borderColor = '#16a34a';
  zone.style.background  = '#f0fdf4';
}

/* ── Paymob ── */
function rpInitPaymob(payMethod) {
  payMethod = payMethod || document.getElementById('rpPayMethodVal').value;
  var btn = document.getElementById('rpPaymobBtn');
  if (btn) { btn.disabled = true; document.getElementById('rpPaymobBtnTxt').textContent = 'جاري التحضير...'; }

  var form = document.getElementById('regForm');
  var fd   = new FormData();
  fd.append('amount',  _rp_amount);
  fd.append('method',  payMethod === 'applepay' ? 'applepay' : 'card');
  fd.append('ref',     'REG_' + Date.now());
  fd.append('name',    (form.querySelector('[name=owner_name]')?.value || 'Guest').trim());
  fd.append('email',   (form.querySelector('[name=email]')?.value || '').trim());
  fd.append('phone',   (form.querySelector('[name=phone]')?.value || '0000000000').trim());

  fetch('ajax_paymob_init.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.ok && d.iframe_url) {
        document.getElementById('rpPaymobFrame').src = d.iframe_url;
        document.getElementById('rpPaymobModal').style.display = 'flex';
      } else {
        alert(d.error || 'فشل الاتصال بـ Paymob');
      }
      if (btn) { btn.disabled = false; document.getElementById('rpPaymobBtnTxt').textContent = 'ادفع الآن'; }
    })
    .catch(function() {
      alert('خطأ في الاتصال بالشبكة');
      if (btn) { btn.disabled = false; }
    });
}

function rpClosePaymob() {
  document.getElementById('rpPaymobModal').style.display = 'none';
  document.getElementById('rpPaymobFrame').src = '';
}

// استقبال نتيجة Paymob من iframe (pay_callback.php يُرسل postMessage)
window.addEventListener('message', function(e) {
  var d = e.data;
  if (!d || (d.type !== 'PAYMOB_CALLBACK' && d.type !== 'PAYMOB_SUCCESS')) return;
  rpClosePaymob();
  if (d.success || d.type === 'PAYMOB_SUCCESS') {
    document.getElementById('rpPayRefVal').value    = d.txn_id || d.ref || 'paymob_paid';
    document.getElementById('rpPayMethodVal').value = 'paymob';
    document.getElementById('regForm').submit();
  } else {
    var errEl = document.getElementById('rpPayError');
    if (!errEl) {
      errEl = document.createElement('div');
      errEl.id = 'rpPayError';
      errEl.className = 'alert alert-danger mt-2';
      document.getElementById('rp-section-card').prepend(errEl);
    }
    errEl.textContent = 'فشل الدفع. يرجى المحاولة مرة أخرى.';
  }
});

<?php if ($pp_on): ?>
/* ── PayPal ── */
document.addEventListener('DOMContentLoaded', function() {
  if (!document.getElementById('paypal-button-container')) return;
  paypal.Buttons({
    createOrder: function() {
      var fd = new FormData();
      fd.append('amount', _rp_amount);
      fd.append('ref', 'REG_' + Date.now());
      return fetch('ajax_paypal_order.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
          if (d.error) throw new Error(d.error);
          return d.id;
        });
    },
    onApprove: function(data) {
      var fd = new FormData();
      fd.append('order_id', data.orderID);
      return fetch('ajax_paypal_capture.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
          if (d.ok) {
            document.getElementById('rpPayRefVal').value    = data.orderID;
            document.getElementById('rpPayMethodVal').value = 'paypal';
            document.getElementById('regForm').submit();
          } else {
            alert('فشل تأكيد الدفع. يرجى المحاولة مجدداً.');
          }
        });
    },
    onError: function(err) { alert('خطأ في PayPal: ' + err); }
  }).render('#paypal-button-container');
});
<?php endif; ?>
</script>

<?php if ($pp_on): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= e($pp_cid) ?>&currency=SAR&locale=ar_SA&components=buttons" data-sdk-integration-source="button-factory"></script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
