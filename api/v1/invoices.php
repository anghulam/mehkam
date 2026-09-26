<?php
/**
 * مِحكام API v1 — الفواتير
 * GET  /api/v1/invoices.php         → قائمة الفواتير
 * GET  /api/v1/invoices.php?id=X    → فاتورة واحدة
 * POST /api/v1/invoices.php         → إنشاء فاتورة
 * PUT  /api/v1/invoices.php?id=X    → تحديث حالة الفاتورة
 */
require_once __DIR__ . '/_init.php';

$oid    = api_authenticate($conn);
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ═══ GET ═══ */
if ($method === 'GET') {

    if ($id) {
        $r = $conn->query("
            SELECT inv.*, cl.full_name client_name, ca.case_number
            FROM invoices inv
            LEFT JOIN clients cl ON inv.client_id = cl.id
            LEFT JOIN cases   ca ON inv.case_id   = ca.id
            WHERE inv.id = $id AND inv.office_id = $oid
            LIMIT 1
        ");
        if (!$r || $r->num_rows === 0) api_error(404, 'الفاتورة غير موجودة');
        $inv = $r->fetch_assoc();
        if ($inv['items']) $inv['items'] = json_decode($inv['items'], true);
        api_success($inv);
    }

    $status_f = $conn->real_escape_string($_GET['status']    ?? '');
    $dir_f    = $conn->real_escape_string($_GET['direction'] ?? '');
    $from     = $conn->real_escape_string($_GET['from']      ?? '');
    $to       = $conn->real_escape_string($_GET['to']        ?? '');
    $page     = (int)($_GET['page']     ?? 1);
    $per_page = (int)($_GET['per_page'] ?? 20);

    $where = "inv.office_id = $oid";
    if ($status_f) $where .= " AND inv.status = '$status_f'";
    if ($dir_f)    $where .= " AND inv.direction = '$dir_f'";
    if ($from)     $where .= " AND COALESCE(inv.paid_date,inv.created_at) >= '$from'";
    if ($to)       $where .= " AND COALESCE(inv.paid_date,inv.created_at) <= '$to'";

    $sql = "
        SELECT inv.id, inv.invoice_number, inv.title, inv.status, inv.direction,
               inv.total, inv.tax_amount, inv.due_date, inv.paid_date, inv.created_at,
               cl.full_name client_name, ca.case_number
        FROM invoices inv
        LEFT JOIN clients cl ON inv.client_id = cl.id
        LEFT JOIN cases   ca ON inv.case_id   = ca.id
        WHERE $where
        ORDER BY inv.created_at DESC
    ";

    api_success(api_paginate($conn, $sql, $page, $per_page));
}

/* ═══ POST ═══ */
if ($method === 'POST') {
    $b = api_body();

    $title      = $conn->real_escape_string(trim($b['title']      ?? ''));
    $client_id  = isset($b['client_id']) ? (int)$b['client_id'] : 'NULL';
    $case_id    = isset($b['case_id'])   ? (int)$b['case_id']   : 'NULL';
    $direction  = ($b['direction'] ?? 'income') === 'expense' ? 'expense' : 'income';
    $status     = in_array($b['status'] ?? '', ['draft','sent','paid']) ? $b['status'] : 'draft';
    $tax_rate   = isset($b['tax_rate']) ? (float)$b['tax_rate'] : 15.00;
    $due_date   = $conn->real_escape_string($b['due_date'] ?? '');
    $notes      = $conn->real_escape_string($b['notes']    ?? '');
    $items      = $b['items'] ?? [];

    if (!$title)          api_error(422, 'الحقل "title" مطلوب');
    if (empty($items))    api_error(422, 'الحقل "items" مطلوب — مصفوفة بنود الفاتورة');
    if (!is_array($items)) api_error(422, '"items" يجب أن يكون مصفوفة');

    // حساب المبالغ
    $subtotal = 0;
    foreach ($items as &$item) {
        $item['qty']        = (float)($item['qty']   ?? 1);
        $item['unit_price'] = (float)($item['unit_price'] ?? 0);
        $item['total']      = $item['qty'] * $item['unit_price'];
        $subtotal += $item['total'];
    }
    $tax_amount = round($subtotal * $tax_rate / 100, 2);
    $total      = round($subtotal + $tax_amount, 2);

    // رقم الفاتورة
    $settings = $conn->query("SELECT invoice_prefix FROM office_settings WHERE office_id=$oid LIMIT 1")->fetch_assoc();
    $prefix   = $settings['invoice_prefix'] ?? 'INV';
    $last_num = $conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)) n FROM invoices WHERE office_id=$oid")->fetch_assoc()['n'] ?? 0;
    $inv_num  = $conn->real_escape_string($prefix . '-' . str_pad((int)$last_num + 1, 4, '0', STR_PAD_LEFT));

    $items_json = $conn->real_escape_string(json_encode($items, JSON_UNESCAPED_UNICODE));
    $dd_sql = $due_date ? "'$due_date'" : 'NULL';

    $conn->query("INSERT INTO invoices (office_id,client_id,case_id,invoice_number,title,items,subtotal,tax_rate,tax_amount,total,status,direction,due_date,notes)
        VALUES ($oid,$client_id,$case_id,'$inv_num','$title','$items_json',$subtotal,$tax_rate,$tax_amount,$total,'$status','$direction',$dd_sql,'$notes')");

    $new_id = $conn->insert_id;
    $r = $conn->query("SELECT * FROM invoices WHERE id=$new_id");
    $inv = $r->fetch_assoc();
    if ($inv['items']) $inv['items'] = json_decode($inv['items'], true);
    api_success($inv, 'تم إنشاء الفاتورة بنجاح', 201);
}

/* ═══ PUT ═══ */
if ($method === 'PUT') {
    if (!$id) api_error(400, 'مطلوب id في الرابط');

    $chk = $conn->query("SELECT id FROM invoices WHERE id=$id AND office_id=$oid LIMIT 1");
    if (!$chk || $chk->num_rows === 0) api_error(404, 'الفاتورة غير موجودة');

    $b    = api_body();
    $sets = [];

    $allowed_statuses = ['draft','sent','paid','overdue','cancelled'];
    if (isset($b['status']) && in_array($b['status'], $allowed_statuses)) {
        $sets[] = "status='".$b['status']."'";
        if ($b['status'] === 'paid') {
            $pd = $conn->real_escape_string($b['paid_date'] ?? date('Y-m-d'));
            $sets[] = "paid_date='$pd'";
        }
    }
    if (isset($b['notes']))    $sets[] = "notes='".$conn->real_escape_string($b['notes'])."'";
    if (isset($b['due_date'])) $sets[] = "due_date='".$conn->real_escape_string($b['due_date'])."'";

    if (empty($sets)) api_error(422, 'لا توجد حقول للتعديل — المتاح: status, notes, due_date, paid_date');

    $conn->query("UPDATE invoices SET ".implode(',', $sets)." WHERE id=$id AND office_id=$oid");

    $r = $conn->query("SELECT * FROM invoices WHERE id=$id");
    $inv = $r->fetch_assoc();
    if ($inv['items']) $inv['items'] = json_decode($inv['items'], true);
    api_success($inv, 'تم تعديل الفاتورة بنجاح');
}

api_error(405, 'Method Not Allowed');
