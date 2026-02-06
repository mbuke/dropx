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
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function checkAuthentication($conn) {
    error_log("=== AUTH CHECK START ===");
    error_log("Session ID: " . session_id());
    error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    
    if (!empty($_SESSION['user_id'])) {
        error_log("Auth Method: PHP Session");
        return $_SESSION['user_id'];
    }
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        error_log("Auth Method: Bearer Token - $token");
        
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE api_token = :token AND api_token_expiry > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            error_log("Bearer Token Valid - User ID: " . $user['id']);
            return $user['id'];
        }
    }
    
    $sessionToken = $headers['X-Session-Token'] ?? '';
    if ($sessionToken) {
        error_log("Auth Method: X-Session-Token - $sessionToken");
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions 
             WHERE session_token = :token AND expires_at > NOW()"
        );
        $stmt->execute([':token' => $sessionToken]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $_SESSION['user_id'] = $result['user_id'];
            error_log("Session Token Valid - User ID: " . $result['user_id']);
            return $result['user_id'];
        }
        
        if (session_id() !== $sessionToken) {
            session_id($sessionToken);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Token - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    if (!empty($_COOKIE['PHPSESSID'])) {
        error_log("Auth Method: PHPSESSID Cookie");
        
        if (session_id() !== $_COOKIE['PHPSESSID']) {
            session_id($_COOKIE['PHPSESSID']);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Cookie - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    error_log("All Headers: " . json_encode($headers));
    error_log("All Cookies: " . json_encode($_COOKIE));
    error_log("=== AUTH CHECK FAILED ===");
    return false;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx-production-6373.up.railway.app";

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
 * GET REQUESTS
 *********************************/
function handleGetRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    $orderId = $_GET['id'] ?? null;
    
    if ($orderId) {
        getQuickOrderDetails($conn, $orderId, $baseUrl, $userId);
    } else {
        getQuickOrdersList($conn, $baseUrl, $userId);
    }
}

/*********************************
 * GET QUICK ORDERS LIST
 *********************************/
function getQuickOrdersList($conn, $baseUrl, $userId = null) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'order_count';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $isPopular = $_GET['is_popular'] ?? null;
    $itemType = $_GET['item_type'] ?? '';
    $inStock = $_GET['in_stock'] ?? null;
    $unitType = $_GET['unit_type'] ?? '';

    $whereConditions = [];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "qo.category = :category";
        $params[':category'] = $category;
    }

    if ($itemType) {
        $whereConditions[] = "qo.item_type = :item_type";
        $params[':item_type'] = $itemType;
    }

    if ($search) {
        $whereConditions[] = "(qo.title LIKE :search OR qo.description LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($isPopular !== null) {
        $whereConditions[] = "qo.is_popular = :is_popular";
        $params[':is_popular'] = $isPopular === 'true' ? 1 : 0;
    }

    if ($inStock !== null && $inStock === 'true') {
        $whereConditions[] = "EXISTS (
            SELECT 1 FROM quick_order_items qoi 
            WHERE qoi.quick_order_id = qo.id 
            AND qoi.is_available = 1 
            AND (qoi.stock_quantity > 0 OR qoi.stock_quantity IS NULL)
        )";
    }

    if ($unitType) {
        $whereConditions[] = "EXISTS (
            SELECT 1 FROM quick_order_items qoi 
            WHERE qoi.quick_order_id = qo.id 
            AND qoi.unit_type = :unit_type
            AND qoi.is_available = 1
        )";
        $params[':unit_type'] = $unitType;
    }

    $whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);

    $allowedSortColumns = ['order_count', 'title', 'created_at', 'rating'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'order_count';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    $countSql = "SELECT COUNT(*) as total FROM quick_orders qo" . ($whereClause ? " $whereClause" : "");
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.description,
                qo.category,
                qo.subcategory,
                qo.item_type,
                qo.image_url,
                qo.color,
                qo.info,
                qo.is_popular,
                qo.delivery_time,
                qo.price,
                qo.order_count,
                qo.rating,
                qo.min_order_amount,
                qo.available_all_day,
                qo.seasonal_available,
                qo.created_at,
                qo.updated_at,
                COALESCE(
                    (SELECT GROUP_CONCAT(DISTINCT qoi.unit_type) 
                     FROM quick_order_items qoi 
                     WHERE qoi.quick_order_id = qo.id 
                     AND qoi.is_available = 1),
                    ''
                ) as available_units
            FROM quick_orders qo
            $whereClause
            ORDER BY qo.is_popular DESC, qo.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryStmt = $conn->prepare(
        "SELECT DISTINCT category FROM quick_orders WHERE category IS NOT NULL AND category != '' ORDER BY category"
    );
    $categoryStmt->execute();
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    $itemTypeStmt = $conn->prepare(
        "SELECT DISTINCT item_type FROM quick_orders WHERE item_type IS NOT NULL ORDER BY item_type"
    );
    $itemTypeStmt->execute();
    $availableItemTypes = $itemTypeStmt->fetchAll(PDO::FETCH_COLUMN);

    $unitTypeStmt = $conn->prepare(
        "SELECT DISTINCT unit_type FROM quick_order_items WHERE unit_type IS NOT NULL ORDER BY unit_type"
    );
    $unitTypeStmt->execute();
    $availableUnitTypes = $unitTypeStmt->fetchAll(PDO::FETCH_COLUMN);

    $formattedOrders = array_map(function($q) use ($baseUrl) {
        return formatQuickOrderListData($q, $baseUrl);
    }, $quickOrders);

    $response = [
        'quick_orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'filters' => [
            'available_categories' => $availableCategories,
            'available_item_types' => $availableItemTypes,
            'available_unit_types' => $availableUnitTypes,
            'item_type_counts' => getItemTypeCounts($conn)
        ]
    ];

    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
    } else {
        $response['user_authenticated'] = false;
    }

    ResponseHandler::success($response);
}

