<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);

require_once "db.php";

// Optional CORS if Flutter/web needs it
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Accept GET or POST
$userId = $_REQUEST['user_id'] ?? null;
$authToken = $_REQUEST['auth_token'] ?? null;
$videoId = $_REQUEST['video_id'] ?? null;

if (empty($userId) || empty($authToken) || empty($videoId)) {
    http_response_code(400);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "status" => "error",
        "message" => "Missing required parameters."
    ]);
    exit();
}

try {
    // 1. Verify user
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
        http_response_code(401);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "status" => "error",
            "message" => "Unauthorized."
        ]);
        exit();
    }

    // 2. Get video and make sure it belongs to this user
    $videoStmt = $conn->prepare("
        SELECT 
            ev.id,
            ev.user_id,
            ev.exercise_name,
            ev.file_name,
            ev.file_path,
            ev.processing_status,
            ev.uploaded_at,
            ws.session_type
        FROM exercise_videos ev
        LEFT JOIN workout_sessions ws
            ON ws.id = ev.workout_session_id
        WHERE ev.id = ?
          AND ev.user_id = ?
        LIMIT 1
    ");
    $videoStmt->execute([$videoId, $userId]);
    $video = $videoStmt->fetch(PDO::FETCH_ASSOC);

    if (!$video) {
        http_response_code(404);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "status" => "error",
            "message" => "Video not found."
        ]);
        exit();
    }

    $filePath = $video['file_path'];

    if (empty($filePath) || !file_exists($filePath)) {
        http_response_code(404);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "status" => "error",
            "message" => "Video file missing on server."
        ]);
        exit();
    }

    $fileSize = filesize($filePath);
    $mimeType = mime_content_type($filePath);

    if (!$mimeType) {
        $mimeType = 'video/mp4';
    }

    $start = 0;
    $end = $fileSize - 1;

    header("Content-Type: " . $mimeType);
    header("Accept-Ranges: bytes");
    header('Content-Disposition: inline; filename="' . basename($video['file_name']) . '"');

    // 3. Support range requests for video seeking
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = intval($matches[1]);
            if ($matches[2] !== '') {
                $end = intval($matches[2]);
            }
        }

        if ($start > $end || $end >= $fileSize) {
            header("HTTP/1.1 416 Range Not Satisfiable");
            header("Content-Range: bytes */$fileSize");
            exit();
        }

        $length = $end - $start + 1;

        header("HTTP/1.1 206 Partial Content");
        header("Content-Length: " . $length);
        header("Content-Range: bytes $start-$end/$fileSize");

        $fp = fopen($filePath, 'rb');
        fseek($fp, $start);

        $bufferSize = 8192;
        $bytesLeft = $length;

        while (!feof($fp) && $bytesLeft > 0) {
            $read = ($bytesLeft > $bufferSize) ? $bufferSize : $bytesLeft;
            $data = fread($fp, $read);
            echo $data;
            flush();
            $bytesLeft -= strlen($data);
        }

        fclose($fp);
        exit();
    }

    // 4. Full file response
    header("Content-Length: " . $fileSize);
    readfile($filePath);
    exit();

} catch (Exception $e) {
    error_log("STREAM VIDEO ERROR: " . $e->getMessage());
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "status" => "error",
        "message" => "Server error."
    ]);
    exit();
}
?>