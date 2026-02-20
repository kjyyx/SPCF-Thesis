<?php
// api/notifications.php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

try {
    error_log("=== Notifications API Started ===");
    
    // Check session
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        error_log("Notifications API: No session found");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No active session']);
        exit;
    }
    
    $auth = new Auth();
    $currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

    if (!$currentUser) {
        error_log("Notifications API: User not found");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($input === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
            exit;
        }
        
        // Mark single notification as read
        if (isset($input['notification_id'])) {
            $stmt = $db->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE id = ? AND recipient_id = ? AND recipient_role = ?
            ");
            $stmt->execute([$input['notification_id'], $currentUser['id'], $currentUser['role']]);
            
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            exit;
        }
        
        // Mark all as read
        if (isset($input['action']) && $input['action'] === 'mark_all_read') {
            $stmt = $db->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0
            ");
            $stmt->execute([$currentUser['id'], $currentUser['role']]);
            
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            exit;
        }
        
        // Archive notification
        if (isset($input['action']) && $input['action'] === 'archive' && isset($input['notification_id'])) {
            $stmt = $db->prepare("
                UPDATE notifications 
                SET is_archived = 1 
                WHERE id = ? AND recipient_id = ? AND recipient_role = ?
            ");
            $stmt->execute([$input['notification_id'], $currentUser['id'], $currentUser['role']]);
            
            echo json_encode(['success' => true, 'message' => 'Notification archived']);
            exit;
        }

        // Delete notification
        if (isset($input['action']) && $input['action'] === 'delete' && isset($input['notification_id'])) {
            $stmt = $db->prepare("
                DELETE FROM notifications 
                WHERE id = ? AND recipient_id = ? AND recipient_role = ?
            ");
            $stmt->execute([$input['notification_id'], $currentUser['id'], $currentUser['role']]);
            
            echo json_encode(['success' => true, 'message' => 'Notification deleted']);
            exit;
        }

        // Clear all notifications
        if (isset($input['action']) && $input['action'] === 'clear_all') {
            $stmt = $db->prepare("
                DELETE FROM notifications 
                WHERE recipient_id = ? AND recipient_role = ?
            ");
            $stmt->execute([$currentUser['id'], $currentUser['role']]);
            
            echo json_encode(['success' => true, 'message' => 'All notifications cleared']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    // GET request - fetch notifications
    
    // FIRST: Generate any new notifications based on current state
    // This ensures all notifications are up to date before counting
    try {
        generateNotifications($db, $currentUser);
    } catch (Exception $e) {
        error_log("Error generating notifications: " . $e->getMessage());
        // Continue even if generation fails - we'll still show existing notifications
    }

    // NOW get the counts and notifications (after generation)
    
    // Get total unread count
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0 
        AND (is_archived = 0 OR is_archived IS NULL)
    ");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $unreadCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Get total count for debugging
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE recipient_id = ? AND recipient_role = ?
        AND (is_archived = 0 OR is_archived IS NULL)
    ");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    error_log("User {$currentUser['id']} - Total: {$totalCount}, Unread: {$unreadCount}");

    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    $includeRead = isset($_GET['include_read']) ? filter_var($_GET['include_read'], FILTER_VALIDATE_BOOLEAN) : false;

    // Build query
    $query = "
        SELECT * FROM notifications 
        WHERE recipient_id = ? AND recipient_role = ? 
    ";
    $params = [$currentUser['id'], $currentUser['role']];

    if (!$includeRead) {
        $query .= " AND is_read = 0";
    }

    $query .= " AND (is_archived = 0 OR is_archived IS NULL)";
    $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";

    // Prepare and execute with proper binding
    $stmt = $db->prepare($query);
    $stmt->bindValue(1, $currentUser['id'], PDO::PARAM_STR);
    $stmt->bindValue(2, $currentUser['role'], PDO::PARAM_STR);
    
    $paramIndex = 3;
    if (!$includeRead) {
        // If we added the is_read condition, we need to adjust parameter index
        // But since we're using bindValue with positional parameters, we need to be careful
        // Let's rebuild the query with named parameters to avoid confusion
    }
    
    // Simpler approach: use named parameters
    $query = "
        SELECT * FROM notifications 
        WHERE recipient_id = :user_id 
        AND recipient_role = :user_role 
    ";
    
    if (!$includeRead) {
        $query .= " AND is_read = 0";
    }
    
    $query .= " AND (is_archived = 0 OR is_archived IS NULL)";
    $query .= " ORDER BY created_at DESC LIMIT :limit_val OFFSET :offset_val";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $currentUser['id'], PDO::PARAM_STR);
    $stmt->bindValue(':user_role', $currentUser['role'], PDO::PARAM_STR);
    $stmt->bindValue(':limit_val', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_val', (int)$offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format timestamps
    foreach ($notifications as &$notif) {
        $notif['created_at_formatted'] = date('c', strtotime($notif['created_at']));
        $notif['time_ago'] = getTimeAgo($notif['created_at']);
        $notif['is_read'] = (bool)$notif['is_read'];
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => intval($unreadCount),
        'total_count' => intval($totalCount),
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => count($notifications) === $limit,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function generateNotifications($db, $user) {
    try {
        // Only generate if we have few notifications (to avoid duplicates)
        $checkStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE recipient_id = ? AND recipient_role = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $checkStmt->execute([$user['id'], $user['role']]);
        $recentCount = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // If we have recent notifications, don't generate more to avoid duplicates
        if ($recentCount > 20) {
            return;
        }

        $db->beginTransaction();
        
        $oneDayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));

        // Generate notifications based on user role
        if ($user['role'] === 'student') {
            // Document status updates - only if not already notified
            $stmt = $db->prepare("
                SELECT d.id, d.title, d.status, d.updated_at
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
                LIMIT 10
            ");
            $stmt->execute([$user['id'], $oneDayAgo]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $messages = [
                    'approved' => 'approved and ready for download',
                    'rejected' => 'rejected',
                    'in_review' => 'is now under review'
                ];
                
                $insertStmt = $db->prepare("
                    INSERT INTO notifications (
                        recipient_id, recipient_role, type, title, message, 
                        related_document_id, is_read, created_at
                    ) VALUES (?, 'student', 'document', ?, ?, ?, 0, NOW())
                ");
                $insertStmt->execute([
                    $user['id'],
                    'Document Status Update',
                    "Your document '{$row['title']}' has been {$messages[$row['status']]}",
                    $row['id']
                ]);
                error_log("Generated status notification for document {$row['id']} - status: {$row['status']}");
            }
            
            // Pending documents for students - only if not already notified
            $stmt = $db->prepare("
                SELECT d.id, d.title, ds.id as step_id
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
                LIMIT 10
            ");
            $stmt->execute([$user['id'], $oneDayAgo]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $insertStmt = $db->prepare("
                    INSERT INTO notifications (
                        recipient_id, recipient_role, type, title, message, 
                        related_document_id, is_read, created_at
                    ) VALUES (?, 'student', 'document', ?, ?, ?, 0, NOW())
                ");
                $insertStmt->execute([
                    $user['id'],
                    'Document Pending Signature',
                    "Document '{$row['title']}' requires your signature",
                    $row['id']
                ]);
                error_log("Generated pending notification for document {$row['id']}");
            }
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in generateNotifications: " . $e->getMessage());
    }
}

function getTimeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    
    if ($time_difference < 60) {
        return "Just now";
    } elseif ($time_difference < 3600) {
        $minutes = floor($time_difference / 60);
        return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
    } elseif ($time_difference < 86400) {
        $hours = floor($time_difference / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    } elseif ($time_difference < 604800) {
        $days = floor($time_difference / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    } else {
        return date('M j, Y', $time_ago);
    }
}
?>