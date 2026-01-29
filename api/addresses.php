<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Id, X-Session-Token");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION & AUTH CONFIG
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
 * AUTHENTICATION MIDDLEWARE
 *********************************/
function authenticateUser() {
    // Check session authentication first
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    // Check for API token/header authentication
    $headers = getallheaders();
    $userId = $headers['X-User-Id'] ?? $headers['x-user-id'] ?? null;
    $sessionToken = $headers['X-Session-Token'] ?? $headers['x-session-token'] ?? null;
    
    if ($userId && $sessionToken) {
        // Validate session token from database
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions 
             WHERE user_id = :user_id 
             AND session_id = :session_token 
             AND expires_at > NOW() 
             AND logout_at IS NULL"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_token' => $sessionToken
        ]);
        
        if ($stmt->fetch()) {
            $_SESSION['user_id'] = $userId;
            return $userId;
        }
    }
    
    // Check for Bearer token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        // Validate JWT or other token format
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM users 
             WHERE remember_token = :token 
             AND reset_token_expires > NOW()"
        );
        $stmt->execute([':token' => $token]);
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['user_id'] = $user['user_id'];
            return $user['user_id'];
        }
    }
    
    return null;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = str_replace('/api/addresses', '', $path);
    
    // Extract location ID from path if present
    $locationId = null;
    if (preg_match('/\/(\d+)$/', $path, $matches)) {
        $locationId = $matches[1];
    }

    if ($method === 'GET') {
        if ($locationId) {
            getLocationDetails($locationId);
        } else {
            getLocationsList();
        }
    } elseif ($method === 'POST') {
        handlePostRequest();
    } elseif ($method === 'PUT') {
        if ($locationId) {
            updateLocation($locationId);
        } else {
            ResponseHandler::error('Location ID required for update', 400);
        }
    } elseif ($method === 'PATCH') {
        handlePatchRequest($locationId);
    } elseif ($method === 'DELETE') {
        if ($locationId) {
            deleteLocation($locationId);
        } else {
            ResponseHandler::error('Location ID required for deletion', 400);
        }
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET LOCATIONS LIST
 *********************************/
function getLocationsList() {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get query parameters
    $params = $_GET;
    $type = $params['type'] ?? '';
    $isDefault = $params['is_default'] ?? null;
    $sortBy = $params['sort_by'] ?? 'last_used';
    $sortOrder = strtoupper($params['sort_order'] ?? 'DESC');
    $search = $params['search'] ?? '';
    $limit = min(100, max(1, intval($params['limit'] ?? 20)));
    $page = max(1, intval($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    // Build WHERE clause
    $whereConditions = ["a.user_id = :user_id"];
    $queryParams = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $whereConditions[] = "a.location_type = :type";
        $queryParams[':type'] = $type;
    }

    if ($isDefault !== null) {
        $whereConditions[] = "a.is_default = :is_default";
        $queryParams[':is_default'] = $isDefault === 'true' ? 1 : 0;
    }

    if ($search) {
        $whereConditions[] = "(a.label LIKE :search OR a.address_line1 LIKE :search OR a.area LIKE :search OR a.sector LIKE :search OR a.landmark LIKE :search)";
        $queryParams[':search'] = "%$search%";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['last_used', 'created_at', 'label', 'is_default', 'updated_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'last_used';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM addresses a $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($queryParams);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get locations with pagination
    $sql = "SELECT 
                a.id,
                a.user_id,
                a.label,
                a.full_name,
                a.phone,
                a.address_line1,
                a.address_line2,
                a.city,
                a.neighborhood,
                a.area,
                a.sector,
                a.location_type,
                a.landmark,
                a.latitude,
                a.longitude,
                a.is_default,
                a.last_used,
                a.created_at,
                a.updated_at,
                u.default_address_id
            FROM addresses a
            LEFT JOIN users u ON a.user_id = u.id
            $whereClause
            ORDER BY a.is_default DESC, a.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format location data
    $formattedLocations = [];
    $currentLocation = null;
    
    foreach ($locations as $loc) {
        $formattedLocation = formatLocationData($loc);
        $formattedLocations[] = $formattedLocation;
        
        // Identify current location (default or most recent)
        if ($loc['is_default'] || $loc['id'] == $loc['default_address_id']) {
            $currentLocation = $formattedLocation;
        }
    }

    // If no default found, use most recent
    if (!$currentLocation && !empty($formattedLocations)) {
        usort($formattedLocations, function($a, $b) {
            $timeA = strtotime($a['last_used'] ?? $a['created_at']);
            $timeB = strtotime($b['last_used'] ?? $b['created_at']);
            return $timeB - $timeA;
        });
        $currentLocation = $formattedLocations[0];
    }

    // Get user's statistics from database
    $statsStmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_locations,
            SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) as default_locations,
            SUM(CASE WHEN location_type = 'home' THEN 1 ELSE 0 END) as home_locations,
            SUM(CASE WHEN location_type = 'work' THEN 1 ELSE 0 END) as work_locations,
            SUM(CASE WHEN location_type = 'other' THEN 1 ELSE 0 END) as other_locations
        FROM addresses 
        WHERE user_id = :user_id"
    );
    $statsStmt->execute([':user_id' => $userId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get Lilongwe areas and sectors from database if available
    $areasSectors = getAreasAndSectorsFromDB($conn);

    ResponseHandler::success([
        'locations' => $formattedLocations,
        'current_location' => $currentLocation,
        'total_count' => $totalCount,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($totalCount / $limit),
            'has_next' => ($page * $limit) < $totalCount,
            'has_prev' => $page > 1
        ],
        'statistics' => [
            'total' => $stats['total_locations'] ?? 0,
            'default' => $stats['default_locations'] ?? 0,
            'home' => $stats['home_locations'] ?? 0,
            'work' => $stats['work_locations'] ?? 0,
            'other' => $stats['other_locations'] ?? 0
        ],
        'areas_sectors' => $areasSectors
    ]);
}

/*********************************
 * GET LOCATION DETAILS
 *********************************/
function getLocationDetails($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare(
        "SELECT 
            a.id,
            a.user_id,
            a.label,
            a.full_name,
            a.phone,
            a.address_line1,
            a.address_line2,
            a.city,
            a.neighborhood,
            a.area,
            a.sector,
            a.location_type,
            a.landmark,
            a.latitude,
            a.longitude,
            a.is_default,
            a.last_used,
            a.created_at,
            a.updated_at,
            u.default_address_id,
            COUNT(o.id) as order_count,
            MAX(o.created_at) as last_order_date
        FROM addresses a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN orders o ON a.user_id = o.user_id 
            AND (o.delivery_address LIKE CONCAT('%', a.address_line1, '%') 
                OR o.delivery_address LIKE CONCAT('%', a.label, '%'))
        WHERE a.id = :id AND a.user_id = :user_id
        GROUP BY a.id"
    );
    
    $stmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    $formattedLocation = formatLocationData($location);
    
    // Add order history from database if any
    if ($location['order_count'] > 0) {
        $orderStmt = $conn->prepare(
            "SELECT 
                o.id,
                o.order_number,
                o.merchant_id,
                m.name as merchant_name,
                o.total_amount,
                o.status,
                o.created_at
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.user_id = :user_id 
                AND (o.delivery_address LIKE CONCAT('%', :address, '%') 
                    OR o.delivery_address LIKE CONCAT('%', :label, '%'))
            ORDER BY o.created_at DESC
            LIMIT 5"
        );
        $orderStmt->execute([
            ':user_id' => $userId,
            ':address' => $location['address_line1'],
            ':label' => $location['label']
        ]);
        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formattedLocation['order_history'] = $orders;
    } else {
        $formattedLocation['order_history'] = [];
    }

    // Get nearby locations from database
    $nearbyStmt = $conn->prepare(
        "SELECT 
            id,
            label,
            area,
            sector,
            location_type,
            latitude,
            longitude,
            (6371 * acos(cos(radians(:lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians(:lng)) + sin(radians(:lat)) * sin(radians(latitude)))) AS distance
        FROM addresses 
        WHERE user_id = :user_id 
            AND id != :location_id
            AND latitude IS NOT NULL 
            AND longitude IS NOT NULL
        HAVING distance < 5 -- within 5km
        ORDER BY distance ASC
        LIMIT 3"
    );
    
    if ($location['latitude'] && $location['longitude']) {
        $nearbyStmt->execute([
            ':user_id' => $userId,
            ':location_id' => $locationId,
            ':lat' => $location['latitude'],
            ':lng' => $location['longitude']
        ]);
        $nearbyLocations = $nearbyStmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedLocation['nearby_locations'] = $nearbyLocations;
    } else {
        $formattedLocation['nearby_locations'] = [];
    }

    ResponseHandler::success([
        'location' => $formattedLocation
    ]);
}

/*********************************
 * CREATE NEW LOCATION
 *********************************/
function createLocation() {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields based on database schema
    $required = ['label', 'full_name', 'phone', 'address_line1', 'city'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            ResponseHandler::error("$field is required", 400);
        }
    }

    $label = trim($input['label']);
    $fullName = trim($input['full_name']);
    $phone = trim($input['phone']);
    $addressLine1 = trim($input['address_line1']);
    $addressLine2 = trim($input['address_line2'] ?? '');
    $city = trim($input['city']);
    $neighborhood = trim($input['neighborhood'] ?? '');
    $area = trim($input['area'] ?? $city);
    $sector = trim($input['sector'] ?? $neighborhood);
    $locationType = trim($input['location_type'] ?? 'other');
    $landmark = trim($input['landmark'] ?? '');
    $latitude = isset($input['latitude']) ? floatval($input['latitude']) : null;
    $longitude = isset($input['longitude']) ? floatval($input['longitude']) : null;
    $isDefault = boolval($input['is_default'] ?? false);

    // Validate location type from database enum
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get valid location types from database schema
    $enumStmt = $conn->query("SHOW COLUMNS FROM addresses LIKE 'location_type'");
    $enumRow = $enumStmt->fetch(PDO::FETCH_ASSOC);
    $validTypes = [];
    
    if ($enumRow && preg_match("/enum\('(.+)'\)/", $enumRow['Type'], $matches)) {
        $validTypes = explode("','", $matches[1]);
    } else {
        // Fallback to default types if enum not found
        $validTypes = ['home', 'work', 'other'];
    }
    
    if (!in_array($locationType, $validTypes)) {
        ResponseHandler::error("Invalid location type. Must be one of: " . implode(', ', $validTypes), 400);
    }

    // Validate phone number
    if (!preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', $phone)) {
        ResponseHandler::error('Invalid phone number format', 400);
    }

    // Validate coordinates if provided
    if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
        ResponseHandler::error('Invalid latitude value. Must be between -90 and 90', 400);
    }
    if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
        ResponseHandler::error('Invalid longitude value. Must be between -180 and 180', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Check for duplicate address from database
        $duplicateStmt = $conn->prepare(
            "SELECT id FROM addresses 
             WHERE user_id = :user_id 
             AND address_line1 = :address_line1 
             AND city = :city 
             AND area = :area"
        );
        $duplicateStmt->execute([
            ':user_id' => $userId,
            ':address_line1' => $addressLine1,
            ':city' => $city,
            ':area' => $area
        ]);
        
        if ($duplicateStmt->fetch()) {
            ResponseHandler::error('This address already exists in your saved locations', 409);
        }

        // If setting as default, remove default from other locations
        if ($isDefault) {
            $updateStmt = $conn->prepare(
                "UPDATE addresses 
                 SET is_default = 0 
                 WHERE user_id = :user_id AND is_default = 1"
            );
            $updateStmt->execute([':user_id' => $userId]);
        }

        // Create the new location
        $stmt = $conn->prepare(
            "INSERT INTO addresses 
                (user_id, label, full_name, phone, address_line1, address_line2, 
                 city, neighborhood, area, sector, location_type, landmark, 
                 latitude, longitude, is_default, created_at, last_used)
             VALUES 
                (:user_id, :label, :full_name, :phone, :address_line1, :address_line2, 
                 :city, :neighborhood, :area, :sector, :location_type, :landmark, 
                 :latitude, :longitude, :is_default, NOW(), NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':label' => $label,
            ':full_name' => $fullName,
            ':phone' => $phone,
            ':address_line1' => $addressLine1,
            ':address_line2' => $addressLine2,
            ':city' => $city,
            ':neighborhood' => $neighborhood,
            ':area' => $area,
            ':sector' => $sector,
            ':location_type' => $locationType,
            ':landmark' => $landmark,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':is_default' => $isDefault ? 1 : 0
        ]);

        $locationId = $conn->lastInsertId();

        // If no default exists and this isn't set as default, set it as default
        if (!$isDefault) {
            $checkDefaultStmt = $conn->prepare(
                "SELECT COUNT(*) as default_count 
                 FROM addresses 
                 WHERE user_id = :user_id AND is_default = 1"
            );
            $checkDefaultStmt->execute([':user_id' => $userId]);
            $defaultCount = $checkDefaultStmt->fetch(PDO::FETCH_ASSOC)['default_count'];

            if ($defaultCount == 0) {
                $setDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 1 
                     WHERE id = :id"
                );
                $setDefaultStmt->execute([':id' => $locationId]);
                $isDefault = true;
            }
        }

        // Update user's default address if needed
        if ($isDefault) {
            $updateUserStmt = $conn->prepare(
                "UPDATE users 
                 SET default_address_id = :address_id 
                 WHERE id = :user_id"
            );
            $updateUserStmt->execute([
                ':address_id' => $locationId,
                ':user_id' => $userId
            ]);
        }

        $conn->commit();

        // Get the created location from database
        $locationStmt = $conn->prepare(
            "SELECT a.*, u.default_address_id 
             FROM addresses a 
             LEFT JOIN users u ON a.user_id = u.id 
             WHERE a.id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        $formattedLocation = formatLocationData($location);

        // Log activity to database
        logUserActivity($conn, $userId, 'location_created', "Created location: $label", [
            'location_id' => $locationId,
            'location_type' => $locationType,
            'is_default' => $isDefault
        ]);

        // Create notification for user
        createNotification($conn, $userId, 'location_added', 'New Location Added', "You added a new location: $label", [
            'location_id' => $locationId,
            'location_name' => $label
        ]);

        ResponseHandler::success([
            'location' => $formattedLocation,
            'message' => 'Location created successfully'
        ], 201);

    } catch (PDOException $e) {
        $conn->rollBack();
        if ($e->getCode() == '23000') {
            ResponseHandler::error('This address already exists', 409);
        } else {
            ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
        }
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE LOCATION
 *********************************/
function updateLocation($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('Invalid request data', 400);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT * FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $currentLocation = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentLocation) {
        ResponseHandler::error('Location not found', 404);
    }

    // Prepare update data
    $updateFields = [];
    $params = [':id' => $locationId];

    if (isset($input['label'])) {
        $updateFields[] = "label = :label";
        $params[':label'] = trim($input['label']);
    }

    if (isset($input['full_name'])) {
        $updateFields[] = "full_name = :full_name";
        $params[':full_name'] = trim($input['full_name']);
    }

    if (isset($input['phone'])) {
        $phone = trim($input['phone']);
        if (!preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', $phone)) {
            ResponseHandler::error('Invalid phone number format', 400);
        }
        $updateFields[] = "phone = :phone";
        $params[':phone'] = $phone;
    }

    if (isset($input['address_line1'])) {
        $updateFields[] = "address_line1 = :address_line1";
        $params[':address_line1'] = trim($input['address_line1']);
    }

    if (isset($input['address_line2'])) {
        $updateFields[] = "address_line2 = :address_line2";
        $params[':address_line2'] = trim($input['address_line2']);
    }

    if (isset($input['city'])) {
        $updateFields[] = "city = :city";
        $params[':city'] = trim($input['city']);
    }

    if (isset($input['neighborhood'])) {
        $updateFields[] = "neighborhood = :neighborhood";
        $params[':neighborhood'] = trim($input['neighborhood']);
    }

    if (isset($input['area'])) {
        $updateFields[] = "area = :area";
        $params[':area'] = trim($input['area']);
    }

    if (isset($input['sector'])) {
        $updateFields[] = "sector = :sector";
        $params[':sector'] = trim($input['sector']);
    }

    if (isset($input['location_type'])) {
        $locationType = trim($input['location_type']);
        // Get valid types from database
        $enumStmt = $conn->query("SHOW COLUMNS FROM addresses LIKE 'location_type'");
        $enumRow = $enumStmt->fetch(PDO::FETCH_ASSOC);
        $validTypes = [];
        
        if ($enumRow && preg_match("/enum\('(.+)'\)/", $enumRow['Type'], $matches)) {
            $validTypes = explode("','", $matches[1]);
        } else {
            $validTypes = ['home', 'work', 'other'];
        }
        
        if (!in_array($locationType, $validTypes)) {
            ResponseHandler::error("Invalid location type. Must be one of: " . implode(', ', $validTypes), 400);
        }
        $updateFields[] = "location_type = :location_type";
        $params[':location_type'] = $locationType;
    }

    if (isset($input['landmark'])) {
        $updateFields[] = "landmark = :landmark";
        $params[':landmark'] = trim($input['landmark']);
    }

    if (isset($input['latitude'])) {
        $latitude = floatval($input['latitude']);
        if ($latitude < -90 || $latitude > 90) {
            ResponseHandler::error('Invalid latitude value', 400);
        }
        $updateFields[] = "latitude = :latitude";
        $params[':latitude'] = $latitude;
    }

    if (isset($input['longitude'])) {
        $longitude = floatval($input['longitude']);
        if ($longitude < -180 || $longitude > 180) {
            ResponseHandler::error('Invalid longitude value', 400);
        }
        $updateFields[] = "longitude = :longitude";
        $params[':longitude'] = $longitude;
    }

    if (empty($updateFields)) {
        ResponseHandler::error('No fields to update', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Handle default location change
        if (isset($input['is_default']) && boolval($input['is_default']) !== boolval($currentLocation['is_default'])) {
            if (boolval($input['is_default'])) {
                // Remove default from other locations
                $removeDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 0 
                     WHERE user_id = :user_id AND is_default = 1"
                );
                $removeDefaultStmt->execute([':user_id' => $userId]);

                $updateFields[] = "is_default = 1";

                // Update user's default address
                $updateUserStmt = $conn->prepare(
                    "UPDATE users 
                     SET default_address_id = :address_id 
                     WHERE id = :user_id"
                );
                $updateUserStmt->execute([
                    ':address_id' => $locationId,
                    ':user_id' => $userId
                ]);
            } else {
                $updateFields[] = "is_default = 0";
                
                // Clear user's default address if this was the default
                if ($currentLocation['is_default']) {
                    $clearUserStmt = $conn->prepare(
                        "UPDATE users 
                         SET default_address_id = NULL 
                         WHERE id = :user_id"
                    );
                    $clearUserStmt->execute([':user_id' => $userId]);
                }
            }
        }

        // Update the location
        $updateFields[] = "updated_at = NOW()";
        
        $sql = "UPDATE addresses SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $conn->commit();

        // Get updated location from database
        $locationStmt = $conn->prepare(
            "SELECT a.*, u.default_address_id 
             FROM addresses a 
             LEFT JOIN users u ON a.user_id = u.id 
             WHERE a.id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        $formattedLocation = formatLocationData($location);

        // Log activity to database
        logUserActivity($conn, $userId, 'location_updated', "Updated location: {$currentLocation['label']}", [
            'location_id' => $locationId,
            'changes' => array_keys($input)
        ]);

        ResponseHandler::success([
            'location' => $formattedLocation,
            'message' => 'Location updated successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DELETE LOCATION
 *********************************/
function deleteLocation($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user from database
    $checkStmt = $conn->prepare(
        "SELECT id, label, is_default FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $location = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Check if location is used in any recent orders from database
    $orderCheckStmt = $conn->prepare(
        "SELECT COUNT(*) as order_count FROM orders 
         WHERE user_id = :user_id 
         AND delivery_address LIKE CONCAT('%', :label, '%')
         AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $orderCheckStmt->execute([
        ':user_id' => $userId,
        ':label' => $location['label']
    ]);
    $orderCount = $orderCheckStmt->fetch(PDO::FETCH_ASSOC)['order_count'];

    if ($orderCount > 0) {
        ResponseHandler::error('Cannot delete location that has been used in recent orders. Consider archiving instead.', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Delete the location from database
        $deleteStmt = $conn->prepare(
            "DELETE FROM addresses 
             WHERE id = :id AND user_id = :user_id"
        );
        $deleteStmt->execute([
            ':id' => $locationId,
            ':user_id' => $userId
        ]);

        $deletedCount = $deleteStmt->rowCount();

        // If deleting default location, set a new default from database
        if ($location['is_default']) {
            // Get another location to set as default
            $newDefaultStmt = $conn->prepare(
                "SELECT id FROM addresses 
                 WHERE user_id = :user_id 
                 ORDER BY last_used DESC, created_at DESC 
                 LIMIT 1"
            );
            $newDefaultStmt->execute([':user_id' => $userId]);
            $newDefault = $newDefaultStmt->fetch(PDO::FETCH_ASSOC);

            if ($newDefault) {
                // Set new default
                $setDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 1 
                     WHERE id = :id"
                );
                $setDefaultStmt->execute([':id' => $newDefault['id']]);

                // Update user's default address
                $updateUserStmt = $conn->prepare(
                    "UPDATE users 
                     SET default_address_id = :address_id 
                     WHERE id = :user_id"
                );
                $updateUserStmt->execute([
                    ':address_id' => $newDefault['id'],
                    ':user_id' => $userId
                ]);
            } else {
                // No locations left, clear user's default address
                $clearUserStmt = $conn->prepare(
                    "UPDATE users 
                     SET default_address_id = NULL 
                     WHERE id = :user_id"
                );
                $clearUserStmt->execute([':user_id' => $userId]);
            }
        }

        $conn->commit();

        // Log activity to database
        logUserActivity($conn, $userId, 'location_deleted', "Deleted location: {$location['label']}", [
            'location_id' => $locationId,
            'was_default' => $location['is_default']
        ]);

        ResponseHandler::success([
            'message' => 'Location deleted successfully',
            'deleted_id' => $locationId,
            'was_default' => boolval($location['is_default'])
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to delete location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE POST REQUESTS (Special Operations)
 *********************************/
function handlePostRequest() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create_location':
            createLocation();
            break;
        case 'search_locations':
            searchLocations($input);
            break;
        case 'validate_address':
            validateAddress($input);
            break;
        case 'get_areas_sectors':
            getAreasAndSectors();
            break;
        case 'get_location_suggestions':
            getLocationSuggestions($input);
            break;
        case 'import_locations':
            importLocations($input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * HANDLE PATCH REQUESTS
 *********************************/
function handlePatchRequest($locationId = null) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('Invalid request data', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'set_default':
            setDefaultLocation($locationId);
            break;
        case 'update_last_used':
            updateLastUsed($locationId);
            break;
        case 'bulk_update':
            bulkUpdateLocations($input);
            break;
        case 'geocode_address':
            geocodeAddressFromDB($input);
            break;
        case 'reverse_geocode':
            reverseGeocodeFromDB($input);
            break;
        case 'check_delivery_zone':
            checkDeliveryZoneFromDB($input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * SET DEFAULT LOCATION
 *********************************/
function setDefaultLocation($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, label FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $location = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Remove default from all locations in database
        $removeDefaultStmt = $conn->prepare(
            "UPDATE addresses 
             SET is_default = 0 
             WHERE user_id = :user_id AND is_default = 1"
        );
        $removeDefaultStmt->execute([':user_id' => $userId]);

        // Set the specified location as default
        $setDefaultStmt = $conn->prepare(
            "UPDATE addresses 
             SET is_default = 1, 
                 last_used = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $setDefaultStmt->execute([':id' => $locationId]);

        // Update user's default address in database
        $updateUserStmt = $conn->prepare(
            "UPDATE users 
             SET default_address_id = :address_id 
             WHERE id = :user_id"
        );
        $updateUserStmt->execute([
            ':address_id' => $locationId,
            ':user_id' => $userId
        ]);

        $conn->commit();

        // Get updated location from database
        $locationStmt = $conn->prepare(
            "SELECT a.*, u.default_address_id 
             FROM addresses a 
             LEFT JOIN users u ON a.user_id = u.id 
             WHERE a.id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        $formattedLocation = formatLocationData($location);

        // Log activity to database
        logUserActivity($conn, $userId, 'location_set_default', "Set default location: {$location['label']}", [
            'location_id' => $locationId
        ]);

        ResponseHandler::success([
            'location' => $formattedLocation,
            'message' => 'Default location updated successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to set default location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE LAST USED TIMESTAMP
 *********************************/
function updateLastUsed($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Location not found', 404);
    }

    try {
        // Update last used timestamp in database
        $stmt = $conn->prepare(
            "UPDATE addresses 
             SET last_used = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([':id' => $locationId]);

        ResponseHandler::success([
            'message' => 'Last used timestamp updated',
            'location_id' => $locationId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

    } catch (PDOException $e) {
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * SEARCH LOCATIONS
 *********************************/
function searchLocations($input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $query = trim($input['query'] ?? '');
    $city = $input['city'] ?? '';
    $area = $input['area'] ?? '';
    $sector = $input['sector'] ?? '';
    $limit = min(50, max(1, intval($input['limit'] ?? 10)));
    
    if (empty($query) && empty($city) && empty($area) && empty($sector)) {
        ResponseHandler::error('Search query or filters required', 400);
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];
    
    if ($query) {
        $whereConditions[] = "(label LIKE :query OR address_line1 LIKE :query OR landmark LIKE :query)";
        $params[':query'] = "%$query%";
    }
    
    if ($city) {
        $whereConditions[] = "city = :city";
        $params[':city'] = $city;
    }
    
    if ($area) {
        $whereConditions[] = "area = :area";
        $params[':area'] = $area;
    }
    
    if ($sector) {
        $whereConditions[] = "sector = :sector";
        $params[':sector'] = $sector;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
                id,
                label,
                address_line1,
                address_line2,
                city,
                area,
                sector,
                landmark,
                location_type,
                is_default,
                last_used
            FROM addresses
            $whereClause
            ORDER BY 
                CASE 
                    WHEN label LIKE :query_exact THEN 1
                    WHEN address_line1 LIKE :query_exact THEN 2
                    WHEN area LIKE :query_exact THEN 3
                    ELSE 4
                END,
                is_default DESC,
                last_used DESC
            LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':query_exact', "$query%");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's default address ID for formatting
    $defaultStmt = $conn->prepare(
        "SELECT default_address_id FROM users WHERE id = :user_id"
    );
    $defaultStmt->execute([':user_id' => $userId]);
    $user = $defaultStmt->fetch(PDO::FETCH_ASSOC);
    $defaultAddressId = $user['default_address_id'] ?? null;
    
    // Format location data
    $formattedLocations = [];
    foreach ($locations as $loc) {
        $formattedLocations[] = formatLocationData($loc, $defaultAddressId);
    }
    
    ResponseHandler::success([
        'locations' => $formattedLocations,
        'total_count' => count($formattedLocations)
    ]);
}

/*********************************
 * VALIDATE ADDRESS
 *********************************/
function validateAddress($input) {
    $address = $input['address'] ?? '';
    $city = $input['city'] ?? '';
    $area = $input['area'] ?? '';
    $sector = $input['sector'] ?? '';

    if (empty($address)) {
        ResponseHandler::error('Address is required', 400);
    }

    // Get validation rules from database if available
    $db = new Database();
    $conn = $db->getConnection();
    
    $errors = [];
    
    // Check address length
    if (strlen($address) < 5) {
        $errors[] = 'Address is too short (minimum 5 characters)';
    }
    
    if (strlen($address) > 500) {
        $errors[] = 'Address is too long (maximum 500 characters)';
    }
    
    if (empty($city)) {
        $errors[] = 'City is required';
    }
    
    // Check for invalid characters
    if (preg_match('/[<>{}[\]]/', $address)) {
        $errors[] = 'Address contains invalid characters';
    }

    // Check if city exists in database (from addresses or merchants)
    if ($city) {
        $cityStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT city) as city_exists 
             FROM (
                 SELECT city FROM addresses WHERE city = :city 
                 UNION 
                 SELECT address FROM merchants WHERE address LIKE CONCAT('%', :city, '%')
             ) as cities"
        );
        $cityStmt->execute([':city' => $city]);
        $cityExists = $cityStmt->fetch(PDO::FETCH_ASSOC)['city_exists'];
        
        if (!$cityExists) {
            // Check if it's a valid Lilongwe area
            if (strpos($city, 'Area ') === 0 || in_array($city, ['City Centre', 'Old Town', 'Kawale', 'Mtandire', 'Ntandire', 'Biwi', 'Likuni', 'Chilinde', 'Mchesi'])) {
                // It's a valid Lilongwe area
            } else {
                $errors[] = 'City/Area not found in our service area';
            }
        }
    }

    // Check area format if provided
    if ($area && !preg_match('/^[A-Za-z0-9\s\-\.]+$/', $area)) {
        $errors[] = 'Area contains invalid characters';
    }

    // Check sector format if provided
    if ($sector && !preg_match('/^[A-Za-z0-9\s\-\.]+$/', $sector)) {
        $errors[] = 'Sector contains invalid characters';
    }

    if (!empty($errors)) {
        ResponseHandler::success([
            'valid' => false,
            'errors' => $errors
        ]);
    } else {
        ResponseHandler::success([
            'valid' => true,
            'errors' => []
        ]);
    }
}

/*********************************
 * GET AREAS AND SECTORS
 *********************************/
function getAreasAndSectors() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $areasSectors = getAreasAndSectorsFromDB($conn);
    
    ResponseHandler::success($areasSectors);
}

/*********************************
 * GET AREAS AND SECTORS FROM DATABASE
 *********************************/
function getAreasAndSectorsFromDB($conn) {
    // Get unique areas from addresses table
    $areasStmt = $conn->query(
        "SELECT DISTINCT area 
         FROM addresses 
         WHERE area IS NOT NULL AND area != '' 
         ORDER BY area"
    );
    $areas = $areasStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique sectors from addresses table
    $sectorsStmt = $conn->query(
        "SELECT DISTINCT sector 
         FROM addresses 
         WHERE sector IS NOT NULL AND sector != '' 
         ORDER BY sector"
    );
    $sectors = $sectorsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique cities from addresses table
    $citiesStmt = $conn->query(
        "SELECT DISTINCT city 
         FROM addresses 
         WHERE city IS NOT NULL AND city != '' 
         ORDER BY city"
    );
    $cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique neighborhoods from addresses table
    $neighborhoodsStmt = $conn->query(
        "SELECT DISTINCT neighborhood 
         FROM addresses 
         WHERE neighborhood IS NOT NULL AND neighborhood != '' 
         ORDER BY neighborhood"
    );
    $neighborhoods = $neighborhoodsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    return [
        'areas' => $areas ?: [],
        'sectors' => $sectors ?: [],
        'cities' => $cities ?: [],
        'neighborhoods' => $neighborhoods ?: []
    ];
}

/*********************************
 * GET LOCATION SUGGESTIONS
 *********************************/
function getLocationSuggestions($input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $partialAddress = $input['partial_address'] ?? '';
    $currentLocationId = $input['current_location_id'] ?? null;
    
    if (empty($partialAddress)) {
        ResponseHandler::error('Partial address is required', 400);
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get user's locations for suggestions
    $locationsStmt = $conn->prepare(
        "SELECT 
            id,
            label,
            address_line1,
            area,
            sector,
            location_type,
            is_default
        FROM addresses 
        WHERE user_id = :user_id 
        AND (label LIKE :query OR address_line1 LIKE :query OR area LIKE :query)
        ORDER BY 
            CASE WHEN label LIKE :query_exact THEN 1
                 WHEN address_line1 LIKE :query_exact THEN 2
                 ELSE 3
            END,
            is_default DESC,
            last_used DESC
        LIMIT 10"
    );
    
    $locationsStmt->execute([
        ':user_id' => $userId,
        ':query' => "%$partialAddress%",
        ':query_exact' => "$partialAddress%"
    ]);
    
    $locations = $locationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get popular locations from orders database
    $popularStmt = $conn->prepare(
        "SELECT 
            delivery_address,
            COUNT(*) as order_count
        FROM orders 
        WHERE user_id = :user_id 
        AND delivery_address LIKE :query
        GROUP BY delivery_address
        ORDER BY order_count DESC
        LIMIT 5"
    );
    
    $popularStmt->execute([
        ':user_id' => $userId,
        ':query' => "%$partialAddress%"
    ]);
    
    $popularLocations = $popularStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format suggestions
    $suggestions = [
        'user_locations' => $locations,
        'popular_locations' => $popularLocations
    ];
    
    ResponseHandler::success($suggestions);
}

/*********************************
 * GEOCODE ADDRESS FROM DATABASE
 *********************************/
function geocodeAddressFromDB($input) {
    $address = $input['address'] ?? '';
    $city = $input['city'] ?? '';
    $area = $input['area'] ?? '';
    $sector = $input['sector'] ?? '';

    if (empty($address) || empty($city)) {
        ResponseHandler::error('Address and city are required', 400);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // First, check if we have this address in our database with coordinates
    $existingStmt = $conn->prepare(
        "SELECT 
            latitude,
            longitude,
            area,
            sector
        FROM addresses 
        WHERE address_line1 LIKE :address 
        AND city = :city
        AND latitude IS NOT NULL 
        AND longitude IS NOT NULL
        LIMIT 1"
    );
    
    $existingStmt->execute([
        ':address' => "%$address%",
        ':city' => $city
    ]);
    
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Found in database
        ResponseHandler::success([
            'coordinates' => [
                'latitude' => floatval($existing['latitude']),
                'longitude' => floatval($existing['longitude'])
            ],
            'source' => 'database',
            'accuracy' => 'address_match'
        ]);
    } else {
        // Not found in database, check area-based coordinates
        if ($area) {
            $areaStmt = $conn->prepare(
                "SELECT 
                    AVG(latitude) as avg_lat,
                    AVG(longitude) as avg_lng
                FROM addresses 
                WHERE area = :area 
                AND city = :city
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL"
            );
            
            $areaStmt->execute([
                ':area' => $area,
                ':city' => $city
            ]);
            
            $areaCoords = $areaStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($areaCoords['avg_lat'] && $areaCoords['avg_lng']) {
                ResponseHandler::success([
                    'coordinates' => [
                        'latitude' => floatval($areaCoords['avg_lat']),
                        'longitude' => floatval($areaCoords['avg_lng'])
                    ],
                    'source' => 'database_area_average',
                    'accuracy' => 'area_based'
                ]);
            }
        }
        
        // No coordinates found in database
        ResponseHandler::success([
            'coordinates' => null,
            'source' => 'none',
            'accuracy' => 'unknown',
            'message' => 'No coordinates found in database. Consider using a geocoding service.'
        ]);
    }
}

/*********************************
 * REVERSE GEOCODE FROM DATABASE
 *********************************/
function reverseGeocodeFromDB($input) {
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;

    if (!$latitude || !$longitude) {
        ResponseHandler::error('Latitude and longitude are required', 400);
    }

    if (!is_numeric($latitude) || $latitude < -90 || $latitude > 90) {
        ResponseHandler::error('Invalid latitude value', 400);
    }
    if (!is_numeric($longitude) || $longitude < -180 || $longitude > 180) {
        ResponseHandler::error('Invalid longitude value', 400);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Find nearest addresses in database
    $nearestStmt = $conn->prepare(
        "SELECT 
            address_line1,
            city,
            area,
            sector,
            landmark,
            (6371 * acos(cos(radians(:lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians(:lng)) + sin(radians(:lat)) * sin(radians(latitude)))) AS distance
        FROM addresses 
        WHERE latitude IS NOT NULL 
        AND longitude IS NOT NULL
        HAVING distance < 1 -- within 1km
        ORDER BY distance ASC
        LIMIT 5"
    );
    
    $nearestStmt->execute([
        ':lat' => $latitude,
        ':lng' => $longitude
    ]);
    
    $nearestAddresses = $nearestStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($nearestAddresses)) {
        ResponseHandler::success([
            'address' => null,
            'nearest_addresses' => [],
            'accuracy' => 'none',
            'message' => 'No nearby addresses found in database'
        ]);
    } else {
        // Get the closest address
        $closest = $nearestAddresses[0];
        
        ResponseHandler::success([
            'address' => [
                'address_line1' => $closest['address_line1'],
                'city' => $closest['city'],
                'area' => $closest['area'],
                'sector' => $closest['sector'],
                'landmark' => $closest['landmark']
            ],
            'nearest_addresses' => $nearestAddresses,
            'distance' => $closest['distance'],
            'accuracy' => 'approximate'
        ]);
    }
}

/*********************************
 * CHECK DELIVERY ZONE FROM DATABASE
 *********************************/
function checkDeliveryZoneFromDB($input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $locationId = $input['location_id'] ?? null;
    $merchantId = $input['merchant_id'] ?? null;
    $area = $input['area'] ?? null;
    $sector = $input['sector'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Get location details if location_id is provided
    $location = null;
    if ($locationId) {
        $stmt = $conn->prepare(
            "SELECT area, sector, city FROM addresses 
             WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute([
            ':id' => $locationId,
            ':user_id' => $userId
        ]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Use provided area/sector or get from location
    $checkArea = $area ?? $location['area'] ?? null;
    $checkSector = $sector ?? $location['sector'] ?? null;
    $checkCity = $location['city'] ?? 'Lilongwe';

    if (!$checkArea) {
        ResponseHandler::error('Area information is required', 400);
    }

    // Check merchant delivery zones from database
    $zoneStmt = $conn->prepare(
        "SELECT dz.*, mdz.custom_delivery_fee
         FROM merchant_delivery_zones mdz
         JOIN delivery_zones dz ON mdz.zone_id = dz.id
         WHERE mdz.merchant_id = :merchant_id 
         AND mdz.is_active = 1
         AND dz.is_active = 1"
    );
    $zoneStmt->execute([':merchant_id' => $merchantId]);
    $zones = $zoneStmt->fetchAll(PDO::FETCH_ASSOC);

    $inZone = false;
    $deliveryFee = null;
    $estimatedTime = null;
    $matchedZone = null;

    foreach ($zones as $zone) {
        // Parse polygon coordinates from database
        $polygon = json_decode($zone['polygon_coordinates'], true);
        
        // Check if area matches zone name or description
        if (stripos($zone['name'], $checkArea) !== false || 
            stripos($zone['description'], $checkArea) !== false ||
            (isset($polygon['areas']) && in_array($checkArea, $polygon['areas']))) {
            $inZone = true;
            $matchedZone = $zone['name'];
            $deliveryFee = $zone['custom_delivery_fee'] ?? $zone['delivery_fee'];
            $estimatedTime = $zone['estimated_delivery_time'];
            break;
        }
    }

    // If no specific zone found, check merchant's general delivery settings
    if (!$inZone) {
        $merchantStmt = $conn->prepare(
            "SELECT delivery_fee, delivery_time 
             FROM merchants 
             WHERE id = :merchant_id AND is_active = 1"
        );
        $merchantStmt->execute([':merchant_id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($merchant) {
            // Check if merchant delivers to this city
            $cityStmt = $conn->prepare(
                "SELECT COUNT(*) as delivers_to_city 
                 FROM merchant_delivery_zones mdz
                 JOIN delivery_zones dz ON mdz.zone_id = dz.id
                 WHERE mdz.merchant_id = :merchant_id 
                 AND dz.description LIKE CONCAT('%', :city, '%')"
            );
            $cityStmt->execute([
                ':merchant_id' => $merchantId,
                ':city' => $checkCity
            ]);
            $deliversToCity = $cityStmt->fetch(PDO::FETCH_ASSOC)['delivers_to_city'];
            
            if ($deliversToCity > 0) {
                $inZone = true;
                $deliveryFee = $merchant['delivery_fee'];
                $estimatedTime = $merchant['delivery_time'];
                $matchedZone = 'City-wide delivery';
            }
        }
    }

    ResponseHandler::success([
        'in_delivery_zone' => $inZone,
        'delivery_fee' => $deliveryFee,
        'estimated_delivery_time' => $estimatedTime,
        'matched_zone' => $matchedZone,
        'checked_location' => [
            'area' => $checkArea,
            'sector' => $checkSector,
            'city' => $checkCity
        ]
    ]);
}

/*********************************
 * IMPORT LOCATIONS
 *********************************/
function importLocations($input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $locations = $input['locations'] ?? [];
    
    if (!is_array($locations) || empty($locations)) {
        ResponseHandler::error('Locations array is required', 400);
    }

    $db = new Database();
    $conn = $db->getConnection();

    $imported = 0;
    $skipped = 0;
    $errors = [];

    $conn->beginTransaction();

    try {
        foreach ($locations as $index => $location) {
            try {
                // Validate required fields
                $required = ['label', 'address_line1', 'city'];
                foreach ($required as $field) {
                    if (empty($location[$field])) {
                        throw new Exception("Location $index: $field is required");
                    }
                }

                $label = trim($location['label']);
                $addressLine1 = trim($location['address_line1']);
                $city = trim($location['city']);
                $area = trim($location['area'] ?? $city);
                
                // Check for duplicate
                $duplicateStmt = $conn->prepare(
                    "SELECT id FROM addresses 
                     WHERE user_id = :user_id 
                     AND address_line1 = :address_line1 
                     AND city = :city 
                     AND area = :area"
                );
                $duplicateStmt->execute([
                    ':user_id' => $userId,
                    ':address_line1' => $addressLine1,
                    ':city' => $city,
                    ':area' => $area
                ]);
                
                if ($duplicateStmt->fetch()) {
                    $skipped++;
                    continue;
                }

                // Insert location
                $stmt = $conn->prepare(
                    "INSERT INTO addresses 
                        (user_id, label, full_name, phone, address_line1, address_line2, 
                         city, neighborhood, area, sector, location_type, landmark, 
                         latitude, longitude, is_default, created_at, last_used)
                     VALUES 
                        (:user_id, :label, :full_name, :phone, :address_line1, :address_line2, 
                         :city, :neighborhood, :area, :sector, :location_type, :landmark, 
                         :latitude, :longitude, :is_default, NOW(), NOW())"
                );
                
                $stmt->execute([
                    ':user_id' => $userId,
                    ':label' => $label,
                    ':full_name' => trim($location['full_name'] ?? 'User'),
                    ':phone' => trim($location['phone'] ?? ''),
                    ':address_line1' => $addressLine1,
                    ':address_line2' => trim($location['address_line2'] ?? ''),
                    ':city' => $city,
                    ':neighborhood' => trim($location['neighborhood'] ?? ''),
                    ':area' => $area,
                    ':sector' => trim($location['sector'] ?? ''),
                    ':location_type' => trim($location['location_type'] ?? 'other'),
                    ':landmark' => trim($location['landmark'] ?? ''),
                    ':latitude' => isset($location['latitude']) ? floatval($location['latitude']) : null,
                    ':longitude' => isset($location['longitude']) ? floatval($location['longitude']) : null,
                    ':is_default' => isset($location['is_default']) && $location['is_default'] ? 1 : 0
                ]);
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        $conn->commit();
        
        // Log activity
        logUserActivity($conn, $userId, 'locations_imported', "Imported $imported locations", [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => count($errors)
        ]);

        ResponseHandler::success([
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "Successfully imported $imported locations"
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Import failed: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * BULK UPDATE LOCATIONS
 *********************************/
function bulkUpdateLocations($input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $locationIds = $input['location_ids'] ?? [];
    $updates = $input['updates'] ?? [];

    if (!is_array($locationIds) || empty($locationIds)) {
        ResponseHandler::error('Location IDs array is required', 400);
    }

    if (!is_array($updates) || empty($updates)) {
        ResponseHandler::error('Updates array is required', 400);
    }

    // Validate updates
    $allowedFields = ['location_type', 'city', 'area', 'sector', 'neighborhood'];
    foreach (array_keys($updates) as $field) {
        if (!in_array($field, $allowedFields)) {
            ResponseHandler::error("Field '$field' cannot be updated in bulk", 400);
        }
    }

    // Validate location_type if present
    if (isset($updates['location_type'])) {
        $db = new Database();
        $conn = $db->getConnection();
        
        $enumStmt = $conn->query("SHOW COLUMNS FROM addresses LIKE 'location_type'");
        $enumRow = $enumStmt->fetch(PDO::FETCH_ASSOC);
        $validTypes = [];
        
        if ($enumRow && preg_match("/enum\('(.+)'\)/", $enumRow['Type'], $matches)) {
            $validTypes = explode("','", $matches[1]);
        } else {
            $validTypes = ['home', 'work', 'other'];
        }
        
        if (!in_array($updates['location_type'], $validTypes)) {
            ResponseHandler::error('Invalid location type', 400);
        }
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Convert IDs to integers
    $validIds = [];
    foreach ($locationIds as $id) {
        $intId = intval($id);
        if ($intId > 0) {
            $validIds[] = $intId;
        }
    }

    if (empty($validIds)) {
        ResponseHandler::error('No valid location IDs provided', 400);
    }

    // Check ownership of all locations
    $placeholders = implode(',', array_fill(0, count($validIds), '?'));
    $checkStmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM addresses 
         WHERE user_id = ? AND id IN ($placeholders)"
    );
    $checkStmt->execute(array_merge([$userId], $validIds));
    $ownedCount = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];

    if ($ownedCount != count($validIds)) {
        ResponseHandler::error('Some locations do not belong to you or do not exist', 403);
    }

    // Build update query
    $updateFields = [];
    $params = [];
    
    foreach ($updates as $field => $value) {
        $updateFields[] = "$field = ?";
        $params[] = $value;
    }
    
    $updateFields[] = "updated_at = NOW()";
    
    $params = array_merge($params, $validIds);
    
    $sql = "UPDATE addresses SET " . implode(", ", $updateFields) . 
           " WHERE id IN ($placeholders)";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $updatedCount = $stmt->rowCount();

        // Log activity
        logUserActivity($conn, $userId, 'locations_bulk_updated', "Bulk updated $updatedCount locations", [
            'location_ids' => $validIds,
            'updates' => $updates
        ]);

        ResponseHandler::success([
            'message' => "$updatedCount locations updated successfully",
            'updated_count' => $updatedCount,
            'location_ids' => $validIds
        ]);

    } catch (PDOException $e) {
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/*********************************
 * FORMAT LOCATION DATA
 *********************************/
function formatLocationData($loc, $defaultAddressId = null) {
    if (empty($loc)) {
        return null;
    }
    
    // Determine if this is the default location
    $isDefault = boolval($loc['is_default'] ?? false);
    $isCurrent = false;
    
    if (isset($loc['default_address_id'])) {
        $isCurrent = ($loc['id'] == $loc['default_address_id']);
    } elseif ($defaultAddressId !== null) {
        $isCurrent = ($loc['id'] == $defaultAddressId);
    }
    
    // Generate display addresses
    $displayAddress = generateDisplayAddress($loc);
    $shortAddress = generateShortAddress($loc);
    
    // Get type info
    $typeInfo = getLocationTypeInfo($loc['location_type'] ?? 'other');
    
    return [
        'id' => $loc['id'],
        'user_id' => $loc['user_id'] ?? null,
        'name' => $loc['label'] ?? '',
        'full_name' => $loc['full_name'] ?? '',
        'phone' => $loc['phone'] ?? '',
        'address' => $loc['address_line1'] ?? '',
        'apartment' => $loc['address_line2'] ?? '',
        'city' => $loc['city'] ?? '',
        'area' => $loc['area'] ?? '',
        'sector' => $loc['sector'] ?? '',
        'neighborhood' => $loc['neighborhood'] ?? '',
        'landmark' => $loc['landmark'] ?? '',
        'type' => $loc['location_type'] ?? 'other',
        'is_default' => $isDefault,
        'is_current' => $isCurrent,
        'last_used' => $loc['last_used'] ?? null,
        'latitude' => isset($loc['latitude']) ? floatval($loc['latitude']) : null,
        'longitude' => isset($loc['longitude']) ? floatval($loc['longitude']) : null,
        'created_at' => $loc['created_at'] ?? null,
        'updated_at' => $loc['updated_at'] ?? null,
        'display_address' => $displayAddress,
        'short_address' => $shortAddress,
        'type_icon' => $typeInfo['icon'],
        'type_color' => $typeInfo['color']
    ];
}

/*********************************
 * GENERATE DISPLAY ADDRESS
 *********************************/
function generateDisplayAddress($loc) {
    $parts = [];
    
    if (!empty($loc['address_line1'])) {
        $parts[] = $loc['address_line1'];
    }
    
    if (!empty($loc['address_line2'])) {
        $parts[] = $loc['address_line2'];
    }
    
    if (!empty($loc['landmark'])) {
        $parts[] = 'Near ' . $loc['landmark'];
    }
    
    // Use area and sector for addressing
    if (!empty($loc['sector']) && !empty($loc['area'])) {
        $parts[] = $loc['sector'] . ', ' . $loc['area'];
    } elseif (!empty($loc['area'])) {
        $parts[] = $loc['area'];
    }
    
    if (!empty($loc['city'])) {
        $parts[] = $loc['city'];
    }
    
    return implode(', ', array_filter($parts));
}

/*********************************
 * GENERATE SHORT ADDRESS
 *********************************/
function generateShortAddress($loc) {
    $parts = [];
    
    if (!empty($loc['landmark'])) {
        $parts[] = 'Near ' . $loc['landmark'];
    }
    
    if (!empty($loc['area'])) {
        $parts[] = $loc['area'];
    }
    
    if (!empty($loc['sector'])) {
        $parts[] = $loc['sector'];
    }
    
    return implode(', ', $parts);
}

/*********************************
 * GET LOCATION TYPE INFO
 *********************************/
function getLocationTypeInfo($type) {
    $types = [
        'home' => [
            'icon' => 'home',
            'color' => '#2196F3' // Blue
        ],
        'work' => [
            'icon' => 'work',
            'color' => '#4CAF50' // Green
        ],
        'other' => [
            'icon' => 'location_on',
            'color' => '#FF9800' // Orange
        ]
    ];

    return $types[$type] ?? $types['other'];
}

/*********************************
 * LOG USER ACTIVITY
 *********************************/
function logUserActivity($conn, $userId, $activityType, $description, $metadata = null) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO user_activities 
                (user_id, activity_type, description, ip_address, user_agent, metadata, created_at)
             VALUES 
                (:user_id, :activity_type, :description, :ip_address, :user_agent, :metadata, NOW())"
        );
        
        $metaJson = $metadata ? json_encode($metadata) : null;
        
        $stmt->execute([
            ':user_id' => $userId,
            ':activity_type' => $activityType,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':metadata' => $metaJson
        ]);
    } catch (Exception $e) {
        // Silently fail logging
        error_log('Failed to log user activity: ' . $e->getMessage());
    }
}

/*********************************
 * CREATE NOTIFICATION
 *********************************/
function createNotification($conn, $userId, $type, $title, $message, $data = null) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO notifications 
                (user_id, type, title, message, data, sent_at, created_at)
             VALUES 
                (:user_id, :type, :title, :message, :data, NOW(), NOW())"
        );
        
        $dataJson = $data ? json_encode($data) : null;
        
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':data' => $dataJson
        ]);
    } catch (Exception $e) {
        // Silently fail
        error_log('Failed to create notification: ' . $e->getMessage());
    }
}

/*********************************
 * GET USER DETAILS
 *********************************/
function getUserDetails($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            id,
            full_name,
            email,
            phone,
            default_address_id,
            city,
            member_level
        FROM users 
        WHERE id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>