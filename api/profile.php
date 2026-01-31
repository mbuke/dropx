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
    // Check authentication first
    if (!checkAuthentication()) {
        return;
    }
    
    // Handle different GET actions
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'dashboard':
            getDashboardData();
            break;
        case 'payment_methods':
            getPaymentMethods();
            break;
        case 'my_reviews':
            getMyReviews();
            break;
        case 'wishlist':
            getWishlist();
            break;
        case 'support_tickets':
            getSupportTickets();
            break;
        default:
            getUserProfile();
    }
}

/*********************************
 * CHECK AUTHENTICATION
 *********************************/
function checkAuthentication() {
    // Check if user is logged in
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Authentication required. Please login.', 401);
        return false;
    }
    return true;
}

/*********************************
 * GET USER PROFILE
 *********************************/
function getUserProfile() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $userId = $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT 
            id,
            full_name,
            email,
            phone,
            address,
            city,
            gender,
            avatar,
            wallet_balance,
            member_level,
            member_points,
            total_orders,
            rating,
            verified,
            member_since,
            created_at,
            updated_at
         FROM users 
         WHERE id = :id"
    );
    
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('User not found', 404);
        return;
    }

    // Get user statistics
    $statsStmt = $conn->prepare(
        "SELECT 
            (SELECT COUNT(*) FROM orders WHERE user_id = :user_id) as total_orders,
            (SELECT COUNT(*) FROM orders WHERE user_id = :user_id AND status = 'completed') as completed_orders,
            (SELECT COUNT(*) FROM orders WHERE user_id = :user_id AND status = 'pending') as pending_orders,
            (SELECT COUNT(*) FROM orders WHERE user_id = :user_id AND status = 'cancelled') as cancelled_orders,
            (SELECT COUNT(*) FROM user_favorite_merchants WHERE user_id = :user_id) as favorite_merchants,
            (SELECT COUNT(*) FROM user_reviews WHERE user_id = :user_id) as total_reviews,
            (SELECT COUNT(*) FROM user_addresses WHERE user_id = :user_id) as saved_addresses,
            (SELECT SUM(total_amount) FROM orders WHERE user_id = :user_id AND status = 'completed') as total_spent"
    );
    
    $statsStmt->execute([':user_id' => $userId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user' => formatUserData($user),
        'statistics' => [
            'total_orders' => intval($stats['total_orders'] ?? 0),
            'completed_orders' => intval($stats['completed_orders'] ?? 0),
            'pending_orders' => intval($stats['pending_orders'] ?? 0),
            'cancelled_orders' => intval($stats['cancelled_orders'] ?? 0),
            'favorite_merchants' => intval($stats['favorite_merchants'] ?? 0),
            'total_reviews' => intval($stats['total_reviews'] ?? 0),
            'saved_addresses' => intval($stats['saved_addresses'] ?? 0),
            'total_spent' => floatval($stats['total_spent'] ?? 0)
        ]
    ]);
}

/*********************************
 * GET DASHBOARD DATA
 *********************************/
