<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');


// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Default response
$response = [
    'success' => false,
    'message' => 'Invalid request',
    'data' => null
];

try {
    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Get action parameter
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    
    // Handle different actions
    switch ($action) {
        case 'quick_order':
            handleQuickOrder($db, $response);
            break;
            
        case 'list':
            handleListMenuItems($db, $response);
            break;
            
        case 'get':
            handleGetMenuItem($db, $response);
            break;
            
        case 'by_restaurant':
            handleItemsByRestaurant($db, $response);
            break;
            
        case 'by_category':
            handleItemsByCategory($db, $response);
            break;
            
        case 'search':
            handleSearchItems($db, $response);
            break;
            
        case 'toggle_favorite':
            handleToggleFavorite($db, $response);
            break;
            
        case 'check_favorite':
            handleCheckFavorite($db, $response);
            break;
            
        case 'categories':
            handleGetCategories($db, $response);
            break;
            
        case 'restaurant_categories':
            handleRestaurantCategories($db, $response);
            break;
            
        default:
            $response['message'] = 'Unknown action';
            http_response_code(400);
            break;
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    http_response_code(500);
} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    http_response_code(500);
}

// Send JSON response
echo json_encode($response);
exit();

// ==================== FUNCTION DEFINITIONS ====================

/**
 * Handle quick order items request
 */
