<?php
header('Content-Type: application/json');

// OPTIONAL: check Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if ($authHeader) {
    // Example: Bearer token check (optional)
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid token format"]);
        exit;
    }
}

// If everything is fine
http_response_code(200);
echo json_encode([
    "status" => "ok",
    "server_time" => date("Y-m-d H:i:s")
]);