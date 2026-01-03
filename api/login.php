<?php
// ================== CORS ==================
$allowedOrigins = [
    'https://dropx-frontend-seven.vercel.app'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// ================== PREFLIGHT ==================
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ================== DEPENDENCIES ==================
require_once __DIR__ . '/../includes/ResponseHandler.php';
require_once __DIR__ . '/../config/database.php';

// ================== SESSION ==================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'secure' => true,        // REQUIRED
        'httponly' => true,
        'samesite' => 'None'     // REQUIRED
    ]);
    session_start();
}

// ================== METHOD ==================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHandler::error('Method not allowed', 405);
}

// ================== INPUT ==================
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ResponseHandler::error('Invalid JSON input', 400);
}

$identifier = trim($input['email'] ?? '');
$password   = $input['password'] ?? '';

if ($identifier === '' || $password === '') {
    ResponseHandler::error('Email/username and password are required', 400);
}

// ================== LOGIN ==================
try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare(
        "SELECT * FROM users 
         WHERE email = :id OR username = :id 
         LIMIT 1"
    );
    $stmt->execute([':id' => $identifier]);

    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    // ================== SESSION DATA ==================
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;

    unset($user['password']);

    ResponseHandler::success([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'name' => $user['full_name'] ?: $user['username'],
            'wallet_balance' => (float) ($user['wallet_balance'] ?? 0),
            'member_level' => $user['member_level'] ?? 'Silver'
        ]
    ], 'Login successful');

} catch (Throwable $e) {
    ResponseHandler::error('Server error', 500);
}
