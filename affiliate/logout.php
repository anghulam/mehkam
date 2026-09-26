<?php
if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['affiliate_id']);
header('Location: login.php'); exit;
