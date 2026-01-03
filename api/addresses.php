<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/database.php';

class AddressAPI {
    private $conn;
    private $user_id;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->user_id = $_SESSION['user_id'];
        } catch (Exception $e) {
            $this->sendResponse(false, 'Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                $this->getAddresses();
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true);
                $this->addAddress($input);
                break;
            default:
                $this->sendResponse(false, 'Method not allowed', 405);
        }
    }

    private function getAddresses() {
        try {
            $query = "
                SELECT 
                    id,
                    user_id,
                    title,
                    address,
                    city,
                    state,
                    zip_code,
                    latitude,
                    longitude,
                    is_default,
                    instructions,
                    address_type,
                    created_at,
                    updated_at
                FROM user_addresses 
                WHERE user_id = ? 
                ORDER BY is_default DESC, created_at DESC
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format addresses for frontend
            $formattedAddresses = array_map(function($address) {
                return [
                    'id' => (int)$address['id'],
                    'label' => $address['title'] ?: ucfirst($address['address_type']),
                    'recipient_name' => '', // Not in your table structure
                    'phone' => '', // Not in your table structure
                    'full_address' => $this->formatFullAddress($address),
                    'address' => $address['address'],
                    'city' => $address['city'],
                    'state' => $address['state'],
                    'zip_code' => $address['zip_code'],
                    'latitude' => $address['latitude'] ? (float)$address['latitude'] : null,
                    'longitude' => $address['longitude'] ? (float)$address['longitude'] : null,
                    'is_default' => (bool)$address['is_default'],
                    'instructions' => $address['instructions'],
                    'address_type' => $address['address_type'],
                    'created_at' => $address['created_at']
                ];
            }, $addresses);
            
            // If no addresses, try to get from user profile
            if (empty($formattedAddresses)) {
                $formattedAddresses = $this->getAddressFromUserProfile();
            }
            
            $this->sendResponse(true, [
                'addresses' => $formattedAddresses,
                'count' => count($formattedAddresses)
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Failed to fetch addresses: ' . $e->getMessage(), 500);
        }
    }

    private function formatFullAddress($address) {
        $parts = [];
        if ($address['address']) $parts[] = $address['address'];
        if ($address['city']) $parts[] = $address['city'];
        if ($address['state']) $parts[] = $address['state'];
        if ($address['zip_code']) $parts[] = $address['zip_code'];
        return implode(', ', $parts);
    }

    private function getAddressFromUserProfile() {
        try {
            $query = "SELECT address FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['address']) {
                return [[
                    'id' => 0,
                    'label' => 'Home',
                    'full_address' => $user['address'],
                    'address' => $user['address'],
                    'city' => '',
                    'state' => '',
                    'zip_code' => '',
                    'is_default' => true,
                    'address_type' => 'home',
                    'created_at' => date('Y-m-d H:i:s')
                ]];
            }
            
            return [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function addAddress($data) {
        try {
            if (empty($data['address'])) {
                $this->sendResponse(false, 'Address is required', 400);
            }
            
            $this->conn->beginTransaction();
            
            // If setting as default, remove default from others
            if (isset($data['is_default']) && $data['is_default']) {
                $resetQuery = "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?";
                $resetStmt = $this->conn->prepare($resetQuery);
                $resetStmt->execute([$this->user_id]);
            }
            
            $query = "
                INSERT INTO user_addresses 
                (user_id, title, address, city, state, zip_code, latitude, longitude, 
                 is_default, instructions, address_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $this->user_id,
                $data['title'] ?? ($data['label'] ?? 'Home'),
                $data['address'],
                $data['city'] ?? '',
                $data['state'] ?? '',
                $data['zip_code'] ?? '',
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['is_default'] ?? false ? 1 : 0,
                $data['instructions'] ?? '',
                $data['address_type'] ?? 'home'
            ]);
            
            $this->conn->commit();
            
            $this->sendResponse(true, [
                'address_id' => $this->conn->lastInsertId(),
                'message' => 'Address added successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            $this->sendResponse(false, 'Failed to add address: ' . $e->getMessage(), 500);
        }
    }

    private function sendResponse($success, $data, $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => $success,
            'data' => is_string($data) ? ['message' => $data] : $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}

try {
    $api = new AddressAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>