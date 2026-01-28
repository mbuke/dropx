<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

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
    $db = new Database();
    $conn = $db->getConnection();

    // Check for specific quick order ID
    $orderId = $_GET['id'] ?? null;
    
    if ($orderId) {
        getQuickOrderDetails($conn, $orderId);
    } else {
        getQuickOrdersList($conn);
    }
}

/*********************************
 * GET QUICK ORDERS LIST
 *********************************/
function getQuickOrdersList($conn) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'order_count';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $isPopular = $_GET['is_popular'] ?? null;

    // Build WHERE clause
    $whereConditions = ["qo.is_active = 1"];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "qo.category = :category";
        $params[':category'] = $category;
    }

    if ($search) {
        $whereConditions[] = "(qo.title LIKE :search OR qo.description LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($isPopular !== null) {
        $whereConditions[] = "qo.is_popular = :is_popular";
        $params[':is_popular'] = $isPopular === 'true' ? 1 : 0;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['order_count', 'title', 'created_at', 'rating'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'order_count';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM quick_orders qo $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get quick orders
    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.image_url,
                qo.color,
                qo.info,
                qo.is_popular,
                qo.category,
                qo.description,
                qo.delivery_time,
                qo.price_range,
                qo.order_count,
                qo.rating,
                qo.created_at,
                qo.updated_at
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

    // Format quick order data
    $formattedOrders = array_map('formatQuickOrderListData', $quickOrders);

    ResponseHandler::success([
        'quick_orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET QUICK ORDER DETAILS
 *********************************/
function getQuickOrderDetails($conn, $orderId) {
    $stmt = $conn->prepare(
        "SELECT 
            qo.id,
            qo.title,
            qo.image_url,
            qo.color,
            qo.info,
            qo.is_popular,
            qo.category,
            qo.description,
            qo.delivery_time,
            qo.price_range,
            qo.order_count,
            qo.rating,
            qo.created_at,
            qo.updated_at
        FROM quick_orders qo
        WHERE qo.id = :id AND qo.is_active = 1"
    );
    
    $stmt->execute([':id' => $orderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Get included items
    $itemsStmt = $conn->prepare(
        "SELECT 
            qoi.id,
            qoi.name,
            qoi.description,
            qoi.price,
            qoi.image_url,
            qoi.is_default,
            qoi.created_at
        FROM quick_order_items qoi
        WHERE qoi.quick_order_id = :quick_order_id
        ORDER BY qoi.is_default DESC, qoi.name"
    );
    
    $itemsStmt->execute([':quick_order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get available merchants for this quick order
    $merchantsStmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.category,
            m.rating,
            m.image_url,
            m.is_open,
            m.delivery_time,
            m.delivery_fee,
            m.distance
        FROM merchants m
        INNER JOIN quick_order_merchants qom ON m.id = qom.merchant_id
        WHERE qom.quick_order_id = :quick_order_id
        AND m.is_active = 1
        AND m.is_open = 1
        ORDER BY m.rating DESC, m.distance ASC
        LIMIT 5"
    );
    
    $merchantsStmt->execute([':quick_order_id' => $orderId]);
    $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderData = formatQuickOrderDetailData($quickOrder);
    $orderData['items'] = array_map('formatQuickOrderItemData', $items);
    $orderData['merchants'] = array_map('formatQuickOrderMerchantData', $merchants);

    ResponseHandler::success([
        'quick_order' => $orderData
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_order':
            createQuickOrder($conn, $input);
            break;
        case 'get_order_history':
            getQuickOrderHistory($conn, $input);
            break;
        case 'cancel_order':
            cancelQuickOrder($conn, $input);
            break;
        case 'rate_order':
            rateQuickOrder($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * CREATE QUICK ORDER
 *********************************/
function createQuickOrder($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
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
        "SELECT title, price_range, delivery_time FROM quick_orders WHERE id = :id AND is_active = 1"
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
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET QUICK ORDER HISTORY
 *********************************/
function getQuickOrderHistory($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
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
            INNER JOIN quick_orders qo ON o.quick_order_id = qo.id
            INNER JOIN merchants m ON o.merchant_id = m.id
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
    $formattedOrders = array_map('formatOrderHistoryData', $orders);

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
function cancelQuickOrder($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
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
function rateQuickOrder($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
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
 * GET ORDER DETAILS
 *********************************/
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
            INNER JOIN quick_orders qo ON o.quick_order_id = qo.id
            INNER JOIN merchants m ON o.merchant_id = m.id
            WHERE o.id = :id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $orderId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * UPDATE QUICK ORDER RATING
 *********************************/
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

/*********************************
 * FORMAT QUICK ORDER LIST DATA
 *********************************/
function formatQuickOrderListData($q) {
    return [
        'id' => $q['id'],
        'title' => $q['title'] ?? '',
        'image_url' => $q['image_url'] ?? '',
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'category' => $q['category'] ?? '',
        'description' => $q['description'] ?? '',
        'delivery_time' => $q['delivery_time'] ?? '',
        'price_range' => $q['price_range'] ?? '',
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? ''
    ];
}

/*********************************
 * FORMAT QUICK ORDER DETAIL DATA
 *********************************/
function formatQuickOrderDetailData($q) {
    return [
        'id' => $q['id'],
        'title' => $q['title'] ?? '',
        'image_url' => $q['image_url'] ?? '',
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'category' => $q['category'] ?? '',
        'description' => $q['description'] ?? '',
        'delivery_time' => $q['delivery_time'] ?? '',
        'price_range' => $q['price_range'] ?? '',
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? ''
    ];
}

/*********************************
 * FORMAT QUICK ORDER ITEM DATA
 *********************************/
function formatQuickOrderItemData($item) {
    return [
        'id' => $item['id'],
        'name' => $item['name'] ?? '',
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price'] ?? 0),
        'image_url' => $item['image_url'] ?? '',
        'is_default' => boolval($item['is_default'] ?? false),
        'created_at' => $item['created_at'] ?? ''
    ];
}

/*********************************
 * FORMAT QUICK ORDER MERCHANT DATA
 *********************************/
function formatQuickOrderMerchantData($merchant) {
    return [
        'id' => $merchant['id'],
        'name' => $merchant['name'] ?? '',
        'category' => $merchant['category'] ?? '',
        'rating' => floatval($merchant['rating'] ?? 0),
        'image_url' => $merchant['image_url'] ?? '',
        'is_open' => boolval($merchant['is_open'] ?? false),
        'delivery_time' => $merchant['delivery_time'] ?? '',
        'delivery_fee' => floatval($merchant['delivery_fee'] ?? 0),
        'distance' => $merchant['distance'] ?? ''
    ];
}

/*********************************
 * FORMAT ORDER DATA
 *********************************/
function formatOrderData($order) {
    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'],
        'delivery_address' => $order['delivery_address'],
        'special_instructions' => $order['special_instructions'],
        'quick_order_title' => $order['quick_order_title'],
        'quick_order_image' => $order['quick_order_image'],
        'merchant_name' => $order['merchant_name'],
        'merchant_phone' => $order['merchant_phone'],
        'merchant_address' => $order['merchant_address'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at']
    ];
}

/*********************************
 * FORMAT ORDER HISTORY DATA
 *********************************/
function formatOrderHistoryData($order) {
    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'],
        'delivery_address' => $order['delivery_address'],
        'special_instructions' => $order['special_instructions'],
        'quick_order_title' => $order['quick_order_title'],
        'quick_order_image' => $order['quick_order_image'],
        'merchant_name' => $order['merchant_name'],
        'merchant_image' => $order['merchant_image'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at']
    ];
}
?>