<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-Device-ID, X-Platform, X-App-Version");
header("Content-Type: application/json; charset=UTF-8");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG - MATCHING FLUTTER
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days - matches Flutter
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']), // Auto-detect for Railway
        'httponly' => true,
        'samesite' => 'Lax' // Better compatibility
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function checkAuthentication($conn) {
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE api_token = :token AND api_token_expiry > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            return $user['id'];
        }
    }
    
    $sessionToken = $headers['X-Session-Token'] ?? '';
    if ($sessionToken) {
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions WHERE session_token = :token AND expires_at > NOW()"
        );
        $stmt->execute([':token' => $sessionToken]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $_SESSION['user_id'] = $result['user_id'];
            return $result['user_id'];
        }
        
        if (session_id() !== $sessionToken) {
            session_id($sessionToken);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                return $_SESSION['user_id'];
            }
        }
    }
    
    if (!empty($_COOKIE['PHPSESSID'])) {
        if (session_id() !== $_COOKIE['PHPSESSID']) {
            session_id($_COOKIE['PHPSESSID']);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                return $_SESSION['user_id'];
            }
        }
    }
    
    return false;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropxbackend-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }

    switch ($method) {
        case 'GET':
            handleGetRequest($path, $_GET);
            break;
        case 'POST':
            handlePostRequest($path, $input);
            break;
        case 'PUT':
            handlePutRequest($path, $input);
            break;
        case 'DELETE':
            handleDeleteRequest($path, $input);
            break;
        default:
            ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($path, $queryParams) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    if (strpos($path, '/cart') !== false) {
        $cartId = $queryParams['cart_id'] ?? null;
        $action = $queryParams['action'] ?? '';
        
        if ($action === 'count') {
            getCartItemCount($conn, $userId);
        } elseif ($cartId) {
            getCartDetails($conn, $cartId, $baseUrl, $userId);
        } else {
            getCurrentCart($conn, $baseUrl, $userId);
        }
    } elseif (strpos($path, '/cart/items') !== false) {
        getCartItems($conn, $baseUrl, $userId);
    } else {
        ResponseHandler::error('Endpoint not found', 404);
    }
}

/*********************************
 * GET CURRENT CART
 *********************************/
function getCurrentCart($conn, $baseUrl, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::error('Failed to retrieve cart', 500);
    }
    
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    $promotions = getApplicablePromotions($conn, $userId, $totals['subtotal']);
    $appliedPromotion = getAppliedPromotion($conn, $cart['id']);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'cart' => [
                'id' => $cart['id'],
                'user_id' => $cart['user_id'],
                'status' => $cart['status'] ?? 'active',
                'created_at' => $cart['created_at'],
                'updated_at' => $cart['updated_at']
            ],
            'items' => $cartItems,
            'summary' => [
                'subtotal' => $totals['subtotal'],
                'delivery_fee' => $totals['delivery_fee'],
                'service_fee' => $totals['service_fee'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total_amount'],
                'item_count' => $totals['item_count'],
                'total_quantity' => $totals['total_quantity']
            ],
            'promotions' => $promotions,
            'applied_promotion' => $appliedPromotion,
            'is_eligible_for_checkout' => $totals['item_count'] > 0
        ]
    ]);
}

/*********************************
 * GET CART ITEM COUNT
 *********************************/
function getCartItemCount($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::success([
            'success' => true,
            'data' => [
                'item_count' => 0,
                'total_quantity' => 0,
                'has_cart' => false
            ]
        ]);
    }
    
    $stmt = $conn->prepare(
        "SELECT COUNT(ci.id) as item_count, SUM(ci.quantity) as total_quantity
         FROM cart_items ci
         WHERE ci.cart_id = :cart_id AND ci.is_active = 1"
    );
    
    $stmt->execute([':cart_id' => $cart['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'item_count' => intval($result['item_count'] ?? 0),
            'total_quantity' => intval($result['total_quantity'] ?? 0),
            'has_cart' => true,
            'cart_id' => $cart['id']
        ]
    ]);
}

/*********************************
 * GET APPLIED PROMOTION
 *********************************/
