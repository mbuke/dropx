<?php
// api/cart.php - COMPLETE WORKING VERSION
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
session_start();
require_once __DIR__ . '/../config/database.php';

function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
            
        case 'POST':
            handlePost($conn);
            break;
            
        case 'PUT':
            handlePut($conn);
            break;
            
        case 'DELETE':
            handleDelete($conn);
            break;
            
        default:
            jsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}

// =============== HANDLERS ===============

function handleGet($conn) {
    $sessionId = getSessionId();
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : 1;
    
    // Find cart session
    $cartSession = findOrCreateCartSession($conn, $sessionId, $merchantId);
    
    if (!$cartSession) {
        jsonResponse(true, getEmptyCartData(), 'Cart is empty');
        return;
    }
    
    // Get cart items with merchant info
    $query = "
        SELECT 
            ci.*,
            mi.name as item_name,
            mi.description as item_description,
            mi.image_url,
            mi.in_stock,
            r.name as merchant_name,
            r.delivery_fee,
            r.min_order_amount,
            r.id as merchant_id
        FROM cart_items ci
        JOIN menu_items mi ON ci.menu_item_id = mi.id
        JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE ci.cart_session_id = :session_id
        ORDER BY ci.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':session_id' => $cartSession['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = calculateSummary($items);
    
    // Format response
    $response = [
        'session' => [
            'id' => $cartSession['id'],
            'merchantId' => $cartSession['restaurant_id']
        ],
        'items' => formatCartItems($items),
        'summary' => $summary
    ];
    
    jsonResponse(true, $response, 'Cart retrieved');
}

function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    // Check for merge action
    if (isset($input['action']) && $input['action'] === 'merge') {
        handleMerge($conn, $input);
        return;
    }
    
    // Add item to cart
    $menuItemId = intval($input['menu_item_id'] ?? 0);
    $merchantId = intval($input['merchant_id'] ?? 0);
    $quantity = max(1, intval($input['quantity'] ?? 1));
    
    if (!$menuItemId || !$merchantId) {
        jsonResponse(false, null, 'Missing required fields', 400);
    }
    
    $sessionId = getSessionId();
    $cartSession = findOrCreateCartSession($conn, $sessionId, $merchantId);
    
    if (!$cartSession) {
        jsonResponse(false, null, 'Failed to create cart session', 500);
    }
    
    // Get menu item details
    $priceQuery = "
        SELECT mi.*, r.name as merchant_name 
        FROM menu_items mi
        JOIN restaurants r ON mi.restaurant_id = r.id
        WHERE mi.id = :id AND r.id = :merchant_id
    ";
    $priceStmt = $conn->prepare($priceQuery);
    $priceStmt->execute([
        ':id' => $menuItemId,
        ':merchant_id' => $merchantId
    ]);
    $menuItem = $priceStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$menuItem) {
        jsonResponse(false, null, 'Menu item not found', 404);
    }
    
    $unitPrice = $menuItem['discounted_price'] ?: $menuItem['price'];
    
    // Check if item exists in cart
    $checkQuery = "
        SELECT id, quantity 
        FROM cart_items 
        WHERE cart_session_id = :session_id 
        AND menu_item_id = :item_id
    ";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->execute([
        ':session_id' => $cartSession['id'],
        ':item_id' => $menuItemId
    ]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update quantity
        $newQty = $existing['quantity'] + $quantity;
        if ($newQty > 50) $newQty = 50;
        
        $newTotal = $unitPrice * $newQty;
        
        $updateQuery = "
            UPDATE cart_items 
            SET quantity = :qty, 
                total_price = :total,
                updated_at = NOW()
            WHERE id = :id
        ";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':qty' => $newQty,
            ':total' => $newTotal,
            ':id' => $existing['id']
        ]);
        
        $itemId = $existing['id'];
        $message = 'Item quantity updated';
    } else {
        // Insert new item
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
                image_url,
                in_stock,
                created_at,
                updated_at
            ) VALUES (
                :session_id, 
                :item_id, 
                :name,
                :description,
                :qty, 
                :price, 
                :total,
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
            ':qty' => $quantity,
            ':price' => $unitPrice,
            ':total' => $totalPrice,
            ':image_url' => $menuItem['image_url'] ?: 'default-menu-item.jpg',
            ':in_stock' => $menuItem['in_stock'] ? 1 : 0
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
    handleGet($conn);
}

function handlePut($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $itemId = intval($input['item_id'] ?? $input['cartItemId'] ?? 0);
    $quantity = intval($input['quantity'] ?? 1);
    
    if (!$itemId) {
        jsonResponse(false, null, 'Item ID required', 400);
    }
    
    if ($quantity < 1) {
        // Delete instead
        handleDelete($conn);
        return;
    }
    
    // Limit quantity
    if ($quantity > 50) $quantity = 50;
    
    // Get current item to calculate new total
    $getQuery = "SELECT unit_price FROM cart_items WHERE id = :id";
    $getStmt = $conn->prepare($getQuery);
    $getStmt->execute([':id' => $itemId]);
    $item = $getStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        jsonResponse(false, null, 'Item not found', 404);
    }
    
    $totalPrice = $item['unit_price'] * $quantity;
    
    $updateQuery = "
        UPDATE cart_items 
        SET quantity = :qty, 
            total_price = :total,
            updated_at = NOW() 
        WHERE id = :id
    ";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute([
        ':qty' => $quantity,
        ':total' => $totalPrice,
        ':id' => $itemId
    ]);
    
    // Return updated cart
    handleGet($conn);
}

