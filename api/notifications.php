<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No active session']);
        exit;
    }

    $auth = new Auth();
    $currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
            exit;
        }

        $action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : null;
        $notificationId = isset($input['notification_id']) ? (int) $input['notification_id'] : 0;

        if ($action === 'mark_all_read') {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0");
            $stmt->execute([$currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            exit;
        }

        if ($action === 'archive') {
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid notification_id']);
                exit;
            }

            $stmt = $db->prepare("UPDATE notifications SET is_archived = 1 WHERE id = ? AND recipient_id = ? AND recipient_role = ?");
            $stmt->execute([$notificationId, $currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true, 'message' => 'Notification archived']);
            exit;
        }

        if ($action === 'delete') {
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid notification_id']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND recipient_id = ? AND recipient_role = ?");
            $stmt->execute([$notificationId, $currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true, 'message' => 'Notification deleted']);
            exit;
        }

        if ($action === 'clear_all') {
            $stmt = $db->prepare("DELETE FROM notifications WHERE recipient_id = ? AND recipient_role = ?");
            $stmt->execute([$currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true, 'message' => 'All notifications cleared']);
            exit;
        }

        if ($notificationId > 0) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ? AND recipient_role = ?");
            $stmt->execute([$notificationId, $currentUser['id'], $currentUser['role']]);
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    try {
        generateNotifications($db, $currentUser);
    } catch (Exception $e) {
        error_log('Error generating notifications: ' . $e->getMessage());
    }

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0 AND (is_archived = 0 OR is_archived IS NULL)");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $unreadCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND (is_archived = 0 OR is_archived IS NULL)");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $totalCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    $includeRead = isset($_GET['include_read']) ? filter_var($_GET['include_read'], FILTER_VALIDATE_BOOLEAN) : false;

    $query = "SELECT * FROM notifications WHERE recipient_id = :user_id AND recipient_role = :user_role";
    if (!$includeRead) {
        $query .= " AND is_read = 0";
    }
    $query .= " AND (is_archived = 0 OR is_archived IS NULL) ORDER BY created_at DESC LIMIT :limit_val OFFSET :offset_val";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $currentUser['id'], PDO::PARAM_STR);
    $stmt->bindValue(':user_role', $currentUser['role'], PDO::PARAM_STR);
    $stmt->bindValue(':limit_val', (int) $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_val', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$notif) {
        $notif['created_at_formatted'] = date('c', strtotime($notif['created_at']));
        $notif['time_ago'] = getTimeAgo($notif['created_at']);
        $notif['is_read'] = (bool) $notif['is_read'];
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'total_count' => $totalCount,
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => count($notifications) === $limit,
        'timestamp' => date('c')
    ]);
} catch (Exception $e) {
    error_log('Notifications API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function generateNotifications($db, $user)
{
    try {
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $checkStmt->execute([$user['id'], $user['role']]);
        $recentCount = (int) $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($recentCount > 20) {
            return;
        }

        $db->beginTransaction();
        $oneDayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));

        if ($user['role'] === 'student') {
            $stmt = $db->prepare("SELECT d.id, d.title, d.status, d.updated_at
                FROM documents d
                LEFT JOIN notifications n ON n.related_document_id = d.id
                    AND n.recipient_id = d.student_id
                    AND n.recipient_role = 'student'
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                WHERE d.student_id = ?
                AND d.updated_at > ?
                AND d.status IN ('approved', 'rejected', 'in_review')
                AND n.id IS NULL
                ORDER BY d.updated_at DESC
                LIMIT 10");
            $stmt->execute([$user['id'], $oneDayAgo]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $messages = [
                    'approved' => 'approved and ready for download',
                    'rejected' => 'rejected',
                    'in_review' => 'is now under review'
                ];

                $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, is_read, created_at)
                    VALUES (?, 'student', 'document', ?, ?, ?, 0, NOW())");
                $insertStmt->execute([
                    $user['id'],
                    'Document Status Update',
                    "Your document '{$row['title']}' has been {$messages[$row['status']]}",
                    $row['id']
                ]);
            }

            $stmt = $db->prepare("SELECT d.id, d.title, ds.id as step_id
                FROM documents d
                JOIN document_steps ds ON d.id = ds.document_id
                LEFT JOIN notifications n ON n.related_document_id = d.id
                    AND n.recipient_id = ds.assigned_to_student_id
                    AND n.recipient_role = 'student'
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                    AND n.message LIKE '%requires your signature%'
                WHERE ds.assigned_to_student_id = ?
                AND ds.status = 'pending'
                AND d.status NOT IN ('approved', 'rejected', 'cancelled')
                AND d.updated_at > ?
                AND n.id IS NULL
                LIMIT 10");
            $stmt->execute([$user['id'], $oneDayAgo]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, is_read, created_at)
                    VALUES (?, 'student', 'document', ?, ?, ?, 0, NOW())");
                $insertStmt->execute([
                    $user['id'],
                    'Document Pending Signature',
                    "Document '{$row['title']}' requires your signature",
                    $row['id']
                ]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Error in generateNotifications: ' . $e->getMessage());
    }
}

function getTimeAgo($timestamp)
{
    $timeAgo = strtotime($timestamp);
    $currentTime = time();
    $timeDifference = $currentTime - $timeAgo;

    if ($timeDifference < 60) {
        return 'Just now';
    }
    if ($timeDifference < 3600) {
        $minutes = floor($timeDifference / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    }
    if ($timeDifference < 86400) {
        $hours = floor($timeDifference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    }
    if ($timeDifference < 604800) {
        $days = floor($timeDifference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }

    return date('M j, Y g:i A', $timeAgo);
}
