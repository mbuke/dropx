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
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx-production-6373.up.railway.app";

/*********************************
 * INITIALIZATION & HELPER FUNCTIONS
 *********************************/
function initDatabase() {
    $db = new Database();
    return $db->getConnection();
}

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

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
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx-production-6373.up.railway.app";

/*********************************
 * INITIALIZATION & HELPER FUNCTIONS
 *********************************/
function initDatabase() {
    $db = new Database();
    return $db->getConnection();
}

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * ROUTER - FIXED PATH PARSING
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $queryString = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    
    // Parse query parameters
    parse_str($queryString ?? '', $queryParams);
    
    // Debug: Log the full path
    error_log("FULL PATH: " . $path);
    error_log("SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME']);
    
    // Remove the script name (merchants.php) from the path
    $scriptName = basename($_SERVER['SCRIPT_NAME']); // merchants.php
    $basePath = dirname($_SERVER['SCRIPT_NAME']); // directory containing merchants.php
    
    // Extract the path after the script name
    if (strpos($path, $scriptName) !== false) {
        // Path contains merchants.php
        $relativePath = substr($path, strpos($path, $scriptName) + strlen($scriptName));
        $pathParts = explode('/', trim($relativePath, '/'));
    } else {
        // Path doesn't contain script name
        $pathParts = explode('/', trim($path, '/'));
    }
    
    // Remove empty parts
    $pathParts = array_filter($pathParts, function($part) {
        return $part !== '';
    });
    
    // Re-index array
    $pathParts = array_values($pathParts);
    
    error_log("PATH PARTS: " . json_encode($pathParts));
    error_log("METHOD: " . $method);
    error_log("FULL URI: " . $_SERVER['REQUEST_URI']);
    
    // Initialize database connection
    $conn = initDatabase();
    $baseUrl = getBaseUrl();
    
    // Route the request
    if ($method === 'GET') {
        // If no path parts, it's /merchants.php
        if (empty($pathParts)) {
            getMerchantsList($conn, $baseUrl);
            exit();
        }
        
        // If first part is a number, it's /merchants.php/{id}
        if (is_numeric($pathParts[0])) {
            $merchantId = intval($pathParts[0]);
            
            // If there's a second part, it's an action like /merchants.php/{id}/menu
            if (isset($pathParts[1])) {
                $action = $pathParts[1];
                
                switch ($action) {
                    case 'menu':
                        $includeQuickOrders = isset($queryParams['include_quick_orders']) 
                            ? filter_var($queryParams['include_quick_orders'], FILTER_VALIDATE_BOOLEAN)
                            : true;
                        getMerchantMenu($conn, $merchantId, $baseUrl, $includeQuickOrders);
                        exit();
                        
                    case 'categories':
                        getMerchantCategories($conn, $merchantId, $baseUrl);
                        exit();
                        
                    case 'quick-orders':
                        getMerchantQuickOrders($conn, $merchantId, $baseUrl);
                        exit();
                        
                    default:
                        ResponseHandler::error('Invalid action specified', 400);
                }
            } else {
                // No action, just merchant details: /merchants.php/{id}
                getMerchantDetails($conn, $merchantId, $baseUrl);
                exit();
            }
        }
        
        // If we get here, no route matched
        ResponseHandler::error('Endpoint not found', 404);
        
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Router Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET MERCHANTS LIST - FIXED RESPONSE STRUCTURE
 *********************************/
function getMerchantsList($conn, $baseUrl) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'rating';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $minRating = floatval($_GET['min_rating'] ?? 0);
    $isOpen = $_GET['is_open'] ?? null;
    $isPromoted = $_GET['is_promoted'] ?? null;
    $businessType = $_GET['business_type'] ?? '';
    $itemType = $_GET['item_type'] ?? '';
    $deliveryFee = $_GET['delivery_fee'] ?? null;

    $whereConditions = ["m.is_active = 1"];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "m.category = :category";
        $params[':category'] = $category;
    }

    if ($businessType) {
        $whereConditions[] = "m.business_type = :business_type";
        $params[':business_type'] = $businessType;
    }

    if ($itemType) {
        $whereConditions[] = "JSON_CONTAINS(m.item_types, :item_type)";
        $params[':item_type'] = json_encode($itemType);
    }

    if ($search) {
        $whereConditions[] = "(m.name LIKE :search OR m.category LIKE :search OR m.tags LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($minRating > 0) {
        $whereConditions[] = "m.rating >= :min_rating";
        $params[':min_rating'] = $minRating;
    }

    if ($isOpen !== null) {
        $whereConditions[] = "m.is_open = :is_open";
        $params[':is_open'] = $isOpen === 'true' ? 1 : 0;
    }

    if ($isPromoted !== null) {
        $whereConditions[] = "m.is_promoted = :is_promoted";
        $params[':is_promoted'] = $isPromoted === 'true' ? 1 : 0;
    }

    if ($deliveryFee !== null) {
        if ($deliveryFee === 'free') {
            $whereConditions[] = "m.delivery_fee = 0";
        } elseif ($deliveryFee === 'paid') {
            $whereConditions[] = "m.delivery_fee > 0";
        }
    }

    $whereClause = count($whereConditions) > 0 ? "WHERE " . implode(" AND ", $whereConditions) : "";

    $allowedSortColumns = ['rating', 'review_count', 'name', 'delivery_fee', 'created_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'rating';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.business_type,
                m.item_types,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.is_open,
                m.is_promoted,
                m.delivery_fee,
                m.min_order_amount,
                m.delivery_radius,
                m.created_at,
                m.updated_at
            FROM merchants m
            $whereClause
            ORDER BY m.is_promoted DESC, m.$sortBy $sortOrder
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
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryStmt = $conn->prepare(
        "SELECT DISTINCT category FROM merchants WHERE category IS NOT NULL AND category != '' ORDER BY category"
    );
    $categoryStmt->execute();
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    $businessTypeStmt = $conn->prepare(
        "SELECT DISTINCT business_type FROM merchants WHERE business_type IS NOT NULL ORDER BY business_type"
    );
    $businessTypeStmt->execute();
    $availableBusinessTypes = $businessTypeStmt->fetchAll(PDO::FETCH_COLUMN);

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'filters' => [
            'available_categories' => $availableCategories,
            'available_business_types' => $availableBusinessTypes,
            'business_type_counts' => getBusinessTypeCounts($conn)
        ]
    ]);
}

