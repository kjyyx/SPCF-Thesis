<?php
/**
 * System Settings API
 * ====================
 *
 * Handles system-wide settings like 2FA enforcement.
 * Accessible only to administrators.
 */

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

requireAuth(); // Ensure user is logged in

$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get a setting by key
        $key = $_GET['key'] ?? null;
        if (!$key) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Setting key required']);
            exit();
        }

        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'value' => $result ? $result['setting_value'] : null
        ]);
        break;

    case 'POST':
        // Save settings
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid input']);
            exit();
        }

        $conn->beginTransaction();
        try {
            foreach ($input as $key => $value) {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Settings saved']);
        } catch (Exception $e) {
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save settings']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}
?>