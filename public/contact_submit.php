<?php
/**
 * contact_submit.php — معالج نموذج اتصل بنا
 * يعمل مع AJAX ومع الإرسال العادي (بدون JS)
 */

// هل الطلب AJAX؟ نتحقق من header أو حقل مخفي
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || isset($_POST['ajax_submit']);

// POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'طلب غير مسموح']);
    } else {
        header('Location: contact.php');
    }
    exit;
}

// الاتصال بقاعدة البيانات
require_once __DIR__ . '/../config/db.php';

// دالة للرد حسب النوع
function respond($success, $message, $isAjax) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $message]);
    } else {
        $param = $success ? 'sent=1' : 'err=' . urlencode($message);
        header('Location: contact.php?' . $param);
    }
    exit;
}

if ($conn->connect_error) {
    respond(false, 'خطأ في الاتصال بقاعدة البيانات', $isAjax);
}

// قراءة البيانات
$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$phone   = trim($_POST['phone']   ?? '');
$subject = trim($_POST['subject'] ?? 'استفسار عام');
$message = trim($_POST['message'] ?? '');

// التحقق
if (!$name || !$email || !$message) {
    respond(false, 'يرجى ملء جميع الحقول المطلوبة', $isAjax);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'البريد الإلكتروني غير صحيح', $isAjax);
}

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// إدراج
$name    = $conn->real_escape_string($name);
$email   = $conn->real_escape_string($email);
$phone   = $conn->real_escape_string($phone);
$subject = $conn->real_escape_string($subject);
$message = $conn->real_escape_string($message);
$ip      = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');

$ok = $conn->query(
    "INSERT INTO contact_messages (name, email, phone, subject, message, ip_address)
     VALUES ('$name', '$email', '$phone', '$subject', '$message', '$ip')"
);

if ($ok) {
    respond(true, 'تم إرسال رسالتك بنجاح! سنرد عليك خلال 24 ساعة.', $isAjax);
} else {
    respond(false, 'فشل الحفظ: ' . $conn->error, $isAjax);
}
