<?php
// api/upload-avatar.php
header("Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', 1);

require_once __DIR__ . '/../includes/ResponseHandler.php';
require_once __DIR__ . '/../includes/EnhancedProfile.php';

try {
    $profile = new EnhancedProfile();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            ResponseHandler::error('No file uploaded or upload error', 400);
        }
        
        $profile->uploadAvatar($_FILES['avatar']);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>