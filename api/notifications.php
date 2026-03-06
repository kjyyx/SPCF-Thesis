<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

function normalizeDepartmentValue($value) {
    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $text);
}

function isUniversityWideDepartment($department) {
    $norm = normalizeDepartmentValue($department);
    if ($norm === '') {
        return true;
    }
    return $norm === 'university wide' || strpos($norm, 'university') !== false;
}

function shouldReceiveEventAnnouncement($currentUser, $eventDepartment) {
    $role = strtolower((string) ($currentUser['role'] ?? ''));
    if ($role === 'admin') {
        return true;
    }

    if (isUniversityWideDepartment($eventDepartment)) {
        return true;
    }

    $userDept = normalizeDepartmentValue($currentUser['department'] ?? '');
    $eventDept = normalizeDepartmentValue($eventDepartment);
    return $userDept !== '' && $eventDept !== '' && $userDept === $eventDept;
}

function ensureUpcomingEventNotificationsForCurrentUser($db, $currentUser) {
    $stmt = $db->query("SELECT id, title, department, event_date, event_time FROM events WHERE approved = 1 AND event_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($events)) {
        return;
    }

    $recipientId = $currentUser['id'] ?? null;
    $recipientRole = $currentUser['role'] ?? null;
    if (!$recipientId || !$recipientRole) {
        return;
    }

    $todayTag = date('Ymd');
    foreach ($events as $event) {
        if (!shouldReceiveEventAnnouncement($currentUser, $event['department'] ?? '')) {
            continue;
        }

        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId <= 0) {
            continue;
        }

        $refId = 'event_reminder_' . $eventId . '_' . $todayTag . '_' . $recipientRole . '_' . $recipientId;
        $check = $db->prepare("SELECT id FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND reference_id = ? LIMIT 1");
        $check->execute([$recipientId, $recipientRole, $refId]);
        if ($check->fetchColumn()) {
            continue;
        }

        $title = trim((string) ($event['title'] ?? 'Upcoming Event'));
        $eventDate = (string) ($event['event_date'] ?? '');
        $eventTime = (string) ($event['event_time'] ?? '');
        $department = (string) ($event['department'] ?? 'University Wide');
        $timePart = $eventTime ? (' at ' . substr($eventTime, 0, 5)) : '';
        $message = "Reminder: '{$title}' is scheduled on {$eventDate}{$timePart} ({$department}).";

        $insert = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_event_id, reference_id, reference_type, is_read, created_at) VALUES (?, ?, 'event', ?, ?, ?, ?, 'event_reminder', 0, NOW())");
        $insert->execute([$recipientId, $recipientRole, 'Upcoming Event Reminder', $message, $eventId, $refId]);
    }
}

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        sendJsonResponse(false, 'No active session', 401);
    }

    $auth = new Auth();
    $currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
    if (!$currentUser) sendJsonResponse(false, 'User not found', 401);

    $db = (new Database())->getConnection();

    // Ensure bell polling can surface upcoming event reminders reliably per user.
    ensureUpcomingEventNotificationsForCurrentUser($db, $currentUser);

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