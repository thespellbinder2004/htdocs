<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once "db.php";

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$sessionId = $_GET['session_id'] ?? '';
$exerciseName = $_GET['exercise_name'] ?? '';
$authToken = $_GET['auth_token'] ?? '';

if ($userId <= 0 || empty($sessionId) || empty($exerciseName) || empty($authToken)) {
    http_response_code(400);
    exit("Missing required parameters.");
}

try {
    // 1. Verify token belongs to this user
    $stmtUser = $conn->prepare("
        SELECT id
        FROM users
        WHERE id = ? AND auth_token = ?
        LIMIT 1
    ");
    $stmtUser->execute([$userId, $authToken]);
    $verifiedUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$verifiedUser) {
        http_response_code(401);
        exit("Unauthorized.");
    }

    // 2. Get saved video path
    $stmtVideo = $conn->prepare("
        SELECT file_path
        FROM processed_videos
        WHERE user_id = ?
          AND session_id = ?
          AND exercise_name = ?
          AND model_status = 'done'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtVideo->execute([$userId, $sessionId, $exerciseName]);
    $video = $stmtVideo->fetch(PDO::FETCH_ASSOC);

    if (!$video || empty($video['file_path'])) {
        http_response_code(404);
        exit("Video not found.");
    }

    // file_path should be relative, like:
    // user_20/session123/squat/video_out.mp4
    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $video['file_path']);
    $baseDir = "D:\\xampp\\.uploads\\uploads\\ai_videos";
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $relativePath;

    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        exit("Video file missing.");
    }

    // Extra safety: path must belong to the requesting user
    if (stripos($fullPath, "user_" . $userId) === false) {
        http_response_code(403);
        exit("Access denied.");
    }

    $fileSize = filesize($fullPath);
    $start = 0;
    $end = $fileSize - 1;

    header("Content-Type: video/mp4");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            if ($matches[1] !== '') $start = (int)$matches[1];
            if ($matches[2] !== '') $end = (int)$matches[2];
        }

        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            header("HTTP/1.1 416 Range Not Satisfiable");
            header("Content-Range: bytes */$fileSize");
            exit;
        }

        $length = $end - $start + 1;

        header("HTTP/1.1 206 Partial Content");
        header("Content-Length: $length");
        header("Content-Range: bytes $start-$end/$fileSize");

        $fp = fopen($fullPath, 'rb');
        fseek($fp, $start);

        while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
            $remaining = $end - $pos + 1;
            echo fread($fp, min(8192, $remaining));
            flush();
        }

        fclose($fp);
        exit;
    }

    header("Content-Length: " . $fileSize);
    readfile($fullPath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    exit("Server error.");
}