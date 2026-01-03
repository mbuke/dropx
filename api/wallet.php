<?php
// wallet.php - CORRECTED VERSION
// Remove any whitespace or output before headers

// Start output buffering to prevent any accidental output
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Now start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error handling function
function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => true
    ]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    handleError('Unauthorized access. Please login.', 401);
}

require_once '../config/database.php';

class WalletAPI {
    private $conn;
    private $user_id;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            
            $this->user_id = $_SESSION['user_id'];
            
            // Validate user exists
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            if (!$stmt->fetch()) {
                handleError('User not found', 404);
            }
        } catch (Exception $e) {
            handleError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            
            $action = '';
            
            // Get action from GET or POST
            if ($method === 'GET') {
                $action = $_GET['action'] ?? '';
            } else {
                // Read raw input for POST requests
                $input = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    handleError('Invalid JSON input', 400);
                }
                $action = $input['action'] ?? $_POST['action'] ?? '';
            }
            
            if (empty($action)) {
                handleError('No action specified', 400);
            }
            
            switch ($action) {
                case 'overview':
                    $this->getWalletOverview();
                    break;
                case 'recent_transactions':
                    $this->getRecentTransactions();
                    break;
                case 'all_transactions':
                    $this->getAllTransactions();
                    break;
                case 'refund_history':
                    $this->getRefundHistory();
                    break;
                case 'topup':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->topupWallet();
                    break;
                case 'process_qr_payment':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->processQRPayment();
                    break;
                case 'export_transactions':
                    $this->exportTransactions();
                    break;
                case 'request_refund':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->requestRefund();
                    break;
                default:
                    handleError('Invalid action: ' . htmlspecialchars($action), 400);
            }
        } catch (Exception $e) {
            handleError('Request handling failed: ' . $e->getMessage(), 500);
        }
    }

    private function getWalletOverview() {
        try {
            // Get user with wallet balance
            $query = "SELECT 
                        u.wallet_balance as balance,
                        u.total_orders,
                        COALESCE(SUM(CASE WHEN wt.type = 'credit' THEN wt.amount ELSE 0 END), 0) as total_earned,
                        COALESCE(SUM(CASE WHEN wt.type = 'debit' THEN wt.amount ELSE 0 END), 0) as total_spent
                      FROM users u
                      LEFT JOIN wallet_transactions wt ON u.id = wt.user_id
                      WHERE u.id = ?
                      GROUP BY u.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found');
            }

            // Get recent transactions for display
            $recentQuery = "SELECT wt.*, 
                            CASE 
                                WHEN wt.type = 'credit' THEN 'credit'
                                ELSE 'debit'
                            END as display_type,
                            DATE_FORMAT(wt.created_at, '%b %d, %Y %h:%i %p') as formatted_date
                          FROM wallet_transactions wt
                          WHERE wt.user_id = ? 
                          ORDER BY wt.created_at DESC 
                          LIMIT 5";
            
            $stmt = $this->conn->prepare($recentQuery);
            $stmt->execute([$this->user_id]);
            $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get pending refunds count
            $refundQuery = "SELECT COUNT(*) as pending_refunds 
                           FROM wallet_transactions 
                           WHERE user_id = ? 
                             AND type = 'credit' 
                             AND category = 'refund' 
                             AND status = 'pending'";
            $stmt = $this->conn->prepare($refundQuery);
            $stmt->execute([$this->user_id]);
            $pendingRefunds = $stmt->fetch(PDO::FETCH_ASSOC)['pending_refunds'];

            echo json_encode([
                'success' => true,
                'data' => [
                    'balance' => (float)$user['balance'],
                    'totalEarned' => (float)$user['total_earned'],
                    'totalSpent' => (float)$user['total_spent'],
                    'totalOrders' => (int)$user['total_orders'],
                    'recentTransactions' => $this->formatTransactions($recentTransactions),
                    'pendingRefunds' => (int)$pendingRefunds
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get wallet overview: ' . $e->getMessage());
        }
    }

    private function getRecentTransactions() {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            
            // FIXED: Remove OFFSET since it's causing the SQL error
            $query = "SELECT wt.*, 
                        o.order_number,
                        DATE_FORMAT(wt.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
                        CASE 
                            WHEN wt.type = 'credit' THEN 'credit'
                            ELSE 'debit'
                        END as display_type
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
                    WHERE wt.user_id = ? 
                    ORDER BY wt.created_at DESC 
                    LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            // Use bindValue for LIMIT parameter
            $stmt->bindValue(1, $this->user_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'transactions' => $this->formatTransactions($transactions),
                    'total' => count($transactions),
                    'limit' => $limit
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get recent transactions: ' . $e->getMessage());
        }
    }

    private function getAllTransactions() {
        try {
            $type = $_GET['type'] ?? null;
            $status = $_GET['status'] ?? null;
            $category = $_GET['category'] ?? null;
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            $query = "SELECT wt.*, 
                        o.order_number,
                        r.name as merchant_name,
                        DATE_FORMAT(wt.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
                        CASE 
                            WHEN wt.type = 'credit' THEN 'credit'
                            ELSE 'debit'
                        END as display_type
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
                    LEFT JOIN restaurants r ON wt.restaurant_id = r.id
                    WHERE wt.user_id = ?";
            
            $params = [$this->user_id];
            $paramTypes = 'i';

            if ($type && in_array($type, ['credit', 'debit'])) {
                $query .= " AND wt.type = ?";
                $params[] = $type;
                $paramTypes .= 's';
            }

            if ($status && in_array($status, ['pending', 'completed', 'failed'])) {
                $query .= " AND wt.status = ?";
                $params[] = $status;
                $paramTypes .= 's';
            }

            if ($category) {
                $query .= " AND wt.category = ?";
                $params[] = $category;
                $paramTypes .= 's';
            }

            if ($startDate) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                    throw new Exception('Invalid start date format. Use YYYY-MM-DD');
                }
                $query .= " AND DATE(wt.created_at) >= ?";
                $params[] = $startDate;
                $paramTypes .= 's';
            }

            if ($endDate) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    throw new Exception('Invalid end date format. Use YYYY-MM-DD');
                }
                $query .= " AND DATE(wt.created_at) <= ?";
                $params[] = $endDate;
                $paramTypes .= 's';
            }

            $query .= " ORDER BY wt.created_at DESC LIMIT ?";
            $params[] = $limit;
            $paramTypes .= 'i';

            $stmt = $this->conn->prepare($query);
            
            // Bind all parameters properly
            foreach ($params as $key => $value) {
                $paramType = $paramTypes[$key] === 'i' ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key + 1, $value, $paramType);
            }
            
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM wallet_transactions WHERE user_id = ?";
            $countParams = [$this->user_id];
            
            if ($type) {
                $countQuery .= " AND type = ?";
                $countParams[] = $type;
            }
            if ($status) {
                $countQuery .= " AND status = ?";
                $countParams[] = $status;
            }
            if ($category) {
                $countQuery .= " AND category = ?";
                $countParams[] = $category;
            }
            if ($startDate) {
                $countQuery .= " AND DATE(created_at) >= ?";
                $countParams[] = $startDate;
            }
            if ($endDate) {
                $countQuery .= " AND DATE(created_at) <= ?";
                $countParams[] = $endDate;
            }

            $stmt = $this->conn->prepare($countQuery);
            $stmt->execute($countParams);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            echo json_encode([
                'success' => true,
                'data' => [
                    'transactions' => $this->formatTransactions($transactions),
                    'total' => (int)$total,
                    'limit' => $limit
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get all transactions: ' . $e->getMessage());
        }
    }

    private function getRefundHistory() {
        try {
            $query = "SELECT wt.*, 
                        o.order_number,
                        o.status as order_status,
                        r.name as merchant_name,
                        DATE_FORMAT(wt.created_at, '%b %d, %Y') as formatted_date,
                        DATE_FORMAT(wt.updated_at, '%b %d, %Y %h:%i %p') as processed_date
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
                    LEFT JOIN restaurants r ON wt.restaurant_id = r.id
                    WHERE wt.user_id = ? 
                      AND wt.category = 'refund'
                    ORDER BY wt.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedRefunds = [];
            foreach ($refunds as $refund) {
                $description = 'Refund for Order';
                if ($refund['order_number']) {
                    $description .= ' #' . $refund['order_number'];
                }
                if ($refund['merchant_name']) {
                    $description .= ' - ' . $refund['merchant_name'];
                }

                $formattedRefunds[] = [
                    'id' => $refund['id'],
                    'order_id' => $refund['order_id'],
                    'amount' => (float)$refund['amount'],
                    'description' => $description,
                    'reason' => $refund['description'],
                    'status' => $refund['status'],
                    'method' => $refund['payment_method'] ?? 'original_method',
                    'date' => $refund['formatted_date'],
                    'processed_date' => $refund['processed_date'],
                    'reference' => $refund['transaction_id'] ?? $refund['reference_id']
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => ['refunds' => $formattedRefunds]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get refund history: ' . $e->getMessage());
        }
    }

    private function topupWallet() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid request data');
            }
            
            $amount = (float)($input['amount'] ?? 0);
            $method = $input['method'] ?? 'mobile';
            $reference = $input['reference'] ?? '';

            if ($amount < 100) {
                throw new Exception('Minimum amount is MWK 100');
            }

            if ($amount > 500000) {
                throw new Exception('Maximum amount is MWK 500,000');
            }

            // Validate payment method
            $validMethods = ['mobile', 'card', 'bank'];
            if (!in_array($method, $validMethods)) {
                throw new Exception('Invalid payment method');
            }

            // Process payment based on method
            $paymentResult = $this->processPayment($amount, $method, $reference);
            
            if (!$paymentResult['success']) {
                throw new Exception($paymentResult['message']);
            }

            $this->conn->beginTransaction();

            try {
                // Get current balance
                $balanceQuery = "SELECT wallet_balance FROM users WHERE id = ?";
                $stmt = $this->conn->prepare($balanceQuery);
                $stmt->execute([$this->user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentBalance = $user['wallet_balance'];

                // Update user balance
                $updateBalance = "UPDATE users 
                               SET wallet_balance = wallet_balance + ?, 
                                   updated_at = NOW()
                               WHERE id = ?";
                $stmt = $this->conn->prepare($updateBalance);
                $stmt->execute([$amount, $this->user_id]);

                // Create transaction
                $transactionId = 'TUP' . time() . rand(1000, 9999);
                $txReference = 'TOPUP' . time();
                
                $insertTx = "INSERT INTO wallet_transactions 
                            (user_id, type, amount, payment_method, transaction_id, status, 
                             description, reference_id, balance_before, balance_after, 
                             category, created_at)
                            VALUES (?, 'credit', ?, ?, ?, 'completed', 
                                    'Wallet Top-up', ?, ?, ?, 'topup', NOW())";
                
                $stmt = $this->conn->prepare($insertTx);
                $stmt->execute([
                    $this->user_id,
                    $amount,
                    $method,
                    $transactionId,
                    $txReference,
                    $currentBalance,
                    $currentBalance + $amount
                ]);

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Wallet topped up successfully',
                    'data' => [
                        'reference' => $txReference,
                        'transaction_id' => $transactionId,
                        'new_balance' => $currentBalance + $amount,
                        'amount' => $amount
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Topup failed: ' . $e->getMessage());
        }
    }

    private function processQRPayment() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid request data');
            }
            
            $qrData = $input['qr_data'] ?? [];
            $amount = (float)($input['amount'] ?? 0);

            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }

            // Check balance
            $balanceQuery = "SELECT wallet_balance FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($balanceQuery);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found');
            }

            if ($user['wallet_balance'] < $amount) {
                throw new Exception('Insufficient balance');
            }

            $merchantId = $qrData['merchant_id'] ?? null;
            $description = $qrData['merchant_name'] ?? 'QR Payment';
            $category = $qrData['category'] ?? 'shopping';

            $this->conn->beginTransaction();

            try {
                // Get current balance
                $currentBalance = $user['wallet_balance'];

                // Update user balance
                $updateBalance = "UPDATE users 
                               SET wallet_balance = wallet_balance - ?, 
                                   updated_at = NOW()
                               WHERE id = ?";
                $stmt = $this->conn->prepare($updateBalance);
                $stmt->execute([$amount, $this->user_id]);

                // Create transaction
                $reference = 'QR' . time() . rand(1000, 9999);
                $transactionId = 'QR' . time() . rand(1000, 9999);
                
                $insertTx = "INSERT INTO wallet_transactions 
                            (user_id, type, amount, payment_method, transaction_id, status, 
                             description, reference_id, balance_before, balance_after, 
                             category, restaurant_id, created_at)
                            VALUES (?, 'debit', ?, 'qr_payment', ?, 'completed', 
                                    ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $this->conn->prepare($insertTx);
                $stmt->execute([
                    $this->user_id,
                    $amount,
                    $transactionId,
                    $description,
                    $reference,
                    $currentBalance,
                    $currentBalance - $amount,
                    $category,
                    $merchantId
                ]);

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment processed successfully',
                    'data' => [
                        'reference' => $reference,
                        'transaction_id' => $transactionId,
                        'new_balance' => $currentBalance - $amount,
                        'merchant' => $description,
                        'amount' => $amount
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('QR payment failed: ' . $e->getMessage());
        }
    }

    private function requestRefund() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid request data');
            }
            
            $orderId = $input['order_id'] ?? null;
            $reason = $input['reason'] ?? '';
            $details = $input['details'] ?? '';

            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Validate reason
            $reason = trim($reason);
            if (strlen($reason) < 10) {
                throw new Exception('Please provide a detailed reason (minimum 10 characters)');
            }

            // Check if order exists and belongs to user
            $orderQuery = "SELECT o.*, r.name as merchant_name 
                          FROM orders o
                          LEFT JOIN restaurants r ON o.restaurant_id = r.id
                          WHERE o.id = ? AND o.user_id = ?";
            $stmt = $this->conn->prepare($orderQuery);
            $stmt->execute([$orderId, $this->user_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception('Order not found or does not belong to you');
            }

            // Check if order is eligible for refund (within 7 days)
            $orderDate = new DateTime($order['created_at']);
            $currentDate = new DateTime();
            $daysDifference = $currentDate->diff($orderDate)->days;

            if ($daysDifference > 7) {
                throw new Exception('Refunds must be requested within 7 days of order');
            }

            // Check if refund already requested for this order
            $existingRefundQuery = "SELECT COUNT(*) as existing_count 
                                   FROM wallet_transactions 
                                   WHERE order_id = ? 
                                     AND category = 'refund'
                                     AND user_id = ?";
            $stmt = $this->conn->prepare($existingRefundQuery);
            $stmt->execute([$orderId, $this->user_id]);
            $existingCount = $stmt->fetch(PDO::FETCH_ASSOC)['existing_count'];

            if ($existingCount > 0) {
                throw new Exception('Refund already requested for this order');
            }

            // Create refund request (as a pending credit transaction)
            $transactionId = 'REF' . time() . rand(1000, 9999);
            $reference = 'RFND' . time();
            $amount = (float)$order['total_amount'];
            
            $insertRefund = "INSERT INTO wallet_transactions 
                            (user_id, order_id, restaurant_id, type, amount, 
                             payment_method, transaction_id, status, description, 
                             reference_id, category, created_at)
                            VALUES (?, ?, ?, 'credit', ?, ?, ?, 'pending', 
                                    ?, ?, 'refund', NOW())";
            
            $stmt = $this->conn->prepare($insertRefund);
            $stmt->execute([
                $this->user_id,
                $orderId,
                $order['restaurant_id'],
                $amount,
                $order['payment_method'],
                $transactionId,
                "Refund request: {$reason}. " . ($details ? "Details: {$details}" : ""),
                $reference
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Refund request submitted successfully',
                'data' => [
                    'request_id' => $transactionId,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'status' => 'pending',
                    'reference' => $reference
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Refund request failed: ' . $e->getMessage());
        }
    }

    private function exportTransactions() {
        try {
            $format = $_POST['format'] ?? 'csv';
            $filters = $_POST['filters'] ?? [];
            $search = $_POST['search'] ?? '';

            // Build query with filters
            $query = "SELECT wt.*, 
                        o.order_number,
                        r.name as merchant_name,
                        DATE_FORMAT(wt.created_at, '%Y-%m-%d %H:%i:%s') as date,
                        CASE 
                            WHEN wt.type = 'credit' THEN 'Credit'
                            ELSE 'Debit'
                        END as type_display
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
                    LEFT JOIN restaurants r ON wt.restaurant_id = r.id
                    WHERE wt.user_id = ?";
            
            $params = [$this->user_id];

            if (!empty($filters['type']) && $filters['type'] !== 'all') {
                $query .= " AND wt.type = ?";
                $params[] = $filters['type'];
            }

            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $query .= " AND wt.status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['method']) && $filters['method'] !== 'all') {
                $query .= " AND wt.payment_method = ?";
                $params[] = $filters['method'];
            }

            if (!empty($filters['startDate'])) {
                $query .= " AND DATE(wt.created_at) >= ?";
                $params[] = $filters['startDate'];
            }

            if (!empty($filters['endDate'])) {
                $query .= " AND DATE(wt.created_at) <= ?";
                $params[] = $filters['endDate'];
            }

            if (!empty($search)) {
                $query .= " AND (wt.description LIKE ? OR wt.transaction_id LIKE ? OR o.order_number LIKE ?)";
                $searchParam = "%{$search}%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            $query .= " ORDER BY wt.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($format === 'csv') {
                $this->generateCSV($transactions);
                return;
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'transactions' => $this->formatTransactions($transactions),
                        'count' => count($transactions),
                        'exported_at' => date('Y-m-d H:i:s')
                    ]
                ]);
            }
            
        } catch (Exception $e) {
            throw new Exception('Export failed: ' . $e->getMessage());
        }
    }

    private function processPayment($amount, $method, $reference) {
        // Mock payment processor
        $paymentGateways = [
            'mobile' => ['fee' => 0, 'min' => 100, 'max' => 500000],
            'card' => ['fee' => 50, 'min' => 100, 'max' => 500000],
            'bank' => ['fee' => 100, 'min' => 1000, 'max' => 500000]
        ];

        if (!isset($paymentGateways[$method])) {
            return ['success' => false, 'message' => 'Invalid payment method'];
        }

        $gateway = $paymentGateways[$method];

        if ($amount < $gateway['min']) {
            return ['success' => false, 'message' => "Minimum amount for {$method} is MWK {$gateway['min']}"];
        }

        if ($amount > $gateway['max']) {
            return ['success' => false, 'message' => "Maximum amount for {$method} is MWK {$gateway['max']}"];
        }

        // Simulate payment processing
        sleep(1);

        return [
            'success' => true,
            'data' => [
                'transaction_id' => $reference ?: 'PAY' . time(),
                'fee' => $gateway['fee'],
                'net_amount' => $amount - $gateway['fee'],
                'method' => $method
            ]
        ];
    }

    private function formatTransactions($transactions) {
        $formatted = [];
        foreach ($transactions as $tx) {
            $description = $tx['description'] ?? '';
            if ($tx['order_number'] ?? false) {
                $description = 'Order #' . $tx['order_number'];
                if ($tx['merchant_name'] ?? false) {
                    $description .= ' - ' . $tx['merchant_name'];
                }
            }

            $formatted[] = [
                'id' => $tx['id'] ?? null,
                'type' => $tx['display_type'] ?? ($tx['type'] ?? 'debit'),
                'amount' => (float)($tx['amount'] ?? 0),
                'description' => $description,
                'method' => $tx['payment_method'] ?? 'Wallet',
                'status' => $tx['status'] ?? 'completed',
                'date' => $tx['formatted_date'] ?? ($tx['created_at'] ?? ''),
                'reference' => $tx['transaction_id'] ?? ($tx['reference_id'] ?? ''),
                'category' => $tx['category'] ?? '',
                'merchant' => $tx['merchant_name'] ?? null
            ];
        }
        return $formatted;
    }

    private function generateCSV($transactions) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add headers
        fputcsv($output, ['Date', 'Description', 'Type', 'Amount (MWK)', 'Payment Method', 'Status', 'Reference', 'Category', 'Merchant']);
        
        // Add data
        foreach ($transactions as $tx) {
            $description = $tx['description'] ?? '';
            if ($tx['order_number'] ?? false) {
                $description = 'Order #' . $tx['order_number'];
            }
            if ($tx['merchant_name'] ?? false) {
                $description .= ' - ' . $tx['merchant_name'];
            }

            $type = ($tx['type'] ?? 'debit') === 'credit' ? 'Credit' : 'Debit';
            
            fputcsv($output, [
                $tx['date'] ?? $tx['created_at'] ?? '',
                $description,
                $type,
                $tx['amount'] ?? 0,
                $tx['payment_method'] ?? 'Wallet',
                $tx['status'] ?? 'completed',
                $tx['transaction_id'] ?? ($tx['reference_id'] ?? ''),
                $tx['category'] ?? '',
                $tx['merchant_name'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    }
}

try {
    $api = new WalletAPI();
    $api->handleRequest();
} catch (Exception $e) {
    handleError('Application error: ' . $e->getMessage(), 500);
}

// Clean output buffer
ob_end_flush();
?>