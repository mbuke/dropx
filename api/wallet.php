<?php
// wallet.php - WALLET DEPOSIT ONLY VERSION
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => true
    ]);
    exit;
}

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
            
            if ($method === 'GET') {
                $action = $_GET['action'] ?? '';
            } else {
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
                case 'deposit_funds':
                    if ($method !== 'POST') handleError('Method not allowed', 405);
                    $this->depositFunds();
                    break;
                case 'export_transactions':
                    $this->exportTransactions();
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
            $query = "SELECT 
                        u.wallet_balance as balance,
                        COALESCE(SUM(CASE WHEN wt.type = 'credit' THEN wt.amount ELSE 0 END), 0) as total_deposited,
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
                                WHEN wt.type = 'credit' THEN 'deposit'
                                WHEN wt.type = 'debit' THEN 'payment'
                                ELSE wt.type
                            END as display_type,
                            DATE_FORMAT(wt.created_at, '%b %d, %Y %h:%i %p') as formatted_date
                          FROM wallet_transactions wt
                          WHERE wt.user_id = ? 
                          ORDER BY wt.created_at DESC 
                          LIMIT 5";
            
            $stmt = $this->conn->prepare($recentQuery);
            $stmt->execute([$this->user_id]);
            $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'balance' => (float)$user['balance'],
                    'totalDeposited' => (float)$user['total_deposited'],
                    'totalSpent' => (float)$user['total_spent'],
                    'recentTransactions' => $this->formatTransactions($recentTransactions)
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get wallet overview: ' . $e->getMessage());
        }
    }

    private function getRecentTransactions() {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            
            $query = "SELECT wt.*, 
                        o.order_number,
                        DATE_FORMAT(wt.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
                        CASE 
                            WHEN wt.type = 'credit' THEN 'deposit'
                            WHEN wt.type = 'debit' THEN 'payment'
                            ELSE wt.type
                        END as display_type
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
                    WHERE wt.user_id = ? 
                    ORDER BY wt.created_at DESC 
                    LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
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
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            $query = "SELECT wt.*, 
                        o.order_number,
                        DATE_FORMAT(wt.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
                        CASE 
                            WHEN wt.type = 'credit' THEN 'deposit'
                            WHEN wt.type = 'debit' THEN 'payment'
                            ELSE wt.type
                        END as display_type
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
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
            
            foreach ($params as $key => $value) {
                $paramType = $paramTypes[$key] === 'i' ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key + 1, $value, $paramType);
            }
            
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    private function depositFunds() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid request data');
            }
            
            $amount = (float)($input['amount'] ?? 0);
            $paymentMethod = $input['payment_method'] ?? 'wallet';
            $reference = $input['reference'] ?? '';

            if ($amount < 100) {
                throw new Exception('Minimum deposit amount is MWK 100');
            }

            if ($amount > 1000000) {
                throw new Exception('Maximum deposit amount is MWK 1,000,000');
            }

            // Only allow wallet deposits
            if ($paymentMethod !== 'wallet') {
                throw new Exception('Only wallet deposits are allowed');
            }

            // Process deposit
            $paymentResult = $this->processDeposit($amount, $reference);
            
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

                // Create transaction record
                $transactionId = 'DEP' . time() . rand(1000, 9999);
                $txReference = 'DEPOSIT' . time();
                
                $insertTx = "INSERT INTO wallet_transactions 
                            (user_id, type, amount, payment_method, transaction_id, status, 
                             description, reference_id, balance_before, balance_after, 
                             category, created_at)
                            VALUES (?, 'credit', ?, ?, ?, 'completed', 
                                    'Wallet Deposit', ?, ?, ?, 'deposit', NOW())";
                
                $stmt = $this->conn->prepare($insertTx);
                $stmt->execute([
                    $this->user_id,
                    $amount,
                    $paymentMethod,
                    $transactionId,
                    $txReference,
                    $currentBalance,
                    $currentBalance + $amount
                ]);

                $this->conn->commit();

                // Get updated balance
                $stmt = $this->conn->prepare($balanceQuery);
                $stmt->execute([$this->user_id]);
                $newBalance = $stmt->fetch(PDO::FETCH_COLUMN);

                echo json_encode([
                    'success' => true,
                    'message' => 'Deposit successful',
                    'data' => [
                        'reference' => $txReference,
                        'transaction_id' => $transactionId,
                        'amount' => $amount,
                        'previous_balance' => $currentBalance,
                        'new_balance' => $newBalance,
                        'payment_method' => $paymentMethod
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Deposit failed: ' . $e->getMessage());
        }
    }

    private function exportTransactions() {
        try {
            $format = $_POST['format'] ?? 'csv';
            $filters = $_POST['filters'] ?? [];

            $query = "SELECT wt.*, 
                        o.order_number,
                        DATE_FORMAT(wt.created_at, '%Y-%m-%d %H:%i:%s') as date,
                        CASE 
                            WHEN wt.type = 'credit' THEN 'Deposit'
                            ELSE 'Payment'
                        END as type_display
                    FROM wallet_transactions wt
                    LEFT JOIN orders o ON wt.order_id = o.id
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

            if (!empty($filters['startDate'])) {
                $query .= " AND DATE(wt.created_at) >= ?";
                $params[] = $filters['startDate'];
            }

            if (!empty($filters['endDate'])) {
                $query .= " AND DATE(wt.created_at) <= ?";
                $params[] = $filters['endDate'];
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

    private function processDeposit($amount, $reference) {
        // Simulate deposit processing
        sleep(1);
        
        // Validate reference if provided
        if ($reference && strlen($reference) < 5) {
            return [
                'success' => false,
                'message' => 'Invalid payment reference'
            ];
        }

        // Simulate 95% success rate
        if (rand(1, 100) <= 95) {
            return [
                'success' => true,
                'data' => [
                    'transaction_id' => $reference ?: 'DEP' . time(),
                    'amount' => $amount,
                    'method' => 'wallet'
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Deposit processing failed. Please try again.'
            ];
        }
    }

    private function formatTransactions($transactions) {
        $formatted = [];
        foreach ($transactions as $tx) {
            $description = $tx['description'] ?? '';
            if ($tx['order_number'] ?? false) {
                $description = 'Order #' . $tx['order_number'];
            }

            $displayType = 'payment';
            if ($tx['type'] === 'credit') {
                $displayType = 'deposit';
            } elseif ($tx['type'] === 'debit') {
                $displayType = 'payment';
            }

            $formatted[] = [
                'id' => $tx['id'] ?? null,
                'type' => $displayType,
                'amount' => (float)($tx['amount'] ?? 0),
                'description' => $description,
                'method' => 'Wallet',
                'status' => $tx['status'] ?? 'completed',
                'date' => $tx['formatted_date'] ?? ($tx['created_at'] ?? ''),
                'reference' => $tx['transaction_id'] ?? ($tx['reference_id'] ?? ''),
                'category' => $tx['category'] ?? '',
                'balance_before' => (float)($tx['balance_before'] ?? 0),
                'balance_after' => (float)($tx['balance_after'] ?? 0)
            ];
        }
        return $formatted;
    }

    private function generateCSV($transactions) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="wallet-transactions-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['Date', 'Type', 'Description', 'Amount (MWK)', 'Status', 'Reference', 'Balance Before', 'Balance After', 'Payment Method']);
        
        foreach ($transactions as $tx) {
            $description = $tx['description'] ?? '';
            if ($tx['order_number'] ?? false) {
                $description = 'Order #' . $tx['order_number'];
            }

            $type = ($tx['type'] ?? 'debit') === 'credit' ? 'Deposit' : 'Payment';
            
            fputcsv($output, [
                $tx['date'] ?? $tx['created_at'] ?? '',
                $type,
                $description,
                $tx['amount'] ?? 0,
                $tx['status'] ?? 'completed',
                $tx['transaction_id'] ?? ($tx['reference_id'] ?? ''),
                $tx['balance_before'] ?? 0,
                $tx['balance_after'] ?? 0,
                $tx['payment_method'] ?? 'Wallet'
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

ob_end_flush();
?>