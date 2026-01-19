<?php
// api/cart.php - Fixed Shopping Cart API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session only if not already started
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

// Generate session ID if not provided
function getSessionId() {
    $sessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? null;
    
    if (!$sessionId && isset($_COOKIE['PHPSESSID'])) {
        $sessionId = $_COOKIE['PHPSESSID'];
    }
    
    if (!$sessionId) {
        $sessionId = 'web_' . bin2hex(random_bytes(16));
    }
    
    return $sessionId;
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'] ?? null;
$sessionId = getSessionId();

// Store session ID for future use
if (!isset($_SESSION['cart_session_id'])) {
    $_SESSION['cart_session_id'] = $sessionId;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // For debugging - log request
    error_log("Cart API Request: Method=$method, SessionID=$sessionId, UserID=" . ($userId ?? 'null'));
    
    switch ($method) {
        case 'GET':
            // Get cart with merchant_id from query params
            $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
            $cart = getCart($conn, $userId, $sessionId, $merchantId);
            jsonResponse(true, $cart, 'Cart retrieved successfully');
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST; // Fallback to form data
            }
            
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
            if (!$input) {
                jsonResponse(false, null, 'Invalid JSON data', 400);
            }
            updateCartItem($conn, $userId, $sessionId, $input);
            break;
            
        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_GET;
            }
            removeFromCart($conn, $userId, $sessionId, $input);
            break;
            
        default:
            jsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Cart API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}

// =============== HELPER FUNCTIONS ===============

/**
 * Get cart for user/session
 */
function getCart($conn, $userId, $sessionId, $merchantId = null) {
    // Try multiple ways to find cart
    $cartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    
    if (!$cartSession) {
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
    
    // Get cart items
    $query = "
        SELECT 
            ci.*,
            mi.image_url as item_image,
            mi.in_stock as item_in_stock,
            mi.uuid as item_uuid,
            r.name as merchant_name,
            r.delivery_fee,
            r.id as merchant_id,
            r.min_order_amount,
            r.is_active as restaurant_active
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
            'inStock' => (bool) $item['item_in_stock'],
            'merchantId' => $item['merchant_id'],
            'merchantName' => $item['merchant_name'],
            'createdAt' => $item['created_at']
        ];
    }
    
    $deliveryFee = $cartSession['delivery_fee'] ?? ($items[0]['delivery_fee'] ?? 0);
    $taxAmount = $cartSession['tax_amount'] ?? calculateTax($subtotal);
    $total = $subtotal + $deliveryFee + $taxAmount;
    $minOrder = $items[0]['min_order_amount'] ?? 0;
    
    // Update cart session totals
    if ($cartSession['id']) {
        $updateQuery = "
            UPDATE cart_sessions 
            SET item_count = :item_count,
                subtotal = :subtotal,
                total_amount = :total,
                updated_at = NOW()
            WHERE id = :session_id
        ";
        
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':item_count' => $itemCount,
            ':subtotal' => $subtotal,
            ':total' => $total,
            ':session_id' => $cartSession['id']
        ]);
    }
    
    return [
        'session' => [
            'id' => $cartSession['uuid'] ?? $cartSession['id'],
            'sessionId' => $cartSession['id'],
            'merchantId' => $cartSession['restaurant_id'],
            'status' => $cartSession['status'],
            'createdAt' => $cartSession['created_at']
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
 * Find cart session using multiple strategies
 */
function findCartSession($conn, $userId, $sessionId, $merchantId = null) {
    // Strategy 1: Find by user ID and merchant
    if ($userId && $merchantId) {
        $query = "
            SELECT * FROM cart_sessions 
            WHERE user_id = :user_id 
                AND restaurant_id = :merchant_id
                AND status = 'active'
                AND expires_at > NOW()
            ORDER BY updated_at DESC LIMIT 1
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            error_log("Found cart by user_id and merchant_id");
            return $session;
        }
    }
    
    // Strategy 2: Find by session ID and merchant
    if ($merchantId) {
        $query = "
            SELECT * FROM cart_sessions 
            WHERE session_id = :session_id 
                AND restaurant_id = :merchant_id
                AND status = 'active'
                AND expires_at > NOW()
            ORDER BY updated_at DESC LIMIT 1
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':merchant_id' => $merchantId
        ]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            error_log("Found cart by session_id and merchant_id");
            return $session;
        }
    }
    
    // Strategy 3: Find any active session for this user/session
    $query = "
        SELECT * FROM cart_sessions 
        WHERE (user_id = :user_id OR session_id = :session_id)
            AND status = 'active'
            AND expires_at > NOW()
        ORDER BY updated_at DESC LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':user_id' => $userId,
        ':session_id' => $sessionId
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        error_log("Found cart by user_id or session_id");
        return $session;
    }
    
    // Strategy 4: Create new session if merchant provided
    if ($merchantId) {
        return createCartSession($conn, $userId, $sessionId, $merchantId);
    }
    
    return null;
}

