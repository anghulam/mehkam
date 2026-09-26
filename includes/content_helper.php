<?php
/**
 * content_helper.php
 * دوال مساعدة لقراءة محتوى الموقع من قاعدة البيانات
 * تُضمَّن في صفحات الموقع العام (public/)
 */

// دالة e() — تهريب HTML (مشتركة بين public وadmin)
if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// دالة sc() — قراءة قيمة من site_content مع cache
if (!function_exists('sc')) {
    function sc($conn, $key, $default = '') {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            // إنشاء الجدول إن لم يكن موجوداً
            $conn->query("CREATE TABLE IF NOT EXISTS site_content (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value LONGTEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $res = $conn->query("SELECT setting_key, setting_value FROM site_content");
            if ($res) while ($row = $res->fetch_assoc()) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
        return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
    }
}

// دالة site_logo() — شعار المنصّة المرفوع من الأدمن (مسار مطلق من الجذر) أو ''
if (!function_exists('site_logo')) {
    function site_logo($conn): string {
        return trim((string) sc($conn, 'site_logo', ''));
    }
}

// دالة site_name() — اسم النظام من قاعدة البيانات
if (!function_exists('site_name')) {
    function site_name($conn, $default = 'LawSaaS') {
        return sc($conn, 'site_name', $default);
    }
}

// دالة site_desc() — وصف النظام من قاعدة البيانات
if (!function_exists('site_desc')) {
    function site_desc($conn, $default = 'نظام إدارة المحاماة') {
        return sc($conn, 'site_desc', $default);
    }
}

// دالة contact_email() — وصف النظام من قاعدة البيانات
if (!function_exists('contact_email')) {
    function contact_email($conn, $default = '') {
        return sc($conn, 'contact_email', $default);
    }
}

// دالة contact_phone() — وصف النظام من قاعدة البيانات
if (!function_exists('contact_phone')) {
    function contact_phone($conn, $default = '') {
        return sc($conn, 'contact_phone', $default);
    }
}
