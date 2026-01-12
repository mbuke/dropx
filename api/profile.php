<?php
// profile.php - COMPLETE PROFILE API
// Enable CORS and handle preflight

// Start output buffering
ob_start();

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session with proper settings
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

// Response function
function jsonResponse($success, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    jsonResponse(false, 'Authentication required. Please login.', [], 401);
}

$user_id = $_SESSION['user_id'];

try {
    // Include database
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get HTTP method
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Route the request
    switch ($method) {
        case 'GET':
            handleGetRequest($user_id, $conn);
            break;
        case 'POST':
            handlePostRequest($user_id, $conn);
            break;
        case 'PUT':
            handlePutRequest($user_id, $conn);
            break;
        case 'DELETE':
            handleDeleteRequest($user_id, $conn);
            break;
        default:
            jsonResponse(false, 'Method not allowed', [], 405);
    }
    
} catch (Exception $e) {
    ob_end_clean();
    jsonResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}

// ============ GET REQUESTS ============
function handleGetRequest($user_id, $conn) {
    $action = $_GET['action'] ?? 'get_profile';
    
    switch ($action) {
        case 'get_profile':
            getUserProfile($user_id, $conn);
            break;
        case 'addresses':
            getUserAddresses($user_id, $conn);
            break;
        case 'orders':
            getUserOrders($user_id, $conn);
            break;
        default:
            jsonResponse(false, 'Invalid action', [], 400);
    }
}

// ============ POST REQUESTS ============
function handlePostRequest($user_id, $conn) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    // Check if it's form data (for file upload)
    if (strpos($contentType, 'multipart/form-data') !== false) {
        $data = $_POST;
        $files = $_FILES;
        $action = $data['action'] ?? '';
    } else {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(false, 'Invalid JSON input', [], 400);
        }
        $files = [];
        $action = $data['action'] ?? '';
    }
    
    switch ($action) {
        case 'update_profile':
            updateProfile($user_id, $data, $files, $conn);
            break;
        case 'add_address':
            addAddress($user_id, $data, $conn);
            break;
        case 'change_password':
            changePassword($user_id, $data, $conn);
            break;
        default:
            jsonResponse(false, 'Invalid action', [], 400);
    }
}

// ============ PUT REQUESTS ============
function handlePutRequest($user_id, $conn) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(false, 'Invalid JSON input', [], 400);
    }
    
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'set_default_address':
            setDefaultAddress($user_id, $data, $conn);
            break;
        case 'update_address':
            updateAddress($user_id, $data, $conn);
            break;
        default:
            jsonResponse(false, 'Invalid action', [], 400);
    }
}

// ============ DELETE REQUESTS ============
function handleDeleteRequest($user_id, $conn) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(false, 'Invalid JSON input', [], 400);
    }
    
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'delete_address':
            deleteAddress($user_id, $data, $conn);
            break;
        default:
            jsonResponse(false, 'Invalid action', [], 400);
    }
}

// ============ PROFILE FUNCTIONS ============

