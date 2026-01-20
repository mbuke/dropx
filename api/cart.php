<?php
// api/cart.php - VERSION FOR LOGGED-IN USERS ONLY
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// =============== AUTHENTICATION CHECK ===============
function checkAuthentication() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required. Please login first.',
            'requiresLogin' => true,
            'timestamp' => date('c')
        ]);
        exit();
    }
    return $_SESSION['user_id'];
}

// =============== UTILITY FUNCTIONS ===============
function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('c')
    ]);
    exit();
}

// Session ID handling
function getSessionId() {
    $sessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? null;
    
    if (!$sessionId && isset($_COOKIE['PHPSESSID'])) {
        $sessionId = $_COOKIE['PHPSESSID'];
    }
    
    if (!$sessionId) {
        $sessionId = 'user_' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(8));
    }
    
    return $sessionId;
}

// =============== MAIN EXECUTION ===============
try {
    // Check authentication for ALL requests
    $userId = checkAuthentication();
    $sessionId = getSessionId();
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
            $cart = getCart($conn, $userId, $sessionId, $merchantId);
            jsonResponse(true, $cart, 'Cart retrieved successfully');
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            if (empty($input)) {
                jsonResponse(false, null, 'No data provided', 400);
            }
            
            $action = $input['action'] ?? 'add';
            if ($action === 'add') {
                addToCart($conn, $userId, $sessionId, $input);
            } else {
                jsonResponse(false, null, 'Invalid action', 400);
            }
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateCartItem($conn, $userId, $sessionId, $input);
            break;
            
        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_GET;
            removeFromCart($conn, $userId, $sessionId, $input);
            break;
            
        default:
            jsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Cart API Error: " . $e->getMessage());
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}

// =============== HELPER FUNCTIONS ===============

/**
 * Get cart for logged-in user
 */
