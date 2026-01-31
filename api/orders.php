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
 * SESSION CONFIG - MUST MATCH auth.php EXACTLY
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

/*********************************
 * AUTHENTICATION CHECK - SIMPLIFIED
 *********************************/
function checkAuthentication() {
    // Simple check - same as auth.php
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    return null;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropxbackend-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    // Check authentication first for all requests
    $userId = checkAuthentication();
    if (!$userId) {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }

    // Route authenticated requests
    if ($method === 'GET') {
        handleGetRequest($userId);
    } elseif ($method === 'POST') {
        handlePostRequest($userId);
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $orderId = $_GET['id'] ?? null;
    
    if ($orderId) {
        getOrderDetails($conn, $orderId, $userId);
    } else {
        getOrdersList($conn, $userId);
    }
}

/*********************************
 * GET ORDERS LIST
 *********************************/
function getOrdersList($conn, $userId) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? 'all';
    $orderNumber = $_GET['order_number'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

    // Build WHERE clause
    $whereConditions = ["o.user_id = :user_id"];
    $params = [':user_id' => $userId];

    if ($status !== 'all') {
        $whereConditions[] = "o.status = :status";
        $params[':status'] = $status;
    }

    if ($orderNumber) {
        $whereConditions[] = "o.order_number LIKE :order_number";
        $params[':order_number'] = "%$orderNumber%";
    }

    if ($startDate) {
        $whereConditions[] = "DATE(o.created_at) >= :start_date";
        $params[':start_date'] = $startDate;
    }

    if ($endDate) {
        $whereConditions[] = "DATE(o.created_at) <= :end_date";
        $params[':end_date'] = $endDate;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['created_at', 'total_amount', 'order_number'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get orders with items
    $sql = "SELECT DISTINCT
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
                o.merchant_id,
                m.name as restaurant_name,
                m.image_url as merchant_image,
                d.name as driver_name,
                d.phone as driver_phone,
                ot.estimated_delivery,
                ot.status as tracking_status,
                GROUP_CONCAT(
                    CONCAT(oi.item_name, '||', oi.quantity, '||', oi.price)
                    ORDER BY oi.id SEPARATOR ';;'
                ) as items_data
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            $whereClause
            GROUP BY o.id
            ORDER BY o.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    // Bind all parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user info for customer name
    $userStmt = $conn->prepare(
        "SELECT full_name, phone FROM users WHERE id = :user_id"
    );
    $userStmt->execute([':user_id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Format orders data
    $formattedOrders = array_map(function($order) use ($user) {
        return formatOrderData($order, $user);
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
 * GET ORDER DETAILS
 *********************************/
function getOrderDetails($conn, $orderId, $userId) {
    // Get order details
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
                o.cancellation_reason,
                o.created_at,
                o.updated_at,
                u.full_name as customer_name,
                u.phone as customer_phone,
                u.email as customer_email,
                m.name as restaurant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.image_url as merchant_image,
                d.name as driver_name,
                d.phone as driver_phone,
                d.email as driver_email,
                d.image_url as driver_image,
                ot.estimated_delivery,
                ot.location_updates,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            WHERE o.id = :order_id AND o.user_id = :user_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Get order items
    $itemsSql = "SELECT 
                    id,
                    item_name as name,
                    quantity,
                    price,
                    total,
                    created_at
                FROM order_items
                WHERE order_id = :order_id
                ORDER BY id";
    
    $itemsStmt = $conn->prepare($itemsSql);
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order tracking history
    $trackingSql = "SELECT 
                        status,
                        estimated_delivery,
                        location_updates,
                        created_at,
                        updated_at
                    FROM order_tracking
                    WHERE order_id = :order_id
                    ORDER BY created_at DESC";
    
    $trackingStmt = $conn->prepare($trackingSql);
    $trackingStmt->execute([':order_id' => $orderId]);
    $tracking = $trackingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order status history
    $statusSql = "SELECT 
                    old_status,
                    new_status,
                    changed_by,
                    changed_by_id,
                    reason,
                    notes,
                    created_at as timestamp
                FROM order_status_history
                WHERE order_id = :order_id
                ORDER BY created_at ASC";
    
    $statusStmt = $conn->prepare($statusSql);
    $statusStmt->execute([':order_id' => $orderId]);
    $statusHistory = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response
    $orderData = formatOrderDetailData($order);
    $orderData['items'] = array_map('formatOrderItemData', $items);
    $orderData['tracking_history'] = array_map('formatTrackingData', $tracking);
    $orderData['status_history'] = $statusHistory;

    ResponseHandler::success([
        'order' => $orderData
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_order':
            createOrder($conn, $input, $userId);
            break;
        case 'cancel_order':
            cancelOrder($conn, $input, $userId);
            break;
        case 'create_review':
            createOrderReview($conn, $input, $userId);
            break;
        case 'reorder':
            reorder($conn, $input, $userId);
            break;
        case 'track_order':
            trackOrder($conn, $input, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * CREATE ORDER
 *********************************/
function createOrder($conn, $data, $userId) {
    // Validate required data
    $requiredFields = ['merchant_id', 'items', 'delivery_address', 'total_amount'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            ResponseHandler::error("Missing required field: $field", 400);
        }
    }

    // Validate merchant exists
    $merchantId = $data['merchant_id'];
    $merchantStmt = $conn->prepare(
        "SELECT id, name, delivery_fee, is_open FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $merchantStmt->execute([':id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    if (!$merchant['is_open']) {
        ResponseHandler::error('Merchant is currently closed', 400);
    }

    // Validate items
    $items = $data['items'];
    if (!is_array($items) || empty($items)) {
        ResponseHandler::error('No items in order', 400);
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($items as $item) {
        if (empty($item['name']) || empty($item['price']) || empty($item['quantity'])) {
            ResponseHandler::error('Invalid item data', 400);
        }
        $subtotal += $item['price'] * $item['quantity'];
    }

    $deliveryFee = $merchant['delivery_fee'];
    $totalAmount = $subtotal + $deliveryFee;

    // Generate unique order number
    $orderNumber = 'DROPX-' . date('Y-m') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Create order
        $orderSql = "INSERT INTO orders (
            order_number, user_id, merchant_id, subtotal, delivery_fee, 
            total_amount, payment_method, delivery_address, special_instructions,
            status, created_at
        ) VALUES (
            :order_number, :user_id, :merchant_id, :subtotal, :delivery_fee,
            :total_amount, :payment_method, :delivery_address, :special_instructions,
            'pending', NOW()
        )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total_amount' => $totalAmount,
            ':payment_method' => $data['payment_method'] ?? 'Cash on Delivery',
            ':delivery_address' => $data['delivery_address'],
            ':special_instructions' => $data['special_instructions'] ?? ''
        ]);

        $orderId = $conn->lastInsertId();

        // Create order items
        $itemSql = "INSERT INTO order_items (
            order_id, item_name, quantity, price, total, created_at
        ) VALUES (
            :order_id, :item_name, :quantity, :price, :total, NOW()
        )";

        $itemStmt = $conn->prepare($itemSql);
        foreach ($items as $item) {
            $itemTotal = $item['price'] * $item['quantity'];
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $itemTotal
            ]);
        }

        // Create order tracking
        $trackingSql = "INSERT INTO order_tracking (
            order_id, status, estimated_delivery, created_at
        ) VALUES (
            :order_id, :status, :estimated_delivery, NOW()
        )";

        $trackingStmt = $conn->prepare($trackingSql);
        $estimatedDelivery = date('Y-m-d H:i:s', strtotime('+45 minutes'));
        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':status' => 'Order placed',
            ':estimated_delivery' => $estimatedDelivery
        ]);

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => 'pending',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);

        // Update user's total orders
        $updateUserSql = "UPDATE users SET total_orders = total_orders + 1 WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([':user_id' => $userId]);

        // Log user activity
        $activitySql = "INSERT INTO user_activities (
            user_id, activity_type, description, created_at
        ) VALUES (
            :user_id, 'order_created', :description, NOW()
        )";

        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            ':user_id' => $userId,
            ':description' => "Created order #$orderNumber with {$merchant['name']}"
        ]);

        // Commit transaction
        $conn->commit();

        ResponseHandler::success([
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'message' => 'Order created successfully'
        ], 201);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CANCEL ORDER
 *********************************/
function cancelOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $reason = trim($data['reason'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be cancelled
    $cancellableStatuses = ['pending', 'confirmed'];
    if (!in_array($order['status'], $cancellableStatuses)) {
        ResponseHandler::error('Order cannot be cancelled at this stage', 400);
    }

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Update order status
        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                status = 'cancelled',
                cancellation_reason = :reason,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':reason' => $reason
        ]);

        // Update order tracking
        $trackingStmt = $conn->prepare(
            "UPDATE order_tracking SET 
                status = 'Cancelled',
                updated_at = NOW()
             WHERE order_id = :order_id"
        );
        $trackingStmt->execute([':order_id' => $orderId]);

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, reason, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, :reason, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => 'cancelled',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId,
            ':reason' => $reason
        ]);

        // Log user activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (
                user_id, activity_type, description, created_at
            ) VALUES (
                :user_id, 'order_cancelled', :description, NOW()
            )"
        );
        
        $activityStmt->execute([
            ':user_id' => $userId,
            ':description' => "Cancelled order #$orderId"
        ]);

        $conn->commit();

        ResponseHandler::success([], 'Order cancelled successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ORDER REVIEW
 *********************************/
function createOrderReview($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');
    $reviewType = $data['review_type'] ?? 'merchant';

    if (!$orderId || !$rating) {
        ResponseHandler::error('Order ID and rating are required', 400);
    }

    // Check if order exists and belongs to user
    $orderStmt = $conn->prepare(
        "SELECT id, merchant_id, status FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    if ($order['status'] !== 'delivered') {
        ResponseHandler::error('Order must be delivered before reviewing', 400);
    }

    // Check if already reviewed
    $existingStmt = $conn->prepare(
        "SELECT id FROM user_reviews 
         WHERE user_id = :user_id AND order_id = :order_id"
    );
    $existingStmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already reviewed this order', 409);
    }

    // Create review
    $reviewSql = "INSERT INTO user_reviews (
        user_id, order_id, merchant_id, rating, comment, review_type, created_at
    ) VALUES (
        :user_id, :order_id, :merchant_id, :rating, :comment, :review_type, NOW()
    )";

    $reviewStmt = $conn->prepare($reviewSql);
    $reviewStmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderId,
        ':merchant_id' => $merchantId ?: $order['merchant_id'],
        ':rating' => $rating,
        ':comment' => $comment,
        ':review_type' => $reviewType
    ]);

    // Update merchant rating if review type is merchant
    if ($reviewType === 'merchant' && $merchantId) {
        updateMerchantRating($conn, $merchantId);
    }

    // Update user's rating if review type is driver
    if ($reviewType === 'driver') {
        // Update driver rating logic would go here
    }

    ResponseHandler::success([], 'Review submitted successfully');
}

