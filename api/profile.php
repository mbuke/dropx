<?php
// profile.php - COMPLETE OPTIMIZED VERSION
ob_start();

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============ CORS HEADERS ============
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============ SESSION CONFIGURATION ============
// Reduce session overhead
session_cache_limiter('private_no_expire');
session_cache_expire(60);

ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', true);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 86400);

// Check for session ID in headers for optimization
if (isset($_SERVER['HTTP_X_SESSION_ID'])) {
    session_id($_SERVER['HTTP_X_SESSION_ID']);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'None',
        'read_and_close' => false // Keep session open for multiple operations
    ]);
}

// ============ ERROR HANDLING ============
function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => true,
        'timestamp' => time()
    ]);
    exit;
}

// ============ CHECK AUTHENTICATION ============
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    handleError('Authentication required. Please login.', 401);
}

$user_id = $_SESSION['user_id'];

// ============ INCLUDE DATABASE ============
require_once __DIR__ . '/../config/database.php';

// ============ MAIN API CLASS ============
class ProfileAPI {
    private $conn;
    private $user_id;
    private $base_url = 'https://dropxbackend-production.up.railway.app';
    private $cache = [];
    private $cache_timeout = 300; // 5 minutes

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->user_id = $_SESSION['user_id'];
            
            // Quick user validation with caching
            if (!$this->validateUser()) {
                handleError('User not found', 404);
            }
        } catch (Exception $e) {
            handleError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    private function validateUser() {
        $cacheKey = 'user_exists_' . $this->user_id;
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $stmt = $this->conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$this->user_id]);
        $exists = (bool)$stmt->fetch();
        
        $this->cache[$cacheKey] = $exists;
        return $exists;
    }

    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
            
            if ($method === 'GET') {
                $action = $_GET['action'] ?? 'get_profile';
                $this->handleGetRequest($action);
            } else if ($isMultipart) {
                $action = $_POST['action'] ?? '';
                $this->handleMultipartRequest($action);
            } else {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    handleError('Invalid JSON input', 400);
                }
                
                $action = $data['action'] ?? '';
                
                switch ($method) {
                    case 'POST':
                        $this->handlePostRequest($action, $data);
                        break;
                    case 'PUT':
                        $this->handlePutRequest($action, $data);
                        break;
                    case 'DELETE':
                        $this->handleDeleteRequest($action, $data);
                        break;
                    default:
                        handleError('Method not allowed', 405);
                }
            }
        } catch (Exception $e) {
            handleError('Request handling failed: ' . $e->getMessage(), 500);
        }
    }

    private function handleGetRequest($action) {
        switch ($action) {
            case 'get_profile':
                $light = isset($_GET['light']) && $_GET['light'] == '1';
                $this->getUserProfile($light);
                break;
            case 'addresses':
                $this->getUserAddresses();
                break;
            case 'orders':
                $this->getUserOrders();
                break;
            case 'health':
                $this->healthCheck();
                break;
            default:
                handleError('Invalid action', 400);
        }
    }

    private function handlePostRequest($action, $data) {
        switch ($action) {
            case 'update_profile':
                $this->updateProfile($data);
                break;
            case 'change_password':
                $this->changePassword($data);
                break;
            case 'add_address':
                $this->addAddress($data);
                break;
            default:
                handleError('Invalid action', 400);
        }
    }

    private function handlePutRequest($action, $data) {
        switch ($action) {
            case 'set_default_address':
                $this->setDefaultAddress($data);
                break;
            case 'update_address':
                $this->updateAddress($data);
                break;
            default:
                handleError('Invalid action', 400);
        }
    }

    private function handleDeleteRequest($action, $data) {
        switch ($action) {
            case 'delete_address':
                $this->deleteAddress($data);
                break;
            default:
                handleError('Invalid action', 400);
        }
    }

    private function handleMultipartRequest($action) {
        switch ($action) {
            case 'update_profile':
                $this->updateProfileWithAvatar($_POST, $_FILES);
                break;
            default:
                handleError('Invalid action for multipart request', 400);
        }
    }

    // ============ PROFILE FUNCTIONS ============
    private function getUserProfile($light = false) {
        $cacheKey = 'profile_' . $this->user_id . '_' . ($light ? 'light' : 'full');
        
        // Check memory cache
        if (isset($this->cache[$cacheKey]) && 
            time() - ($this->cache[$cacheKey]['timestamp'] ?? 0) < $this->cache_timeout) {
            echo json_encode($this->cache[$cacheKey]['data']);
            return;
        }
        
        try {
            // Get user data with optimized query
            $query = "SELECT 
                        id, email, full_name, phone, avatar,
                        wallet_balance, member_level, member_points,
                        total_orders, rating, verified,
                        DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
                      FROM users 
                      WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Handle avatar URL
            if (!empty($user['avatar'])) {
                $user['avatar'] = $this->getFullAvatarUrl($user['avatar'], 'large');
            } else {
                // Generate default avatar URL
                $initials = substr($user['full_name'], 0, 2);
                $user['avatar'] = "https://ui-avatars.com/api/?name=" . urlencode($initials) . "&background=random&size=400&bold=true&color=fff";
            }
            
            $response = [
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => $user
                ]
            ];
            
            if (!$light) {
                // Get additional data only if not light mode
                $user['address'] = $this->getUserDefaultAddress();
                $recent_orders = $this->getRecentOrders(5);
                
                $response['data']['recent_orders'] = $recent_orders;
            }
            
            // Cache the response
            $this->cache[$cacheKey] = [
                'data' => $response,
                'timestamp' => time()
            ];
            
            echo json_encode($response);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get profile: ' . $e->getMessage());
        }
    }

    private function getFullAvatarUrl($avatarPath, $size = 'large') {
        if (empty($avatarPath)) {
            return $this->generateDefaultAvatar('User', $size);
        }
        
        // If it's already a full URL, return as is
        if (strpos($avatarPath, 'http') === 0) {
            return $avatarPath;
        }
        
        // Check if file exists
        $root_dir = dirname(dirname(__FILE__));
        $relativePath = ltrim($avatarPath, '/');
        $fullPath = $root_dir . '/' . $relativePath;
        
        if (!file_exists($fullPath)) {
            // File doesn't exist, return default
            return $this->generateDefaultAvatar('User', $size);
        }
        
        // For size variations
        if ($size !== 'large') {
            $pathInfo = pathinfo($avatarPath);
            $sizePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $size . '.' . $pathInfo['extension'];
            $sizeRelativePath = ltrim($sizePath, '/');
            $sizeFullPath = $root_dir . '/' . $sizeRelativePath;
            
            if (file_exists($sizeFullPath)) {
                return $this->base_url . '/' . $sizeRelativePath;
            }
        }
        
        // Return original
        $cleanPath = ltrim($avatarPath, '/');
        return $this->base_url . '/' . $cleanPath;
    }
    
    private function generateDefaultAvatar($name, $size = 'large') {
        $sizes = [
            'large' => 400,
            'medium' => 200,
            'small' => 100,
            'thumbnail' => 50
        ];
        
        $sizeValue = $sizes[$size] ?? 400;
        $initials = strtoupper(substr($name, 0, 2));
        
        return "https://ui-avatars.com/api/?name=" . urlencode($initials) . 
               "&background=random&size=" . $sizeValue . "&bold=true&color=fff";
    }

    private function getUserDefaultAddress() {
        $cacheKey = 'default_address_' . $this->user_id;
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $query = "SELECT address, city 
                 FROM user_addresses 
                 WHERE user_id = ? AND is_default = 1 
                 LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$this->user_id]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($address) {
            $fullAddress = $address['address'];
            if (!empty($address['city'])) {
                $fullAddress .= ', ' . $address['city'];
            }
            $this->cache[$cacheKey] = $fullAddress;
            return $fullAddress;
        }
        
        $this->cache[$cacheKey] = '';
        return '';
    }

    private function getRecentOrders($limit = 5) {
        $cacheKey = 'recent_orders_' . $this->user_id . '_' . $limit;
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $query = "SELECT 
                    o.id, o.order_number, o.total_amount, o.status, 
                    DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                    r.name as restaurant_name,
                    COUNT(oi.id) as item_count
                  FROM orders o
                  LEFT JOIN restaurants r ON o.restaurant_id = r.id
                  LEFT JOIN order_items oi ON o.id = oi.order_id
                  WHERE o.user_id = ?
                  GROUP BY o.id
                  ORDER BY o.created_at DESC 
                  LIMIT ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$this->user_id, $limit]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->cache[$cacheKey] = $orders;
        return $orders;
    }

    private function updateProfileData($data, $files = null) {
        try {
            // Validate required fields
            if (empty($data['full_name'])) {
                throw new Exception('Full name is required');
            }
            
            if (empty($data['email'])) {
                throw new Exception('Email is required');
            }
            
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            // Check if email exists for another user
            $checkQuery = "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([trim($data['email']), $this->user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Email already in use by another account');
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Handle avatar upload
                $avatar_url = null;
                if ($files && isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatar_url = $this->uploadAvatar($files['avatar']);
                }
                
                // Build update query
                $updateFields = [];
                $params = [];
                
                $fields = [
                    'full_name' => trim($data['full_name']),
                    'email' => trim($data['email'])
                ];
                
                if (isset($data['phone'])) {
                    $fields['phone'] = trim($data['phone']);
                }
                
                if ($avatar_url) {
                    $fields['avatar'] = $avatar_url;
                }
                
                $fields['updated_at'] = 'CURRENT_TIMESTAMP';
                
                foreach ($fields as $field => $value) {
                    if ($value === 'CURRENT_TIMESTAMP') {
                        $updateFields[] = "$field = CURRENT_TIMESTAMP";
                    } else {
                        $updateFields[] = "$field = ?";
                        $params[] = $value;
                    }
                }
                
                // Update user
                $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
                $params[] = $this->user_id;
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                
                // Update address if provided
                if (isset($data['address']) && !empty(trim($data['address']))) {
                    $this->updateUserAddress(trim($data['address']));
                }
                
                // Get updated user data
                $userQuery = "SELECT 
                                id, email, full_name, phone, avatar,
                                wallet_balance, total_orders, rating, verified,
                                DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
                              FROM users 
                              WHERE id = ?";
                
                $stmt = $this->conn->prepare($userQuery);
                $stmt->execute([$this->user_id]);
                $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Handle avatar URL
                if (!empty($updated_user['avatar'])) {
                    $updated_user['avatar'] = $this->getFullAvatarUrl($updated_user['avatar'], 'large');
                }
                
                // Get default address
                $updated_user['address'] = $this->getUserDefaultAddress();
                
                $this->conn->commit();
                
                // Clear relevant cache
                $this->clearProfileCache();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Profile updated successfully',
                    'data' => [
                        'user' => $updated_user
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Profile update failed: ' . $e->getMessage());
        }
    }

    private function clearProfileCache() {
        $cacheKeys = [
            'profile_' . $this->user_id . '_light',
            'profile_' . $this->user_id . '_full',
            'default_address_' . $this->user_id,
            'user_exists_' . $this->user_id
        ];
        
        foreach ($cacheKeys as $key) {
            unset($this->cache[$key]);
        }
    }

    private function uploadAvatar($file) {
        try {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file['type'], $allowed_types)) {
                throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
            }
            
            if ($file['size'] > $max_size) {
                throw new Exception('File size exceeds 2MB limit');
            }
            
            $current_dir = dirname(__FILE__);
            $root_dir = dirname($current_dir);
            $upload_dir = $root_dir . '/uploads/avatars/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception('Failed to create upload directory');
                }
            }
            
            // Generate unique filename (use webp for better compression)
            $ext = 'webp';
            $filename = 'avatar_' . $this->user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filepath = $upload_dir . $filename;
            
            // Resize and save image
            $this->createAvatarSizes($file['tmp_name'], $filepath);
            
            // Delete original uploaded file
            unlink($file['tmp_name']);
            
            // Return relative path
            return '/uploads/avatars/' . $filename;
            
        } catch (Exception $e) {
            throw new Exception('Avatar upload failed: ' . $e->getMessage());
        }
    }

    private function createAvatarSizes($sourcePath, $destinationBasePath) {
        $sizes = [
            'large' => 400,
            'medium' => 200,
            'small' => 100,
            'thumbnail' => 50
        ];
        
        $extension = pathinfo($destinationBasePath, PATHINFO_EXTENSION);
        $filename = pathinfo($destinationBasePath, PATHINFO_FILENAME);
        $directory = dirname($destinationBasePath);
        
        foreach ($sizes as $sizeName => $size) {
            $destinationPath = $directory . '/' . $filename . '_' . $sizeName . '.' . $extension;
            $this->resizeImage($sourcePath, $destinationPath, $size, $size);
        }
    }

    private function resizeImage($sourcePath, $destinationPath, $maxWidth, $maxHeight) {
        // Get image info
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }
        
        $mime = $imageInfo['mime'];
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // Calculate new dimensions
        $ratio = $width / $height;
        $newWidth = $maxWidth;
        $newHeight = $maxHeight;
        
        if ($width > $height) {
            $newHeight = $maxWidth / $ratio;
        } else {
            $newWidth = $maxHeight * $ratio;
        }
        
        // Create image resource based on mime type
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $source = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $source = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $source = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // Create new image
        $destination = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagecolortransparent($destination, imagecolorallocatealpha($destination, 0, 0, 0, 127));
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
        }
        
        // Resize image
        imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Save as WebP (better compression)
        imagewebp($destination, $destinationPath, 80);
        
        // Clean up
        imagedestroy($source);
        imagedestroy($destination);
        
        return true;
    }

    private function updateUserAddress($address) {
        try {
            // Check if user has default address
            $checkQuery = "SELECT id FROM user_addresses 
                          WHERE user_id = ? AND is_default = 1 
                          LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Update existing address
                $updateQuery = "UPDATE user_addresses 
                               SET address = ?, updated_at = CURRENT_TIMESTAMP 
                               WHERE id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute([$address, $result['id']]);
            } else {
                // Create new address
                $insertQuery = "INSERT INTO user_addresses 
                               (user_id, title, address, city, address_type, is_default, created_at)
                               VALUES (?, 'Home', ?, '', 'home', 1, CURRENT_TIMESTAMP)";
                $stmt = $this->conn->prepare($insertQuery);
                $stmt->execute([$this->user_id, $address]);
            }
            
            // Clear address cache
            unset($this->cache['default_address_' . $this->user_id]);
            
        } catch (Exception $e) {
            // Address update is not critical, log and continue
            error_log('Address update failed: ' . $e->getMessage());
        }
    }

    private function getUserAddresses() {
        $cacheKey = 'addresses_' . $this->user_id;
        
        if (isset($this->cache[$cacheKey]) && 
            time() - ($this->cache[$cacheKey]['timestamp'] ?? 0) < $this->cache_timeout) {
            echo json_encode($this->cache[$cacheKey]['data']);
            return;
        }
        
        try {
            $query = "SELECT 
                        id, title, address, city, state, zip_code,
                        latitude, longitude, is_default, instructions,
                        address_type,
                        DATE_FORMAT(created_at, '%Y-%m-%d') as created_date
                      FROM user_addresses 
                      WHERE user_id = ? 
                      ORDER BY is_default DESC, created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'message' => 'Addresses retrieved successfully',
                'data' => [
                    'addresses' => $addresses
                ]
            ];
            
            $this->cache[$cacheKey] = [
                'data' => $response,
                'timestamp' => time()
            ];
            
            echo json_encode($response);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get addresses: ' . $e->getMessage());
        }
    }

    private function addAddress($data) {
        try {
            // Validate required fields
            if (empty($data['title']) || empty($data['address']) || empty($data['city'])) {
                throw new Exception('Title, address, and city are required');
            }
            
            // Check if this is the first address
            $checkQuery = "SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$this->user_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            $is_default = $count == 0 ? 1 : 0;
            
            // If setting as default, clear other defaults
            if (isset($data['is_default']) && $data['is_default']) {
                $clearQuery = "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?";
                $stmt = $this->conn->prepare($clearQuery);
                $stmt->execute([$this->user_id]);
                $is_default = 1;
            }
            
            // Insert new address
            $query = "INSERT INTO user_addresses 
                     (user_id, title, address, city, state, zip_code, 
                      address_type, is_default, instructions, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
            
            $state = $data['state'] ?? '';
            $zip_code = $data['zip_code'] ?? '';
            $address_type = $data['address_type'] ?? 'other';
            $instructions = $data['instructions'] ?? '';
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $this->user_id,
                $data['title'],
                $data['address'],
                $data['city'],
                $state,
                $zip_code,
                $address_type,
                $is_default,
                $instructions
            ]);
            
            $address_id = $this->conn->lastInsertId();
            
            // Clear addresses cache
            unset($this->cache['addresses_' . $this->user_id]);
            unset($this->cache['default_address_' . $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Address added successfully',
                'data' => [
                    'address_id' => $address_id
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to add address: ' . $e->getMessage());
        }
    }

    private function setDefaultAddress($data) {
        try {
            if (empty($data['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $address_id = $data['address_id'];
            
            // Verify ownership
            $checkQuery = "SELECT id FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Address not found');
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Clear all defaults
                $clearQuery = "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?";
                $stmt = $this->conn->prepare($clearQuery);
                $stmt->execute([$this->user_id]);
                
                // Set new default
                $updateQuery = "UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute([$address_id, $this->user_id]);
                
                $this->conn->commit();
                
                // Clear cache
                unset($this->cache['addresses_' . $this->user_id]);
                unset($this->cache['default_address_' . $this->user_id]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Default address updated successfully'
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to set default address: ' . $e->getMessage());
        }
    }

    private function updateAddress($data) {
        try {
            if (empty($data['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $address_id = $data['address_id'];
            
            // Verify ownership
            $checkQuery = "SELECT id FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Address not found');
            }
            
            // Build update query dynamically
            $updates = [];
            $params = [];
            
            $fields = [
                'title', 'address', 'city', 'state', 'zip_code', 
                'address_type', 'instructions'
            ];
            
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            $sql = "UPDATE user_addresses SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
            $params[] = $address_id;
            $params[] = $this->user_id;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            // Clear cache
            unset($this->cache['addresses_' . $this->user_id]);
            unset($this->cache['default_address_' . $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Address updated successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to update address: ' . $e->getMessage());
        }
    }

    private function deleteAddress($data) {
        try {
            if (empty($data['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $address_id = $data['address_id'];
            
            // Check if exists and is default
            $checkQuery = "SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception('Address not found');
            }
            
            if ($result['is_default'] == 1) {
                throw new Exception('Cannot delete default address');
            }
            
            // Delete address
            $deleteQuery = "DELETE FROM user_addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($deleteQuery);
            $stmt->execute([$address_id, $this->user_id]);
            
            // Clear cache
            unset($this->cache['addresses_' . $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Address deleted successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to delete address: ' . $e->getMessage());
        }
    }

    private function getUserOrders() {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $status = $_GET['status'] ?? '';
        
        $cacheKey = 'orders_' . $this->user_id . '_' . $page . '_' . $limit . '_' . $status;
        
        if (isset($this->cache[$cacheKey]) && 
            time() - ($this->cache[$cacheKey]['timestamp'] ?? 0) < $this->cache_timeout) {
            echo json_encode($this->cache[$cacheKey]['data']);
            return;
        }
        
        try {
            $offset = ($page - 1) * $limit;
            
            // Build query
            $where = "WHERE o.user_id = ?";
            $params = [$this->user_id];
            
            if (!empty($status)) {
                $where .= " AND o.status = ?";
                $params[] = $status;
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM orders o $where";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get orders with optimized query
            $sql = "SELECT 
                    o.*,
                    r.name as restaurant_name,
                    r.image as restaurant_image,
                    DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                    COUNT(oi.id) as item_count
                  FROM orders o
                  LEFT JOIN restaurants r ON o.restaurant_id = r.id
                  LEFT JOIN order_items oi ON o.id = oi.order_id
                  $where
                  GROUP BY o.id
                  ORDER BY o.created_at DESC
                  LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => [
                    'orders' => $orders,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ];
            
            $this->cache[$cacheKey] = [
                'data' => $response,
                'timestamp' => time()
            ];
            
            echo json_encode($response);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get orders: ' . $e->getMessage());
        }
    }

    private function changePassword($data) {
        try {
            $required = ['current_password', 'new_password', 'confirm_password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("$field is required");
                }
            }
            
            if ($data['new_password'] !== $data['confirm_password']) {
                throw new Exception('New passwords do not match');
            }
            
            if (strlen($data['new_password']) < 6) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            // Get current password hash
            $query = "SELECT password FROM users WHERE id = ? LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Verify current password
            if (!password_verify($data['current_password'], $user['password'])) {
                throw new Exception('Current password is incorrect');
            }
            
            // Hash new password
            $new_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
            
            // Update password
            $updateQuery = "UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->conn->prepare($updateQuery);
            $stmt->execute([$new_hash, $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to change password: ' . $e->getMessage());
        }
    }

    private function healthCheck() {
        $response = [
            'status' => 'ok',
            'timestamp' => time(),
            'session' => [
                'active' => isset($_SESSION['user_id']),
                'user_id' => $_SESSION['user_id'] ?? null
            ],
            'avatar_dir' => [
                'path' => dirname(__FILE__) . '/../uploads/avatars',
                'exists' => file_exists(dirname(__FILE__) . '/../uploads/avatars'),
                'writable' => is_writable(dirname(__FILE__) . '/../uploads/avatars')
            ],
            'cache' => [
                'size' => count($this->cache),
                'keys' => array_keys($this->cache)
            ]
        ];
        
        echo json_encode($response);
    }
}

// ============ MAIN EXECUTION ============
try {
    $api = new ProfileAPI();
    $api->handleRequest();
} catch (Exception $e) {
    handleError('Application error: ' . $e->getMessage(), 500);
}

ob_end_flush();
?>