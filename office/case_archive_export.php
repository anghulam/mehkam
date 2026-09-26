<?php
/**
 * office/case_archive_export.php
 * ════════════════════════════════════════════════════════════
 *  تنزيل «ملف القضية الكامل» كحزمة ZIP:
 *   - case_report.html : تقرير مقروء (بيانات القضية، العميل، الجلسات، المهام، الفواتير، تقارير العمل، المرفقات)
 *   - case_data.json   : كل السجلات بصيغة قابلة للاستيراد لاحقاً
 *   - files/           : كل المرفقات (بعد فك التشفير، من التخزين المحلي أو Google Drive)
 *
 *  الاستخدام: case_archive_export.php?id=<case_id>
 * ════════════════════════════════════════════════════════════
 */
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/storage.php';
requireOffice();
if (!can('cases','view')) { header('Location: dashboard.php?msg=denied'); exit; }

$oid = (int)$_SESSION['office_id'];
$cid = (int)($_GET['id'] ?? 0);
if (!class_exists('ZipArchive')) { http_response_code(500); exit('إضافة ZipArchive غير مفعّلة على الخادم'); }

$case = $cid ? $conn->query("SELECT * FROM cases WHERE id=$cid AND office_id=$oid")->fetch_assoc() : null;
if (!$case || !canSeeCase($conn, $cid)) { http_response_code(404); exit('القضية غير موجودة'); }

/** جلب صفوف استعلام كمصفوفة — يتجاهل الجداول غير الموجودة في المكاتب القديمة */
function _ax_rows($conn, string $sql): array {
    try {
        $r = $conn->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (\Throwable $e) { return []; }
}

$client   = $case['client_id'] ? (_ax_rows($conn, "SELECT * FROM clients WHERE id=".(int)$case['client_id']." AND office_id=$oid")[0] ?? null) : null;
$sessions = _ax_rows($conn, "SELECT * FROM sessions WHERE case_id=$cid AND office_id=$oid ORDER BY session_date");
$tasks    = _ax_rows($conn, "SELECT * FROM tasks WHERE case_id=$cid AND office_id=$oid ORDER BY due_date");
$invoices = _ax_rows($conn, "SELECT * FROM invoices WHERE case_id=$cid AND office_id=$oid ORDER BY id");
$logs     = _ax_rows($conn, "SELECT w.*, u.full_name FROM case_work_logs w LEFT JOIN users u ON u.id=w.user_id WHERE w.case_id=$cid AND w.office_id=$oid ORDER BY w.created_at");
$assign   = _ax_rows($conn, "SELECT u.full_name, u.role FROM case_assignments ca JOIN users u ON u.id=ca.user_id WHERE ca.case_id=$cid AND u.office_id=$oid");
$atts     = _ax_rows($conn, "SELECT * FROM file_attachments WHERE office_id=$oid AND entity_type='case' AND entity_id=$cid ORDER BY id");

// حقول حساسة لا تدخل الحزمة
if ($client) unset($client['password'], $client['password_hash']);

$zipPath = tempnam(sys_get_temp_dir(), 'mkcase');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('تعذّر إنشاء الحزمة'); }

/* ── المرفقات (+ الملف الرئيسي المرفوع مع القضية إن وُجد) ── */
$fileList = []; $missing = []; $used = [];
$uniq = function (string $name) use (&$used): string {
    $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '_', $name) ?: 'file';
    $base = $name; $i = 1;
    while (isset($used[mb_strtolower($name)])) {
        $p = pathinfo($base);
        $name = ($p['filename'] ?? 'file') . '_' . (++$i) . (isset($p['extension']) ? '.' . $p['extension'] : '');
    }
    $used[mb_strtolower($name)] = true;
    return $name;
};
$sources = [];
if (!empty($case['file_path'])) {
    $sources[] = ['ref'=>$case['file_path'], 'driver'=>$case['file_driver'] ?: 'local', 'name'=>$case['file_name'] ?: 'case_file', 'note'=>'الملف الرئيسي للقضية'];
}
foreach ($atts as $a) {
    $sources[] = ['ref'=>$a['file_path'], 'driver'=>$a['driver'] ?: 'local', 'name'=>$a['original_name'], 'note'=>(string)$a['notes'], 'date'=>$a['created_at']];
}
foreach ($sources as $s) {
    if ($s['ref'] === '') continue;
    $res = storage_fetch_bytes($s['ref'], $s['driver'], $oid);
    if ($res['ok']) {
        $inZip = $uniq($s['name']);
        $zip->addFromString('files/' . $inZip, $res['data']);
        $fileList[] = ['name'=>$s['name'], 'zip_path'=>'files/' . $inZip, 'size'=>strlen($res['data']), 'note'=>$s['note'], 'date'=>$s['date'] ?? ''];
    } else {
        $missing[] = ['name'=>$s['name'], 'error'=>$res['error']];
    }
}

