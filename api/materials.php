<?php
/**
 * Materials API - Public Materials Management
 * ===========================================
 *
 * Manages downloadable public materials (documents, files):
 * - GET: List materials or download specific files
 * - POST: Upload new materials (admins only)
 * - PUT: Update material metadata (admins only)
 * - DELETE: Remove materials (admins only)
 *
 * Handles file uploads, downloads, and storage tracking.
 * Tracks download counts for analytics.
 */

require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';

requireAuth(); // Ensure user is logged in

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if download request
        if (isset($_GET['download']) && $_GET['download'] == '1') {
            /**
             * GET /api/materials.php?download=1&id={id} - Download file
             * =======================================================
             * Downloads a specific material file.
             * Increments download counter for analytics.
             * Serves file directly to browser.
             */

            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Material ID required']);
                exit();
            }

            // Get material
            $stmt = $conn->prepare("SELECT * FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$material) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Material not found']);
                exit();
            }

            $filePath = $material['file_path'];
            if (!file_exists($filePath)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'File not found']);
                exit();
            }

            // Increment downloads
            $stmt = $conn->prepare("UPDATE materials SET downloads = downloads + 1 WHERE id = ?");
            $stmt->execute([$id]);

            // Serve file
            $fileName = basename($filePath);
            header('Content-Type: ' . mime_content_type($filePath));
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit();
        }

        // Only admins can view materials
        if ($_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        // Get materials with pagination
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;

        // Get total count
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM materials");
        $countStmt->execute();
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get paginated materials
        $stmt = $conn->prepare("
        SELECT m.*, 
               CONCAT(s.first_name, ' ', s.last_name) as submitted_by, 
               s.department,
               CONCAT(e.first_name, ' ', e.last_name) as approved_by_name
        FROM materials m
        LEFT JOIN students s ON m.student_id = s.id
        LEFT JOIN employees e ON m.approved_by = e.id
        ORDER BY m.uploaded_at DESC 
        LIMIT :limit OFFSET :offset
    ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = ceil($total / $limit);
        echo json_encode(['success' => true, 'materials' => $materials, 'total' => $total, 'page' => $page, 'limit' => $limit, 'totalPages' => $totalPages]);
        break;

    case 'POST':
        // Allow students and admins to upload
        $action = $_POST['action'] ?? null;
        if ($action !== 'upload') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit();
        }

        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            exit();
        }

        $file = $_FILES['file'];
        $studentId = $_POST['student_id'] ?? null;
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        error_log("Upload attempt: student_id=$studentId, title=$title, description=$description");
        $fileSizeKb = round($file['size'] / 1024);

        // Generate next ID, handling both old INT ids and new MAT ids
        $stmt = $conn->prepare("
            SELECT GREATEST(
                COALESCE(MAX(CASE WHEN id REGEXP '^MAT[0-9]+$' THEN CAST(SUBSTRING(id, 4) AS UNSIGNED) ELSE 0 END), 0),
                COALESCE(MAX(CASE WHEN id NOT REGEXP '^MAT[0-9]+$' THEN CAST(id AS UNSIGNED) ELSE 0 END), 0)
            ) as max_id FROM materials
        ");
        $stmt->execute();
        $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
        $newId = 'MAT' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);

        // Validate inputs
        if (!$studentId || !$title) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($file['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file type']);
            exit();
        }

        // Validate file size (10MB max)
        if ($file['size'] > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File too large']);
            exit();
        }

        // Create uploads directory if it doesn't exist
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $uniqueName = uniqid('pubmat_', true) . '.' . $fileExtension;
        $filePath = $uploadDir . $uniqueName;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save file']);
            exit();
        }

        // Insert with new ID and description
        $stmt = $conn->prepare("INSERT INTO materials (id, student_id, title, description, file_path, status, file_size_kb, downloads) VALUES (?, ?, ?, ?, ?, 'pending', ?, 0)");
        $stmt->execute([$newId, $studentId, $title, $description, $filePath, $fileSizeKb]);

        echo json_encode(['success' => true, 'id' => $newId]);
        break;

    case 'DELETE':
        // Only admins can delete materials
        if ($_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }

        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Material ID required']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM materials WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>