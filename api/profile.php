<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * BASE URL CONFIGURATION - ADD THIS
 *********************************/
$baseUrl = "https://dropx-production-6373.up.railway.app";

/*********************************
 * GENERATE 5-DIGIT ACCOUNT NUMBER
 *********************************/
function generateAccountNumber($conn) {
    $maxAttempts = 100;
    
    // First, try to use sequential numbers starting from 10000
    $stmt = $conn->query("SELECT MAX(CAST(account_number AS UNSIGNED)) as max_number FROM users WHERE account_number REGEXP '^[0-9]{5}$'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $startNumber = 10000;
    $nextNumber = ($result['max_number'] ?? ($startNumber - 1)) + 1;
    
    // Ensure it's within 5-digit range (10000-99999)
    if ($nextNumber >= 10000 && $nextNumber <= 99999) {
        $accountNumber = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        
        // Check if exists
        $check = $conn->prepare("SELECT id FROM users WHERE account_number = :account_number");
        $check->execute([':account_number' => $accountNumber]);
        
        if ($check->rowCount() === 0) {
            return $accountNumber;
        }
    }
    
    // If sequential fails, try random 5-digit numbers
    for ($i = 0; $i < $maxAttempts; $i++) {
        // Generate random number between 10000 and 99999
        $randomNumber = mt_rand(10000, 99999);
        $accountNumber = str_pad($randomNumber, 5, '0', STR_PAD_LEFT);
        
        // Check if exists
        $check = $conn->prepare("SELECT id FROM users WHERE account_number = :account_number");
        $check->execute([':account_number' => $accountNumber]);
        
        if ($check->rowCount() === 0) {
            return $accountNumber;
        }
    }
    
    // Last resort: use timestamp (get last 5 digits)
    $timestamp = time();
    $accountNumber = substr($timestamp, -5);
    $accountNumber = str_pad($accountNumber, 5, '0', STR_PAD_LEFT);
    
    // Ensure it's >= 10000
    if ((int)$accountNumber < 10000) {
        $accountNumber = str_pad((int)$accountNumber + 10000, 5, '0', STR_PAD_LEFT);
    }
    
    return $accountNumber;
}

/*********************************
 * HELPER FUNCTION FOR BASE URL - ADD THIS
 *********************************/
function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest();
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET: USER PROFILE & STATS
 *********************************/
function handleGetRequest() {
    $db = new Database();
    $conn = $db->getConnection();
    $baseUrl = getBaseUrl(); // Get the base URL

    // Check which endpoint is requested
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathSegments = explode('/', trim($path, '/'));
    
    if (end($pathSegments) === 'stats') {
        handleGetUserStats($conn);
    } else {
        handleGetProfile($conn, $baseUrl); // Pass baseUrl
    }
}

function handleGetProfile($conn, $baseUrl) { // Accept baseUrl parameter
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $stmt = $conn->prepare(
        "SELECT id, account_number, full_name, email, phone, address, city, gender, avatar,
                wallet_balance, member_level, member_points, total_orders,
                rating, verified, member_since, 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as updated_at,
                date_of_birth, location
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('User not found', 404);
    }

    ResponseHandler::success([
        'user' => formatUserData($user, $baseUrl) // Pass baseUrl
    ]);
}

function handleGetUserStats($conn) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    // Get user stats
    $stmt = $conn->prepare(
        "SELECT 
            member_points,
            total_orders,
            wallet_balance,
            (SELECT COUNT(*) FROM orders WHERE user_id = :user_id) as total_order_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = :user_id) as total_spent,
            (SELECT COALESCE(AVG(total_amount), 0) FROM orders WHERE user_id = :user_id) as avg_order_value,
            (SELECT MAX(created_at) FROM orders WHERE user_id = :user_id) as last_order_date
         FROM users WHERE id = :user_id"
    );
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'stats' => [
            'member_points' => (int) ($stats['member_points'] ?? 0),
            'total_orders' => (int) ($stats['total_order_count'] ?? 0),
            'total_spent' => (float) ($stats['total_spent'] ?? 0),
            'avg_order_value' => (float) ($stats['avg_order_value'] ?? 0),
            'last_order_date' => $stats['last_order_date'] ? formatDateForFlutter($stats['last_order_date']) : null
        ]
    ]);
}

