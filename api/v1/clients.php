<?php
/**
 * مِحكام API v1 — العملاء
 * GET    /api/v1/clients.php          → قائمة العملاء
 * GET    /api/v1/clients.php?id=X     → عميل واحد
 * POST   /api/v1/clients.php          → إنشاء عميل
 * PUT    /api/v1/clients.php?id=X     → تعديل عميل
 * DELETE /api/v1/clients.php?id=X     → حذف عميل
 */
require_once __DIR__ . '/_init.php';

$oid    = api_authenticate($conn);
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ═══ GET ═══ */
if ($method === 'GET') {

    if ($id) {
        $r = $conn->query("SELECT * FROM clients WHERE id=$id AND office_id=$oid LIMIT 1");
        if (!$r || $r->num_rows === 0) api_error(404, 'العميل غير موجود');
        $client = $r->fetch_assoc();

        // قضايا العميل
        $cases = [];
        $cr = $conn->query("SELECT id,case_number,title,status FROM cases WHERE client_id=$id AND office_id=$oid ORDER BY created_at DESC");
        if ($cr) while ($row = $cr->fetch_assoc()) $cases[] = $row;
        $client['cases'] = $cases;

        api_success($client);
    }

    $search   = $conn->real_escape_string($_GET['q'] ?? '');
    $type_f   = $conn->real_escape_string($_GET['type'] ?? '');
    $page     = (int)($_GET['page'] ?? 1);
    $per_page = (int)($_GET['per_page'] ?? 20);

    $where = "office_id = $oid";
    if ($search) $where .= " AND (full_name LIKE '%$search%' OR phone LIKE '%$search%' OR id_number LIKE '%$search%')";
    if ($type_f) $where .= " AND client_type = '$type_f'";

    $sql = "SELECT id, full_name, phone, email, id_number AS national_id, city AS address, client_type, created_at FROM clients WHERE $where ORDER BY created_at DESC";
    api_success(api_paginate($conn, $sql, $page, $per_page));
}

/* ═══ POST ═══ */
if ($method === 'POST') {
    $b = api_body();

    $full_name   = $conn->real_escape_string(trim($b['full_name']   ?? ''));
    $phone       = $conn->real_escape_string(trim($b['phone']       ?? ''));
    $email       = $conn->real_escape_string(trim($b['email']       ?? ''));
    $national_id = $conn->real_escape_string(trim($b['national_id'] ?? ''));
    $client_type = in_array($b['client_type'] ?? '', ['individual','company']) ? $b['client_type'] : 'individual';
    $address     = $conn->real_escape_string($b['address']    ?? '');
    $notes       = $conn->real_escape_string($b['notes']      ?? '');
    $vat_number  = $conn->real_escape_string($b['vat_number'] ?? '');
    $cr_number   = $conn->real_escape_string($b['cr_number']  ?? '');

    if (!$full_name) api_error(422, 'الحقل "full_name" مطلوب');

    $conn->query("INSERT INTO clients (office_id,full_name,phone,email,id_number,client_type,city,notes,vat_number,cr_number)
        VALUES ($oid,'$full_name','$phone','$email','$national_id','$client_type','$address','$notes','$vat_number','$cr_number')");

    $new_id = $conn->insert_id;
    $r = $conn->query("SELECT * FROM clients WHERE id=$new_id");
    api_success($r->fetch_assoc(), 'تم إنشاء العميل بنجاح', 201);
}

/* ═══ PUT ═══ */
if ($method === 'PUT') {
    if (!$id) api_error(400, 'مطلوب id في الرابط');

    $chk = $conn->query("SELECT id FROM clients WHERE id=$id AND office_id=$oid LIMIT 1");
    if (!$chk || $chk->num_rows === 0) api_error(404, 'العميل غير موجود');

    $b    = api_body();
    $sets = [];

    // اسم الحقل في الـ API => اسم العمود في قاعدة البيانات
    $str_fields = [
        'full_name'   => 'full_name',
        'phone'       => 'phone',
        'email'       => 'email',
        'national_id' => 'id_number',
        'address'     => 'city',
        'notes'       => 'notes',
        'vat_number'  => 'vat_number',
        'cr_number'   => 'cr_number',
    ];
    foreach ($str_fields as $apiField => $col) {
        if (isset($b[$apiField])) $sets[] = "$col='".$conn->real_escape_string($b[$apiField])."'";
    }
    if (isset($b['client_type']) && in_array($b['client_type'], ['individual','company']))
        $sets[] = "client_type='".$b['client_type']."'";

    if (empty($sets)) api_error(422, 'لا توجد حقول للتعديل');

    $conn->query("UPDATE clients SET ".implode(',', $sets)." WHERE id=$id AND office_id=$oid");

    $r = $conn->query("SELECT * FROM clients WHERE id=$id");
    api_success($r->fetch_assoc(), 'تم تعديل العميل بنجاح');
}

/* ═══ DELETE ═══ */
if ($method === 'DELETE') {
    if (!$id) api_error(400, 'مطلوب id في الرابط');

    $chk = $conn->query("SELECT id FROM clients WHERE id=$id AND office_id=$oid LIMIT 1");
    if (!$chk || $chk->num_rows === 0) api_error(404, 'العميل غير موجود');

    $conn->query("DELETE FROM clients WHERE id=$id AND office_id=$oid");
    api_success(null, 'تم حذف العميل بنجاح');
}

api_error(405, 'Method Not Allowed');
