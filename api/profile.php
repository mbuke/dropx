<?php
// profile.php - User Profile API

// Enable CORS
header("Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============ RESPONSE FUNCTIONS ============
function sendSuccess($message, $data = []) {
    $response = [
        'success' => true,
        'message' => $message,
        'data' => $data
    ];
    
    http_response_code(200);
    echo json_encode($response);
    exit();
}

function sendError($message, $statusCode = 400) {
    $response = [
        'success' => false,
        'message' => $message
    ];
    
    http_response_code($statusCode);
    echo json_encode($response);
    exit();
}

// ============ MAIN EXECUTION ============
try {
    // Check authentication
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        sendError('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    
    // Get database connection from config
    require_once __DIR__ . '/../config/database.php';
    $db = getDatabaseConnection();
    
    // Handle request based on method
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGet($userId, $db);
            break;
            
        case 'POST':
            handlePost($userId, $db);
            break;
            
        case 'PUT':
            handlePut($userId, $db);
            break;
            
        case 'DELETE':
            handleDelete($userId, $db);
            break;
            
        default:
            sendError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

// ============ REQUEST HANDLERS ============
function handleGet($userId, $db) {
    $action = isset($_GET['action']) ? $_GET['action'] : 'profile';
    
    switch ($action) {
        case 'orders':
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            getUserOrders($userId, $page, $limit, $status, $db);
            break;
            
        case 'addresses':
            getUserAddresses($userId, $db);
            break;
            
        case 'profile':
        default:
            getUserProfile($userId, $db);
            break;
    }
}

function handlePost($userId, $db) {
    // Check content type
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Handle form data (profile update with avatar)
        $data = $_POST;
        updateProfile($userId, $data, $db);
    } else {
        // Handle JSON
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (!$data || !isset($data['action'])) {
            sendError('Invalid request data', 400);
        }
        
        switch ($data['action']) {
            case 'add_address':
                addAddress($userId, $data, $db);
                break;
                
            case 'update_profile':
                updateProfile($userId, $data, $db);
                break;
                
            default:
                sendError('Invalid action', 400);
        }
    }
}

function handlePut($userId, $db) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['action'])) {
        sendError('Invalid request data', 400);
    }
    
    switch ($data['action']) {
        case 'set_default_address':
            setDefaultAddress($userId, $data, $db);
            break;
            
        case 'update_profile':
            updateProfile($userId, $data, $db);
            break;
            
        default:
            sendError('Invalid action', 405);
    }
}

function handleDelete($userId, $db) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['action'])) {
        sendError('Invalid request data', 400);
    }
    
    switch ($data['action']) {
        case 'delete_address':
            deleteAddress($userId, $data, $db);
            break;
            
        default:
            sendError('Invalid action', 400);
    }
}

// ============ PROFILE FUNCTIONS ============

