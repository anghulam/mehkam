<?php
/**
 * office/file.php
 * ════════════════════════════════════════════════════════════
 *  بوابة تحميل الملفات الآمنة:
 *   - تتحقق أن الملف يخص مكتب المستخدم الحالي
 *   - تجلب الملف (محلي أو Google Drive الخاص بالمكتب)
 *   - تفكّ التشفير إن كان مشفَّراً
 *   - تُرسله للمتصفح (عرض أو تنزيل)
 *
 *  الاستخدام:  file.php?t=<type>&id=<row_id>[&dl=1]
 *  الأنواع:    case | contract | poa | corr | archive | library | att | smart | clientup
 * ════════════════════════════════════════════════════════════
 */
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/storage.php';
requireOffice();

$oid = (int)($_SESSION['office_id'] ?? 0);
$t   = preg_replace('/[^a-z]/', '', strtolower($_GET['t'] ?? ''));
$id  = (int)($_GET['id'] ?? 0);
$dl  = !empty($_GET['dl']);

if (!$oid || !$id || !$t) { http_response_code(400); exit('طلب غير صالح'); }

// نوع => [الجدول, عمود المسار, عمود السائق, عمود الاسم]
$map = [
    'case'     => ['cases',            'file_path', 'file_driver', 'file_name'],
    'contract' => ['contracts',        'file_path', 'file_driver', 'file_name'],
    'poa'      => ['poa',              'file_path', 'file_driver', 'file_name'],
    'corr'     => ['correspondence',   'file_path', 'file_driver', 'file_name'],
    'archive'  => ['archive',          'file_path', 'file_driver', 'file_name'],
    'library'   => ['library',          'file_path', 'file_driver', 'file_name'],
    'precedent' => ['precedents',        'file_path', 'file_driver', 'file_name'],
    'att'       => ['file_attachments', 'file_path', 'driver',      'original_name'],
    'smart'     => ['smart_archive_docs', 'file_path', 'file_driver', 'file_name'],
    'clientup'  => ['client_action_items', 'file_path', 'file_driver', 'file_name'],
];
if (!isset($map[$t])) { http_response_code(404); exit('نوع غير معروف'); }
[$table, $pathCol, $drvCol, $nameCol] = $map[$t];

try {
    $r = $conn->query("SELECT * FROM `$table` WHERE id=" . (int)$id . " LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
} catch (\Throwable $e) {
    http_response_code(500); exit('خطأ في قاعدة البيانات');
}
if (!$row) { http_response_code(404); exit('الملف غير موجود'); }

// ── تحقق الملكية ──
$row_oid = (int)($row['office_id'] ?? 0);
$is_public_lib = ($t === 'library' && !empty($row['is_public']));
if ($row_oid !== $oid && !$is_public_lib) {
    http_response_code(403); exit('غير مصرّح لك بالوصول لهذا الملف');
}

$ref    = (string)($row[$pathCol] ?? '');
$driver = (string)($row[$drvCol] ?? 'local');
$fname  = (string)($row[$nameCol] ?? 'file');
if ($ref === '') { http_response_code(404); exit('لا يوجد ملف مرفق'); }

// المكتب صاحب الملف (للملفات العامة قد يكون 0 → استخدم مكتب المستخدم لفك التشفير غير المتوقع)
$owner_oid = $row_oid ?: $oid;

$res = storage_fetch_bytes($ref, $driver, $owner_oid);
if (!$res['ok']) { http_response_code(502); exit(htmlspecialchars($res['error'] ?: 'تعذّر جلب الملف')); }

$data = $res['data'];
$ext  = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$mimes = [
    'pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
    'webp'=>'image/webp','txt'=>'text/plain; charset=utf-8',
    'doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip'=>'application/zip','rar'=>'application/vnd.rar','mp4'=>'video/mp4',
    'ppt'=>'application/vnd.ms-powerpoint',
    'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';
$inline = !$dl && in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','txt','mp4'], true);

$ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '', $fname));
$utf8  = rawurlencode($fname);

while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($data));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $utf8);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
echo $data;
exit;
