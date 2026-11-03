<?php
session_start();
require_once "../config/database.php";
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('[]');
}
header('Content-Type: application/json');
$sid = (int) ($_GET['supplier_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, purchase_no, balance FROM purchases WHERE supplier_id=? AND approval_status='approved' AND balance>0 ORDER BY purchase_date");
$stmt->execute([$sid]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