function getItemTypeCounts($conn) {
    $counts = [];
    
    $stmt = $conn->prepare(
        "SELECT 
            item_type,
            COUNT(*) as count
        FROM quick_orders 
        WHERE item_type IS NOT NULL
        GROUP BY item_type"
    );
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $row) {
        $counts[$row['item_type']] = intval($row['count']);
    }
    
    return $counts;
}

/*********************************
 * GET QUICK ORDER DETAILS
 *********************************/
function getQuickOrderDetails($conn, $orderId, $baseUrl, $userId = null) {
    $stmt = $conn->prepare(
        "SELECT 
            qo.id,
            qo.title,
            qo.description,
            qo.category,
            qo.subcategory,
            qo.item_type,
            qo.image_url,
            qo.color,
            qo.info,
            qo.is_popular,
            qo.delivery_time,
            qo.price,
            qo.order_count,
            qo.rating,
            qo.min_order_amount,
            qo.available_all_day,
            qo.available_start_time,
            qo.available_end_time,
            qo.seasonal_available,
            qo.season_start_month,
            qo.season_end_month,
            qo.created_at,
            qo.updated_at
        FROM quick_orders qo
        WHERE qo.id = :id"
    );
    
    $stmt->execute([':id' => $orderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    $itemsStmt = $conn->prepare(
        "SELECT 
            qoi.id,
            qoi.name,
            qoi.description,
            qoi.price,
            qoi.image_url,
            qoi.unit_type,
            qoi.unit_value,
            qoi.is_default,
            qoi.is_available,
            qoi.stock_quantity,
            qoi.reorder_level,
            qoi.created_at
        FROM quick_order_items qoi
        WHERE qoi.quick_order_id = :quick_order_id
        ORDER BY qoi.is_default DESC, qoi.name"
    );
    
    $itemsStmt->execute([':quick_order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $merchantsStmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.category,
            m.business_type,
            m.rating,
            m.image_url,
            m.is_open,
            m.delivery_time,
            m.delivery_fee,
            m.min_order_amount,
            m.delivery_radius,
            qom.custom_price,
            qom.custom_delivery_time,
            qom.priority
        FROM merchants m
        INNER JOIN quick_order_merchants qom ON m.id = qom.merchant_id
        WHERE qom.quick_order_id = :quick_order_id
        AND m.is_active = 1
        AND qom.is_active = 1
        ORDER BY qom.priority DESC, m.rating DESC
        LIMIT 10"
    );
    
    $merchantsStmt->execute([':quick_order_id' => $orderId]);
    $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderData = formatQuickOrderDetailData($quickOrder, $baseUrl);
    $orderData['items'] = array_map(function($item) use ($baseUrl) {
        return formatQuickOrderItemData($item, $baseUrl);
    }, $items);
    $orderData['merchants'] = array_map(function($merchant) use ($baseUrl) {
        return formatQuickOrderMerchantData($merchant, $baseUrl);
    }, $merchants);

    $similarItemsStmt = $conn->prepare(
        "SELECT 
            qo.id,
            qo.title,
            qo.description,
            qo.category,
            qo.item_type,
            qo.image_url,
            qo.price,
            qo.rating
        FROM quick_orders qo
        WHERE qo.category = :category
        AND qo.id != :id
        AND qo.item_type = :item_type
        ORDER BY qo.order_count DESC
        LIMIT 5"
    );
    
    $similarItemsStmt->execute([
        ':category' => $quickOrder['category'],
        ':id' => $orderId,
        ':item_type' => $quickOrder['item_type']
    ]);
    $similarItems = $similarItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $orderData['similar_items'] = array_map(function($item) use ($baseUrl) {
        return formatQuickOrderListData($item, $baseUrl);
    }, $similarItems);

    $response = [
        'quick_order' => $orderData
    ];

    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
        
        $favoriteStmt = $conn->prepare(
            "SELECT id FROM user_favorites 
             WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
        );
        $favoriteStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $orderId
        ]);
        
        $response['quick_order']['is_favorited'] = $favoriteStmt->rowCount() > 0;
        
        $cartStmt = $conn->prepare(
            "SELECT quantity FROM cart_items 
             WHERE user_id = :user_id AND quick_order_item_id IN (
                 SELECT id FROM quick_order_items WHERE quick_order_id = :quick_order_id
             )"
        );
        $cartStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $orderId
        ]);
        $response['quick_order']['in_cart'] = $cartStmt->rowCount() > 0;
    } else {
        $response['user_authenticated'] = false;
        $response['quick_order']['is_favorited'] = false;
        $response['quick_order']['in_cart'] = false;
    }

    ResponseHandler::success($response);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    if (!$input) {
        ResponseHandler::error('No input data provided', 400);
    }
    
    $action = $input['action'] ?? '';

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    switch ($action) {
        case 'create_order':
            createQuickOrder($conn, $input, $userId);
            break;
        case 'get_order_history':
            getQuickOrderHistory($conn, $input, $baseUrl, $userId);
            break;
        case 'cancel_order':
            cancelQuickOrder($conn, $input, $userId);
            break;
        case 'rate_order':
            rateQuickOrder($conn, $input, $userId);
            break;
        case 'toggle_favorite':
            toggleQuickOrderFavorite($conn, $input, $userId);
            break;
        case 'add_to_cart':
            addQuickOrderToCart($conn, $input, $userId);
            break;
        case 'bulk_update_stock':
            bulkUpdateStock($conn, $input, $userId);
            break;
        case 'get_by_categories':
            getQuickOrdersByCategories($conn, $input, $baseUrl, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

function addQuickOrderToCart($conn, $data, $userId) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $itemId = $data['item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $unitType = $data['unit_type'] ?? null;

    if (!$quickOrderId || !$itemId) {
        ResponseHandler::error('Quick order ID and item ID are required', 400);
    }

    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }

    $itemStmt = $conn->prepare(
        "SELECT qoi.*, qo.item_type, qo.category 
         FROM quick_order_items qoi
         JOIN quick_orders qo ON qoi.quick_order_id = qo.id
         WHERE qoi.id = :item_id 
         AND qoi.quick_order_id = :quick_order_id
         AND qoi.is_available = 1"
    );
    $itemStmt->execute([
        ':item_id' => $itemId,
        ':quick_order_id' => $quickOrderId
    ]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        ResponseHandler::error('Item not available', 404);
    }

    if ($item['stock_quantity'] > 0 && $quantity > $item['stock_quantity']) {
        ResponseHandler::error('Insufficient stock', 400);
    }

    $checkStmt = $conn->prepare(
        "SELECT id, quantity FROM cart_items 
         WHERE user_id = :user_id 
         AND quick_order_item_id = :item_id"
    );
    $checkStmt->execute([
        ':user_id' => $userId,
        ':item_id' => $itemId
    ]);
    
    if ($existing = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
        $newQuantity = $existing['quantity'] + $quantity;
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity, 
                 unit_type = :unit_type,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':unit_type' => $unitType ?? $item['unit_type'],
            ':id' => $existing['id']
        ]);
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items (
                user_id, 
                quick_order_item_id,
                quick_order_id,
                name,
                description,
                price,
                image_url,
                quantity,
                unit_type,
                unit_value,
                item_type,
                category,
                created_at
            ) VALUES (
                :user_id, 
                :quick_order_item_id,
                :quick_order_id,
                :name,
                :description,
                :price,
                :image_url,
                :quantity,
                :unit_type,
                :unit_value,
                :item_type,
                :category,
                NOW()
            )"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':quick_order_item_id' => $itemId,
            ':quick_order_id' => $quickOrderId,
            ':name' => $item['name'],
            ':description' => $item['description'],
            ':price' => $item['price'],
            ':image_url' => $item['image_url'],
            ':quantity' => $quantity,
            ':unit_type' => $unitType ?? $item['unit_type'],
            ':unit_value' => $item['unit_value'],
            ':item_type' => $item['item_type'],
            ':category' => $item['category']
        ]);
    }

    ResponseHandler::success(['message' => 'Added to cart'], 'Item added to cart successfully');
}