function getCart($conn, $userId, $sessionId, $merchantId = null) {
    // Find cart session for this user
    $cartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    
    if (!$cartSession) {
        return getEmptyCartData();
    }
    
    // Get cart items
    $query = "
        SELECT 
            ci.*,
            mi.image_url as menu_item_image,
            mi.in_stock as menu_item_in_stock,
            r.name as merchant_name,
            r.delivery_time,
            r.delivery_fee as merchant_delivery_fee,
            r.min_order_amount as merchant_min_order,
            r.image as merchant_image
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE ci.cart_session_id = :session_id
        ORDER BY ci.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':session_id' => $cartSession['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    return calculateCartData($items, $cartSession);
}

/**
 * Find or create cart session for user
 */
function findCartSession($conn, $userId, $sessionId, $merchantId = null) {
    $params = [];
    $conditions = [];
    
    // User must be logged in
    $conditions[] = "user_id = :user_id";
    $params[':user_id'] = $userId;
    
    if ($merchantId) {
        $conditions[] = "restaurant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    $conditions[] = "status = 'active'";
    $conditions[] = "expires_at > NOW()";
    
    $query = "
        SELECT 
            cs.*,
            r.name as merchant_name,
            r.delivery_time,
            r.image as merchant_image,
            r.address as merchant_address
        FROM cart_sessions cs
        LEFT JOIN restaurants r ON cs.restaurant_id = r.id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY cs.updated_at DESC 
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session && $merchantId) {
        $session = createCartSession($conn, $userId, $sessionId, $merchantId);
    }
    
    return $session;
}

/**
 * Create new cart session for user
 */
function createCartSession($conn, $userId, $sessionId, $merchantId) {
    // Verify merchant exists and is active
    $merchantQuery = "
        SELECT 
            id, name, delivery_fee, min_order_amount, 
            delivery_time, image, status, is_active
        FROM restaurants 
        WHERE id = :merchant_id AND status = 'active'
    ";
    
    $merchantStmt = $conn->prepare($merchantQuery);
    $merchantStmt->execute([':merchant_id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$merchant) {
        return null;
    }
    
    $uuid = 'cart_' . bin2hex(random_bytes(8));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $insertQuery = "
        INSERT INTO cart_sessions (
            uuid, user_id, restaurant_id, session_id, 
            status, expires_at, created_at, updated_at,
            delivery_fee, min_order_amount
        ) VALUES (
            :uuid, :user_id, :merchant_id, :session_id, 
            'active', :expires_at, NOW(), NOW(),
            :delivery_fee, :min_order_amount
        )
    ";
    
    try {
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->execute([
            ':uuid' => $uuid,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':session_id' => $sessionId,
            ':expires_at' => $expiresAt,
            ':delivery_fee' => $merchant['delivery_fee'],
            ':min_order_amount' => $merchant['min_order_amount']
        ]);
        
        $cartSessionId = $conn->lastInsertId();
        
        // Get the newly created session with merchant info
        $getQuery = "
            SELECT 
                cs.*,
                r.name as merchant_name,
                r.delivery_time,
                r.image as merchant_image
            FROM cart_sessions cs
            LEFT JOIN restaurants r ON cs.restaurant_id = r.id
            WHERE cs.id = :id
        ";
        
        $getStmt = $conn->prepare($getQuery);
        $getStmt->execute([':id' => $cartSessionId]);
        $session = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        return $session;
        
    } catch (Exception $e) {
        error_log("Error creating cart session: " . $e->getMessage());
        return null;
    }
}

/**
 * Add item to cart
 */
function addToCart($conn, $userId, $sessionId, $data) {
    $menuItemId = intval($data['menu_item_id'] ?? 0);
    $merchantId = intval($data['merchant_id'] ?? 0);
    $quantity = max(1, intval($data['quantity'] ?? 1));
    $customization = isset($data['customization']) ? json_encode($data['customization']) : null;
    $specialInstructions = $data['special_instructions'] ?? null;
    
    if (!$menuItemId || !$merchantId) {
        jsonResponse(false, null, 'Menu item and merchant are required', 400);
    }
    
    // Get menu item with validation
    $menuQuery = "
        SELECT 
            mi.*, 
            r.delivery_fee, 
            r.min_order_amount,
            r.name as merchant_name
        FROM menu_items mi
        INNER JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE mi.id = :item_id 
        AND r.id = :merchant_id
        AND mi.in_stock = 1
        AND mi.is_active = 1
        AND r.status = 'active'
    ";
    
    $menuStmt = $conn->prepare($menuQuery);
    $menuStmt->execute([
        ':item_id' => $menuItemId,
        ':merchant_id' => $merchantId
    ]);
    $menuItem = $menuStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$menuItem) {
        jsonResponse(false, null, 'Menu item not available', 404);
    }
    
    // Get or create cart session
    $cartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    if (!$cartSession) {
        jsonResponse(false, null, 'Failed to create cart session', 500);
    }
    
    // Check if item already exists in cart
    $existingQuery = "
        SELECT id, quantity FROM cart_items 
        WHERE cart_session_id = :session_id 
        AND menu_item_id = :item_id
        AND (customization = :customization OR (customization IS NULL AND :customization IS NULL))
    ";
    
    $existingStmt = $conn->prepare($existingQuery);
    $existingStmt->execute([
        ':session_id' => $cartSession['id'],
        ':item_id' => $menuItemId,
        ':customization' => $customization
    ]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    $unitPrice = $menuItem['discounted_price'] ?: $menuItem['price'];
    
    if ($existingItem) {
        // Update existing item quantity
        $newQuantity = $existingItem['quantity'] + $quantity;
        if ($newQuantity > 50) $newQuantity = 50;
        
        $totalPrice = $unitPrice * $newQuantity;
        
        $updateQuery = "
            UPDATE cart_items 
            SET quantity = :quantity, 
                total_price = :total_price, 
                updated_at = NOW()
            WHERE id = :item_id
        ";
        
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':total_price' => $totalPrice,
            ':item_id' => $existingItem['id']
        ]);
        
        $itemId = $existingItem['id'];
        $message = 'Item quantity updated';
    } else {
        // Add new item
        $totalPrice = $unitPrice * $quantity;
        
        $insertQuery = "
            INSERT INTO cart_items (
                cart_session_id, menu_item_id, item_name, item_description,
                quantity, unit_price, total_price, image_url, in_stock,
                customization, special_instructions,
                created_at, updated_at
            ) VALUES (
                :session_id, :item_id, :name, :description,
                :quantity, :unit_price, :total_price, :image_url, :in_stock,
                :customization, :special_instructions,
                NOW(), NOW()
            )
        ";
        
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->execute([
            ':session_id' => $cartSession['id'],
            ':item_id' => $menuItemId,
            ':name' => $menuItem['name'],
            ':description' => $menuItem['description'] ?? '',
            ':quantity' => $quantity,
            ':unit_price' => $unitPrice,
            ':total_price' => $totalPrice,
            ':image_url' => $menuItem['image_url'] ?: 'default-menu-item.jpg',
            ':in_stock' => $menuItem['in_stock'],
            ':customization' => $customization,
            ':special_instructions' => $specialInstructions
        ]);
        
        $itemId = $conn->lastInsertId();
        $message = 'Item added to cart';
    }
    
    // Update cart session timestamp
    $updateSessionQuery = "
        UPDATE cart_sessions 
        SET updated_at = NOW() 
        WHERE id = :session_id
    ";
    $updateSessionStmt = $conn->prepare($updateSessionQuery);
    $updateSessionStmt->execute([':session_id' => $cartSession['id']]);
    
    // Return updated cart
    $updatedCart = getCart($conn, $userId, $sessionId, $merchantId);
    
    jsonResponse(true, [
        'cartItemId' => $itemId,
        'cart' => $updatedCart
    ], $message);
}