function getBusinessTypeCounts($conn) {
    $counts = [];
    
    $stmt = $conn->prepare(
        "SELECT 
            business_type,
            COUNT(*) as count
        FROM merchants 
        WHERE business_type IS NOT NULL
        AND is_active = 1
        GROUP BY business_type"
    );
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $row) {
        $counts[$row['business_type']] = intval($row['count']);
    }
    
    return $counts;
}

/*********************************
 * GET MERCHANT DETAILS
 *********************************/
function getMerchantDetails($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.description,
            m.category,
            m.business_type,
            m.item_types,
            m.rating,
            m.review_count,
            CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
            m.image_url,
            m.logo_url,
            m.is_open,
            m.is_promoted,
            m.address,
            m.phone,
            m.email,
            m.latitude,
            m.longitude,
            m.operating_hours,
            m.tags,
            m.payment_methods,
            m.delivery_fee,
            m.min_order_amount,
            m.delivery_radius,
            m.delivery_time,
            m.created_at,
            m.updated_at
        FROM merchants m
        WHERE m.id = :id AND m.is_active = 1"
    );
    
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $reviewsStmt = $conn->prepare(
        "SELECT 
            mr.id,
            mr.user_id,
            u.full_name as user_name,
            u.avatar as user_avatar,
            mr.rating,
            mr.comment,
            mr.created_at
        FROM merchant_reviews mr
        LEFT JOIN users u ON mr.user_id = u.id
        WHERE mr.merchant_id = :merchant_id
        ORDER BY mr.created_at DESC
        LIMIT 10"
    );
    
    $reviewsStmt->execute([':merchant_id' => $merchantId]);
    $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

    $categoriesStmt = $conn->prepare(
        "SELECT DISTINCT 
            category as name,
            COUNT(*) as item_count
        FROM menu_items 
        WHERE merchant_id = :merchant_id
        AND is_active = 1
        AND is_available = 1
        GROUP BY category
        ORDER BY category
        LIMIT 10"
    );
    
    $categoriesStmt->execute([':merchant_id' => $merchantId]);
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    $popularItemsStmt = $conn->prepare(
        "SELECT 
            mi.id,
            mi.name,
            mi.description,
            mi.price,
            mi.image_url,
            mi.category,
            mi.item_type,
            mi.unit_type,
            mi.unit_value,
            mi.is_available,
            mi.is_popular
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        AND mi.is_popular = 1
        AND mi.is_active = 1
        AND mi.is_available = 1
        ORDER BY mi.display_order, mi.name
        LIMIT 6"
    );
    
    $popularItemsStmt->execute([':merchant_id' => $merchantId]);
    $popularMenuItems = $popularItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $quickOrdersStmt = $conn->prepare(
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
        INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
        WHERE qom.merchant_id = :merchant_id
        AND qom.is_active = 1
        ORDER BY qom.priority DESC, qo.order_count DESC
        LIMIT 6"
    );
    
    $quickOrdersStmt->execute([':merchant_id' => $merchantId]);
    $quickOrders = $quickOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

    $isFavorite = false;
    if (!empty($_SESSION['user_id'])) {
        $favStmt = $conn->prepare(
            "SELECT id FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $favStmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':merchant_id' => $merchantId
        ]);
        $isFavorite = $favStmt->fetch() ? true : false;
    }

    $merchantData = formatMerchantDetailData($merchant, $baseUrl);
    $merchantData['reviews'] = array_map('formatReviewData', $reviews);
    $merchantData['is_favorite'] = $isFavorite;
    
    $merchantData['menu_summary'] = [
        'categories' => array_map(function($cat) {
            return [
                'name' => $cat['name'],
                'item_count' => intval($cat['item_count'])
            ];
        }, $categories),
        'popular_items' => array_map(function($item) use ($baseUrl) {
            return formatMenuItemData($item, $baseUrl);
        }, $popularMenuItems),
        'quick_orders' => array_map(function($order) use ($baseUrl) {
            return formatQuickOrderForMerchant($order, $baseUrl);
        }, $quickOrders),
        'has_menu' => !empty($categories) || !empty($popularMenuItems)
    ];

    ResponseHandler::success([
        'merchant' => $merchantData
    ]);
}

