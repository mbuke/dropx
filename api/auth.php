<?php
/*********************************
 * CORS (Vercel frontend)
 *********************************/
$frontend = 'https://dropx-frontend-seven.vercel.app';

if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $frontend) {
    header("Access-Control-Allow-Origin: $frontend");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => true,        // HTTPS required on Render
        'httponly' => true,
        'samesite' => 'None'     // Required for cross-domain cookies
    ]);
    session_start();
}

/*********************************
 * DEPENDENCIES
 *********************************/
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
            "SELECT id, username, email, full_name, phone, address, avatar,
                    wallet_balance, member_level, member_points, total_orders,
                    rating, verified, join_date, created_at
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

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
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
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * LOGIN - ACCEPTS EMAIL OR PHONE
 *********************************/
function loginUser($conn, $data) {
    $identifier = trim($data['identifier'] ?? '');
    $password = $data['password'] ?? '';

    if (!$identifier || !$password) {
        ResponseHandler::error('Identifier and password required', 400);
    }

    // Check if identifier is phone number (contains only digits, +, spaces, dashes, parentheses)
    $isPhone = preg_match('/^[\+\s\-\(\)0-9]+$/', $identifier);
    
    if ($isPhone) {
        // Phone login - clean the phone number
        $phone = cleanPhoneNumber($identifier);
        
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        
        // Query with phone number
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        // Email/username login
        $stmt = $conn->prepare(
            "SELECT * FROM users WHERE email = :id OR username = :id"
        );
        $stmt->execute([':id' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('Invalid credentials', 401);
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;

    // Remove password from response
    unset($user['password']);

    ResponseHandler::success([
        'user' => formatUserData($user)
    ], 'Login successful');
}

/*********************************
 * CLEAN PHONE NUMBER
 *********************************/
function cleanPhoneNumber($phone) {
    // Remove all non-digit characters except leading +
    $phone = trim($phone);
    
    // If starts with +, keep it
    $hasPlus = substr($phone, 0, 1) === '+';
    
    // Remove all non-digit characters
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    
    return $digits;
}

/*********************************
 * REGISTER
 *********************************/
function registerUser($conn, $data) {
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;

    if (!$username || !$email || !$password) {
        ResponseHandler::error('Username, email and password required', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Invalid email format', 400);
    }

    if (strlen($password) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    // Check if phone is valid (if provided)
    if ($phone && strlen($phone) < 10) {
        ResponseHandler::error('Invalid phone number', 400);
    }

    // Check for existing user
    $check = $conn->prepare(
        "SELECT id FROM users WHERE email = :email OR username = :username"
        . ($phone ? " OR phone = :phone" : "")
    );
    
    $params = [':email' => $email, ':username' => $username];
    if ($phone) {
        $params[':phone'] = $phone;
    }
    
    $check->execute($params);

    if ($check->rowCount()) {
        ResponseHandler::error('User already exists', 409);
    }

    // Prepare SQL with phone field
    $sqlFields = "username, email, password";
    $sqlValues = ":u, :e, :p";
    $params = [
        ':u' => $username,
        ':e' => $email,
        ':p' => password_hash($password, PASSWORD_DEFAULT)
    ];
    
    if ($phone) {
        $sqlFields .= ", phone";
        $sqlValues .= ", :phone";
        $params[':phone'] = $phone;
    }
    
    $sqlFields .= ", full_name, wallet_balance, member_level, member_points, total_orders, rating, verified, join_date, created_at, updated_at";
    $sqlValues .= ", :f, 0, 'Silver', 100, 0, 5, 0, :jd, NOW(), NOW()";
    
    $params[':f'] = $username;
    $params[':jd'] = date('F j, Y');

    $stmt = $conn->prepare(
        "INSERT INTO users ($sqlFields) VALUES ($sqlValues)"
    );

    $stmt->execute($params);

    $_SESSION['user_id'] = $conn->lastInsertId();
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([], 'Registration successful', 201);
}

/*********************************
 * UPDATE PROFILE
 *********************************/
function updateProfile($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $fields = [];
    $params = [':id' => $_SESSION['user_id']];

    $allowedFields = ['full_name', 'email', 'phone', 'address', 'avatar'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $value = trim($data[$field]);
            
            // Special handling for phone
            if ($field === 'phone') {
                $value = cleanPhoneNumber($value);
                if ($value && strlen($value) < 10) {
                    ResponseHandler::error('Invalid phone number', 400);
                }
            }
            
            // Special handling for email
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                ResponseHandler::error('Invalid email format', 400);
            }
            
            $fields[] = "$field = :$field";
            $params[":$field"] = $value;
        }
    }

    if (!$fields) {
        ResponseHandler::error('Nothing to update', 400);
    }

    // Check if new email or phone already exists
    if (isset($params[':email']) || isset($params[':phone'])) {
        $checkSql = "SELECT id FROM users WHERE (";
        $checkParams = [];
        
        if (isset($params[':email'])) {
            $checkSql .= "email = :email";
            $checkParams[':email'] = $params[':email'];
        }
        
        if (isset($params[':phone'])) {
            if (isset($params[':email'])) {
                $checkSql .= " OR ";
            }
            $checkSql .= "phone = :phone";
            $checkParams[':phone'] = $params[':phone'];
        }
        
        $checkSql .= ") AND id != :id";
        $checkParams[':id'] = $params[':id'];
        
        $check = $conn->prepare($checkSql);
        $check->execute($checkParams);
        
        if ($check->rowCount()) {
            ResponseHandler::error('Email or phone already in use', 409);
        }
    }

    $fields[] = "updated_at = NOW()";

    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
    $conn->prepare($sql)->execute($params);

    ResponseHandler::success([], 'Profile updated');
}

/*********************************
 * CHANGE PASSWORD
 *********************************/
function changePassword($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    if (($data['new_password'] ?? '') !== ($data['confirm_password'] ?? '')) {
        ResponseHandler::error('Passwords do not match', 400);
    }

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($data['current_password'], $user['password'])) {
        ResponseHandler::error('Current password incorrect', 401);
    }

    $conn->prepare(
        "UPDATE users SET password = :p, updated_at = NOW() WHERE id = :id"
    )->execute([
        ':p' => password_hash($data['new_password'], PASSWORD_DEFAULT),
        ':id' => $_SESSION['user_id']
    ]);

    ResponseHandler::success([], 'Password changed');
}

/*********************************
 * LOGOUT
 *********************************/
function logoutUser() {
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
}

/*********************************
 * FORMAT USER RESPONSE
 *********************************/
function formatUserData($u) {
    return [
        'id' => $u['id'],
        'username' => $u['username'],
        'email' => $u['email'],
        'phone' => $u['phone'] ?? '',
        'name' => $u['full_name'] ?: $u['username'],
        'full_name' => $u['full_name'] ?: $u['username'],
        'address' => $u['address'] ?? '',
        'avatar' => $u['avatar'] ?? null,
        'wallet_balance' => (float) ($u['wallet_balance'] ?? 0),
        'member_level' => $u['member_level'] ?? 'Silver',
        'member_points' => (int) ($u['member_points'] ?? 100),
        'total_orders' => (int) ($u['total_orders'] ?? 0),
        'rating' => (float) ($u['rating'] ?? 5),
        'verified' => (bool) ($u['verified'] ?? false),
        'join_date' => $u['join_date'],
        'created_at' => $u['created_at']
    ];
}