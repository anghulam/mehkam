<?php
/**
 * config/storage.php
 * ══════════════════════════════════════════════
 *  إعدادات التخزين المركزية — عدّل هذا الملف فقط
 * ══════════════════════════════════════════════
 *
 *  للتبديل إلى Google Drive مستقبلاً:
 *  غيّر STORAGE_DRIVER من 'local' إلى 'gdrive'
 *  ثم عبّئ إعدادات Google Drive أدناه
 */

// ── اختر المزود: 'local' | 'gdrive' ──────────────────
define('STORAGE_DRIVER', 'local');

// ── إعدادات التخزين المحلي ────────────────────────────
define('STORAGE_LOCAL_BASE', dirname(__DIR__) . '/uploads/');
define('STORAGE_LOCAL_URL',  'uploads/');          // المسار النسبي للتحميل
define('STORAGE_MAX_MB',     50);                  // حجم أقصى بالميغابايت
define('STORAGE_ALLOWED_EXT', [
    'pdf','doc','docx','xls','xlsx','ppt','pptx',
    'jpg','jpeg','png','gif','webp',
    'zip','rar','txt','mp4',
]);

// ── إعدادات Google Drive (تُفعَّل عند التبديل) ────────
define('STORAGE_GD_SERVICE_JSON', dirname(__DIR__) . '/config/gdrive_service_account.json');
define('STORAGE_GD_ROOT_FOLDER',  'YOUR_DRIVE_FOLDER_ID_HERE');
define('STORAGE_GD_MAX_MB',       50);