/*********************************
 * GET MERCHANT MENU - FIXED RESPONSE STRUCTURE
 *********************************/
function getMerchantMenu($conn, $merchantId, $baseUrl, $includeQuickOrders = true) {
    error_log("DEBUG - Getting menu for merchant ID: " . $merchantId);
    
    $checkStmt = $conn->prepare(
        "SELECT id, name, business_type FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $quickOrderItems = [];
    if ($includeQuickOrders) {
        $quickOrderStmt = $conn->prepare(
            "SELECT 
                qoi.id as quick_order_item_id,
                qoi.name,
                qoi.description,
                qoi.price,
                qoi.image_url,
                qoi.unit_type,
                qoi.unit_value,
                qoi.is_available,
                qo.category,
                qo.item_type,
                'quick_order' as source
            FROM quick_order_items qoi
            JOIN quick_orders qo ON qoi.quick_order_id = qo.id
            JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
            WHERE qom.merchant_id = :merchant_id
            AND qom.is_active = 1
            AND qoi.is_available = 1
            ORDER BY qo.category, qoi.name"
        );
        $quickOrderStmt->execute([':merchant_id' => $merchantId]);
        $quickOrderItems = $quickOrderStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("DEBUG - Found " . count($quickOrderItems) . " quick order items");
    }

    // Get categories from menu items
    $categoriesStmt = $conn->prepare(
        "SELECT DISTINCT 
            category as name,
            COUNT(*) as item_count
        FROM menu_items 
        WHERE merchant_id = :merchant_id
        AND is_active = 1
        AND is_available = 1
        AND category IS NOT NULL
        AND category != ''
        GROUP BY category
        ORDER BY category ASC"
    );
    
    $categoriesStmt->execute([':merchant_id' => $merchantId]);
    $uniqueCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG - Found " . count($uniqueCategories) . " categories from menu items");
    
    $displayOrder = 1;
    $categories = [];
    foreach ($uniqueCategories as $cat) {
        $categories[] = [
            'id' => 0,
            'name' => $cat['name'],
            'description' => '',
            'image_url' => null,
            'display_order' => $displayOrder++,
            'item_count' => intval($cat['item_count']),
            'is_quick_order' => false
        ];
    }

    // Add quick order categories
    if (!empty($quickOrderItems)) {
        $quickOrderCategories = [];
        foreach ($quickOrderItems as $item) {
            $catName = $item['category'] ?: 'Quick Orders';
            if (!isset($quickOrderCategories[$catName])) {
                $quickOrderCategories[$catName] = 0;
            }
            $quickOrderCategories[$catName]++;
        }
        
        foreach ($quickOrderCategories as $catName => $count) {
            $categories[] = [
                'id' => -1,
                'name' => $catName . ' (Quick Order)',
                'description' => 'Pre-configured quick orders',
                'image_url' => null,
                'display_order' => 999,
                'item_count' => $count,
                'is_quick_order' => true
            ];
        }
    }

    // Get menu items
    $menuStmt = $conn->prepare(
        "SELECT 
            mi.id,
            mi.name,
            mi.description,
            mi.price,
            mi.image_url,
            mi.category,
            mi.item_type,
            mi.subcategory,
            mi.unit_type,
            mi.unit_value,
            mi.is_available,
            mi.is_popular,
            mi.display_order,
            mi.options,
            mi.ingredients,
            mi.allergens,
            mi.nutrition_info,
            mi.preparation_time,
            mi.stock_quantity,
            mi.created_at,
            mi.updated_at,
            'menu' as source
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        AND mi.is_active = 1
        AND mi.is_available = 1
        ORDER BY mi.category ASC, mi.display_order ASC, mi.name ASC"
    );
    
    $menuStmt->execute([':merchant_id' => $merchantId]);
    $menuItems = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG - Found " . count($menuItems) . " menu items");

    $allItems = array_merge($menuItems, $quickOrderItems);
    error_log("DEBUG - Total items: " . count($allItems));

    // Organize items by category
    $itemsByCategory = [];
    
    // Initialize categories
    foreach ($categories as $category) {
        $categoryName = $category['name'];
        $itemsByCategory[$categoryName] = [
            'category_info' => formatCategoryData($category, $baseUrl),
            'items' => []
        ];
    }
    
    // Add uncategorized category
    $itemsByCategory['Uncategorized'] = [
        'category_info' => [
            'id' => 0,
            'name' => 'Uncategorized',
            'description' => 'Items without category',
            'image_url' => null,
            'display_order' => 9999,
            'item_count' => 0,
            'is_quick_order' => false,
            'item_types' => []
        ],
        'items' => []
    ];

    // Distribute items to categories
    foreach ($allItems as $item) {
        $categoryName = $item['category'] ?: 'Uncategorized';
        
        // Handle quick order items
        if ($item['source'] === 'quick_order' && !empty($item['category'])) {
            $categoryName = $item['category'] . ' (Quick Order)';
        }
        
        // Create category if it doesn't exist
        if (!isset($itemsByCategory[$categoryName])) {
            $itemsByCategory[$categoryName] = [
                'category_info' => [
                    'id' => $item['source'] === 'quick_order' ? -1 : 0,
                    'name' => $categoryName,
                    'description' => $item['source'] === 'quick_order' ? 'Pre-configured quick orders' : '',
                    'image_url' => null,
                    'display_order' => $item['source'] === 'quick_order' ? 999 : 9999,
                    'item_count' => 0,
                    'is_quick_order' => $item['source'] === 'quick_order',
                    'item_types' => []
                ],
                'items' => []
            ];
        }
        
        $itemsByCategory[$categoryName]['items'][] = formatMenuItemData($item, $baseUrl);
    }

    // Clean up empty categories and update item counts
    foreach ($itemsByCategory as $categoryName => $data) {
        if (empty($data['items'])) {
            unset($itemsByCategory[$categoryName]);
        } else {
            $itemsByCategory[$categoryName]['category_info']['item_count'] = count($data['items']);
        }
    }

    // Sort categories by display order
    usort($itemsByCategory, function($a, $b) {
        return $a['category_info']['display_order'] <=> $b['category_info']['display_order'];
    });

    $organizedMenu = array_values($itemsByCategory);

    error_log("DEBUG - Organized menu has " . count($organizedMenu) . " categories");

    // Return the menu data with the correct structure
    ResponseHandler::success([
        'success' => true,
        'message' => 'Menu retrieved successfully',
        'data' => [
            'merchant_id' => $merchantId,
            'merchant_name' => $merchant['name'],
            'business_type' => $merchant['business_type'],
            'menu' => $organizedMenu,
            'total_items' => count($allItems),
            'total_categories' => count($organizedMenu),
            'includes_quick_orders' => !empty($quickOrderItems)
        ]
    ]);
}

/*********************************
 * GET MERCHANT CATEGORIES
 *********************************/
function getMerchantCategories($conn, $merchantId, $baseUrl) {
    $checkStmt = $conn->prepare(
        "SELECT id, name FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $categoriesStmt = $conn->prepare(
        "SELECT 
            category as name,
            COUNT(*) as item_count,
            GROUP_CONCAT(DISTINCT item_type) as item_types
        FROM menu_items 
        WHERE merchant_id = :merchant_id
        AND is_active = 1
        AND is_available = 1
        AND category IS NOT NULL
        AND category != ''
        GROUP BY category
        ORDER BY category ASC"
    );
    
    $categoriesStmt->execute([':merchant_id' => $merchantId]);
    $uniqueCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $displayOrder = 1;
    $categories = [];
    foreach ($uniqueCategories as $cat) {
        $itemTypes = !empty($cat['item_types']) ? explode(',', $cat['item_types']) : [];
        $categories[] = [
            'id' => 0,
            'name' => $cat['name'],
            'description' => '',
            'display_order' => $displayOrder++,
            'image_url' => null,
            'is_active' => true,
            'item_count' => intval($cat['item_count']),
            'item_types' => $itemTypes
        ];
    }

    $quickOrderCategoriesStmt = $conn->prepare(
        "SELECT 
            qo.category as name,
            COUNT(*) as item_count,
            GROUP_CONCAT(DISTINCT qo.item_type) as item_types
        FROM quick_orders qo
        INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
        WHERE qom.merchant_id = :merchant_id
        AND qom.is_active = 1
        GROUP BY qo.category
        ORDER BY qo.category ASC"
    );
    
    $quickOrderCategoriesStmt->execute([':merchant_id' => $merchantId]);
    $quickOrderCategories = $quickOrderCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($quickOrderCategories as $cat) {
        $itemTypes = !empty($cat['item_types']) ? explode(',', $cat['item_types']) : [];
        $categories[] = [
            'id' => -1,
            'name' => $cat['name'] . ' (Quick Order)',
            'description' => 'Pre-configured quick orders',
            'display_order' => 999,
            'image_url' => null,
            'is_active' => true,
            'item_count' => intval($cat['item_count']),
            'item_types' => $itemTypes,
            'is_quick_order' => true
        ];
    }

    $formattedCategories = array_map(function($cat) use ($baseUrl) {
        return formatCategoryData($cat, $baseUrl);
    }, $categories);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'categories' => $formattedCategories,
        'total_categories' => count($formattedCategories)
    ]);
}

