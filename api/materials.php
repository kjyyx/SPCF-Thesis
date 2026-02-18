<?php
/**
 * Materials API - Public Materials Management with Approval Workflow
 * ===========================================
 *
 * Manages downloadable public materials (documents, files) with hierarchical approval:
 * College Student Council Adviser -> College Dean -> OIC-OSA
 *
 * - GET: List materials or download specific files
 * - POST: Upload new materials (students/admins) and initiate workflow
 * - PUT: Approve/reject materials (approvers only) and advance workflow
 * - DELETE: Remove materials (admins only)
 *
 * Handles file uploads, downloads, and storage tracking.
 * Tracks download counts for analytics.
 * Workflow steps stored in materials_steps table.
 */

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

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

        // Check if requesting materials for approval (for approvers)
        $forApproval = isset($_GET['for_approval']) && $_GET['for_approval'] == '1';
        if ($forApproval) {
            try {
                // Validate session variables
                if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !isset($_SESSION['position'])) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'Session expired or invalid']);
                    exit();
                }

                // Only allow specific approver roles
                $userRole = $_SESSION['user_role'];
                $userPosition = $_SESSION['position'] ?? '';
                $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
                
                if ($userRole !== 'employee' || !in_array($userPosition, $allowedPositions)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                // Get materials pending approval for this user's step
                $stepOrder = getStepOrderForPosition($userPosition);
                if ($stepOrder === null) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid position for approval']);
                    exit();
                }

                $department = $_SESSION['department'] ?? null; // For College Student Council Adviser filtering

                $whereClause = "ms.assigned_to_employee_id = ? AND ms.status = 'pending' AND ms.step_order = ?";
                $params = [$_SESSION['user_id'], $stepOrder];

                // For College Student Council Adviser, filter by department
                if ($userPosition === 'College Student Council Adviser' && $department) {
                    $whereClause .= " AND s.department = ?";
                    $params[] = $department;
                }

                $stmt = $conn->prepare("
                    SELECT m.*, ms.step_order, ms.status as step_status, ms.assigned_to_employee_id
                    FROM materials m
                    JOIN materials_steps ms ON m.id = ms.material_id
                    LEFT JOIN students s ON m.student_id = s.id
                    WHERE " . $whereClause . "
                    ORDER BY m.uploaded_at DESC
                ");
                $stmt->execute($params);
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'materials' => $materials]);
            } catch (Exception $e) {
                error_log('Error in materials approval query: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
            }
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
        $status = $_GET['status'] ?? null;
        $search = $_GET['search'] ?? null;
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        // Build WHERE clause
        $where = '';
        $params = [];
        if ($status) {
            $where .= " AND m.status = :status";
            $params[':status'] = $status;
        }
        if ($search) {
            $searchTerm = "%$search%";
            $where .= " AND (m.title LIKE :search OR m.description LIKE :search OR CONCAT(s.first_name, ' ', s.last_name) LIKE :search OR s.department LIKE :search)";
            $params[':search'] = $searchTerm;
        }
        if ($start_date) {
            $where .= " AND DATE(m.uploaded_at) >= :start_date";
            $params[':start_date'] = $start_date;
        }
        if ($end_date) {
            $where .= " AND DATE(m.uploaded_at) <= :end_date";
            $params[':end_date'] = $end_date;
        }

        // Get total count
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM materials m LEFT JOIN students s ON m.student_id = s.id LEFT JOIN employees e ON m.approved_by = e.id WHERE 1=1" . $where);
        $countStmt->execute($params);
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
        WHERE 1=1" . $where . "
        ORDER BY m.uploaded_at DESC 
        LIMIT :limit OFFSET :offset
    ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
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
        $uploadDir = ROOT_PATH . 'uploads/';
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

        // After inserting material, create workflow steps
        createMaterialWorkflow($conn, $newId, $studentId);

        echo json_encode(['success' => true, 'id' => $newId]);
        break;

    case 'PUT':
        // Handle approve/reject for workflow
        $id = $_GET['id'] ?? null;
        $action = $_POST['action'] ?? null; // approve or reject
        $note = $_POST['note'] ?? null;

        if (!$id || !in_array($action, ['approve', 'reject'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit();
        }

        // Check if user can approve this material
        $userId = $_SESSION['user_id'];
        $userPosition = $_SESSION['position'] ?? '';
        $stepOrder = getStepOrderForPosition($userPosition);

        $stmt = $conn->prepare("
            SELECT ms.* FROM materials_steps ms
            WHERE ms.material_id = ? AND ms.assigned_to_employee_id = ? AND ms.status = 'pending' AND ms.step_order = ?
        ");
        $stmt->execute([$id, $userId, $stepOrder]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$step) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized to approve this material']);
            exit();
        }

        // Update step status
        $newStatus = $action === 'approve' ? 'completed' : 'rejected';
        $stmt = $conn->prepare("UPDATE materials_steps SET status = ?, completed_at = NOW(), note = ? WHERE id = ?");
        $stmt->execute([$newStatus, $note, $step['id']]);

        if ($action === 'approve') {
            // Check if this is the final step
            $totalSteps = 3; // CSC -> Dean -> OIC-OSA
            if ($stepOrder < $totalSteps) {
                // Advance to next step
                $nextStepOrder = $stepOrder + 1;
                $nextAssignee = getAssigneeForStep($conn, $id, $nextStepOrder);
                if ($nextAssignee) {
                    $stmt = $conn->prepare("INSERT INTO materials_steps (material_id, step_order, assigned_to_employee_id, status) VALUES (?, ?, ?, 'pending')");
                    $stmt->execute([$id, $nextStepOrder, $nextAssignee]);
                }
            } else {
                // Final approval - update material status
                $stmt = $conn->prepare("UPDATE materials SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$userId, $id]);
            }
        } else {
            // Rejected - update material status
            $stmt = $conn->prepare("UPDATE materials SET status = 'rejected', rejected_by = ?, rejected_at = NOW() WHERE id = ?");
            $stmt->execute([$userId, $id]);
        }

        echo json_encode(['success' => true]);
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

// Helper functions
function getStepOrderForPosition($position) {
    $steps = [
        'College Student Council Adviser' => 1,
        'College Dean' => 2,
        'Officer-in-Charge, Office of Student Affairs (OIC-OSA)' => 3
    ];
    return $steps[$position] ?? null;
}

function createMaterialWorkflow($conn, $materialId, $studentId) {
    // Get student's department
    $stmt = $conn->prepare("SELECT department FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC)['department'];

    // Step 1: College Student Council Adviser for the department
    $cscAdviser = getEmployeeByPositionAndDepartment($conn, 'College Student Council Adviser', $department);
    if ($cscAdviser) {
        $stmt = $conn->prepare("INSERT INTO materials_steps (material_id, step_order, assigned_to_employee_id, status) VALUES (?, 1, ?, 'pending')");
        $stmt->execute([$materialId, $cscAdviser]);
    }

    // Note: Steps 2 and 3 will be created when step 1 is approved
}

function getAssigneeForStep($conn, $materialId, $stepOrder) {
    // Get material's department
    $stmt = $conn->prepare("SELECT s.department FROM materials m JOIN students s ON m.student_id = s.id WHERE m.id = ?");
    $stmt->execute([$materialId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC)['department'];

    if ($stepOrder == 2) {
        // College Dean
        return getEmployeeByPositionAndDepartment($conn, 'College Dean', $department);
    } elseif ($stepOrder == 3) {
        // OIC-OSA
        return getEmployeeByPosition($conn, 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)');
    }
    return null;
}

function getEmployeeByPositionAndDepartment($conn, $position, $department) {
    $stmt = $conn->prepare("SELECT id FROM employees WHERE position = ? AND department = ? LIMIT 1");
    $stmt->execute([$position, $department]);
    return $stmt->fetch(PDO::FETCH_ASSOC)['id'] ?? null;
}

function getEmployeeByPosition($conn, $position) {
    $stmt = $conn->prepare("SELECT id FROM employees WHERE position = ? LIMIT 1");
    $stmt->execute([$position]);
    return $stmt->fetch(PDO::FETCH_ASSOC)['id'] ?? null;
}
?>