/*********************************
 * POST ROUTER
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();
    $baseUrl = getBaseUrl(); // Get the base URL

    // Check which endpoint is requested
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathSegments = explode('/', trim($path, '/'));
    $endpoint = end($pathSegments);

    if ($endpoint === 'upload-avatar') {
        uploadAvatar($conn, $baseUrl); // Pass baseUrl
    } elseif ($endpoint === 'remove-avatar') {
        removeAvatar($conn);
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'login':
                loginUser($conn, $input, $baseUrl); // Pass baseUrl
                break;
            case 'register':
                registerUser($conn, $input, $baseUrl); // Pass baseUrl
                break;
            case 'update_profile':
                updateProfile($conn, $input, $baseUrl); // Pass baseUrl
                break;
            case 'change_password':
                changePassword($conn, $input);
                break;
            case 'forgot_password':
                forgotPassword($conn, $input);
                break;
            case 'logout':
                logoutUser();
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    }
}

/*********************************
 * LOGIN
 *********************************/
function loginUser($conn, $data, $baseUrl) { // Accept baseUrl
    $identifier = trim($data['identifier'] ?? '');
    $password = $data['password'] ?? '';
    $rememberMe = $data['remember_me'] ?? false;

    if (!$identifier || !$password) {
        ResponseHandler::error('Email/phone and password required', 400);
    }

    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        $phone = cleanPhoneNumber($identifier);
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('Invalid credentials', 401);
    }
    
    if (!password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;

    // Update last login
    $conn->prepare(
        "UPDATE users SET last_login = NOW() WHERE id = :id"
    )->execute([':id' => $user['id']]);

    // Remove password from response
    unset($user['password']);

    ResponseHandler::success([
        'user' => formatUserData($user, $baseUrl) // Pass baseUrl
    ], 'Login successful');
}

/*********************************
 * REGISTER WITH 5-DIGIT ACCOUNT NUMBER
 *********************************/
function registerUser($conn, $data, $baseUrl) { // Accept baseUrl
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $password = $data['password'] ?? '';
    $address = trim($data['address'] ?? '');
    $city = trim($data['city'] ?? '');
    $gender = $data['gender'] ?? null;

    // Validation
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    if (!$email) {
        ResponseHandler::error('Email address is required', 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Enter a valid email address', 400);
    }
    
    if ($phone && strlen($phone) < 10) {
        ResponseHandler::error('Enter a valid phone number', 400);
    }
    
    if (!$password) {
        ResponseHandler::error('Password is required', 400);
    }
    
    if (strlen($password) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    // Check if user already exists
    $checkSql = "SELECT id FROM users WHERE email = :email";
    $params = [':email' => $email];
    
    if ($phone) {
        $checkSql .= " OR phone = :phone";
        $params[':phone'] = $phone;
    }
    
    $check = $conn->prepare($checkSql);
    $check->execute($params);
    
    if ($check->rowCount() > 0) {
        ResponseHandler::error('User already exists with this email or phone', 409);
    }

    // Generate 5-digit account number
    $accountNumber = generateAccountNumber($conn);

    // Create user
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, phone, password, address, city, gender, 
                           wallet_balance, member_level, member_points, total_orders, 
                           rating, verified, member_since, account_number, created_at, updated_at)
         VALUES (:full_name, :email, :phone, :password, :address, :city, :gender,
                 0.00, 'basic', 0, 0, 0.00, 0, :member_since, :account_number, NOW(), NOW())"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':phone' => $phone,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':address' => $address,
        ':city' => $city,
        ':gender' => $gender,
        ':member_since' => date('M d, Y'),
        ':account_number' => $accountNumber
    ]);

    // Get the new user
    $userId = $conn->lastInsertId();
    $stmt = $conn->prepare(
        "SELECT id, account_number, full_name, email, phone, address, city, gender, avatar,
                wallet_balance, member_level, member_points, total_orders,
                rating, verified, member_since, created_at, updated_at,
                date_of_birth, location
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([
        'user' => formatUserData($user, $baseUrl) // Pass baseUrl
    ], 'Registration successful. Your account number is ' . $accountNumber, 201);
}

