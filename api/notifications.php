<?php
/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx-production-6373.up.railway.app";

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
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    // Check authentication
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }
    
    $userId = $_SESSION['user_id'];

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }

    if ($method === 'GET') {
        handleGetRequest($conn, $userId);
    } elseif ($method === 'POST') {
        handlePostRequest($conn, $userId, $input);
    } elseif ($method === 'PUT') {
        handlePutRequest($conn, $userId, $input);
    } elseif ($method === 'DELETE') {
        handleDeleteRequest($conn, $userId, $input);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($conn, $userId) {
    $notificationId = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? null;
    
    if ($notificationId) {
        getNotificationDetail($conn, $userId, $notificationId);
    } elseif ($action === 'statistics') {
        getNotificationStatistics($conn, $userId);
    } elseif ($action === 'preferences') {
        getNotificationPreferences($conn, $userId);
    } else {
        getNotificationsList($conn, $userId);
    }
}

/*********************************
 * GET NOTIFICATIONS LIST
 *********************************/
function getNotificationsList($conn, $userId) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $type = $_GET['type'] ?? '';
    $isRead = $_GET['is_read'] ?? null;
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

    // Build WHERE clause
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $whereConditions[] = "type = :type";
        $params[':type'] = $type;
    }

    if ($isRead !== null) {
        $whereConditions[] = "is_read = :is_read";
        $params[':is_read'] = ($isRead === 'true' || $isRead === '1') ? 1 : 0;
    }

    if ($search) {
        $whereConditions[] = "(title LIKE :search OR message LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['created_at', 'sent_at', 'title', 'is_read'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM notifications $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get notifications
    $sql = "SELECT 
                id,
                type,
                title,
                message,
                data,
                is_read,
                read_at,
                sent_via,
                sent_at,
                created_at
            FROM notifications
            $whereClause
            ORDER BY $sortBy $sortOrder
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
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format notification data
    $formattedNotifications = array_map('formatNotificationData', $notifications);

    // Get unread count
    $unreadStmt = $conn->prepare(
        "SELECT COUNT(*) as unread_count 
         FROM notifications 
         WHERE user_id = :user_id AND is_read = 0"
    );
    $unreadStmt->execute([':user_id' => $userId]);
    $unreadCount = $unreadStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

    ResponseHandler::success([
        'success' => true,
        'data' => [
            'notifications' => $formattedNotifications,
            'unread_count' => intval($unreadCount),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => intval($totalCount),
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]
    ]);
}

/*********************************
 * GET NOTIFICATION DETAIL
 *********************************/
function getNotificationDetail($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "SELECT 
            id,
            type,
            title,
            message,
            data,
            is_read,
            read_at,
            sent_via,
            sent_at,
            created_at
        FROM notifications
        WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$notification) {
        ResponseHandler::error('Notification not found', 404);
    }

    // Mark as read when viewing details
    if (!$notification['is_read']) {
        $updateStmt = $conn->prepare(
            "UPDATE notifications 
             SET is_read = 1, read_at = NOW()
             WHERE id = :id AND user_id = :user_id"
        );
        $updateStmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    }

    ResponseHandler::success([
        'success' => true,
        'data' => [
            'notification' => formatNotificationData($notification)
        ]
    ]);
}

/*********************************
 * GET NOTIFICATION STATISTICS
 *********************************/
function getNotificationStatistics($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
            COUNT(CASE WHEN type = 'order' THEN 1 END) as order_notifications,
            COUNT(CASE WHEN type = 'promotion' THEN 1 END) as promotion_notifications,
            COUNT(CASE WHEN type = 'delivery' THEN 1 END) as delivery_notifications,
            COUNT(CASE WHEN type = 'system' THEN 1 END) as system_notifications,
            COUNT(CASE WHEN type = 'payment' THEN 1 END) as payment_notifications,
            COUNT(CASE WHEN type = 'update' THEN 1 END) as update_notifications
         FROM notifications 
         WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'statistics' => [
                'total' => intval($stats['total'] ?? 0),
                'unread' => intval($stats['unread'] ?? 0),
                'by_type' => [
                    'order' => intval($stats['order_notifications'] ?? 0),
                    'promotion' => intval($stats['promotion_notifications'] ?? 0),
                    'delivery' => intval($stats['delivery_notifications'] ?? 0),
                    'system' => intval($stats['system_notifications'] ?? 0),
                    'payment' => intval($stats['payment_notifications'] ?? 0),
                    'update' => intval($stats['update_notifications'] ?? 0)
                ]
            ]
        ]
    ]);
}

/*********************************
 * GET NOTIFICATION PREFERENCES
 *********************************/
