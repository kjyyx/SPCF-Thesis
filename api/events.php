<?php
/**
 * Events API - Event Management
 * =============================
 *
 * Manages university events with the following operations:
 * - GET: Retrieve all events with department information
 * - POST: Create new events (admins/employees only)
 * - PUT: Update existing events (admins/employees only)
 * - DELETE: Remove events (admins/employees only)
 *
 * Events are associated with academic units/departments.
 * Only authorized personnel can modify events.
 */

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/session.php';
require_once '../includes/database.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // Only employees/admins can modify events
    $role = $_SESSION['user_role'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    switch ($method) {
        case 'GET':
            /**
             * GET /api/events.php - Retrieve all events
             * =========================================
             * Returns list of all events with department names.
             * Available to all authenticated users.
             * Events are ordered by date and time.
             */

            // Join units to expose readable department name for UI
            $query = "SELECT e.id, e.title, e.description, e.event_date, e.event_time, e.unit_id,
                             e.created_by, e.created_by_role, e.created_at, e.updated_at,
                             u.name AS department
                      FROM events e
                      LEFT JOIN units u ON e.unit_id = u.id
                      ORDER BY e.event_date, e.event_time";
            $stmt = $db->prepare($query);
            $stmt->execute();

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $events[] = $row;
            }

            echo json_encode(['success' => true, 'events' => $events]);
            break;

        case 'POST':
            /**
             * POST /api/events.php - Create new event
             * =======================================
             * Creates a new event (admins/employees only).
             * Requires title, description, date, time, and unit_id.
             */

            if (!in_array($role, ['admin', 'employee'])) {
                echo json_encode(['success' => false, 'message' => 'Not authorized']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $title = trim($data['title'] ?? '');
            $description = $data['description'] ?? null;
            $event_date = $data['event_date'] ?? null;
            $event_time = $data['event_time'] ?? null; // can be null
            $departmentName = $data['department'] ?? null; // UI sends department name; map to unit_id

            // Map department name to unit_id (optional)
            $unit_id = null;
            if ($departmentName) {
                $u = $db->prepare("SELECT id FROM units WHERE name = :name LIMIT 1");
                $u->execute([':name' => $departmentName]);
                $row = $u->fetch(PDO::FETCH_ASSOC);
                if ($row) { $unit_id = (int)$row['id']; }
            }

            $query = "INSERT INTO events (title, description, event_date, event_time, unit_id, created_by, created_by_role)
                      VALUES (:title, :description, :event_date, :event_time, :unit_id, :created_by, :created_by_role)";
            $stmt = $db->prepare($query);
            $ok = $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':event_date' => $event_date,
                ':event_time' => $event_time,
                ':unit_id' => $unit_id,
                ':created_by' => $userId,
                ':created_by_role' => $role,
            ]);

            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Event created', 'id' => $db->lastInsertId()]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Event creation failed']);
            }
            break;

        case 'PUT':
            if (!in_array($role, ['admin', 'employee'])) {
                echo json_encode(['success' => false, 'message' => 'Not authorized']);
                exit();
            }

            parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
            $id = isset($qs['id']) ? (int) $qs['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Missing id']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            // Admin can edit any; employee can edit only own
            if ($role === 'employee') {
                $ownCheck = $db->prepare("SELECT COUNT(*) FROM events WHERE id = :id AND created_by = :uid");
                $ownCheck->execute([':id' => $id, ':uid' => $userId]);
                if ($ownCheck->fetchColumn() == 0) {
                    echo json_encode(['success' => false, 'message' => 'Not authorized to edit this event']);
                    exit();
                }
            }

            $title = trim($data['title'] ?? '');
            $description = $data['description'] ?? null;
            $event_date = $data['event_date'] ?? null;
            $event_time = $data['event_time'] ?? null;
            $departmentName = $data['department'] ?? null;

            $unit_id = null;
            if ($departmentName) {
                $u = $db->prepare("SELECT id FROM units WHERE name = :name LIMIT 1");
                $u->execute([':name' => $departmentName]);
                $row = $u->fetch(PDO::FETCH_ASSOC);
                if ($row) { $unit_id = (int)$row['id']; }
            }

            $stmt = $db->prepare("UPDATE events 
                SET title=:title, description=:description, event_date=:event_date, event_time=:event_time, unit_id=:unit_id, updated_at=NOW()
                WHERE id=:id");
            $ok = $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':event_date' => $event_date,
                ':event_time' => $event_time,
                ':unit_id' => $unit_id,
                ':id' => $id
            ]);

            echo json_encode(['success' => $ok, 'message' => $ok ? 'Event updated' : 'Update failed']);
            break;

        case 'DELETE':
            if (!in_array($role, ['admin', 'employee'])) {
                echo json_encode(['success' => false, 'message' => 'Not authorized']);
                exit();
            }

            parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
            $id = isset($qs['id']) ? (int) $qs['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Missing id']);
                exit();
            }

            // Admin can delete any; employee can delete only own
            if ($role === 'employee') {
                $ownCheck = $db->prepare("SELECT COUNT(*) FROM events WHERE id = :id AND created_by = :uid");
                $ownCheck->execute([':id' => $id, ':uid' => $userId]);
                if ($ownCheck->fetchColumn() == 0) {
                    echo json_encode(['success' => false, 'message' => 'Not authorized to delete this event']);
                    exit();
                }
            }

            $stmt = $db->prepare("DELETE FROM events WHERE id=:id");
            $ok = $stmt->execute([':id' => $id]);

            echo json_encode(['success' => $ok, 'message' => $ok ? 'Event deleted' : 'Delete failed']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    error_log("Events API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}