function handleQuickOrder($db, &$response) {
    // Get parameters
    $category = isset($_GET['category']) && $_GET['category'] != 'all' ? $_GET['category'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 12;
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'featured';
    $restaurant_id = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : null;
    
    // Build base query
    $query = "
        SELECT 
            mi.id,
            mi.uuid,
            mi.restaurant_id,
            mi.category_id,
            mi.name,
            mi.description,
            mi.price,
            mi.discounted_price,
            mi.cost_price,
            mi.image_url,
            mi.prep_time,
            mi.calories,
            mi.rating,
            mi.review_count,
            mi.unit,
            mi.in_stock,
            mi.is_organic,
            mi.is_popular,
            mi.is_signature,
            mi.is_healthy,
            mi.tags,
            mi.dietary,
            mi.allergens,
            mi.customization_options,
            mi.nutritional_info,
            mi.metadata,
            mi.display_order,
            mi.is_active,
            mi.created_at,
            mi.updated_at,
            r.name as restaurant_name,
            r.merchant_type,
            r.main_category,
            r.cuisine_type,
            r.rating as restaurant_rating,
            r.delivery_time,
            r.delivery_fee,
            r.min_order_amount,
            r.is_open,
            r.opening_hours,
            r.phone as restaurant_phone,
            r.address as restaurant_address,
            r.city as restaurant_city,
            r.latitude,
            r.longitude,
            r.is_featured as restaurant_featured,
            r.is_promoted as restaurant_promoted,
            r.slug as restaurant_slug,
            r.status as restaurant_status,
            mc.name as category_name,
            mc.slug as category_slug
        FROM menu_items mi
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
        WHERE mi.is_active = 1 
        AND mi.in_stock = 1
        AND r.status = 'active'
        AND r.is_active = 1
    ";
    
    $params = [];
    
    // Add restaurant filter
    if ($restaurant_id) {
        $query .= " AND mi.restaurant_id = :restaurant_id";
        $params[':restaurant_id'] = $restaurant_id;
    }
    
    // Add category filter
    if ($category) {
        $query .= " AND mc.slug = :category";
        $params[':category'] = $category;
    }
    
    // Add search filter
    if ($search) {
        $query .= " AND (
            mi.name LIKE :search 
            OR mi.description LIKE :search 
            OR mi.tags LIKE :search
            OR r.name LIKE :search
            OR mc.name LIKE :search
        )";
        $params[':search'] = "%$search%";
    }
    
    // Add sorting
    switch ($sort) {
        case 'price-low':
            $query .= " ORDER BY COALESCE(mi.discounted_price, mi.price) ASC";
            break;
        case 'price-high':
            $query .= " ORDER BY COALESCE(mi.discounted_price, mi.price) DESC";
            break;
        case 'rating':
            $query .= " ORDER BY mi.rating DESC, mi.review_count DESC";
            break;
        case 'popular':
            $query .= " ORDER BY mi.is_popular DESC, mi.rating DESC";
            break;
        case 'prep-time':
            $query .= " ORDER BY mi.prep_time ASC";
            break;
        case 'featured':
        default:
            $query .= " ORDER BY mi.is_popular DESC, r.is_featured DESC, mi.rating DESC";
            break;
    }
    
    // Add limit
    $query .= " LIMIT :limit";
    $params[':limit'] = $limit;
    
    // Prepare and execute query
    $stmt = $db->prepare($query);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    
    // Get results
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format menu items
    $formattedItems = array_map(function($item) {
        // Parse JSON fields
        $tags = !empty($item['tags']) ? json_decode($item['tags'], true) : [];
        $dietary = !empty($item['dietary']) ? json_decode($item['dietary'], true) : [];
        $allergens = !empty($item['allergens']) ? json_decode($item['allergens'], true) : [];
        $customization_options = !empty($item['customization_options']) ? json_decode($item['customization_options'], true) : [];
        $nutritional_info = !empty($item['nutritional_info']) ? json_decode($item['nutritional_info'], true) : [];
        $metadata = !empty($item['metadata']) ? json_decode($item['metadata'], true) : [];
        
        // Calculate display price
        $display_price = !empty($item['discounted_price']) ? floatval($item['discounted_price']) : floatval($item['price']);
        $original_price = !empty($item['discounted_price']) ? floatval($item['price']) : null;
        
        // Calculate discount percentage if discounted price exists
        $discountPercentage = null;
        if ($original_price && $display_price < $original_price) {
            $discount = $original_price - $display_price;
            $discountPercentage = round(($discount / $original_price) * 100);
        }
        
        return [
            'id' => $item['id'],
            'uuid' => $item['uuid'],
            'name' => $item['name'],
            'description' => $item['description'],
            'price' => $display_price,
            'original_price' => $original_price,
            'discounted_price' => !empty($item['discounted_price']) ? floatval($item['discounted_price']) : null,
            'discount_percentage' => $discountPercentage,
            'category_id' => $item['category_id'],
            'category_name' => $item['category_name'],
            'category_slug' => $item['category_slug'],
            'restaurant_id' => $item['restaurant_id'],
            'restaurant_name' => $item['restaurant_name'],
            'restaurant_type' => $item['merchant_type'],
            'restaurant_category' => $item['main_category'],
            'cuisine_type' => $item['cuisine_type'],
            'restaurant_rating' => floatval($item['restaurant_rating']),
            'delivery_time' => $item['delivery_time'],
            'delivery_fee' => floatval($item['delivery_fee']),
            'min_order_amount' => floatval($item['min_order_amount']),
            'image_url' => $item['image_url'],
            'image' => $item['image_url'], // Alias for frontend compatibility
            'prep_time' => $item['prep_time'],
            'calories' => $item['calories'],
            'rating' => floatval($item['rating']),
            'review_count' => intval($item['review_count']),
            'unit' => $item['unit'],
            'in_stock' => (bool)$item['in_stock'],
            'is_active' => (bool)$item['is_active'],
            'is_organic' => (bool)$item['is_organic'],
            'is_popular' => (bool)$item['is_popular'],
            'is_signature' => (bool)$item['is_signature'],
            'is_healthy' => (bool)$item['is_healthy'],
            'tags' => $tags,
            'dietary_info' => $dietary,
            'dietary' => $dietary, // Alias
            'allergens' => $allergens,
            'customization_options' => $customization_options,
            'nutritional_info' => $nutritional_info,
            'metadata' => $metadata,
            'display_order' => $item['display_order'],
            'restaurant_phone' => $item['restaurant_phone'],
            'restaurant_address' => $item['restaurant_address'],
            'restaurant_city' => $item['restaurant_city'],
            'is_open' => (bool)$item['is_open'],
            'opening_hours' => $item['opening_hours'],
            'restaurant_featured' => (bool)$item['restaurant_featured'],
            'restaurant_promoted' => (bool)$item['restaurant_promoted'],
            'restaurant_slug' => $item['restaurant_slug'],
            'latitude' => $item['latitude'],
            'longitude' => $item['longitude'],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
            'is_quick_order' => true
        ];
    }, $items);
    
    $response['success'] = true;
    $response['message'] = 'Menu items retrieved successfully';
    $response['data'] = [
        'items' => $formattedItems,
        'total' => count($formattedItems),
        'limit' => $limit
    ];
}

