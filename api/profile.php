<?php
// Enable CORS
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files
require_once __DIR__ . '/../includes/ResponseHandler.php';
require_once __DIR__ . '/../includes/Profile.php';

try {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check authentication
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $profile = new Profile();
    
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetRequest($profile, $userId);
            break;
            
        case 'POST':
            handlePostRequest($profile, $userId);
            break;
            
        case 'PUT':
            handlePutRequest($profile, $userId);
            break;
            
        case 'DELETE':
            handleDeleteRequest($profile, $userId);
            break;
            
        default:
            ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

function handleGetRequest($profile, $userId) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'orders':
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $status = $_GET['status'] ?? '';
            $profile->getUserOrders($userId, $page, $limit, $status);
            break;
            
        case 'addresses':
            $profile->getUserAddresses($userId);
            break;
            
        case 'payment_methods':
            $profile->getPaymentMethods($userId);
            break;
            
        case 'favorites':
            $profile->getFavorites($userId);
            break;
            
        case 'wallet_transactions':
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $profile->getWalletTransactions($userId, $page, $limit);
            break;
            
        case 'notifications':
            $profile->getNotificationSettings($userId);
            break;
            
        default:
            $profile->getUserProfile($userId);
    }
}

function handlePostRequest($profile, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ResponseHandler::error('Invalid JSON input', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'add_address':
            $profile->addAddress($userId, $input);
            break;
            
        case 'add_payment_method':
            $profile->addPaymentMethod($userId, $input);
            break;
            
        case 'add_favorite':
            $profile->addFavorite($userId, $input);
            break;
            
        case 'topup_wallet':
            $profile->topupWallet($userId, $input);
            break;
            
        case 'upload_avatar':
            $profile->uploadAvatar($userId);
            break;
            
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

function handlePutRequest($profile, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ResponseHandler::error('Invalid JSON input', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            $profile->updateUserProfile($userId, $input);
            break;
            
        case 'update_address':
            $profile->updateAddress($userId, $input);
            break;
            
        case 'update_notifications':
            $profile->updateNotificationSettings($userId, $input);
            break;
            
        case 'set_default_payment':
            $profile->setDefaultPaymentMethod($userId, $input);
            break;
            
        case 'set_default_address':
            $profile->setDefaultAddress($userId, $input);
            break;
            
        default:
            ResponseHandler::error('Invalid action', 405);
    }
}

function handleDeleteRequest($profile, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ResponseHandler::error('Invalid JSON input', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'delete_address':
            $profile->deleteAddress($userId, $input);
            break;
            
        case 'delete_payment_method':
            $profile->deletePaymentMethod($userId, $input);
            break;
            
        case 'remove_favorite':
            $profile->removeFavorite($userId, $input);
            break;
            
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}
?>