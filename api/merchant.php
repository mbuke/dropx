<?php
// api/merchant.php - Merchant API with database integration - COMPLETE FIXED VERSION
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$merchantId = $_GET['id'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Debug logging
error_log("Merchant API Request: action=$action, merchantId=$merchantId, userId=" . ($userId ?? 'none'));

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Check if user_favorites table exists
    function userFavoritesTableExists($conn) {
        try {
            $check = $conn->query("SHOW TABLES LIKE 'user_favorites'");
            return $check->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Create user_favorites table if it doesn't exist
    function createUserFavoritesTable($conn) {
        $createQuery = "
            CREATE TABLE IF NOT EXISTS user_favorites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                restaurant_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_favorite (user_id, restaurant_id),
                INDEX idx_user_id (user_id),
                INDEX idx_restaurant_id (restaurant_id)
            )
        ";
        
        try {
            $conn->exec($createQuery);
            error_log("Created user_favorites table");
            return true;
        } catch (Exception $e) {
            error_log("Failed to create user_favorites table: " . $e->getMessage());
            return false;
        }
    }
    
    // FIX: Disable ONLY_FULL_GROUP_BY mode for this session
    try {
        $conn->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    } catch (Exception $e) {
        error_log("Could not set sql_mode: " . $e->getMessage());
    }
    
    switch ($action) {
        case 'get':
            if (empty($merchantId)) {
                jsonResponse(false, null, 'Merchant ID is required', 400);
            }
            
            // Validate merchant ID is numeric
            if (!is_numeric($merchantId)) {
                jsonResponse(false, null, 'Invalid merchant ID. Must be a number.', 400);
            }
            
            $merchantId = intval($merchantId);
            
            // Get merchant details - FIXED QUERY
            $query = "
                SELECT 
                    r.*,
                    COALESCE(AVG(mr.rating), r.rating) as actual_rating,
                    COALESCE(COUNT(DISTINCT mr.id), r.review_count) as actual_review_count
                FROM restaurants r
                LEFT JOIN merchant_reviews mr ON r.id = mr.restaurant_id 
                    AND mr.status = 'approved'
                WHERE r.id = :id AND (r.is_active = 1 OR r.status = 'active')
                GROUP BY r.id
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([':id' => $merchantId]);
            $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$merchant) {
                jsonResponse(false, null, 'Merchant not found or inactive', 404);
            }
            
            // Check if user has favorited this merchant
            $isFavorite = false;
            $favoriteCount = 0;
            
            if ($userId && userFavoritesTableExists($conn)) {
                // Check if user has favorited
                $favCheckQuery = "
                    SELECT id FROM user_favorites 
                    WHERE user_id = :user_id AND restaurant_id = :restaurant_id
                ";
                $favCheckStmt = $conn->prepare($favCheckQuery);
                $favCheckStmt->execute([
                    ':user_id' => $userId,
                    ':restaurant_id' => $merchantId
                ]);
                $isFavorite = $favCheckStmt->rowCount() > 0;
                
                // Get favorite count
                $countQuery = "
                    SELECT COUNT(*) as favorite_count 
                    FROM user_favorites 
                    WHERE restaurant_id = :restaurant_id
                ";
                $countStmt = $conn->prepare($countQuery);
                $countStmt->execute([':restaurant_id' => $merchantId]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                $favoriteCount = $countResult ? (int)$countResult['favorite_count'] : 0;
            }
            
            // Get categories
            $categories = [];
            try {
                $categoriesQuery = "
                    SELECT id, name, slug, description, display_order, item_count
                    FROM merchant_categories 
                    WHERE restaurant_id = :merchant_id AND is_active = 1
                    ORDER BY display_order, name
                ";
                $catStmt = $conn->prepare($categoriesQuery);
                $catStmt->execute([':merchant_id' => $merchantId]);
                $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Categories query failed: " . $e->getMessage());
            }
            
            // Format merchant data
            $merchantData = [
                'id' => $merchant['id'],
                'name' => $merchant['name'],
                'type' => $merchant['merchant_type'] ?? 'restaurant',
                'mainCategory' => $merchant['main_category'] ?? 'food',
                'description' => $merchant['description'],
                'shortDescription' => $merchant['short_description'] ?? substr($merchant['description'], 0, 100) . '...',
                'rating' => round($merchant['actual_rating'] ?? $merchant['rating'] ?? 0, 1),
                'reviewCount' => (int) ($merchant['actual_review_count'] ?? $merchant['review_count'] ?? 0),
                'deliveryTime' => $merchant['delivery_time'] ?? '30-45 min',
                'deliveryFee' => (float) ($merchant['delivery_fee'] ?? 0),
                'minOrder' => (float) ($merchant['min_order_amount'] ?? 0),
                'distance' => $merchant['distance_km'] ? round($merchant['distance_km'], 1) : 1.2,
                'isOpen' => (bool) ($merchant['is_open'] ?? true),
                'openingHours' => $merchant['opening_hours'] ?? '9:00 AM - 10:00 PM',
                'coverImage' => $merchant['cover_image'] ?: 'default-cover.jpg',
                'image' => $merchant['image'] ?: 'default-merchant.jpg',
                'isFeatured' => (bool) ($merchant['is_featured'] ?? false),
                'isPromoted' => (bool) ($merchant['is_promoted'] ?? false),
                'isFarmSourced' => (bool) ($merchant['is_farm_sourced'] ?? false),
                'contact' => [
                    'phone' => $merchant['phone'] ?? 'Not available',
                    'address' => $merchant['address'] ?? 'Not available',
                    'city' => $merchant['city'] ?? 'Not available',
                    'latitude' => $merchant['latitude'] ? (float)$merchant['latitude'] : null,
                    'longitude' => $merchant['longitude'] ? (float)$merchant['longitude'] : null
                ],
                'tags' => json_decode($merchant['tags'] ?? '[]', true),
                'offers' => json_decode($merchant['offers'] ?? '[]', true),
                'cuisineType' => json_decode($merchant['cuisine_types'] ?? '[]', true),
                'dietary' => json_decode($merchant['dietary_options'] ?? '[]', true),
                'deliveryTypes' => json_decode($merchant['delivery_types'] ?? '["express", "scheduled"]', true),
                'favoriteCount' => $favoriteCount,
                'isFavorite' => $isFavorite,
                'categories' => array_map(function($cat) {
                    return [
                        'id' => $cat['id'],
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                        'description' => $cat['description'],
                        'itemCount' => (int) ($cat['item_count'] ?? 0),
                        'displayOrder' => (int) ($cat['display_order'] ?? 0)
                    ];
                }, $categories)
            ];
            
            jsonResponse(true, $merchantData, 'Merchant retrieved successfully');
            break;
            
        case 'list':
            // Get all merchants with filtering
            $type = $_GET['type'] ?? null;
            $category = $_GET['category'] ?? null;
            $city = $_GET['city'] ?? null;
            $search = $_GET['search'] ?? '';
            $minRating = isset($_GET['min_rating']) ? floatval($_GET['min_rating']) : null;
            $featured = isset($_GET['featured']) ? intval($_GET['featured']) : null;
            $promoted = isset($_GET['promoted']) ? intval($_GET['promoted']) : null;
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $sortBy = $_GET['sort'] ?? 'featured';
            
            // Build query
            $query = "
                SELECT 
                    r.*,
                    COALESCE(AVG(mr.rating), r.rating) as actual_rating,
                    COALESCE(COUNT(DISTINCT mr.id), r.review_count) as actual_review_count
                FROM restaurants r
                LEFT JOIN merchant_reviews mr ON r.id = mr.restaurant_id 
                    AND mr.status = 'approved'
                WHERE (r.is_active = 1 OR r.status = 'active')
            ";
            
            $params = [];
            
            // Apply filters
            if ($type) {
                $query .= " AND r.merchant_type = :type";
                $params[':type'] = $type;
            }
            
            if ($category) {
                $query .= " AND r.main_category = :category";
                $params[':category'] = $category;
            }
            
            if ($city) {
                $query .= " AND r.city = :city";
                $params[':city'] = $city;
            }
            
            if ($search) {
                $query .= " AND (r.name LIKE :search OR r.short_description LIKE :search)";
                $params[':search'] = "%{$search}%";
            }
            
            if ($featured !== null) {
                $query .= " AND r.is_featured = :featured";
                $params[':featured'] = $featured;
            }
            
            if ($promoted !== null) {
                $query .= " AND r.is_promoted = :promoted";
                $params[':promoted'] = $promoted;
            }
            
            $query .= " GROUP BY r.id";
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM ($query) as filtered";
            $countStmt = $conn->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Apply sorting
            $orderBy = "ORDER BY ";
            switch ($sortBy) {
                case 'rating':
                    $orderBy .= "actual_rating DESC, actual_review_count DESC";
                    break;
                case 'delivery_time':
                    $orderBy .= "
                        CASE 
                            WHEN r.delivery_time LIKE '%min%' 
                            THEN CAST(SUBSTRING_INDEX(r.delivery_time, '-', 1) AS UNSIGNED)
                            ELSE 60
                        END ASC";
                    break;
                case 'min_order':
                    $orderBy .= "r.min_order_amount ASC";
                    break;
                case 'delivery_fee':
                    $orderBy .= "r.delivery_fee ASC";
                    break;
                case 'name':
                    $orderBy .= "r.name ASC";
                    break;
                case 'featured':
                default:
                    $orderBy .= "r.is_featured DESC, r.is_promoted DESC, actual_rating DESC";
            }
            
            $query .= " " . $orderBy . " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            
            $stmt->execute();
            $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter by rating
            if ($minRating !== null) {
                $merchants = array_filter($merchants, function($merchant) use ($minRating) {
                    return ($merchant['actual_rating'] ?? $merchant['rating'] ?? 0) >= $minRating;
                });
                $merchants = array_values($merchants);
            }
            
            // Format merchants
            $formattedMerchants = array_map(function($merchant) use ($conn, $userId) {
                $isFavorite = false;
                $favoriteCount = 0;
                
                if ($userId && userFavoritesTableExists($conn)) {
                    $favCheckQuery = "
                        SELECT id FROM user_favorites 
                        WHERE user_id = :user_id AND restaurant_id = :restaurant_id
                    ";
                    $favCheckStmt = $conn->prepare($favCheckQuery);
                    $favCheckStmt->execute([
                        ':user_id' => $userId,
                        ':restaurant_id' => $merchant['id']
                    ]);
                    $isFavorite = $favCheckStmt->rowCount() > 0;
                    
                    $countQuery = "SELECT COUNT(*) as count FROM user_favorites WHERE restaurant_id = :restaurant_id";
                    $countStmt = $conn->prepare($countQuery);
                    $countStmt->execute([':restaurant_id' => $merchant['id']]);
                    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $favoriteCount = $countResult ? (int)$countResult['count'] : 0;
                }
                
                return [
                    'id' => $merchant['id'],
                    'name' => $merchant['name'],
                    'type' => $merchant['merchant_type'] ?? 'restaurant',
                    'mainCategory' => $merchant['main_category'] ?? 'food',
                    'description' => $merchant['short_description'] ?? substr($merchant['description'] ?? '', 0, 100) . '...',
                    'rating' => round($merchant['actual_rating'] ?? $merchant['rating'] ?? 0, 1),
                    'reviewCount' => (int) ($merchant['actual_review_count'] ?? $merchant['review_count'] ?? 0),
                    'deliveryTime' => $merchant['delivery_time'] ?? '30-45 min',
                    'deliveryFee' => (float) ($merchant['delivery_fee'] ?? 0),
                    'minOrder' => (float) ($merchant['min_order_amount'] ?? 0),
                    'distance' => $merchant['distance_km'] ? round($merchant['distance_km'], 1) : null,
                    'isOpen' => (bool) ($merchant['is_open'] ?? true),
                    'image' => $merchant['image'] ?: 'default-merchant.jpg',
                    'coverImage' => $merchant['cover_image'] ?: 'default-cover.jpg',
                    'isFeatured' => (bool) ($merchant['is_featured'] ?? false),
                    'isPromoted' => (bool) ($merchant['is_promoted'] ?? false),
                    'isFarmSourced' => (bool) ($merchant['is_farm_sourced'] ?? false),
                    'city' => $merchant['city'] ?? 'Not available',
                    'address' => $merchant['address'] ?? 'Not available',
                    'phone' => $merchant['phone'] ?? 'Not available',
                    'tags' => json_decode($merchant['tags'] ?? '[]', true),
                    'offers' => json_decode($merchant['offers'] ?? '[]', true),
                    'isFavorite' => $isFavorite,
                    'favoriteCount' => $favoriteCount
                ];
            }, $merchants);
            
            jsonResponse(true, [
                'merchants' => $formattedMerchants,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int) $total,
                    'pages' => ceil($total / $limit)
                ]
            ], 'Merchants retrieved successfully');
            break;
            
        case 'toggle_favorite':
            if (empty($merchantId)) {
                jsonResponse(false, null, 'Merchant ID is required', 400);
            }
            
            // Validate merchant ID is numeric
            if (!is_numeric($merchantId)) {
                jsonResponse(false, null, 'Invalid merchant ID. Must be a number.', 400);
            }
            
            $merchantId = intval($merchantId);
            
            // Check for user authentication
            if (!$userId) {
                // Check for user_id parameter for testing
                $testUserId = $_GET['user_id'] ?? null;
                if ($testUserId && is_numeric($testUserId)) {
                    $userId = intval($testUserId);
                } else {
                    jsonResponse(false, null, 'Authentication required. Please login.', 401);
                }
            }
            
            $userId = intval($userId);
            
            // Ensure user_favorites table exists
            if (!userFavoritesTableExists($conn)) {
                if (!createUserFavoritesTable($conn)) {
                    jsonResponse(false, null, 'Favorites system is not available at the moment', 500);
                }
            }
            
            // Check if restaurant exists
            $restaurantCheckQuery = "SELECT id FROM restaurants WHERE id = :id AND (is_active = 1 OR status = 'active')";
            $restaurantCheckStmt = $conn->prepare($restaurantCheckQuery);
            $restaurantCheckStmt->execute([':id' => $merchantId]);
            
            if (!$restaurantCheckStmt->rowCount()) {
                jsonResponse(false, null, 'Merchant not found or inactive', 404);
            }
            
            $conn->beginTransaction();
            
            try {
                // Check if already favorited
                $checkQuery = "
                    SELECT id FROM user_favorites 
                    WHERE user_id = :user_id AND restaurant_id = :merchant_id
                ";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->execute([
                    ':user_id' => $userId,
                    ':merchant_id' => $merchantId
                ]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Remove from favorites
                    $deleteQuery = "
                        DELETE FROM user_favorites 
                        WHERE user_id = :user_id AND restaurant_id = :merchant_id
                    ";
                    $deleteStmt = $conn->prepare($deleteQuery);
                    $deleteStmt->execute([
                        ':user_id' => $userId,
                        ':merchant_id' => $merchantId
                    ]);
                    $action = 'removed';
                    $isFavorite = false;
                } else {
                    // Add to favorites
                    $insertQuery = "
                        INSERT INTO user_favorites (user_id, restaurant_id, created_at)
                        VALUES (:user_id, :merchant_id, NOW())
                        ON DUPLICATE KEY UPDATE created_at = NOW()
                    ";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->execute([
                        ':user_id' => $userId,
                        ':merchant_id' => $merchantId
                    ]);
                    $action = 'added';
                    $isFavorite = true;
                }
                
                // Get updated favorite count
                $countQuery = "
                    SELECT COUNT(*) as favorite_count 
                    FROM user_favorites 
                    WHERE restaurant_id = :merchant_id
                ";
                $countStmt = $conn->prepare($countQuery);
                $countStmt->execute([':merchant_id' => $merchantId]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                $count = $countResult ? (int)$countResult['favorite_count'] : 0;
                
                // Update restaurant favorite count
                try {
                    $updateQuery = "
                        UPDATE restaurants 
                        SET favorite_count = :count, updated_at = NOW()
                        WHERE id = :merchant_id
                    ";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([
                        ':count' => $count,
                        ':merchant_id' => $merchantId
                    ]);
                } catch (Exception $e) {
                    // If favorite_count column doesn't exist, skip this update
                    error_log("Could not update favorite_count: " . $e->getMessage());
                }
                
                $conn->commit();
                
                jsonResponse(true, [
                    'isFavorite' => $isFavorite,
                    'favoriteCount' => $count,
                    'merchantId' => $merchantId,
                    'userId' => $userId
                ], "Merchant {$action} to favorites");
                
            } catch (Exception $e) {
                $conn->rollBack();
                error_log("Favorite toggle error: " . $e->getMessage());
                jsonResponse(false, null, 'Failed to update favorites: ' . $e->getMessage(), 500);
            }
            break;
            
        default:
            jsonResponse(false, null, 'Invalid action specified', 400);
    }
    
} catch (Exception $e) {
    error_log("Merchant API Error: " . $e->getMessage());
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}
?>