/**
 * Create new cart session
 */
function createCartSession($conn, $userId, $sessionId, $merchantId) {
    // Verify merchant exists
    $merchantQuery = "
        SELECT id, name, delivery_fee, is_active
        FROM restaurants 
        WHERE id = :merchant_id AND is_active = 1
    ";
    $merchantStmt = $conn->prepare($merchantQuery);
    $merchantStmt->execute([':merchant_id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$merchant) {
        error_log("Merchant not found or inactive: $merchantId");
        return null;
    }
    
    $uuid = 'cart_' . bin2hex(random_bytes(8));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $insertQuery = "
        INSERT INTO cart_sessions (
            uuid, user_id, restaurant_id, session_id, 
            status, expires_at, created_at, updated_at
        ) VALUES (:uuid, :user_id, :merchant_id, :session_id, 
                 'active', :expires_at, NOW(), NOW())
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
        
        error_log("Created new cart session: ID=$cartSessionId, Merchant=$merchantId");
        return $session;
        
    } catch (Exception $e) {
        error_log("Failed to create cart session: " . $e->getMessage());
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
    $specialInstructions = isset($data['special_instructions']) ? trim($data['special_instructions']) : '';
    $customization = isset($data['customization']) ? $data['customization'] : null;
    
    error_log("Add to cart: MenuItem=$menuItemId, Merchant=$merchantId, Qty=$quantity");
    
    if (!$menuItemId || !$merchantId) {
        jsonResponse(false, null, 'Menu item and merchant are required', 400);
    }
    
    if ($quantity > 50) {
        jsonResponse(false, null, 'Maximum quantity per item is 50', 400);
    }
    
    // Get menu item details
    $menuQuery = "
        SELECT 
            mi.*, 
            r.id as restaurant_id,
            r.name as merchant_name,
            r.delivery_fee,
            r.min_order_amount,
            r.is_active as restaurant_active
        FROM menu_items mi
        INNER JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE mi.id = :item_id 
            AND mi.is_active = 1 
            AND r.id = :merchant_id
            AND r.is_active = 1
    ";
    
    $menuStmt = $conn->prepare($menuQuery);
    $menuStmt->execute([
        ':item_id' => $menuItemId,
        ':merchant_id' => $merchantId
    ]);
    $menuItem = $menuStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$menuItem) {
        error_log("Menu item not found or inactive: ID=$menuItemId, Merchant=$merchantId");
        jsonResponse(false, null, 'Menu item not found or unavailable', 404);
    }
    
    if (!$menuItem['in_stock']) {
        jsonResponse(false, null, 'This item is currently out of stock', 400);
    }
    
    // Get or create cart session
    $cartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    if (!$cartSession) {
        jsonResponse(false, null, 'Failed to create cart session', 500);
    }
    
    // Check if item already in cart
    $existingQuery = "
        SELECT id, quantity, total_price 
        FROM cart_items 
        WHERE cart_session_id = :session_id 
            AND menu_item_id = :item_id
    ";
    
    $existingStmt = $conn->prepare($existingQuery);
    $existingStmt->execute([
        ':session_id' => $cartSession['id'],
        ':item_id' => $menuItemId
    ]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate price
    $unitPrice = $menuItem['discounted_price'] ?: $menuItem['price'];
    
    if ($existingItem) {
        // Update existing item
        $newQuantity = $existingItem['quantity'] + $quantity;
        if ($newQuantity > 50) {
            $newQuantity = 50;
        }
        
        $totalPrice = $unitPrice * $newQuantity;
        
        $updateQuery = "
            UPDATE cart_items 
            SET quantity = :quantity,
                total_price = :total_price,
                special_instructions = :instructions,
                customization = :customization,
                updated_at = NOW()
            WHERE id = :item_id
        ";
        
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':total_price' => $totalPrice,
            ':instructions' => $specialInstructions,
            ':customization' => $customization ? json_encode($customization) : null,
            ':item_id' => $existingItem['id']
        ]);
        
        $itemId = $existingItem['id'];
        $message = 'Item quantity updated';
        $action = 'updated';
        
    } else {
        // Add new item
        $totalPrice = $unitPrice * $quantity;
        
        $insertQuery = "
            INSERT INTO cart_items (
                cart_session_id, 
                menu_item_id, 
                item_name, 
                item_description,
                quantity, 
                unit_price, 
                total_price, 
                special_instructions,
                customization, 
                image_url, 
                in_stock, 
                created_at, 
                updated_at
            ) VALUES (
                :session_id, 
                :item_id, 
                :name, 
                :description,
                :quantity, 
                :unit_price, 
                :total_price, 
                :instructions,
                :customization, 
                :image_url, 
                :in_stock, 
                NOW(), 
                NOW()
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
            ':instructions' => $specialInstructions,
            ':customization' => $customization ? json_encode($customization) : null,
            ':image_url' => $menuItem['image_url'] ?: 'default-menu-item.jpg',
            ':in_stock' => $menuItem['in_stock']
        ]);
        
        $itemId = $conn->lastInsertId();
        $message = 'Item added to cart';
        $action = 'added';
    }
    
    // Get updated cart
    $updatedCart = getCart($conn, $userId, $sessionId, $merchantId);
    
    jsonResponse(true, [
        'action' => $action,
        'cartItemId' => $itemId,
        'menuItemId' => $menuItemId,
        'cart' => $updatedCart
    ], $message);
}

