<?php
/**
 * Events API - Event Management (Optimized for Dynamic String Departments)
 * =============================
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';

function sendJsonResponse($success, $payload = '', $statusCode = 200)
{
    http_response_code($statusCode);
    $response = is_string($payload) ? ['success' => $success, 'message' => $payload] : array_merge(['success' => $success], $payload);
    echo json_encode($response);
    exit;
}

function hasHighEventPrivileges($role, $currentUser)
{
    if ($role === 'admin')
        return true;
    if ($role === 'employee') {
        $position = strtolower((string) ($currentUser['position'] ?? ''));
        return (
            strpos($position, 'executive vice-president') !== false ||
            strpos($position, 'evp') !== false ||
            strpos($position, 'physical plant and facilities office') !== false ||
            strpos($position, 'ppfo') !== false
        );
    }
    return false;
}

function canModifyEvent($db, $role, $currentUser, $eventId, $userId)
{
    if (hasHighEventPrivileges($role, $currentUser))
        return true;
    if ($role !== 'employee')
        return false;

    $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE id = ? AND created_by = ?");
    $stmt->execute([$eventId, $userId]);
    return $stmt->fetchColumn() > 0;
}

function normalizeDepartmentValue($value)
{
    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $text);
}

function isUniversityWideDepartment($department)
{
    $norm = normalizeDepartmentValue($department);
    if ($norm === '') {
        return true;
    }

    return $norm === 'university wide' || strpos($norm, 'university') !== false;
}

function insertEventNotification($db, $recipientId, $recipientRole, $title, $message, $eventId, $referenceType, $referenceId)
{
    if (!$recipientId || !$recipientRole || !$referenceId) {
        return;
    }

    // Dedupe aggressively so repeated API calls do not spam recipients.
    $check = $db->prepare("SELECT id FROM notifications WHERE recipient_id = ? AND recipient_role = ? AND reference_id = ? LIMIT 1");
    $check->execute([$recipientId, $recipientRole, $referenceId]);
    if ($check->fetchColumn()) {
        return;
    }

    $stmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_event_id, reference_id, reference_type, is_read, created_at) VALUES (?, ?, 'event', ?, ?, ?, ?, ?, 0, NOW())");
    $stmt->execute([$recipientId, $recipientRole, $title, $message, $eventId, $referenceId, $referenceType]);
}

function fetchEventRecipients($db, $department)
{
    $recipients = [];

    // Admins always receive event announcements.
    try {
        $adminStmt = $db->query("SELECT id FROM administrators WHERE status = 'active'");
        foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $recipients['admin:' . $id] = ['id' => $id, 'role' => 'admin'];
        }
    } catch (Exception $e) {
        error_log('Event notifications admin lookup failed: ' . $e->getMessage());
    }

    if (isUniversityWideDepartment($department)) {
        try {
            $employeeStmt = $db->query("SELECT id FROM employees WHERE status = 'active'");
            foreach ($employeeStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $recipients['employee:' . $id] = ['id' => $id, 'role' => 'employee'];
            }
        } catch (Exception $e) {
            error_log('Event notifications employee lookup failed: ' . $e->getMessage());
        }

        try {
            $studentStmt = $db->query("SELECT id FROM students WHERE status = 'active'");
            foreach ($studentStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $recipients['student:' . $id] = ['id' => $id, 'role' => 'student'];
            }
        } catch (Exception $e) {
            error_log('Event notifications student lookup failed: ' . $e->getMessage());
        }

        return array_values($recipients);
    }

    $normDepartment = normalizeDepartmentValue($department);

    try {
        $employeeStmt = $db->prepare("SELECT id FROM employees WHERE status = 'active' AND LOWER(TRIM(COALESCE(department, office, ''))) = ?");
        $employeeStmt->execute([$normDepartment]);
        foreach ($employeeStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $recipients['employee:' . $id] = ['id' => $id, 'role' => 'employee'];
        }
    } catch (Exception $e) {
        error_log('Event notifications employee dept lookup failed: ' . $e->getMessage());
    }

    try {
        $studentStmt = $db->prepare("SELECT id FROM students WHERE status = 'active' AND LOWER(TRIM(COALESCE(department, ''))) = ?");
        $studentStmt->execute([$normDepartment]);
        foreach ($studentStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $recipients['student:' . $id] = ['id' => $id, 'role' => 'student'];
        }
    } catch (Exception $e) {
        error_log('Event notifications student dept lookup failed: ' . $e->getMessage());
    }

    return array_values($recipients);
}

function dispatchEventAnnouncementNotifications($db, $event)
{
    if (empty($event) || empty($event['id'])) {
        return;
    }

    $eventId = (int) $event['id'];
    $title = trim((string) ($event['title'] ?? 'Upcoming Event'));
    $department = $event['department'] ?? 'University Wide';
    $eventDate = (string) ($event['event_date'] ?? '');
    $eventTime = (string) ($event['event_time'] ?? '');

    $timePart = $eventTime ? (' at ' . substr($eventTime, 0, 5)) : '';
    $message = "New event: '{$title}' on {$eventDate}{$timePart} ({$department}).";

    foreach (fetchEventRecipients($db, $department) as $recipient) {
        $refId = 'event_announce_' . $eventId . '_' . $recipient['role'] . '_' . $recipient['id'];
        insertEventNotification(
            $db,
            $recipient['id'],
            $recipient['role'],
            'New Event Announcement',
            $message,
            $eventId,
            'event_announcement',
            $refId
        );
    }
}

function dispatchUpcomingEventReminderNotifications($db)
{
    $stmt = $db->query("SELECT id, title, department, event_date, event_time FROM events WHERE approved = 1 AND event_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($events)) {
        return;
    }

    $todayTag = date('Ymd');
    foreach ($events as $event) {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId <= 0) {
            continue;
        }

        $title = trim((string) ($event['title'] ?? 'Upcoming Event'));
        $department = $event['department'] ?? 'University Wide';
        $eventDate = (string) ($event['event_date'] ?? '');
        $eventTime = (string) ($event['event_time'] ?? '');
        $timePart = $eventTime ? (' at ' . substr($eventTime, 0, 5)) : '';

        foreach (fetchEventRecipients($db, $department) as $recipient) {
            $refId = 'event_reminder_' . $eventId . '_' . $todayTag . '_' . $recipient['role'] . '_' . $recipient['id'];
            insertEventNotification(
                $db,
                $recipient['id'],
                $recipient['role'],
                'Upcoming Event Reminder',
                "Reminder: '{$title}' is scheduled on {$eventDate}{$timePart} ({$department}).",
                $eventId,
                'event_reminder',
                $refId
            );
        }
    }
}

if (!isLoggedIn())
    sendJsonResponse(false, 'Not authenticated', 401);

$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? null;

if (!$userId || !$role)
    sendJsonResponse(false, 'Not authenticated', 401);

$db = (new Database())->getConnection();
$currentUser = (new Auth())->getUser($userId, $role);
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    switch ($method) {
        case 'GET':
            dispatchUpcomingEventReminderNotifications($db);

            // FIX: Now securely checks the new 'department' column first!
            $query = "SELECT e.id, e.title, e.description, e.venue, e.event_date, e.event_time, 
                             e.created_by, e.created_by_role, e.created_at, e.updated_at, e.source_document_id,
                             e.approved, e.approved_by, e.approved_at,
                             COALESCE(e.department, d.departmentFull, d.department, emp.department, stu.department, 'University Wide') AS department, 
                             d.status AS document_status
                      FROM events e
                      LEFT JOIN documents d ON e.source_document_id = d.id
                      LEFT JOIN employees emp ON e.created_by = emp.id AND e.created_by_role = 'employee'
                      LEFT JOIN students stu ON e.created_by = stu.id AND e.created_by_role = 'student'
                      ORDER BY e.event_date, e.event_time";

            $events = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            sendJsonResponse(true, ['events' => $events]);
            break;

        case 'POST':
            if (!in_array($role, ['admin', 'employee']))
                sendJsonResponse(false, 'Not authorized', 403);

            // FIX: Added 'department' to the INSERT
            $stmt = $db->prepare("INSERT INTO events (title, description, venue, department, event_date, event_time, created_by, created_by_role, approved) 
                                  VALUES (:title, :description, :venue, :department, :event_date, :event_time, :created_by, :created_by_role, :approved)");

            $ok = $stmt->execute([
                ':title' => trim($data['title'] ?? ''),
                ':description' => $data['description'] ?? null,
                ':venue' => $data['venue'] ?? null,
                ':department' => $data['department'] ?? null,
                ':event_date' => $data['event_date'] ?? null,
                ':event_time' => $data['event_time'] ?? null,
                ':created_by' => $userId,
                ':created_by_role' => $role,
                ':approved' => $data['approved'] ?? 0
            ]);

            if ($ok) {
                $newEventId = (int) $db->lastInsertId();

                $newEvent = [
                    'id' => $newEventId,
                    'title' => trim((string) ($data['title'] ?? '')),
                    'department' => $data['department'] ?? 'University Wide',
                    'event_date' => $data['event_date'] ?? null,
                    'event_time' => $data['event_time'] ?? null,
                ];

                if ((int) ($data['approved'] ?? 0) === 1) {
                    dispatchEventAnnouncementNotifications($db, $newEvent);
                } else {
                    // Notify admins that a new event is pending review.
                    try {
                        $adminStmt = $db->query("SELECT id FROM administrators WHERE status = 'active'");
                        foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                            insertEventNotification(
                                $db,
                                $adminId,
                                'admin',
                                'Event Pending Approval',
                                "A new event '{$newEvent['title']}' for {$newEvent['department']} is awaiting approval.",
                                $newEventId,
                                'event_pending_approval',
                                'event_pending_' . $newEventId . '_admin_' . $adminId
                            );
                        }
                    } catch (Exception $e) {
                        error_log('Event pending approval notifications failed: ' . $e->getMessage());
                    }
                }

                sendJsonResponse(true, ['message' => 'Event created', 'id' => $newEventId]);
            } else {
                sendJsonResponse(false, 'Event creation failed', 500);
            }
            break;

        case 'PUT':
            $action = $data['action'] ?? null;

            if ($action === 'approve' || $action === 'disapprove') {
                if (!hasHighEventPrivileges($role, $currentUser))
                    sendJsonResponse(false, 'Not authorized to approve events', 403);
                if ($eventId <= 0)
                    sendJsonResponse(false, 'Missing id', 400);

                $approved = ($action === 'approve') ? 1 : 0;
                $stmt = $db->prepare("UPDATE events SET approved = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $ok = $stmt->execute([$approved, $userId, $eventId]);

                if ($ok) {
                    $evStmt = $db->prepare("SELECT id, title, department, event_date, event_time, created_by, created_by_role FROM events WHERE id = ? LIMIT 1");
                    $evStmt->execute([$eventId]);
                    $ev = $evStmt->fetch(PDO::FETCH_ASSOC);

                    if ($ev && $approved) {
                        // Notify creator that approval is complete.
                        insertEventNotification(
                            $db,
                            $ev['created_by'],
                            $ev['created_by_role'],
                            'Event Approved',
                            "Your manually created event '{$ev['title']}' has been approved.",
                            (int) $ev['id'],
                            'event_status_approved',
                            'event_approved_' . $ev['id'] . '_' . $ev['created_by_role'] . '_' . $ev['created_by']
                        );

                        // Broadcast approved event to intended audience.
                        dispatchEventAnnouncementNotifications($db, $ev);
                    }

                    if ($ev && !$approved) {
                        insertEventNotification(
                            $db,
                            $ev['created_by'],
                            $ev['created_by_role'],
                            'Event Disapproved',
                            "Your manually created event '{$ev['title']}' was disapproved.",
                            (int) $ev['id'],
                            'event_status_disapproved',
                            'event_disapproved_' . $ev['id'] . '_' . $ev['created_by_role'] . '_' . $ev['created_by']
                        );
                    }
                }

                sendJsonResponse($ok, $ok ? 'Event ' . ($approved ? 'approved' : 'disapproved') : 'Update failed', $ok ? 200 : 500);
            }

            if (!in_array($role, ['admin', 'employee']))
                sendJsonResponse(false, 'Not authorized', 403);
            if ($eventId <= 0)
                sendJsonResponse(false, 'Missing id', 400);
            if (!canModifyEvent($db, $role, $currentUser, $eventId, $userId))
                sendJsonResponse(false, 'Not authorized to edit this event', 403);

            // FIX: Added 'department' to the UPDATE
            $stmt = $db->prepare("UPDATE events SET title=:title, description=:description, venue=:venue, department=:department, event_date=:event_date, event_time=:event_time, updated_at=NOW() WHERE id=:id");

            $ok = $stmt->execute([
                ':title' => trim($data['title'] ?? ''),
                ':description' => $data['description'] ?? null,
                ':venue' => $data['venue'] ?? null,
                ':department' => $data['department'] ?? null,
                ':event_date' => $data['event_date'] ?? null,
                ':event_time' => $data['event_time'] ?? null,
                ':id' => $eventId
            ]);

            sendJsonResponse($ok, $ok ? 'Event updated' : 'Update failed', $ok ? 200 : 500);
            break;

        case 'DELETE':
            if (!in_array($role, ['admin', 'employee']))
                sendJsonResponse(false, 'Not authorized', 403);
            if ($eventId <= 0)
                sendJsonResponse(false, 'Missing id', 400);
            if (!canModifyEvent($db, $role, $currentUser, $eventId, $userId))
                sendJsonResponse(false, 'Not authorized to delete this event', 403);

            $stmt = $db->prepare("DELETE FROM events WHERE id=:id");
            $ok = $stmt->execute([':id' => $eventId]);

            sendJsonResponse($ok, $ok ? 'Event deleted' : 'Delete failed', $ok ? 200 : 500);
            break;

        default:
            sendJsonResponse(false, 'Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log("Events API Error: " . $e->getMessage());
    sendJsonResponse(false, 'Server error', 500);
}