function getAppliedPromotion($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT c.applied_promotion_id, c.applied_discount, p.code, p.name, p.discount_type, p.discount_value
         FROM carts c
         LEFT JOIN promotions p ON c.applied_promotion_id = p.id
         WHERE c.id = :cart_id"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !$result['applied_promotion_id']) {
        return null;
    }
    
    return [
        'promotion_id' => $result['applied_promotion_id'],
        'code' => $result['code'],
        'name' => $result['name'],
        'discount_type' => $result['discount_type'],
        'discount_value' => floatval($result['discount_value']),
        'applied_discount' => floatval($result['applied_discount'])
    ];
}

/*********************************
 * GET OR CREATE USER CART
 *********************************/
function getOrCreateUserCart($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, user_id, status, created_at, updated_at 
         FROM carts 
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    
    $stmt->execute([':user_id' => $userId]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cart) {
        return $cart;
    }
    
    $insertStmt = $conn->prepare(
        "INSERT INTO carts (user_id, status, created_at, updated_at)
         VALUES (:user_id, 'active', NOW(), NOW())"
    );
    
    try {
        $insertStmt->execute([':user_id' => $userId]);
        $cartId = $conn->lastInsertId();
        
        return [
            'id' => $cartId,
            'user_id' => $userId,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    } catch (Exception $e) {
        error_log("Failed to create cart: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * GET CART ITEMS BY CART ID - CORRECTED
 *********************************/
function getCartItemsByCartId($conn, $cartId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.cart_id,
            ci.menu_item_id as item_id,
            mi.name as item_name,
            mi.description as item_description,
            mi.price,
            ci.quantity,
            ci.special_instructions,
            ci.created_at,
            ci.updated_at,
            m.id as merchant_id,
            m.name as merchant_name,
            m.category as merchant_category,
            m.image_url as merchant_image,
            mi.image_url as item_image,
            mi.category as item_category,
            CASE 
                WHEN m.category LIKE '%dropx%' OR m.name LIKE '%DropX%' THEN 1
                ELSE 0 
            END as is_dropx
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        LEFT JOIN merchants m ON mi.merchant_id = m.id
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1
        ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return array_map(function($item) use ($baseUrl) {
        return formatCartItemData($item, $baseUrl);
    }, $items);
}

/*********************************
 * CALCULATE CART TOTALS - CORRECTED
 *********************************/
function calculateCartTotals($conn, $cartId, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            SUM(mi.price * ci.quantity) as subtotal,
            COUNT(ci.id) as item_count,
            SUM(ci.quantity) as total_quantity
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = floatval($result['subtotal'] ?? 0);
    $itemCount = intval($result['item_count'] ?? 0);
    $totalQuantity = intval($result['total_quantity'] ?? 0);
    
    $promoStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $promoStmt->execute([':cart_id' => $cartId]);
    $promoResult = $promoStmt->fetch(PDO::FETCH_ASSOC);
    $promotionDiscount = floatval($promoResult['applied_discount'] ?? 0);
    
    $adjustedSubtotal = $subtotal - $promotionDiscount;
    if ($adjustedSubtotal < 0) $adjustedSubtotal = 0;
    
    $addressStmt = $conn->prepare(
        "SELECT a.*, dz.delivery_fee, dz.min_delivery_amount
         FROM addresses a
         LEFT JOIN delivery_zones dz ON 1=1
         WHERE a.user_id = :user_id AND a.is_default = 1
         LIMIT 1"
    );
    
    $addressStmt->execute([':user_id' => $userId]);
    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    
    $deliveryFee = 2.99;
    if ($address && !empty($address['delivery_fee'])) {
        $deliveryFee = floatval($address['delivery_fee']);
    }
    
    if ($adjustedSubtotal > 0 && $address && !empty($address['min_delivery_amount'])) {
        $minAmount = floatval($address['min_delivery_amount']);
        if ($adjustedSubtotal < $minAmount) {
            $deliveryFee += 2.00;
        }
    }
    
    $serviceFee = max(1.50, $adjustedSubtotal * 0.02);
    $taxableAmount = $adjustedSubtotal + $deliveryFee + $serviceFee;
    $taxAmount = $taxableAmount * 0.10;
    $totalAmount = $taxableAmount + $taxAmount;
    
    return [
        'subtotal' => round($subtotal, 2),
        'promotion_discount' => round($promotionDiscount, 2),
        'adjusted_subtotal' => round($adjustedSubtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee' => round($serviceFee, 2),
        'tax_amount' => round($taxAmount, 2),
        'total_amount' => round($totalAmount, 2),
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity
    ];
}

/*********************************
 * GET APPLICABLE PROMOTIONS
 *********************************/
function getApplicablePromotions($conn, $userId, $subtotal) {
    $currentDate = date('Y-m-d');
    
    $stmt = $conn->prepare(
        "SELECT p.id, p.code, p.name, p.description, p.discount_type, p.discount_value,
                p.min_order_amount, p.max_discount_amount, p.usage_limit, p.times_used,
                p.applicable_to, p.applicable_ids
         FROM promotions p
         WHERE p.is_active = 1
         AND p.valid_from <= :current_date
         AND p.valid_until >= :current_date
         AND (p.usage_limit IS NULL OR p.times_used < p.usage_limit)
         AND (p.min_order_amount IS NULL OR p.min_order_amount <= :subtotal)
         ORDER BY p.discount_value DESC"
    );
    
    $stmt->execute([':current_date' => $currentDate, ':subtotal' => $subtotal]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $applicablePromotions = [];
    foreach ($promotions as $promotion) {
        $usageStmt = $conn->prepare(
            "SELECT COUNT(*) as usage_count
             FROM promotion_usage
             WHERE promotion_id = :promotion_id AND user_id = :user_id"
        );
        
        $usageStmt->execute([':promotion_id' => $promotion['id'], ':user_id' => $userId]);
        $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
        $userUsageCount = intval($usage['usage_count'] ?? 0);
        
        if ($promotion['usage_limit'] && $userUsageCount >= $promotion['usage_limit']) {
            continue;
        }
        
        $discountAmount = 0;
        if ($promotion['discount_type'] === 'percentage') {
            $discountAmount = $subtotal * ($promotion['discount_value'] / 100);
            if ($promotion['max_discount_amount'] && $discountAmount > $promotion['max_discount_amount']) {
                $discountAmount = $promotion['max_discount_amount'];
            }
        } else {
            $discountAmount = $promotion['discount_value'];
        }
        
        $applicablePromotions[] = [
            'id' => $promotion['id'],
            'code' => $promotion['code'],
            'name' => $promotion['name'],
            'description' => $promotion['description'],
            'discount_type' => $promotion['discount_type'],
            'discount_value' => floatval($promotion['discount_value']),
            'discount_amount' => round($discountAmount, 2),
            'min_order_amount' => floatval($promotion['min_order_amount'] ?? 0),
            'max_discount_amount' => floatval($promotion['max_discount_amount'] ?? 0),
            'usage_limit' => $promotion['usage_limit'],
            'user_usage_count' => $userUsageCount,
            'applicable_to' => $promotion['applicable_to'],
            'applicable_ids' => $promotion['applicable_ids']
        ];
    }
    
    return $applicablePromotions;
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'add_item':
            addItemToCart($conn, $data, $userId);
            break;
        case 'apply_promo':
            applyPromotionToCart($conn, $data, $userId);
            break;
        case 'remove_promo':
            removePromotionFromCart($conn, $data, $userId);
            break;
        case 'clear_cart':
            clearCart($conn, $data, $userId);
            break;
        case 'validate_cart':
            validateCart($conn, $data, $userId);
            break;
        case 'prepare_checkout':
            prepareCheckout($conn, $data, $userId);
            break;
        case 'merge_cart':
            mergeCart($conn, $data, $userId);
            break;
        case 'debug_auth':
            debugAuth($conn);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * ADD ITEM TO CART - CORRECTED
 *********************************/
function addItemToCart($conn, $data, $userId) {
    $menuItemId = $data['menu_item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $customizations = $data['customizations'] ?? [];
    
    if (!$menuItemId) {
        ResponseHandler::error('Menu item ID is required', 400);
    }
    
    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }
    
    $itemCheckStmt = $conn->prepare(
        "SELECT mi.id, mi.name, mi.price, mi.is_available, 
                m.id as merchant_id, m.name as merchant_name
         FROM menu_items mi
         LEFT JOIN merchants m ON mi.merchant_id = m.id
         WHERE mi.id = :item_id
         AND mi.is_available = 1
         AND m.is_active = 1"
    );
    
    $itemCheckStmt->execute([':item_id' => $menuItemId]);
    $item = $itemCheckStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Item not available or merchant not active', 404);
    }
    
    $cart = getOrCreateUserCart($conn, $userId);
    
    $existingStmt = $conn->prepare(
        "SELECT id, quantity, special_instructions 
         FROM cart_items 
         WHERE cart_id = :cart_id 
         AND menu_item_id = :item_id
         AND is_active = 1"
    );
    
    $existingStmt->execute([':cart_id' => $cart['id'], ':item_id' => $menuItemId]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingItem) {
        $newQuantity = $existingItem['quantity'] + $quantity;
        
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity, 
                 special_instructions = :instructions,
                 customizations = :customizations,
                 updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':instructions' => $specialInstructions ?: $existingItem['special_instructions'],
            ':customizations' => json_encode($customizations),
            ':id' => $existingItem['id']
        ]);
        
        $message = 'Item quantity updated in cart';
        $cartItemId = $existingItem['id'];
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items 
                (cart_id, menu_item_id, quantity, special_instructions, customizations, is_active, created_at, updated_at)
             VALUES (:cart_id, :item_id, :quantity, :instructions, :customizations, 1, NOW(), NOW())"
        );
        
        $insertStmt->execute([
            ':cart_id' => $cart['id'],
            ':item_id' => $menuItemId,
            ':quantity' => $quantity,
            ':instructions' => $specialInstructions,
            ':customizations' => json_encode($customizations)
        ]);
        
        $message = 'Item added to cart';
        $cartItemId = $conn->lastInsertId();
    }
    
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $GLOBALS['baseUrl']);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'success' => true,
        'message' => $message,
        'data' => [
            'cart_item_id' => $cartItemId,
            'cart_id' => $cart['id'],
            'cart_item_count' => count($cartItems),
            'cart_total_quantity' => $totals['total_quantity'],
            'cart_subtotal' => $totals['subtotal'],
            'cart_total_amount' => $totals['total_amount']
        ]
    ]);
}

