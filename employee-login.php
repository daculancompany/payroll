<?php
// Employee login is now unified with the main login page (Option A).
// Keep this URL working for old bookmarks / links → redirect appropriately.
session_start();
if (isset($_SESSION['emp_is_login']) && $_SESSION['emp_is_login']) {
    header('location:employee-portal.php'); exit;
}
if (isset($_SESSION['is_login']) && $_SESSION['is_login']) {
    header('location:index.php?page=home'); exit;
}
header('location:login.php');
exit;