function bulkUpdateStock($conn, $data, $userId) {
    if (!isAdmin($conn, $userId)) {
        ResponseHandler::error('Admin access required', 403);
    }

    $updates = $data['updates'] ?? [];
    if (empty($updates)) {
        ResponseHandler::error('No updates provided', 400);
    }

    $conn->beginTransaction();
    try {
        foreach ($updates as $update) {
            $itemId = $update['item_id'] ?? null;
            $stockQuantity = intval($update['stock_quantity'] ?? 0);
            $isAvailable = $update['is_available'] ?? null;

            if (!$itemId) {
                throw new Exception('Item ID is required for each update');
            }

            $updateFields = [];
            $updateParams = [':id' => $itemId];

            if ($stockQuantity !== null) {
                $updateFields[] = "stock_quantity = :stock_quantity";
                $updateParams[':stock_quantity'] = $stockQuantity;
            }

            if ($isAvailable !== null) {
                $updateFields[] = "is_available = :is_available";
                $updateParams[':is_available'] = $isAvailable ? 1 : 0;
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $sql = "UPDATE quick_order_items SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute($updateParams);
            }
        }

        $conn->commit();
        ResponseHandler::success(['message' => 'Stock updated successfully']);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update stock: ' . $e->getMessage(), 500);
    }
}