/* ── JSON ── */
$data = [
    'exported_at' => date('c'),
    'case'        => $case,
    'client'      => $client,
    'assignees'   => $assign,
    'sessions'    => $sessions,
    'tasks'       => $tasks,
    'invoices'    => $invoices,
    'work_logs'   => $logs,
    'files'       => $fileList,
    'files_failed'=> $missing,
];
$zip->addFromString('case_data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR));

/* ── تقرير HTML مقروء ── */
$statusAr = ['active'=>'نشطة','closed'=>'مغلقة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوية','suspended'=>'موقوفة'];
$sessAr   = ['scheduled'=>'مجدولة','held'=>'عُقدت','postponed'=>'مؤجلة','cancelled'=>'ملغاة'];
$taskAr   = ['pending'=>'معلقة','in_progress'=>'جارية','completed'=>'مكتملة','cancelled'=>'ملغاة'];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$row = fn(string $k, $v) => '<tr><th>' . $h($k) . '</th><td>' . nl2br($h($v === null || $v === '' ? '—' : $v)) . '</td></tr>';
$table = function (array $heads, array $rows) use ($h): string {
    if (!$rows) return '<p class="muted">لا يوجد</p>';
    $o = '<table><thead><tr>';
    foreach ($heads as $t) $o .= '<th>' . $h($t) . '</th>';
    $o .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $c) $o .= '<td>' . nl2br($h($c === null || $c === '' ? '—' : $c)) . '</td>'; $o .= '</tr>'; }
    return $o . '</tbody></table>';
};

$html  = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>ملف القضية ' . $h($case['case_number']) . '</title>';
$html .= '<style>body{font-family:Tahoma,Arial,sans-serif;max-width:900px;margin:24px auto;padding:0 16px;color:#1f2937}h1{font-size:22px}h2{font-size:17px;border-bottom:2px solid #e5e7eb;padding-bottom:4px;margin-top:28px}table{width:100%;border-collapse:collapse;margin:8px 0;font-size:13px}th,td{border:1px solid #e5e7eb;padding:6px 8px;text-align:right;vertical-align:top}th{background:#f9fafb}.kv th{width:28%}.muted{color:#9ca3af}</style></head><body>';
$html .= '<h1>ملف القضية: ' . $h($case['case_number']) . ' — ' . $h($case['title']) . '</h1>';
$html .= '<p class="muted">صُدّر في ' . date('Y-m-d H:i') . ($case['is_archived'] ?? 0 ? ' · مؤرشفة بتاريخ ' . $h($case['archived_at']) : '') . '</p>';

$html .= '<h2>بيانات القضية</h2><table class="kv">'
    . $row('رقم القضية', $case['case_number']) . $row('العنوان', $case['title']) . $row('النوع', $case['case_type'])
    . $row('المحكمة', $case['court_name']) . $row('الحالة', $statusAr[$case['status']] ?? $case['status'])
    . $row('الأتعاب', $case['fees']) . $row('تاريخ الفتح', $case['created_at'])
    . $row('الوصف', $case['description']) . $row('ملاحظات', $case['notes'])
    . $row('المُسندون', implode('، ', array_column($assign, 'full_name')))
    . '</table>';

$html .= '<h2>العميل</h2>';
$html .= $client
    ? '<table class="kv">' . $row('الاسم', $client['full_name'] ?? '') . $row('الجوال', $client['phone'] ?? '') . $row('البريد', $client['email'] ?? '') . '</table>'
    : '<p class="muted">لا يوجد عميل مرتبط</p>';

$html .= '<h2>الجلسات (' . count($sessions) . ')</h2>' . $table(['التاريخ','الوصف','النتيجة','الحالة','ملاحظات'],
    array_map(fn($s) => [$s['session_date'], $s['description'], $s['result'], $sessAr[$s['status']] ?? $s['status'], $s['notes']], $sessions));

$html .= '<h2>المهام (' . count($tasks) . ')</h2>' . $table(['المهمة','المسؤول','الاستحقاق','الحالة'],
    array_map(fn($t) => [$t['title'], $t['assigned_to'], $t['due_date'], $taskAr[$t['status']] ?? $t['status']], $tasks));

$html .= '<h2>الفواتير (' . count($invoices) . ')</h2>' . $table(['الرقم','العنوان','الإجمالي','الحالة'],
    array_map(fn($i) => [$i['invoice_number'], $i['title'], $i['total'] ?? '', $i['status'] ?? ''], $invoices));

$html .= '<h2>تقارير العمل (' . count($logs) . ')</h2>' . $table(['التاريخ','الموظف','التقرير'],
    array_map(fn($l) => [$l['created_at'], $l['full_name'], $l['content']], $logs));

$html .= '<h2>المرفقات (' . count($fileList) . ')</h2>' . $table(['الملف','الحجم','ملاحظات','المسار في الحزمة'],
    array_map(fn($f) => [$f['name'], formatSize($f['size']), $f['note'], $f['zip_path']], $fileList));
if ($missing) {
    $html .= '<h2>ملفات تعذّر تضمينها</h2>' . $table(['الملف','السبب'], array_map(fn($m) => [$m['name'], $m['error']], $missing));
}
$html .= '</body></html>';
$zip->addFromString('case_report.html', $html);
$zip->close();

if (function_exists('logAction')) logAction($conn, 'export', 'case', $cid, 'تصدير ملف القضية الكامل ' . $case['case_number']);

$dlName = 'case_' . preg_replace('/[^A-Za-z0-9_-]/', '', $case['case_number']) . '_' . $cid . '_' . date('Ymd') . '.zip';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipPath));
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($zipPath);
@unlink($zipPath);
exit;