function getUserProfile($user_id, $conn) {
    try {
        // Get user data
        $stmt = $conn->prepare("
            SELECT 
                id, email, full_name, phone, avatar,
                wallet_balance, member_level, member_points,
                total_orders, rating, verified,
                DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        // Get default address
        $addressStmt = $conn->prepare("
            SELECT address, city 
            FROM user_addresses 
            WHERE user_id = ? AND is_default = 1 
            LIMIT 1
        ");
        $addressStmt->execute([$user_id]);
        $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($address) {
            $user['address'] = $address['address'];
            if ($address['city']) {
                $user['address'] .= ', ' . $address['city'];
            }
        } else {
            $user['address'] = '';
        }
        
        // Get recent orders
        $ordersStmt = $conn->prepare("
            SELECT 
                o.id, o.order_number, o.total_amount, o.status, 
                DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                r.name as restaurant_name,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            WHERE o.user_id = ?
            ORDER BY o.created_at DESC 
            LIMIT 5
        ");
        $ordersStmt->execute([$user_id]);
        $recent_orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_end_clean();
        jsonResponse(true, 'Profile retrieved successfully', [
            'user' => $user,
            'recent_orders' => $recent_orders
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to get profile: ' . $e->getMessage());
    }
}

function updateProfile($user_id, $data, $files, $conn) {
    try {
        // Validate required fields
        if (empty($data['full_name'])) {
            jsonResponse(false, 'Full name is required', [], 400);
        }
        
        if (empty($data['email'])) {
            jsonResponse(false, 'Email is required', [], 400);
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Invalid email format', [], 400);
        }
        
        // Check if email exists for another user
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkStmt->execute([$data['email'], $user_id]);
        if ($checkStmt->fetch()) {
            jsonResponse(false, 'Email already in use by another account', [], 400);
        }
        
        $conn->beginTransaction();
        
        try {
            // Handle avatar upload
            $avatar_url = null;
            if (isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatar_url = uploadAvatar($user_id, $files['avatar']);
            }
            
            // Build update query
            $updateFields = [];
            $params = [];
            
            $updateFields[] = "full_name = ?";
            $params[] = trim($data['full_name']);
            
            $updateFields[] = "email = ?";
            $params[] = trim($data['email']);
            
            if (isset($data['phone'])) {
                $updateFields[] = "phone = ?";
                $params[] = trim($data['phone']);
            }
            
            if ($avatar_url) {
                $updateFields[] = "avatar = ?";
                $params[] = $avatar_url;
            }
            
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            
            // Update user
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $params[] = $user_id;
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            // Update address if provided
            if (isset($data['address']) && !empty(trim($data['address']))) {
                updateUserAddress($user_id, trim($data['address']), $conn);
            }
            
            // Get updated user
            $userStmt = $conn->prepare("
                SELECT 
                    id, email, full_name, phone, avatar,
                    wallet_balance, total_orders, rating, verified,
                    DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
                FROM users 
                WHERE id = ?
            ");
            $userStmt->execute([$user_id]);
            $updated_user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get default address
            $addrStmt = $conn->prepare("
                SELECT address, city 
                FROM user_addresses 
                WHERE user_id = ? AND is_default = 1 
                LIMIT 1
            ");
            $addrStmt->execute([$user_id]);
            $address = $addrStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($address) {
                $updated_user['address'] = $address['address'];
                if ($address['city']) {
                    $updated_user['address'] .= ', ' . $address['city'];
                }
            } else {
                $updated_user['address'] = '';
            }
            
            $conn->commit();
            
            ob_end_clean();
            jsonResponse(true, 'Profile updated successfully', [
                'user' => $updated_user
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Profile update failed: ' . $e->getMessage());
    }
}

function uploadAvatar($user_id, $file) {
    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
    }
    
    if ($file['size'] > $max_size) {
        throw new Exception('File size exceeds 5MB limit');
    }
    
    // Create upload directory
    $upload_dir = __DIR__ . '/../../uploads/avatars/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }
    
    return '/uploads/avatars/' . $filename;
}

function updateUserAddress($user_id, $address, $conn) {
    try {
        // Check if user has default address
        $checkStmt = $conn->prepare("
            SELECT id FROM user_addresses 
            WHERE user_id = ? AND is_default = 1 
            LIMIT 1
        ");
        $checkStmt->execute([$user_id]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Update existing
            $updateStmt = $conn->prepare("
                UPDATE user_addresses 
                SET address = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $updateStmt->execute([$address, $result['id']]);
        } else {
            // Create new
            $insertStmt = $conn->prepare("
                INSERT INTO user_addresses 
                (user_id, title, address, city, address_type, is_default, created_at)
                VALUES (?, 'Home', ?, '', 'home', 1, CURRENT_TIMESTAMP)
            ");
            $insertStmt->execute([$user_id, $address]);
        }
    } catch (Exception $e) {
        // Address update is not critical, continue
    }
}

function getUserAddresses($user_id, $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                id, title, address, city, state, zip_code,
                latitude, longitude, is_default, instructions,
                address_type,
                DATE_FORMAT(created_at, '%Y-%m-%d') as created_date
            FROM user_addresses 
            WHERE user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$user_id]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_end_clean();
        jsonResponse(true, 'Addresses retrieved successfully', [
            'addresses' => $addresses
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to get addresses: ' . $e->getMessage());
    }
}

function addAddress($user_id, $data, $conn) {
    try {
        // Validate required fields
        if (empty($data['title']) || empty($data['address']) || empty($data['city'])) {
            jsonResponse(false, 'Title, address, and city are required', [], 400);
        }
        
        // Check if first address
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?");
        $checkStmt->execute([$user_id]);
        $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
        $is_default = $count == 0 ? 1 : 0;
        
        // If setting as default, clear others
        if (isset($data['is_default']) && $data['is_default']) {
            $clearStmt = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $clearStmt->execute([$user_id]);
            $is_default = 1;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO user_addresses 
            (user_id, title, address, city, state, zip_code, 
             address_type, is_default, instructions, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $state = $data['state'] ?? '';
        $zip_code = $data['zip_code'] ?? '';
        $address_type = $data['address_type'] ?? 'other';
        $instructions = $data['instructions'] ?? '';
        
        $stmt->execute([
            $user_id,
            $data['title'],
            $data['address'],
            $data['city'],
            $state,
            $zip_code,
            $address_type,
            $is_default,
            $instructions
        ]);
        
        $address_id = $conn->lastInsertId();
        
        ob_end_clean();
        jsonResponse(true, 'Address added successfully', [
            'address_id' => $address_id
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to add address: ' . $e->getMessage());
    }
}

function setDefaultAddress($user_id, $data, $conn) {
    try {
        if (empty($data['address_id'])) {
            jsonResponse(false, 'Address ID is required', [], 400);
        }
        
        $address_id = $data['address_id'];
        
        // Verify ownership
        $checkStmt = $conn->prepare("SELECT id FROM user_addresses WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$address_id, $user_id]);
        if (!$checkStmt->fetch()) {
            jsonResponse(false, 'Address not found', [], 404);
        }
        
        $conn->beginTransaction();
        
        try {
            // Clear all defaults
            $clearStmt = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $clearStmt->execute([$user_id]);
            
            // Set new default
            $updateStmt = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
            $updateStmt->execute([$address_id, $user_id]);
            
            $conn->commit();
            
            ob_end_clean();
            jsonResponse(true, 'Default address updated successfully');
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to set default address: ' . $e->getMessage());
    }
}

function updateAddress($user_id, $data, $conn) {
    try {
        if (empty($data['address_id'])) {
            jsonResponse(false, 'Address ID is required', [], 400);
        }
        
        $address_id = $data['address_id'];
        
        // Verify ownership
        $checkStmt = $conn->prepare("SELECT id FROM user_addresses WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$address_id, $user_id]);
        if (!$checkStmt->fetch()) {
            jsonResponse(false, 'Address not found', [], 404);
        }
        
        // Build update
        $updates = [];
        $params = [];
        
        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = $data['title'];
        }
        
        if (isset($data['address'])) {
            $updates[] = "address = ?";
            $params[] = $data['address'];
        }
        
        if (isset($data['city'])) {
            $updates[] = "city = ?";
            $params[] = $data['city'];
        }
        
        if (isset($data['state'])) {
            $updates[] = "state = ?";
            $params[] = $data['state'];
        }
        
        if (isset($data['zip_code'])) {
            $updates[] = "zip_code = ?";
            $params[] = $data['zip_code'];
        }
        
        if (isset($data['address_type'])) {
            $updates[] = "address_type = ?";
            $params[] = $data['address_type'];
        }
        
        if (isset($data['instructions'])) {
            $updates[] = "instructions = ?";
            $params[] = $data['instructions'];
        }
        
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        
        $sql = "UPDATE user_addresses SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
        $params[] = $address_id;
        $params[] = $user_id;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        ob_end_clean();
        jsonResponse(true, 'Address updated successfully');
        
    } catch (Exception $e) {
        throw new Exception('Failed to update address: ' . $e->getMessage());
    }
}

function deleteAddress($user_id, $data, $conn) {
    try {
        if (empty($data['address_id'])) {
            jsonResponse(false, 'Address ID is required', [], 400);
        }
        
        $address_id = $data['address_id'];
        
        // Check if exists and is default
        $checkStmt = $conn->prepare("SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$address_id, $user_id]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            jsonResponse(false, 'Address not found', [], 404);
        }
        
        if ($result['is_default'] == 1) {
            jsonResponse(false, 'Cannot delete default address', [], 400);
        }
        
        // Delete address
        $deleteStmt = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
        $deleteStmt->execute([$address_id, $user_id]);
        
        ob_end_clean();
        jsonResponse(true, 'Address deleted successfully');
        
    } catch (Exception $e) {
        throw new Exception('Failed to delete address: ' . $e->getMessage());
    }
}

function getUserOrders($user_id, $conn) {
    try {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $status = $_GET['status'] ?? '';
        
        $offset = ($page - 1) * $limit;
        
        // Build query
        $where = "WHERE o.user_id = ?";
        $params = [$user_id];
        
        if (!empty($status)) {
            $where .= " AND o.status = ?";
            $params[] = $status;
        }
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM orders $where";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get orders
        $params[] = $limit;
        $params[] = $offset;
        
        $sql = "
            SELECT 
                o.*,
                r.name as restaurant_name,
                r.image as restaurant_image,
                DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            $where
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_end_clean();
        jsonResponse(true, 'Orders retrieved successfully', [
            'orders' => $orders,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to get orders: ' . $e->getMessage());
    }
}

function changePassword($user_id, $data, $conn) {
    try {
        $required = ['current_password', 'new_password', 'confirm_password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                jsonResponse(false, "$field is required", [], 400);
            }
        }
        
        if ($data['new_password'] !== $data['confirm_password']) {
            jsonResponse(false, 'New passwords do not match', [], 400);
        }
        
        if (strlen($data['new_password']) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters', [], 400);
        }
        
        // Get current password hash
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        // Verify current password
        if (!password_verify($data['current_password'], $user['password'])) {
            jsonResponse(false, 'Current password is incorrect', [], 400);
        }
        
        // Hash new password
        $new_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
        
        // Update password
        $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([$new_hash, $user_id]);
        
        ob_end_clean();
        jsonResponse(true, 'Password changed successfully');
        
    } catch (Exception $e) {
        throw new Exception('Failed to change password: ' . $e->getMessage());
    }
}

// Clean output buffer
ob_end_flush();
?>