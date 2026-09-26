<?php
/**
 * office/gdrive_connect.php
 * يبدأ ربط حساب Google Drive الخاص بالمكتب (OAuth).
 */
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/gdrive_helper.php';
requireOffice();

if (currentRole() !== 'office_owner') {
    header('Location: profile.php?tab=office&msg=owner_only'); exit;
}
if (!gd_oauth_ready()) {
    header('Location: profile.php?tab=office&msg=gd_noconfig'); exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['gdrive_oauth_state'] = $state;

header('Location: ' . gd_oauth_auth_url($state));
exit;
