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

    $debug = isset($_GET['debug']) ? filter_var($_GET['debug'], FILTER_VALIDATE_BOOLEAN) : false;
    $debugLogs = [];

    try {
        generateNotifications($db, $currentUser, $debug, $debugLogs);
    } catch (Exception $e) {
        $debugLogs[] = 'Error generating notifications: ' . $e->getMessage();
        error_log('Error generating notifications: ' . $e->getMessage());
    }

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0 AND (is_archived = 0 OR is_archived IS NULL)");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $unreadCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND (is_archived = 0 OR is_archived IS NULL)");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $totalCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $limit = ($limit > 0) ? min(100, $limit) : 20;
    $offset = ($page - 1) * $limit;
    $includeRead = isset($_GET['include_read']) ? filter_var($_GET['include_read'], FILTER_VALIDATE_BOOLEAN) : true;
    $typeFilter = isset($_GET['type']) ? trim((string) $_GET['type']) : '';

    $query = "SELECT * FROM notifications WHERE recipient_id = :user_id AND recipient_role = :user_role";
    if (!$includeRead) {
        $query .= " AND is_read = 0";
    }
    if ($typeFilter !== '') {
        $query .= " AND type = :type_filter";
    }
    $query .= " AND (is_archived = 0 OR is_archived IS NULL) ORDER BY created_at DESC LIMIT :limit_val OFFSET :offset_val";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $currentUser['id'], PDO::PARAM_STR);
    $stmt->bindValue(':user_role', $currentUser['role'], PDO::PARAM_STR);
    if ($typeFilter !== '') {
        $stmt->bindValue(':type_filter', $typeFilter, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit_val', (int) $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_val', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countQuery = "SELECT COUNT(*) as count FROM notifications WHERE recipient_id = :user_id AND recipient_role = :user_role";
    if (!$includeRead) {
        $countQuery .= " AND is_read = 0";
    }
    if ($typeFilter !== '') {
        $countQuery .= " AND type = :type_filter";
    }
    $countQuery .= " AND (is_archived = 0 OR is_archived IS NULL)";
    $countStmt = $db->prepare($countQuery);
    $countStmt->bindValue(':user_id', $currentUser['id'], PDO::PARAM_STR);
    $countStmt->bindValue(':user_role', $currentUser['role'], PDO::PARAM_STR);
    if ($typeFilter !== '') {
        $countStmt->bindValue(':type_filter', $typeFilter, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $filteredTotal = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

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
        'filtered_total' => $filteredTotal,
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + count($notifications)) < $filteredTotal,
        'timestamp' => date('c'),
        'debug_logs' => $debug ? $debugLogs : null
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

function generateNotifications($db, $user, $debug = false, &$debugLogs = [])
{
    $debugLogs[] = "=== generateNotifications called for user {$user['id']} role {$user['role']} ===";

    try {
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $checkStmt->execute([$user['id'], $user['role']]);
        $recentCount = (int) $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];

        $debugLogs[] = "User {$user['id']} has $recentCount recent notifications";

        if ($recentCount > 50) {
            $debugLogs[] = "Skipping notification generation due to $recentCount recent notifications (>50 limit)";
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
                    AND n.reference_type = CONCAT('document_status_', d.status)
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
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

                $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_type, is_read, created_at)
                    VALUES (?, 'student', 'document', ?, ?, ?, ?, 0, NOW())");
                $insertStmt->execute([
                    $user['id'],
                    'Document Status Update',
                    "Your document '{$row['title']}' has been {$messages[$row['status']]}",
                    $row['id'],
                    'document_status_' . $row['status']
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
                $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_type, is_read, created_at)
                    VALUES (?, 'student', 'document', ?, ?, ?, ?, 0, NOW())");
                $insertStmt->execute([
                    $user['id'],
                    'Document Pending Signature',
                    "Document '{$row['title']}' requires your signature",
                    $row['id'],
                    'document_pending_signature'
                ]);
            }

            // Document comments and replies on student's documents
            $commentStmt = $db->prepare("SELECT dn.id as note_id, dn.document_id, dn.parent_note_id, d.title
                FROM document_notes dn
                JOIN documents d ON d.id = dn.document_id
                LEFT JOIN notifications n ON n.reference_id = CAST(dn.id AS CHAR)
                    AND n.recipient_id = d.student_id
                    AND n.recipient_role = 'student'
                    AND n.reference_type IN ('document_comment', 'document_reply')
                WHERE d.student_id = ?
                AND dn.created_at > ?
                AND NOT (dn.author_id = ? AND dn.author_role = 'student')
                AND n.id IS NULL
                ORDER BY dn.created_at DESC
                LIMIT 20");
            $commentStmt->execute([$user['id'], $oneDayAgo, $user['id']]);

            while ($comment = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
                $isReply = !empty($comment['parent_note_id']);
                $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_id, reference_type, is_read, created_at)
                    VALUES (?, 'student', 'document', ?, ?, ?, ?, ?, 0, NOW())");
                $insertStmt->execute([
                    $user['id'],
                    $isReply ? 'New Reply' : 'New Comment',
                    $isReply ? "Someone replied on your document '{$comment['title']}'" : "New comment on your document '{$comment['title']}'",
                    $comment['document_id'],
                    (string) $comment['note_id'],
                    $isReply ? 'document_reply' : 'document_comment'
                ]);
            }

            // Material status updates for students
            $stmt = $db->prepare("SELECT m.id, m.title, m.status,
                    COALESCE(m.approved_at, m.rejected_at) as action_time
                FROM materials m
                LEFT JOIN notifications n ON n.reference_id = m.id
                    AND n.reference_type = CONCAT('material_status_', m.status)
                    AND n.recipient_id = m.student_id
                    AND n.recipient_role = 'student'
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                WHERE m.student_id = ?
                AND m.status IN ('approved', 'rejected')
                AND COALESCE(m.approved_at, m.rejected_at) IS NOT NULL
                AND COALESCE(m.approved_at, m.rejected_at) > ?
                AND n.id IS NULL
                ORDER BY action_time DESC
                LIMIT 10");
            $stmt->execute([$user['id'], $oneDayAgo]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, reference_id, reference_type, is_read, created_at)
                    VALUES (?, 'student', 'document', ?, ?, ?, ?, 0, NOW())");
                $insertStmt->execute([
                    $user['id'],
                    'Pubmat Status Update',
                    "Your pubmat '{$row['title']}' was {$row['status']}",
                    $row['id'],
                    'material_status_' . $row['status']
                ]);
            }
        }

        if ($user['role'] === 'employee') {
            $debugLogs[] = "=== Generating notifications for employee {$user['id']} ===";

            // Check if employee has any assigned pending steps
            $checkAssigned = $db->prepare("SELECT COUNT(*) as count FROM document_steps WHERE assigned_to_employee_id = ? AND status = 'pending'");
            $checkAssigned->execute([$user['id']]);
            $assignedCount = (int) $checkAssigned->fetch(PDO::FETCH_ASSOC)['count'];
            $debugLogs[] = "Employee {$user['id']} has $assignedCount assigned pending document steps";

            // Check if employee has any assigned pending pubmat steps
            $checkPubmatAssigned = $db->prepare("SELECT COUNT(*) as count FROM materials_steps WHERE assigned_to_employee_id = ? AND status = 'pending'");
            $checkPubmatAssigned->execute([$user['id']]);
            $pubmatAssignedCount = (int) $checkPubmatAssigned->fetch(PDO::FETCH_ASSOC)['count'];
            $debugLogs[] = "Employee {$user['id']} has $pubmatAssignedCount assigned pending pubmat steps";

            // Pending documents requiring employee action
            try {
                $stmt = $db->prepare("SELECT d.id, d.title
                    FROM documents d
                    JOIN document_steps ds ON d.id = ds.document_id
                    LEFT JOIN notifications n ON n.related_document_id = d.id
                        AND n.recipient_id = ds.assigned_to_employee_id
                        AND n.recipient_role = 'employee'
                        AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                        AND n.reference_type = 'employee_document_pending'
                    WHERE ds.assigned_to_employee_id = ?
                    AND ds.status = 'pending'
                    AND d.status NOT IN ('approved', 'rejected', 'cancelled')
                    AND d.updated_at > ?
                    AND n.id IS NULL
                    ORDER BY d.updated_at DESC
                    LIMIT 10");
                $stmt->execute([$user['id'], $oneDayAgo]);
                $pendingDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $debugLogs[] = "Employee pending documents query returned " . count($pendingDocs) . " results";

                $createdCount = 0;
                while ($row = array_shift($pendingDocs)) {
                    $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_type, is_read, created_at)
                        VALUES (?, 'employee', 'document', ?, ?, ?, ?, 0, NOW())");
                    $insertStmt->execute([
                        $user['id'],
                        'Document Pending Review',
                        "Document '{$row['title']}' requires your review",
                        $row['id'],
                        'employee_document_pending'
                    ]);
                    $createdCount++;
                    $debugLogs[] = "Created notification for employee document: {$row['title']}";
                }
                $debugLogs[] = "Created $createdCount document notifications for employee {$user['id']}";

            } catch (Exception $e) {
                $debugLogs[] = "Error generating employee document notifications: " . $e->getMessage();
            }

            // Pending pubmat approvals for employee
            try {
                $stmt = $db->prepare("SELECT m.id, m.title
                    FROM materials m
                    JOIN materials_steps ms ON m.id = ms.material_id
                    LEFT JOIN notifications n ON n.reference_id = m.id
                        AND n.recipient_id = ms.assigned_to_employee_id
                        AND n.recipient_role = 'employee'
                        AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                        AND n.reference_type = 'employee_material_pending'
                    WHERE ms.assigned_to_employee_id = ?
                    AND ms.status = 'pending'
                    AND m.status = 'pending'
                    AND m.uploaded_at > ?
                    AND n.id IS NULL
                    ORDER BY m.uploaded_at DESC
                    LIMIT 10");
                $stmt->execute([$user['id'], $oneDayAgo]);
                $pendingMats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $debugLogs[] = "Employee pending pubmats query returned " . count($pendingMats) . " results";

                $createdCount = 0;
                while ($row = array_shift($pendingMats)) {
                    $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, reference_id, reference_type, is_read, created_at)
                        VALUES (?, 'employee', 'document', ?, ?, ?, ?, 0, NOW())");
                    $insertStmt->execute([
                        $user['id'],
                        'Pubmat Pending Review',
                        "Pubmat '{$row['title']}' requires your review",
                        $row['id'],
                        'employee_material_pending'
                    ]);
                    $createdCount++;
                    $debugLogs[] = "Created notification for employee pubmat: {$row['title']}";
                }
                $debugLogs[] = "Created $createdCount pubmat notifications for employee {$user['id']}";

            } catch (Exception $e) {
                $debugLogs[] = "Error generating employee pubmat notifications: " . $e->getMessage();
            }

            // Comments/replies on documents assigned to employee
            try {
                $commentStmt = $db->prepare("SELECT DISTINCT dn.id as note_id, dn.document_id, dn.parent_note_id, d.title
                    FROM document_notes dn
                    JOIN documents d ON d.id = dn.document_id
                    JOIN document_steps ds ON ds.document_id = d.id
                    LEFT JOIN notifications n ON n.reference_id = CAST(dn.id AS CHAR)
                        AND n.recipient_id = ?
                        AND n.recipient_role = 'employee'
                        AND n.reference_type IN ('document_comment', 'document_reply')
                    WHERE ds.assigned_to_employee_id = ?
                    AND dn.created_at > ?
                    AND NOT (dn.author_id = ? AND dn.author_role = 'employee')
                    AND n.id IS NULL
                    ORDER BY dn.created_at DESC
                    LIMIT 20");
                $commentStmt->execute([$user['id'], $user['id'], $oneDayAgo, $user['id']]);
                $comments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);
                $debugLogs[] = "Employee comments query returned " . count($comments) . " results";

                $createdCount = 0;
                while ($comment = array_shift($comments)) {
                    $isReply = !empty($comment['parent_note_id']);
                    $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_id, reference_type, is_read, created_at)
                        VALUES (?, 'employee', 'document', ?, ?, ?, ?, ?, 0, NOW())");
                    $insertStmt->execute([
                        $user['id'],
                        $isReply ? 'New Reply' : 'New Comment',
                        $isReply ? "New reply on assigned document '{$comment['title']}'" : "New comment on assigned document '{$comment['title']}'",
                        $comment['document_id'],
                        (string) $comment['note_id'],
                        $isReply ? 'document_reply' : 'document_comment'
                    ]);
                    $createdCount++;
                    $debugLogs[] = "Created notification for employee comment on: {$comment['title']}";
                }
                $debugLogs[] = "Created $createdCount comment notifications for employee {$user['id']}";

            } catch (Exception $e) {
                $debugLogs[] = "Error generating employee comment notifications: " . $e->getMessage();
            }

            $debugLogs[] = "=== Finished generating notifications for employee {$user['id']} ===";
        }

        if ($user['role'] === 'admin') {
            // Daily summary for pending documents
            $summaryKey = date('Y-m-d');
            $existsStmt = $db->prepare("SELECT id FROM notifications WHERE recipient_id = ? AND recipient_role = 'admin' AND reference_type = 'admin_pending_summary' AND reference_id = ? LIMIT 1");
            $existsStmt->execute([$user['id'], $summaryKey]);
            $exists = $existsStmt->fetch(PDO::FETCH_ASSOC);

            if (!$exists) {
                $countStmt = $db->prepare("SELECT COUNT(*) as cnt FROM documents WHERE status IN ('submitted', 'in_review')");
                $countStmt->execute();
                $pendingDocuments = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

                if ($pendingDocuments > 0) {
                    $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, reference_id, reference_type, is_read, created_at)
                        VALUES (?, 'admin', 'system', ?, ?, ?, ?, 0, NOW())");
                    $insertStmt->execute([
                        $user['id'],
                        'Pending Documents Summary',
                        "There are {$pendingDocuments} document(s) currently pending review.",
                        $summaryKey,
                        'admin_pending_summary'
                    ]);
                }
            }
        }

        // Upcoming approved events (next 24 hours) for all users (deduped)
        $eventStmt = $db->prepare("SELECT e.id, e.title, e.event_date, e.event_time
            FROM events e
            LEFT JOIN notifications n ON n.related_event_id = e.id
                AND n.recipient_id = ?
                AND n.recipient_role = ?
                AND n.reference_type = 'event_reminder'
                AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            WHERE e.approved = 1
            AND CONCAT(e.event_date, ' ', COALESCE(e.event_time, '00:00:00')) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)
            AND n.id IS NULL
            ORDER BY e.event_date ASC, e.event_time ASC
            LIMIT 5");
        $eventStmt->execute([$user['id'], $user['role']]);

        while ($event = $eventStmt->fetch(PDO::FETCH_ASSOC)) {
            $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_event_id, reference_type, is_read, created_at)
                VALUES (?, ?, 'event', ?, ?, ?, ?, 0, NOW())");
            $insertStmt->execute([
                $user['id'],
                $user['role'],
                'Upcoming Event Reminder',
                "Event '{$event['title']}' is scheduled within 24 hours.",
                $event['id'],
                'event_reminder'
            ]);
        }

        // Event approval/disapproval update for event creators
        $eventStatusStmt = $db->prepare("SELECT e.id, e.title, e.approved
            FROM events e
            LEFT JOIN notifications n ON n.related_event_id = e.id
                AND n.recipient_id = e.created_by
                AND n.recipient_role = e.created_by_role
                AND n.reference_type IN ('event_status_approved', 'event_status_disapproved')
                AND n.created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
            WHERE e.created_by = ?
            AND e.created_by_role = ?
            AND e.approved_at IS NOT NULL
            AND e.approved_at > ?
            AND n.id IS NULL
            ORDER BY e.approved_at DESC
            LIMIT 10");
        $eventStatusStmt->execute([$user['id'], $user['role'], $oneDayAgo]);

        while ($event = $eventStatusStmt->fetch(PDO::FETCH_ASSOC)) {
            $approved = ((int) $event['approved'] === 1);
            $insertStmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_event_id, reference_type, is_read, created_at)
                VALUES (?, ?, 'event', ?, ?, ?, ?, 0, NOW())");
            $insertStmt->execute([
                $user['id'],
                $user['role'],
                'Event Status Update',
                "Your event '{$event['title']}' was " . ($approved ? 'approved' : 'disapproved'),
                $event['id'],
                $approved ? 'event_status_approved' : 'event_status_disapproved'
            ]);
        }

        $db->commit();
        $debugLogs[] = "Successfully committed notification generation for user {$user['id']}";
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $debugLogs[] = 'Error in generateNotifications: ' . $e->getMessage();
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
