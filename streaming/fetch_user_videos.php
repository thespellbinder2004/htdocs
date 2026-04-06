<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once "db.php";

$userId = $_POST['user_id'] ?? null;
$authToken = $_POST['auth_token'] ?? null;
$sessionId = $_POST['session_id'] ?? null; // optional

if (!$userId || !$authToken) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing credentials."
    ]);
    exit();
}

try {
    // Verify user
    $userStmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE id = ?
          AND auth_token = ?
        LIMIT 1
    ");
    $userStmt->execute([$userId, $authToken]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "status" => "error",
            "message" => "Unauthorized."
        ]);
        exit();
    }

    if ($sessionId) {
        $stmt = $conn->prepare("
            SELECT 
                ev.id,
                ev.workout_session_id,
                ev.exercise_name,
                ev.file_name,
                ev.processing_status,
                ev.uploaded_at,
                ws.session_type
            FROM exercise_videos ev
            LEFT JOIN workout_sessions ws
                ON ws.id = ev.workout_session_id
            WHERE ev.user_id = ?
              AND ev.workout_session_id = ?
            ORDER BY ev.uploaded_at DESC
        ");
        $stmt->execute([$userId, $sessionId]);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                ev.id,
                ev.workout_session_id,
                ev.exercise_name,
                ev.file_name,
                ev.processing_status,
                ev.uploaded_at,
                ws.session_type
            FROM exercise_videos ev
            LEFT JOIN workout_sessions ws
                ON ws.id = ev.workout_session_id
            WHERE ev.user_id = ?
            ORDER BY ev.uploaded_at DESC
        ");
        $stmt->execute([$userId]);
    }

    $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($videos as &$video) {
        $video['stream_url'] =
            "https://bettergym.online/stream_video.php?user_id=" .
            urlencode($userId) .
            "&auth_token=" . urlencode($authToken) .
            "&video_id=" . urlencode($video['id']);
    }

    echo json_encode([
        "status" => "success",
        "videos" => $videos
    ]);

} catch (Exception $e) {
    error_log("FETCH USER VIDEOS ERROR: " . $e->getMessage());

    echo json_encode([
        "status" => "error",
        "message" => "Database error."
    ]);
}
?>