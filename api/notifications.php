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
        
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    // GET request - fetch notifications
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    $includeRead = isset($_GET['include_read']) ? filter_var($_GET['include_read'], FILTER_VALIDATE_BOOLEAN) : false;

    // First, try to generate notifications (with error handling)
    try {
        generateNotifications($db, $currentUser);
    } catch (Exception $e) {
        error_log("Error generating notifications: " . $e->getMessage());
        // Continue even if generation fails - we'll still show existing notifications
    }

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

    // FIX: Explicitly cast to integers and use bindParam with PARAM_INT
    $stmt = $db->prepare($query);

    // Bind parameters with explicit types
    $stmt->bindParam(1, $currentUser['id'], PDO::PARAM_STR);
    $stmt->bindParam(2, $currentUser['role'], PDO::PARAM_STR);

    $paramIndex = 3;
    if (!$includeRead) {
        // is_read = 0 is already in query, no extra param needed
    }

    // Bind limit and offset as integers
    $limitInt = (int)$limit;
    $offsetInt = (int)$offset;
    $stmt->bindParam($paramIndex++, $limitInt, PDO::PARAM_INT);
    $stmt->bindParam($paramIndex++, $offsetInt, PDO::PARAM_INT);

    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total unread count
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0 
        AND (is_archived = 0 OR is_archived IS NULL)
    ");
    $stmt->execute([$currentUser['id'], $currentUser['role']]);
    $unreadCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

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
        // Check if we have any notifications first
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND recipient_role = ?");
        $checkStmt->execute([$user['id'], $user['role']]);
        $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Only generate if we have fewer than 50 notifications
        if ($count > 50) {
            return;
        }

        $db->beginTransaction();
        
        $now = date('Y-m-d H:i:s');
        $oneDayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));

        // Generate notifications based on user role
        if ($user['role'] === 'student') {
            // Document status updates
            $stmt = $db->prepare("
                SELECT d.id, d.title, d.status, d.updated_at
                FROM documents d
                WHERE d.student_id = ? 
                AND d.updated_at > ?
                AND d.status IN ('approved', 'rejected', 'in_review')
                ORDER BY d.updated_at DESC
                LIMIT 10
            ");
            $stmt->execute([$user['id'], $oneDayAgo]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Check if notification already exists
                $checkStmt = $db->prepare("
                    SELECT id FROM notifications 
                    WHERE recipient_id = ? AND recipient_role = 'student'
                    AND related_document_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                ");
                $checkStmt->execute([$user['id'], $row['id']]);
                
                if (!$checkStmt->fetch()) {
                    $messages = [
                        'approved' => 'Your document has been approved',
                        'rejected' => 'Your document has been rejected',
                        'in_review' => 'Your document is now under review'
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
                        $messages[$row['status']] . ": {$row['title']}",
                        $row['id']
                    ]);
                }
            }
            
            // Pending documents for students
            $stmt = $db->prepare("
                SELECT d.id, d.title
                FROM documents d
                JOIN document_steps ds ON d.id = ds.document_id
                WHERE ds.assigned_to_student_id = ? 
                AND ds.status = 'pending'
                AND d.status NOT IN ('approved', 'rejected', 'cancelled')
                AND d.updated_at > ?
                LIMIT 10
            ");
            $stmt->execute([$user['id'], $oneDayAgo]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $checkStmt = $db->prepare("
                    SELECT id FROM notifications 
                    WHERE recipient_id = ? AND recipient_role = 'student'
                    AND related_document_id = ? AND message LIKE '%pending%'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                ");
                $checkStmt->execute([$user['id'], $row['id']]);
                
                if (!$checkStmt->fetch()) {
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
                }
            }
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in generateNotifications: " . $e->getMessage());
        // Don't throw - just log the error
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