/*********************************
 * GET MERCHANT QUICK ORDERS
 *********************************/
function getMerchantQuickOrders($conn, $merchantId, $baseUrl) {
    $checkStmt = $conn->prepare(
        "SELECT id, name FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $category = $_GET['category'] ?? '';
    $itemType = $_GET['item_type'] ?? '';

    $whereConditions = ["qom.merchant_id = :merchant_id", "qom.is_active = 1"];
    $params = [':merchant_id' => $merchantId];

    if ($category) {
        $whereConditions[] = "qo.category = :category";
        $params[':category'] = $category;
    }

    if ($itemType) {
        $whereConditions[] = "qo.item_type = :item_type";
        $params[':item_type'] = $itemType;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $countSql = "SELECT COUNT(*) as total 
                 FROM quick_orders qo
                 INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
                 $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.description,
                qo.category,
                qo.item_type,
                qo.image_url,
                qo.price,
                qo.rating,
                qo.order_count,
                qo.delivery_time,
                qom.priority,
                qom.custom_price,
                qom.custom_delivery_time
            FROM quick_orders qo
            INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
            $whereClause
            ORDER BY qom.priority DESC, qo.order_count DESC
            LIMIT :limit OFFSET :offset";

    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedOrders = array_map(function($order) use ($baseUrl) {
        return formatQuickOrderForMerchantList($order, $baseUrl);
    }, $quickOrders);

    $categoryStmt = $conn->prepare(
        "SELECT DISTINCT qo.category 
         FROM quick_orders qo
         INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
         WHERE qom.merchant_id = :merchant_id
         AND qom.is_active = 1
         ORDER BY qo.category"
    );
    $categoryStmt->execute([':merchant_id' => $merchantId]);
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'quick_orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'filters' => [
            'available_categories' => $availableCategories
        ]
    ]);
}

