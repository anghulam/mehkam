<?php
/**
 * مِحكام API v1 — القضايا
 * GET    /api/v1/cases.php          → قائمة القضايا
 * GET    /api/v1/cases.php?id=X     → قضية واحدة
 * POST   /api/v1/cases.php          → إنشاء قضية
 * PUT    /api/v1/cases.php?id=X     → تعديل قضية
 * DELETE /api/v1/cases.php?id=X     → حذف قضية
 */
require_once __DIR__ . '/_init.php';

$oid    = api_authenticate($conn);
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ═══ GET ═══ */
if ($method === 'GET') {

    if ($id) {
        // قضية واحدة
        $r = $conn->query("
            SELECT c.*, cl.full_name client_name, cl.phone client_phone
            FROM cases c
            LEFT JOIN clients cl ON c.client_id = cl.id
            WHERE c.id = $id AND c.office_id = $oid
            LIMIT 1
        ");
        if (!$r || $r->num_rows === 0) api_error(404, 'القضية غير موجودة');
        api_success($r->fetch_assoc());
    }

    // قائمة
    $status_f = $conn->real_escape_string($_GET['status'] ?? '');
    $search   = $conn->real_escape_string($_GET['q'] ?? '');
    $page     = (int)($_GET['page'] ?? 1);
    $per_page = (int)($_GET['per_page'] ?? 20);

    $where = "c.office_id = $oid";
    if ($status_f) $where .= " AND c.status = '$status_f'";
    if ($search)   $where .= " AND (c.case_number LIKE '%$search%' OR c.title LIKE '%$search%')";

    $sql = "
        SELECT c.id, c.case_number, c.title, c.court_name AS court, c.status, c.fees,
               c.next_session, c.created_at,
               cl.full_name client_name
        FROM cases c
        LEFT JOIN clients cl ON c.client_id = cl.id
        WHERE $where
        ORDER BY c.created_at DESC
    ";

    api_success(api_paginate($conn, $sql, $page, $per_page));
}

/* ═══ POST ═══ */
if ($method === 'POST') {
    $b = api_body();

    $case_number = $conn->real_escape_string(trim($b['case_number'] ?? ''));
    $title       = $conn->real_escape_string(trim($b['title']       ?? ''));
    $court       = $conn->real_escape_string(trim($b['court']       ?? ''));
    $status      = in_array($b['status'] ?? '', ['active','closed','suspended']) ? $b['status'] : 'active';
    $client_id   = isset($b['client_id']) ? (int)$b['client_id'] : 'NULL';
    $fees        = isset($b['fees'])      ? (float)$b['fees']    : 0;
    $next_session = $conn->real_escape_string($b['next_session'] ?? '');
    $notes       = $conn->real_escape_string($b['notes'] ?? '');

    if (!$title) api_error(422, 'الحقل "title" مطلوب');

    // التحقق من client_id ينتمي للمكتب
    if ($client_id !== 'NULL') {
        $chk = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid LIMIT 1");
        if (!$chk || $chk->num_rows === 0) api_error(422, 'client_id غير موجود في هذا المكتب');
    }

    $ns_sql = $next_session ? "'$next_session'" : 'NULL';
    $conn->query("INSERT INTO cases (office_id,case_number,title,court_name,status,client_id,fees,next_session,notes)
        VALUES ($oid,'$case_number','$title','$court','$status',$client_id,$fees,$ns_sql,'$notes')");

    $new_id = $conn->insert_id;
    $r = $conn->query("SELECT * FROM cases WHERE id=$new_id");
    api_success($r->fetch_assoc(), 'تم إنشاء القضية بنجاح', 201);
}

/* ═══ PUT ═══ */
if ($method === 'PUT') {
    if (!$id) api_error(400, 'مطلوب id في الرابط');

    $chk = $conn->query("SELECT id FROM cases WHERE id=$id AND office_id=$oid LIMIT 1");
    if (!$chk || $chk->num_rows === 0) api_error(404, 'القضية غير موجودة');

    $b = api_body();
    $sets = [];

    if (isset($b['case_number'])) $sets[] = "case_number='".$conn->real_escape_string($b['case_number'])."'";
    if (isset($b['title']))       $sets[] = "title='"      .$conn->real_escape_string($b['title'])."'";
    if (isset($b['court']))       $sets[] = "court_name='" .$conn->real_escape_string($b['court'])."'";
    if (isset($b['notes']))       $sets[] = "notes='"      .$conn->real_escape_string($b['notes'])."'";
    if (isset($b['fees']))        $sets[] = "fees=".(float)$b['fees'];
    if (isset($b['status']) && in_array($b['status'], ['active','closed','suspended']))
        $sets[] = "status='".$b['status']."'";
    if (isset($b['next_session']))
        $sets[] = "next_session=".($b['next_session'] ? "'".$conn->real_escape_string($b['next_session'])."'" : 'NULL');

    if (empty($sets)) api_error(422, 'لا توجد حقول للتعديل');

    $conn->query("UPDATE cases SET ".implode(',', $sets)." WHERE id=$id AND office_id=$oid");

    $r = $conn->query("SELECT * FROM cases WHERE id=$id");
    api_success($r->fetch_assoc(), 'تم تعديل القضية بنجاح');
}

/* ═══ DELETE ═══ */
if ($method === 'DELETE') {
    if (!$id) api_error(400, 'مطلوب id في الرابط');

    $chk = $conn->query("SELECT id FROM cases WHERE id=$id AND office_id=$oid LIMIT 1");
    if (!$chk || $chk->num_rows === 0) api_error(404, 'القضية غير موجودة');

    $conn->query("DELETE FROM cases WHERE id=$id AND office_id=$oid");
    api_success(null, 'تم حذف القضية بنجاح');
}

api_error(405, 'Method Not Allowed');
