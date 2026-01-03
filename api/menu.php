<?php
// api/menu.php - Menu Items API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
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

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($method === 'GET') {
        $merchantId = $_GET['merchant_id'] ?? '';
        $category = $_GET['category'] ?? 'all';
        $search = $_GET['search'] ?? '';
        $sortBy = $_GET['sort'] ?? 'recommended';
        $minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : null;
        $maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : null;
        $organic = isset($_GET['organic']) ? intval($_GET['organic']) : null;
        $inStock = isset($_GET['in_stock']) ? intval($_GET['in_stock']) : 1;
        $popular = isset($_GET['popular']) ? intval($_GET['popular']) : null;
        $featured = isset($_GET['featured']) ? intval($_GET['featured']) : null;
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        
        if (!$merchantId) {
            jsonResponse(false, null, 'Merchant ID is required', 400);
        }
        
        // First, check if restaurant exists and is active
        $restaurantCheck = "
            SELECT id, name, is_active, status 
            FROM restaurants 
            WHERE id = :merchant_id 
            AND (is_active = 1 OR status = 'active')
        ";
        
        $checkStmt = $conn->prepare($restaurantCheck);
        $checkStmt->execute([':merchant_id' => $merchantId]);
        $restaurant = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$restaurant) {
            jsonResponse(false, null, 'Restaurant not found or inactive', 404);
        }
        
        // Build WHERE clause
        $where = ["mi.restaurant_id = :merchant_id", "mi.is_active = 1"];
        $params = [':merchant_id' => $merchantId];
        
        if ($category !== 'all') {
            // First check if categories table exists
            $tableCheck = "
                SELECT COUNT(*) as table_exists 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name IN ('categories', 'menu_categories', 'merchant_categories')
            ";
            
            $tableStmt = $conn->query($tableCheck);
            $tableResult = $tableStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tableResult['table_exists'] > 0) {
                // Try to find the correct table name
                $tableName = '';
                $tables = ['categories', 'menu_categories', 'merchant_categories'];
                
                foreach ($tables as $table) {
                    $check = $conn->query("SHOW TABLES LIKE '$table'");
                    if ($check->rowCount() > 0) {
                        $tableName = $table;
                        break;
                    }
                }
                
                if ($tableName) {
                    $where[] = "mc.slug = :category OR mc.name = :category";
                    $params[':category'] = $category;
                } else {
                    // If no categories table, just skip category filter
                    error_log("Categories table not found, skipping category filter");
                }
            }
        }
        
        if (!empty($search)) {
            $where[] = "(mi.name LIKE :search OR mi.description LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        
        if ($minPrice !== null) {
            $where[] = "mi.price >= :min_price";
            $params[':min_price'] = $minPrice;
        }
        
        if ($maxPrice !== null) {
            $where[] = "mi.price <= :max_price";
            $params[':max_price'] = $maxPrice;
        }
        
        if ($organic !== null) {
            $where[] = "mi.is_organic = :organic";
            $params[':organic'] = $organic;
        }
        
        if ($inStock !== null) {
            $where[] = "mi.in_stock = :in_stock";
            $params[':in_stock'] = $inStock;
        }
        
        if ($popular !== null) {
            $where[] = "mi.is_popular = :popular";
            $params[':popular'] = $popular;
        }
        
        if ($featured !== null) {
            $where[] = "mi.is_featured = :featured";
            $params[':featured'] = $featured;
        }
        
        $whereClause = "WHERE " . implode(' AND ', $where);
        
        // Get total count
        $countQuery = "
            SELECT COUNT(*) as total 
            FROM menu_items mi
            $whereClause
        ";
        
        $countStmt = $conn->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetch()['total'];
        
        // Build ORDER BY
        $orderBy = "ORDER BY ";
        switch ($sortBy) {
            case 'price-low':
                $orderBy .= "mi.price ASC";
                break;
            case 'price-high':
                $orderBy .= "mi.price DESC";
                break;
            case 'rating':
                $orderBy .= "mi.rating DESC, mi.review_count DESC";
                break;
            case 'name':
                $orderBy .= "mi.name ASC";
                break;
            case 'popular':
                $orderBy .= "mi.is_popular DESC, mi.rating DESC";
                break;
            case 'newest':
                $orderBy .= "mi.created_at DESC";
                break;
            default: // recommended
                $orderBy .= "mi.display_order ASC, mi.is_popular DESC, mi.is_signature DESC, mi.rating DESC";
        }
        
        // Get menu items
        $query = "
            SELECT 
                mi.*
            FROM menu_items mi
            $whereClause
            $orderBy
            LIMIT :limit OFFSET :offset
        ";
        
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process items
        $processedItems = array_map(function($item) {
            $tags = json_decode($item['tags'] ?? '[]', true);
            $displayTags = [];
            
            if ($item['is_popular']) {
                $displayTags[] = '🔥 Popular';
                if (!in_array('🔥 Popular', $tags)) {
                    $tags[] = '🔥 Popular';
                }
            }
            if ($item['is_signature']) {
                $displayTags[] = '⭐ Signature';
                if (!in_array('⭐ Signature', $tags)) {
                    $tags[] = '⭐ Signature';
                }
            }
            if ($item['is_healthy']) {
                $displayTags[] = '🌿 Healthy';
                if (!in_array('🌿 Healthy', $tags)) {
                    $tags[] = '🌿 Healthy';
                }
            }
            if ($item['is_organic']) {
                $displayTags[] = '🥕 Organic';
                if (!in_array('🥕 Organic', $tags)) {
                    $tags[] = '🥕 Organic';
                }
            }
            
            return [
                'id' => (int) $item['id'],  // Use numeric ID for cart compatibility
                'uuid' => $item['uuid'] ?? $item['id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'price' => (float) $item['price'],
                'discountedPrice' => $item['discounted_price'] ? (float) $item['discounted_price'] : null,
                'finalPrice' => $item['discounted_price'] ? (float) $item['discounted_price'] : (float) $item['price'],
                'image' => $item['image_url'] ?: 'default-menu-item.jpg',
                'categoryId' => $item['category_id'],
                'tags' => $tags,
                'displayTags' => array_merge($displayTags, $tags),
                'prepTime' => $item['prep_time'],
                'calories' => $item['calories'] ? (int) $item['calories'] : null,
                'rating' => round($item['rating'], 1),
                'reviewCount' => (int) $item['review_count'],
                'unit' => $item['unit'],
                'inStock' => (bool) $item['in_stock'],
                'isOrganic' => (bool) $item['is_organic'],
                'isPopular' => (bool) $item['is_popular'],
                'isSignature' => (bool) $item['is_signature'],
                'isHealthy' => (bool) $item['is_healthy'],
                'dietary' => json_decode($item['dietary'] ?? '[]', true),
                'allergens' => json_decode($item['allergens'] ?? '[]', true),
                'customizationOptions' => json_decode($item['customization_options'] ?? '[]', true),
                'nutritionalInfo' => json_decode($item['nutritional_info'] ?? '{}', true),
                'displayOrder' => (int) $item['display_order'],
                'createdAt' => $item['created_at'],
                'restaurantId' => (int) $item['restaurant_id']  // Important for cart compatibility
            ];
        }, $items);
        
        // Get categories if table exists
        $categories = [];
        try {
            // Check if categories table exists
            $checkCat = $conn->query("SHOW TABLES LIKE 'categories'");
            if ($checkCat->rowCount() > 0) {
                $categoriesQuery = "
                    SELECT 
                        id, 
                        name, 
                        slug, 
                        description, 
                        display_order, 
                        is_active,
                        (SELECT COUNT(*) FROM menu_items WHERE category_id = c.id AND is_active = 1) as item_count
                    FROM categories c
                    WHERE restaurant_id = :merchant_id 
                    ORDER BY display_order ASC, name ASC
                ";
                
                $catStmt = $conn->prepare($categoriesQuery);
                $catStmt->execute([':merchant_id' => $merchantId]);
                $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            // Categories table doesn't exist or has different structure
            error_log("Categories not available: " . $e->getMessage());
            $categories = [];
        }
        
        $formattedCategories = array_map(function($cat) {
            return [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'slug' => $cat['slug'],
                'description' => $cat['description'],
                'itemCount' => (int) $cat['item_count'],
                'displayOrder' => (int) $cat['display_order'],
                'isActive' => (bool) $cat['is_active']
            ];
        }, $categories);
        
        // Group items by category
        $groupedItems = [];
        foreach ($processedItems as $item) {
            $catId = $item['categoryId'] ?: 0;
            $catName = 'All Items';
            
            if ($catId) {
                foreach ($formattedCategories as $cat) {
                    if ($cat['id'] == $catId) {
                        $catName = $cat['name'];
                        break;
                    }
                }
            }
            
            if (!isset($groupedItems[$catId])) {
                $groupedItems[$catId] = [
                    'id' => $catId,
                    'name' => $catName,
                    'slug' => $catId ? 'category-' . $catId : 'all',
                    'items' => []
                ];
            }
            $groupedItems[$catId]['items'][] = $item;
        }
        
        jsonResponse(true, [
            'restaurant' => [
                'id' => (int) $restaurant['id'],
                'name' => $restaurant['name'],
                'isActive' => (bool) $restaurant['is_active'],
                'status' => $restaurant['status']
            ],
            'items' => $processedItems,
            'groupedItems' => array_values($groupedItems),
            'categories' => $formattedCategories,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $total,
                'pages' => ceil($total / $limit)
            ],
            'filters' => [
                'category' => $category,
                'search' => $search,
                'sort' => $sortBy
            ]
        ], 'Menu items retrieved successfully');
        
    } else {
        jsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Menu API Error: " . $e->getMessage());
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}
?>