/*********************************
 * REORDER
 *********************************/
function reorder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Get original order details
    $orderSql = "SELECT 
                    o.*,
                    GROUP_CONCAT(
                        CONCAT(oi.item_name, '||', oi.quantity, '||', oi.price)
                        ORDER BY oi.id SEPARATOR ';;'
                    ) as items_data
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.id = :order_id AND o.user_id = :user_id
                GROUP BY o.id";
    
    $orderStmt = $conn->prepare($orderSql);
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if merchant is still active
    $merchantStmt = $conn->prepare(
        "SELECT id, is_open, is_active FROM merchants WHERE id = :id"
    );
    $merchantStmt->execute([':id' => $order['merchant_id']]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant || !$merchant['is_active']) {
        ResponseHandler::error('Merchant is no longer available', 400);
    }

    if (!$merchant['is_open']) {
        ResponseHandler::error('Merchant is currently closed', 400);
    }

    // Parse items data
    $items = [];
    if (!empty($order['items_data'])) {
        $itemStrings = explode(';;', $order['items_data']);
        foreach ($itemStrings as $itemString) {
            $parts = explode('||', $itemString);
            if (count($parts) === 3) {
                $items[] = [
                    'name' => $parts[0],
                    'quantity' => (int)$parts[1],
                    'price' => (float)$parts[2]
                ];
            }
        }
    }

    // Prepare reorder data
    $reorderData = [
        'merchant_id' => $order['merchant_id'],
        'items' => $items,
        'delivery_address' => $order['delivery_address'],
        'special_instructions' => $order['special_instructions'],
        'payment_method' => $order['payment_method'],
        'total_amount' => $order['total_amount']
    ];

    // Call createOrder function
    createOrder($conn, $reorderData, $userId);
}

/*********************************
 * TRACK ORDER
 *********************************/
function trackOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Get order tracking info
    $sql = "SELECT 
                o.order_number,
                o.status,
                o.created_at,
                m.name as merchant_name,
                d.name as driver_name,
                d.phone as driver_phone,
                d.current_latitude as driver_lat,
                d.current_longitude as driver_lng,
                d.image_url as driver_image,
                ot.estimated_delivery,
                ot.location_updates
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            WHERE o.id = :order_id AND o.user_id = :user_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $trackingInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trackingInfo) {
        ResponseHandler::error('Order not found', 404);
    }

    // Parse location updates
    $locationUpdates = [];
    if (!empty($trackingInfo['location_updates'])) {
        $updates = json_decode($trackingInfo['location_updates'], true);
        if (is_array($updates)) {
            $locationUpdates = $updates;
        }
    }

    // Format driver image URL
    global $baseUrl;
    $driverImage = '';
    if (!empty($trackingInfo['driver_image'])) {
        if (strpos($trackingInfo['driver_image'], 'http') === 0) {
            $driverImage = $trackingInfo['driver_image'];
        } else {
            $driverImage = rtrim($baseUrl, '/') . '/uploads/drivers/' . $trackingInfo['driver_image'];
        }
    }

    $response = [
        'order_number' => $trackingInfo['order_number'],
        'status' => $trackingInfo['status'],
        'merchant_name' => $trackingInfo['merchant_name'],
        'driver_name' => $trackingInfo['driver_name'],
        'driver_phone' => $trackingInfo['driver_phone'],
        'driver_image' => $driverImage,
        'driver_location' => $trackingInfo['driver_lat'] && $trackingInfo['driver_lng'] 
            ? ['lat' => (float)$trackingInfo['driver_lat'], 'lng' => (float)$trackingInfo['driver_lng']]
            : null,
        'estimated_delivery' => $trackingInfo['estimated_delivery'],
        'location_updates' => $locationUpdates,
        'order_placed_at' => $trackingInfo['created_at']
    ];

    ResponseHandler::success([
        'tracking_info' => $response
    ]);
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        parse_str(file_get_contents('php://input'), $input);
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'update_order':
            updateOrder($conn, $input, $userId);
            break;
        case 'update_delivery_address':
            updateDeliveryAddress($conn, $input, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * UPDATE ORDER
 *********************************/
function updateOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be modified
    $modifiableStatuses = ['pending'];
    if (!in_array($order['status'], $modifiableStatuses)) {
        ResponseHandler::error('Order cannot be modified at this stage', 400);
    }

    // Build update query dynamically based on provided data
    $updatableFields = ['special_instructions', 'delivery_address'];
    $updates = [];
    $params = [':order_id' => $orderId];

    foreach ($updatableFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    if (empty($updates)) {
        ResponseHandler::error('No fields to update', 400);
    }

    $updates[] = "updated_at = NOW()";
    $updateSql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = :order_id";

    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute($params);

    ResponseHandler::success([], 'Order updated successfully');
}

/*********************************
 * UPDATE DELIVERY ADDRESS
 *********************************/
function updateDeliveryAddress($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $newAddress = trim($data['delivery_address'] ?? '');

    if (!$orderId || !$newAddress) {
        ResponseHandler::error('Order ID and new address are required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can have address changed
    $addressChangeableStatuses = ['pending', 'confirmed'];
    if (!in_array($order['status'], $addressChangeableStatuses)) {
        ResponseHandler::error('Delivery address cannot be changed at this stage', 400);
    }

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Update address
        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                delivery_address = :address,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':address' => $newAddress
        ]);

        // Log the change
        $logStmt = $conn->prepare(
            "INSERT INTO user_activities (
                user_id, activity_type, description, created_at
            ) VALUES (
                :user_id, 'address_changed', :description, NOW()
            )"
        );
        
        $logStmt->execute([
            ':user_id' => $userId,
            ':description' => "Changed delivery address for order #$orderId"
        ]);

        $conn->commit();

        ResponseHandler::success([], 'Delivery address updated successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * FORMAT ORDER DATA
 *********************************/
function formatOrderData($order, $user) {
    global $baseUrl;
    
    // Parse items data
    $items = [];
    $itemCount = 0;
    
    if (!empty($order['items_data'])) {
        $itemStrings = explode(';;', $order['items_data']);
        foreach ($itemStrings as $itemString) {
            $parts = explode('||', $itemString);
            if (count($parts) === 3) {
                $items[] = [
                    'name' => $parts[0],
                    'quantity' => (int)$parts[1],
                    'price' => (float)$parts[2]
                ];
                $itemCount += (int)$parts[1];
            }
        }
    }

    // Format merchant image URL
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
    }

    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'customer_name' => $user['full_name'] ?? '',
        'customer_phone' => $user['phone'] ?? '',
        'delivery_address' => $order['delivery_address'],
        'total_amount' => (float)$order['total_amount'],
        'delivery_fee' => (float)$order['delivery_fee'],
        'subtotal' => (float)$order['subtotal'],
        'items' => $items,
        'item_count' => $itemCount,
        'order_date' => $order['created_at'],
        'estimated_delivery' => $order['estimated_delivery'],
        'payment_method' => $order['payment_method'],
        'payment_status' => getPaymentStatus($order['status']),
        'restaurant_name' => $order['restaurant_name'],
        'merchant_id' => $order['merchant_id'] ?? null,
        'merchant_image' => $merchantImage,
        'driver_name' => $order['driver_name'] ?? '',
        'driver_phone' => $order['driver_phone'] ?? '',
        'special_instructions' => $order['special_instructions'] ?? '',
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at']
    ];
}

