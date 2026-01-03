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
            
            // Ensure payment tables exist
            $this->createPaymentTables();
        } catch (Exception $e) {
            $this->sendError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    private function createPaymentTables() {
        // Check and create payment_transactions table
        $checkQuery = "SHOW TABLES LIKE 'payment_transactions'";
        $stmt = $this->conn->query($checkQuery);
        if ($stmt->rowCount() == 0) {
            $createPaymentTransactions = "
                CREATE TABLE payment_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    merchant_id INT,
                    amount DECIMAL(10,2) NOT NULL,
                    payment_method ENUM('wallet', 'mobile_money', 'card', 'bank') NOT NULL,
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

        // Check and create mobile_money_transactions table
        $checkQuery = "SHOW TABLES LIKE 'mobile_money_transactions'";
        $stmt = $this->conn->query($checkQuery);
        if ($stmt->rowCount() == 0) {
            $createMobileMoneyTransactions = "
                CREATE TABLE mobile_money_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    payment_transaction_id INT NOT NULL,
                    phone_number VARCHAR(20) NOT NULL,
                    network ENUM('airtel', 'tnm', 'mobicash') NOT NULL,
                    transaction_ref VARCHAR(100),
                    status ENUM('initiated', 'pending', 'success', 'failed', 'reversed'),
                    callback_data JSON,
                    verified_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE,
                    INDEX idx_phone (phone_number),
                    INDEX idx_status (status),
                    INDEX idx_network (network)
                )
            ";
            $this->conn->exec($createMobileMoneyTransactions);
        }

        // Check and create user_payment_methods table
        $checkQuery = "SHOW TABLES LIKE 'user_payment_methods'";
        $stmt = $this->conn->query($checkQuery);
        if ($stmt->rowCount() == 0) {
            $createUserPaymentMethods = "
                CREATE TABLE user_payment_methods (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    method_type ENUM('card', 'mobile_money', 'bank_account') NOT NULL,
                    is_default BOOLEAN DEFAULT FALSE,
                    
                    -- For cards (tokenized)
                    card_token VARCHAR(255),
                    card_last_four VARCHAR(4),
                    card_type VARCHAR(20),
                    card_expiry VARCHAR(7),
                    
                    -- For mobile money
                    mobile_number VARCHAR(20),
                    mobile_network VARCHAR(20),
                    
                    -- For bank accounts
                    account_name VARCHAR(255),
                    account_number VARCHAR(50),
                    bank_name VARCHAR(100),
                    
                    status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user (user_id),
                    INDEX idx_default (is_default)
                )
            ";
            $this->conn->exec($createUserPaymentMethods);
        }
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            $this->sendError('Invalid request', 400);
        }

        $action = $input['action'];

        switch ($action) {
            case 'mobile_money_payment':
                $this->processMobileMoneyPayment($input);
                break;
            case 'card_payment':
                $this->processCardPayment($input);
                break;
            case 'direct_payment':
                $this->processDirectPayment($input);
                break;
            case 'get_payment_methods':
                $this->getUserPaymentMethods();
                break;
            case 'save_payment_method':
                $this->savePaymentMethod($input);
                break;
            case 'get_transaction_history':
                $this->getTransactionHistory($input);
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }

    private function processMobileMoneyPayment($data) {
        try {
            $amount = (float)($data['amount'] ?? 0);
            $phone = $data['phone_number'] ?? '';
            $network = $data['network'] ?? 'airtel';
            $merchantId = $data['merchant_id'] ?? null;
            $description = $data['description'] ?? '';
            $saveMethod = $data['save_payment_method'] ?? false;

            // Validation
            if ($amount < 50) {
                throw new Exception('Minimum amount is MK 50');
            }
            if ($amount > 50000) {
                throw new Exception('Maximum mobile money amount is MK 50,000');
            }
            if (!$phone) {
                throw new Exception('Phone number required');
            }

            // Clean and validate phone number
            $cleanPhone = $this->cleanPhoneNumber($phone);
            if (!$this->validateMobileNumber($cleanPhone, $network)) {
                throw new Exception('Invalid ' . ($network == 'airtel' ? 'Airtel' : 'TNM') . ' number');
            }

            $this->conn->beginTransaction();

            try {
                // Generate reference IDs
                $referenceId = 'MM-' . time() . '-' . rand(1000, 9999);
                $transactionId = 'TXN' . time() . rand(1000, 9999);

                // 1. Create payment transaction record
                $stmt = $this->conn->prepare("
                    INSERT INTO payment_transactions 
                    (user_id, merchant_id, amount, payment_method, status, 
                     reference_id, transaction_id, description, created_at)
                    VALUES (?, ?, ?, 'mobile_money', 'pending', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $this->user_id,
                    $merchantId,
                    $amount,
                    $referenceId,
                    $transactionId,
                    $description ?: "Mobile money payment"
                ]);

                $paymentId = $this->conn->lastInsertId();

                // 2. Create mobile money transaction record
                $stmt = $this->conn->prepare("
                    INSERT INTO mobile_money_transactions 
                    (payment_transaction_id, phone_number, network, status, created_at)
                    VALUES (?, ?, ?, 'initiated', NOW())
                ");
                $stmt->execute([
                    $paymentId,
                    $cleanPhone,
                    $network
                ]);

                // 3. Call Mobile Money API (Mock for now)
                $mmResponse = $this->callMobileMoneyAPI([
                    'amount' => $amount,
                    'from' => $cleanPhone,
                    'to' => $merchantId ? $this->getMerchantMobileNumber($merchantId) : 'SYSTEM',
                    'network' => $network,
                    'reference' => $referenceId
                ]);

                if ($mmResponse['success']) {
                    // 4. Update payment status
                    $stmt = $this->conn->prepare("
                        UPDATE payment_transactions 
                        SET status = 'completed', 
                            gateway_response = ?,
                            transaction_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        json_encode($mmResponse),
                        $mmResponse['transaction_id'] ?? $transactionId,
                        $paymentId
                    ]);

                    // 5. Update mobile money transaction
                    $stmt = $this->conn->prepare("
                        UPDATE mobile_money_transactions 
                        SET status = 'success',
                            transaction_ref = ?,
                            verified_at = NOW()
                        WHERE payment_transaction_id = ?
                    ");
                    $stmt->execute([
                        $mmResponse['transaction_ref'] ?? '',
                        $paymentId
                    ]);

                    // 6. Save payment method if requested
                    if ($saveMethod) {
                        $this->saveMobileMoneyPaymentMethod($cleanPhone, $network);
                    }

                    // 7. If merchant exists, update their wallet
                    if ($merchantId) {
                        $this->updateMerchantWallet($merchantId, $amount);
                    }

                    $this->conn->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Payment initiated. Please authorize on your phone.',
                        'data' => [
                            'reference' => $referenceId,
                            'transaction_id' => $transactionId,
                            'amount' => $amount,
                            'payment_id' => $paymentId,
                            'merchant' => $merchantId ? $this->getMerchantName($merchantId) : 'System'
                        ]
                    ]);
                } else {
                    throw new Exception('Mobile money payment failed: ' . $mmResponse['message']);
                }

            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function processDirectPayment($data) {
        try {
            $amount = (float)($data['amount'] ?? 0);
            $method = $data['payment_method'] ?? 'wallet';
            $merchantId = $data['merchant_id'] ?? null;
            $description = $data['description'] ?? '';
            $paymentMethodId = $data['payment_method_id'] ?? null;

            // Validation
            if ($amount < 50) {
                throw new Exception('Minimum amount is MK 50');
            }
            if ($amount > 500000) {
                throw new Exception('Maximum amount is MK 500,000');
            }

            $this->conn->beginTransaction();

            try {
                // Generate reference IDs
                $referenceId = strtoupper($method) . '-' . time() . '-' . rand(1000, 9999);
                $transactionId = 'TXN' . time() . rand(1000, 9999);
                $status = 'pending';

                if ($method === 'wallet') {
                    // Check wallet balance
                    $balance = $this->getWalletBalance();
                    if ($balance < $amount) {
                        throw new Exception('Insufficient wallet balance');
                    }

                    // Deduct from user's wallet
                    $stmt = $this->conn->prepare("
                        UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?
                    ");
                    $stmt->execute([$amount, $this->user_id]);

                    // Add to merchant's wallet if merchant exists
                    if ($merchantId) {
                        $this->updateMerchantWallet($merchantId, $amount);
                    }

                    $status = 'completed';
                    
                } elseif ($method === 'mobile_money') {
                    // Process mobile money
                    if ($paymentMethodId) {
                        // Use saved payment method
                        $savedMethod = $this->getSavedPaymentMethod($paymentMethodId);
                        if (!$savedMethod || $savedMethod['user_id'] != $this->user_id) {
                            throw new Exception('Invalid payment method');
                        }
                        
                        $phone = $savedMethod['mobile_number'];
                        $network = $savedMethod['mobile_network'];
                    } else {
                        $phone = $data['phone_number'] ?? '';
                        $network = $data['network'] ?? 'airtel';
                        
                        if (!$phone) {
                            throw new Exception('Phone number required for mobile money');
                        }
                        
                        $cleanPhone = $this->cleanPhoneNumber($phone);
                        if (!$this->validateMobileNumber($cleanPhone, $network)) {
                            throw new Exception('Invalid mobile number');
                        }
                    }

                    // Call mobile money API
                    $mmResult = $this->callMobileMoneyAPI([
                        'amount' => $amount,
                        'from' => $phone,
                        'to' => $merchantId ? $this->getMerchantMobileNumber($merchantId) : 'SYSTEM',
                        'network' => $network
                    ]);
                    
                    if (!$mmResult['success']) {
                        throw new Exception('Mobile money payment failed');
                    }
                    
                    $status = 'completed';
                    
                } elseif ($method === 'card') {
                    // Process card payment
                    if ($paymentMethodId) {
                        // Use saved card
                        $savedMethod = $this->getSavedPaymentMethod($paymentMethodId);
                        if (!$savedMethod || $savedMethod['user_id'] != $this->user_id) {
                            throw new Exception('Invalid payment method');
                        }
                        
                        $cardResult = $this->processSavedCard($savedMethod, $amount);
                    } else {
                        $cardData = $data['card_details'] ?? [];
                        if (empty($cardData)) {
                            throw new Exception('Card details required');
                        }
                        
                        $cardResult = $this->processCard($cardData, $amount);
                    }
                    
                    if (!$cardResult['success']) {
                        throw new Exception('Card payment failed: ' . $cardResult['message']);
                    }
                    
                    $status = 'completed';
                }

                // 1. Create payment transaction record
                $stmt = $this->conn->prepare("
                    INSERT INTO payment_transactions 
                    (user_id, merchant_id, amount, payment_method, status, 
                     reference_id, transaction_id, description, gateway_response, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $this->user_id,
                    $merchantId,
                    $amount,
                    $method,
                    $status,
                    $referenceId,
                    $transactionId,
                    $description ?: "Payment via {$method}",
                    json_encode(['status' => $status, 'method' => $method])
                ]);

                $paymentId = $this->conn->lastInsertId();

                // 2. Create specific payment method record if needed
                if ($method === 'mobile_money') {
                    $stmt = $this->conn->prepare("
                        INSERT INTO mobile_money_transactions 
                        (payment_transaction_id, phone_number, network, status, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $paymentId,
                        $phone ?? '',
                        $network ?? 'airtel',
                        $status
                    ]);
                }

                // 3. Create wallet transaction for wallet payments
                if ($method === 'wallet') {
                    $stmt = $this->conn->prepare("
                        INSERT INTO wallet_transactions 
                        (user_id, type, amount, payment_method, transaction_id, 
                         status, description, category, reference_id, created_at)
                        VALUES (?, 'debit', ?, ?, ?, ?, ?, 'payment', ?, NOW())
                    ");
                    $stmt->execute([
                        $this->user_id,
                        $amount,
                        $method,
                        $transactionId,
                        $status,
                        $description ?: "Payment via wallet",
                        $referenceId
                    ]);
                }

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment successful',
                    'data' => [
                        'reference' => $referenceId,
                        'transaction_id' => $transactionId,
                        'amount' => $amount,
                        'payment_id' => $paymentId,
                        'merchant' => $merchantId ? $this->getMerchantName($merchantId) : 'System',
                        'new_balance' => $this->getWalletBalance(),
                        'payment_method' => $method
                    ]
                ]);

            } catch (Exception $e) {
                $this->conn->rollBack();
                
                // Log failed payment attempt
                if (isset($referenceId)) {
                    $this->logFailedPayment($referenceId, $method, $amount, $e->getMessage());
                }
                
                throw $e;
            }

        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function getUserPaymentMethods() {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, method_type, is_default, 
                       card_last_four, card_type, card_expiry,
                       mobile_number, mobile_network,
                       account_name, account_number, bank_name,
                       status, created_at
                FROM user_payment_methods
                WHERE user_id = ? AND status = 'active'
                ORDER BY is_default DESC, created_at DESC
            ");
            $stmt->execute([$this->user_id]);
            $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedMethods = array_map(function($method) {
                $data = [
                    'id' => (int)$method['id'],
                    'type' => $method['method_type'],
                    'is_default' => (bool)$method['is_default'],
                    'status' => $method['status'],
                    'created_at' => $method['created_at']
                ];

                if ($method['method_type'] === 'card') {
                    $data['card'] = [
                        'last_four' => $method['card_last_four'],
                        'type' => $method['card_type'],
                        'expiry' => $method['card_expiry']
                    ];
                } elseif ($method['method_type'] === 'mobile_money') {
                    $data['mobile_money'] = [
                        'number' => $method['mobile_number'],
                        'network' => $method['mobile_network']
                    ];
                } elseif ($method['method_type'] === 'bank_account') {
                    $data['bank_account'] = [
                        'account_name' => $method['account_name'],
                        'account_number' => $method['account_number'],
                        'bank_name' => $method['bank_name']
                    ];
                }

                return $data;
            }, $methods);

            echo json_encode([
                'success' => true,
                'data' => [
                    'payment_methods' => $formattedMethods,
                    'count' => count($formattedMethods)
                ]
            ]);

        } catch (Exception $e) {
            $this->sendError('Failed to fetch payment methods: ' . $e->getMessage(), 500);
        }
    }

    private function savePaymentMethod($data) {
        try {
            $methodType = $data['method_type'] ?? '';
            $isDefault = $data['is_default'] ?? false;

            if (!in_array($methodType, ['card', 'mobile_money', 'bank_account'])) {
                throw new Exception('Invalid payment method type');
            }

            $this->conn->beginTransaction();

            try {
                // Reset default if this is new default
                if ($isDefault) {
                    $stmt = $this->conn->prepare("
                        UPDATE user_payment_methods 
                        SET is_default = FALSE 
                        WHERE user_id = ? AND method_type = ?
                    ");
                    $stmt->execute([$this->user_id, $methodType]);
                }

                // Prepare query based on method type
                if ($methodType === 'card') {
                    $cardData = $data['card_details'] ?? [];
                    if (empty($cardData)) {
                        throw new Exception('Card details required');
                    }

                    // Process and tokenize card (mock implementation)
                    $cardToken = $this->tokenizeCard($cardData);
                    
                    $stmt = $this->conn->prepare("
                        INSERT INTO user_payment_methods 
                        (user_id, method_type, is_default, 
                         card_token, card_last_four, card_type, card_expiry,
                         status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                    ");
                    $stmt->execute([
                        $this->user_id,
                        $methodType,
                        $isDefault ? 1 : 0,
                        $cardToken,
                        substr($cardData['number'], -4),
                        $this->detectCardType($cardData['number']),
                        $cardData['expiry'] ?? '',
                    ]);

                } elseif ($methodType === 'mobile_money') {
                    $phone = $data['phone_number'] ?? '';
                    $network = $data['network'] ?? 'airtel';

                    if (!$phone) {
                        throw new Exception('Phone number required');
                    }

                    $cleanPhone = $this->cleanPhoneNumber($phone);
                    if (!$this->validateMobileNumber($cleanPhone, $network)) {
                        throw new Exception('Invalid mobile number');
                    }

                    $stmt = $this->conn->prepare("
                        INSERT INTO user_payment_methods 
                        (user_id, method_type, is_default, 
                         mobile_number, mobile_network,
                         status, created_at)
                        VALUES (?, ?, ?, ?, ?, 'active', NOW())
                    ");
                    $stmt->execute([
                        $this->user_id,
                        $methodType,
                        $isDefault ? 1 : 0,
                        $cleanPhone,
                        $network
                    ]);
                }

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment method saved successfully',
                    'data' => [
                        'method_id' => $this->conn->lastInsertId(),
                        'type' => $methodType,
                        'is_default' => $isDefault
                    ]
                ]);

            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->sendError('Failed to save payment method: ' . $e->getMessage(), 400);
        }
    }

    private function getTransactionHistory($data) {
        try {
            $page = max(1, (int)($data['page'] ?? 1));
            $limit = min(50, max(1, (int)($data['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $method = $data['method'] ?? null;
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
                WHERE pt.user_id = ?
            ";
            
            $params = [$this->user_id];
            
            if ($method) {
                $query .= " AND pt.payment_method = ?";
                $params[] = $method;
            }
            
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
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM payment_transactions WHERE user_id = ?";
            $countParams = [$this->user_id];
            
            if ($method) {
                $countQuery .= " AND payment_method = ?";
                $countParams[] = $method;
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

            echo json_encode([
                'success' => true,
                'data' => [
                    'transactions' => $transactions,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => (int)$total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->sendError('Failed to fetch transaction history: ' . $e->getMessage(), 500);
        }
    }

    // Helper Methods
    private function cleanPhoneNumber($phone) {
        // Remove all non-numeric characters
        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        // Remove country code if present
        if (substr($clean, 0, 3) == '265') {
            $clean = substr($clean, 3);
        }
        
        return $clean;
    }

    private function validateMobileNumber($phone, $network) {
        if (strlen($phone) != 9) return false;
        
        if ($network == 'airtel') {
            return preg_match('/^(099|098)/', $phone);
        } elseif ($network == 'tnm') {
            return preg_match('/^(088|089)/', $phone);
        }
        
        return false;
    }

    private function getMerchant($merchantId) {
        $stmt = $this->conn->prepare("
            SELECT id, name, phone, mobile_money_number, wallet_balance 
            FROM users 
            WHERE id = ? AND user_type = 'merchant' AND status = 'active'
        ");
        $stmt->execute([$merchantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getMerchantName($merchantId) {
        $merchant = $this->getMerchant($merchantId);
        return $merchant ? $merchant['name'] : 'Unknown Merchant';
    }

    private function getMerchantMobileNumber($merchantId) {
        $merchant = $this->getMerchant($merchantId);
        return $merchant ? ($merchant['mobile_money_number'] ?? $merchant['phone']) : '';
    }

    private function updateMerchantWallet($merchantId, $amount) {
        $stmt = $this->conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance + ? 
            WHERE id = ? AND user_type = 'merchant'
        ");
        $stmt->execute([$amount, $merchantId]);
    }

    private function getWalletBalance() {
        $stmt = $this->conn->prepare("
            SELECT wallet_balance FROM users WHERE id = ?
        ");
        $stmt->execute([$this->user_id]);
        return $stmt->fetch(PDO::FETCH_COLUMN) ?? 0;
    }

    private function getSavedPaymentMethod($methodId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM user_payment_methods 
            WHERE id = ? AND user_id = ? AND status = 'active'
        ");
        $stmt->execute([$methodId, $this->user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function saveMobileMoneyPaymentMethod($phone, $network) {
        // Check if already saved
        $stmt = $this->conn->prepare("
            SELECT id FROM user_payment_methods 
            WHERE user_id = ? AND method_type = 'mobile_money' 
            AND mobile_number = ? AND mobile_network = ?
        ");
        $stmt->execute([$this->user_id, $phone, $network]);
        
        if ($stmt->rowCount() == 0) {
            $stmt = $this->conn->prepare("
                INSERT INTO user_payment_methods 
                (user_id, method_type, mobile_number, mobile_network, status, created_at)
                VALUES (?, 'mobile_money', ?, ?, 'active', NOW())
            ");
            $stmt->execute([$this->user_id, $phone, $network]);
        }
    }

    private function callMobileMoneyAPI($data) {
        // Mock implementation - replace with real API call
        sleep(1); // Simulate API delay
        
        // Simulate 95% success rate
        if (rand(1, 100) <= 95) {
            return [
                'success' => true,
                'message' => 'Payment authorized',
                'transaction_id' => 'MM-' . time() . rand(1000, 9999),
                'transaction_ref' => 'REF' . time() . rand(10000, 99999)
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Payment declined by network',
                'code' => 'DECLINED'
            ];
        }
    }

    private function processCard($cardData, $amount) {
        // Mock card processing
        sleep(1);
        
        // Basic validation
        $cardNumber = str_replace(' ', '', $cardData['number'] ?? '');
        if (!preg_match('/^\d{13,19}$/', $cardNumber)) {
            return ['success' => false, 'message' => 'Invalid card number'];
        }
        
        // Validate expiry
        if (!isset($cardData['expiry']) || !preg_match('/^\d{2}\/\d{2}$/', $cardData['expiry'])) {
            return ['success' => false, 'message' => 'Invalid expiry date'];
        }
        
        // Validate CVV
        if (!isset($cardData['cvv']) || !preg_match('/^\d{3,4}$/', $cardData['cvv'])) {
            return ['success' => false, 'message' => 'Invalid CVV'];
        }
        
        // Simulate 90% success rate
        if (rand(1, 100) <= 90) {
            return [
                'success' => true,
                'message' => 'Card authorized',
                'transaction_id' => 'CARD-' . time() . rand(1000, 9999),
                'auth_code' => 'AUTH' . rand(100000, 999999)
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Card declined',
                'code' => 'DECLINED'
            ];
        }
    }

    private function processSavedCard($savedMethod, $amount) {
        // Process payment with saved card token
        sleep(1);
        
        // Simulate 95% success rate for saved cards
        if (rand(1, 100) <= 95) {
            return [
                'success' => true,
                'message' => 'Card authorized',
                'transaction_id' => 'CARD-' . time() . rand(1000, 9999),
                'auth_code' => 'AUTH' . rand(100000, 999999)
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Card declined',
                'code' => 'DECLINED'
            ];
        }
    }

    private function tokenizeCard($cardData) {
        // Mock tokenization - in real system, use payment gateway tokenization
        return 'tok_' . md5($cardData['number'] . time() . rand(1000, 9999));
    }

    private function detectCardType($cardNumber) {
        $cleanNumber = preg_replace('/[^0-9]/', '', $cardNumber);
        
        if (preg_match('/^4/', $cleanNumber)) return 'visa';
        if (preg_match('/^5[1-5]/', $cleanNumber)) return 'mastercard';
        if (preg_match('/^3[47]/', $cleanNumber)) return 'amex';
        if (preg_match('/^6(?:011|5)/', $cleanNumber)) return 'discover';
        if (preg_match('/^62/', $cleanNumber)) return 'unionpay';
        
        return 'unknown';
    }

    private function logFailedPayment($reference, $method, $amount, $error) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO payment_transactions 
                (user_id, amount, payment_method, status, reference_id, 
                 gateway_response, description, created_at)
                VALUES (?, ?, ?, 'failed', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->user_id,
                $amount,
                $method,
                $reference,
                json_encode(['error' => $error]),
                "Failed payment attempt"
            ]);
        } catch (Exception $e) {
            // Silent fail for logging
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