/*********************************
 * UPDATE PROFILE
 *********************************/
function updateProfile($conn, $data, $baseUrl) { // Accept baseUrl
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $address = trim($data['address'] ?? '');
    $city = trim($data['city'] ?? '');
    $gender = $data['gender'] ?? null;
    $dateOfBirth = $data['date_of_birth'] ?? null;
    $location = $data['location'] ?? null;

    // Validation
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    if (!$email) {
        ResponseHandler::error('Email is required', 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Enter a valid email address', 400);
    }
    
    if ($phone && strlen($phone) < 10) {
        ResponseHandler::error('Enter a valid phone number', 400);
    }

    // Check if email or phone already exists
    $checkSql = "SELECT id FROM users WHERE (email = :email OR phone = :phone) AND id != :id";
    $check = $conn->prepare($checkSql);
    $check->execute([
        ':email' => $email,
        ':phone' => $phone,
        ':id' => $_SESSION['user_id']
    ]);
    
    if ($check->rowCount() > 0) {
        ResponseHandler::error('Email or phone already in use', 409);
    }

    // Update user
    $stmt = $conn->prepare(
        "UPDATE users SET 
            full_name = :full_name,
            email = :email,
            phone = :phone,
            address = :address,
            city = :city,
            gender = :gender,
            date_of_birth = :date_of_birth,
            location = :location,
            updated_at = NOW()
         WHERE id = :id"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':phone' => $phone,
        ':address' => $address,
        ':city' => $city,
        ':gender' => $gender,
        ':date_of_birth' => $dateOfBirth,
        ':location' => $location,
        ':id' => $_SESSION['user_id']
    ]);

    // Get updated user
    $stmt = $conn->prepare(
        "SELECT id, account_number, full_name, email, phone, address, city, gender, avatar,
                wallet_balance, member_level, member_points, total_orders,
                rating, verified, member_since, 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as updated_at,
                date_of_birth, location
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user' => formatUserData($user, $baseUrl) // Pass baseUrl
    ], 'Profile updated successfully');
}

/*********************************
 * CHANGE PASSWORD
 *********************************/
function changePassword($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';

    if (!$currentPassword || !$newPassword || !$confirmPassword) {
        ResponseHandler::error('All password fields are required', 400);
    }

    if ($newPassword !== $confirmPassword) {
        ResponseHandler::error('New passwords do not match', 400);
    }

    if (strlen($newPassword) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    // Get current user password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        ResponseHandler::error('Current password is incorrect', 401);
    }

    // Update password
    $stmt = $conn->prepare(
        "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute([
        ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => $_SESSION['user_id']
    ]);

    ResponseHandler::success([], 'Password changed successfully');
}

/*********************************
 * FORGOT PASSWORD
 *********************************/
function forgotPassword($conn, $data) {
    $identifier = trim($data['identifier'] ?? '');

    if (!$identifier) {
        ResponseHandler::error('Email or phone number is required', 400);
    }

    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        $phone = cleanPhoneNumber($identifier);
        $stmt = $conn->prepare("SELECT id, email, account_number FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        $stmt = $conn->prepare("SELECT id, email, account_number FROM users WHERE email = :email");
        $stmt->execute([':email' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::success([], 'If your account exists, you will receive reset instructions');
    }

    // Generate reset token
    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    
    $stmt = $conn->prepare(
        "UPDATE users SET 
            reset_token = :token,
            reset_token_expires = :expires
         WHERE id = :id"
    );
    $stmt->execute([
        ':token' => $resetToken,
        ':expires' => $expiresAt,
        ':id' => $user['id']
    ]);

    // In production, send email/SMS with reset link
    ResponseHandler::success([], 'Reset instructions sent to your email/phone. Account: ' . $user['account_number']);
}

/*********************************
 * AVATAR UPLOAD
 *********************************/
function uploadAvatar($conn, $baseUrl) { // Accept baseUrl
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        ResponseHandler::error('No file uploaded or upload error', 400);
    }

    $file = $_FILES['avatar'];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($file['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        ResponseHandler::error('Only JPEG, PNG, GIF, and WebP images are allowed', 400);
    }

    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        ResponseHandler::error('File size must be less than 5MB', 400);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $extension;
    
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $filePath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        ResponseHandler::error('Failed to save file', 500);
    }

    $avatarUrl = '/uploads/avatars/' . $filename;
    
    $stmt = $conn->prepare(
        "UPDATE users SET avatar = :avatar, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute([
        ':avatar' => $avatarUrl,
        ':id' => $_SESSION['user_id']
    ]);

    // Return full URL for Flutter
    $fullAvatarUrl = rtrim($baseUrl, '/') . $avatarUrl;

    ResponseHandler::success([
        'avatar_url' => $fullAvatarUrl
    ], 'Profile picture updated successfully');
}

