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

class OrderAPI {
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
        $action = $_GET['action'] ?? '';
        
        switch ($method) {
            case 'GET':
                if ($action === 'history') {
                    $this->getOrderHistory();
                } else {
                    $this->getOrderDetails();
                }
                break;
            case 'POST':
                $this->createOrder();
                break;
            case 'PUT':
                $this->updateOrder();
                break;
            default:
                $this->sendResponse(false, 'Method not allowed', 405);
        }
    }

    private function createOrder() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validate required data
            if (empty($input['items'])) {
                $this->sendResponse(false, 'No items in order', 400);
            }
            
            if (empty($input['delivery_info']['address'])) {
                $this->sendResponse(false, 'Delivery address is required', 400);
            }
            
            $this->conn->beginTransaction();
            
            // Generate order number
            $orderNumber = 'ORD' . date('YmdHis') . rand(100, 999);
            
            // Calculate totals
            $subtotal = $input['totals']['subtotal'] ?? $this->calculateSubtotal($input['items']);
            $deliveryFee = $input['totals']['deliveryFee'] ?? 1500;
            $tax = $input['totals']['tax'] ?? $subtotal * 0.1;
            $totalAmount = $subtotal + $deliveryFee + $tax;
            
            // Get restaurant ID from first item
            $restaurantId = $input['items'][0]['restaurant_id'] ?? null;
            
            // Insert into orders table (matching your table structure)
            $orderQuery = "
                INSERT INTO orders 
                (user_id, order_number, restaurant_id, total_amount, delivery_fee, 
                 tax_amount, status, payment_method, delivery_address, 
                 delivery_instructions, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
            ";
            
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute([
                $this->user_id,
                $orderNumber,
                $restaurantId,
                $totalAmount,
                $deliveryFee,
                $tax,
                $input['payment_info']['method'] ?? 'cod',
                $input['delivery_info']['address'],
                $input['delivery_info']['instructions'] ?? ''
            ]);
            
            $orderId = $this->conn->lastInsertId();
            
            // Insert order items
            foreach ($input['items'] as $item) {
                $itemTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                
                $itemQuery = "
                    INSERT INTO order_items 
                    (order_id, item_name, quantity, price, total_price, special_instructions)
                    VALUES (?, ?, ?, ?, ?, ?)
                ";
                
                $itemStmt = $this->conn->prepare($itemQuery);
                $itemStmt->execute([
                    $orderId,
                    $item['name'],
                    $item['quantity'] ?? 1,
                    $item['price'] ?? 0,
                    $itemTotal,
                    $item['special_instructions'] ?? ''
                ]);
            }
            
            // Update user address if address_id provided
            if (!empty($input['delivery_info']['address_id'])) {
                $this->updateLastUsedAddress($input['delivery_info']['address_id']);
            }
            
            $this->conn->commit();
            
            // Return order details
            $orderDetails = $this->getOrderById($orderId);
            
            $this->sendResponse(true, [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'message' => 'Order created successfully',
                'order' => $orderDetails
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            $this->sendResponse(false, 'Failed to create order: ' . $e->getMessage(), 500);
        }
    }

    private function calculateSubtotal($items) {
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }
        return $subtotal;
    }

    private function getOrderById($orderId) {
        try {
            // Get order
            $orderQuery = "
                SELECT 
                    o.*,
                    r.name as restaurant_name,
                    r.image as restaurant_image
                FROM orders o
                LEFT JOIN restaurants r ON o.restaurant_id = r.id
                WHERE o.id = ? AND o.user_id = ?
            ";
            
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute([$orderId, $this->user_id]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return null;
            }
            
            // Get order items
            $itemsQuery = "
                SELECT * FROM order_items WHERE order_id = ?
            ";
            
            $itemsStmt = $this->conn->prepare($itemsQuery);
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'order' => $order,
                'items' => $items
            ];
            
        } catch (Exception $e) {
            return null;
        }
    }

    private function getOrderHistory() {
        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM orders WHERE user_id = ?";
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->execute([$this->user_id]);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get orders with restaurant info
            $ordersQuery = "
                SELECT 
                    o.*,
                    r.name as restaurant_name,
                    r.image as restaurant_image
                FROM orders o
                LEFT JOIN restaurants r ON o.restaurant_id = r.id
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $ordersStmt = $this->conn->prepare($ordersQuery);
            $ordersStmt->bindValue(1, $this->user_id, PDO::PARAM_INT);
            $ordersStmt->bindValue(2, $limit, PDO::PARAM_INT);
            $ordersStmt->bindValue(3, $offset, PDO::PARAM_INT);
            $ordersStmt->execute();
            $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get items for each order
            $formattedOrders = [];
            foreach ($orders as $order) {
                $itemsQuery = "SELECT * FROM order_items WHERE order_id = ?";
                $itemsStmt = $this->conn->prepare($itemsQuery);
                $itemsStmt->execute([$order['id']]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $formattedOrders[] = [
                    'id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'restaurant_name' => $order['restaurant_name'],
                    'restaurant_image' => $order['restaurant_image'],
                    'total_amount' => (float)$order['total_amount'],
                    'delivery_fee' => (float)$order['delivery_fee'],
                    'tax_amount' => (float)$order['tax_amount'],
                    'status' => $order['status'],
                    'payment_method' => $order['payment_method'],
                    'delivery_address' => $order['delivery_address'],
                    'created_at' => $order['created_at'],
                    'delivered_at' => $order['delivered_at'],
                    'items' => $items,
                    'item_count' => count($items)
                ];
            }
            
            $this->sendResponse(true, [
                'orders' => $formattedOrders,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Failed to fetch order history: ' . $e->getMessage(), 500);
        }
    }

    private function getOrderDetails() {
        try {
            $orderId = $_GET['id'] ?? null;
            
            if (!$orderId) {
                $this->sendResponse(false, 'Order ID is required', 400);
            }
            
            $orderDetails = $this->getOrderById($orderId);
            
            if (!$orderDetails) {
                $this->sendResponse(false, 'Order not found', 404);
            }
            
            $this->sendResponse(true, $orderDetails);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Failed to fetch order details: ' . $e->getMessage(), 500);
        }
    }

    private function updateOrder() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $orderId = $input['order_id'] ?? null;
            $action = $input['action'] ?? '';
            
            if (!$orderId) {
                $this->sendResponse(false, 'Order ID is required', 400);
            }
            
            // Verify order belongs to user
            $verifyQuery = "SELECT id FROM orders WHERE id = ? AND user_id = ?";
            $verifyStmt = $this->conn->prepare($verifyQuery);
            $verifyStmt->execute([$orderId, $this->user_id]);
            
            if ($verifyStmt->rowCount() === 0) {
                $this->sendResponse(false, 'Order not found or unauthorized', 404);
            }
            
            switch ($action) {
                case 'cancel':
                    $this->cancelOrder($orderId);
                    break;
                case 'update_status':
                    $this->updateOrderStatus($orderId, $input['status'] ?? '');
                    break;
                default:
                    $this->sendResponse(false, 'Invalid action', 400);
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Failed to update order: ' . $e->getMessage(), 500);
        }
    }

    private function cancelOrder($orderId) {
        try {
            // Check if order can be cancelled (only pending orders)
            $checkQuery = "SELECT status FROM orders WHERE id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$orderId]);
            $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order['status'] !== 'pending') {
                $this->sendResponse(false, 'Order cannot be cancelled at this stage', 400);
            }
            
            $updateQuery = "UPDATE orders SET status = 'cancelled' WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([$orderId]);
            
            $this->sendResponse(true, 'Order cancelled successfully');
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function updateOrderStatus($orderId, $status) {
        try {
            $validStatuses = ['pending', 'confirmed', 'preparing', 'on_delivery', 'delivered', 'cancelled'];
            
            if (!in_array($status, $validStatuses)) {
                $this->sendResponse(false, 'Invalid status', 400);
            }
            
            $updateData = ['status' => $status];
            
            // If delivered, set delivered_at timestamp
            if ($status === 'delivered') {
                $updateData['delivered_at'] = date('Y-m-d H:i:s');
            }
            
            $setClause = implode(', ', array_map(function($key) {
                return "$key = ?";
            }, array_keys($updateData)));
            
            $values = array_values($updateData);
            $values[] = $orderId;
            
            $updateQuery = "UPDATE orders SET $setClause WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute($values);
            
            $this->sendResponse(true, 'Order status updated successfully');
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function updateLastUsedAddress($addressId) {
        try {
            $query = "UPDATE user_addresses SET updated_at = NOW() WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$addressId, $this->user_id]);
        } catch (Exception $e) {
            // Silently fail - not critical
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
    $api = new OrderAPI();
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