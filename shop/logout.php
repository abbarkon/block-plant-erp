<?php
require_once __DIR__ . '/bootstrap.php';
unset($_SESSION['shop_account_id'], $_SESSION['shop_customer_id'], $_SESSION['shop_login_attempts']);
session_regenerate_id(true);
shop_flash('success', shop_t('You have been logged out.'));
shop_redirect('index.php');