/**
 * Handle list menu items request
 */
function handleListMenuItems($db, &$response) {
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $restaurant_id = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $in_stock_only = isset($_GET['in_stock']) ? filter_var($_GET['in_stock'], FILTER_VALIDATE_BOOLEAN) : true;
    
    $query = "
        SELECT 
            mi.*, 
            r.name as restaurant_name,
            r.rating as restaurant_rating,
            r.delivery_fee,
            r.delivery_time,
            mc.name as category_name
        FROM menu_items mi
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
        WHERE mi.is_active = 1
    ";
    
    $params = [];
    
    if ($in_stock_only) {
        $query .= " AND mi.in_stock = 1";
    }
    
    if ($category_id) {
        $query .= " AND mi.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }
    
    if ($restaurant_id) {
        $query .= " AND mi.restaurant_id = :restaurant_id";
        $params[':restaurant_id'] = $restaurant_id;
    }
    
    $query .= " ORDER BY mi.display_order ASC, mi.is_popular DESC, mi.name ASC LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format items
    $formattedItems = array_map(function($item) {
        // Parse JSON fields
        $item['tags'] = !empty($item['tags']) ? json_decode($item['tags'], true) : [];
        $item['dietary'] = !empty($item['dietary']) ? json_decode($item['dietary'], true) : [];
        $item['allergens'] = !empty($item['allergens']) ? json_decode($item['allergens'], true) : [];
        $item['customization_options'] = !empty($item['customization_options']) ? json_decode($item['customization_options'], true) : [];
        $item['nutritional_info'] = !empty($item['nutritional_info']) ? json_decode($item['nutritional_info'], true) : [];
        $item['metadata'] = !empty($item['metadata']) ? json_decode($item['metadata'], true) : [];
        
        // Calculate display price
        $item['display_price'] = !empty($item['discounted_price']) ? floatval($item['discounted_price']) : floatval($item['price']);
        $item['original_price'] = !empty($item['discounted_price']) ? floatval($item['price']) : null;
        
        if ($item['original_price'] && $item['display_price'] < $item['original_price']) {
            $discount = $item['original_price'] - $item['display_price'];
            $item['discount_percentage'] = round(($discount / $item['original_price']) * 100);
        } else {
            $item['discount_percentage'] = null;
        }
        
        return $item;
    }, $items);
    
    $response['success'] = true;
    $response['message'] = 'Menu items retrieved successfully';
    $response['data'] = [
        'items' => $formattedItems,
        'total' => count($formattedItems),
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Handle get single menu item
 */
function handleGetMenuItem($db, &$response) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $uuid = isset($_GET['uuid']) ? $_GET['uuid'] : null;
    
    if (!$id && !$uuid) {
        $response['message'] = 'Item ID or UUID is required';
        http_response_code(400);
        return;
    }
    
    $query = "
        SELECT 
            mi.*,
            r.name as restaurant_name,
            r.merchant_type,
            r.main_category,
            r.cuisine_type,
            r.rating as restaurant_rating,
            r.delivery_time,
            r.delivery_fee,
            r.min_order_amount,
            r.image as restaurant_image,
            r.cover_image as restaurant_cover_image,
            r.is_open,
            r.opening_hours,
            r.phone as restaurant_phone,
            r.address as restaurant_address,
            r.city as restaurant_city,
            r.latitude,
            r.longitude,
            r.is_featured as restaurant_featured,
            r.slug as restaurant_slug,
            r.status as restaurant_status,
            mc.name as category_name,
            mc.slug as category_slug,
            mc.description as category_description
        FROM menu_items mi
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
        WHERE mi.is_active = 1
    ";
    
    if ($id) {
        $query .= " AND mi.id = :id";
        $params[':id'] = $id;
    } else {
        $query .= " AND mi.uuid = :uuid";
        $params[':uuid'] = $uuid;
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        $response['message'] = 'Menu item not found';
        http_response_code(404);
        return;
    }
    
    // Parse JSON fields
    $item['tags'] = !empty($item['tags']) ? json_decode($item['tags'], true) : [];
    $item['dietary'] = !empty($item['dietary']) ? json_decode($item['dietary'], true) : [];
    $item['allergens'] = !empty($item['allergens']) ? json_decode($item['allergens'], true) : [];
    $item['customization_options'] = !empty($item['customization_options']) ? json_decode($item['customization_options'], true) : [];
    $item['nutritional_info'] = !empty($item['nutritional_info']) ? json_decode($item['nutritional_info'], true) : [];
    $item['metadata'] = !empty($item['metadata']) ? json_decode($item['metadata'], true) : [];
    
    // Calculate display price
    $item['display_price'] = !empty($item['discounted_price']) ? floatval($item['discounted_price']) : floatval($item['price']);
    $item['original_price'] = !empty($item['discounted_price']) ? floatval($item['price']) : null;
    
    if ($item['original_price'] && $item['display_price'] < $item['original_price']) {
        $discount = $item['original_price'] - $item['display_price'];
        $item['discount_percentage'] = round(($discount / $item['original_price']) * 100);
    } else {
        $item['discount_percentage'] = null;
    }
    
    $response['success'] = true;
    $response['message'] = 'Menu item retrieved successfully';
    $response['data'] = $item;
}

/**
 * Handle items by restaurant
 */
function handleItemsByRestaurant($db, &$response) {
    $restaurant_id = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : null;
    $restaurant_slug = isset($_GET['restaurant_slug']) ? $_GET['restaurant_slug'] : null;
    
    if (!$restaurant_id && !$restaurant_slug) {
        $response['message'] = 'Restaurant ID or slug is required';
        http_response_code(400);
        return;
    }
    
    $query = "
        SELECT 
            mi.*,
            mc.name as category_name,
            mc.slug as category_slug,
            mc.description as category_description,
            mc.display_order as category_display_order
        FROM menu_items mi
        LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
        WHERE mi.is_active = 1 
        AND mi.in_stock = 1
    ";
    
    $params = [];
    
    if ($restaurant_id) {
        $query .= " AND mi.restaurant_id = :restaurant_id";
        $params[':restaurant_id'] = $restaurant_id;
    } else {
        // Get restaurant ID from slug first
        $restaurant_query = "SELECT id FROM restaurants WHERE slug = :slug AND status = 'active'";
        $restaurant_stmt = $db->prepare($restaurant_query);
        $restaurant_stmt->bindValue(':slug', $restaurant_slug);
        $restaurant_stmt->execute();
        $restaurant = $restaurant_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$restaurant) {
            $response['message'] = 'Restaurant not found';
            http_response_code(404);
            return;
        }
        
        $query .= " AND mi.restaurant_id = :restaurant_id";
        $params[':restaurant_id'] = $restaurant['id'];
    }
    
    $query .= " ORDER BY 
        mc.display_order ASC, 
        mi.display_order ASC, 
        mi.is_popular DESC, 
        mi.name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by category
    $categories = [];
    foreach ($items as $item) {
        $category_id = $item['category_id'] ?: 0;
        $category_name = $item['category_name'] ?: 'Uncategorized';
        $category_slug = $item['category_slug'] ?: 'uncategorized';
        
        if (!isset($categories[$category_id])) {
            $categories[$category_id] = [
                'id' => $category_id,
                'name' => $category_name,
                'slug' => $category_slug,
                'description' => $item['category_description'],
                'display_order' => $item['category_display_order'],
                'items' => []
            ];
        }
        
        // Parse JSON fields
        $item['tags'] = !empty($item['tags']) ? json_decode($item['tags'], true) : [];
        $item['dietary'] = !empty($item['dietary']) ? json_decode($item['dietary'], true) : [];
        $item['allergens'] = !empty($item['allergens']) ? json_decode($item['allergens'], true) : [];
        $item['customization_options'] = !empty($item['customization_options']) ? json_decode($item['customization_options'], true) : [];
        
        // Calculate display price
        $item['display_price'] = !empty($item['discounted_price']) ? floatval($item['discounted_price']) : floatval($item['price']);
        $item['original_price'] = !empty($item['discounted_price']) ? floatval($item['price']) : null;
        
        if ($item['original_price'] && $item['display_price'] < $item['original_price']) {
            $discount = $item['original_price'] - $item['display_price'];
            $item['discount_percentage'] = round(($discount / $item['original_price']) * 100);
        } else {
            $item['discount_percentage'] = null;
        }
        
        $categories[$category_id]['items'][] = $item;
    }
    
    // Sort categories by display order
    usort($categories, function($a, $b) {
        return $a['display_order'] <=> $b['display_order'];
    });
    
    $response['success'] = true;
    $response['message'] = 'Menu items retrieved successfully';
    $response['data'] = [
        'categories' => $categories,
        'total_items' => count($items)
    ];
}

/**
 * Handle items by category
 */
function handleItemsByCategory($db, &$response) {
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $category_slug = isset($_GET['category_slug']) ? $_GET['category_slug'] : null;
    
    if (!$category_id && !$category_slug) {
        $response['message'] = 'Category ID or slug is required';
        http_response_code(400);
        return;
    }
    
    $query = "
        SELECT 
            mi.*,
            r.name as restaurant_name,
            r.rating as restaurant_rating,
            r.delivery_fee,
            r.delivery_time,
            mc.name as category_name
        FROM menu_items mi
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
        WHERE mi.is_active = 1 
        AND mi.in_stock = 1
        AND r.status = 'active'
    ";
    
    $params = [];
    
    if ($category_id) {
        $query .= " AND mi.category_id = :category_id";
        $params[':category_id'] = $category_id;
    } else {
        $query .= " AND mc.slug = :category_slug";
        $params[':category_slug'] = $category_slug;
    }
    
    $query .= " ORDER BY mi.is_popular DESC, mi.rating DESC, mi.name ASC LIMIT 50";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['success'] = true;
    $response['message'] = 'Menu items retrieved successfully';
    $response['data'] = ['items' => $items];
}

/**
 * Handle search items
 */
function handleSearchItems($db, &$response) {
    $search = isset($_GET['query']) ? trim($_GET['query']) : null;
    $restaurant_id = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : null;
    
    if (!$search || strlen($search) < 2) {
        $response['message'] = 'Search query must be at least 2 characters';
        http_response_code(400);
        return;
    }
    
    $query = "
        SELECT 
            mi.*,
            r.name as restaurant_name,
            r.rating as restaurant_rating,
            r.delivery_fee,
            r.delivery_time,
            mc.name as category_name
        FROM menu_items mi
        LEFT JOIN restaurants r ON mi.restaurant_id = r.id
        LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
        WHERE mi.is_active = 1 
        AND mi.in_stock = 1
        AND r.status = 'active'
        AND (
            mi.name LIKE :search 
            OR mi.description LIKE :search 
            OR mi.tags LIKE :search
            OR r.name LIKE :search
            OR mc.name LIKE :search
        )
    ";
    
    $params = [':search' => "%$search%"];
    
    if ($restaurant_id) {
        $query .= " AND mi.restaurant_id = :restaurant_id";
        $params[':restaurant_id'] = $restaurant_id;
    }
    
    $query .= " ORDER BY 
        CASE 
            WHEN mi.name LIKE :exact_search THEN 1
            WHEN mi.tags LIKE :search_tags THEN 2
            ELSE 3
        END,
        mi.rating DESC
        LIMIT 30";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':search', "%$search%");
    $stmt->bindValue(':exact_search', "$search%");
    $stmt->bindValue(':search_tags', "%\"$search\"%");
    
    if ($restaurant_id) {
        $stmt->bindValue(':restaurant_id', $restaurant_id, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['success'] = true;
    $response['message'] = 'Search results retrieved successfully';
    $response['data'] = [
        'items' => $items,
        'query' => $search,
        'total' => count($items)
    ];
}

/**
 * Handle toggle favorite (restaurant)
 */
function handleToggleFavorite($db, &$response) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'Authentication required';
        http_response_code(401);
        return;
    }
    
    $user_id = $_SESSION['user_id'];
    $restaurant_id = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : null;
    
    if (!$restaurant_id) {
        $response['message'] = 'Restaurant ID is required';
        http_response_code(400);
        return;
    }
    
    // Check if already favorited
    $check_query = "SELECT id FROM user_favorites WHERE user_id = :user_id AND restaurant_id = :restaurant_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $check_stmt->bindValue(':restaurant_id', $restaurant_id, PDO::PARAM_INT);
    $check_stmt->execute();
    
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Remove from favorites
        $delete_query = "DELETE FROM user_favorites WHERE id = :id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindValue(':id', $existing['id'], PDO::PARAM_INT);
        $delete_stmt->execute();
        
        $isFavorite = false;
    } else {
        // Add to favorites
        $insert_query = "INSERT INTO user_favorites (user_id, restaurant_id, created_at) VALUES (:user_id, :restaurant_id, NOW())";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $insert_stmt->bindValue(':restaurant_id', $restaurant_id, PDO::PARAM_INT);
        $insert_stmt->execute();
        
        $isFavorite = true;
    }
    
    $response['success'] = true;
    $response['message'] = $isFavorite ? 'Added to favorites' : 'Removed from favorites';
    $response['data'] = ['isFavorite' => $isFavorite];
}

