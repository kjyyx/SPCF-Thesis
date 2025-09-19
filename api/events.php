<?php
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
            $query = "SELECT * FROM events ORDER BY event_date, event_time";
            $stmt = $db->prepare($query);
            $stmt->execute();

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $events[] = $row;
            }

            echo json_encode(['success' => true, 'events' => $events]);
            break;

        case 'POST':
            if (!in_array($role, ['admin', 'employee'])) {
                echo json_encode(['success' => false, 'message' => 'Not authorized']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $query = "INSERT INTO events 
                      (title, description, event_date, event_time, department, created_by, created_by_role) 
                      VALUES (:title, :description, :event_date, :event_time, :department, :created_by, :created_by_role)";
            $stmt = $db->prepare($query);

            $stmt->bindParam(':title', $data['title']);
            $stmt->bindParam(':description', $data['description']);
            $stmt->bindParam(':event_date', $data['event_date']);
            $stmt->bindParam(':event_time', $data['event_time']);
            $stmt->bindParam(':department', $data['department']);
            $stmt->bindParam(':created_by', $userId);
            $stmt->bindParam(':created_by_role', $role);

            if ($stmt->execute()) {
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

            $stmt = $db->prepare("UPDATE events 
                SET title=:title, description=:description, event_date=:event_date, event_time=:event_time, department=:department, updated_at=NOW()
                WHERE id=:id");
            $ok = $stmt->execute([
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':event_date' => $data['event_date'],
                ':event_time' => $data['event_time'],
                ':department' => $data['department'],
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