function getQuickOrdersByCategories($conn, $data, $baseUrl, $userId = null) {
    $categories = $data['categories'] ?? [];
    $itemTypes = $data['item_types'] ?? [];
    $limit = min(20, max(1, intval($data['limit'] ?? 10)));

    if (empty($categories) && empty($itemTypes)) {
        ResponseHandler::error('At least one category or item type is required', 400);
    }

    $whereConditions = [];
    $params = [];

    if (!empty($categories)) {
        $categoryPlaceholders = [];
        foreach ($categories as $index => $category) {
            $param = ":category_$index";
            $categoryPlaceholders[] = $param;
            $params[$param] = $category;
        }
        $whereConditions[] = "qo.category IN (" . implode(',', $categoryPlaceholders) . ")";
    }

    if (!empty($itemTypes)) {
        $typePlaceholders = [];
        foreach ($itemTypes as $index => $type) {
            $param = ":type_$index";
            $typePlaceholders[] = $param;
            $params[$param] = $type;
        }
        $whereConditions[] = "qo.item_type IN (" . implode(',', $typePlaceholders) . ")";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.description,
                qo.category,
                qo.item_type,
                qo.image_url,
                qo.color,
                qo.info,
                qo.is_popular,
                qo.price,
                qo.rating,
                qo.order_count,
                qo.delivery_time
            FROM quick_orders qo
            $whereClause
            ORDER BY qo.is_popular DESC, qo.order_count DESC
            LIMIT :limit";

    $params[':limit'] = $limit;
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groupedByCategory = [];
    foreach ($quickOrders as $order) {
        $category = $order['category'];
        if (!isset($groupedByCategory[$category])) {
            $groupedByCategory[$category] = [];
        }
        $groupedByCategory[$category][] = formatQuickOrderListData($order, $baseUrl);
    }

    $response = [
        'grouped_by_category' => $groupedByCategory,
        'total_items' => count($quickOrders)
    ];

    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
    }

    ResponseHandler::success($response);
}

function isAdmin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && $user['is_admin'] == 1;
}

