<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

// Get current user info
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Fetch all employees with non-null required fields
    $stmt = $db->prepare("SELECT id, first_name, last_name, position, department FROM employees WHERE first_name IS NOT NULL AND last_name IS NOT NULL AND position IS NOT NULL ORDER BY first_name, last_name");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'employees' => $employees]);
} catch (Exception $e) {
    error_log("Error fetching employees: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch employees']);
}
?>