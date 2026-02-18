<?php
// api/notifications.php - Notifications API endpoint
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Handle POST requests for marking notifications as read
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        error_log("Notifications API POST: Raw input: " . $rawInput);
        $input = json_decode($rawInput, true);
        error_log("Notifications API POST: Decoded input: " . json_encode($input));
        
        if ($input === null) {
            error_log("Notifications API POST: JSON decode failed");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
            exit;
        }
        
        if (isset($input['action']) && $input['action'] === 'mark_all_read') {
            // Mark all notifications as read (client-side only, just return success)
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            exit;
        }
        
        // Handle individual mark as read if needed
        if (isset($input['notification_id'])) {
            // For individual marking, could implement server-side tracking
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            exit;
        }
        
        error_log("Notifications API POST: Invalid action");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    $notifications = [];
    $unreadCount = 0;

    error_log("Notifications API: Starting for user " . $currentUser['id'] . " role " . $currentUser['role']);

    if ($currentUser['role'] === 'employee' || ($currentUser['role'] === 'student' && ($currentUser['position'] === 'Supreme Student Council President' || $currentUser['position'] === 'College Student Council President'))) {
        // Pending documents to sign
        $stmt = $db->prepare("
            SELECT d.id, d.title, 'pending_document' as type, d.uploaded_at as timestamp
            FROM documents d
            JOIN document_steps ds ON d.id = ds.document_id
            WHERE ds.assigned_to_employee_id = ? AND ds.status = 'pending'
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => 'Pending Document: ' . $row['title'],
                'message' => 'You have a document awaiting your signature.',
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => false
            ];
            $unreadCount++;
        }

        // New documents submitted for approval (assigned to this employee)
        $stmt = $db->prepare("
            SELECT d.id, d.title, 'new_document' as type, d.uploaded_at as timestamp
            FROM documents d
            JOIN document_steps ds ON d.id = ds.document_id
            WHERE ds.assigned_to_employee_id = ? AND ds.step_order = 1 AND d.status = 'submitted'
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => 'New Document Submitted: ' . $row['title'],
                'message' => 'A new document has been submitted and requires your approval.',
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => false
            ];
            $unreadCount++;
        }
        // NEW: SAF Funds Ready (from recent audit logs)
        $stmt = $db->prepare("
            SELECT al.target_id as document_id, d.title, 'saf_funds_ready' as type, al.timestamp
            FROM audit_logs al
            JOIN documents d ON al.target_id = d.id
            WHERE al.action = 'SAF_FUNDS_READY' 
              AND d.student_id = ? 
              AND al.timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => 'saf_' . $row['document_id'] . '_' . $currentUser['id'],
                'type' => $row['type'],
                'title' => 'SAF Funds Available',
                'message' => 'Your SAF funds for \'' . $row['title'] . '\' are now available for claiming.',
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => false
            ];
            $unreadCount++;
        }

        // NEW: New Note Added to Your Document
        $stmt = $db->prepare("
            SELECT dn.id, d.title, 'new_note' as type, dn.created_at,
                   CASE 
                       WHEN dn.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                       WHEN dn.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                       ELSE 'Unknown'
                   END as author_name
            FROM document_notes dn
            JOIN documents d ON dn.document_id = d.id
            LEFT JOIN employees e ON dn.author_id = e.id AND dn.author_role = 'employee'
            LEFT JOIN students s ON dn.author_id = s.id AND dn.author_role = 'student'
            WHERE d.student_id = ? AND dn.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => 'note_' . $row['id'] . '_' . $currentUser['id'],
                'type' => $row['type'],
                'title' => 'New Note on Your Document',
                'message' => 'A new note was added to your document \'' . $row['title'] . '\' by ' . $row['author_name'] . '.',
                'timestamp' => date('c', strtotime($row['created_at'])),
                'read' => false
            ];
            $unreadCount++;
        }

        // NEW: Document Fully Approved and Ready
        $stmt = $db->prepare("
            SELECT d.id, d.title, 'approved_ready' as type, ds.acted_at as timestamp
            FROM documents d
            JOIN document_steps ds ON d.id = ds.document_id
            WHERE d.student_id = ? AND d.status = 'approved' 
              AND ds.status = 'completed' AND ds.acted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY d.id HAVING COUNT(CASE WHEN ds.status != 'completed' THEN 1 END) = 0
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => 'approved_' . $row['id'] . '_' . $currentUser['id'],
                'type' => $row['type'],
                'title' => 'Document Approved and Ready',
                'message' => 'Your document \'' . $row['title'] . '\' has been fully approved and is ready for download.',
                'timestamp' => $row['timestamp'],
                'read' => false
            ];
            $unreadCount++;
        }
    }
    elseif ($currentUser['role'] === 'student') {
        // Pending documents to sign (assigned to student)
        $stmt = $db->prepare("
            SELECT d.id, d.title, 'pending_document' as type, d.uploaded_at as timestamp
            FROM documents d
            JOIN document_steps ds ON d.id = ds.document_id
            WHERE ds.assigned_to_student_id = ? AND ds.status = 'pending'
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => 'Pending Document: ' . $row['title'],
                'message' => 'You have a document awaiting your signature.',
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => false
            ];
            $unreadCount++;
        }

        // New documents submitted for approval (assigned to this student)
        $stmt = $db->prepare("
            SELECT d.id, d.title, 'new_document' as type, d.uploaded_at as timestamp
            FROM documents d
            JOIN document_steps ds ON d.id = ds.document_id
            WHERE ds.assigned_to_student_id = ? AND ds.step_order = 1 AND d.status = 'submitted'
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => 'New Document Submitted: ' . $row['title'],
                'message' => 'A new document has been submitted and requires your approval.',
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => false
            ];
            $unreadCount++;
        }

        // Document status updates for owned documents (based on document status changes)
        $stmt = $db->prepare("
            SELECT DISTINCT d.id, d.title, d.status as doc_status, d.updated_at as timestamp,
                   CASE
                       WHEN d.status = 'approved' THEN 'Your document has been approved and is ready for download.'
                       WHEN d.status = 'rejected' THEN 'Your document has been rejected. Please check the details.'
                       WHEN d.status = 'in_review' THEN 'Your document is now under review.'
                       ELSE 'Your document status has been updated.'
                   END as message,
                   CONCAT('doc_status_', d.id) as notification_id
            FROM documents d
            WHERE d.student_id = ? AND d.status IN ('approved', 'rejected', 'in_review')
                  AND d.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY d.updated_at DESC
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Check if already read
            $isRead = false;
            // Note: read_notifications table doesn't exist, so all notifications are treated as unread
            // $isRead = false; // Simplified - all notifications are unread for now

            $notifications[] = [
                'id' => $row['notification_id'],
                'type' => $row['type'],
                'title' => 'Document Update: ' . $row['title'],
                'message' => $row['message'],
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => $isRead
            ];
            if (!$isRead) $unreadCount++;
        }

        // Step completion notifications for student's documents
        $stmt = $db->prepare("
            SELECT ds.id, d.title, 'step_completed' as type, ds.acted_at as timestamp, ds.name as step_name,
                   CASE 
                       WHEN ds.assigned_to_employee_id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                       WHEN ds.assigned_to_student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
                       ELSE 'Unknown'
                   END as assignee_name
            FROM documents d
            JOIN document_steps ds ON d.id = ds.document_id
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            LEFT JOIN students s ON ds.assigned_to_student_id = s.id
            WHERE d.student_id = ? AND ds.status = 'completed' AND ds.acted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY ds.acted_at DESC
        ");
        $stmt->execute([$currentUser['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => 'step_' . $row['id'] . '_' . $currentUser['id'],
                'type' => $row['type'],
                'title' => 'Document Step Completed: ' . $row['title'],
                'message' => 'Step "' . $row['step_name'] . '" completed by ' . $row['assignee_name'] . '.',
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'read' => false
            ];
            $unreadCount++;
        }
    }
    elseif ($currentUser['role'] === 'admin') {
        // New user registrations
        $stmt = $db->prepare("
            SELECT id, CONCAT(first_name, ' ', last_name) as name, 'new_user' as type, created_at as timestamp
            FROM students
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            UNION
            SELECT id, CONCAT(first_name, ' ', last_name) as name, 'new_user' as type, created_at as timestamp
            FROM employees
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY timestamp DESC
            LIMIT 5
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => 'New User Registered',
                'message' => $row['name'] . ' has registered.',
                'timestamp' => $row['timestamp'],
                'read' => false
            ];
            $unreadCount++;
        }
    }

    // NEW: Document Approaching Deadline (within 2 days) - for all users
    $stmt = $db->prepare("
        SELECT d.id, d.title, 'deadline_approaching' as type, DATE_ADD(d.uploaded_at, INTERVAL 4 DAY) as deadline
        FROM documents d
        JOIN document_steps ds ON d.id = ds.document_id
        WHERE ds.status = 'pending' 
          AND ((ds.assigned_to_employee_id = ? AND ? = 'employee') OR (ds.assigned_to_student_id = ? AND ? = 'student'))
          AND DATE_ADD(d.uploaded_at, INTERVAL 2 DAY) <= NOW() 
          AND DATE_ADD(d.uploaded_at, INTERVAL 4 DAY) > NOW()
    ");
    $params = [$currentUser['id'], $currentUser['role'], $currentUser['id'], $currentUser['role']];
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => 'deadline_' . $row['id'] . '_' . $currentUser['id'],
            'type' => $row['type'],
            'title' => 'Document Deadline Approaching',
            'message' => 'Document \'' . $row['title'] . '\' is due for action in 2 days. Please review it soon.',
            'timestamp' => date('c'),
            'read' => false
        ];
        $unreadCount++;
    }

    // NEW: Document Overdue - for all users
    $stmt = $db->prepare("
        SELECT d.id, d.title, 'overdue' as type
        FROM documents d
        JOIN document_steps ds ON d.id = ds.document_id
        WHERE ds.status = 'pending' 
          AND ((ds.assigned_to_employee_id = ? AND ? = 'employee') OR (ds.assigned_to_student_id = ? AND ? = 'student'))
          AND DATE_ADD(d.uploaded_at, INTERVAL 4 DAY) <= NOW()
    ");
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => 'overdue_' . $row['id'] . '_' . $currentUser['id'],
            'type' => $row['type'],
            'title' => 'Document Overdue',
            'message' => 'Document \'' . $row['id'] . '\' is overdue. Immediate action required.',
            'timestamp' => date('c'),
            'read' => false
        ];
        $unreadCount++;
    }

    // NEW: Profile Update Reminder (for all users)
    $stmt = $db->prepare("
        SELECT id, 'profile_reminder' as type
        FROM " . ($currentUser['role'] === 'student' ? 'students' : 'employees') . "
        WHERE id = ? AND DATEDIFF(NOW(), created_at) >= 30
    ");
    $stmt->execute([$currentUser['id']]);
    if ($stmt->fetch()) {
        $notifications[] = [
            'id' => 'profile_' . $currentUser['id'],
            'type' => 'profile_reminder',
            'title' => 'Profile Update Reminder',
            'message' => 'It\'s been 30 days since your account was created. Please update your profile information.',
            'timestamp' => date('c'),
            'read' => false
        ];
        $unreadCount++;
    }

    // Upcoming events (next 7 days)
    $stmt = $db->prepare("
        SELECT id, title, event_date, 'upcoming_event' as type
        FROM events
        WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY event_date
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'Upcoming Event: ' . $row['title'],
            'message' => 'Event on ' . date('M j, Y', strtotime($row['event_date'])),
            'timestamp' => date('c', strtotime($row['event_date'])),
            'read' => false
        ];
        $unreadCount++;
    }

    // New events (created in last 7 days)
    $stmt = $db->prepare("
        SELECT id, title, event_date, created_at, 'new_event' as type
        FROM events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'New Event: ' . $row['title'],
            'message' => 'New event scheduled for ' . date('M j, Y', strtotime($row['event_date'])),
            'timestamp' => date('c', strtotime($row['created_at'])),
            'read' => false
        ];
        $unreadCount++;
    }

    // Event reminders (day before)
    $stmt = $db->prepare("
        SELECT id, title, event_date, 'event_reminder' as type
        FROM events
        WHERE event_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ORDER BY event_date
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'Event Reminder: ' . $row['title'],
            'message' => 'Event tomorrow on ' . date('M j, Y', strtotime($row['event_date'])),
            'timestamp' => date('c', strtotime($row['event_date'])),
            'read' => false
        ];
        $unreadCount++;
    }

    // Audit log alerts (high failed logins)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE action = 'LOGIN_FAILED' AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $failedLogins = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    if ($failedLogins > 5) {
        $notifications[] = [
            'id' => 'alert_' . time(),
            'type' => 'security_alert',
            'title' => 'Security Alert',
            'message' => $failedLogins . ' failed login attempts in the last hour.',
            'timestamp' => date('c'),
            'read' => false
        ];
        $unreadCount++;
    }

    // General notifications from recent audit logs (last 24 hours)
    // Removed to prevent internal audit logs from appearing in user notifications

    // Password change confirmations (recent)
    if (isset($_SESSION['password_changed']) && $_SESSION['password_changed'] > time() - 3600) {
        $notifications[] = [
            'id' => 'pwd_change_' . time(),
            'type' => 'account',
            'title' => 'Password Changed',
            'message' => 'Your password has been changed successfully.',
            'timestamp' => date('c'),
            'read' => false
        ];
        $unreadCount++;
        unset($_SESSION['password_changed']);
    }

    // Handle mark as read request (simplified - client-side only)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
        // Client-side only marking, just return success
        echo json_encode(['success' => true]);
        exit;
    }

    // Deduplicate notifications
    $uniqueNotifications = [];
    $seenIds = [];
    foreach ($notifications as $notif) {
        if (!in_array($notif['id'], $seenIds)) {
            $uniqueNotifications[] = $notif;
            $seenIds[] = $notif['id'];
        }
    }
    $notifications = $uniqueNotifications;

    // Recalculate unreadCount after deduplication
    $unreadCount = count(array_filter($notifications, fn($n) => !$n['read']));

    echo json_encode([
        'success' => true,
        'notifications' => array_slice($notifications, 0, 20), // Limit to 20
        'unread_count' => $unreadCount
    ]);
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>