/**
 * Update cart item quantity
 */
function updateCartItem($conn, $userId, $sessionId, $data) {
    $itemId = intval($data['item_id'] ?? $data['cartItemId'] ?? 0);
    $quantity = max(0, intval($data['quantity'] ?? 1));
    $specialInstructions = isset($data['special_instructions']) ? trim($data['special_instructions']) : null;
    $customization = isset($data['customization']) ? $data['customization'] : null;
    
    if (!$itemId) {
        jsonResponse(false, null, 'Cart item ID is required', 400);
    }
    
    if ($quantity > 50) {
        jsonResponse(false, null, 'Maximum quantity per item is 50', 400);
    }
    
    // Get cart item with merchant info
    $query = "
        SELECT 
            ci.*, 
            cs.restaurant_id as merchant_id,
            cs.uuid as cart_uuid
        FROM cart_items ci
        JOIN cart_sessions cs ON ci.cart_session_id = cs.id
        WHERE ci.id = :item_id
            AND (cs.user_id = :user_id OR cs.session_id = :session_id OR cs.uuid = :session_id)
            AND cs.status = 'active'
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':item_id' => $itemId,
        ':user_id' => $userId,
        ':session_id' => $sessionId
    ]);
    $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        jsonResponse(false, null, 'Cart item not found', 404);
    }
    
    if ($quantity === 0) {
        // Remove item
        $deleteQuery = "DELETE FROM cart_items WHERE id = :item_id";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->execute([':item_id' => $itemId]);
        
        $message = 'Item removed from cart';
        $action = 'removed';
        
    } else {
        // Update item
        $totalPrice = $cartItem['unit_price'] * $quantity;
        
        $updateQuery = "
            UPDATE cart_items 
            SET quantity = :quantity,
                total_price = :total_price,
                special_instructions = COALESCE(:instructions, special_instructions),
                customization = COALESCE(:customization, customization),
                updated_at = NOW()
            WHERE id = :item_id
        ";
        
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':quantity' => $quantity,
            ':total_price' => $totalPrice,
            ':instructions' => $specialInstructions,
            ':customization' => $customization ? json_encode($customization) : null,
            ':item_id' => $itemId
        ]);
        
        $message = 'Item quantity updated';
        $action = 'updated';
    }
    
    // Get updated cart
    $updatedCart = getCart($conn, $userId, $sessionId, $cartItem['merchant_id']);
    
    jsonResponse(true, [
        'action' => $action,
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
    
    // Get merchant_id before deletion
    $query = "
        SELECT cs.restaurant_id as merchant_id
        FROM cart_items ci
        JOIN cart_sessions cs ON ci.cart_session_id = cs.id
        WHERE ci.id = :item_id
            AND (cs.user_id = :user_id OR cs.session_id = :session_id)
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':item_id' => $itemId,
        ':user_id' => $userId,
        ':session_id' => $sessionId
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        jsonResponse(false, null, 'Cart item not found', 404);
    }
    
    // Delete item
    $deleteQuery = "DELETE FROM cart_items WHERE id = :item_id";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->execute([':item_id' => $itemId]);
    
    // Get updated cart
    $updatedCart = getCart($conn, $userId, $sessionId, $result['merchant_id']);
    
    jsonResponse(true, [
        'action' => 'removed',
        'cart' => $updatedCart
    ], 'Item removed from cart');
}

/**
 * Clear entire cart
 */
