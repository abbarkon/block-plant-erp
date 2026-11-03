<?php
/**
 * HR entry point for payroll.
 *
 * Payroll generation lives in finance/payroll.php (it also owns the attendance
 * approval gate). This file keeps the historical /hr/payroll.php URL working for
 * HR navigation, "View HR Payroll" buttons and older in-app notification links
 * that were stored pointing here.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_login();
require_permission('hr', 'view');

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/block_plant/finance/payroll.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target);
exit;
