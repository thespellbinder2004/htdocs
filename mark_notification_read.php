<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data["auth_token"]) || empty($data["user_id"]) || empty($data["notification_id"])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing required fields."
    ]);
    exit();
}

$authToken = $data["auth_token"];
$userId = (int)$data["user_id"];
$notificationId = (int)$data["notification_id"];

try {
    $stmtUser = $conn->prepare("
        SELECT id
        FROM users
        WHERE id = ? AND auth_token = ?
        LIMIT 1
    ");
    $stmtUser->execute([$userId, $authToken]);

    if (!$stmtUser->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(401);
        echo json_encode([
            "status" => "error",
            "message" => "Unauthorized."
        ]);
        exit();
    }

    $stmtUpdate = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = ? AND user_id = ?
    ");
    $stmtUpdate->execute([$notificationId, $userId]);

    echo json_encode([
        "status" => "success",
        "message" => "Notification marked as read."
    ]);
    exit();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to update notification.",
        "debug" => $e->getMessage()
    ]);
    exit();
}
?>