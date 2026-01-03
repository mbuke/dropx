<?php
// api/search.php - Search API for merchants and menu items
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
        $query = $_GET['q'] ?? '';
        $type = $_GET['type'] ?? 'all'; // all, merchants, items
        $category = $_GET['category'] ?? null;
        $city = $_GET['city'] ?? null;
        $minRating = isset($_GET['min_rating']) ? floatval($_GET['min_rating']) : null;
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        
        if (empty($query)) {
            jsonResponse(false, null, 'Search query is required', 400);
        }
        
        $results = [
            'merchants' => [],
            'menu_items' => [],
            'categories' => []
        ];
        
        // Search merchants
        if ($type === 'all' || $type === 'merchants') {
            $merchantQuery = "
                SELECT 
                    r.*,
                    COALESCE(AVG(mr.rating), r.rating) as actual_rating,
                    COALESCE(COUNT(DISTINCT mr.id), r.review_count) as actual_review_count
                FROM restaurants r
                LEFT JOIN merchant_reviews mr ON r.id = mr.restaurant_id 
                    AND mr.status = 'approved'
                WHERE r.status = 'active'
                    AND (r.name LIKE :query 
                         OR r.short_description LIKE :query 
                         OR r.tags LIKE :query
                         OR r.city LIKE :query)
            ";
            
            $merchantParams = [':query' => "%{$query}%"];
            
            if ($category) {
                $merchantQuery .= " AND r.main_category = :category";
                $merchantParams[':category'] = $category;
            }
            
            if ($city) {
                $merchantQuery .= " AND r.city = :city";
                $merchantParams[':city'] = $city;
            }
            
            $merchantQuery .= " GROUP BY r.id";
            
            if ($minRating !== null) {
                $merchantQuery .= " HAVING actual_rating >= :min_rating";
                $merchantParams[':min_rating'] = $minRating;
            }
            
            $merchantQuery .= " ORDER BY 
                CASE 
                    WHEN r.name LIKE :exact_query THEN 0
                    WHEN r.name LIKE :start_query THEN 1
                    ELSE 2
                END,
                r.is_featured DESC,
                actual_rating DESC
                LIMIT :limit";
            
            $merchantParams[':exact_query'] = $query;
            $merchantParams[':start_query'] = "{$query}%";
            $merchantParams[':limit'] = $limit;
            
            $merchantStmt = $conn->prepare($merchantQuery);
            foreach ($merchantParams as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $merchantStmt->bindValue($key, $value, $type);
            }
            
            $merchantStmt->execute();
            $merchants = $merchantStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results['merchants'] = array_map(function($merchant) {
                return [
                    'id' => $merchant['id'],
                    'name' => $merchant['name'],
                    'type' => $merchant['merchant_type'] ?? 'restaurant',
                    'mainCategory' => $merchant['main_category'] ?? 'food',
                    'description' => $merchant['short_description'] ?? substr($merchant['description'] ?? '', 0, 100) . '...',
                    'rating' => round($merchant['actual_rating'], 1),
                    'reviewCount' => (int) $merchant['actual_review_count'],
                    'deliveryTime' => $merchant['delivery_time'] ?? '30-45 min',
                    'deliveryFee' => 'MK ' . number_format($merchant['delivery_fee'] ?? 0, 0),
                    'minOrder' => 'MK ' . number_format($merchant['min_order_amount'] ?? 0, 0),
                    'distance' => $merchant['distance_km'] ? round($merchant['distance_km'], 1) . ' km' : null,
                    'isOpen' => (bool) $merchant['is_open'],
                    'image' => $merchant['image'] ?: 'default-merchant.jpg',
                    'isFeatured' => (bool) $merchant['is_featured'],
                    'isPromoted' => (bool) $merchant['is_promoted'],
                    'city' => $merchant['city'],
                    'tags' => json_decode($merchant['tags'] ?? '[]', true)
                ];
            }, $merchants);
        }
        
        // Search menu items
        if ($type === 'all' || $type === 'items') {
            $itemQuery = "
                SELECT 
                    mi.*,
                    r.name as merchant_name,
                    r.id as merchant_id,
                    r.image as merchant_image,
                    r.rating as merchant_rating,
                    mc.name as category_name,
                    mc.slug as category_slug
                FROM menu_items mi
                JOIN restaurants r ON mi.restaurant_id = r.id
                LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
                WHERE mi.is_active = 1
                    AND r.status = 'active'
                    AND (mi.name LIKE :query 
                         OR mi.description LIKE :query 
                         OR mi.tags LIKE :query)
            ";
            
            $itemParams = [':query' => "%{$query}%"];
            
            if ($category) {
                $itemQuery .= " AND r.main_category = :category";
                $itemParams[':category'] = $category;
            }
            
            if ($city) {
                $itemQuery .= " AND r.city = :city";
                $itemParams[':city'] = $city;
            }
            
            if ($minRating !== null) {
                $itemQuery .= " AND r.rating >= :min_rating";
                $itemParams[':min_rating'] = $minRating;
            }
            
            $itemQuery .= " ORDER BY 
                CASE 
                    WHEN mi.name LIKE :exact_query THEN 0
                    WHEN mi.name LIKE :start_query THEN 1
                    ELSE 2
                END,
                mi.is_popular DESC,
                mi.rating DESC
                LIMIT :limit";
            
            $itemParams[':exact_query'] = $query;
            $itemParams[':start_query'] = "{$query}%";
            $itemParams[':limit'] = $limit;
            
            $itemStmt = $conn->prepare($itemQuery);
            foreach ($itemParams as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $itemStmt->bindValue($key, $value, $type);
            }
            
            $itemStmt->execute();
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results['menu_items'] = array_map(function($item) {
                $tags = json_decode($item['tags'] ?? '[]', true);
                $displayTags = [];
                
                if ($item['is_popular']) {
                    $displayTags[] = '🔥 Popular';
                    if (!in_array('🔥 Popular', $tags)) {
                        $tags[] = '🔥 Popular';
                    }
                }
                
                return [
                    'id' => $item['uuid'] ?? $item['id'],
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'price' => (float) $item['price'],
                    'discountedPrice' => $item['discounted_price'] ? (float) $item['discounted_price'] : null,
                    'image' => $item['image_url'] ?: 'default-menu-item.jpg',
                    'category' => $item['category_slug'],
                    'categoryName' => $item['category_name'],
                    'tags' => $tags,
                    'displayTags' => array_merge($displayTags, $tags),
                    'prepTime' => $item['prep_time'],
                    'rating' => round($item['rating'], 1),
                    'reviewCount' => (int) $item['review_count'],
                    'inStock' => (bool) $item['in_stock'],
                    'merchant' => [
                        'id' => $item['merchant_id'],
                        'name' => $item['merchant_name'],
                        'image' => $item['merchant_image'],
                        'rating' => round($item['merchant_rating'], 1)
                    ]
                ];
            }, $items);
        }
        
        // Search categories
        if ($type === 'all') {
            $categoryQuery = "
                SELECT DISTINCT
                    mc.name,
                    mc.slug,
                    r.name as merchant_name,
                    COUNT(mi.id) as item_count
                FROM merchant_categories mc
                JOIN restaurants r ON mc.restaurant_id = r.id
                LEFT JOIN menu_items mi ON mc.id = mi.category_id AND mi.is_active = 1
                WHERE mc.is_active = 1 
                    AND r.status = 'active'
                    AND mc.name LIKE :query
                GROUP BY mc.id, mc.name, mc.slug, r.name
                ORDER BY 
                    CASE 
                        WHEN mc.name LIKE :exact_query THEN 0
                        WHEN mc.name LIKE :start_query THEN 1
                        ELSE 2
                    END,
                    item_count DESC
                LIMIT 10
            ";
            
            $categoryStmt = $conn->prepare($categoryQuery);
            $categoryStmt->execute([
                ':query' => "%{$query}%",
                ':exact_query' => $query,
                ':start_query' => "{$query}%"
            ]);
            $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results['categories'] = array_map(function($cat) {
                return [
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'merchant' => $cat['merchant_name'],
                    'itemCount' => (int) $cat['item_count']
                ];
            }, $categories);
        }
        
        jsonResponse(true, [
            'query' => $query,
            'results' => $results,
            'counts' => [
                'merchants' => count($results['merchants']),
                'menu_items' => count($results['menu_items']),
                'categories' => count($results['categories'])
            ]
        ], 'Search results retrieved');
        
    } else {
        jsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Search API Error: " . $e->getMessage());
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}
?>