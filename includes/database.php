<?php
// includes/database.php - Database connection class
require_once 'config.php';

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $this->conn->exec("SET time_zone = '+08:00'");

            // Set MySQL session timezone to match PHP
            $this->conn->exec("SET time_zone = '+08:00'"); // Kuala Lumpur is UTC+8

        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            if (ENVIRONMENT === 'development') {
                die("Connection error: " . $exception->getMessage());
            } else {
                die("Database connection failed. Please try again later.");
            }
        }

        return $this->conn;
    }
}
?>