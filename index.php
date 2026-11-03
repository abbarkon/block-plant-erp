<?php
/**
 * Application entry point.
 *
 * The real dashboard lives at dashboard/index.php. This front controller only
 * bootstraps the app and sends authenticated users to the dashboard.
 */
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/helpers.php";

require_login();
header('Location: /block_plant/dashboard/index.php');
exit;
