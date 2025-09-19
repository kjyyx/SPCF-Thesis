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
            error_log("DEBUG Database->getConnection: Attempting to connect to " . $this->host . "/" . $this->db_name . " as " . $this->username);

            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            error_log("DEBUG Database->getConnection: Connection successful");

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