function clearCart($conn, $userId, $sessionId, $data) {
    $merchantId = isset($data['merchant_id']) ? intval($data['merchant_id']) : null;
    
    if (!$merchantId) {
        jsonResponse(false, null, 'Merchant ID is required to clear cart', 400);
    }
    
    // Get cart session
    $cartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    
    if (!$cartSession) {
        jsonResponse(true, ['cart' => getCart($conn, $userId, $sessionId, $merchantId)], 'Cart is already empty');
    }
    
    // Delete all items
    $deleteQuery = "DELETE FROM cart_items WHERE cart_session_id = :session_id";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->execute([':session_id' => $cartSession['id']]);
    
    // Update session
    $updateQuery = "
        UPDATE cart_sessions 
        SET item_count = 0,
            subtotal = 0,
            total_amount = 0,
            updated_at = NOW()
        WHERE id = :session_id
    ";
    
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute([':session_id' => $cartSession['id']]);
    
    $updatedCart = getCart($conn, $userId, $sessionId, $merchantId);
    
    jsonResponse(true, [
        'action' => 'cleared',
        'cart' => $updatedCart
    ], 'Cart cleared successfully');
}

/**
 * Merge guest cart with user cart after login
 */
function mergeCart($conn, $userId, $sessionId, $data) {
    $guestSessionId = $data['guest_session_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    
    if (!$guestSessionId || !$merchantId) {
        jsonResponse(false, null, 'Guest session ID and merchant ID required', 400);
    }
    
    // Get guest cart
    $guestCart = getCart($conn, null, $guestSessionId, $merchantId);
    
    if (empty($guestCart['items'])) {
        jsonResponse(true, ['cart' => getCart($conn, $userId, $sessionId, $merchantId)], 'No items to merge');
    }
    
    // Get or create user cart session
    $userCartSession = findCartSession($conn, $userId, $sessionId, $merchantId);
    
    if (!$userCartSession) {
        $userCartSession = createCartSession($conn, $userId, $sessionId, $merchantId);
    }
    
    // Merge items
    foreach ($guestCart['items'] as $item) {
        // Check if item already in user cart
        $existingQuery = "
            SELECT id, quantity 
            FROM cart_items 
            WHERE cart_session_id = :session_id 
                AND menu_item_id = :item_id
        ";
        
        $existingStmt = $conn->prepare($existingQuery);
        $existingStmt->execute([
            ':session_id' => $userCartSession['id'],
            ':item_id' => $item['menuItemId']
        ]);
        $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingItem) {
            // Update quantity
            $newQuantity = $existingItem['quantity'] + $item['quantity'];
            if ($newQuantity > 50) $newQuantity = 50;
            
            $updateQuery = "
                UPDATE cart_items 
                SET quantity = :quantity,
                    total_price = unit_price * :quantity,
                    updated_at = NOW()
                WHERE id = :item_id
            ";
            
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->execute([
                ':quantity' => $newQuantity,
                ':item_id' => $existingItem['id']
            ]);
        } else {
            // Add new item
            $insertQuery = "
                INSERT INTO cart_items (
                    cart_session_id, menu_item_id, item_name, item_description,
                    quantity, unit_price, total_price, special_instructions,
                    customization, image_url, in_stock, created_at, updated_at
                ) VALUES (
                    :session_id, :item_id, :name, :description,
                    :quantity, :unit_price, :total_price, :instructions,
                    :customization, :image_url, :in_stock, NOW(), NOW()
                )
            ";
            
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->execute([
                ':session_id' => $userCartSession['id'],
                ':item_id' => $item['menuItemId'],
                ':name' => $item['name'],
                ':description' => $item['description'] ?? '',
                ':quantity' => $item['quantity'],
                ':unit_price' => $item['unitPrice'],
                ':total_price' => $item['total'],
                ':instructions' => $item['specialInstructions'] ?? '',
                ':customization' => json_encode($item['customization'] ?? []),
                ':image_url' => $item['image'] ?? 'default-menu-item.jpg',
                ':in_stock' => $item['inStock'] ?? 1
            ]);
        }
    }
    
    // Clear guest cart
    clearCart($conn, null, $guestSessionId, ['merchant_id' => $merchantId]);
    
    $mergedCart = getCart($conn, $userId, $sessionId, $merchantId);
    
    jsonResponse(true, [
        'action' => 'merged',
        'cart' => $mergedCart
    ], 'Cart merged successfully');
}

/**
 * Calculate tax amount
 */
function calculateTax($amount) {
    // 16.5% VAT for Malawi
    return $amount * 0.165;
}
?>