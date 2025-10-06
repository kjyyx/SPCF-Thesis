<?php
// api/notifications.php - Notifications API endpoint
require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$notifications = [];
$unreadCount = 0;

if ($currentUser['role'] === 'employee') {
    // Pending documents to sign
    $stmt = $db->prepare("
        SELECT d.id, d.title, 'pending_document' as type, d.created_at as timestamp
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
            'timestamp' => $row['timestamp'],
            'read' => false
        ];
        $unreadCount++;
    }

    // New documents submitted for approval (assigned to this employee)
    $stmt = $db->prepare("
        SELECT d.id, d.title, 'new_document' as type, d.created_at as timestamp
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
            'timestamp' => $row['timestamp'],
            'read' => false
        ];
        $unreadCount++;
    }

    // Document status changes (approved/rejected)
    $stmt = $db->prepare("
        SELECT d.id, d.title, 'document_status' as type, al.created_at as timestamp, al.details
        FROM documents d
        JOIN audit_logs al ON al.target_id = d.id AND al.target_type = 'Document'
        WHERE al.action IN ('DOCUMENT_APPROVED', 'DOCUMENT_REJECTED') AND al.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY al.created_at DESC
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'Document Status Update: ' . $row['title'],
            'message' => $row['details'],
            'timestamp' => $row['timestamp'],
            'read' => false
        ];
        $unreadCount++;
    }

    // Pending materials for approval
    $stmt = $db->prepare("
        SELECT id, title, 'pending_material' as type, uploaded_at as timestamp
        FROM materials
        WHERE status = 'pending'
        ORDER BY uploaded_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'Pending Material: ' . $row['title'],
            'message' => 'A new material has been uploaded and awaits approval.',
            'timestamp' => $row['timestamp'],
            'read' => false
        ];
        $unreadCount++;
    }
} elseif ($currentUser['role'] === 'student') {
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
            'timestamp' => $row['event_date'],
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
            'timestamp' => $row['event_date'],
            'read' => false
        ];
        $unreadCount++;
    }

    // Document status updates for owned documents
    $stmt = $db->prepare("
        SELECT d.id, d.title, 'document_status' as type, al.created_at as timestamp, al.details
        FROM documents d
        JOIN audit_logs al ON al.target_id = d.id AND al.target_type = 'Document'
        WHERE d.student_id = ? AND al.action IN ('DOCUMENT_APPROVED', 'DOCUMENT_REJECTED', 'DOCUMENT_SIGNED') AND al.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY al.created_at DESC
    ");
    $stmt->execute([$currentUser['id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'Your Document Update: ' . $row['title'],
            'message' => $row['details'],
            'timestamp' => $row['timestamp'],
            'read' => false
        ];
        $unreadCount++;
    }

    // Material approval updates
    $stmt = $db->prepare("
        SELECT id, title, 'material_status' as type, updated_at as timestamp, status
        FROM materials
        WHERE uploaded_by = ? AND status IN ('approved', 'rejected') AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$currentUser['id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statusMsg = $row['status'] === 'approved' ? 'has been approved.' : 'has been rejected.';
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => 'Material Update: ' . $row['title'],
            'message' => 'Your uploaded material ' . $statusMsg,
            'timestamp' => $row['timestamp'],
            'read' => false
        ];
        $unreadCount++;
    }
} elseif ($currentUser['role'] === 'admin') {
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

    // Audit log alerts (high failed logins)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE action = 'LOGIN_FAILED' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $failedLogins = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    if ($failedLogins > 5) {
        $notifications[] = [
            'id' => 'alert_' . time(),
            'type' => 'security_alert',
            'title' => 'Security Alert',
            'message' => $failedLogins . ' failed login attempts in the last hour.',
            'timestamp' => date('Y-m-d H:i:s'),
            'read' => false
        ];
        $unreadCount++;
    }
}

// General notifications from recent audit logs (last 24 hours)
$stmt = $db->prepare("
    SELECT id, action, details, created_at
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $notifications[] = [
        'id' => $row['id'],
        'type' => 'system',
        'title' => $row['action'],
        'message' => $row['details'],
        'timestamp' => $row['created_at'],
        'read' => false
    ];
    $unreadCount++;
}

// Password change confirmations (recent)
if (isset($_SESSION['password_changed']) && $_SESSION['password_changed'] > time() - 3600) {
    $notifications[] = [
        'id' => 'pwd_change_' . time(),
        'type' => 'account',
        'title' => 'Password Changed',
        'message' => 'Your password has been changed successfully.',
        'timestamp' => date('Y-m-d H:i:s'),
        'read' => false
    ];
    $unreadCount++;
    unset($_SESSION['password_changed']);
}

echo json_encode([
    'success' => true,
    'notifications' => array_slice($notifications, 0, 20), // Limit to 20
    'unread_count' => $unreadCount
]);
?>