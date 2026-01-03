<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ResponseHandler.php';

class Profile {
    private $conn;
    
    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }
    
    // Get user profile with all data
    public function getUserProfile($userId) {
        try {
            // Get user basic info
            $userQuery = "SELECT 
                id, username, email, full_name, phone, address, avatar,
                wallet_balance, member_level, member_points, total_orders,
                rating, verified, join_date, created_at
                FROM users WHERE id = :id";
            
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                ResponseHandler::error('User not found', 404);
            }
            
            // Get counts for different sections
            $countsQuery = "SELECT 
                (SELECT COUNT(*) FROM user_addresses WHERE user_id = :id) as address_count,
                (SELECT COUNT(*) FROM orders WHERE user_id = :id) as order_count,
                (SELECT COUNT(*) FROM user_favorites WHERE user_id = :id) as favorite_count,
                (SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :id) as transaction_count";
            
            $countsStmt = $this->conn->prepare($countsQuery);
            $countsStmt->execute([':id' => $userId]);
            $counts = $countsStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent orders
            $ordersQuery = "SELECT 
                o.id, o.order_number, o.total_amount, o.status, 
                o.created_at, r.name as restaurant_name,
                oi.item_count
                FROM orders o
                LEFT JOIN restaurants r ON o.restaurant_id = r.id
                LEFT JOIN (
                    SELECT order_id, COUNT(*) as item_count 
                    FROM order_items 
                    GROUP BY order_id
                ) oi ON o.id = oi.order_id
                WHERE o.user_id = :id
                ORDER BY o.created_at DESC
                LIMIT 5";
            
            $ordersStmt = $this->conn->prepare($ordersQuery);
            $ordersStmt->execute([':id' => $userId]);
            $recentOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the response
            $response = [
                'user' => $this->formatUserData($user),
                'counts' => $counts,
                'recent_orders' => $recentOrders,
                'stats' => [
                    'total_spent' => $this->getTotalSpent($userId),
                    'avg_order_value' => $this->getAverageOrderValue($userId),
                    'favorite_cuisine' => $this->getFavoriteCuisine($userId)
                ]
            ];
            
            ResponseHandler::success($response, 'Profile data retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    // Update user profile
    public function updateUserProfile($userId, $data) {
        try {
            // Validate required fields
            if (!isset($data['full_name']) || empty(trim($data['full_name']))) {
                ResponseHandler::error('Full name is required', 400);
            }
            
            if (!isset($data['email']) || empty(trim($data['email']))) {
                ResponseHandler::error('Email is required', 400);
            }
            
            // Check if email is already taken by another user
            $emailCheckQuery = "SELECT id FROM users WHERE email = :email AND id != :id";
            $emailCheckStmt = $this->conn->prepare($emailCheckQuery);
            $emailCheckStmt->execute([
                ':email' => trim($data['email']),
                ':id' => $userId
            ]);
            
            if ($emailCheckStmt->rowCount() > 0) {
                ResponseHandler::error('Email already taken by another user', 409);
            }
            
            // Prepare update data
            $updateData = [
                ':id' => $userId,
                ':full_name' => trim($data['full_name']),
                ':email' => trim($data['email']),
                ':phone' => isset($data['phone']) ? trim($data['phone']) : '',
                ':address' => isset($data['address']) ? trim($data['address']) : '',
                ':updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Handle avatar if provided
            if (isset($data['avatar']) && !empty($data['avatar'])) {
                // In production, you would save the file and store the path
                $updateData[':avatar'] = $this->handleAvatarUpload($data['avatar'], $userId);
                $avatarField = ', avatar = :avatar';
            } else {
                $avatarField = '';
            }
            
            // Update query
            $query = "UPDATE users SET 
                full_name = :full_name,
                email = :email,
                phone = :phone,
                address = :address
                {$avatarField},
                updated_at = :updated_at
                WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt->execute($updateData)) {
                ResponseHandler::error('Failed to update profile', 500);
            }
            
            // Get updated user data
            $updatedUser = $this->getUserById($userId);
            
            ResponseHandler::success(
                ['user' => $this->formatUserData($updatedUser)],
                'Profile updated successfully'
            );
            
        } catch (PDOException $e) {
            ResponseHandler::error('Update failed: ' . $e->getMessage(), 500);
        }
    }
    
    // Get user orders
    public function getUserOrders($userId, $page = 1, $limit = 10, $status = '') {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "WHERE o.user_id = :user_id";
            $params = [':user_id' => $userId];
            
            if ($status && $status !== 'all') {
                $whereClause .= " AND o.status = :status";
                $params[':status'] = $status;
            }
            
            // Get orders with pagination
            $query = "SELECT 
                o.id, o.order_number, o.total_amount, o.status, 
                o.delivery_address, o.delivery_instructions,
                o.payment_method, o.delivery_fee, o.tax_amount,
                o.created_at, o.delivered_at,
                r.name as restaurant_name, r.image as restaurant_image,
                COUNT(oi.id) as item_count,
                GROUP_CONCAT(DISTINCT oi.item_name) as item_names
                FROM orders o
                LEFT JOIN restaurants r ON o.restaurant_id = r.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                {$whereClause}
                GROUP BY o.id
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";
            
            $stmt = $this->conn->prepare($query);
            $params[':limit'] = (int)$limit;
            $params[':offset'] = (int)$offset;
            
            // Bind parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countQuery = "SELECT COUNT(*) as total FROM orders o {$whereClause}";
            $countStmt = $this->conn->prepare($countQuery);
            unset($params[':limit'], $params[':offset']);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Format orders
            foreach ($orders as &$order) {
                $order['items'] = $this->getOrderItems($order['id']);
                $order['formatted_date'] = date('F j, Y, g:i a', strtotime($order['created_at']));
                $order['delivery_time'] = $order['delivered_at'] 
                    ? date('g:i a', strtotime($order['delivered_at']))
                    : null;
            }
            
            ResponseHandler::success([
                'orders' => $orders,
                'pagination' => [
                    'page' => (int)$page,
                    'limit' => (int)$limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ],
                'stats' => $this->getOrderStats($userId)
            ], 'Orders retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get orders: ' . $e->getMessage(), 500);
        }
    }
    
    // Address Management
    public function getUserAddresses($userId) {
        try {
            $query = "SELECT 
                id, title, address, city, state, zip_code,
                latitude, longitude, is_default, instructions,
                address_type, created_at
                FROM user_addresses 
                WHERE user_id = :user_id
                ORDER BY is_default DESC, created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['addresses' => $addresses], 'Addresses retrieved');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get addresses: ' . $e->getMessage(), 500);
        }
    }
    
    public function addAddress($userId, $data) {
        try {
            // Validate required fields
            $required = ['title', 'address', 'city'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty(trim($data[$field]))) {
                    ResponseHandler::error("{$field} is required", 400);
                }
            }
            
            // If this is set as default, unset other defaults
            if (isset($data['is_default']) && $data['is_default']) {
                $this->clearDefaultAddresses($userId);
            }
            
            // Insert new address
            $query = "INSERT INTO user_addresses (
                user_id, title, address, city, state, zip_code,
                latitude, longitude, is_default, instructions,
                address_type, created_at
            ) VALUES (
                :user_id, :title, :address, :city, :state, :zip_code,
                :latitude, :longitude, :is_default, :instructions,
                :address_type, NOW()
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':user_id' => $userId,
                ':title' => trim($data['title']),
                ':address' => trim($data['address']),
                ':city' => trim($data['city']),
                ':state' => isset($data['state']) ? trim($data['state']) : '',
                ':zip_code' => isset($data['zip_code']) ? trim($data['zip_code']) : '',
                ':latitude' => isset($data['latitude']) ? (float)$data['latitude'] : null,
                ':longitude' => isset($data['longitude']) ? (float)$data['longitude'] : null,
                ':is_default' => isset($data['is_default']) ? (int)$data['is_default'] : 0,
                ':instructions' => isset($data['instructions']) ? trim($data['instructions']) : '',
                ':address_type' => isset($data['address_type']) ? trim($data['address_type']) : 'home'
            ]);
            
            $addressId = $this->conn->lastInsertId();
            
            ResponseHandler::success(
                ['address_id' => $addressId],
                'Address added successfully',
                201
            );
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to add address: ' . $e->getMessage(), 500);
        }
    }
    
    public function updateAddress($userId, $data) {
        try {
            if (!isset($data['address_id'])) {
                ResponseHandler::error('Address ID is required', 400);
            }
            
            // Verify ownership
            if (!$this->verifyAddressOwnership($userId, $data['address_id'])) {
                ResponseHandler::error('Address not found or access denied', 404);
            }
            
            // If setting as default, clear other defaults
            if (isset($data['is_default']) && $data['is_default']) {
                $this->clearDefaultAddresses($userId);
            }
            
            // Build update query
            $updates = [];
            $params = [':id' => $data['address_id']];
            
            $fields = [
                'title', 'address', 'city', 'state', 'zip_code',
                'latitude', 'longitude', 'is_default', 'instructions', 'address_type'
            ];
            
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                ResponseHandler::error('No fields to update', 400);
            }
            
            $query = "UPDATE user_addresses SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            ResponseHandler::success([], 'Address updated successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
        }
    }
    
    public function deleteAddress($userId, $data) {
        try {
            if (!isset($data['address_id'])) {
                ResponseHandler::error('Address ID is required', 400);
            }
            
            // Verify ownership
            if (!$this->verifyAddressOwnership($userId, $data['address_id'])) {
                ResponseHandler::error('Address not found or access denied', 404);
            }
            
            // Check if it's a default address
            $checkQuery = "SELECT is_default FROM user_addresses WHERE id = :id AND user_id = :user_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':id' => $data['address_id'], ':user_id' => $userId]);
            $address = $checkStmt->fetch();
            
            if ($address['is_default']) {
                ResponseHandler::error('Cannot delete default address. Set another as default first.', 400);
            }
            
            $query = "DELETE FROM user_addresses WHERE id = :id AND user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $data['address_id'], ':user_id' => $userId]);
            
            ResponseHandler::success([], 'Address deleted successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to delete address: ' . $e->getMessage(), 500);
        }
    }
    
    public function setDefaultAddress($userId, $data) {
        try {
            if (!isset($data['address_id'])) {
                ResponseHandler::error('Address ID is required', 400);
            }
            
            // Verify ownership
            if (!$this->verifyAddressOwnership($userId, $data['address_id'])) {
                ResponseHandler::error('Address not found or access denied', 404);
            }
            
            // Start transaction
            $this->conn->beginTransaction();
            
            // Clear all defaults
            $clearQuery = "UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id";
            $clearStmt = $this->conn->prepare($clearQuery);
            $clearStmt->execute([':user_id' => $userId]);
            
            // Set new default
            $setQuery = "UPDATE user_addresses SET is_default = 1 WHERE id = :id AND user_id = :user_id";
            $setStmt = $this->conn->prepare($setQuery);
            $setStmt->execute([':id' => $data['address_id'], ':user_id' => $userId]);
            
            $this->conn->commit();
            
            ResponseHandler::success([], 'Default address updated successfully');
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Failed to set default address: ' . $e->getMessage(), 500);
        }
    }
    
    // Wallet Management
    public function topupWallet($userId, $data) {
        try {
            if (!isset($data['amount']) || $data['amount'] <= 0) {
                ResponseHandler::error('Valid amount is required', 400);
            }
            
            if (!isset($data['payment_method'])) {
                ResponseHandler::error('Payment method is required', 400);
            }
            
            $amount = (float)$data['amount'];
            
            // Start transaction
            $this->conn->beginTransaction();
            
            // Update wallet balance
            $updateQuery = "UPDATE users SET wallet_balance = wallet_balance + :amount WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([':amount' => $amount, ':id' => $userId]);
            
            // Record transaction
            $transactionQuery = "INSERT INTO wallet_transactions (
                user_id, type, amount, payment_method, transaction_id,
                status, description, created_at
            ) VALUES (
                :user_id, 'credit', :amount, :payment_method, :transaction_id,
                'completed', 'Wallet top-up', NOW()
            )";
            
            $transactionStmt = $this->conn->prepare($transactionQuery);
            $transactionStmt->execute([
                ':user_id' => $userId,
                ':amount' => $amount,
                ':payment_method' => $data['payment_method'],
                ':transaction_id' => isset($data['transaction_id']) ? $data['transaction_id'] : uniqid('TXN_')
            ]);
            
            // Get updated balance
            $balanceQuery = "SELECT wallet_balance FROM users WHERE id = :id";
            $balanceStmt = $this->conn->prepare($balanceQuery);
            $balanceStmt->execute([':id' => $userId]);
            $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            
            $this->conn->commit();
            
            ResponseHandler::success([
                'new_balance' => $balance['wallet_balance'],
                'transaction_id' => $transactionStmt->lastInsertId()
            ], 'Wallet topped up successfully');
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Top-up failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function getWalletTransactions($userId, $page = 1, $limit = 10) {
        try {
            $offset = ($page - 1) * $limit;
            
            $query = "SELECT 
                id, type, amount, payment_method, transaction_id,
                status, description, created_at
                FROM wallet_transactions
                WHERE user_id = :user_id
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM wallet_transactions WHERE user_id = :user_id";
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->execute([':user_id' => $userId]);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Format dates
            foreach ($transactions as &$transaction) {
                $transaction['formatted_date'] = date('M j, Y g:i A', strtotime($transaction['created_at']));
                $transaction['formatted_amount'] = 'MK ' . number_format($transaction['amount'], 2);
            }
            
            ResponseHandler::success([
                'transactions' => $transactions,
                'pagination' => [
                    'page' => (int)$page,
                    'limit' => (int)$limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ], 'Transactions retrieved');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get transactions: ' . $e->getMessage(), 500);
        }
    }
    
    // Favorites Management
    public function getFavorites($userId) {
        try {
            $query = "SELECT 
                f.id, f.restaurant_id, f.created_at,
                r.name, r.image, r.cuisine_type, r.rating,
                r.delivery_time, r.min_order_amount
                FROM user_favorites f
                JOIN restaurants r ON f.restaurant_id = r.id
                WHERE f.user_id = :user_id
                ORDER BY f.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['favorites' => $favorites], 'Favorites retrieved');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get favorites: ' . $e->getMessage(), 500);
        }
    }
    
    public function addFavorite($userId, $data) {
        try {
            if (!isset($data['restaurant_id'])) {
                ResponseHandler::error('Restaurant ID is required', 400);
            }
            
            // Check if already favorited
            $checkQuery = "SELECT id FROM user_favorites WHERE user_id = :user_id AND restaurant_id = :restaurant_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([
                ':user_id' => $userId,
                ':restaurant_id' => $data['restaurant_id']
            ]);
            
            if ($checkStmt->rowCount() > 0) {
                ResponseHandler::error('Already in favorites', 409);
            }
            
            $query = "INSERT INTO user_favorites (user_id, restaurant_id, created_at) 
                     VALUES (:user_id, :restaurant_id, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':user_id' => $userId,
                ':restaurant_id' => $data['restaurant_id']
            ]);
            
            ResponseHandler::success(
                ['favorite_id' => $this->conn->lastInsertId()],
                'Added to favorites',
                201
            );
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to add favorite: ' . $e->getMessage(), 500);
        }
    }
    
    public function removeFavorite($userId, $data) {
        try {
            if (!isset($data['favorite_id'])) {
                ResponseHandler::error('Favorite ID is required', 400);
            }
            
            $query = "DELETE FROM user_favorites WHERE id = :id AND user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':id' => $data['favorite_id'],
                ':user_id' => $userId
            ]);
            
            if ($stmt->rowCount() === 0) {
                ResponseHandler::error('Favorite not found', 404);
            }
            
            ResponseHandler::success([], 'Removed from favorites');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to remove favorite: ' . $e->getMessage(), 500);
        }
    }
    
    // Notification Settings
    public function getNotificationSettings($userId) {
        try {
            $query = "SELECT 
                email_notifications, push_notifications, sms_notifications,
                promotional_emails, order_updates, delivery_alerts,
                price_alerts, new_restaurant_alerts
                FROM user_notifications
                WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$settings) {
                // Create default settings
                $settings = $this->createDefaultNotificationSettings($userId);
            }
            
            ResponseHandler::success(['settings' => $settings], 'Notification settings retrieved');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get notification settings: ' . $e->getMessage(), 500);
        }
    }
    
    public function updateNotificationSettings($userId, $data) {
        try {
            // Check if settings exist
            $checkQuery = "SELECT id FROM user_notifications WHERE user_id = :user_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':user_id' => $userId]);
            
            if ($checkStmt->rowCount() === 0) {
                // Create new settings
                $this->createDefaultNotificationSettings($userId);
            }
            
            // Build update query
            $updates = [];
            $params = [':user_id' => $userId];
            
            $fields = [
                'email_notifications', 'push_notifications', 'sms_notifications',
                'promotional_emails', 'order_updates', 'delivery_alerts',
                'price_alerts', 'new_restaurant_alerts'
            ];
            
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "{$field} = :{$field}";
                    $params[":{$field}"] = (int)$data[$field];
                }
            }
            
            if (empty($updates)) {
                ResponseHandler::error('No settings to update', 400);
            }
            
            $query = "UPDATE user_notifications SET " . implode(', ', $updates) . " 
                     WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            ResponseHandler::success([], 'Notification settings updated');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to update notification settings: ' . $e->getMessage(), 500);
        }
    }
    
    // Helper Methods
    private function getUserById($userId) {
        $query = "SELECT * FROM users WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function formatUserData($user) {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'] ?: $user['username'],
            'phone' => $user['phone'] ?: '+265 881 234 567',
            'address' => $user['address'] ?: '123 Nyambadwe, Blantyre, Malawi',
            'avatar' => $user['avatar'] ?: null,
            'wallet_balance' => (float) ($user['wallet_balance'] ?: 0),
            'member_level' => $user['member_level'] ?: 'Silver',
            'member_points' => (int) ($user['member_points'] ?: 100),
            'total_orders' => (int) ($user['total_orders'] ?: 0),
            'rating' => (float) ($user['rating'] ?: 5.0),
            'verified' => (bool) ($user['verified'] ?: false),
            'join_date' => $user['join_date'] ?: date('F j, Y'),
            'created_at' => $user['created_at']
        ];
    }
    
    private function handleAvatarUpload($avatarData, $userId) {
        // This is a simplified version. In production, you would:
        // 1. Validate the image
        // 2. Resize it
        // 3. Save to storage (e.g., S3 or local storage)
        // 4. Return the file path or URL
        
        // For now, we'll just store base64 or URL as is
        if (strpos($avatarData, 'data:image') === 0) {
            // It's a base64 image
            // Extract and save the image
            $imageData = explode(',', $avatarData)[1];
            $imageData = base64_decode($imageData);
            
            // Create directory if it doesn't exist
            $uploadDir = __DIR__ . '/../uploads/avatars/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Generate filename
            $filename = $userId . '_' . time() . '.jpg';
            $filepath = $uploadDir . $filename;
            
            // Save file
            file_put_contents($filepath, $imageData);
            
            // Return the URL path
            return '/uploads/avatars/' . $filename;
        } else {
            // Assume it's already a URL
            return $avatarData;
        }
    }
    
    private function getOrderItems($orderId) {
        $query = "SELECT 
            item_name, quantity, price, total_price,
            special_instructions
            FROM order_items
            WHERE order_id = :order_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getOrderStats($userId) {
        $query = "SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_spent,
            AVG(total_amount) as avg_order_value,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
            FROM orders
            WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function verifyAddressOwnership($userId, $addressId) {
        $query = "SELECT id FROM user_addresses WHERE id = :id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $addressId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }
    
    private function clearDefaultAddresses($userId) {
        $query = "UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $userId]);
    }
    
    private function createDefaultNotificationSettings($userId) {
        $query = "INSERT INTO user_notifications (
            user_id, email_notifications, push_notifications, sms_notifications,
            promotional_emails, order_updates, delivery_alerts,
            price_alerts, new_restaurant_alerts
        ) VALUES (
            :user_id, 1, 1, 0, 1, 1, 1, 1, 1
        )";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        
        return [
            'email_notifications' => 1,
            'push_notifications' => 1,
            'sms_notifications' => 0,
            'promotional_emails' => 1,
            'order_updates' => 1,
            'delivery_alerts' => 1,
            'price_alerts' => 1,
            'new_restaurant_alerts' => 1
        ];
    }
    
    private function getTotalSpent($userId) {
        $query = "SELECT COALESCE(SUM(total_amount), 0) as total_spent FROM orders WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total_spent'];
    }
    
    private function getAverageOrderValue($userId) {
        $query = "SELECT COALESCE(AVG(total_amount), 0) as avg_value FROM orders WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['avg_value'];
    }
    
    private function getFavoriteCuisine($userId) {
        $query = "SELECT 
            r.cuisine_type, COUNT(*) as order_count
            FROM orders o
            JOIN restaurants r ON o.restaurant_id = r.id
            WHERE o.user_id = :user_id
            GROUP BY r.cuisine_type
            ORDER BY order_count DESC
            LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['cuisine_type'] : 'None';
    }
}
?>