/*********************************
 * APPLY PROMOTION TO CART
 *********************************/
function applyPromotionToCart($conn, $data, $userId) {
    $promoCode = trim($data['promo_code'] ?? '');
    
    if (!$promoCode) {
        ResponseHandler::error('Promotion code is required', 400);
    }
    
    $cart = getOrCreateUserCart($conn, $userId);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    $currentDate = date('Y-m-d');
    
    $promoStmt = $conn->prepare(
        "SELECT * FROM promotions 
         WHERE code = :code 
         AND is_active = 1
         AND valid_from <= :current_date
         AND valid_until >= :current_date"
    );
    
    $promoStmt->execute([':code' => $promoCode, ':current_date' => $currentDate]);
    $promotion = $promoStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promotion) {
        ResponseHandler::error('Invalid or expired promotion code', 404);
    }
    
    $usageStmt = $conn->prepare(
        "SELECT COUNT(*) as usage_count
         FROM promotion_usage
         WHERE promotion_id = :promotion_id AND user_id = :user_id"
    );
    
    $usageStmt->execute([':promotion_id' => $promotion['id'], ':user_id' => $userId]);
    $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
    $userUsageCount = intval($usage['usage_count'] ?? 0);
    
    if ($promotion['usage_limit'] && $userUsageCount >= $promotion['usage_limit']) {
        ResponseHandler::error('You have reached the usage limit for this promotion', 400);
    }
    
    if ($promotion['min_order_amount'] && $totals['subtotal'] < $promotion['min_order_amount']) {
        $minAmount = number_format($promotion['min_order_amount'], 2);
        ResponseHandler::error("Minimum order amount of $$minAmount required for this promotion", 400);
    }
    
    $discountAmount = 0;
    if ($promotion['discount_type'] === 'percentage') {
        $discountAmount = $totals['subtotal'] * ($promotion['discount_value'] / 100);
        if ($promotion['max_discount_amount'] && $discountAmount > $promotion['max_discount_amount']) {
            $discountAmount = $promotion['max_discount_amount'];
        }
    } else {
        $discountAmount = $promotion['discount_value'];
        if ($discountAmount > $totals['subtotal']) {
            $discountAmount = $totals['subtotal'];
        }
    }
    
    $discountAmount = round($discountAmount, 2);
    
    $updateStmt = $conn->prepare(
        "UPDATE carts 
         SET applied_promotion_id = :promotion_id,
             applied_discount = :discount_amount,
             updated_at = NOW()
         WHERE id = :cart_id"
    );
    
    $updateStmt->execute([
        ':promotion_id' => $promotion['id'],
        ':discount_amount' => $discountAmount,
        ':cart_id' => $cart['id']
    ]);
    
    $newTotals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Promotion applied successfully',
        'data' => [
            'promotion' => [
                'id' => $promotion['id'],
                'code' => $promotion['code'],
                'name' => $promotion['name'],
                'discount_type' => $promotion['discount_type'],
                'discount_value' => floatval($promotion['discount_value']),
                'discount_amount' => $discountAmount
            ],
            'cart_totals' => $newTotals,
            'savings' => $discountAmount
        ]
    ]);
}