function getNotificationPreferences($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            push_enabled,
            email_enabled,
            sms_enabled,
            order_updates,
            promotional_offers,
            new_merchants,
            special_offers,
            created_at,
            updated_at
        FROM user_notification_settings 
        WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$preferences) {
        // Return default preferences if not set
        $preferences = [
            'push_enabled' => true,
            'email_enabled' => true,
            'sms_enabled' => false,
            'order_updates' => true,
            'promotional_offers' => true,
            'new_merchants' => true,
            'special_offers' => true
        ];
    }
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'preferences' => $preferences
        ]
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($conn, $userId, $input) {
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'mark_all_read':
            markAllAsRead($conn, $userId, $input);
            break;
        case 'clear_all':
            clearAllNotifications($conn, $userId, $input);
            break;
        case 'batch_update':
            batchUpdateNotifications($conn, $userId, $input);
            break;
        case 'update_preferences':
            updateNotificationPreferences($conn, $userId, $input);
            break;
        case 'trigger_event': // ADDED: Support for trigger_event
            handleTriggerEvent($conn, $userId, $input);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($conn, $userId, $input) {
    $notificationId = $input['id'] ?? null;
    
    if (!$notificationId) {
        ResponseHandler::error('Notification ID is required', 400);
    }

    markAsRead($conn, $userId, $notificationId);
}

/*********************************
 * DELETE REQUESTS
 *********************************/
function handleDeleteRequest($conn, $userId, $input) {
    $notificationId = $input['id'] ?? null;
    
    if (!$notificationId) {
        ResponseHandler::error('Notification ID is required', 400);
    }

    deleteNotification($conn, $userId, $notificationId);
}

/*********************************
 * MARK NOTIFICATION AS READ
 *********************************/
function markAsRead($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "UPDATE notifications 
         SET is_read = 1, read_at = NOW()
         WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Notification not found', 404);
    }

    ResponseHandler::success([
        'success' => true,
        'message' => 'Notification marked as read'
    ]);
}

/*********************************
 * MARK ALL AS READ
 *********************************/
function markAllAsRead($conn, $userId, $data) {
    $type = $data['type'] ?? '';

    $sql = "UPDATE notifications 
            SET is_read = 1, read_at = NOW()
            WHERE user_id = :user_id AND is_read = 0";
    
    $params = [':user_id' => $userId];
    
    if ($type && $type !== 'all') {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $affectedRows = $stmt->rowCount();
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'marked_count' => $affectedRows
        ],
        'message' => "Marked $affectedRows notifications as read"
    ]);
}

/*********************************
 * CLEAR ALL NOTIFICATIONS
 *********************************/
function clearAllNotifications($conn, $userId, $data) {
    $type = $data['type'] ?? '';
    $olderThan = $data['older_than'] ?? '';

    $sql = "DELETE FROM notifications WHERE user_id = :user_id";
    $params = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }

    if ($olderThan) {
        $sql .= " AND created_at < :older_than";
        $params[':older_than'] = $olderThan;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $deletedCount = $stmt->rowCount();
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'deleted_count' => $deletedCount
        ],
        'message' => "Cleared $deletedCount notifications"
    ]);
}

/*********************************
 * DELETE SINGLE NOTIFICATION
 *********************************/
function deleteNotification($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "DELETE FROM notifications 
         WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Notification not found', 404);
    }

    ResponseHandler::success([
        'success' => true,
        'message' => 'Notification deleted successfully'
    ]);
}

/*********************************
 * BATCH UPDATE NOTIFICATIONS
 *********************************/
function batchUpdateNotifications($conn, $userId, $data) {
    $notificationIds = $data['notification_ids'] ?? [];
    $operation = $data['operation'] ?? ''; // 'mark_read' or 'delete'
    
    if (empty($notificationIds)) {
        ResponseHandler::error('No notification IDs provided', 400);
    }
    
    if (!in_array($operation, ['mark_read', 'delete'])) {
        ResponseHandler::error('Invalid operation. Use "mark_read" or "delete"', 400);
    }
    
    // Create placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    $params = array_merge([$userId], $notificationIds);
    
    if ($operation === 'mark_read') {
        $sql = "UPDATE notifications 
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND id IN ($placeholders)";
    } else {
        $sql = "DELETE FROM notifications 
                WHERE user_id = ? AND id IN ($placeholders)";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $affectedRows = $stmt->rowCount();
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'affected_count' => $affectedRows
        ],
        'message' => "Successfully processed $affectedRows notifications"
    ]);
}

/*********************************
 * UPDATE NOTIFICATION PREFERENCES
 *********************************/