/**
 * Update cart item
 */
function updateCartItem($conn, $userId, $sessionId, $data) {
    $itemId = intval($data['item_id'] ?? $data['cartItemId'] ?? 0);
    $quantity = max(0, intval($data['quantity'] ?? 1));
    
    if (!$itemId) {
        jsonResponse(false, null, 'Cart item ID is required', 400);
    }
    
    // Get cart item with user validation
    $query = "
        SELECT 
            ci.*, 
            cs.user_id, 
            cs.restaurant_id as merchant_id
        FROM cart_items ci
        JOIN cart_sessions cs ON ci.cart_session_id = cs.id
        WHERE ci.id = :item_id 
        AND cs.user_id = :user_id
        AND cs.status = 'active'
        AND cs.expires_at > NOW()
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':item_id' => $itemId,
        ':user_id' => $userId
    ]);
    $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        jsonResponse(false, null, 'Cart item not found', 404);
    }
    
    if ($quantity === 0) {
        // Delete item
        $deleteQuery = "DELETE FROM cart_items WHERE id = :item_id";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->execute([':item_id' => $itemId]);
        $message = 'Item removed from cart';
    } else {
        // Update quantity
        if ($quantity > 50) $quantity = 50;
        
        $totalPrice = $cartItem['unit_price'] * $quantity;
        
        $updateQuery = "
            UPDATE cart_items 
            SET quantity = :quantity, 
                total_price = :total_price, 
                updated_at = NOW()
            WHERE id = :item_id
        ";
        
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':quantity' => $quantity,
            ':total_price' => $totalPrice,
            ':item_id' => $itemId
        ]);
        $message = 'Item quantity updated';
    }
    
    // Update cart session
    $updateSessionQuery = "
        UPDATE cart_sessions 
        SET updated_at = NOW() 
        WHERE id = :session_id
    ";
    $updateSessionStmt = $conn->prepare($updateSessionQuery);
    $updateSessionStmt->execute([':session_id' => $cartItem['cart_session_id']]);
    
    // Return updated cart
    $updatedCart = getCart($conn, $userId, $sessionId, $cartItem['merchant_id']);
    
    jsonResponse(true, [
        'cart' => $updatedCart
    ], $message);
}

/**
 * Remove item from cart
 */