/*********************************
 * POST REQUEST HANDLER
 *********************************/
function handlePostRequest() {
    $conn = initDatabase();
    $baseUrl = getBaseUrl();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_review':
            createMerchantReview($conn, $input);
            break;
        case 'toggle_favorite':
            toggleMerchantFavorite($conn, $input);
            break;
        case 'get_favorites':
            getFavoriteMerchants($conn, $input, $baseUrl);
            break;
        case 'report_merchant':
            reportMerchant($conn, $input);
            break;
        case 'search_menu':
            searchMenuItems($conn, $input, $baseUrl);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

function createMerchantReview($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $existingStmt = $conn->prepare(
        "SELECT id FROM merchant_reviews 
         WHERE merchant_id = :merchant_id AND user_id = :user_id"
    );
    $existingStmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already reviewed this merchant', 409);
    }

    $stmt = $conn->prepare(
        "INSERT INTO merchant_reviews 
            (merchant_id, user_id, rating, comment, created_at)
         VALUES (:merchant_id, :user_id, :rating, :comment, NOW())"
    );
    
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId,
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    updateMerchantRating($conn, $merchantId);

    ResponseHandler::success([], 'Review submitted successfully');
}

function toggleMerchantFavorite($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $favStmt = $conn->prepare(
        "SELECT id FROM user_favorite_merchants 
         WHERE user_id = :user_id AND merchant_id = :merchant_id"
    );
    $favStmt->execute([
        ':user_id' => $userId,
        ':merchant_id' => $merchantId
    ]);
    
    if ($favStmt->fetch()) {
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        ResponseHandler::success(['is_favorite' => false], 'Removed from favorites');
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO user_favorite_merchants (user_id, merchant_id, created_at)
             VALUES (:user_id, :merchant_id, NOW())"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        ResponseHandler::success(['is_favorite' => true], 'Added to favorites');
    }
}