/*********************************
 * REMOVE PROMOTION FROM CART
 *********************************/
function removePromotionFromCart($conn, $data, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    $updateStmt = $conn->prepare(
        "UPDATE carts 
         SET applied_promotion_id = NULL,
             applied_discount = NULL,
             updated_at = NOW()
         WHERE id = :cart_id"
    );
    
    $updateStmt->execute([':cart_id' => $cart['id']]);
    
    $newTotals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Promotion removed successfully',
        'data' => [
            'cart_totals' => $newTotals
        ]
    ]);
}

/*********************************
 * CLEAR CART
 *********************************/
function clearCart($conn, $data, $userId) {
    $cartStmt = $conn->prepare(
        "SELECT id FROM carts 
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    
    $cartStmt->execute([':user_id' => $userId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cart) {
        ResponseHandler::error('No active cart found', 404);
    }
    
    $clearStmt = $conn->prepare(
        "UPDATE cart_items 
         SET is_active = 0, updated_at = NOW()
         WHERE cart_id = :cart_id AND is_active = 1"
    );
    
    $clearStmt->execute([':cart_id' => $cart['id']]);
    $itemsCleared = $clearStmt->rowCount();
    
    $updateCartStmt = $conn->prepare(
        "UPDATE carts 
         SET applied_promotion_id = NULL,
             applied_discount = NULL,
             updated_at = NOW()
         WHERE id = :cart_id"
    );
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Cart cleared successfully',
        'data' => [
            'items_cleared' => $itemsCleared,
            'cart_id' => $cart['id']
        ]
    ]);
}

