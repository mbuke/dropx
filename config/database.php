<?php
class Database {
    private $host, $db_name, $username, $password;
    public $conn;

    public function __construct() {
        // Auto-detect if we're on InfinityFree
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        
        if (strpos($currentHost, 'rf.gd') !== false || 
            strpos($currentHost, 'epizy.com') !== false ||
            strpos($currentHost, 'infinityfree') !== false) {
            // INFINITYFREE
            $this->host = "sql100.infinityfree.com";
            $this->db_name = "if0_40806329_delivery_app";
            $this->username = "if0_40806329";
            $this->password = "mbuke80808080";
        } else {
            // LOCAL XAMPP
            $this->host = "localhost";
            $this->db_name = "delivery_app";
            $this->username = "root";
            $this->password = "mbuke80808080";
        }
    }

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
        } catch(PDOException $exception) {
            $response = [
                'success' => false,
                'message' => 'Database connection failed',
                'error' => $exception->getMessage(),
                'environment' => $this->host === 'localhost' ? 'Local' : 'InfinityFree',
                'details' => [
                    'host' => $this->host,
                    'database' => $this->db_name,
                    'user' => $this->username
                ]
            ];
            echo json_encode($response);
            exit();
        }
        
        return $this->conn;
    }
}
?>