function getFavoriteMerchants($conn, $data, $baseUrl) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.business_type,
                m.item_types,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.is_open,
                m.is_promoted,
                m.delivery_fee,
                m.min_order_amount,
                m.created_at,
                ufm.created_at as favorited_at
            FROM merchants m
            INNER JOIN user_favorite_merchants ufm ON m.id = ufm.merchant_id
            WHERE ufm.user_id = :user_id
            AND m.is_active = 1
            ORDER BY ufm.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) as total 
         FROM user_favorite_merchants ufm
         JOIN merchants m ON ufm.merchant_id = m.id
         WHERE ufm.user_id = :user_id AND m.is_active = 1"
    );
    $countStmt->execute([':user_id' => $userId]);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

function reportMerchant($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;
    $reason = trim($data['reason'] ?? '');
    $details = trim($data['details'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if (!$reason) {
        ResponseHandler::error('Report reason is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $stmt = $conn->prepare(
        "INSERT INTO merchant_reports 
            (merchant_id, user_id, reason, details, status, created_at)
         VALUES (:merchant_id, :user_id, :reason, :details, 'pending', NOW())"
    );
    
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId,
        ':reason' => $reason,
        ':details' => $details
    ]);

    ResponseHandler::success([], 'Report submitted successfully');
}

function searchMenuItems($conn, $data, $baseUrl) {
    $merchantId = $data['merchant_id'] ?? null;
    $query = trim($data['query'] ?? '');
    $category = trim($data['category'] ?? '');
    $itemType = trim($data['item_type'] ?? '');
    $sortBy = $data['sort_by'] ?? 'name';
    $sortOrder = strtoupper($data['sort_order'] ?? 'ASC');
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $checkStmt = $conn->prepare(
        "SELECT id, name FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $whereConditions = ["mi.merchant_id = :merchant_id", "mi.is_active = 1"];
    $params = [':merchant_id' => $merchantId];

    if ($query) {
        $whereConditions[] = "(mi.name LIKE :query OR mi.description LIKE :query OR mi.ingredients LIKE :query)";
        $params[':query'] = "%$query%";
    }

    if ($category) {
        $whereConditions[] = "mi.category = :category";
        $params[':category'] = $category;
    }

    if ($itemType) {
        $whereConditions[] = "mi.item_type = :item_type";
        $params[':item_type'] = $itemType;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $allowedSortColumns = ['name', 'price', 'is_popular', 'created_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'name';
    $sortOrder = $sortOrder === 'DESC' ? 'DESC' : 'ASC';

    $countSql = "SELECT COUNT(*) as total FROM menu_items mi $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                mi.id,
                mi.name,
                mi.description,
                mi.price,
                mi.image_url,
                mi.category,
                mi.item_type,
                mi.unit_type,
                mi.unit_value,
                mi.is_available,
                mi.is_popular,
                mi.options,
                mi.ingredients,
                mi.preparation_time,
                mi.created_at,
                mi.updated_at
            FROM menu_items mi
            $whereClause
            ORDER BY mi.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedMenuItems = array_map(function($item) use ($baseUrl) {
        return formatMenuItemData($item, $baseUrl);
    }, $menuItems);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'menu_items' => $formattedMenuItems,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'search_params' => [
            'query' => $query,
            'category' => $category,
            'item_type' => $itemType,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder
        ]
    ]);
}

function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM merchant_reviews
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

/*********************************
 * FORMATTING FUNCTIONS
 *********************************/
function formatMerchantListData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . $m['image_url'];
        }
    }
    
    $itemTypes = [];
    if (!empty($m['item_types'])) {
        $itemTypes = json_decode($m['item_types'], true);
        if (!is_array($itemTypes)) {
            $itemTypes = [];
        }
    }
    
    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'category' => $m['category'] ?? '',
        'business_type' => $m['business_type'] ?? 'restaurant',
        'item_types' => $itemTypes,
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'delivery_info' => $m['delivery_info'] ?? '',
        'image_url' => $imageUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_promoted' => boolval($m['is_promoted'] ?? false),
        'delivery_fee' => floatval($m['delivery_fee'] ?? 0),
        'formatted_delivery_fee' => 'MK ' . number_format(floatval($m['delivery_fee'] ?? 0), 2),
        'min_order_amount' => floatval($m['min_order_amount'] ?? 0),
        'delivery_radius' => intval($m['delivery_radius'] ?? 5),
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

