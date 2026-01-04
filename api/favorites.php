<?php
// api/favorites.php - Favorites API
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
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    jsonResponse(false, null, 'Authentication required', 401);
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    switch ($method) {
        case 'GET':
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $type = $_GET['type'] ?? null;
            $category = $_GET['category'] ?? null;
            $city = $_GET['city'] ?? null;
            
            // Get total count
            $countQuery = "
                SELECT COUNT(*) as total
                FROM user_favorites uf
                JOIN restaurants r ON uf.restaurant_id = r.id
                WHERE uf.user_id = :user_id
                    AND r.status = 'active'
            ";
            
            $countParams = [':user_id' => $userId];
            
            if ($type) {
                $countQuery .= " AND r.merchant_type = :type";
                $countParams[':type'] = $type;
            }
            
            if ($category) {
                $countQuery .= " AND r.main_category = :category";
                $countParams[':category'] = $category;
            }
            
            if ($city) {
                $countQuery .= " AND r.city = :city";
                $countParams[':city'] = $city;
            }
            
            $countStmt = $conn->prepare($countQuery);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
            
            // Get favorites
            $query = "
                SELECT 
                    uf.*,
                    r.*,
                    COALESCE(AVG(mr.rating), r.rating) as actual_rating,
                    COALESCE(COUNT(DISTINCT mr.id), r.review_count) as actual_review_count
                FROM user_favorites uf
                JOIN restaurants r ON uf.restaurant_id = r.id
                LEFT JOIN merchant_reviews mr ON r.id = mr.restaurant_id 
                    AND mr.status = 'approved'
                WHERE uf.user_id = :user_id
                    AND r.status = 'active'
            ";
            
            $params = [':user_id' => $userId];
            
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
            
            $query .= " GROUP BY r.id
                      ORDER BY uf.created_at DESC
                      LIMIT :limit OFFSET :offset";
            
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            
            $stmt->execute();
            $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $formattedFavorites = array_map(function($fav) {
                return [
                    'id' => $fav['restaurant_id'],
                    'name' => $fav['name'],
                    'type' => $fav['merchant_type'] ?? 'restaurant',
                    'mainCategory' => $fav['main_category'] ?? 'food',
                    'description' => $fav['short_description'] ?? substr($fav['description'] ?? '', 0, 100) . '...',
                    'rating' => round($fav['actual_rating'], 1),
                    'reviewCount' => (int) $fav['actual_review_count'],
                    'deliveryTime' => $fav['delivery_time'] ?? '30-45 min',
                    'deliveryFee' => 'MK ' . number_format($fav['delivery_fee'] ?? 0, 0),
                    'minOrder' => 'MK ' . number_format($fav['min_order_amount'] ?? 0, 0),
                    'distance' => $fav['distance_km'] ? round($fav['distance_km'], 1) . ' km' : null,
                    'isOpen' => (bool) $fav['is_open'],
                    'image' => $fav['image'] ?: 'default-merchant.jpg',
                    'coverImage' => $fav['cover_image'] ?: 'default-cover.jpg',
                    'isFeatured' => (bool) $fav['is_featured'],
                    'isPromoted' => (bool) $fav['is_promoted'],
                    'isFarmSourced' => (bool) $fav['is_farm_sourced'],
                    'city' => $fav['city'],
                    'address' => $fav['address'],
                    'phone' => $fav['phone'],
                    'tags' => json_decode($fav['tags'] ?? '[]', true),
                    'favoritedAt' => $fav['created_at']
                ];
            }, $favorites);
            
            jsonResponse(true, [
                'favorites' => $formattedFavorites,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int) $total,
                    'pages' => ceil($total / $limit)
                ]
            ], 'Favorites retrieved successfully');
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            
            $merchantId = intval($input['merchant_id'] ?? 0);
            $action = $input['action'] ?? 'toggle';
            
            if (!$merchantId) {
                jsonResponse(false, null, 'Merchant ID is required', 400);
            }
            
            $conn->beginTransaction();
            
            try {
                if ($action === 'add' || $action === 'toggle') {
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
                    $existing = $checkStmt->fetch();
                    
                    if ($existing) {
                        if ($action === 'add') {
                            $conn->rollBack();
                            jsonResponse(true, [
                                'isFavorite' => true,
                                'favoriteId' => $existing['id']
                            ], 'Already favorited');
                            return;
                        } else {
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
                            $isFavorite = false;
                            $message = 'Removed from favorites';
                        }
                    } else {
                        // Add to favorites
                        $insertQuery = "
                            INSERT INTO user_favorites (user_id, restaurant_id, created_at, updated_at)
                            VALUES (:user_id, :merchant_id, NOW(), NOW())
                        ";
                        $insertStmt = $conn->prepare($insertQuery);
                        $insertStmt->execute([
                            ':user_id' => $userId,
                            ':merchant_id' => $merchantId
                        ]);
                        $favoriteId = $conn->lastInsertId();
                        $isFavorite = true;
                        $message = 'Added to favorites';
                    }
                } else if ($action === 'remove') {
                    $deleteQuery = "
                        DELETE FROM user_favorites 
                        WHERE user_id = :user_id AND restaurant_id = :merchant_id
                    ";
                    $deleteStmt = $conn->prepare($deleteQuery);
                    $deleteStmt->execute([
                        ':user_id' => $userId,
                        ':merchant_id' => $merchantId
                    ]);
                    $isFavorite = false;
                    $message = 'Removed from favorites';
                } else {
                    $conn->rollBack();
                    jsonResponse(false, null, 'Invalid action', 400);
                    return;
                }
                
                // Update favorite count
                $countQuery = "
                    SELECT COUNT(*) as favorite_count 
                    FROM user_favorites 
                    WHERE restaurant_id = :merchant_id
                ";
                $countStmt = $conn->prepare($countQuery);
                $countStmt->execute([':merchant_id' => $merchantId]);
                $count = $countStmt->fetch()['favorite_count'];
                
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
                
                $conn->commit();
                
                $responseData = [
                    'isFavorite' => $isFavorite,
                    'favoriteCount' => (int) $count
                ];
                
                if (isset($favoriteId)) {
                    $responseData['favoriteId'] = $favoriteId;
                }
                
                jsonResponse(true, $responseData, $message);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_GET;
            }
            
            $favoriteId = intval($input['favorite_id'] ?? 0);
            $merchantId = intval($input['merchant_id'] ?? 0);
            
            if ($favoriteId) {
                // Delete by favorite ID
                $query = "DELETE FROM user_favorites WHERE id = :id AND user_id = :user_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':id' => $favoriteId,
                    ':user_id' => $userId
                ]);
                
                if ($stmt->rowCount() > 0) {
                    jsonResponse(true, null, 'Favorite removed');
                } else {
                    jsonResponse(false, null, 'Favorite not found', 404);
                }
            } else if ($merchantId) {
                // Delete by merchant ID
                $query = "DELETE FROM user_favorites WHERE user_id = :user_id AND restaurant_id = :merchant_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':merchant_id' => $merchantId
                ]);
                
                if ($stmt->rowCount() > 0) {
                    // Update favorite count
                    $countQuery = "
                        SELECT COUNT(*) as favorite_count 
                        FROM user_favorites 
                        WHERE restaurant_id = :merchant_id
                    ";
                    $countStmt = $conn->prepare($countQuery);
                    $countStmt->execute([':merchant_id' => $merchantId]);
                    $count = $countStmt->fetch()['favorite_count'];
                    
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
                    
                    jsonResponse(true, [
                        'favoriteCount' => (int) $count
                    ], 'Favorite removed');
                } else {
                    jsonResponse(false, null, 'Favorite not found', 404);
                }
            } else {
                jsonResponse(false, null, 'Favorite ID or Merchant ID is required', 400);
            }
            break;
            
        default:
            jsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Favorites API Error: " . $e->getMessage());
    jsonResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}
?>