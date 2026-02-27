<?php
/**
 * Events API - Event Management
 * =============================
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';
// require_once ROOT_PATH . 'includes/utilities.php'; // Consider moving sendJsonResponse here if you haven't!

// ------------------------------------------------------------------
// Helper Functions
// ------------------------------------------------------------------

function sendJsonResponse($success, $payload = '', $statusCode = 200) {
    http_response_code($statusCode);
    $response = is_string($payload) ? ['success' => $success, 'message' => $payload] : array_merge(['success' => $success], $payload);
    echo json_encode($response);
    exit;
}

function getUnitIdByName($db, $name) {
    if (!$name) return null;
    $stmt = $db->prepare("SELECT id FROM units WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $name]);
    return $stmt->fetchColumn() ?: null;
}

function hasHighEventPrivileges($role, $currentUser) {
    if ($role === 'admin') return true;
    if ($role === 'employee') {
        $position = $currentUser['position'] ?? '';
        return (strpos($position, 'Executive Vice-President') !== false || 
                strpos($position, 'Physical Plant and Facilities Office') !== false);
    }
    return false;
}

function canModifyEvent($db, $role, $currentUser, $eventId, $userId) {
    if (hasHighEventPrivileges($role, $currentUser)) return true;
    if ($role !== 'employee') return false;

    // Standard employees can only modify their own events
    $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE id = ? AND created_by = ?");
    $stmt->execute([$eventId, $userId]);
    return $stmt->fetchColumn() > 0;
}

// ------------------------------------------------------------------
// Request Handling
// ------------------------------------------------------------------

if (!isLoggedIn()) sendJsonResponse(false, 'Not authenticated', 401);

$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? null;

if (!$userId || !$role) sendJsonResponse(false, 'Not authenticated', 401);

$db = (new Database())->getConnection();
$currentUser = (new Auth())->getUser($userId, $role);
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?: [];

// PHP automatically populates $_GET from the query string, even for PUT/DELETE
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0; 

try {
    switch ($method) {
        case 'GET':
            $query = "SELECT e.id, e.title, e.description, e.venue, e.event_date, e.event_time, e.unit_id,
                             e.created_by, e.created_by_role, e.created_at, e.updated_at, e.source_document_id,
                             e.approved, e.approved_by, e.approved_at,
                             u.name AS department, d.status AS document_status
                      FROM events e
                      LEFT JOIN units u ON e.unit_id = u.id
                      LEFT JOIN documents d ON e.source_document_id = d.id
                      ORDER BY e.event_date, e.event_time";
            
            $events = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            sendJsonResponse(true, ['events' => $events]);
            break;

        case 'POST':
            if (!in_array($role, ['admin', 'employee'])) sendJsonResponse(false, 'Not authorized', 403);

            $stmt = $db->prepare("INSERT INTO events (title, description, venue, event_date, event_time, unit_id, created_by, created_by_role, approved) 
                                  VALUES (:title, :description, :venue, :event_date, :event_time, :unit_id, :created_by, :created_by_role, :approved)");
            
            $ok = $stmt->execute([
                ':title' => trim($data['title'] ?? ''),
                ':description' => $data['description'] ?? null,
                ':venue' => $data['venue'] ?? null,
                ':event_date' => $data['event_date'] ?? null,
                ':event_time' => $data['event_time'] ?? null,
                ':unit_id' => getUnitIdByName($db, $data['department'] ?? null),
                ':created_by' => $userId,
                ':created_by_role' => $role,
                ':approved' => $data['approved'] ?? 0
            ]);

            if ($ok) {
                sendJsonResponse(true, ['message' => 'Event created', 'id' => $db->lastInsertId()]);
            } else {
                sendJsonResponse(false, 'Event creation failed', 500);
            }
            break;

        case 'PUT':
            $action = $data['action'] ?? null;

            if ($action === 'approve' || $action === 'disapprove') {
                if (!hasHighEventPrivileges($role, $currentUser)) sendJsonResponse(false, 'Not authorized to approve events', 403);
                if ($eventId <= 0) sendJsonResponse(false, 'Missing id', 400);

                $approved = ($action === 'approve') ? 1 : 0;
                $stmt = $db->prepare("UPDATE events SET approved = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $ok = $stmt->execute([$approved, $userId, $eventId]);
                
                sendJsonResponse($ok, $ok ? 'Event ' . ($approved ? 'approved' : 'disapproved') : 'Update failed', $ok ? 200 : 500);
            }

            // Regular update
            if (!in_array($role, ['admin', 'employee'])) sendJsonResponse(false, 'Not authorized', 403);
            if ($eventId <= 0) sendJsonResponse(false, 'Missing id', 400);
            if (!canModifyEvent($db, $role, $currentUser, $eventId, $userId)) sendJsonResponse(false, 'Not authorized to edit this event', 403);

            $stmt = $db->prepare("UPDATE events SET title=:title, description=:description, venue=:venue, event_date=:event_date, event_time=:event_time, unit_id=:unit_id, updated_at=NOW() WHERE id=:id");
            
            $ok = $stmt->execute([
                ':title' => trim($data['title'] ?? ''),
                ':description' => $data['description'] ?? null,
                ':venue' => $data['venue'] ?? null,
                ':event_date' => $data['event_date'] ?? null,
                ':event_time' => $data['event_time'] ?? null,
                ':unit_id' => getUnitIdByName($db, $data['department'] ?? null),
                ':id' => $eventId
            ]);

            sendJsonResponse($ok, $ok ? 'Event updated' : 'Update failed', $ok ? 200 : 500);
            break;

        case 'DELETE':
            if (!in_array($role, ['admin', 'employee'])) sendJsonResponse(false, 'Not authorized', 403);
            if ($eventId <= 0) sendJsonResponse(false, 'Missing id', 400);
            if (!canModifyEvent($db, $role, $currentUser, $eventId, $userId)) sendJsonResponse(false, 'Not authorized to delete this event', 403);

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