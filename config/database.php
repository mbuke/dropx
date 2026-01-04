<?php
class Database {

    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Railway MySQL environment variables
        $this->host     = getenv("MYSQLHOST");
        $this->port     = getenv("MYSQLPORT");
        $this->db_name  = getenv("MYSQLDATABASE");
        $this->username = getenv("MYSQLUSER");
        $this->password = getenv("MYSQLPASSWORD");
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";

            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

        } catch (PDOException $exception) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Database connection failed",
                "error"   => $exception->getMessage(),
                "environment" => "Production / Railway MySQL"
            ]);
            exit();
        }

        return $this->conn;
    }
}
?>
