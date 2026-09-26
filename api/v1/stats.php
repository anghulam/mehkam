<?php
/**
 * مِحكام API v1 — الإحصائيات
 * GET /api/v1/stats.php → إحصائيات لوحة التحكم
 */
require_once __DIR__ . '/_init.php';

$oid    = api_authenticate($conn);
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') api_error(405, 'Method Not Allowed');

$stats = [];

// القضايا
$r = $conn->query("SELECT status, COUNT(*) cnt FROM cases WHERE office_id=$oid GROUP BY status");
$cases_by_status = [];
while ($row = $r->fetch_assoc()) $cases_by_status[$row['status']] = (int)$row['cnt'];
$stats['cases'] = [
    'total'     => array_sum($cases_by_status),
    'active'    => $cases_by_status['active']    ?? 0,
    'closed'    => $cases_by_status['closed']    ?? 0,
    'suspended' => $cases_by_status['suspended'] ?? 0,
];

// العملاء
$r = $conn->query("SELECT COUNT(*) c FROM clients WHERE office_id=$oid");
$stats['clients'] = ['total' => (int)$r->fetch_assoc()['c']];

// المالية
$stats['finance'] = [
    'total_income'  => (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND (direction='income' OR direction IS NULL)")->fetch_assoc()['s'],
    'total_expense' => (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND direction='expense'")->fetch_assoc()['s'],
    'pending'       => (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status IN('sent','overdue') AND (direction='income' OR direction IS NULL)")->fetch_assoc()['s'],
    'month_income'  => (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND (direction='income' OR direction IS NULL) AND MONTH(COALESCE(paid_date,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(paid_date,created_at))=YEAR(CURDATE())")->fetch_assoc()['s'],
];
$stats['finance']['net'] = $stats['finance']['total_income'] - $stats['finance']['total_expense'];

// الجلسات القادمة (7 أيام)
$sessions = [];
$r = $conn->query("SELECT c.id, c.case_number, c.title, c.next_session, cl.full_name client_name
    FROM cases c LEFT JOIN clients cl ON c.client_id=cl.id
    WHERE c.office_id=$oid AND c.next_session BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY c.next_session ASC LIMIT 10");
if ($r) while ($row = $r->fetch_assoc()) $sessions[] = $row;
$stats['upcoming_sessions'] = $sessions;

// الفواتير المتأخرة
$r = $conn->query("SELECT COUNT(*) c FROM invoices WHERE office_id=$oid AND status='overdue'");
$stats['overdue_invoices'] = (int)$r->fetch_assoc()['c'];

// المهام المعلقة
$r = $conn->query("SELECT COUNT(*) c FROM tasks WHERE office_id=$oid AND status='pending'");
$stats['pending_tasks'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

api_success($stats);
