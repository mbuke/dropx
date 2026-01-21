<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/database.php';

class PaymentAPI {
    private $conn;
    private $user_id;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->user_id = $_SESSION['user_id'];
            
            // Ensure tables exist
            $this->createTablesIfNotExist();
        } catch (Exception $e) {
            $this->sendError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    private function createTablesIfNotExist() {
        // Check and create payment_transactions table if needed
        $checkQuery = "SHOW TABLES LIKE 'payment_transactions'";
        $stmt = $this->conn->query($checkQuery);
        if ($stmt->rowCount() == 0) {
            $createPaymentTransactions = "
                CREATE TABLE payment_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    merchant_id INT,
                    amount DECIMAL(10,2) NOT NULL,
                    payment_method ENUM('wallet') NOT NULL DEFAULT 'wallet',
                    status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
                    reference_id VARCHAR(50) UNIQUE,
                    transaction_id VARCHAR(100),
                    gateway_response TEXT,
                    fee DECIMAL(10,2) DEFAULT 0,
                    net_amount DECIMAL(10,2),
                    description VARCHAR(255),
                    metadata JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_merchant (merchant_id),
                    INDEX idx_status (status),
                    INDEX idx_reference (reference_id),
                    INDEX idx_created (created_at)
                )
            ";
            $this->conn->exec($createPaymentTransactions);
        }

        // Check and create wallet_transactions table if needed
        $checkQuery = "SHOW TABLES LIKE 'wallet_transactions'";
        $stmt = $this->conn->query($checkQuery);
        if ($stmt->rowCount() == 0) {
            $createWalletTransactions = "
                CREATE TABLE wallet_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type ENUM('credit', 'debit') NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    payment_method ENUM('wallet') NOT NULL DEFAULT 'wallet',
                    transaction_id VARCHAR(100),
                    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    reference_id VARCHAR(100),
                    sender_id INT,
                    recipient_id INT,
                    payment_method_id INT,
                    balance_before DECIMAL(10,2),
                    balance_after DECIMAL(10,2),
                    category VARCHAR(50),
                    order_id INT,
                    restaurant_id INT,
                    fee DECIMAL(10,2) DEFAULT 0,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    processed_at TIMESTAMP NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_type (type),
                    INDEX idx_status (status),
                    INDEX idx_created (created_at),
                    INDEX idx_reference (reference_id),
                    INDEX idx_order (order_id)
                )
            ";
            $this->conn->exec($createWalletTransactions);
        }

        // Check if users table has wallet_balance column
        $checkColumn = "SHOW COLUMNS FROM users LIKE 'wallet_balance'";
        $stmt = $this->conn->query($checkColumn);
        if ($stmt->rowCount() == 0) {
            $addColumn = "ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(10,2) DEFAULT 0.00";
            $this->conn->exec($addColumn);
        }
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            $this->sendError('Invalid request', 400);
        }

        $action = $input['action'];

        switch ($action) {
            case 'wallet_payment':
                $this->processWalletPayment($input);
                break;
            case 'check_wallet_balance':
                $this->checkWalletBalance();
                break;
            case 'get_wallet_transactions':
                $this->getWalletTransactions($input);
                break;
            case 'get_payment_history':
                $this->getPaymentHistory($input);
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }

    private function processWalletPayment($data) {
        try {
            $amount = (float)($data['amount'] ?? 0);
            $merchantId = $data['merchant_id'] ?? null;
            $orderId = $data['order_id'] ?? null;
            $description = $data['description'] ?? '';

            // Validation
            if ($amount <= 0) {
                throw new Exception('Invalid payment amount');
            }

            // Get user's current wallet balance
            $stmt = $this->conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $balanceBefore = $stmt->fetch(PDO::FETCH_COLUMN);
            
            if ($balanceBefore < $amount) {
                throw new Exception('Insufficient wallet balance. Current balance: MK ' . number_format($balanceBefore, 2));
            }

            $this->conn->beginTransaction();

            try {
                // Generate reference IDs
                $referenceId = 'WALLET-' . time() . '-' . rand(1000, 9999);
                $transactionId = 'TXN' . time() . rand(1000, 9999);
                
                // Calculate balance after payment
                $balanceAfter = $balanceBefore - $amount;

                // 1. Deduct from user's wallet
                $stmt = $this->conn->prepare("
                    UPDATE users 
                    SET wallet_balance = wallet_balance - ? 
                    WHERE id = ? AND wallet_balance >= ?
                ");
                $stmt->execute([$amount, $this->user_id, $amount]);
                
                if ($stmt->rowCount() == 0) {
                    throw new Exception('Failed to deduct from wallet. Please check your balance.');
                }

                // 2. Create payment transaction record
                $stmt = $this->conn->prepare("
                    INSERT INTO payment_transactions 
                    (user_id, merchant_id, amount, payment_method, status, 
                     reference_id, transaction_id, description, metadata, created_at)
                    VALUES (?, ?, ?, 'wallet', 'completed', ?, ?, ?, ?, NOW())
                ");
                
                $metadata = [
                    'order_id' => $orderId,
                    'user_id' => $this->user_id,
                    'merchant_id' => $merchantId,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'payment_timestamp' => time()
                ];
                
                $stmt->execute([
                    $this->user_id,
                    $merchantId,
                    $amount,
                    $referenceId,
                    $transactionId,
                    $description ?: "Wallet payment" . ($orderId ? " for order #$orderId" : ""),
                    json_encode($metadata)
                ]);

                $paymentId = $this->conn->lastInsertId();

                // 3. Create wallet transaction record (type = 'debit' for payment)
                $stmt = $this->conn->prepare("
                    INSERT INTO wallet_transactions 
                    (user_id, type, amount, payment_method, transaction_id, 
                     status, description, reference_id, category, balance_before, balance_after, order_id, created_at)
                    VALUES (?, 'debit', ?, 'wallet', ?, 'completed', ?, ?, 'payment', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $this->user_id,
                    $amount,
                    $transactionId,
                    $description ?: "Payment for order #{$orderId}",
                    $referenceId,
                    $balanceBefore,
                    $balanceAfter,
                    $orderId
                ]);

                // 4. Add to merchant's wallet if merchant exists
                if ($merchantId) {
                    $this->updateMerchantWallet($merchantId, $amount, $balanceBefore, $referenceId, $transactionId);
                }

                // 5. If order ID provided, update order payment status
                if ($orderId) {
                    $this->updateOrderPayment($orderId, $paymentId, $referenceId);
                }

                $this->conn->commit();

                // Get updated balance
                $stmt = $this->conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
                $stmt->execute([$this->user_id]);
                $newBalance = $stmt->fetch(PDO::FETCH_COLUMN);

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment successful',
                    'data' => [
                        'reference_id' => $referenceId,
                        'transaction_id' => $transactionId,
                        'amount' => $amount,
                        'payment_id' => $paymentId,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'current_balance' => $newBalance,
                        'merchant_id' => $merchantId,
                        'order_id' => $orderId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);

            } catch (Exception $e) {
                $this->conn->rollBack();
                
                // Log failed payment attempt
                if (isset($referenceId)) {
                    $this->logFailedPayment($referenceId, $amount, $e->getMessage());
                }
                
                throw $e;
            }

        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function updateMerchantWallet($merchantId, $amount, $userBalanceBefore, $referenceId, $transactionId) {
        try {
            // Get merchant's current balance
            $stmt = $this->conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
            $stmt->execute([$merchantId]);
            $merchantBalanceBefore = $stmt->fetch(PDO::FETCH_COLUMN);
            $merchantBalanceAfter = $merchantBalanceBefore + $amount;

            // Update merchant's wallet balance
            $stmt = $this->conn->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + ? 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $merchantId]);

            // Create wallet transaction for merchant (type = 'credit' for receiving)
            $merchantTransactionId = 'TXN-MER-' . time() . rand(1000, 9999);
            $stmt = $this->conn->prepare("
                INSERT INTO wallet_transactions 
                (user_id, type, amount, payment_method, transaction_id, 
                 status, description, reference_id, category, balance_before, balance_after, created_at)
                VALUES (?, 'credit', ?, 'wallet', ?, 'completed', ?, ?, 'payment_received', ?, ?, NOW())
            ");
            $stmt->execute([
                $merchantId,
                $amount,
                $merchantTransactionId,
                "Payment received from user #{$this->user_id}",
                $referenceId,
                $merchantBalanceBefore,
                $merchantBalanceAfter
            ]);
        } catch (Exception $e) {
            // Log error but don't stop the main payment process
            error_log("Failed to update merchant wallet: " . $e->getMessage());
        }
    }

    private function checkWalletBalance() {
        try {
            $stmt = $this->conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $balance = $stmt->fetch(PDO::FETCH_COLUMN);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'balance' => (float)$balance,
                    'formatted_balance' => 'MK ' . number_format($balance, 2),
                    'user_id' => $this->user_id
                ]
            ]);

        } catch (Exception $e) {
            $this->sendError('Failed to fetch wallet balance: ' . $e->getMessage(), 500);
        }
    }

    private function getWalletTransactions($data) {
        try {
            $page = max(1, (int)($data['page'] ?? 1));
            $limit = min(50, max(1, (int)($data['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $type = $data['type'] ?? null;
            $status = $data['status'] ?? null;
            $startDate = $data['start_date'] ?? null;
            $endDate = $data['end_date'] ?? null;

            // Build query
            $query = "
                SELECT wt.*
                FROM wallet_transactions wt
                WHERE wt.user_id = ?
            ";
            
            $params = [$this->user_id];
            
            if ($type) {
                $query .= " AND wt.type = ?";
                $params[] = $type;
            }
            
            if ($status) {
                $query .= " AND wt.status = ?";
                $params[] = $status;
            }
            
            if ($startDate) {
                $query .= " AND DATE(wt.created_at) >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate) {
                $query .= " AND DATE(wt.created_at) <= ?";
                $params[] = $endDate;
            }
            
            $query .= " ORDER BY wt.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format transactions
            $formattedTransactions = array_map(function($transaction) {
                return [
                    'id' => (int)$transaction['id'],
                    'type' => $transaction['type'],
                    'amount' => (float)$transaction['amount'],
                    'payment_method' => $transaction['payment_method'],
                    'transaction_id' => $transaction['transaction_id'],
                    'status' => $transaction['status'],
                    'description' => $transaction['description'],
                    'reference_id' => $transaction['reference_id'],
                    'balance_before' => (float)$transaction['balance_before'],
                    'balance_after' => (float)$transaction['balance_after'],
                    'category' => $transaction['category'],
                    'order_id' => $transaction['order_id'],
                    'created_at' => $transaction['created_at'],
                    'formatted_amount' => 'MK ' . number_format($transaction['amount'], 2),
                    'is_debit' => $transaction['type'] === 'debit'
                ];
            }, $transactions);

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

            // Get current balance
            $stmt = $this->conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $balance = $stmt->fetch(PDO::FETCH_COLUMN);

            echo json_encode([
                'success' => true,
                'data' => [
                    'transactions' => $formattedTransactions,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => (int)$total,
                        'pages' => ceil($total / $limit)
                    ],
                    'wallet_summary' => [
                        'current_balance' => (float)$balance,
                        'formatted_balance' => 'MK ' . number_format($balance, 2),
                        'total_transactions' => (int)$total
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->sendError('Failed to fetch wallet transactions: ' . $e->getMessage(), 500);
        }
    }

    private function getPaymentHistory($data) {
        try {
            $page = max(1, (int)($data['page'] ?? 1));
            $limit = min(50, max(1, (int)($data['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = $data['status'] ?? null;
            $startDate = $data['start_date'] ?? null;
            $endDate = $data['end_date'] ?? null;

            // Build query
            $query = "
                SELECT pt.*,
                       u.name as merchant_name,
                       u.phone as merchant_phone
                FROM payment_transactions pt
                LEFT JOIN users u ON pt.merchant_id = u.id
                WHERE pt.user_id = ? AND pt.payment_method = 'wallet'
            ";
            
            $params = [$this->user_id];
            
            if ($status) {
                $query .= " AND pt.status = ?";
                $params[] = $status;
            }
            
            if ($startDate) {
                $query .= " AND DATE(pt.created_at) >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate) {
                $query .= " AND DATE(pt.created_at) <= ?";
                $params[] = $endDate;
            }
            
            $query .= " ORDER BY pt.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format payments
            $formattedPayments = array_map(function($payment) {
                $metadata = json_decode($payment['metadata'] ?? '{}', true);
                
                return [
                    'id' => (int)$payment['id'],
                    'amount' => (float)$payment['amount'],
                    'payment_method' => $payment['payment_method'],
                    'status' => $payment['status'],
                    'reference_id' => $payment['reference_id'],
                    'transaction_id' => $payment['transaction_id'],
                    'description' => $payment['description'],
                    'merchant_name' => $payment['merchant_name'],
                    'merchant_phone' => $payment['merchant_phone'],
                    'order_id' => $metadata['order_id'] ?? null,
                    'balance_before' => $metadata['balance_before'] ?? null,
                    'balance_after' => $metadata['balance_after'] ?? null,
                    'created_at' => $payment['created_at'],
                    'formatted_amount' => 'MK ' . number_format($payment['amount'], 2),
                    'formatted_date' => date('M d, Y H:i', strtotime($payment['created_at']))
                ];
            }, $payments);

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM payment_transactions WHERE user_id = ? AND payment_method = 'wallet'";
            $countParams = [$this->user_id];
            
            if ($status) {
                $countQuery .= " AND status = ?";
                $countParams[] = $status;
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

            // Get total amounts
            $sumQuery = "SELECT 
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END) as total_failed
                FROM payment_transactions 
                WHERE user_id = ? AND payment_method = 'wallet'";
            
            $stmt = $this->conn->prepare($sumQuery);
            $stmt->execute([$this->user_id]);
            $sums = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'payments' => $formattedPayments,
                    'summary' => [
                        'total_payments' => (int)$total,
                        'total_amount_paid' => (float)($sums['total_paid'] ?? 0),
                        'total_amount_failed' => (float)($sums['total_failed'] ?? 0),
                        'formatted_total_paid' => 'MK ' . number_format($sums['total_paid'] ?? 0, 2)
                    ],
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => (int)$total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->sendError('Failed to fetch payment history: ' . $e->getMessage(), 500);
        }
    }

    private function updateOrderPayment($orderId, $paymentId, $referenceId) {
        try {
            // Check if orders table exists
            $checkTable = "SHOW TABLES LIKE 'orders'";
            $stmt = $this->conn->query($checkTable);
            
            if ($stmt->rowCount() > 0) {
                $stmt = $this->conn->prepare("
                    UPDATE orders 
                    SET payment_status = 'paid', 
                        payment_id = ?,
                        payment_reference = ?,
                        paid_at = NOW(),
                        status = 'processing'
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$paymentId, $referenceId, $orderId, $this->user_id]);
            }
        } catch (Exception $e) {
            error_log("Failed to update order payment: " . $e->getMessage());
        }
    }

    private function logFailedPayment($reference, $amount, $error) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO payment_transactions 
                (user_id, amount, payment_method, status, reference_id, 
                 gateway_response, description, created_at)
                VALUES (?, ?, 'wallet', 'failed', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->user_id,
                $amount,
                $reference,
                json_encode(['error' => $error]),
                "Failed wallet payment attempt"
            ]);
        } catch (Exception $e) {
            // Silent fail for logging
            error_log("Failed to log failed payment: " . $e->getMessage());
        }
    }

    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code
        ]);
        exit;
    }
}

try {
    $api = new PaymentAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'code' => 500
    ]);
}

ob_end_flush();
?>