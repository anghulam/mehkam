<?php
if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['portal_client_id'], $_SESSION['portal_office_id']);
session_destroy();
header('Location: login.php'); exit;