function formatMerchantDetailData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . $m['image_url'];
        }
    }
    
    $logoUrl = '';
    if (!empty($m['logo_url'])) {
        if (strpos($m['logo_url'], 'http') === 0) {
            $logoUrl = $m['logo_url'];
        } else {
            $logoUrl = rtrim($baseUrl, '/') . '/uploads/merchants/logos/' . $m['logo_url'];
        }
    }
    
    $itemTypes = [];
    if (!empty($m['item_types'])) {
        $itemTypes = json_decode($m['item_types'], true);
        if (!is_array($itemTypes)) {
            $itemTypes = [];
        }
    }

    $tags = [];
    if (!empty($m['tags'])) {
        $tags = json_decode($m['tags'], true);
        if (!is_array($tags)) {
            $tags = explode(',', $m['tags']);
        }
    }

    $paymentMethods = [];
    if (!empty($m['payment_methods'])) {
        $paymentMethods = json_decode($m['payment_methods'], true);
        if (!is_array($paymentMethods)) {
            $paymentMethods = explode(',', $m['payment_methods']);
        }
    }

    $operatingHours = [];
    if (!empty($m['operating_hours'])) {
        $operatingHours = json_decode($m['operating_hours'], true);
        if (!is_array($operatingHours)) {
            $operatingHours = [];
        }
    }

    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'description' => $m['description'] ?? '',
        'category' => $m['category'] ?? '',
        'business_type' => $m['business_type'] ?? 'restaurant',
        'item_types' => $itemTypes,
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'delivery_info' => $m['delivery_info'] ?? '',
        'image_url' => $imageUrl,
        'logo_url' => $logoUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_promoted' => boolval($m['is_promoted'] ?? false),
        'address' => $m['address'] ?? '',
        'phone' => $m['phone'] ?? '',
        'email' => $m['email'] ?? '',
        'latitude' => floatval($m['latitude'] ?? 0),
        'longitude' => floatval($m['longitude'] ?? 0),
        'operating_hours' => $operatingHours,
        'tags' => $tags,
        'payment_methods' => $paymentMethods,
        'delivery_fee' => floatval($m['delivery_fee'] ?? 0),
        'formatted_delivery_fee' => 'MK ' . number_format(floatval($m['delivery_fee'] ?? 0), 2),
        'min_order_amount' => floatval($m['min_order_amount'] ?? 0),
        'delivery_radius' => intval($m['delivery_radius'] ?? 5),
        'delivery_time' => $m['delivery_time'] ?? '',
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

function formatReviewData($review) {
    global $baseUrl;
    
    $avatarUrl = '';
    if (!empty($review['user_avatar'])) {
        if (strpos($review['user_avatar'], 'http') === 0) {
            $avatarUrl = $review['user_avatar'];
        } else {
            $avatarUrl = rtrim($baseUrl, '/') . '/uploads/avatars/' . $review['user_avatar'];
        }
    }
    
    return [
        'id' => $review['id'],
        'user_id' => $review['user_id'],
        'user_name' => $review['user_name'] ?? 'Anonymous',
        'user_avatar' => $avatarUrl,
        'rating' => intval($review['rating'] ?? 0),
        'comment' => $review['comment'] ?? '',
        'created_at' => $review['created_at'] ?? ''
    ];
}

