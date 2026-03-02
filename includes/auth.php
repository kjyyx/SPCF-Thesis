<?php
// includes/auth.php - Authentication functions
require_once 'database.php';
require_once 'session.php';

class Auth {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function login($userId, $password, $loginType) {
        try {
            // Determine table based on login type
            $table = $this->getTableName($loginType);
            if (!$table) {
                return false;
            }

            $query = "SELECT * FROM " . $table . " WHERE id = :id LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $userId);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Check active status
                if (isset($user['status']) && $user['status'] !== 'active') {
                    // User is inactive/suspended
                    return false;
                }

                // Verify password using modern hashing
                $passwordVerified = password_verify($password, $user['password']);

                if ($passwordVerified) {
                    // Return user data without password for safety
                    $mustChange = isset($user['must_change_password']) ? (int)$user['must_change_password'] : 0;
                    unset($user['password']);
                    $user['role'] = $loginType;
                    $user['must_change_password'] = $mustChange;
                    return $user;
                }
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
                $query = "SELECT id, first_name, last_name, email, department, position, phone, must_change_password FROM " . $table . " WHERE id = :id";
            } else {
                $query = "SELECT id, first_name, last_name, email, office as department, position, phone, must_change_password FROM " . $table . " WHERE id = :id";
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

// Global helper function for API files
function getUserHelper($userId, $role) {
    $auth = new Auth();
    return $auth->getUser($userId, $role);
}
?>