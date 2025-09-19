<?php
header('Content-Type: application/json');
// Fix the include paths - use absolute paths from root
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $userId = $data['userId'] ?? '';
    $password = $data['password'] ?? '';
    $loginType = $data['loginType'] ?? '';

    // DEBUG: Log API request
    error_log("DEBUG api/auth.php: API login request - userId=$userId, loginType=$loginType, password_length=" . strlen($password));

    $auth = new Auth();
    $user = $auth->login($userId, $password, $loginType);

    // DEBUG: Log API result
    error_log("DEBUG api/auth.php: Auth->login returned: " . ($user ? 'SUCCESS' : 'FAILED'));

    if ($user) {
        loginUser($user);
        error_log("DEBUG api/auth.php: User logged in via API - id=" . $user['id'] . ", role=" . $user['role']);
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        error_log("DEBUG api/auth.php: API login failed");
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
} else {
    error_log("DEBUG api/auth.php: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>