function formatMenuItemData($item, $baseUrl) {
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu-items/' . $item['image_url'];
        }
    }
    
    $options = [];
    if (!empty($item['options'])) {
        try {
            $options = json_decode($item['options'], true);
            if (!is_array($options)) {
                $options = [];
            }
        } catch (Exception $e) {
            $options = [];
        }
    }

    $ingredients = [];
    if (!empty($item['ingredients'])) {
        try {
            $ingredients = json_decode($item['ingredients'], true);
            if (!is_array($ingredients)) {
                $ingredients = explode(',', $item['ingredients']);
            }
        } catch (Exception $e) {
            $ingredients = [];
        }
    }

    $allergens = [];
    if (!empty($item['allergens'])) {
        try {
            $allergens = json_decode($item['allergens'], true);
            if (!is_array($allergens)) {
                $allergens = explode(',', $item['allergens']);
            }
        } catch (Exception $e) {
            $allergens = [];
        }
    }

    $nutritionInfo = [];
    if (!empty($item['nutrition_info'])) {
        try {
            $nutritionInfo = json_decode($item['nutrition_info'], true);
            if (!is_array($nutritionInfo)) {
                $nutritionInfo = [];
            }
        } catch (Exception $e) {
            $nutritionInfo = [];
        }
    }

    $displayPrice = $item['price'];
    $displayUnit = $item['unit_type'] ?? 'piece';
    
    if (($item['unit_type'] ?? 'piece') === 'kg' && ($item['unit_value'] ?? 1) != 1) {
        $displayPrice = $item['price'] / ($item['unit_value'] ?? 1);
        $displayUnit = 'g';
        $displayPrice = round($displayPrice * 1000, 2);
    }

    return [
        'id' => $item['id'] ?? null,
        'quick_order_item_id' => $item['quick_order_item_id'] ?? null,
        'name' => $item['name'] ?? '',
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price'] ?? 0),
        'display_price' => floatval($displayPrice),
        'formatted_price' => 'MK ' . number_format(floatval($displayPrice), 2) . ' / ' . $displayUnit,
        'image_url' => $imageUrl,
        'category' => $item['category'] ?? '',
        'item_type' => $item['item_type'] ?? 'food',
        'subcategory' => $item['subcategory'] ?? '',
        'unit_type' => $item['unit_type'] ?? 'piece',
        'unit_value' => floatval($item['unit_value'] ?? 1),
        'is_available' => boolval($item['is_available'] ?? true),
        'is_popular' => boolval($item['is_popular'] ?? false),
        'options' => $options,
        'ingredients' => $ingredients,
        'allergens' => $allergens,
        'nutrition_info' => $nutritionInfo,
        'preparation_time' => intval($item['preparation_time'] ?? 15),
        'stock_quantity' => intval($item['stock_quantity'] ?? 0),
        'in_stock' => ($item['stock_quantity'] === null || $item['stock_quantity'] > 0) && ($item['is_available'] ?? true),
        'source' => $item['source'] ?? 'menu',
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}

function formatCategoryData($category, $baseUrl) {
    $imageUrl = '';
    if (!empty($category['image_url'])) {
        if (strpos($category['image_url'], 'http') === 0) {
            $imageUrl = $category['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/categories/' . $category['image_url'];
        }
    }
    
    return [
        'id' => intval($category['id'] ?? 0),
        'name' => $category['name'] ?? '',
        'description' => $category['description'] ?? '',
        'image_url' => $imageUrl,
        'display_order' => intval($category['display_order'] ?? 0),
        'item_count' => intval($category['item_count'] ?? 0),
        'item_types' => $category['item_types'] ?? [],
        'is_active' => boolval($category['is_active'] ?? true),
        'is_quick_order' => boolval($category['is_quick_order'] ?? false),
        'created_at' => $category['created_at'] ?? '',
        'updated_at' => $category['updated_at'] ?? ''
    ];
}

function formatQuickOrderForMerchant($order, $baseUrl) {
    $imageUrl = '';
    if (!empty($order['image_url'])) {
        if (strpos($order['image_url'], 'http') === 0) {
            $imageUrl = $order['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/quick-orders/' . $order['image_url'];
        }
    }
    
    return [
        'id' => $order['id'] ?? null,
        'title' => $order['title'] ?? '',
        'description' => $order['description'] ?? '',
        'category' => $order['category'] ?? '',
        'item_type' => $order['item_type'] ?? 'food',
        'image_url' => $imageUrl,
        'price' => floatval($order['price'] ?? 0),
        'formatted_price' => 'MK ' . number_format(floatval($order['price'] ?? 0), 2),
        'rating' => floatval($order['rating'] ?? 0)
    ];
}

function formatQuickOrderForMerchantList($order, $baseUrl) {
    $imageUrl = '';
    if (!empty($order['image_url'])) {
        if (strpos($order['image_url'], 'http') === 0) {
            $imageUrl = $order['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/quick-orders/' . $order['image_url'];
        }
    }
    
    $price = $order['custom_price'] ?? $order['price'];
    $deliveryTime = $order['custom_delivery_time'] ?? $order['delivery_time'];
    
    return [
        'id' => $order['id'] ?? null,
        'title' => $order['title'] ?? '',
        'description' => $order['description'] ?? '',
        'category' => $order['category'] ?? '',
        'item_type' => $order['item_type'] ?? 'food',
        'image_url' => $imageUrl,
        'price' => floatval($price),
        'formatted_price' => 'MK ' . number_format(floatval($price), 2),
        'rating' => floatval($order['rating'] ?? 0),
        'order_count' => intval($order['order_count'] ?? 0),
        'delivery_time' => $deliveryTime,
        'priority' => intval($order['priority'] ?? 0),
        'has_custom_price' => isset($order['custom_price']),
        'has_custom_delivery_time' => isset($order['custom_delivery_time'])
    ];
}
?>