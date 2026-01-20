<?php
// api/cart.php - User-based cart system
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

// Get user ID from session or token
function getUserId() {
    // Check PHP session
    if (isset($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    // Check Authorization header
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
        // Validate token and get user ID
        // You'll need to implement your token validation logic
    }
    
    return null;
}

// Session ID handling
function getSessionId() {
    // Priority 1: X-Session-ID header
    $sessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? null;
    
    // Priority 2: PHP session ID for non-logged in users
    if (!$sessionId && session_id()) {
        $sessionId = session_id();
    }
    
    // Priority 3: Create new
    if (!$sessionId) {
        $sessionId = 'anon_' . bin2hex(random_bytes(12));
    }
    
    return $sessionId;
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = getUserId();
$sessionId = getSessionId();

// Store session ID for future requests
if (!isset($_SESSION['cart_session_id'])) {
    $_SESSION['cart_session_id'] = $sessionId;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
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
            switch ($action) {
                case 'add':
                    addToCart($conn, $userId, $sessionId, $input);
                    break;
                case 'update':
                    updateCartItem($conn, $userId, $sessionId, $input);
                    break;
                case 'clear':
                    clearCart($conn, $userId, $sessionId, $input);
                    break;
                case 'merge':
                    mergeCart($conn, $userId, $sessionId, $input);
                    break;
                default:
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
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}

// =============== HELPER FUNCTIONS ===============

/**
 * Get cart for user/session
 */
function getCart($conn, $userId, $sessionId, $merchantId = null) {
    // Try to get cart session based on user or session
    $cartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    
    if (!$cartSession) {
        return getEmptyCartData();
    }
    
    // Get cart items
    $query = "
        SELECT 
            ci.*,
            mi.image_url as item_image,
            mi.in_stock as item_in_stock,
            r.name as merchant_name,
            r.delivery_fee,
            r.id as merchant_id,
            r.min_order_amount,
            r.delivery_time_minutes,
            r.delivery_time_maxutes
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE ci.cart_session_id = :session_id
        AND ci.is_removed = 0
        ORDER BY ci.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':session_id' => $cartSession['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    return calculateCartData($items, $cartSession);
}

/**
 * Find or create cart session
 */
function findCartSession($conn, $userId, $sessionId, $merchantId = null) {
    $params = [];
    $conditions = [];
    
    // If user is logged in, prioritize user's cart
    if ($userId) {
        $conditions[] = "user_id = :user_id";
        $params[':user_id'] = $userId;
    } else {
        // For non-logged in users, use session ID
        $conditions[] = "session_id = :session_id AND user_id IS NULL";
        $params[':session_id'] = $sessionId;
    }
    
    // Add merchant filter if specified
    if ($merchantId) {
        $conditions[] = "restaurant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    // Active carts only
    $conditions[] = "status = 'active'";
    $conditions[] = "expires_at > NOW()";
    
    $query = "
        SELECT * FROM cart_sessions 
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY updated_at DESC 
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no session found, create new one
    if (!$session && $merchantId) {
        $session = createCartSession($conn, $userId, $sessionId, $merchantId);
    }
    
    return $session;
}

/**
 * Create new cart session
 */
function createCartSession($conn, $userId, $sessionId, $merchantId) {
    // Verify merchant exists and is active
    $merchantQuery = "
        SELECT id, name, delivery_fee, min_order_amount, is_active,
               delivery_time_minutes, delivery_time_maxutes
        FROM restaurants 
        WHERE id = :merchant_id AND is_active = 1
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
            status, expires_at, created_at, updated_at
        ) VALUES (
            :uuid, :user_id, :merchant_id, :session_id, 
            'active', :expires_at, NOW(), NOW()
        )
    ";
    
    try {
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->execute([
            ':uuid' => $uuid,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':session_id' => $sessionId,
            ':expires_at' => $expiresAt
        ]);
        
        $cartSessionId = $conn->lastInsertId();
        
        // Get the newly created session
        $getQuery = "SELECT * FROM cart_sessions WHERE id = :id";
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
 * Merge anonymous cart with user cart on login
 */
function mergeCart($conn, $userId, $sessionId, $data) {
    if (!$userId) {
        jsonResponse(false, null, 'User ID required for merge', 400);
    }
    
    // Get anonymous cart (session cart without user ID)
    $anonQuery = "
        SELECT cs.* FROM cart_sessions cs
        WHERE cs.session_id = :session_id 
        AND cs.user_id IS NULL
        AND cs.status = 'active'
        AND cs.expires_at > NOW()
    ";
    
    $anonStmt = $conn->prepare($anonQuery);
    $anonStmt->execute([':session_id' => $sessionId]);
    $anonSessions = $anonStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($anonSessions)) {
        jsonResponse(true, ['merged' => false], 'No anonymous cart to merge');
    }
    
    $mergedItems = 0;
    
    foreach ($anonSessions as $anonSession) {
        $merchantId = $anonSession['restaurant_id'];
        
        // Get user's existing cart for this merchant
        $userQuery = "
            SELECT id FROM cart_sessions 
            WHERE user_id = :user_id 
            AND restaurant_id = :merchant_id
            AND status = 'active'
            AND expires_at > NOW()
            LIMIT 1
        ";
        
        $userStmt = $conn->prepare($userQuery);
        $userStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        $userSession = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userSession) {
            // Merge items into existing user cart
            $mergeQuery = "
                UPDATE cart_items 
                SET cart_session_id = :user_session_id,
                    updated_at = NOW()
                WHERE cart_session_id = :anon_session_id
                AND is_removed = 0
            ";
            
            $mergeStmt = $conn->prepare($mergeQuery);
            $mergeStmt->execute([
                ':user_session_id' => $userSession['id'],
                ':anon_session_id' => $anonSession['id']
            ]);
            
            $mergedItems += $mergeStmt->rowCount();
            
            // Update anonymous cart status
            $updateQuery = "
                UPDATE cart_sessions 
                SET status = 'merged', 
                    updated_at = NOW(),
                    merged_to_user_id = :user_id
                WHERE id = :anon_session_id
            ";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->execute([
                ':user_id' => $userId,
                ':anon_session_id' => $anonSession['id']
            ]);
            
        } else {
            // Transfer entire cart session to user
            $transferQuery = "
                UPDATE cart_sessions 
                SET user_id = :user_id,
                    session_id = :new_session_id,
                    updated_at = NOW()
                WHERE id = :session_id
            ";
            
            $transferStmt = $conn->prepare($transferQuery);
            $transferStmt->execute([
                ':user_id' => $userId,
                ':new_session_id' => 'user_' . $userId . '_' . $merchantId,
                ':session_id' => $anonSession['id']
            ]);
            
            // Get item count
            $countQuery = "
                SELECT COUNT(*) as item_count 
                FROM cart_items 
                WHERE cart_session_id = :session_id 
                AND is_removed = 0
            ";
            $countStmt = $conn->prepare($countQuery);
            $countStmt->execute([':session_id' => $anonSession['id']]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            
            $mergedItems += $countResult['item_count'];
        }
    }
    
    // Return merged cart
    $mergedCart = getCart($conn, $userId, $sessionId, null);
    
    jsonResponse(true, [
        'merged' => true,
        'itemsMerged' => $mergedItems,
        'cart' => $mergedCart
    ], 'Cart merged successfully');
}

/**
 * Add item to cart with user context
 */
function addToCart($conn, $userId, $sessionId, $data) {
    $menuItemId = intval($data['menu_item_id'] ?? 0);
    $merchantId = intval($data['merchant_id'] ?? 0);
    $quantity = max(1, intval($data['quantity'] ?? 1));
    $customization = isset($data['customization']) ? json_encode($data['customization']) : '{}';
    $specialInstructions = $data['special_instructions'] ?? '';
    
    if (!$menuItemId || !$merchantId) {
        jsonResponse(false, null, 'Menu item and merchant are required', 400);
    }
    
    // Get menu item with validation
    $menuQuery = "
        SELECT mi.*, r.delivery_fee, r.min_order_amount
        FROM menu_items mi
        INNER JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE mi.id = :item_id 
        AND r.id = :merchant_id
        AND mi.in_stock = 1
        AND r.is_active = 1
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
    
    // Check if same item with same customization exists
    $existingQuery = "
        SELECT id, quantity FROM cart_items 
        WHERE cart_session_id = :session_id 
        AND menu_item_id = :item_id
        AND customization = :customization
        AND is_removed = 0
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
 * Update cart item (user-aware)
 */
function updateCartItem($conn, $userId, $sessionId, $data) {
    $itemId = intval($data['item_id'] ?? $data['cartItemId'] ?? 0);
    $quantity = max(0, intval($data['quantity'] ?? 1));
    
    if (!$itemId) {
        jsonResponse(false, null, 'Cart item ID is required', 400);
    }
    
    // Get cart item with user validation
    $query = "
        SELECT ci.*, cs.user_id, cs.restaurant_id as merchant_id
        FROM cart_items ci
        JOIN cart_sessions cs ON ci.cart_session_id = cs.id
        WHERE ci.id = :item_id 
        AND (cs.session_id = :session_id OR cs.user_id = :user_id)
        AND ci.is_removed = 0
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':item_id' => $itemId,
        ':session_id' => $sessionId,
        ':user_id' => $userId ?: 0
    ]);
    $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        jsonResponse(false, null, 'Cart item not found', 404);
    }
    
    if ($quantity === 0) {
        // Soft delete item
        $deleteQuery = "
            UPDATE cart_items 
            SET is_removed = 1, updated_at = NOW() 
            WHERE id = :item_id
        ";
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
            'specialInstructions' => $item['special_instructions'],
            'customization' => json_decode($item['customization'] ?? '{}', true),
            'image' => $item['item_image'] ?: 'default-menu-item.jpg',
            'inStock' => (bool) ($item['item_in_stock'] ?? 1),
            'merchantId' => $item['merchant_id'],
            'merchantName' => $item['merchant_name'],
            'deliveryTime' => $item['delivery_time_minutes'] 
                ? $item['delivery_time_minutes'] . '-' . $item['delivery_time_maxutes'] . ' min'
                : '30-45 min',
            'createdAt' => $item['created_at']
        ];
    }
    
    $deliveryFee = $cartSession['delivery_fee'] ?? ($items[0]['delivery_fee'] ?? 0);
    $taxAmount = calculateTax($subtotal);
    $total = $subtotal + $deliveryFee + $taxAmount;
    $minOrder = $items[0]['min_order_amount'] ?? 0;
    
    return [
        'session' => [
            'id' => $cartSession['id'],
            'uuid' => $cartSession['uuid'],
            'sessionId' => $cartSession['session_id'],
            'merchantId' => $cartSession['restaurant_id'],
            'userId' => $cartSession['user_id'],
            'status' => $cartSession['status'],
            'createdAt' => $cartSession['created_at'],
            'userSpecific' => $cartSession['user_id'] != null
        ],
        'items' => $processedItems,
        'summary' => [
            'subtotal' => (float) $subtotal,
            'deliveryFee' => (float) $deliveryFee,
            'taxAmount' => (float) $taxAmount,
            'total' => (float) $total,
            'itemCount' => $itemCount,
            'minOrder' => (float) $minOrder,
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
        'summary' => getEmptySummary()
    ];
}

/**
 * Get empty summary
 */
function getEmptySummary() {
    return [
        'subtotal' => 0,
        'deliveryFee' => 0,
        'taxAmount' => 0,
        'total' => 0,
        'itemCount' => 0,
        'minOrder' => 0,
        'meetsMinOrder' => true
    ];
}

/**
 * Calculate tax
 */
function calculateTax($amount) {
    return $amount * 0.165; // 16.5% VAT for Malawi
}
?>