function updateNotificationPreferences($conn, $userId, $data) {
    $pushEnabled = $data['push_notifications'] ?? null;
    $emailEnabled = $data['email_notifications'] ?? null;
    $smsEnabled = $data['sms_notifications'] ?? null;
    $orderUpdates = $data['order_updates'] ?? null;
    $promotionalOffers = $data['promotional_offers'] ?? null;
    $newMerchants = $data['new_merchants'] ?? null;
    $specialOffers = $data['special_offers'] ?? null;
    
    // Check if settings exist
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_notification_settings WHERE user_id = :user_id"
    );
    $checkStmt->execute([':user_id' => $userId]);
    
    if ($checkStmt->fetch()) {
        // Update existing
        $updateFields = [];
        $params = [':user_id' => $userId];
        
        if ($pushEnabled !== null) {
            $updateFields[] = 'push_enabled = :push';
            $params[':push'] = $pushEnabled ? 1 : 0;
        }
        
        if ($emailEnabled !== null) {
            $updateFields[] = 'email_enabled = :email';
            $params[':email'] = $emailEnabled ? 1 : 0;
        }
        
        if ($smsEnabled !== null) {
            $updateFields[] = 'sms_enabled = :sms';
            $params[':sms'] = $smsEnabled ? 1 : 0;
        }
        
        if ($orderUpdates !== null) {
            $updateFields[] = 'order_updates = :order_updates';
            $params[':order_updates'] = $orderUpdates ? 1 : 0;
        }
        
        if ($promotionalOffers !== null) {
            $updateFields[] = 'promotional_offers = :promotional_offers';
            $params[':promotional_offers'] = $promotionalOffers ? 1 : 0;
        }
        
        if ($newMerchants !== null) {
            $updateFields[] = 'new_merchants = :new_merchants';
            $params[':new_merchants'] = $newMerchants ? 1 : 0;
        }
        
        if ($specialOffers !== null) {
            $updateFields[] = 'special_offers = :special_offers';
            $params[':special_offers'] = $specialOffers ? 1 : 0;
        }
        
        if (!empty($updateFields)) {
            $sql = "UPDATE user_notification_settings SET " . 
                   implode(', ', $updateFields) . 
                   ", updated_at = NOW() WHERE user_id = :user_id";
            
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);
        }
    } else {
        // Create new
        $insertStmt = $conn->prepare(
            "INSERT INTO user_notification_settings 
                (user_id, push_enabled, email_enabled, sms_enabled, 
                 order_updates, promotional_offers, new_merchants, special_offers, 
                 created_at, updated_at)
             VALUES (:user_id, :push, :email, :sms, 
                     :order_updates, :promotional_offers, :new_merchants, :special_offers,
                     NOW(), NOW())"
        );
        
        $insertStmt->execute([
            ':user_id' => $userId,
            ':push' => $pushEnabled !== null ? ($pushEnabled ? 1 : 0) : 1,
            ':email' => $emailEnabled !== null ? ($emailEnabled ? 1 : 0) : 1,
            ':sms' => $smsEnabled !== null ? ($smsEnabled ? 1 : 0) : 0,
            ':order_updates' => $orderUpdates !== null ? ($orderUpdates ? 1 : 0) : 1,
            ':promotional_offers' => $promotionalOffers !== null ? ($promotionalOffers ? 1 : 0) : 1,
            ':new_merchants' => $newMerchants !== null ? ($newMerchants ? 1 : 0) : 1,
            ':special_offers' => $specialOffers !== null ? ($specialOffers ? 1 : 0) : 1
        ]);
    }

    // Get updated preferences
    $stmt = $conn->prepare(
        "SELECT * FROM user_notification_settings WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'preferences' => $preferences
        ],
        'message' => 'Notification preferences updated successfully'
    ]);
}

/*********************************
 * HANDLE TRIGGER EVENT
 *********************************/
function handleTriggerEvent($conn, $userId, $input) {
    $eventName = $input['event_name'] ?? '';
    $eventData = $input['event_data'] ?? [];
    
    if (empty($eventName)) {
        ResponseHandler::error('Event name is required', 400);
    }
    
    // Log the event to user_events table if it exists
    try {
        // Check if user_events table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'user_events'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $stmt = $conn->prepare(
                "INSERT INTO user_events (user_id, event_name, event_data, created_at)
                 VALUES (:user_id, :event_name, :event_data, NOW())"
            );
            
            $stmt->execute([
                ':user_id' => $userId,
                ':event_name' => $eventName,
                ':event_data' => json_encode($eventData)
            ]);
        }
    } catch (Exception $e) {
        // If table doesn't exist, just log to error log
        error_log("Failed to log event to user_events: " . $e->getMessage());
    }
    
    // Create notification based on event type
    $notificationCreated = createNotificationForEvent($conn, $userId, $eventName, $eventData);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'event_logged' => true,
            'notification_created' => $notificationCreated,
            'event_name' => $eventName
        ],
        'message' => 'Event logged successfully'
    ]);
}

