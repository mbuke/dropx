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

$username = trim($input['username'] ?? '');
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $email === '' || $password === '') {
    ResponseHandler::error('All fields are required', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ResponseHandler::error('Invalid email', 400);
}

if (strlen($password) < 6) {
    ResponseHandler::error('Password must be at least 6 characters', 400);
}

// ================== REGISTER ==================
try {
    $db = new Database();
    $conn = $db->getConnection();

    $check = $conn->prepare(
        "SELECT id FROM users WHERE email = :email OR username = :username"
    );
    $check->execute([
        ':email' => $email,
        ':username' => $username
    ]);

    if ($check->rowCount() > 0) {
        ResponseHandler::error('User already exists', 409);
    }

    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password, created_at)
         VALUES (:u, :e, :p, NOW())"
    );

    $stmt->execute([
        ':u' => $username,
        ':e' => $email,
        ':p' => password_hash($password, PASSWORD_DEFAULT)
    ]);

    $_SESSION['user_id'] = $conn->lastInsertId();
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([
        'user' => [
            'id' => $_SESSION['user_id'],
            'username' => $username,
            'email' => $email
        ]
    ], 'Registration successful', 201);

} catch (Throwable $e) {
    ResponseHandler::error('Registration failed', 500);
}
