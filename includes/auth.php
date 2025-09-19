<?php
// includes/auth.php - Authentication functions
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

class Auth {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function login($userId, $password, $loginType) {
        try {
            // DEBUG: Log input parameters
            error_log("DEBUG Auth->login: userId=$userId, loginType=$loginType, password_length=" . strlen($password));

            // Determine table based on login type
            $table = $this->getTableName($loginType);
            if (!$table) {
                error_log("DEBUG Auth->login: Invalid loginType=$loginType, no table found");
                return false;
            }

            error_log("DEBUG Auth->login: Using table=$table");

            $query = "SELECT * FROM " . $table . " WHERE id = :id LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $userId);
            $stmt->execute();

            error_log("DEBUG Auth->login: Query executed, rowCount=" . $stmt->rowCount());

            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // DEBUG: Log user data (without password)
                error_log("DEBUG Auth->login: User found - id=" . $user['id'] . ", email=" . $user['email']);

                // Verify password - FIXED: Use password_verify
                $passwordVerified = password_verify($password, $user['password']);
                error_log("DEBUG Auth->login: Password verification result=" . ($passwordVerified ? 'true' : 'false'));

                if ($passwordVerified) {
                    // Return user data without password
                    unset($user['password']);
                    $user['role'] = $loginType;
                    error_log("DEBUG Auth->login: Login successful for user=" . $user['id']);
                    return $user;
                } else {
                    error_log("DEBUG Auth->login: Password verification failed");
                }
            } else {
                error_log("DEBUG Auth->login: No user found with id=$userId in table=$table");
            }

            return false;
        } catch(PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            return false;
        }
    }

    private function getTableName($loginType) {
        switch($loginType) {
            case 'admin': return 'administrators';
            case 'employee': return 'employees';
            case 'student': return 'students';
            default: return null;
        }
    }

    public function getUser($userId, $role) {
        try {
            $table = $this->getTableName($role);
            if (!$table) return null;

            // Different tables have different column names
            if ($role === 'student') {
                $query = "SELECT id, first_name, last_name, email, department, position, phone FROM " . $table . " WHERE id = :id";
            } else {
                $query = "SELECT id, first_name, last_name, email, office, position, phone FROM " . $table . " WHERE id = :id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $userId);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $user['role'] = $role;
                return $user;
            }

            return null;
        } catch(PDOException $e) {
            error_log("Get User Error: " . $e->getMessage());
            return null;
        }
    }
}
?>