function getUserProfile($userId, $db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                id, email, full_name, phone, avatar,
                created_at, verified, role
            FROM users 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendError('User not found', 404);
        }
        
        $user = $result->fetch_assoc();
        
        // Get order count
        $orderStmt = $db->prepare("SELECT COUNT(*) as total_orders FROM orders WHERE user_id = ?");
        $orderStmt->bind_param("i", $userId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result()->fetch_assoc();
        $user['total_orders'] = $orderResult['total_orders'] ?? 0;
        
        // Get address count
        $addrStmt = $db->prepare("SELECT COUNT(*) as total_addresses FROM addresses WHERE user_id = ?");
        $addrStmt->bind_param("i", $userId);
        $addrStmt->execute();
        $addrResult = $addrStmt->get_result()->fetch_assoc();
        $user['total_addresses'] = $addrResult['total_addresses'] ?? 0;
        
        // Get default address
        $address = getDefaultAddress($userId, $db);
        $user['address'] = $address ? $address['address'] : '';
        
        // Get recent orders
        $orders = getRecentOrders($userId, 5, $db);
        
        sendSuccess('Profile retrieved successfully', [
            'user' => $user,
            'recent_orders' => $orders
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get profile: ' . $e->getMessage(), 500);
    }
}

function updateProfile($userId, $data, $db) {
    try {
        // Start transaction
        $db->begin_transaction();
        
        // Get current user
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentUser = $stmt->get_result()->fetch_assoc();
        
        if (!$currentUser) {
            throw new Exception('User not found');
        }
        
        // Prepare updates
        $updates = [];
        $params = [];
        $types = '';
        
        // Full name
        if (isset($data['full_name']) && !empty(trim($data['full_name']))) {
            $updates[] = "full_name = ?";
            $params[] = trim($data['full_name']);
            $types .= 's';
        }
        
        // Email
        if (isset($data['email']) && !empty(trim($data['email']))) {
            $email = trim($data['email']);
            // Check email uniqueness
            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->bind_param("si", $email, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            $updates[] = "email = ?";
            $params[] = $email;
            $types .= 's';
        }
        
        // Phone
        if (isset($data['phone'])) {
            $updates[] = "phone = ?";
            $params[] = trim($data['phone']);
            $types .= 's';
        }
        
        // Avatar upload
        $avatarUrl = $currentUser['avatar'];
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $avatarUrl = uploadAvatar($userId, $_FILES['avatar']);
            $updates[] = "avatar = ?";
            $params[] = $avatarUrl;
            $types .= 's';
        }
        
        // Address
        if (isset($data['address']) && !empty(trim($data['address']))) {
            updateUserAddress($userId, trim($data['address']), $db);
        }
        
        // Update user if there are changes
        if (!empty($updates)) {
            $params[] = $userId;
            $types .= 'i';
            
            $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
            $updateStmt = $db->prepare($sql);
            $updateStmt->bind_param($types, ...$params);
            $updateStmt->execute();
        }
        
        // Get updated user
        $updatedUser = getUpdatedUserData($userId, $db);
        
        $db->commit();
        
        sendSuccess('Profile updated successfully', [
            'user' => $updatedUser
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to update profile: ' . $e->getMessage(), 500);
    }
}

function getUserOrders($userId, $page, $limit, $status, $db) {
    try {
        $offset = ($page - 1) * $limit;
        
        $where = "WHERE user_id = ?";
        $params = [$userId];
        $types = "i";
        
        if (!empty($status)) {
            $where .= " AND status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM orders $where");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalResult = $countStmt->get_result()->fetch_assoc();
        $total = $totalResult['total'];
        
        // Get orders
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $db->prepare("
            SELECT 
                o.*,
                DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            $where
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        
        sendSuccess('Orders retrieved successfully', [
            'orders' => $orders,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get orders: ' . $e->getMessage(), 500);
    }
}

function getUserAddresses($userId, $db) {
    try {
        $stmt = $db->prepare("
            SELECT * FROM addresses 
            WHERE user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $addresses = [];
        while ($row = $result->fetch_assoc()) {
            $addresses[] = $row;
        }
        
        sendSuccess('Addresses retrieved successfully', [
            'addresses' => $addresses
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get addresses: ' . $e->getMessage(), 500);
    }
}

function addAddress($userId, $data, $db) {
    try {
        // Validate required fields
        if (empty($data['title']) || empty($data['address']) || empty($data['city'])) {
            sendError('Title, address, and city are required', 400);
        }
        
        // Check if first address (set as default)
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM addresses WHERE user_id = ?");
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $countResult = $checkStmt->get_result()->fetch_assoc();
        $isDefault = $countResult['count'] == 0 ? 1 : 0;
        
        // If setting as default, update others
        if (isset($data['is_default']) && $data['is_default'] == 1) {
            $resetStmt = $db->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
            $resetStmt->bind_param("i", $userId);
            $resetStmt->execute();
            $isDefault = 1;
        }
        
        $stmt = $db->prepare("
            INSERT INTO addresses (user_id, title, address, city, address_type, is_default, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $addressType = isset($data['address_type']) ? $data['address_type'] : 'other';
        $stmt->bind_param(
            "issssi", 
            $userId, 
            $data['title'], 
            $data['address'], 
            $data['city'], 
            $addressType,
            $isDefault
        );
        
        $stmt->execute();
        
        sendSuccess('Address added successfully', [
            'address_id' => $stmt->insert_id
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to add address: ' . $e->getMessage(), 500);
    }
}

function setDefaultAddress($userId, $data, $db) {
    try {
        if (empty($data['address_id'])) {
            sendError('Address ID is required', 400);
        }
        
        $addressId = $data['address_id'];
        
        // Verify address belongs to user
        $verifyStmt = $db->prepare("SELECT id FROM addresses WHERE id = ? AND user_id = ?");
        $verifyStmt->bind_param("ii", $addressId, $userId);
        $verifyStmt->execute();
        
        if ($verifyStmt->get_result()->num_rows === 0) {
            sendError('Address not found', 404);
        }
        
        $db->begin_transaction();
        
        // Reset all defaults
        $resetStmt = $db->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
        $resetStmt->bind_param("i", $userId);
        $resetStmt->execute();
        
        // Set new default
        $updateStmt = $db->prepare("UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
        $updateStmt->bind_param("ii", $addressId, $userId);
        $updateStmt->execute();
        
        $db->commit();
        
        sendSuccess('Default address updated successfully');
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to set default address: ' . $e->getMessage(), 500);
    }
}

function deleteAddress($userId, $data, $db) {
    try {
        if (empty($data['address_id'])) {
            sendError('Address ID is required', 400);
        }
        
        $addressId = $data['address_id'];
        
        // Check if address exists and is default
        $checkStmt = $db->prepare("SELECT is_default FROM addresses WHERE id = ? AND user_id = ?");
        $checkStmt->bind_param("ii", $addressId, $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            sendError('Address not found', 404);
        }
        
        $address = $result->fetch_assoc();
        
        if ($address['is_default'] == 1) {
            sendError('Cannot delete default address', 400);
        }
        
        // Delete address
        $deleteStmt = $db->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
        $deleteStmt->bind_param("ii", $addressId, $userId);
        $deleteStmt->execute();
        
        sendSuccess('Address deleted successfully');
        
    } catch (Exception $e) {
        sendError('Failed to delete address: ' . $e->getMessage(), 500);
    }
}

// ============ HELPER FUNCTIONS ============

function getDefaultAddress($userId, $db) {
    try {
        $stmt = $db->prepare("
            SELECT address FROM addresses 
            WHERE user_id = ? AND is_default = 1 
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getRecentOrders($userId, $limit, $db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                id, order_number, total_amount, status, created_at,
                DATE_FORMAT(created_at, '%Y-%m-%d') as formatted_date
            FROM orders 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        
        return $orders;
    } catch (Exception $e) {
        return [];
    }
}

function updateUserAddress($userId, $address, $db) {
    try {
        // Check for existing default address
        $checkStmt = $db->prepare("SELECT id FROM addresses WHERE user_id = ? AND is_default = 1 LIMIT 1");
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $addr = $result->fetch_assoc();
            $updateStmt = $db->prepare("UPDATE addresses SET address = ? WHERE id = ?");
            $updateStmt->bind_param("si", $address, $addr['id']);
            $updateStmt->execute();
        } else {
            $insertStmt = $db->prepare("
                INSERT INTO addresses (user_id, title, address, city, address_type, is_default, created_at)
                VALUES (?, 'Home', ?, '', 'home', 1, NOW())
            ");
            $insertStmt->bind_param("is", $userId, $address);
            $insertStmt->execute();
        }
    } catch (Exception $e) {
        // Address update is optional
    }
}

function uploadAvatar($userId, $file) {
    // Validate file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds 5MB limit');
    }
    
    // Create upload directory
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    // Move file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }
    
    return '/uploads/avatars/' . $filename;
}

function getUpdatedUserData($userId, $db) {
    $stmt = $db->prepare("
        SELECT 
            id, email, full_name, phone, avatar, verified, created_at
        FROM users
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    // Get order count
    $orderStmt = $db->prepare("SELECT COUNT(*) as total_orders FROM orders WHERE user_id = ?");
    $orderStmt->bind_param("i", $userId);
    $orderStmt->execute();
    $orderResult = $orderStmt->get_result()->fetch_assoc();
    $user['total_orders'] = $orderResult['total_orders'] ?? 0;
    
    // Get address
    $address = getDefaultAddress($userId, $db);
    $user['address'] = $address ? $address['address'] : '';
    
    return $user;
}
?>