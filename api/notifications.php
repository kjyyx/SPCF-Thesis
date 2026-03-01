<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        sendJsonResponse(false, 'No active session', 401);
    }

    $auth = new Auth();
    $currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
    if (!$currentUser) sendJsonResponse(false, 'User not found', 401);

    $db = (new Database())->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $notificationId = (int) ($input['notification_id'] ?? 0);

        if ($action === 'mark_all_read') {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND recipient_role = ?")->execute([$currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'archive' && $notificationId) {
            $db->prepare("UPDATE notifications SET is_archived = 1 WHERE id = ? AND recipient_id = ?")->execute([$notificationId, $currentUser['id']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'delete' && $notificationId) {
            $db->prepare("DELETE FROM notifications WHERE id = ? AND recipient_id = ?")->execute([$notificationId, $currentUser['id']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'clear_all') {
            $db->prepare("DELETE FROM notifications WHERE recipient_id = ? AND recipient_role = ?")->execute([$currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($notificationId > 0) {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ?")->execute([$notificationId, $currentUser['id']]);
            echo json_encode(['success' => true]); exit;
        }
        sendJsonResponse(false, 'Invalid action', 400);
    }

    // --- GET NOTIFICATIONS (Lightning Fast) ---
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, intval($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // 1. Get Unread Count
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0 AND (is_archived = 0 OR is_archived IS NULL)");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $unreadCount = (int) $stmt->fetchColumn();

    // 2. Fetch Notifications
    $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND (is_archived = 0 OR is_archived IS NULL) ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $currentUser['id']);
    $stmt->bindValue(2, $currentUser['role']);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$notif) {
        $notif['time_ago'] = getTimeAgo($notif['created_at']);
        $notif['is_read'] = (bool) $notif['is_read'];
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getTimeAgo($timestamp) {
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y g:i A', strtotime($timestamp));
}
function sendJsonResponse($success, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}