function getDashboardData() {
    if (!checkAuthentication()) {
        return;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    $userId = $_SESSION['user_id'];

    try {
        // Get recent orders
        $ordersStmt = $conn->prepare(
            "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.total_amount,
                o.created_at,
                m.name as merchant_name,
                m.image_url as merchant_image
             FROM orders o
             LEFT JOIN merchants m ON o.merchant_id = m.id
             WHERE o.user_id = :user_id
             ORDER BY o.created_at DESC
             LIMIT 10"
        );
        
        $ordersStmt->execute([':user_id' => $userId]);
        $recentOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get notifications count
        $notifStmt = $conn->prepare(
            "SELECT COUNT(*) as unread_count
             FROM notifications
             WHERE user_id = :user_id AND is_read = 0"
        );
        
        $notifStmt->execute([':user_id' => $userId]);
        $unreadCount = $notifStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

        // Get wallet balance and member info
        $userStmt = $conn->prepare(
            "SELECT 
                wallet_balance,
                member_level,
                member_points,
                member_since
             FROM users 
             WHERE id = :user_id"
        );
        
        $userStmt->execute([':user_id' => $userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Get favorite merchants
        $favStmt = $conn->prepare(
            "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.image_url,
                m.is_open
             FROM merchants m
             INNER JOIN user_favorite_merchants ufm ON m.id = ufm.merchant_id
             WHERE ufm.user_id = :user_id
             AND m.is_active = 1
             ORDER BY ufm.created_at DESC
             LIMIT 5"
        );
        
        $favStmt->execute([':user_id' => $userId]);
        $favorites = $favStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get recent activity
        $activityStmt = $conn->prepare(
            "SELECT 
                activity_type,
                description,
                created_at
             FROM user_activities
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT 10"
        );
        
        $activityStmt->execute([':user_id' => $userId]);
        $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHandler::success([
            'dashboard' => [
                'user_info' => [
                    'wallet_balance' => floatval($userInfo['wallet_balance'] ?? 0),
                    'member_level' => $userInfo['member_level'] ?? 'basic',
                    'member_points' => intval($userInfo['member_points'] ?? 0),
                    'member_since' => $userInfo['member_since'] ?? '',
                ],
                'recent_orders' => array_map('formatOrderDashboardData', $recentOrders),
                'unread_notifications' => intval($unreadCount ?? 0),
                'favorite_merchants' => array_map('formatMerchantDashboardData', $favorites),
                'recent_activities' => array_map('formatActivityData', $activities),
                'statistics' => [
                    'today_orders' => getTodayOrdersCount($conn, $userId),
                    'weekly_spending' => getWeeklySpending($conn, $userId),
                    'monthly_average' => getMonthlyAverage($conn, $userId),
                    'completion_rate' => getOrderCompletionRate($conn, $userId),
                ]
            ]
        ]);

    } catch (Exception $e) {
        ResponseHandler::error('Failed to load dashboard: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET PAYMENT METHODS
 *********************************/
function getPaymentMethods() {
    if (!checkAuthentication()) {
        return;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    $userId = $_SESSION['user_id'];

    try {
        $stmt = $conn->prepare(
            "SELECT 
                id,
                card_type,
                last_four,
                is_default,
                expiry_month,
                expiry_year,
                created_at
             FROM user_payment_methods
             WHERE user_id = :user_id
             AND is_active = 1
             ORDER BY is_default DESC, created_at DESC"
        );
        
        $stmt->execute([':user_id' => $userId]);
        $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHandler::success([
            'payment_methods' => array_map('formatPaymentMethodData', $paymentMethods),
            'default_method_id' => getDefaultPaymentMethodId($conn, $userId)
        ]);

    } catch (Exception $e) {
        ResponseHandler::error('Failed to load payment methods: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET MY REVIEWS
 *********************************/
function getMyReviews() {
    if (!checkAuthentication()) {
        return;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    $userId = $_SESSION['user_id'];

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        // Get total count
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) as total FROM user_reviews WHERE user_id = :user_id"
        );
        $countStmt->execute([':user_id' => $userId]);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get reviews
        $stmt = $conn->prepare(
            "SELECT 
                ur.id,
                ur.merchant_id,
                m.name as merchant_name,
                m.image_url as merchant_image,
                ur.rating,
                ur.comment,
                ur.review_type,
                ur.created_at,
                ur.updated_at
             FROM user_reviews ur
             LEFT JOIN merchants m ON ur.merchant_id = m.id
             WHERE ur.user_id = :user_id
             ORDER BY ur.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHandler::success([
            'reviews' => array_map('formatUserReviewData', $reviews),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);

    } catch (Exception $e) {
        ResponseHandler::error('Failed to load reviews: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET WISHLIST
 *********************************/
function getWishlist() {
    if (!checkAuthentication()) {
        return;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    $userId = $_SESSION['user_id'];

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        // Get total count
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) as total 
             FROM user_wishlist uw
             JOIN merchants m ON uw.merchant_id = m.id
             WHERE uw.user_id = :user_id AND m.is_active = 1"
        );
        $countStmt->execute([':user_id' => $userId]);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get wishlist items
        $stmt = $conn->prepare(
            "SELECT 
                uw.id,
                uw.merchant_id,
                m.name as merchant_name,
                m.category,
                m.rating,
                m.review_count,
                m.image_url as merchant_image,
                m.is_open,
                uw.created_at
             FROM user_wishlist uw
             JOIN merchants m ON uw.merchant_id = m.id
             WHERE uw.user_id = :user_id
             AND m.is_active = 1
             ORDER BY uw.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $wishlist = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHandler::success([
            'wishlist' => array_map('formatWishlistData', $wishlist),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);

    } catch (Exception $e) {
        ResponseHandler::error('Failed to load wishlist: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET SUPPORT TICKETS
 *********************************/
function getSupportTickets() {
    if (!checkAuthentication()) {
        return;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    $userId = $_SESSION['user_id'];

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        // Get total count
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) as total FROM support_tickets WHERE user_id = :user_id"
        );
        $countStmt->execute([':user_id' => $userId]);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get tickets
        $stmt = $conn->prepare(
            "SELECT 
                id,
                ticket_number,
                subject,
                category,
                status,
                priority,
                created_at,
                updated_at
             FROM support_tickets
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHandler::success([
            'tickets' => array_map('formatTicketData', $tickets),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);

    } catch (Exception $e) {
        ResponseHandler::error('Failed to load support tickets: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    // Check authentication first
    if (!checkAuthentication()) {
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'update_profile':
            updateUserProfile($input);
            break;
        case 'change_password':
            changeUserPassword($input);
            break;
        case 'remove_profile_picture':
            removeProfilePicture($input);
            break;
        case 'add_payment_method':
            addPaymentMethod($input);
            break;
        case 'delete_payment_method':
            deletePaymentMethod($input);
            break;
        case 'add_review':
            addUserReview($input);
            break;
        case 'update_review':
            updateUserReview($input);
            break;
        case 'delete_review':
            deleteUserReview($input);
            break;
        case 'add_to_wishlist':
            addToWishlist($input);
            break;
        case 'remove_from_wishlist':
            removeFromWishlist($input);
            break;
        case 'create_support_ticket':
            createSupportTicket($input);
            break;
        case 'delete_account':
            deleteUserAccount($input);
            break;
        case 'deactivate_account':
            deactivateUserAccount($input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * UPDATE USER PROFILE
 *********************************/
function updateUserProfile($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $fullName = trim($data['full_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
        $address = trim($data['address'] ?? '');
        $city = trim($data['city'] ?? '');
        $gender = $data['gender'] ?? null;

        // Validation
        if (!$fullName) {
            ResponseHandler::error('Full name is required', 400);
            return;
        }
        
        if (!$email) {
            ResponseHandler::error('Email is required', 400);
            return;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHandler::error('Enter a valid email address', 400);
            return;
        }
        
        if ($phone && strlen($phone) < 10) {
            ResponseHandler::error('Enter a valid phone number', 400);
            return;
        }

        // Check if email or phone already exists (excluding current user)
        $checkSql = "SELECT id FROM users WHERE (email = :email OR phone = :phone) AND id != :id";
        $check = $conn->prepare($checkSql);
        $check->execute([
            ':email' => $email,
            ':phone' => $phone,
            ':id' => $userId
        ]);
        
        if ($check->rowCount() > 0) {
            ResponseHandler::error('Email or phone already in use', 409);
            return;
        }

        // Update user
        $stmt = $conn->prepare(
            "UPDATE users SET 
                full_name = :full_name,
                email = :email,
                phone = :phone,
                address = :address,
                city = :city,
                gender = :gender,
                updated_at = NOW()
             WHERE id = :id"
        );
        
        $stmt->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':city' => $city,
            ':gender' => $gender,
            ':id' => $userId
        ]);

        // Get updated user
        $stmt = $conn->prepare(
            "SELECT 
                id,
                full_name,
                email,
                phone,
                address,
                city,
                gender,
                avatar,
                wallet_balance,
                member_level,
                member_points,
                total_orders,
                rating,
                verified,
                member_since,
                created_at,
                updated_at
             FROM users WHERE id = :id"
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'profile_updated', 'Updated profile information', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([
            'user' => formatUserData($user)
        ], 'Profile updated successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to update profile: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CHANGE USER PASSWORD
 *********************************/
function changeUserPassword($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        if (!$currentPassword || !$newPassword || !$confirmPassword) {
            ResponseHandler::error('All password fields are required', 400);
            return;
        }

        if ($newPassword !== $confirmPassword) {
            ResponseHandler::error('New passwords do not match', 400);
            return;
        }

        if (strlen($newPassword) < 6) {
            ResponseHandler::error('Password must be at least 6 characters', 400);
            return;
        }

        // Get current user password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            ResponseHandler::error('Current password is incorrect', 401);
            return;
        }

        // Update password
        $stmt = $conn->prepare(
            "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute([
            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $userId
        ]);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'password_changed', 'Changed account password', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([], 'Password changed successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to change password: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * REMOVE PROFILE PICTURE
 *********************************/
function removeProfilePicture($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        // Get current avatar to delete file if needed
        $stmt = $conn->prepare("SELECT avatar FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Update user (set avatar to null)
        $updateStmt = $conn->prepare(
            "UPDATE users SET avatar = NULL, updated_at = NOW() WHERE id = :id"
        );
        $updateStmt->execute([':id' => $userId]);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'avatar_removed', 'Removed profile picture', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([], 'Profile picture removed successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to remove profile picture: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * ADD PAYMENT METHOD
 *********************************/
function addPaymentMethod($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $cardType = $data['card_type'] ?? '';
        $lastFour = $data['last_four'] ?? '';
        $expiryMonth = $data['expiry_month'] ?? '';
        $expiryYear = $data['expiry_year'] ?? '';
        $isDefault = $data['is_default'] ?? false;

        // Validation
        if (!$cardType || !$lastFour || !$expiryMonth || !$expiryYear) {
            ResponseHandler::error('All card details are required', 400);
            return;
        }

        // Check if this card already exists for user
        $checkStmt = $conn->prepare(
            "SELECT id FROM user_payment_methods 
             WHERE user_id = :user_id AND last_four = :last_four"
        );
        $checkStmt->execute([
            ':user_id' => $userId,
            ':last_four' => $lastFour
        ]);
        
        if ($checkStmt->fetch()) {
            ResponseHandler::error('This card is already saved', 409);
            return;
        }

        // If setting as default, remove default from other cards
        if ($isDefault) {
            $clearDefaultStmt = $conn->prepare(
                "UPDATE user_payment_methods 
                 SET is_default = 0 
                 WHERE user_id = :user_id"
            );
            $clearDefaultStmt->execute([':user_id' => $userId]);
        }

        // Add new payment method
        $stmt = $conn->prepare(
            "INSERT INTO user_payment_methods 
                (user_id, card_type, last_four, expiry_month, expiry_year, is_default, created_at)
             VALUES (:user_id, :card_type, :last_four, :expiry_month, :expiry_year, :is_default, NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':card_type' => $cardType,
            ':last_four' => $lastFour,
            ':expiry_month' => $expiryMonth,
            ':expiry_year' => $expiryYear,
            ':is_default' => $isDefault ? 1 : 0
        ]);

        $paymentMethodId = $conn->lastInsertId();

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'payment_method_added', 'Added new payment method', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([
            'payment_method_id' => $paymentMethodId
        ], 'Payment method added successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to add payment method: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DELETE PAYMENT METHOD
 *********************************/
function deletePaymentMethod($data) {
    $userId = $_SESSION['user_id'];
    $paymentMethodId = $data['payment_method_id'] ?? null;
    
    if (!$paymentMethodId) {
        ResponseHandler::error('Payment method ID is required', 400);
        return;
    }

    $db = new Database();
    $conn = $db->getConnection();

    try {
        // Check if payment method belongs to user
        $checkStmt = $conn->prepare(
            "SELECT id FROM user_payment_methods 
             WHERE id = :id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':id' => $paymentMethodId,
            ':user_id' => $userId
        ]);
        
        if (!$checkStmt->fetch()) {
            ResponseHandler::error('Payment method not found', 404);
            return;
        }

        // Delete payment method
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_payment_methods 
             WHERE id = :id AND user_id = :user_id"
        );
        $deleteStmt->execute([
            ':id' => $paymentMethodId,
            ':user_id' => $userId
        ]);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'payment_method_deleted', 'Deleted payment method', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([], 'Payment method deleted successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to delete payment method: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * ADD USER REVIEW
 *********************************/
function addUserReview($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $merchantId = $data['merchant_id'] ?? null;
        $rating = intval($data['rating'] ?? 0);
        $comment = trim($data['comment'] ?? '');
        $reviewType = $data['review_type'] ?? 'merchant';

        if (!$merchantId || !$rating) {
            ResponseHandler::error('Merchant ID and rating are required', 400);
            return;
        }

        if ($rating < 1 || $rating > 5) {
            ResponseHandler::error('Rating must be between 1 and 5', 400);
            return;
        }

        // Check if user has already reviewed
        $existingStmt = $conn->prepare(
            "SELECT id FROM user_reviews 
             WHERE merchant_id = :merchant_id AND user_id = :user_id"
        );
        $existingStmt->execute([
            ':merchant_id' => $merchantId,
            ':user_id' => $userId
        ]);
        
        if ($existingStmt->fetch()) {
            ResponseHandler::error('You have already reviewed this merchant', 409);
            return;
        }

        // Create review
        $stmt = $conn->prepare(
            "INSERT INTO user_reviews 
                (user_id, merchant_id, rating, comment, review_type, created_at)
             VALUES (:user_id, :merchant_id, :rating, :comment, :review_type, NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':rating' => $rating,
            ':comment' => $comment,
            ':review_type' => $reviewType
        ]);

        $reviewId = $conn->lastInsertId();

        // Update merchant rating
        updateMerchantRating($conn, $merchantId);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'review_added', 'Added review for merchant', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([
            'review_id' => $reviewId
        ], 'Review added successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to add review: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE USER REVIEW
 *********************************/
function updateUserReview($data) {
    $userId = $_SESSION['user_id'];
    $reviewId = $data['review_id'] ?? null;
    
    if (!$reviewId) {
        ResponseHandler::error('Review ID is required', 400);
        return;
    }

    $db = new Database();
    $conn = $db->getConnection();

    try {
        $rating = isset($data['rating']) ? intval($data['rating']) : null;
        $comment = isset($data['comment']) ? trim($data['comment']) : null;

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            ResponseHandler::error('Rating must be between 1 and 5', 400);
            return;
        }

        // Check if review exists and belongs to user
        $checkStmt = $conn->prepare(
            "SELECT merchant_id FROM user_reviews 
             WHERE id = :id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':id' => $reviewId,
            ':user_id' => $userId
        ]);
        
        $review = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$review) {
            ResponseHandler::error('Review not found', 404);
            return;
        }

        $merchantId = $review['merchant_id'];

        // Build update query
        $updates = [];
        $params = [':id' => $reviewId];
        
        if ($rating !== null) {
            $updates[] = "rating = :rating";
            $params[':rating'] = $rating;
        }
        
        if ($comment !== null) {
            $updates[] = "comment = :comment";
            $params[':comment'] = $comment;
        }
        
        if (empty($updates)) {
            ResponseHandler::error('No fields to update', 400);
            return;
        }

        $updates[] = "updated_at = NOW()";
        $updateSql = "UPDATE user_reviews SET " . implode(', ', $updates) . " WHERE id = :id";

        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($params);

        // Update merchant rating
        updateMerchantRating($conn, $merchantId);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'review_updated', 'Updated review', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([], 'Review updated successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to update review: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DELETE USER REVIEW
 *********************************/
function deleteUserReview($data) {
    $userId = $_SESSION['user_id'];
    $reviewId = $data['review_id'] ?? null;
    
    if (!$reviewId) {
        ResponseHandler::error('Review ID is required', 400);
        return;
    }

    $db = new Database();
    $conn = $db->getConnection();

    try {
        // Check if review exists and belongs to user
        $checkStmt = $conn->prepare(
            "SELECT merchant_id FROM user_reviews 
             WHERE id = :id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':id' => $reviewId,
            ':user_id' => $userId
        ]);
        
        $review = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$review) {
            ResponseHandler::error('Review not found', 404);
            return;
        }

        $merchantId = $review['merchant_id'];

        // Delete review
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_reviews 
             WHERE id = :id AND user_id = :user_id"
        );
        $deleteStmt->execute([
            ':id' => $reviewId,
            ':user_id' => $userId
        ]);

        // Update merchant rating
        updateMerchantRating($conn, $merchantId);

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'review_deleted', 'Deleted review', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([], 'Review deleted successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to delete review: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * ADD TO WISHLIST
 *********************************/
function addToWishlist($data) {
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;
    
    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
        return;
    }

    $db = new Database();
    $conn = $db->getConnection();

    try {
        // Check if merchant exists
        $checkStmt = $conn->prepare(
            "SELECT id FROM merchants WHERE id = :id AND is_active = 1"
        );
        $checkStmt->execute([':id' => $merchantId]);
        
        if (!$checkStmt->fetch()) {
            ResponseHandler::error('Merchant not found', 404);
            return;
        }

        // Check if already in wishlist
        $existingStmt = $conn->prepare(
            "SELECT id FROM user_wishlist 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $existingStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        if ($existingStmt->fetch()) {
            ResponseHandler::error('Merchant already in wishlist', 409);
            return;
        }

        // Add to wishlist
        $stmt = $conn->prepare(
            "INSERT INTO user_wishlist (user_id, merchant_id, created_at)
             VALUES (:user_id, :merchant_id, NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);

        $wishlistId = $conn->lastInsertId();

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'wishlist_added', 'Added merchant to wishlist', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([
            'wishlist_id' => $wishlistId
        ], 'Added to wishlist successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to add to wishlist: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * REMOVE FROM WISHLIST
 *********************************/
function removeFromWishlist($data) {
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;
    
    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
        return;
    }

    $db = new Database();
    $conn = $db->getConnection();

    try {
        // Delete from wishlist
        $stmt = $conn->prepare(
            "DELETE FROM user_wishlist 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);

        if ($stmt->rowCount() === 0) {
            ResponseHandler::error('Merchant not found in wishlist', 404);
            return;
        }

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'wishlist_removed', 'Removed merchant from wishlist', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([], 'Removed from wishlist successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to remove from wishlist: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE SUPPORT TICKET
 *********************************/
function createSupportTicket($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $subject = trim($data['subject'] ?? '');
        $category = $data['category'] ?? 'general';
        $priority = $data['priority'] ?? 'medium';
        $description = trim($data['description'] ?? '');
        $orderId = $data['order_id'] ?? null;

        // Validation
        if (!$subject) {
            ResponseHandler::error('Subject is required', 400);
            return;
        }

        if (!$description) {
            ResponseHandler::error('Description is required', 400);
            return;
        }

        // Generate ticket number
        $ticketNumber = 'TICKET-' . date('Ymd') . '-' . strtoupper(substr(md5(microtime()), 0, 6));

        // Create ticket
        $stmt = $conn->prepare(
            "INSERT INTO support_tickets 
                (user_id, ticket_number, subject, category, priority, description, order_id, status, created_at)
             VALUES (:user_id, :ticket_number, :subject, :category, :priority, :description, :order_id, 'open', NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':ticket_number' => $ticketNumber,
            ':subject' => $subject,
            ':category' => $category,
            ':priority' => $priority,
            ':description' => $description,
            ':order_id' => $orderId
        ]);

        $ticketId = $conn->lastInsertId();

        // Log activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (user_id, activity_type, description, created_at)
             VALUES (:user_id, 'support_ticket_created', 'Created support ticket', NOW())"
        );
        $activityStmt->execute([':user_id' => $userId]);

        ResponseHandler::success([
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber
        ], 'Support ticket created successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to create support ticket: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DELETE USER ACCOUNT
 *********************************/
function deleteUserAccount($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $password = $data['password'] ?? '';
        $reason = trim($data['reason'] ?? '');

        if (!$password) {
            ResponseHandler::error('Password is required', 400);
            return;
        }

        // Verify password
        $checkStmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
        $checkStmt->execute([':id' => $userId]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            ResponseHandler::error('Incorrect password', 401);
            return;
        }

        // Check for pending orders
        $ordersStmt = $conn->prepare(
            "SELECT COUNT(*) as pending_orders 
             FROM orders 
             WHERE user_id = :user_id AND status NOT IN ('delivered', 'cancelled')"
        );
        $ordersStmt->execute([':user_id' => $userId]);
        $pendingOrders = $ordersStmt->fetch(PDO::FETCH_ASSOC)['pending_orders'];

        if ($pendingOrders > 0) {
            ResponseHandler::error('Cannot delete account with pending orders. Please complete or cancel all orders first.', 400);
            return;
        }

        // Check for active tickets
        $ticketsStmt = $conn->prepare(
            "SELECT COUNT(*) as active_tickets 
             FROM support_tickets 
             WHERE user_id = :user_id AND status = 'open'"
        );
        $ticketsStmt->execute([':user_id' => $userId]);
        $activeTickets = $ticketsStmt->fetch(PDO::FETCH_ASSOC)['active_tickets'];

        if ($activeTickets > 0) {
            ResponseHandler::error('Cannot delete account with active support tickets. Please resolve all tickets first.', 400);
            return;
        }

        // Log account deletion request (soft delete)
        $logStmt = $conn->prepare(
            "INSERT INTO account_deletion_requests 
                (user_id, reason, status, created_at)
             VALUES (:user_id, :reason, 'pending', NOW())"
        );
        $logStmt->execute([
            ':user_id' => $userId,
            ':reason' => $reason
        ]);

        // Mark user as deleted
        $updateStmt = $conn->prepare(
            "UPDATE users SET 
                is_deleted = 1,
                deleted_at = NOW(),
                updated_at = NOW()
             WHERE id = :id"
        );
        $updateStmt->execute([':id' => $userId]);

        // Destroy session
        session_destroy();

        ResponseHandler::success([], 'Account deletion requested successfully. Your account will be permanently deleted after 30 days.');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to delete account: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DEACTIVATE USER ACCOUNT
 *********************************/
function deactivateUserAccount($data) {
    $userId = $_SESSION['user_id'];
    $db = new Database();
    $conn = $db->getConnection();

    try {
        $password = $data['password'] ?? '';
        $reason = trim($data['reason'] ?? '');

        if (!$password) {
            ResponseHandler::error('Password is required', 400);
            return;
        }

        // Verify password
        $checkStmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
        $checkStmt->execute([':id' => $userId]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            ResponseHandler::error('Incorrect password', 401);
            return;
        }

        // Check for pending orders
        $ordersStmt = $conn->prepare(
            "SELECT COUNT(*) as pending_orders 
             FROM orders 
             WHERE user_id = :user_id AND status NOT IN ('delivered', 'cancelled')"
        );
        $ordersStmt->execute([':user_id' => $userId]);
        $pendingOrders = $ordersStmt->fetch(PDO::FETCH_ASSOC)['pending_orders'];

        if ($pendingOrders > 0) {
            ResponseHandler::error('Cannot deactivate account with pending orders. Please complete or cancel all orders first.', 400);
            return;
        }

        // Deactivate account
        $updateStmt = $conn->prepare(
            "UPDATE users SET 
                is_active = 0,
                deactivation_reason = :reason,
                deactivated_at = NOW(),
                updated_at = NOW()
             WHERE id = :id"
        );
        $updateStmt->execute([
            ':id' => $userId,
            ':reason' => $reason
        ]);

        // Destroy session
        session_destroy();

        ResponseHandler::success([], 'Account deactivated successfully. You can reactivate within 90 days by logging in.');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to deactivate account: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

function formatUserData($u) {
    // Format avatar URL
    $avatarUrl = '';
    if (!empty($u['avatar'])) {
        // If it's already a full URL, use it as is
        if (strpos($u['avatar'], 'http') === 0) {
            $avatarUrl = $u['avatar'];
        } else {
            // Otherwise, build the full URL
            $avatarUrl = 'https://dropxbackend-production.up.railway.app/uploads/avatars/' . $u['avatar'];
        }
    }

    return [
        'id' => $u['id'],
        'full_name' => $u['full_name'] ?? '',
        'email' => $u['email'] ?? '',
        'phone' => $u['phone'] ?? '',
        'address' => $u['address'] ?? '',
        'city' => $u['city'] ?? '',
        'gender' => $u['gender'] ?? '',
        'avatar' => $avatarUrl,
        'wallet_balance' => floatval($u['wallet_balance'] ?? 0),
        'member_level' => $u['member_level'] ?? 'basic',
        'member_points' => intval($u['member_points'] ?? 0),
        'total_orders' => intval($u['total_orders'] ?? 0),
        'rating' => floatval($u['rating'] ?? 0),
        'verified' => boolval($u['verified'] ?? false),
        'member_since' => $u['member_since'] ?? '',
        'created_at' => $u['created_at'] ?? '',
        'updated_at' => $u['updated_at'] ?? ''
    ];
}

function formatOrderDashboardData($order) {
    // Format merchant image URL
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        if (strpos($order['merchant_image'], 'http') === 0) {
            $merchantImage = $order['merchant_image'];
        } else {
            $merchantImage = 'https://dropxbackend-production.up.railway.app/uploads/' . $order['merchant_image'];
        }
    }

    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'] ?? '',
        'status' => $order['status'] ?? '',
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'merchant_name' => $order['merchant_name'] ?? '',
        'merchant_image' => $merchantImage,
        'created_at' => $order['created_at'] ?? '',
        'formatted_date' => !empty($order['created_at']) ? date('M d, Y', strtotime($order['created_at'])) : ''
    ];
}

function formatMerchantDashboardData($merchant) {
    // Format merchant image URL
    $merchantImage = '';
    if (!empty($merchant['merchant_image'])) {
        if (strpos($merchant['merchant_image'], 'http') === 0) {
            $merchantImage = $merchant['merchant_image'];
        } else {
            $merchantImage = 'https://dropxbackend-production.up.railway.app/uploads/' . $merchant['merchant_image'];
        }
    }

    return [
        'id' => $merchant['id'],
        'name' => $merchant['name'] ?? '',
        'category' => $merchant['category'] ?? '',
        'rating' => floatval($merchant['rating'] ?? 0),
        'image_url' => $merchantImage,
        'is_open' => boolval($merchant['is_open'] ?? false)
    ];
}

function formatActivityData($activity) {
    return [
        'type' => $activity['activity_type'] ?? '',
        'description' => $activity['description'] ?? '',
        'created_at' => $activity['created_at'] ?? '',
        'formatted_time' => !empty($activity['created_at']) ? date('g:i A', strtotime($activity['created_at'])) : '',
        'formatted_date' => !empty($activity['created_at']) ? date('M d, Y', strtotime($activity['created_at'])) : ''
    ];
}

function formatPaymentMethodData($method) {
    return [
        'id' => $method['id'],
        'card_type' => $method['card_type'] ?? '',
        'last_four' => $method['last_four'] ?? '',
        'expiry_month' => $method['expiry_month'] ?? '',
        'expiry_year' => $method['expiry_year'] ?? '',
        'is_default' => boolval($method['is_default'] ?? false),
        'created_at' => $method['created_at'] ?? '',
        'formatted_expiry' => !empty($method['expiry_month']) && !empty($method['expiry_year']) 
            ? $method['expiry_month'] . '/' . substr($method['expiry_year'], -2)
            : ''
    ];
}

function formatUserReviewData($review) {
    // Format merchant image URL
    $merchantImage = '';
    if (!empty($review['merchant_image'])) {
        if (strpos($review['merchant_image'], 'http') === 0) {
            $merchantImage = $review['merchant_image'];
        } else {
            $merchantImage = 'https://dropxbackend-production.up.railway.app/uploads/' . $review['merchant_image'];
        }
    }

    return [
        'id' => $review['id'],
        'merchant_id' => $review['merchant_id'],
        'merchant_name' => $review['merchant_name'] ?? '',
        'merchant_image' => $merchantImage,
        'rating' => intval($review['rating'] ?? 0),
        'comment' => $review['comment'] ?? '',
        'review_type' => $review['review_type'] ?? 'merchant',
        'created_at' => $review['created_at'] ?? '',
        'updated_at' => $review['updated_at'] ?? '',
        'formatted_date' => !empty($review['created_at']) ? date('M d, Y', strtotime($review['created_at'])) : ''
    ];
}

function formatWishlistData($item) {
    // Format merchant image URL
    $merchantImage = '';
    if (!empty($item['merchant_image'])) {
        if (strpos($item['merchant_image'], 'http') === 0) {
            $merchantImage = $item['merchant_image'];
        } else {
            $merchantImage = 'https://dropxbackend-production.up.railway.app/uploads/' . $item['merchant_image'];
        }
    }

    return [
        'id' => $item['id'],
        'merchant_id' => $item['merchant_id'],
        'merchant_name' => $item['merchant_name'] ?? '',
        'category' => $item['category'] ?? '',
        'rating' => floatval($item['rating'] ?? 0),
        'review_count' => intval($item['review_count'] ?? 0),
        'image_url' => $merchantImage,
        'is_open' => boolval($item['is_open'] ?? false),
        'created_at' => $item['created_at'] ?? '',
        'formatted_date' => !empty($item['created_at']) ? date('M d, Y', strtotime($item['created_at'])) : ''
    ];
}

function formatTicketData($ticket) {
    return [
        'id' => $ticket['id'],
        'ticket_number' => $ticket['ticket_number'] ?? '',
        'subject' => $ticket['subject'] ?? '',
        'category' => $ticket['category'] ?? 'general',
        'status' => $ticket['status'] ?? 'open',
        'priority' => $ticket['priority'] ?? 'medium',
        'created_at' => $ticket['created_at'] ?? '',
        'updated_at' => $ticket['updated_at'] ?? '',
        'formatted_date' => !empty($ticket['created_at']) ? date('M d, Y', strtotime($ticket['created_at'])) : ''
    ];
}

function getDefaultPaymentMethodId($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id FROM user_payment_methods 
         WHERE user_id = :user_id AND is_default = 1 AND is_active = 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['id'] : null;
}

function getTodayOrdersCount($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM orders 
         WHERE user_id = :user_id AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return intval($result['count'] ?? 0);
}

function getWeeklySpending($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) as total 
         FROM orders 
         WHERE user_id = :user_id 
         AND status = 'completed'
         AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return floatval($result['total'] ?? 0);
}

function getMonthlyAverage($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT COALESCE(AVG(total_amount), 0) as average 
         FROM orders 
         WHERE user_id = :user_id 
         AND status = 'completed'
         AND YEAR(created_at) = YEAR(CURDATE())
         AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return floatval($result['average'] ?? 0);
}

function getOrderCompletionRate($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders
         FROM orders 
         WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total = intval($result['total_orders'] ?? 0);
    $completed = intval($result['completed_orders'] ?? 0);
    
    return $total > 0 ? round(($completed / $total) * 100, 2) : 0;
}

function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM user_reviews
        WHERE merchant_id = :merchant_id"
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

function cleanPhoneNumber($phone) {
    $phone = trim($phone);
    $hasPlus = substr($phone, 0, 1) === '+';
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    
    return $digits;
}
?>