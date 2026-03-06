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
            $db->prepare("UPDATE notifications SET is_archived = 1 WHERE id = ? AND recipient_id = ? AND recipient_role = ?")->execute([$notificationId, $currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'delete' && $notificationId) {
            $db->prepare("DELETE FROM notifications WHERE id = ? AND recipient_id = ? AND recipient_role = ?")->execute([$notificationId, $currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'clear_all') {
            $db->prepare("DELETE FROM notifications WHERE recipient_id = ? AND recipient_role = ?")->execute([$currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($notificationId > 0) {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ? AND recipient_role = ?")->execute([$notificationId, $currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true]); exit;
        }
        sendJsonResponse(false, 'Invalid action', 400);
    }

    // --- GET NOTIFICATIONS (Lightning Fast) ---
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, intval($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // 1. Fetch Notifications
    $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND (is_archived = 0 OR is_archived IS NULL) ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $currentUser['id']);
    $stmt->bindValue(2, $currentUser['role']);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Auto-clean stale "pending review/signature" notifications that are no longer actionable.
    $pendingRefTypes = ['employee_document_pending', 'document_pending_signature'];
    $docIds = [];
    foreach ($notifications as $n) {
        if (in_array($n['reference_type'] ?? '', $pendingRefTypes, true) && !empty($n['related_document_id'])) {
            $docIds[] = (int) $n['related_document_id'];
        }
    }
    $docIds = array_values(array_unique(array_filter($docIds)));

    $actionableDocSet = [];
    if (!empty($docIds)) {
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        if (($currentUser['role'] ?? '') === 'employee') {
            $sql = "SELECT DISTINCT ds.document_id
                    FROM document_steps ds
                    JOIN documents d ON d.id = ds.document_id
                    WHERE ds.status = 'pending'
                      AND ds.assigned_to_employee_id = ?
                      AND ds.document_id IN ($placeholders)
                      AND d.status NOT IN ('approved', 'rejected', 'cancelled')";
        } else {
            $sql = "SELECT DISTINCT ds.document_id
                    FROM document_steps ds
                    JOIN documents d ON d.id = ds.document_id
                    WHERE ds.status = 'pending'
                      AND ds.assigned_to_student_id = ?
                      AND ds.document_id IN ($placeholders)
                      AND d.status NOT IN ('approved', 'rejected', 'cancelled')";
        }

        $params = array_merge([$currentUser['id']], $docIds);
        $checkStmt = $db->prepare($sql);
        $checkStmt->execute($params);
        foreach ($checkStmt->fetchAll(PDO::FETCH_COLUMN) as $docId) {
            $actionableDocSet[(int) $docId] = true;
        }
    }

    $staleNotificationIds = [];
    foreach ($notifications as &$notif) {
        $refType = $notif['reference_type'] ?? '';
        $docId = (int) ($notif['related_document_id'] ?? 0);
        $isActionable = true;

        if (in_array($refType, $pendingRefTypes, true)) {
            $isActionable = ($docId > 0 && isset($actionableDocSet[$docId]));
            if (!$isActionable) {
                $staleNotificationIds[] = (int) $notif['id'];
            }
        }

        $notif['is_actionable'] = $isActionable;
        $notif['time_ago'] = getTimeAgo($notif['created_at']);
        $notif['is_read'] = (bool) $notif['is_read'];
    }
    unset($notif);

    if (!empty($staleNotificationIds)) {
        $ph = implode(',', array_fill(0, count($staleNotificationIds), '?'));
        $sql = "UPDATE notifications
                SET is_read = 1, is_archived = 1
                WHERE id IN ($ph) AND recipient_id = ? AND recipient_role = ?";
        $params = array_merge($staleNotificationIds, [$currentUser['id'], $currentUser['role']]);
        $db->prepare($sql)->execute($params);

        // Remove archived stale notifications from this response immediately.
        $staleMap = array_fill_keys($staleNotificationIds, true);
        $notifications = array_values(array_filter($notifications, function ($n) use ($staleMap) {
            return !isset($staleMap[(int) $n['id']]);
        }));
    }

    // 3. Unread count after stale cleanup.
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0 AND (is_archived = 0 OR is_archived IS NULL)");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $unreadCount = (int) $stmt->fetchColumn();

    // 4. Strict pending approvals count (for reminders), independent from general unread.
    if (($currentUser['role'] ?? '') === 'employee') {
        $pendingCountSql = "SELECT COUNT(*)
                            FROM document_steps ds
                            JOIN documents d ON d.id = ds.document_id
                            WHERE ds.status = 'pending'
                              AND ds.assigned_to_employee_id = ?
                              AND d.status NOT IN ('approved', 'rejected', 'cancelled')";
    } else {
        $pendingCountSql = "SELECT COUNT(*)
                            FROM document_steps ds
                            JOIN documents d ON d.id = ds.document_id
                            WHERE ds.status = 'pending'
                              AND ds.assigned_to_student_id = ?
                              AND d.status NOT IN ('approved', 'rejected', 'cancelled')";
    }
    $pendingStmt = $db->prepare($pendingCountSql);
    $pendingStmt->execute([$currentUser['id']]);
    $pendingApprovalsCount = (int) $pendingStmt->fetchColumn();

    $workflowNotifications = array_values(array_filter($notifications, function ($n) {
        $type = strtolower((string) ($n['type'] ?? ''));
        $refType = strtolower((string) ($n['reference_type'] ?? ''));
        if ($type === 'system' || $type === 'event') {
            return false;
        }
        if ($refType === 'workflow_escalation' || strpos($refType, 'event_') === 0) {
            return false;
        }
        return true;
    }));

    $globalAnnouncements = array_values(array_filter($notifications, function ($n) {
        $type = strtolower((string) ($n['type'] ?? ''));
        $refType = strtolower((string) ($n['reference_type'] ?? ''));
        return $type === 'system' || $type === 'event' || $refType === 'workflow_escalation' || strpos($refType, 'event_') === 0;
    }));

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'workflow_notifications' => $workflowNotifications,
        'global_announcements' => $globalAnnouncements,
        'unread_count' => $unreadCount,
        'pending_approvals_count' => $pendingApprovalsCount,
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