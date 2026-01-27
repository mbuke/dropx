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
 * GET: AUTH CHECK
 *********************************/
function handleGetRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, phone, address, city, gender, avatar,
                    wallet_balance, member_level, member_points, total_orders,
                    rating, verified, member_since, created_at, updated_at
             FROM users WHERE id = :id"
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            ResponseHandler::success([
                'authenticated' => true,
                'user' => formatUserData($user)
            ]);
        }
    }

    ResponseHandler::success(['authenticated' => false]);
}

/*********************************
 * POST ROUTER
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'login':
            loginUser($conn, $input);
            break;
        case 'register':
            registerUser($conn, $input);
            break;
        case 'logout':
            logoutUser();
            break;
        case 'update_profile':
            updateProfile($conn, $input);
            break;
        case 'change_password':
            changePassword($conn, $input);
            break;
        case 'forgot_password':
            forgotPassword($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * LOGIN - EXACTLY AS FLUTTER SENDS
 *********************************/
function loginUser($conn, $data) {
    // Your Flutter sends: identifier (email or phone) and password
    $identifier = trim($data['identifier'] ?? '');
    $password = $data['password'] ?? '';
    $rememberMe = $data['remember_me'] ?? false;

    if (!$identifier || !$password) {
        ResponseHandler::error('Email/phone and password required', 400);
    }

    // Check if identifier is email or phone (same logic as Flutter)
    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL); // FIXED

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        // Clean phone number (remove all non-digit except leading +)
        $phone = cleanPhoneNumber($identifier);
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        
        // Search by phone
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        // Search by email
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
        'user' => formatUserData($user)
    ], 'Login successful');
}

/*********************************
 * REGISTER - EXACT FIELDS FROM FLUTTER SIGNUPSCREEN
 *********************************/
function registerUser($conn, $data) {
    // Your Flutter SignUpScreen sends these exact fields:
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $password = $data['password'] ?? '';
    $address = trim($data['address'] ?? '');
    $city = trim($data['city'] ?? '');
    $gender = $data['gender'] ?? null;

    // Validation (matching Flutter validation)
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    if (!$email) {
        ResponseHandler::error('Email address is required', 400);
    }
    
    // FIXED: Changed $filter_var to filter_var()
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

    // Check if user already exists (email or phone)
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

    // Create user (no username field, just full_name)
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, phone, password, address, city, gender, 
                           wallet_balance, member_level, member_points, total_orders, 
                           rating, verified, member_since, created_at, updated_at)
         VALUES (:full_name, :email, :phone, :password, :address, :city, :gender,
                 0.00, 'basic', 0, 0, 0.00, 0, :member_since, NOW(), NOW())"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':phone' => $phone,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':address' => $address,
        ':city' => $city,
        ':gender' => $gender,
        ':member_since' => date('M d, Y') // Format: Jan 15, 2023 like Flutter
    ]);

    // Get the new user
    $userId = $conn->lastInsertId();
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, address, city, gender, avatar,
                wallet_balance, member_level, member_points, total_orders,
                rating, verified, member_since, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([
        'user' => formatUserData($user)
    ], 'Registration successful', 201);
}

/*********************************
 * UPDATE PROFILE - MATCHES FLUTTER EDIT PROFILE
 *********************************/
function updateProfile($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    // Fields from Flutter EditProfileScreen
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $address = trim($data['address'] ?? '');
    $city = trim($data['city'] ?? '');
    $avatar = $data['avatar'] ?? null;

    // Validation
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    if (!$email) {
        ResponseHandler::error('Email is required', 400);
    }
    
    // FIXED: Changed $filter_var to filter_var()
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Enter a valid email address', 400);
    }
    
    if ($phone && strlen($phone) < 10) {
        ResponseHandler::error('Enter a valid phone number', 400);
    }

    // Check if email or phone already exists (excluding current user)
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
            updated_at = NOW()
         WHERE id = :id"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':phone' => $phone,
        ':address' => $address,
        ':city' => $city,
        ':id' => $_SESSION['user_id']
    ]);

    // Get updated user
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, address, city, gender, avatar,
                wallet_balance, member_level, member_points, total_orders,
                rating, verified, member_since, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user' => formatUserData($user)
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

    // Check if identifier is email or phone
    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL); // FIXED

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        $phone = cleanPhoneNumber($identifier);
        $stmt = $conn->prepare("SELECT id, email FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        $stmt = $conn->prepare("SELECT id, email FROM users WHERE email = :email");
        $stmt->execute([':email' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // For security, don't reveal if user exists
        ResponseHandler::success([], 'If your account exists, you will receive reset instructions');
    }

    // Generate reset token (in production, send actual email/SMS)
    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
    
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

    ResponseHandler::success([], 'Reset instructions sent to your email/phone');
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
function formatUserData($u) {
    return [
        'id' => $u['id'],
        'name' => $u['full_name'] ?: 'User',
        'full_name' => $u['full_name'] ?: 'User',
        'email' => $u['email'] ?? '',
        'phone' => $u['phone'] ?? '',
        'address' => $u['address'] ?? '',
        'city' => $u['city'] ?? '',
        'gender' => $u['gender'] ?? '',
        'avatar' => $u['avatar'] ?? null,
        'wallet_balance' => (float) ($u['wallet_balance'] ?? 0.00),
        'member_level' => $u['member_level'] ?? 'basic',
        'member_points' => (int) ($u['member_points'] ?? 0),
        'total_orders' => (int) ($u['total_orders'] ?? 0),
        'rating' => (float) ($u['rating'] ?? 0.00),
        'verified' => (bool) ($u['verified'] ?? false),
        'member_since' => $u['member_since'] ?? date('M d, Y'),
        'created_at' => $u['created_at'] ?? '',
        'updated_at' => $u['updated_at'] ?? ''
    ];
}