function removeFromCart($conn, $userId, $sessionId, $data) {
    $itemId = intval($data['item_id'] ?? $data['cartItemId'] ?? 0);
    
    if (!$itemId) {
        jsonResponse(false, null, 'Cart item ID is required', 400);
    }
    
    // Get cart item with user validation
    $query = "
        SELECT 
            cs.restaurant_id as merchant_id,
            cs.id as cart_session_id
        FROM cart_items ci
        JOIN cart_sessions cs ON ci.cart_session_id = cs.id
        WHERE ci.id = :item_id 
        AND cs.user_id = :user_id
        AND cs.status = 'active'
        AND cs.expires_at > NOW()
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':item_id' => $itemId,
        ':user_id' => $userId
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        jsonResponse(false, null, 'Cart item not found', 404);
    }
    
    // Delete item
    $deleteQuery = "DELETE FROM cart_items WHERE id = :item_id";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->execute([':item_id' => $itemId]);
    
    // Update cart session timestamp
    $updateSessionQuery = "
        UPDATE cart_sessions 
        SET updated_at = NOW() 
        WHERE id = :session_id
    ";
    $updateSessionStmt = $conn->prepare($updateSessionQuery);
    $updateSessionStmt->execute([':session_id' => $result['cart_session_id']]);
    
    // Return updated cart
    $updatedCart = getCart($conn, $userId, $sessionId, $result['merchant_id']);
    
    jsonResponse(true, [
        'cart' => $updatedCart
    ], 'Item removed from cart');
}

/**
 * Calculate cart data
 */
function calculateCartData($items, $cartSession) {
    $subtotal = 0;
    $itemCount = 0;
    $processedItems = [];
    
    foreach ($items as $item) {
        $itemTotal = $item['total_price'];
        $subtotal += $itemTotal;
        $itemCount += $item['quantity'];
        
        $processedItems[] = [
            'id' => $item['id'],
            'cartItemId' => $item['id'],
            'menuItemId' => $item['menu_item_id'],
            'name' => $item['item_name'],
            'description' => $item['item_description'],
            'quantity' => (int) $item['quantity'],
            'unitPrice' => (float) $item['unit_price'],
            'price' => (float) $item['unit_price'],
            'total' => (float) $itemTotal,
            'image' => $item['image_url'] ?: 'default-menu-item.jpg',
            'inStock' => (bool) ($item['in_stock'] ?? 1),
            'merchantId' => $cartSession['restaurant_id'],
            'merchantName' => $cartSession['merchant_name'] ?? 'Merchant',
            'customization' => $item['customization'] ? json_decode($item['customization'], true) : null,
            'specialInstructions' => $item['special_instructions'] ?? '',
            'createdAt' => $item['created_at']
        ];
    }
    
    // Calculate totals
    $deliveryFee = (float) ($cartSession['delivery_fee'] ?? 0);
    $taxAmount = calculateTax($subtotal);
    $total = $subtotal + $deliveryFee + $taxAmount;
    $minOrder = (float) ($cartSession['min_order_amount'] ?? 0);
    
    return [
        'session' => [
            'id' => $cartSession['id'],
            'uuid' => $cartSession['uuid'],
            'sessionId' => $cartSession['session_id'],
            'merchantId' => $cartSession['restaurant_id'],
            'userId' => $cartSession['user_id'],
            'status' => $cartSession['status'],
            'createdAt' => $cartSession['created_at'],
            'deliveryFee' => $deliveryFee,
            'taxAmount' => $taxAmount,
            'totalAmount' => $total,
            'minOrder' => $minOrder,
            'estimatedDeliveryTime' => $cartSession['estimated_delivery_time'] ?? null,
            'merchantImage' => $cartSession['merchant_image'] ?? null
        ],
        'items' => $processedItems,
        'summary' => [
            'subtotal' => (float) $subtotal,
            'deliveryFee' => $deliveryFee,
            'taxAmount' => $taxAmount,
            'total' => $total,
            'itemCount' => $itemCount,
            'minOrder' => $minOrder,
            'meetsMinOrder' => $subtotal >= $minOrder
        ]
    ];
}

/**
 * Get empty cart data
 */
function getEmptyCartData() {
    return [
        'session' => null,
        'items' => [],
        'summary' => [
            'subtotal' => 0,
            'deliveryFee' => 0,
            'taxAmount' => 0,
            'total' => 0,
            'itemCount' => 0,
            'minOrder' => 0,
            'meetsMinOrder' => true
        ]
    ];
}

/**
 * Calculate tax
 */
function calculateTax($amount) {
    return $amount * 0.165; // 16.5% tax
}
?>