/*********************************
 * VALIDATE CART
 *********************************/
function validateCart($conn, $data, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $GLOBALS['baseUrl']);
    
    if (empty($cartItems)) {
        ResponseHandler::error('Cart is empty', 400);
    }
    
    $issues = [];
    foreach ($cartItems as $item) {
        $checkStmt = $conn->prepare(
            "SELECT mi.is_available, m.is_active, m.is_open
             FROM menu_items mi
             LEFT JOIN merchants m ON mi.merchant_id = m.id
             WHERE mi.id = :item_id"
        );
        
        $checkStmt->execute([':item_id' => $item['item_id']]);
        $status = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$status || !$status['is_available']) {
            $issues[] = "{$item['name']} is no longer available";
        } elseif (!$status['is_active']) {
            $issues[] = "{$item['merchant_name']} is no longer active";
        } elseif (!$status['is_open']) {
            $issues[] = "{$item['merchant_name']} is currently closed";
        }
    }
    
    if (!empty($issues)) {
        ResponseHandler::error('Cart validation failed', 400, ['issues' => $issues]);
    }
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Cart is valid',
        'data' => [
            'is_valid' => true,
            'item_count' => count($cartItems),
            'issues' => $issues
        ]
    ]);
}

/*********************************
 * PREPARE CHECKOUT
 *********************************/
