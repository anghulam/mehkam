<?php
/**
 * مِحكام API v1 — مساعد التهيئة والمصادقة
 */

require_once __DIR__ . '/../../config/db.php';

/* ── CORS & JSON headers ── */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

/* ── جدول المفاتيح ── */
$conn->query("CREATE TABLE IF NOT EXISTS api_keys (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    office_id   INT NOT NULL,
    key_name    VARCHAR(100) DEFAULT 'مفتاح API',
    api_key     VARCHAR(64)  NOT NULL UNIQUE,
    permissions JSON         DEFAULT NULL,
    is_active   TINYINT(1)   DEFAULT 1,
    last_used   DATETIME     DEFAULT NULL,
    requests_count INT       DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX (office_id),
    INDEX (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── Helpers ── */

function api_response($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $code, string $message): void {
    api_response(['success' => false, 'error' => $message], $code);
}

function api_success($data, string $message = 'ok', int $code = 200): void {
    api_response(['success' => true, 'message' => $message, 'data' => $data], $code);
}

function api_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function api_authenticate($conn): int {
    $key = '';

    // 1. Authorization: Bearer <key>
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (str_starts_with($auth, 'Bearer ')) {
        $key = trim(substr($auth, 7));
    }

    // 2. X-API-Key header
    if (!$key) {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    }

    // 3. Query param (fallback)
    if (!$key && isset($_GET['api_key'])) {
        $key = trim($_GET['api_key']);
    }

    if (!$key) {
        api_error(401, 'مطلوب مفتاح API — أرسله في الهيدر: X-API-Key أو Authorization: Bearer <key>');
    }

    $key_e = $conn->real_escape_string($key);
    $r = $conn->query("
        SELECT ak.office_id, ak.id AS key_id, o.status
        FROM api_keys ak
        JOIN offices o ON ak.office_id = o.id
        WHERE ak.api_key = '$key_e' AND ak.is_active = 1
        LIMIT 1
    ");

    if (!$r || $r->num_rows === 0) {
        api_error(401, 'مفتاح API غير صحيح أو غير نشط');
    }

    $row = $r->fetch_assoc();
    if (in_array($row['status'], ['suspended','inactive'])) {
        api_error(403, 'حساب المكتب معلق أو غير نشط');
    }

    // تحديث آخر استخدام وعداد الطلبات
    $kid = (int)$row['key_id'];
    $conn->query("UPDATE api_keys SET last_used=NOW(), requests_count=requests_count+1 WHERE id=$kid");

    return (int)$row['office_id'];
}

function api_paginate($conn, string $sql, int $page = 1, int $per_page = 20): array {
    $page     = max(1, $page);
    $per_page = min(100, max(1, $per_page));
    $offset   = ($page - 1) * $per_page;

    // count
    $count_sql = preg_replace('/SELECT .+? FROM /is', 'SELECT COUNT(*) c FROM ', $sql, 1);
    $count_sql = preg_replace('/ORDER BY.+$/is', '', $count_sql);
    $cnt_r = $conn->query($count_sql);
    $total = $cnt_r ? (int)$cnt_r->fetch_assoc()['c'] : 0;

    $rows = [];
    $res  = $conn->query($sql . " LIMIT $per_page OFFSET $offset");
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;

    return [
        'items'      => $rows,
        'pagination' => [
            'page'       => $page,
            'per_page'   => $per_page,
            'total'      => $total,
            'last_page'  => (int)ceil($total / $per_page),
        ],
    ];
}
