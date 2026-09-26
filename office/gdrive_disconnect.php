<?php
/**
 * office/gdrive_disconnect.php
 * يفصل ربط Google Drive عن المكتب ويلغي التوكن عند Google.
 * الملفات المرفوعة سابقاً تبقى في درايف المكتب (لا تُحذف).
 */
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/gdrive_helper.php';
requireOffice();

$oid = (int)($_SESSION['office_id'] ?? 0);
if (currentRole() !== 'office_owner') { header('Location: profile.php?tab=office&msg=owner_only'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $r = $conn->query("SELECT gdrive_refresh_token FROM office_settings WHERE office_id=$oid LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row && !empty($row['gdrive_refresh_token'])) {
            gd_oauth_revoke($row['gdrive_refresh_token']);
        }
    } catch (\Throwable $e) {}

    $conn->query("UPDATE office_settings SET
        storage_driver='server',
        gdrive_refresh_token=NULL,
        gdrive_access_token=NULL,
        gdrive_token_expiry=0,
        gdrive_root_folder_id=NULL,
        gdrive_email=NULL
        WHERE office_id=$oid");

    header('Location: profile.php?tab=office&msg=gd_disconnected'); exit;
}

header('Location: profile.php?tab=office'); exit;