function prepareCheckout($conn, $data, $userId) {
    $deliveryAddressId = $data['delivery_address_id'] ?? null;
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $tipAmount = floatval($data['tip_amount'] ?? 0);
    $paymentMethod = $data['payment_method'] ?? 'cash';
    
    $cart = getOrCreateUserCart($conn, $userId);
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $GLOBALS['baseUrl']);
    
    if (empty($cartItems)) {
        ResponseHandler::error('Cannot checkout empty cart', 400);
    }
    
    $address = null;
    if ($deliveryAddressId) {
        $addressStmt = $conn->prepare(
            "SELECT * FROM addresses 
             WHERE id = :address_id AND user_id = :user_id"
        );
        
        $addressStmt->execute([':address_id' => $deliveryAddressId, ':user_id' => $userId]);
        $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$address) {
        $defaultAddressStmt = $conn->prepare(
            "SELECT * FROM addresses 
             WHERE user_id = :user_id AND is_default = 1
             LIMIT 1"
        );
        
        $defaultAddressStmt->execute([':user_id' => $userId]);
        $address = $defaultAddressStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$address) {
        ResponseHandler::error('Please select a delivery address', 400);
    }
    
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    $finalTotal = $totals['total_amount'] + $tipAmount;
    
    $validation = validateCartItems($conn, $cart['id']);
    if (!$validation['is_valid']) {
        ResponseHandler::error('Cart validation failed', 400, ['issues' => $validation['issues']]);
    }
    
    $checkoutData = [
        'cart_id' => $cart['id'],
        'address' => formatAddressData($address),
        'payment_method' => $paymentMethod,
        'tip_amount' => round($tipAmount, 2),
        'summary' => [
            'items_subtotal' => $totals['subtotal'],
            'promotion_discount' => $totals['promotion_discount'],
            'adjusted_subtotal' => $totals['adjusted_subtotal'],
            'delivery_fee' => $totals['delivery_fee'],
            'service_fee' => $totals['service_fee'],
            'tax_amount' => $totals['tax_amount'],
            'tip_amount' => round($tipAmount, 2),
            'total_amount' => round($finalTotal, 2)
        ],
        'items' => $cartItems,
        'item_count' => $totals['item_count'],
        'total_quantity' => $totals['total_quantity']
    ];
    
    $checkoutSessionKey = 'checkout_data_' . $cart['id'];
    $_SESSION[$checkoutSessionKey] = $checkoutData;
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Checkout prepared successfully',
        'data' => $checkoutData
    ]);
}

/*********************************
 * MERGE CART - CORRECTED
 *********************************/
function mergeCart($conn, $data, $userId) {
    $guestItems = $data['guest_items'] ?? [];
    
    if (empty($guestItems)) {
        ResponseHandler::error('No guest items to merge', 400);
    }
    
    $cart = getOrCreateUserCart($conn, $userId);
    $mergedCount = 0;
    $skippedCount = 0;
    
    foreach ($guestItems as $guestItem) {
        $menuItemId = $guestItem['menu_item_id'] ?? null;
        $quantity = intval($guestItem['quantity'] ?? 1);
        
        if (!$menuItemId || $quantity < 1) {
            $skippedCount++;
            continue;
        }
        
        $itemCheckStmt = $conn->prepare(
            "SELECT id FROM menu_items WHERE id = :item_id AND is_available = 1"
        );
        $itemCheckStmt->execute([':item_id' => $menuItemId]);
        
        if (!$itemCheckStmt->fetch()) {
            $skippedCount++;
            continue;
        }
        
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items 
                (cart_id, menu_item_id, quantity, is_active, created_at, updated_at)
             VALUES (:cart_id, :item_id, :quantity, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE 
                quantity = quantity + VALUES(quantity),
                updated_at = NOW()"
        );
        
        $insertStmt->execute([
            ':cart_id' => $cart['id'],
            ':item_id' => $menuItemId,
            ':quantity' => $quantity
        ]);
        
        $mergedCount++;
    }
    
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $GLOBALS['baseUrl']);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Cart merged successfully',
        'data' => [
            'merged_count' => $mergedCount,
            'skipped_count' => $skippedCount,
            'cart_item_count' => count($cartItems),
            'cart_total_quantity' => $totals['total_quantity'],
            'cart_subtotal' => $totals['subtotal']
        ]
    ]);
}