function handleDelete($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_GET;
    }
    
    $itemId = intval($input['item_id'] ?? $input['cartItemId'] ?? 0);
    
    if (!$itemId) {
        jsonResponse(false, null, 'Item ID required', 400);
    }
    
    $deleteQuery = "DELETE FROM cart_items WHERE id = :id";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->execute([':id' => $itemId]);
    
    // Return updated cart
    handleGet($conn);
}

// =============== UTILITIES ===============

function getSessionId() {
    $headers = getallheaders();
    $sessionId = $headers['X-Session-ID'] ?? $_COOKIE['PHPSESSID'] ?? session_id();
    
    if (!$sessionId) {
        $sessionId = 'cart_' . bin2hex(random_bytes(8));
    }
    
    return $sessionId;
}

function findOrCreateCartSession($conn, $sessionId, $merchantId) {
    // Try to find existing session
    $query = "
        SELECT * 
        FROM cart_sessions 
        WHERE session_id = :session_id 
        AND restaurant_id = :merchant_id 
        AND status = 'active'
        AND expires_at > NOW()
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':session_id' => $sessionId,
        ':merchant_id' => $merchantId
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        return $session;
    }
    
    // Create new session
    $uuid = 'cart_' . bin2hex(random_bytes(8));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $insertQuery = "
        INSERT INTO cart_sessions (
            uuid, 
            session_id, 
            restaurant_id, 
            status, 
            expires_at, 
            created_at,
            updated_at
        ) VALUES (
            :uuid, 
            :session_id, 
            :merchant_id, 
            'active', 
            :expires_at, 
            NOW(),
            NOW()
        )
    ";
    
    try {
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->execute([
            ':uuid' => $uuid,
            ':session_id' => $sessionId,
            ':merchant_id' => $merchantId,
            ':expires_at' => $expiresAt
        ]);
        
        $newSessionId = $conn->lastInsertId();
        
        $getQuery = "SELECT * FROM cart_sessions WHERE id = :id";
        $getStmt = $conn->prepare($getQuery);
        $getStmt->execute([':id' => $newSessionId]);
        
        return $getStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error creating cart session: " . $e->getMessage());
        return null;
    }
}

function handleMerge($conn, $input) {
    $userId = $_SESSION['user_id'] ?? $input['user_id'] ?? null;
    
    if (!$userId) {
        jsonResponse(false, null, 'User ID required for merge', 400);
    }
    
    $sessionId = getSessionId();
    
    // Find anonymous cart sessions
    $anonQuery = "
        SELECT cs.* 
        FROM cart_sessions cs
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
            SELECT id 
            FROM cart_sessions 
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
            // Update session ID for all items
            $mergeQuery = "
                UPDATE cart_items 
                SET cart_session_id = :user_session_id,
                    updated_at = NOW()
                WHERE cart_session_id = :anon_session_id
            ";
            
            $mergeStmt = $conn->prepare($mergeQuery);
            $mergeStmt->execute([
                ':user_session_id' => $userSession['id'],
                ':anon_session_id' => $anonSession['id']
            ]);
            
            $mergedItems += $mergeStmt->rowCount();
            
            // Mark anonymous session as merged
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
            
            // Count items
            $countQuery = "
                SELECT COUNT(*) as item_count 
                FROM cart_items 
                WHERE cart_session_id = :session_id
            ";
            $countStmt = $conn->prepare($countQuery);
            $countStmt->execute([':session_id' => $anonSession['id']]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            
            $mergedItems += $countResult['item_count'];
        }
    }
    
    // Return success
    jsonResponse(true, [
        'merged' => true,
        'itemsMerged' => $mergedItems
    ], 'Cart merged successfully');
}

function calculateSummary($items) {
    $subtotal = 0;
    $itemCount = 0;
    
    foreach ($items as $item) {
        $subtotal += floatval($item['total_price']);
        $itemCount += intval($item['quantity']);
    }
    
    $taxAmount = $subtotal * 0.165; // 16.5% tax
    $deliveryFee = floatval($items[0]['delivery_fee'] ?? 0);
    $total = $subtotal + $taxAmount + $deliveryFee;
    $minOrder = floatval($items[0]['min_order_amount'] ?? 0);
    
    return [
        'subtotal' => round($subtotal, 2),
        'taxAmount' => round($taxAmount, 2),
        'deliveryFee' => round($deliveryFee, 2),
        'total' => round($total, 2),
        'itemCount' => $itemCount,
        'minOrder' => $minOrder,
        'meetsMinOrder' => $subtotal >= $minOrder
    ];
}

function formatCartItems($items) {
    $formatted = [];
    
    foreach ($items as $item) {
        $formatted[] = [
            'id' => intval($item['id']),
            'cartItemId' => intval($item['id']),
            'menuItemId' => intval($item['menu_item_id']),
            'name' => $item['item_name'] ?? 'Item',
            'description' => $item['item_description'] ?? '',
            'quantity' => intval($item['quantity']),
            'unitPrice' => floatval($item['unit_price']),
            'price' => floatval($item['unit_price']),
            'total' => floatval($item['total_price']),
            'merchantId' => intval($item['merchant_id'] ?? 1),
            'merchantName' => $item['merchant_name'] ?? 'Merchant',
            'image' => $item['image_url'] ?: 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=150&h=150&fit=crop',
            'inStock' => boolval($item['in_stock'] ?? 1),
            'createdAt' => $item['created_at']
        ];
    }
    
    return $formatted;
}

function getEmptyCartData() {
    return [
        'session' => null,
        'items' => [],
        'summary' => [
            'subtotal' => 0,
            'taxAmount' => 0,
            'deliveryFee' => 0,
            'total' => 0,
            'itemCount' => 0,
            'minOrder' => 0,
            'meetsMinOrder' => true
        ]
    ];
}
?>