/*********************************
 * CREATE QUICK ORDER
 *********************************/
function createQuickOrder($conn, $data, $userId) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $items = $data['items'] ?? [];
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $deliveryAddress = trim($data['delivery_address'] ?? '');
    $paymentMethod = $data['payment_method'] ?? 'cash';
    
    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }

    if (!$merchantId) {
        ResponseHandler::error('Merchant selection is required', 400);
    }

    if (empty($items)) {
        ResponseHandler::error('At least one item is required', 400);
    }

    if (!$deliveryAddress) {
        ResponseHandler::error('Delivery address is required', 400);
    }

    // Get quick order details
    $orderStmt = $conn->prepare(
        "SELECT 
            qo.title,
            COALESCE(
                (SELECT qoi.price 
                 FROM quick_order_items qoi 
                 WHERE qoi.quick_order_id = qo.id 
                 AND qoi.is_default = 1 
                 LIMIT 1),
                0.00
            ) as price,
            qo.delivery_time 
        FROM quick_orders qo 
        WHERE qo.id = :id"
    );
    $orderStmt->execute([':id' => $quickOrderId]);
    $quickOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Get merchant details
    $merchantStmt = $conn->prepare(
        "SELECT name, delivery_fee FROM merchants WHERE id = :id AND is_active = 1"
    );
    $merchantStmt->execute([':id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not available', 404);
    }

    // Calculate total
    $subtotal = 0;
    $itemDetails = [];

    foreach ($items as $item) {
        $itemId = $item['id'] ?? null;
        $quantity = intval($item['quantity'] ?? 1);

        if ($itemId && $quantity > 0) {
            $itemStmt = $conn->prepare(
                "SELECT name, price FROM quick_order_items WHERE id = :id"
            );
            $itemStmt->execute([':id' => $itemId]);
            $itemData = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if ($itemData) {
                $itemTotal = $itemData['price'] * $quantity;
                $subtotal += $itemTotal;
                
                $itemDetails[] = [
                    'name' => $itemData['name'],
                    'quantity' => $quantity,
                    'price' => $itemData['price'],
                    'total' => $itemTotal
                ];
            }
        }
    }

    if ($subtotal <= 0) {
        ResponseHandler::error('Invalid order total', 400);
    }

    $deliveryFee = floatval($merchant['delivery_fee'] ?? 0);
    $totalAmount = $subtotal + $deliveryFee;

    // Generate order number
    $orderNumber = 'QO-' . date('Ymd') . '-' . strtoupper(uniqid());

    // Start transaction
    $conn->beginTransaction();

    try {
        // Create order record
        $orderSql = "
            INSERT INTO orders (
                order_number, user_id, merchant_id, quick_order_id,
                subtotal, delivery_fee, total_amount, payment_method,
                delivery_address, special_instructions, status,
                created_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :quick_order_id,
                :subtotal, :delivery_fee, :total_amount, :payment_method,
                :delivery_address, :special_instructions, 'pending',
                NOW()
            )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':quick_order_id' => $quickOrderId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total_amount' => $totalAmount,
            ':payment_method' => $paymentMethod,
            ':delivery_address' => $deliveryAddress,
            ':special_instructions' => $specialInstructions
        ]);

        $orderId = $conn->lastInsertId();

        // Insert order items
        $itemSql = "
            INSERT INTO order_items (
                order_id, item_name, quantity, price, total,
                created_at
            ) VALUES (
                :order_id, :item_name, :quantity, :price, :total,
                NOW()
            )";

        $itemStmt = $conn->prepare($itemSql);

        foreach ($itemDetails as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $item['total']
            ]);
        }

        // Update quick order count
        $updateOrderStmt = $conn->prepare(
            "UPDATE quick_orders SET order_count = order_count + 1 WHERE id = :id"
        );
        $updateOrderStmt->execute([':id' => $quickOrderId]);

        // Commit transaction
        $conn->commit();

        // Get full order details
        $orderDetails = getOrderDetails($conn, $orderId);

        ResponseHandler::success([
            'order' => formatOrderData($orderDetails),
            'order_number' => $orderNumber,
            'estimated_delivery' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
        ], 'Quick order created successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Order creation error: " . $e->getMessage());
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET QUICK ORDER HISTORY
 *********************************/
function getQuickOrderHistory($conn, $data, $baseUrl, $userId) {
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $data['status'] ?? '';

    // Build WHERE clause
    $whereConditions = ["o.user_id = :user_id", "o.quick_order_id IS NOT NULL"];
    $params = [':user_id' => $userId];

    if ($status) {
        $whereConditions[] = "o.status = :status";
        $params[':status'] = $status;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM orders o $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get orders
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.payment_method,
                o.delivery_address,
                o.special_instructions,
                o.created_at,
                o.updated_at,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image,
                m.name as merchant_name,
                m.image_url as merchant_image
            FROM orders o
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format orders
    $formattedOrders = array_map(function($order) use ($baseUrl) {
        return formatOrderHistoryData($order, $baseUrl);
    }, $orders);

    ResponseHandler::success([
        'orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * CANCEL QUICK ORDER
 *********************************/
function cancelQuickOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $reason = trim($data['reason'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM orders 
         WHERE id = :id AND user_id = :user_id AND quick_order_id IS NOT NULL"
    );
    $checkStmt->execute([
        ':id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be cancelled
    $allowedStatuses = ['pending', 'confirmed', 'preparing'];
    if (!in_array($order['status'], $allowedStatuses)) {
        ResponseHandler::error('Order cannot be cancelled at this stage', 400);
    }

    // Update order status
    $updateStmt = $conn->prepare(
        "UPDATE orders 
         SET status = 'cancelled', 
             cancellation_reason = :reason,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':reason' => $reason,
        ':id' => $orderId
    ]);

    ResponseHandler::success([], 'Order cancelled successfully');
}

/*********************************
 * RATE QUICK ORDER
 *********************************/
function rateQuickOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    // Check if order exists and is delivered
    $checkStmt = $conn->prepare(
        "SELECT o.id, o.quick_order_id 
         FROM orders o
         WHERE o.id = :id 
         AND o.user_id = :user_id 
         AND o.status = 'delivered'
         AND o.quick_order_id IS NOT NULL"
    );
    $checkStmt->execute([
        ':id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found or cannot be rated', 404);
    }

    // Check if already rated
    $existingStmt = $conn->prepare(
        "SELECT id FROM user_reviews 
         WHERE order_id = :order_id AND user_id = :user_id"
    );
    $existingStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already rated this order', 409);
    }

    // Create review
    $stmt = $conn->prepare(
        "INSERT INTO user_reviews 
            (user_id, order_id, quick_order_id, rating, comment, review_type, created_at)
         VALUES (:user_id, :order_id, :quick_order_id, :rating, :comment, 'quick_order', NOW())"
    );
    
    $stmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderId,
        ':quick_order_id' => $order['quick_order_id'],
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    // Update quick order rating
    updateQuickOrderRating($conn, $order['quick_order_id']);

    ResponseHandler::success([], 'Thank you for your rating!');
}

/*********************************
 * FORMATTING FUNCTIONS - SINGLE DEFINITIONS ONLY
 *********************************/
function formatQuickOrderListData($q, $baseUrl) {
    $imageUrl = '';
    if (!empty($q['image_url'])) {
        if (strpos($q['image_url'], 'http') === 0) {
            $imageUrl = $q['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu_items/' . $q['image_url'];
        }
    }
    
    return [
        'id' => $q['id'] ?? null,
        'title' => $q['title'] ?? '',
        'description' => $q['description'] ?? '',
        'category' => $q['category'] ?? '',
        'subcategory' => $q['subcategory'] ?? '',
        'item_type' => $q['item_type'] ?? 'food',
        'image_url' => $imageUrl,
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'delivery_time' => $q['delivery_time'] ?? '',
        'price' => floatval($q['price'] ?? 0),
        'formatted_price' => 'MK ' . number_format(floatval($q['price'] ?? 0), 2),
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'min_order_amount' => floatval($q['min_order_amount'] ?? 0),
        'available_all_day' => boolval($q['available_all_day'] ?? true),
        'seasonal_available' => boolval($q['seasonal_available'] ?? false),
        'available_units' => !empty($q['available_units']) ? explode(',', $q['available_units']) : [],
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? ''
    ];
}

function formatQuickOrderDetailData($q, $baseUrl) {
    $imageUrl = '';
    if (!empty($q['image_url'])) {
        if (strpos($q['image_url'], 'http') === 0) {
            $imageUrl = $q['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu_items/' . $q['image_url'];
        }
    }
    
    $isAvailable = true;
    if ($q['seasonal_available']) {
        $currentMonth = date('n');
        $startMonth = $q['season_start_month'] ?? 1;
        $endMonth = $q['season_end_month'] ?? 12;
        
        if ($startMonth <= $endMonth) {
            $isAvailable = ($currentMonth >= $startMonth && $currentMonth <= $endMonth);
        } else {
            $isAvailable = ($currentMonth >= $startMonth || $currentMonth <= $endMonth);
        }
    }
    
    return [
        'id' => $q['id'] ?? null,
        'title' => $q['title'] ?? '',
        'description' => $q['description'] ?? '',
        'category' => $q['category'] ?? '',
        'subcategory' => $q['subcategory'] ?? '',
        'item_type' => $q['item_type'] ?? 'food',
        'image_url' => $imageUrl,
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'delivery_time' => $q['delivery_time'] ?? '',
        'price' => floatval($q['price'] ?? 0),
        'formatted_price' => 'MK ' . number_format(floatval($q['price'] ?? 0), 2),
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'min_order_amount' => floatval($q['min_order_amount'] ?? 0),
        'available_all_day' => boolval($q['available_all_day'] ?? true),
        'available_start_time' => $q['available_start_time'] ?? '',
        'available_end_time' => $q['available_end_time'] ?? '',
        'seasonal_available' => boolval($q['seasonal_available'] ?? false),
        'season_start_month' => $q['season_start_month'] ?? null,
        'season_end_month' => $q['season_end_month'] ?? null,
        'is_available' => $isAvailable,
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? ''
    ];
}

function formatQuickOrderItemData($item, $baseUrl) {
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu_items/' . $item['image_url'];
        }
    }
    
    $displayPrice = $item['price'];
    $displayUnit = $item['unit_type'];
    
    if ($item['unit_type'] === 'kg' && $item['unit_value'] != 1) {
        $displayPrice = $item['price'] / $item['unit_value'];
        $displayUnit = 'g';
        $displayPrice = round($displayPrice * 1000, 2);
    }
    
    return [
        'id' => $item['id'] ?? null,
        'name' => $item['name'] ?? '',
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price'] ?? 0),
        'display_price' => floatval($displayPrice),
        'formatted_price' => 'MK ' . number_format(floatval($displayPrice), 2) . ' / ' . $displayUnit,
        'image_url' => $imageUrl,
        'unit_type' => $item['unit_type'] ?? 'piece',
        'unit_value' => floatval($item['unit_value'] ?? 1),
        'is_default' => boolval($item['is_default'] ?? false),
        'is_available' => boolval($item['is_available'] ?? true),
        'stock_quantity' => intval($item['stock_quantity'] ?? 0),
        'reorder_level' => intval($item['reorder_level'] ?? 10),
        'in_stock' => ($item['stock_quantity'] === null || $item['stock_quantity'] > 0) && $item['is_available'],
        'created_at' => $item['created_at'] ?? ''
    ];
}

function formatQuickOrderMerchantData($merchant, $baseUrl) {
    $imageUrl = '';
    if (!empty($merchant['image_url'])) {
        if (strpos($merchant['image_url'], 'http') === 0) {
            $imageUrl = $merchant['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . $merchant['image_url'];
        }
    }
    
    $deliveryFee = $merchant['custom_price'] ?? $merchant['delivery_fee'];
    $deliveryTime = $merchant['custom_delivery_time'] ?? $merchant['delivery_time'];
    
    return [
        'id' => $merchant['id'] ?? null,
        'name' => $merchant['name'] ?? '',
        'category' => $merchant['category'] ?? '',
        'business_type' => $merchant['business_type'] ?? 'restaurant',
        'rating' => floatval($merchant['rating'] ?? 0),
        'image_url' => $imageUrl,
        'is_open' => boolval($merchant['is_open'] ?? false),
        'delivery_time' => $deliveryTime,
        'delivery_fee' => floatval($deliveryFee),
        'formatted_delivery_fee' => 'MK ' . number_format(floatval($deliveryFee), 2),
        'min_order_amount' => floatval($merchant['min_order_amount'] ?? 0),
        'delivery_radius' => intval($merchant['delivery_radius'] ?? 5),
        'priority' => intval($merchant['priority'] ?? 0),
        'has_custom_price' => isset($merchant['custom_price']),
        'has_custom_delivery_time' => isset($merchant['custom_delivery_time'])
    ];
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function toggleQuickOrderFavorite($conn, $data, $userId) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    
    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }

    // Check if quick order exists
    $checkStmt = $conn->prepare("SELECT id FROM quick_orders WHERE id = :id");
    $checkStmt->execute([':id' => $quickOrderId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Check if already favorited
    $favoriteStmt = $conn->prepare(
        "SELECT id FROM user_favorites 
         WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
    );
    $favoriteStmt->execute([
        ':user_id' => $userId,
        ':quick_order_id' => $quickOrderId
    ]);
    
    if ($favoriteStmt->fetch()) {
        // Remove from favorites
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_favorites 
             WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
        );
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId
        ]);
        
        $isFavorited = false;
        $message = 'Removed from favorites';
    } else {
        // Add to favorites
        $insertStmt = $conn->prepare(
            "INSERT INTO user_favorites (user_id, quick_order_id, created_at)
             VALUES (:user_id, :quick_order_id, NOW())"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId
        ]);
        
        $isFavorited = true;
        $message = 'Added to favorites';
    }

    ResponseHandler::success([
        'is_favorited' => $isFavorited
    ], $message);
}

function getOrderDetails($conn, $orderId) {
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.payment_method,
                o.delivery_address,
                o.special_instructions,
                o.created_at,
                o.updated_at,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image,
                m.name as merchant_name,
                m.phone as merchant_phone,
                m.address as merchant_address
            FROM orders o
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.id = :id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $orderId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateQuickOrderRating($conn, $quickOrderId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM user_reviews
        WHERE quick_order_id = :quick_order_id
        AND review_type = 'quick_order'"
    );
    $stmt->execute([':quick_order_id' => $quickOrderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE quick_orders 
         SET rating = :rating, 
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':id' => $quickOrderId
    ]);
}

function formatOrderData($order) {
    global $baseUrl;
    
    $quickOrderImage = '';
    if (!empty($order['quick_order_image'])) {
        if (strpos($order['quick_order_image'], 'http') === 0) {
            $quickOrderImage = $order['quick_order_image'];
        } else {
            $quickOrderImage = rtrim($baseUrl, '/') . '/uploads/' . $order['quick_order_image'];
        }
    }
    
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        if (strpos($order['merchant_image'], 'http') === 0) {
            $merchantImage = $order['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/' . $order['merchant_image'];
        }
    }
    
    return [
        'id' => $order['id'] ?? null,
        'order_number' => $order['order_number'] ?? '',
        'status' => $order['status'] ?? '',
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'] ?? '',
        'delivery_address' => $order['delivery_address'] ?? '',
        'special_instructions' => $order['special_instructions'] ?? '',
        'quick_order_title' => $order['quick_order_title'] ?? '',
        'quick_order_image' => $quickOrderImage,
        'merchant_name' => $order['merchant_name'] ?? '',
        'merchant_phone' => $order['merchant_phone'] ?? '',
        'merchant_address' => $order['merchant_address'] ?? '',
        'merchant_image' => $merchantImage,
        'created_at' => $order['created_at'] ?? '',
        'updated_at' => $order['updated_at'] ?? ''
    ];
}

function formatOrderHistoryData($order, $baseUrl) {
    $quickOrderImage = '';
    if (!empty($order['quick_order_image'])) {
        if (strpos($order['quick_order_image'], 'http') === 0) {
            $quickOrderImage = $order['quick_order_image'];
        } else {
            $quickOrderImage = rtrim($baseUrl, '/') . '/uploads/' . $order['quick_order_image'];
        }
    }
    
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        if (strpos($order['merchant_image'], 'http') === 0) {
            $merchantImage = $order['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/' . $order['merchant_image'];
        }
    }
    
    return [
        'id' => $order['id'] ?? null,
        'order_number' => $order['order_number'] ?? '',
        'status' => $order['status'] ?? '',
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'] ?? '',
        'delivery_address' => $order['delivery_address'] ?? '',
        'special_instructions' => $order['special_instructions'] ?? '',
        'quick_order_title' => $order['quick_order_title'] ?? '',
        'quick_order_image' => $quickOrderImage,
        'merchant_name' => $order['merchant_name'] ?? '',
        'merchant_image' => $merchantImage,
        'created_at' => $order['created_at'] ?? '',
        'updated_at' => $order['updated_at'] ?? ''
    ];
}
?>