/*********************************
 * CREATE NOTIFICATION FOR EVENT
 *********************************/
function createNotificationForEvent($conn, $userId, $eventName, $eventData) {
    $notificationConfig = getNotificationConfigForEvent($eventName, $eventData);
    
    if (!$notificationConfig['should_create']) {
        return false;
    }
    
    try {
        $stmt = $conn->prepare(
            "INSERT INTO notifications 
                (user_id, type, title, message, data, sent_via, sent_at, created_at)
             VALUES (:user_id, :type, :title, :message, :data, :sent_via, NOW(), NOW())"
        );
        
        $result = $stmt->execute([
            ':user_id' => $userId,
            ':type' => $notificationConfig['type'],
            ':title' => $notificationConfig['title'],
            ':message' => $notificationConfig['message'],
            ':data' => json_encode(array_merge($eventData, ['event_name' => $eventName])),
            ':sent_via' => 'in_app'
        ]);
        
        return $result;
    } catch (Exception $e) {
        error_log("Failed to create notification for event: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * GET NOTIFICATION CONFIG FOR EVENT
 *********************************/
function getNotificationConfigForEvent($eventName, $eventData) {
    $defaultConfig = [
        'should_create' => false,
        'type' => 'system',
        'title' => '',
        'message' => ''
    ];
    
    switch ($eventName) {
        case 'user_created':
            return [
                'should_create' => true,
                'type' => 'system',
                'title' => 'Welcome to DropX! 🎉',
                'message' => 'Thank you for joining DropX. Get started by exploring our restaurants.'
            ];
            
        case 'user_logged_in':
            // Don't create notification for every login
            return $defaultConfig;
            
        case 'order_created':
            $orderNumber = $eventData['order_number'] ?? '';
            return [
                'should_create' => true,
                'type' => 'order',
                'title' => 'Order Confirmed! ✅',
                'message' => "Your order #$orderNumber has been placed successfully."
            ];
            
        case 'order_status_changed':
            $newStatus = $eventData['new_status'] ?? '';
            $orderId = $eventData['order_id'] ?? '';
            $statusMessages = [
                'pending' => 'is being processed',
                'confirmed' => 'has been confirmed',
                'preparing' => 'is being prepared',
                'ready' => 'is ready for pickup/delivery',
                'delivered' => 'has been delivered',
                'cancelled' => 'has been cancelled'
            ];
            
            $message = "Your order #$orderId " . ($statusMessages[$newStatus] ?? "status changed to $newStatus");
            
            return [
                'should_create' => true,
                'type' => 'order',
                'title' => 'Order Status Updated',
                'message' => $message
            ];
            
        case 'payment_success':
            $amount = $eventData['amount'] ?? 0;
            $orderId = $eventData['order_id'] ?? '';
            return [
                'should_create' => true,
                'type' => 'payment',
                'title' => 'Payment Successful! 💳',
                'message' => "Payment of ₹$amount for order #$orderId was successful."
            ];
            
        case 'delivery_assigned':
            $driverName = $eventData['driver_name'] ?? 'driver';
            return [
                'should_create' => true,
                'type' => 'delivery',
                'title' => 'Delivery Partner Assigned',
                'message' => "$driverName will be delivering your order."
            ];
            
        case 'order_delivered':
            return [
                'should_create' => true,
                'type' => 'delivery',
                'title' => 'Order Delivered! 🎊',
                'message' => 'Your order has been delivered. Enjoy your meal!'
            ];
            
        case 'promotion_applied':
            $promoCode = $eventData['promo_code'] ?? '';
            $discount = $eventData['discount_amount'] ?? 0;
            return [
                'should_create' => true,
                'type' => 'promotion',
                'title' => 'Promotion Applied! 🎁',
                'message' => "Promo code '$promoCode' applied. You saved ₹$discount!"
            ];
            
        case 'review_added':
            $targetType = $eventData['target_type'] ?? '';
            $rating = $eventData['rating'] ?? 0;
            
            $messages = [
                'merchant' => "Thanks for rating a restaurant {$rating}⭐",
                'order' => "Thanks for rating your order {$rating}⭐",
                'driver' => "Thanks for rating your delivery {$rating}⭐"
            ];
            
            return [
                'should_create' => true,
                'type' => 'update',
                'title' => 'Review Submitted',
                'message' => $messages[$targetType] ?? "Thanks for your {$rating}⭐ review!"
            ];
            
        case 'cart_threshold':
            $cartTotal = $eventData['cart_total'] ?? 0;
            $eventType = $eventData['event'] ?? 'cart_reminder';
            
            if ($eventType === 'free_delivery_eligible') {
                return [
                    'should_create' => true,
                    'type' => 'promotion',
                    'title' => 'Free Delivery! 🚚',
                    'message' => 'Your cart qualifies for free delivery!'
                ];
            } else {
                return [
                    'should_create' => true,
                    'type' => 'promotion',
                    'title' => 'Cart Reminder',
                    'message' => "Your cart total is ₹$cartTotal. Add more items to qualify for free delivery!"
                ];
            }
            
        case 'address_added':
            return [
                'should_create' => true,
                'type' => 'update',
                'title' => 'Address Saved',
                'message' => 'Your new address has been saved successfully.'
            ];
            
        case 'profile_updated':
            return [
                'should_create' => true,
                'type' => 'update',
                'title' => 'Profile Updated',
                'message' => 'Your profile has been updated successfully.'
            ];
            
        case 'merchant_favorite':
            $isFavorite = $eventData['is_favorite'] ?? false;
            return [
                'should_create' => true,
                'type' => 'update',
                'title' => $isFavorite ? 'Added to Favorites' : 'Removed from Favorites',
                'message' => $isFavorite 
                    ? 'Restaurant added to your favorites!' 
                    : 'Restaurant removed from your favorites.'
            ];
            
        case 'quick_order_created':
            $orderNumber = $eventData['order_number'] ?? '';
            return [
                'should_create' => true,
                'type' => 'order',
                'title' => 'Quick Order Confirmed! ⚡',
                'message' => "Your quick order #$orderNumber has been placed successfully."
            ];
            
        default:
            return $defaultConfig;
    }
}

/*********************************
 * FORMAT NOTIFICATION DATA
 *********************************/
function formatNotificationData($notification) {
    $type = $notification['type'] ?? 'order';
    
    // Map types to icons (matching Flutter expectations)
    $iconMap = [
        'order' => 'shopping_bag',
        'delivery' => 'local_shipping',
        'promotion' => 'local_offer',
        'payment' => 'payment',
        'system' => 'info',
        'support' => 'chat',
        'security' => 'security',
        'update' => 'update',
        'warning' => 'warning',
        'success' => 'check_circle'
    ];
    
    // Get appropriate icon based on type
    $icon = $iconMap[$type] ?? 'notifications';
    
    // Parse data JSON
    $data = [];
    if (!empty($notification['data'])) {
        try {
            $data = json_decode($notification['data'], true);
            if (!is_array($data)) {
                $data = [];
            }
        } catch (Exception $e) {
            $data = [];
        }
    }

    // Format time ago
    $createdAt = $notification['created_at'] ?? '';
    $timeAgo = formatTimeAgo($createdAt);
    
    // Format dates for Flutter
    $readAt = $notification['read_at'] ?? null;
    $sentAt = $notification['sent_at'] ?? null;
    $createdAtFormatted = $createdAt ? date('Y-m-d H:i:s', strtotime($createdAt)) : null;

    return [
        'id' => intval($notification['id']),
        'type' => $type,
        'title' => $notification['title'] ?? '',
        'message' => $notification['message'] ?? '',
        'data' => $data,
        'is_read' => boolval($notification['is_read'] ?? false),
        'read_at' => $readAt,
        'sent_via' => $notification['sent_via'] ?? 'in_app',
        'sent_at' => $sentAt,
        'created_at' => $createdAtFormatted,
        'time_ago' => $timeAgo,
        'icon' => $icon
    ];
}

/*********************************
 * FORMAT TIME AGO
 *********************************/
function formatTimeAgo($datetime) {
    if (empty($datetime)) return 'Just now';
    
    try {
        $now = new DateTime();
        $then = new DateTime($datetime);
        $interval = $now->diff($then);
        
        if ($interval->y > 0) {
            return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
        } elseif ($interval->m > 0) {
            return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
        } elseif ($interval->d > 0) {
            if ($interval->d == 1) return 'Yesterday';
            if ($interval->d < 7) return $interval->d . ' days ago';
            if ($interval->d == 7) return '1 week ago';
            if ($interval->d < 30) return floor($interval->d / 7) . ' weeks ago';
        } elseif ($interval->h > 0) {
            return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif ($interval->i > 0) {
            return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    } catch (Exception $e) {
        return 'Recently';
    }
}
?>