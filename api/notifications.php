<?php
// notifications.php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/database.php';

class NotificationAPI {
    private $conn;
    private $user_id;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->user_id = $_SESSION['user_id'];
            
            // Ensure tables exist
            $this->createNotificationTables();
        } catch (Exception $e) {
            $this->sendError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    private function createNotificationTables() {
        // Check if notifications table exists
        $checkQuery = "SHOW TABLES LIKE 'notifications'";
        $stmt = $this->conn->query($checkQuery);
        
        if ($stmt->rowCount() == 0) {
            // Create notifications table
            $createNotifications = "
                CREATE TABLE notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type ENUM('order', 'promotion', 'system', 'delivery', 'restaurant', 'wallet', 'payment') NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    data JSON DEFAULT NULL,
                    is_read BOOLEAN DEFAULT FALSE,
                    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
                    expires_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    
                    INDEX idx_user (user_id),
                    INDEX idx_user_read (user_id, is_read),
                    INDEX idx_user_created (user_id, created_at),
                    INDEX idx_type (type)
                )
            ";
            $this->conn->exec($createNotifications);
        }
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $input = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input'), true);
        
        $action = $input['action'] ?? '';
        
        if (empty($action)) {
            $this->sendError('No action specified', 400);
        }

        try {
            switch ($action) {
                case 'get_notifications':
                    $this->getNotifications($input);
                    break;
                case 'get_unread_count':
                    $this->getUnreadCount();
                    break;
                case 'mark_as_read':
                    $this->markAsRead($input);
                    break;
                case 'mark_all_as_read':
                    $this->markAllAsRead();
                    break;
                case 'clear_all':
                    $this->clearAllNotifications();
                    break;
                case 'get_stats':
                    $this->getNotificationStats();
                    break;
                case 'create_notification':
                    $this->createNotification($input);
                    break;
                case 'get_preferences':
                    $this->getUserPreferences();
                    break;
                case 'update_preferences':
                    $this->updateUserPreferences($input);
                    break;
                default:
                    $this->sendError('Invalid action', 400);
            }
        } catch (Exception $e) {
            $this->sendError('Request failed: ' . $e->getMessage(), 500);
        }
    }

    private function getNotifications($data) {
        try {
            $page = max(1, (int)($data['page'] ?? 1));
            $limit = min(50, max(1, (int)($data['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $type = $data['type'] ?? null;
            $read = $data['read'] ?? null;
            $priority = $data['priority'] ?? null;
            
            // Build query
            $query = "SELECT * FROM notifications WHERE user_id = ?";
            $params = [$this->user_id];
            
            if ($type) {
                $query .= " AND type = ?";
                $params[] = $type;
            }
            
            if ($read !== null) {
                $query .= " AND is_read = ?";
                $params[] = $read ? 1 : 0;
            }
            
            if ($priority) {
                $query .= " AND priority = ?";
                $params[] = $priority;
            }
            
            // Filter expired notifications
            $query .= " AND (expires_at IS NULL OR expires_at > NOW())";
            
            // Get total count
            $countQuery = str_replace('SELECT *', 'SELECT COUNT(*) as total', $query);
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get notifications with pagination
            $query .= " ORDER BY 
                CASE 
                    WHEN is_read = 0 THEN 0 
                    ELSE 1 
                END,
                CASE priority
                    WHEN 'urgent' THEN 0
                    WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2
                    WHEN 'low' THEN 3
                END,
                created_at DESC 
                LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format notifications
            $formattedNotifications = array_map(function($notification) {
                $data = $notification['data'] ? json_decode($notification['data'], true) : null;
                
                return [
                    'id' => (int)$notification['id'],
                    'type' => $notification['type'],
                    'title' => $notification['title'],
                    'message' => $notification['message'],
                    'read' => (bool)$notification['is_read'],
                    'priority' => $notification['priority'],
                    'data' => $data,
                    'created_at' => $notification['created_at'],
                    'time' => $this->formatTimeAgo($notification['created_at']),
                    'expires_at' => $notification['expires_at']
                ];
            }, $notifications);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'notifications' => $formattedNotifications,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => (int)$total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get notifications: ' . $e->getMessage());
        }
    }

    private function getUnreadCount() {
        try {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as unread_count 
                FROM notifications 
                WHERE user_id = ? 
                  AND is_read = 0
                  AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'unread_count' => (int)$result['unread_count']
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get unread count: ' . $e->getMessage());
        }
    }

    private function markAsRead($data) {
        try {
            $notificationId = $data['notification_id'] ?? null;
            
            if (!$notificationId) {
                $this->sendError('Notification ID is required', 400);
            }
            
            $stmt = $this->conn->prepare("
                UPDATE notifications 
                SET is_read = 1, 
                    updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $this->user_id]);
            
            $affectedRows = $stmt->rowCount();
            
            if ($affectedRows === 0) {
                $this->sendError('Notification not found or unauthorized', 404);
            }
            
            // Get updated unread count
            $unreadStmt = $this->conn->prepare("
                SELECT COUNT(*) as unread_count 
                FROM notifications 
                WHERE user_id = ? AND is_read = 0
            ");
            $unreadStmt->execute([$this->user_id]);
            $unreadCount = $unreadStmt->fetch(PDO::FETCH_COLUMN);
            
            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => [
                    'notification_id' => (int)$notificationId,
                    'unread_count' => (int)$unreadCount
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to mark as read: ' . $e->getMessage());
        }
    }

    private function markAllAsRead() {
        try {
            $stmt = $this->conn->prepare("
                UPDATE notifications 
                SET is_read = 1, 
                    updated_at = NOW()
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$this->user_id]);
            
            $affectedRows = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read',
                'data' => [
                    'marked_count' => (int)$affectedRows,
                    'unread_count' => 0
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to mark all as read: ' . $e->getMessage());
        }
    }

    private function clearAllNotifications() {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM notifications 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->user_id]);
            
            $deletedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'message' => 'All notifications cleared',
                'data' => [
                    'deleted_count' => (int)$deletedCount
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to clear notifications: ' . $e->getMessage());
        }
    }

    private function getNotificationStats() {
        try {
            // Get totals by type
            $typeStmt = $this->conn->prepare("
                SELECT 
                    type,
                    COUNT(*) as total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
                FROM notifications 
                WHERE user_id = ?
                  AND (expires_at IS NULL OR expires_at > NOW())
                GROUP BY type
            ");
            $typeStmt->execute([$this->user_id]);
            $typeStats = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format byType object
            $byType = [];
            foreach ($typeStats as $stat) {
                $byType[$stat['type']] = [
                    'total' => (int)$stat['total'],
                    'unread' => (int)$stat['unread']
                ];
            }
            
            // Get total and unread counts
            $totalStmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
                FROM notifications 
                WHERE user_id = ?
                  AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $totalStmt->execute([$this->user_id]);
            $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'total' => (int)$totals['total'],
                    'unread' => (int)$totals['unread'],
                    'byType' => $byType
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get notification stats: ' . $e->getMessage());
        }
    }

    private function createNotification($data) {
        try {
            $type = $data['type'] ?? '';
            $title = $data['title'] ?? '';
            $message = $data['message'] ?? '';
            $notificationData = $data['data'] ?? null;
            $priority = $data['priority'] ?? 'medium';
            $expiryHours = $data['expiry_hours'] ?? 168; // 7 days default
            
            // Validate
            if (empty($type) || empty($message)) {
                $this->sendError('Type and message are required', 400);
            }
            
            // Validate type
            $validTypes = ['order', 'promotion', 'system', 'delivery', 'restaurant', 'wallet', 'payment'];
            if (!in_array($type, $validTypes)) {
                $this->sendError('Invalid notification type', 400);
            }
            
            // Validate priority
            $validPriorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($priority, $validPriorities)) {
                $this->sendError('Invalid priority', 400);
            }
            
            // Set expiry time
            $expiresAt = null;
            if ($expiryHours > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));
            }
            
            // Insert notification
            $stmt = $this->conn->prepare("
                INSERT INTO notifications 
                (user_id, type, title, message, data, priority, expires_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $jsonData = $notificationData ? json_encode($notificationData) : null;
            $stmt->execute([
                $this->user_id,
                $type,
                $title,
                $message,
                $jsonData,
                $priority,
                $expiresAt
            ]);
            
            $notificationId = $this->conn->lastInsertId();
            
            // Get the created notification
            $stmt = $this->conn->prepare("
                SELECT * FROM notifications WHERE id = ?
            ");
            $stmt->execute([$notificationId]);
            $notification = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Notification created',
                'data' => [
                    'notification' => [
                        'id' => (int)$notification['id'],
                        'type' => $notification['type'],
                        'title' => $notification['title'],
                        'message' => $notification['message'],
                        'read' => (bool)$notification['is_read'],
                        'created_at' => $notification['created_at']
                    ]
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to create notification: ' . $e->getMessage());
        }
    }

    private function getUserPreferences() {
        try {
            // Check if preferences table exists
            $checkQuery = "SHOW TABLES LIKE 'user_notification_preferences'";
            $stmt = $this->conn->query($checkQuery);
            
            if ($stmt->rowCount() == 0) {
                // Return default preferences
                $defaultPreferences = $this->getDefaultPreferences();
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'preferences' => $defaultPreferences
                    ]
                ]);
                return;
            }
            
            // Get user preferences
            $stmt = $this->conn->prepare("
                SELECT * FROM user_notification_preferences 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->user_id]);
            $preferences = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no preferences exist, create defaults
            if (empty($preferences)) {
                $defaultPreferences = $this->getDefaultPreferences();
                $this->createDefaultPreferences();
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'preferences' => $defaultPreferences
                    ]
                ]);
                return;
            }
            
            // Format preferences
            $formattedPreferences = [];
            foreach ($preferences as $pref) {
                $formattedPreferences[$pref['notification_type']] = [
                    'email_enabled' => (bool)$pref['email_enabled'],
                    'push_enabled' => (bool)$pref['push_enabled'],
                    'in_app_enabled' => (bool)$pref['in_app_enabled'],
                    'sms_enabled' => (bool)$pref['sms_enabled']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'preferences' => $formattedPreferences
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get preferences: ' . $e->getMessage());
        }
    }

    private function updateUserPreferences($data) {
        try {
            $preferences = $data['preferences'] ?? [];
            
            if (empty($preferences)) {
                $this->sendError('Preferences data is required', 400);
            }
            
            $this->conn->beginTransaction();
            
            try {
                foreach ($preferences as $type => $settings) {
                    // Check if preference exists
                    $checkStmt = $this->conn->prepare("
                        SELECT id FROM user_notification_preferences 
                        WHERE user_id = ? AND notification_type = ?
                    ");
                    $checkStmt->execute([$this->user_id, $type]);
                    
                    if ($checkStmt->rowCount() > 0) {
                        // Update existing
                        $updateStmt = $this->conn->prepare("
                            UPDATE user_notification_preferences 
                            SET email_enabled = ?, 
                                push_enabled = ?, 
                                in_app_enabled = ?, 
                                sms_enabled = ?,
                                updated_at = NOW()
                            WHERE user_id = ? AND notification_type = ?
                        ");
                        $updateStmt->execute([
                            $settings['email_enabled'] ? 1 : 0,
                            $settings['push_enabled'] ? 1 : 0,
                            $settings['in_app_enabled'] ? 1 : 0,
                            $settings['sms_enabled'] ? 1 : 0,
                            $this->user_id,
                            $type
                        ]);
                    } else {
                        // Insert new
                        $insertStmt = $this->conn->prepare("
                            INSERT INTO user_notification_preferences 
                            (user_id, notification_type, 
                             email_enabled, push_enabled, in_app_enabled, sms_enabled,
                             created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $insertStmt->execute([
                            $this->user_id,
                            $type,
                            $settings['email_enabled'] ? 1 : 0,
                            $settings['push_enabled'] ? 1 : 0,
                            $settings['in_app_enabled'] ? 1 : 0,
                            $settings['sms_enabled'] ? 1 : 0
                        ]);
                    }
                }
                
                $this->conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Preferences updated successfully'
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to update preferences: ' . $e->getMessage());
        }
    }

    private function getDefaultPreferences() {
        return [
            'order_created' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => false],
            'order_confirmed' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => false],
            'order_on_delivery' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => true],
            'order_delivered' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => false],
            'order_cancelled' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => true],
            'wallet_topup' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => false],
            'wallet_low' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => true],
            'payment_success' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => false],
            'payment_failed' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => true],
            'promotion' => ['email_enabled' => true, 'push_enabled' => false, 'in_app_enabled' => true, 'sms_enabled' => false],
            'restaurant' => ['email_enabled' => false, 'push_enabled' => false, 'in_app_enabled' => true, 'sms_enabled' => false],
            'system' => ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true, 'sms_enabled' => false]
        ];
    }

    private function createDefaultPreferences() {
        try {
            $defaults = $this->getDefaultPreferences();
            
            foreach ($defaults as $type => $settings) {
                $stmt = $this->conn->prepare("
                    INSERT INTO user_notification_preferences 
                    (user_id, notification_type, 
                     email_enabled, push_enabled, in_app_enabled, sms_enabled,
                     created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $this->user_id,
                    $type,
                    $settings['email_enabled'] ? 1 : 0,
                    $settings['push_enabled'] ? 1 : 0,
                    $settings['in_app_enabled'] ? 1 : 0,
                    $settings['sms_enabled'] ? 1 : 0
                ]);
            }
        } catch (Exception $e) {
            // Silent fail - preferences will be created on demand
        }
    }

    private function formatTimeAgo($timestamp) {
        $time = strtotime($timestamp);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }

    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code
        ]);
        exit;
    }

    // ========== PUBLIC STATIC METHODS FOR OTHER APIS TO USE ==========
    
    /**
     * Create notification for any user (to be called from other APIs)
     */
    public static function createNotificationForUser($userId, $type, $title, $message, $data = null, $priority = 'medium') {
        try {
            $database = new Database();
            $conn = $database->getConnection();
            
            $stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, type, title, message, data, priority, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $jsonData = $data ? json_encode($data) : null;
            $stmt->execute([$userId, $type, $title, $message, $jsonData, $priority]);
            
            return $conn->lastInsertId();
        } catch (Exception $e) {
            // Log error but don't break main flow
            error_log('Failed to create notification: ' . $e->getMessage());
            return false;
        }
    }
}

// Handle the request
try {
    $api = new NotificationAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'code' => 500
    ]);
}

ob_end_flush();
?>