/*********************************
 * REMOVE AVATAR
 *********************************/
function removeAvatar($conn) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $stmt = $conn->prepare("SELECT avatar FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['avatar']) {
        $filePath = __DIR__ . '/..' . $user['avatar'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    $stmt = $conn->prepare(
        "UPDATE users SET avatar = NULL, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);

    ResponseHandler::success([], 'Profile picture removed successfully');
}

/*********************************
 * LOGOUT
 *********************************/
function logoutUser() {
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
}

/*********************************
 * CLEAN PHONE NUMBER
 *********************************/
function cleanPhoneNumber($phone) {
    $phone = trim($phone);
    $hasPlus = substr($phone, 0, 1) === '+';
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    
    return $digits;
}

/*********************************
 * FORMAT USER DATA FOR FLUTTER
 *********************************/
function formatUserData($u, $baseUrl) { // Accept baseUrl parameter
    // Format avatar URL with full path
    $avatarUrl = null;
    if (!empty($u['avatar'])) {
        if (strpos($u['avatar'], 'http') === 0) {
            $avatarUrl = $u['avatar'];
        } else {
            $avatarUrl = rtrim($baseUrl, '/') . '/' . ltrim($u['avatar'], '/');
        }
    }
    
    return [
        'id' => $u['id'],
        'account_number' => $u['account_number'] ?? generateTempAccountNumber($u['id']),
        'name' => $u['full_name'] ?: 'User',
        'full_name' => $u['full_name'] ?: 'User',
        'email' => $u['email'] ?? '',
        'phone' => $u['phone'] ?? '',
        'address' => $u['address'] ?? '',
        'city' => $u['city'] ?? '',
        'gender' => $u['gender'] ?? '',
        'avatar' => $avatarUrl, // Use formatted URL
        'wallet_balance' => (float) ($u['wallet_balance'] ?? 0.00),
        'member_level' => $u['member_level'] ?? 'basic',
        'member_points' => (int) ($u['member_points'] ?? 0),
        'total_orders' => (int) ($u['total_orders'] ?? 0),
        'rating' => (float) ($u['rating'] ?? 0.00),
        'verified' => (bool) ($u['verified'] ?? false),
        'member_since' => $u['member_since'] ?? formatDateForFlutter($u['created_at']),
        'created_at' => $u['created_at'] ?? '',
        'updated_at' => $u['updated_at'] ?? '',
        'date_of_birth' => $u['date_of_birth'] ?? '',
        'location' => $u['location'] ?? ''
    ];
}

/*********************************
 * GENERATE TEMP ACCOUNT NUMBER
 *********************************/
function generateTempAccountNumber($userId) {
    // Generate temporary 5-digit number from user ID
    return str_pad(10000 + $userId, 5, '0', STR_PAD_LEFT);
}

/*********************************
 * FORMAT DATE FOR FLUTTER
 *********************************/
function formatDateForFlutter($dateString) {
    if (!$dateString) return '';
    
    try {
        $date = new DateTime($dateString);
        return $date->format('M d, Y');
    } catch (Exception $e) {
        return $dateString;
    }
}
?>