/*********************************
 * VALIDATE CART ITEMS - CORRECTED
 *********************************/
function validateCartItems($conn, $cartId) {
    $issues = [];
    
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.menu_item_id as item_id,
            mi.name as item_name,
            mi.is_available,
            m.id as merchant_id,
            m.name as merchant_name,
            m.is_active as merchant_active,
            m.is_open as merchant_open,
            m.min_order as merchant_min_order
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        LEFT JOIN merchants m ON mi.merchant_id = m.id
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        if (!$item['is_available']) {
            $issues[] = "{$item['item_name']} is no longer available";
        }
        if (!$item['merchant_active']) {
            $issues[] = "{$item['merchant_name']} is no longer active";
        }
        if (!$item['merchant_open']) {
            $issues[] = "{$item['merchant_name']} is currently closed";
        }
    }
    
    return ['is_valid' => empty($issues), 'issues' => $issues];
}

/*********************************
 * DEBUG AUTH ENDPOINT
 *********************************/
function debugAuth($conn) {
    $headers = getallheaders();
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'session_id' => session_id(),
            'session_user_id' => $_SESSION['user_id'] ?? null,
            'session_status' => session_status(),
            'session_name' => session_name(),
            'all_headers' => $headers,
            'all_cookies' => $_COOKIE,
            'session_data' => $_SESSION
        ]
    ]);
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $action = $data['action'] ?? '';
    if ($action === 'update_item') {
        updateCartItem($conn, $data, $userId);
    } else {
        ResponseHandler::error('Invalid action for PUT request', 400);
    }
}

/*********************************
 * UPDATE CART ITEM
 *********************************/
function updateCartItem($conn, $data, $userId) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $quantity = isset($data['quantity']) ? intval($data['quantity']) : null;
    $specialInstructions = isset($data['special_instructions']) ? trim($data['special_instructions']) : null;
    $customizations = $data['customizations'] ?? null;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    if ($quantity === null && $specialInstructions === null && $customizations === null) {
        ResponseHandler::error('No update data provided', 400);
    }
    
    if ($quantity === 0) {
        removeCartItem($conn, $userId, $cartItemId);
        return;
    }
    
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, ci.quantity, ci.special_instructions, ci.cart_id
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :cart_item_id
         AND c.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    $updates = [];
    $params = [':id' => $cartItemId];
    
    if ($quantity !== null) {
        if ($quantity < 1) {
            ResponseHandler::error('Quantity must be at least 1', 400);
        }
        $updates[] = 'quantity = :quantity';
        $params[':quantity'] = $quantity;
    }
    
    if ($specialInstructions !== null) {
        $updates[] = 'special_instructions = :instructions';
        $params[':instructions'] = $specialInstructions;
    }
    
    if ($customizations !== null) {
        $updates[] = 'customizations = :customizations';
        $params[':customizations'] = json_encode($customizations);
    }
    
    if (empty($updates)) {
        ResponseHandler::error('No valid updates provided', 400);
    }
    
    $updates[] = 'updated_at = NOW()';
    $updateSql = "UPDATE cart_items SET " . implode(', ', $updates) . " WHERE id = :id";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute($params);
    
    $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $cartUpdateStmt->execute([':cart_id' => $cartItem['cart_id']]);
    
    $cartItems = getCartItemsByCartId($conn, $cartItem['cart_id'], $GLOBALS['baseUrl']);
    $totals = calculateCartTotals($conn, $cartItem['cart_id'], $userId);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Cart item updated successfully',
        'data' => [
            'cart_item_id' => $cartItemId,
            'cart_item_count' => count($cartItems),
            'cart_total_quantity' => $totals['total_quantity'],
            'cart_subtotal' => $totals['subtotal']
        ]
    ]);
}

