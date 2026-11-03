<?php
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../crm/interactions.php' . ($q ? '?' . $q : ''));
exit;