/*********************************
 * FORMAT ORDER DETAIL DATA
 *********************************/
function formatOrderDetailData($order) {
    global $baseUrl;
    
    // Format merchant image URL
    $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
    
    // Format driver image URL
    $driverImage = formatImageUrl($order['driver_image'], $baseUrl, 'drivers');
    
    // Format quick order image URL
    $quickOrderImage = formatImageUrl($order['quick_order_image'], $baseUrl, 'quick_orders');

    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'],
        'customer_email' => $order['customer_email'],
        'delivery_address' => $order['delivery_address'],
        'total_amount' => (float)$order['total_amount'],
        'delivery_fee' => (float)$order['delivery_fee'],
        'subtotal' => (float)$order['subtotal'],
        'order_date' => $order['created_at'],
        'estimated_delivery' => $order['estimated_delivery'],
        'payment_method' => $order['payment_method'],
        'payment_status' => getPaymentStatus($order['status']),
        'restaurant_name' => $order['restaurant_name'],
        'merchant_address' => $order['merchant_address'],
        'merchant_phone' => $order['merchant_phone'],
        'merchant_image' => $merchantImage,
        'driver_name' => $order['driver_name'],
        'driver_phone' => $order['driver_phone'],
        'driver_email' => $order['driver_email'],
        'driver_image' => $driverImage,
        'special_instructions' => $order['special_instructions'],
        'cancellation_reason' => $order['cancellation_reason'],
        'quick_order_title' => $order['quick_order_title'],
        'quick_order_image' => $quickOrderImage,
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at']
    ];
}

/*********************************
 * FORMAT ORDER ITEM DATA
 *********************************/
function formatOrderItemData($item) {
    return [
        'id' => $item['id'],
        'name' => $item['name'],
        'quantity' => (int)$item['quantity'],
        'price' => (float)$item['price'],
        'total' => (float)$item['total'],
        'created_at' => $item['created_at']
    ];
}

/*********************************
 * FORMAT TRACKING DATA
 *********************************/
function formatTrackingData($tracking) {
    return [
        'status' => $tracking['status'],
        'estimated_delivery' => $tracking['estimated_delivery'],
        'location_updates' => json_decode($tracking['location_updates'] ?? '[]', true),
        'created_at' => $tracking['created_at'],
        'updated_at' => $tracking['updated_at']
    ];
}

/*********************************
 * FORMAT IMAGE URL
 *********************************/
function formatImageUrl($imagePath, $baseUrl, $type = '') {
    if (empty($imagePath)) {
        return '';
    }
    
    // If it's already a full URL, use it as is
    if (strpos($imagePath, 'http') === 0) {
        return $imagePath;
    }
    
    // Otherwise, build the full URL
    $folder = '';
    switch ($type) {
        case 'merchants':
            $folder = 'uploads/merchants';
            break;
        case 'drivers':
            $folder = 'uploads/drivers';
            break;
        case 'quick_orders':
            $folder = 'uploads/quick_orders';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($imagePath, '/');
}

/*********************************
 * GET PAYMENT STATUS
 *********************************/
function getPaymentStatus($orderStatus) {
    // This is a simplified version - in reality, this should come from your payment system
    $paidStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived', 'delivered'];
    
    if (in_array($orderStatus, $paidStatuses)) {
        return 'paid';
    }
    
    return $orderStatus === 'cancelled' ? 'refunded' : 'pending';
}

/*********************************
 * UPDATE MERCHANT RATING
 *********************************/
function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM user_reviews
        WHERE merchant_id = :merchant_id AND review_type = 'merchant'"
    );
    $stmt->execute([':merchant_id' => $merchantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE merchants 
         SET rating = :rating, 
             review_count = :review_count,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':review_count' => $result['total_reviews'] ?? 0,
        ':id' => $merchantId
    ]);
}
?>