/*********************************
 * DELETE REQUESTS
 *********************************/
function handleDeleteRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $action = $data['action'] ?? '';
    if ($action === 'remove_item') {
        $cartItemId = $data['cart_item_id'] ?? null;
        if ($cartItemId) {
            removeCartItem($conn, $userId, $cartItemId);
        } else {
            ResponseHandler::error('Cart item ID is required', 400);
        }
    } else {
        ResponseHandler::error('Invalid action for DELETE request', 400);
    }
}

/*********************************
 * REMOVE CART ITEM - CORRECTED
 *********************************/
function removeCartItem($conn, $userId, $cartItemId) {
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, ci.cart_id, ci.menu_item_id, mi.name as item_name
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         JOIN menu_items mi ON ci.menu_item_id = mi.id
         WHERE ci.id = :cart_item_id
         AND c.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    $deleteStmt = $conn->prepare(
        "UPDATE cart_items 
         SET is_active = 0, updated_at = NOW()
         WHERE id = :cart_item_id"
    );
    
    $deleteStmt->execute([':cart_item_id' => $cartItemId]);
    
    $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $cartUpdateStmt->execute([':cart_id' => $cartItem['cart_id']]);
    
    $cartItems = getCartItemsByCartId($conn, $cartItem['cart_id'], $GLOBALS['baseUrl']);
    $totals = calculateCartTotals($conn, $cartItem['cart_id'], $userId);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Item removed from cart',
        'data' => [
            'cart_item_id' => $cartItemId,
            'item_name' => $cartItem['item_name'],
            'cart_item_count' => count($cartItems),
            'cart_total_quantity' => $totals['total_quantity'],
            'cart_subtotal' => $totals['subtotal']
        ]
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

function formatCartItemData($item, $baseUrl) {
    $itemImage = '';
    if (!empty($item['item_image'])) {
        if (strpos($item['item_image'], 'http') === 0) {
            $itemImage = $item['item_image'];
        } else {
            $itemImage = rtrim($baseUrl, '/') . '/uploads/' . $item['item_image'];
        }
    }
    
    $merchantImage = '';
    if (!empty($item['merchant_image'])) {
        if (strpos($item['merchant_image'], 'http') === 0) {
            $merchantImage = $item['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/' . $item['merchant_image'];
        }
    }
    
    $price = floatval($item['price'] ?? 0);
    $quantity = intval($item['quantity'] ?? 1);
    $total = $price * $quantity;
    
    return [
        'id' => $item['id'],
        'item_id' => $item['item_id'],
        'name' => $item['item_name'] ?? '',
        'description' => $item['item_description'] ?? '',
        'price' => $price,
        'quantity' => $quantity,
        'total' => $total,
        'special_instructions' => $item['special_instructions'] ?? '',
        'image_url' => $itemImage,
        'category' => $item['item_category'] ?? '',
        'merchant_id' => $item['merchant_id'],
        'merchant_name' => $item['merchant_name'] ?? '',
        'merchant_category' => $item['merchant_category'] ?? '',
        'merchant_image' => $merchantImage,
        'is_dropx' => boolval($item['is_dropx'] ?? false),
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}

function formatAddressData($address) {
    return [
        'id' => $address['id'],
        'label' => $address['label'] ?? '',
        'full_name' => $address['full_name'] ?? '',
        'phone' => $address['phone'] ?? '',
        'address_line1' => $address['address_line1'] ?? '',
        'address_line2' => $address['address_line2'] ?? '',
        'city' => $address['city'] ?? '',
        'neighborhood' => $address['neighborhood'] ?? '',
        'landmark' => $address['landmark'] ?? '',
        'latitude' => floatval($address['latitude'] ?? 0),
        'longitude' => floatval($address['longitude'] ?? 0),
        'is_default' => boolval($address['is_default'] ?? false)
    ];
}
?>