/**
 * Handle check favorite (restaurant)
 */
function handleCheckFavorite($db, &$response) {
    if (!isset($_SESSION['user_id'])) {
        $response['data'] = ['isFavorite' => false];
        $response['success'] = true;
        return;
    }
    
    $user_id = $_SESSION['user_id'];
    $restaurant_id = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : null;
    
    if (!$restaurant_id) {
        $response['message'] = 'Restaurant ID is required';
        http_response_code(400);
        return;
    }
    
    $query = "SELECT id FROM user_favorites WHERE user_id = :user_id AND restaurant_id = :restaurant_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':restaurant_id', $restaurant_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $isFavorite = $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    
    $response['success'] = true;
    $response['message'] = 'Favorite status retrieved';
    $response['data'] = ['isFavorite' => $isFavorite];
}

/**
 * Handle get categories
 */
function handleGetCategories($db, &$response) {
    $restaurant_id = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : null;
    
    if ($restaurant_id) {
        // Get categories for specific restaurant
        $query = "
            SELECT DISTINCT 
                mc.id,
                mc.name,
                mc.slug,
                mc.description,
                mc.display_order,
                COUNT(mi.id) as item_count
            FROM merchant_categories mc
            LEFT JOIN menu_items mi ON mc.id = mi.category_id AND mi.is_active = 1 AND mi.in_stock = 1
            WHERE mc.restaurant_id = :restaurant_id 
            AND mc.is_active = 1
            GROUP BY mc.id, mc.name, mc.slug, mc.description, mc.display_order
            ORDER BY mc.display_order ASC, mc.name ASC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':restaurant_id', $restaurant_id, PDO::PARAM_INT);
    } else {
        // Get all unique categories
        $query = "
            SELECT DISTINCT 
                mc.name as category,
                mc.slug,
                COUNT(mi.id) as item_count
            FROM merchant_categories mc
            LEFT JOIN menu_items mi ON mc.id = mi.category_id AND mi.is_active = 1 AND mi.in_stock = 1
            WHERE mc.is_active = 1
            GROUP BY mc.name, mc.slug
            ORDER BY mc.name ASC
        ";
        
        $stmt = $db->prepare($query);
    }
    
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['success'] = true;
    $response['message'] = 'Categories retrieved successfully';
    $response['data'] = ['categories' => $categories];
}

/**
 * Handle get restaurant categories
 */
function handleRestaurantCategories($db, &$response) {
    $query = "
        SELECT 
            r.main_category,
            COUNT(DISTINCT r.id) as restaurant_count,
            COUNT(mi.id) as item_count
        FROM restaurants r
        LEFT JOIN menu_items mi ON r.id = mi.restaurant_id AND mi.is_active = 1 AND mi.in_stock = 1
        WHERE r.status = 'active'
        AND r.is_active = 1
        AND r.main_category IS NOT NULL
        GROUP BY r.main_category
        ORDER BY restaurant_count DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['success'] = true;
    $response['message'] = 'Restaurant categories retrieved successfully';
    $response['data'] = ['categories' => $categories];
}
?>