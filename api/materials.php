<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';

requireAuth(); // Ensure user is logged in

// Only admins can access materials API
if ($_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all materials
        $stmt = $conn->prepare("SELECT * FROM documents WHERE status = 'approved' ORDER BY uploaded_at DESC");
        $stmt->execute();
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'materials' => $materials]);
        break;
        
    case 'POST':
        // Add new material (assuming from form or upload)
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
            exit();
        }
        
        $stmt = $conn->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, uploaded_at) VALUES (?, 'material', ?, ?, 'approved', NOW())");
        $stmt->execute([$data['student_id'], $data['title'], $data['description']]);
        
        echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
        break;
        
    case 'DELETE':
        // Delete material
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Material ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM documents WHERE id = ? AND status = 'approved'");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true]);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>