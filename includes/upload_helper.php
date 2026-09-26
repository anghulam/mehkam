<?php
/**
 * upload_helper.php
 * نظام رفع الملفات المركزي
 * الهيكل: uploads/{office_id}/{section}/{filename}
 */

define('UPLOAD_BASE', dirname(__DIR__) . '/uploads/');
define('UPLOAD_MAX_MB', 10);
define('UPLOAD_ALLOWED', ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','rar','txt']);

/**
 * رفع ملف واحد
 * @param array  $file      $_FILES['field_name']
 * @param string $section   'archive' | 'contracts' | 'correspondence' | 'cases'
 * @param int    $office_id
 * @return array ['success'=>bool, 'path'=>string, 'name'=>string, 'size'=>string, 'error'=>string]
 */
function uploadFile(array $file, string $section, int $office_id): array {
    // لا يوجد ملف محدد
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'path' => '', 'name' => '', 'size' => '', 'error' => ''];
    }

    // خطأ في الرفع
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحد المسموح به في السيرفر',
            UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحد المسموح به في النموذج',
            UPLOAD_ERR_PARTIAL    => 'تم رفع الملف جزئياً فقط',
            UPLOAD_ERR_NO_TMP_DIR => 'مجلد temp غير موجود',
            UPLOAD_ERR_CANT_WRITE => 'لا يمكن الكتابة على القرص',
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'خطأ غير معروف أثناء الرفع'];
    }

    // فحص الحجم (10MB)
    if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        return ['success' => false, 'error' => 'حجم الملف يتجاوز ' . UPLOAD_MAX_MB . ' MB'];
    }

    // فحص الامتداد
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED)) {
        return ['success' => false, 'error' => 'نوع الملف غير مسموح به. الأنواع المقبولة: ' . implode(', ', UPLOAD_ALLOWED)];
    }

    // إنشاء المجلد
    $dir = UPLOAD_BASE . $office_id . '/' . $section . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // اسم ملف فريد
    $safe_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename  = date('Ymd_His') . '_' . $safe_name . '.' . $ext;
    $full_path = $dir . $filename;
    $rel_path  = 'uploads/' . $office_id . '/' . $section . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        return ['success' => false, 'error' => 'فشل نقل الملف — تحقق من صلاحيات المجلد'];
    }

    $size_kb = $file['size'] / 1024;
    $size_str = $size_kb >= 1024
        ? round($size_kb / 1024, 1) . ' MB'
        : round($size_kb, 0) . ' KB';

    return [
        'success' => true,
        'path'    => $rel_path,
        'name'    => $file['name'],
        'size'    => $size_str,
        'ext'     => $ext,
        'error'   => '',
    ];
}

/**
 * حذف ملف من السيرفر
 */
function deleteUploadedFile(string $rel_path): void {
    if (empty($rel_path)) return;
    $full = dirname(__DIR__) . '/' . $rel_path;
    if (file_exists($full)) {
        unlink($full);
    }
}

/**
 * رابط تحميل الملف
 */
function fileDownloadUrl(string $rel_path): string {
    return '../' . $rel_path;
}

/**
 * أيقونة الملف حسب الامتداد
 */
function fileIcon(string $ext): string {
    $icons = [
        'pdf'  => 'fa-file-pdf text-danger',
        'doc'  => 'fa-file-word text-primary',
        'docx' => 'fa-file-word text-primary',
        'xls'  => 'fa-file-excel text-success',
        'xlsx' => 'fa-file-excel text-success',
        'jpg'  => 'fa-file-image text-warning',
        'jpeg' => 'fa-file-image text-warning',
        'png'  => 'fa-file-image text-warning',
        'gif'  => 'fa-file-image text-warning',
        'zip'  => 'fa-file-archive text-secondary',
        'rar'  => 'fa-file-archive text-secondary',
    ];
    return 'fas ' . ($icons[strtolower($ext)] ?? 'fa-file text-secondary');
}
