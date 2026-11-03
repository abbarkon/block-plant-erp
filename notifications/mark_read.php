<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
header('Content-Type: application/json');

$notificationId = (int) ($_GET['id'] ?? 0);

if($notificationId > 0) {
    // Verify the notification belongs to the current user
    $stmt = $pdo->prepare("SELECT user_id FROM user_notifications WHERE id = ?");
    $stmt->execute([$notificationId]);
    $notification = $stmt->fetch();
    
    if($notification && $notification['user_id'] == $_SESSION['user_id']) {
        mark_notification_read($notificationId);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Notification not found or access denied']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
}
