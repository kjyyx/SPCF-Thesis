<?php
/**
 * Materials API - Public Materials Management with Approval Workflow
 * ===========================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'vendor/autoload.php';

header('Content-Type: application/json');

requireAuth(); // Ensure user is logged in

$db = new Database();
$conn = $db->getConnection();

function materialAbsolutePath($storedPath) {
    $storedPath = trim((string) $storedPath);
    if ($storedPath === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $storedPath) || str_starts_with($storedPath, '/') || str_starts_with($storedPath, '\\\\')) {
        return $storedPath;
    }

    if (str_starts_with($storedPath, '../uploads/')) {
        return ROOT_PATH . 'uploads/' . basename($storedPath);
    }

    if (str_starts_with($storedPath, 'uploads/')) {
        return ROOT_PATH . ltrim($storedPath, '/');
    }

    return ROOT_PATH . 'uploads/' . basename($storedPath);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if download request
        if (isset($_GET['download']) && $_GET['download'] == '1') {
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Material ID required']);
                exit();
            }

            // Check access permissions
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $userPosition = $_SESSION['position'] ?? '';
            $isPpfo = ($userRole === 'employee' && $userPosition === 'Physical Plant and Facilities Office (PPFO)');
            
            // Check if user is an approver
            $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
            $isApprover = ($userRole === 'employee' && in_array($userPosition, $allowedPositions));
            
            if ($isApprover) {
                // Check if this material is assigned to this approver
                $stepOrder = getStepOrderForPosition($userPosition);
                $checkStmt = $conn->prepare("
                    SELECT 1 FROM materials_steps 
                    WHERE material_id = ? AND assigned_to_employee_id = ? AND step_order = ?
                ");
                $checkStmt->execute([$id, $userId, $stepOrder]);
                $hasAccess = $checkStmt->fetch();
            } else {
                $hasAccess = false;
            }
            
            // Also allow admins
            if ($userRole === 'admin') {
                $hasAccess = true;
            } elseif (!$hasAccess) {
                // Check if user is the student who owns the material
                $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
                $stmt->execute([$id]);
                $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['student_id'];
                $hasAccess = ($ownerId == $userId);
            }

            // Allow PPFO to download approved materials for slideshow operations
            if (!$hasAccess && $isPpfo) {
                $stmt = $conn->prepare("SELECT 1 FROM materials WHERE id = ? AND status = 'approved'");
                $stmt->execute([$id]);
                $hasAccess = (bool) $stmt->fetch();
            }
            
            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit();
            }

            $stmt = $conn->prepare("SELECT * FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$material) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Material not found']);
                exit();
            }

            $filePath = materialAbsolutePath($material['file_path'] ?? '');
            if (!$filePath || !file_exists($filePath)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'File not found']);
                exit();
            }

            $stmt = $conn->prepare("UPDATE materials SET downloads = downloads + 1 WHERE id = ?");
            $stmt->execute([$id]);

            $fileName = basename($filePath);
            header('Content-Type: ' . mime_content_type($filePath));
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit();
        }

        // Check if serving image for preview
        if (isset($_GET['action']) && $_GET['action'] === 'serve_image' && isset($_GET['id'])) {
            $id = $_GET['id'];
            
            // First check if user has access to this material
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $userPosition = $_SESSION['position'] ?? '';
            $isPpfo = ($userRole === 'employee' && $userPosition === 'Physical Plant and Facilities Office (PPFO)');
            
            // Check if user is an approver (CSC Adviser, Dean, OIC-OSA)
            $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
            $isApprover = ($userRole === 'employee' && in_array($userPosition, $allowedPositions));
            
            if ($isApprover) {
                // Check if this material is assigned to this approver
                $stepOrder = getStepOrderForPosition($userPosition);
                $checkStmt = $conn->prepare("
                    SELECT 1 FROM materials_steps 
                    WHERE material_id = ? AND assigned_to_employee_id = ? AND step_order = ?
                ");
                $checkStmt->execute([$id, $userId, $stepOrder]);
                $hasAccess = $checkStmt->fetch();
            } else {
                $hasAccess = false;
            }
            
            // Also allow admins and the student who owns the material
            if ($userRole === 'admin') {
                $hasAccess = true;
            } elseif (!$hasAccess) {
                // Check if user is the student who owns the material
                $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
                $stmt->execute([$id]);
                $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['student_id'];
                $hasAccess = ($ownerId == $userId);
            }

            // Allow PPFO to preview approved materials in the public slideshow
            if (!$hasAccess && $isPpfo) {
                $stmt = $conn->prepare("SELECT 1 FROM materials WHERE id = ? AND status = 'approved'");
                $stmt->execute([$id]);
                $hasAccess = (bool) $stmt->fetch();
            }
            
            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit();
            }
            
            $stmt = $conn->prepare("SELECT file_path, file_type FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $absolutePath = materialAbsolutePath($material['file_path'] ?? '');
            if (!$material || !$absolutePath || !file_exists($absolutePath)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'File not found']);
                exit();
            }
            
            // Serve the image
            header('Content-Type: ' . $material['file_type']);
            header('Content-Length: ' . filesize($absolutePath));
            header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
            readfile($absolutePath);
            exit();
        }

        // Check if requesting comments for a material
        if (isset($_GET['action']) && $_GET['action'] === 'get_comments' && isset($_GET['id'])) {
            $materialId = $_GET['id'];
            getMaterialComments($materialId);
            exit();
        }

        // Check if requesting single material details (simple version)
        if (isset($_GET['action']) && $_GET['action'] === 'get_material' && isset($_GET['id'])) {
            $id = $_GET['id'];
            
            $stmt = $conn->prepare("
                SELECT m.*, 
                       CONCAT(s.first_name, ' ', s.last_name) as creator_name,
                       s.department
                FROM materials m
                LEFT JOIN students s ON m.student_id = s.id
                WHERE m.id = ?
            ");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($material) {
                echo json_encode(['success' => true, 'material' => $material]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Material not found']);
            }
            exit();
        }

        // ===== MATERIAL DETAILS WITH WORKFLOW AND COMMENTS =====
        if (isset($_GET['action']) && $_GET['action'] === 'get_material_details' && isset($_GET['id'])) {
            $id = $_GET['id'];
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $userPosition = $_SESSION['position'] ?? '';
            
            // Check if user is an approver
            $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
            $isApprover = ($userRole === 'employee' && in_array($userPosition, $allowedPositions));
            
            if ($isApprover) {
                // Check if this material is assigned to this approver (any step, not just pending)
                $stepOrder = getStepOrderForPosition($userPosition);
                $checkStmt = $conn->prepare("
                    SELECT 1 FROM materials_steps 
                    WHERE material_id = ? AND assigned_to_employee_id = ? AND step_order = ?
                ");
                $checkStmt->execute([$id, $userId, $stepOrder]);
                $hasAccess = $checkStmt->fetch();
                
                // Also allow if they're the approver for any step (for viewing history)
                if (!$hasAccess) {
                    $checkStmt = $conn->prepare("
                        SELECT 1 FROM materials_steps 
                        WHERE material_id = ? AND assigned_to_employee_id = ?
                    ");
                    $checkStmt->execute([$id, $userId]);
                    $hasAccess = $checkStmt->fetch();
                }
            } else {
                $hasAccess = false;
            }
            
            // Also allow admins
            if ($userRole === 'admin') {
                $hasAccess = true;
            } elseif (!$hasAccess) {
                // Check if user is the student who owns the material
                $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
                $stmt->execute([$id]);
                $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['student_id'];
                $hasAccess = ($ownerId == $userId);
            }
            
            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit();
            }
            
            // Get material with creator info
            $stmt = $conn->prepare("
                SELECT m.*, 
                       CONCAT(s.first_name, ' ', s.last_name) as creator_name,
                       s.department
                FROM materials m
                LEFT JOIN students s ON m.student_id = s.id
                WHERE m.id = ?
            ");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$material) {
                echo json_encode(['success' => false, 'message' => 'Material not found']);
                exit();
            }

            // Clean up the file path - store only the filename for security
            $material['file_path'] = basename($material['file_path']);
            
            // Get workflow history from materials_steps
            $stepsStmt = $conn->prepare("
                SELECT ms.*, 
                       e.first_name as assignee_first, 
                       e.last_name as assignee_last,
                       e.position as assignee_position,
                       CASE 
                           WHEN ms.step_order = 1 THEN 'College Student Council Adviser Approval'
                           WHEN ms.step_order = 2 THEN 'College Dean Approval'
                           WHEN ms.step_order = 3 THEN 'OIC-OSA Approval'
                       END as step_name
                FROM materials_steps ms
                LEFT JOIN employees e ON ms.assigned_to_employee_id = e.id
                WHERE ms.material_id = ?
                ORDER BY ms.step_order ASC
            ");
            $stepsStmt->execute([$id]);
            $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build workflow history
            $workflow_history = [];
            $current_location = 'Pending';
            
            foreach ($steps as $step) {
                $action = 'Pending';
                if ($step['status'] === 'completed') {
                    $action = 'Approved';
                } elseif ($step['status'] === 'rejected') {
                    $action = 'Rejected';
                }
                
                // Determine current location
                if ($step['status'] === 'pending') {
                    $current_location = $step['step_name'];
                } elseif ($step['status'] === 'completed' && $step['step_order'] == 3) {
                    $current_location = 'Completed';
                } elseif ($step['status'] === 'rejected') {
                    $current_location = $step['step_name'] . ' (Rejected)';
                }
                
                $workflow_history[] = [
                    'created_at' => $step['completed_at'] ?: $material['uploaded_at'],
                    'action' => $action,
                    'office_name' => $step['step_name'],
                    'status' => $step['status'],
                    'note' => $step['note']
                ];
            }
            
            // Get comments with error handling if table doesn't exist
            $formattedComments = [];
            try {
                // Check if materials_notes table exists
                $tableCheck = $conn->query("SHOW TABLES LIKE 'materials_notes'");
                if ($tableCheck->rowCount() > 0) {
                    $commentsStmt = $conn->prepare("
                        SELECT n.*,
                               CASE 
                                   WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                                   WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                                   WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                               END as author_name,
                               CASE 
                                   WHEN n.author_role = 'employee' THEN e.position
                                   WHEN n.author_role = 'student' THEN s.position
                                   WHEN n.author_role = 'admin' THEN a.position
                               END as author_position
                        FROM materials_notes n
                        LEFT JOIN students s ON n.author_id = s.id AND n.author_role = 'student'
                        LEFT JOIN employees e ON n.author_id = e.id AND n.author_role = 'employee'
                        LEFT JOIN administrators a ON n.author_id = a.id AND n.author_role = 'admin'
                        WHERE n.material_id = ?
                        ORDER BY n.created_at ASC
                    ");
                    $commentsStmt->execute([$id]);
                    $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $formattedComments = array_map(function($comment) {
                        return [
                            'id' => (int) $comment['id'],
                            'parent_id' => $comment['parent_note_id'],
                            'comment' => $comment['note'],
                            'created_at' => $comment['created_at'],
                            'author_name' => $comment['author_name'] ?: 'Unknown',
                            'author_role' => $comment['author_role'],
                            'author_position' => $comment['author_position'] ?: ''
                        ];
                    }, $comments);
                }
            } catch (PDOException $e) {
                // Table doesn't exist or other error - just return empty comments
                error_log("Error fetching material comments: " . $e->getMessage());
            }
            
            $material['workflow_history'] = $workflow_history;
            $material['comments'] = $formattedComments;
            $material['current_location'] = $current_location;
            
            echo json_encode(['success' => true, 'material' => $material]);
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

                $whereClause = "ms.assigned_to_employee_id = :user_id AND ms.status = 'pending' AND ms.step_order = :step_order";
                $params = [':user_id' => $_SESSION['user_id'], ':step_order' => $stepOrder];
                
                // For College Student Council Adviser, filter by department
                if ($userPosition === 'College Student Council Adviser' && $department) {
                    $whereClause .= " AND s.department = :department";
                    $params[':department'] = $department;
                }

                $stmt = $conn->prepare("
                    SELECT m.id, m.student_id, m.title, m.description, REPLACE(REPLACE(m.file_path, :root_uploads, ''), '\\\\', '/') as file_path, m.file_type, m.file_size_kb, m.uploaded_at, m.status, m.downloads, m.approved_by, m.approved_at, m.rejected_by, m.rejected_at, m.rejection_reason,
                           ms.step_order, ms.status as step_status, ms.assigned_to_employee_id,
                           CONCAT(s.first_name, ' ', s.last_name) as creator_name, s.department
                    FROM materials m
                    JOIN materials_steps ms ON m.id = ms.material_id
                    LEFT JOIN students s ON m.student_id = s.id
                    WHERE " . $whereClause . "
                    ORDER BY m.uploaded_at DESC
                ");
                $params[':root_uploads'] = ROOT_PATH . 'uploads/';
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->execute();
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Add recent comments to each material
                foreach ($materials as &$material) {
                    $commentStmt = $conn->prepare("
                        SELECT n.note, 
                               CASE 
                                   WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                                   WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                                   WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                               END as author_name
                        FROM materials_notes n
                        LEFT JOIN employees e ON n.author_role = 'employee' AND n.author_id = e.id
                        LEFT JOIN students s ON n.author_role = 'student' AND n.author_id = s.id
                        LEFT JOIN administrators a ON n.author_role = 'admin' AND n.author_id = a.id
                        WHERE n.material_id = ?
                        ORDER BY n.created_at DESC
                        LIMIT 1
                    ");
                    $commentStmt->execute([$material['id']]);
                    $recentComment = $commentStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($recentComment) {
                        $material['recent_comment'] = [
                            'author' => $recentComment['author_name'] ?? 'Unknown',
                            'preview' => substr($recentComment['note'], 0, 50) . (strlen($recentComment['note']) > 50 ? '...' : '')
                        ];
                    }
                }

                echo json_encode(['success' => true, 'materials' => $materials]);
            } catch (Exception $e) {
                error_log('Error in materials approval query: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
            }
            exit();
        }

        // Check if requesting approved materials for display (for PPFO)
        $forDisplay = isset($_GET['for_display']) && $_GET['for_display'] == '1';
        if ($forDisplay) {
            try {
                // Validate session variables
                if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !isset($_SESSION['position'])) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'Session expired or invalid']);
                    exit();
                }

                // Only allow PPFO
                $userPosition = $_SESSION['position'] ?? '';
                if ($userPosition !== 'Physical Plant and Facilities Office (PPFO)') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                // Auto-delete approved materials older than 30 days
                $expiredStmt = $conn->prepare("SELECT id, file_path FROM materials WHERE status = 'approved' AND uploaded_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                $expiredStmt->execute();
                $expiredMaterials = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($expiredMaterials)) {
                    $conn->beginTransaction();
                    try {
                        foreach ($expiredMaterials as $expired) {
                            $materialId = $expired['id'];
                            $deleteNotes = $conn->prepare("DELETE FROM materials_notes WHERE material_id = ?");
                            $deleteNotes->execute([$materialId]);

                            $deleteSteps = $conn->prepare("DELETE FROM materials_steps WHERE material_id = ?");
                            $deleteSteps->execute([$materialId]);

                            $deleteMaterial = $conn->prepare("DELETE FROM materials WHERE id = ?");
                            $deleteMaterial->execute([$materialId]);

                            $filePath = materialAbsolutePath($expired['file_path'] ?? '');
                            if (!empty($filePath) && file_exists($filePath)) {
                                @unlink($filePath);
                            }
                        }
                        $conn->commit();
                    } catch (Exception $cleanupError) {
                        $conn->rollBack();
                        error_log('Auto-delete cleanup error: ' . $cleanupError->getMessage());
                    }
                }

                // Get approved materials
                $stmt = $conn->prepare("
                    SELECT m.id, m.title, REPLACE(REPLACE(m.file_path, :root_uploads, ''), '\\\\', '/') as file_path, m.file_type, m.status
                    FROM materials m
                    WHERE m.status = 'approved'
                    ORDER BY m.uploaded_at DESC
                ");
                $stmt->execute([':root_uploads' => ROOT_PATH . 'uploads/']);
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'materials' => $materials]);
            } catch (Exception $e) {
                error_log('Error in materials display query: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Internal server error']);
            }
            exit();
        }

        // Only admins can view all materials
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
        // Log everything to debug
        error_log("=== MATERIALS.PHP POST ===");
        error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
        error_log("POST data: " . print_r($_POST, true));
        error_log("FILES data: " . print_r($_FILES, true));

        $rawInput = file_get_contents('php://input');
        error_log("Raw input length: " . strlen($rawInput));
        if (!empty($rawInput)) {
            error_log("Raw input preview: " . substr($rawInput, 0, 200));
        }

        // Check if this is a JSON request (comments) or multipart/form-data (uploads)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJsonRequest = strpos($contentType, 'application/json') !== false;

        if ($isJsonRequest) {
            // Handle JSON requests for comments
            $input = json_decode($rawInput, true);
            if ($input && isset($input['action'])) {
                $action = $input['action'];
                error_log("JSON action: " . $action);
                switch ($action) {
                    case 'add_comment':
                        addMaterialComment($input);
                        break;
                    default:
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Invalid action']);
                        break;
                }
                break;
            }
        }

        // Handle file upload (multipart/form-data)
        $action = $_POST['action'] ?? null;
        error_log("FormData action: " . ($action ?? 'not set'));

        if ($action === 'upload') {
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
            $relativePath = 'uploads/' . $uniqueName;
            $filePath = ROOT_PATH . $relativePath;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to save file']);
                exit();
            }

            // Insert with new ID and description
            $stmt = $conn->prepare("INSERT INTO materials (id, student_id, title, description, file_path, file_type, status, file_size_kb, downloads) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 0)");
            $stmt->execute([$newId, $studentId, $title, $description, $relativePath, $file['type'], $fileSizeKb]);

            // After inserting material, create workflow steps
            createMaterialWorkflow($conn, $newId, $studentId);

            echo json_encode(['success' => true, 'id' => $newId]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        break;

    case 'PUT':
        // Handle approve/reject for workflow
        $id = $_GET['id'] ?? null;

        // Get the raw input
        $rawInput = file_get_contents('php://input');

        // Parse the multipart data properly
        $action = null;
        $note = null;

        // Try to parse from $_POST first (if PHP populated it)
        if (!empty($_POST)) {
            $action = $_POST['action'] ?? null;
            $note = $_POST['note'] ?? null;
        } else {
            // Parse from raw input
            // Check if it's multipart/form-data
            if (strpos($rawInput, '------') !== false) {
                // Parse multipart data
                preg_match_all('/name="([^"]+)"\s*\r?\n\r?\n([^-\r\n]+)/', $rawInput, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    if ($match[1] === 'action') {
                        $action = trim($match[2]);
                    } elseif ($match[1] === 'note') {
                        $note = trim($match[2]);
                    }
                }
            } else {
                // Try to parse as query string
                parse_str($rawInput, $parsed);
                $action = $parsed['action'] ?? null;
                $note = $parsed['note'] ?? null;
            }
        }

        if (!$id || !in_array($action, ['approve', 'reject'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request. ID: ' . $id . ', Action: ' . $action]);
            exit();
        }

        // Check if user can approve this material
        $userId = $_SESSION['user_id'];
        $userPosition = $_SESSION['position'] ?? '';
        $stepOrder = getStepOrderForPosition($userPosition);

        error_log("PUT Request - User: $userId, Position: $userPosition, StepOrder: $stepOrder, Material: $id, Action: $action");

        $stmt = $conn->prepare("
            SELECT ms.* FROM materials_steps ms
            WHERE ms.material_id = ? AND ms.assigned_to_employee_id = ? AND ms.status = 'pending' AND ms.step_order = ?
        ");
        $stmt->execute([$id, $userId, $stepOrder]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$step) {
            error_log("No pending step found for material $id, user $userId, step order $stepOrder");
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized to approve this material']);
            exit();
        }

        error_log("Found step: " . print_r($step, true));

        // Start transaction
        $conn->beginTransaction();

        try {
            // Update step status
            $newStatus = $action === 'approve' ? 'completed' : 'rejected';
            $stmt = $conn->prepare("UPDATE materials_steps SET status = ?, completed_at = NOW(), note = ? WHERE id = ?");
            $stmt->execute([$newStatus, $note, $step['id']]);

            error_log("Step updated to $newStatus");

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
                        error_log("Created next step: order $nextStepOrder, assignee $nextAssignee");
                    }
                } else {
                    // Final approval - update material status
                    $stmt = $conn->prepare("UPDATE materials SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$userId, $id]);
                    error_log("Material fully approved");
                }
            } else {
                // Rejected - update material status
                $stmt = $conn->prepare("UPDATE materials SET status = 'rejected', rejected_by = ?, rejected_at = NOW() WHERE id = ?");
                $stmt->execute([$userId, $id]);
                error_log("Material rejected");
            }

            $conn->commit();
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error in PUT: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        $userRole = $_SESSION['user_role'] ?? '';
        $userPosition = $_SESSION['position'] ?? '';
        $isAdmin = ($userRole === 'admin');
        $isPpfo = ($userRole === 'employee' && $userPosition === 'Physical Plant and Facilities Office (PPFO)');

        // Only admin or PPFO can delete materials
        if (!$isAdmin && !$isPpfo) {
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

        $materialStmt = $conn->prepare("SELECT id, status, file_path FROM materials WHERE id = ?");
        $materialStmt->execute([$id]);
        $material = $materialStmt->fetch(PDO::FETCH_ASSOC);

        if (!$material) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Material not found']);
            exit();
        }

        // PPFO can only delete approved materials
        if ($isPpfo && ($material['status'] ?? '') !== 'approved') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'PPFO can only delete approved materials']);
            exit();
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("DELETE FROM materials_notes WHERE material_id = ?");
            $stmt->execute([$id]);

            $stmt = $conn->prepare("DELETE FROM materials_steps WHERE material_id = ?");
            $stmt->execute([$id]);

            $stmt = $conn->prepare("DELETE FROM materials WHERE id = ?");
            $stmt->execute([$id]);

            $filePath = materialAbsolutePath($material['file_path'] ?? '');
            if (!empty($filePath) && file_exists($filePath)) {
                @unlink($filePath);
            }

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete material']);
            exit();
        }

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

function userCanAccessMaterialForComments($materialId)
{
    global $conn, $_SESSION;

    if (!$materialId || empty($_SESSION['user_id'])) {
        return false;
    }

    // Admin can access everything
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        return true;
    }

    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? '';
    $userPosition = $_SESSION['position'] ?? '';
    
    // Check if user is the student who owns the material
    $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
    $stmt->execute([$materialId]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($material && $material['student_id'] == $userId) {
        error_log("User $userId is the owner of material $materialId");
        return true;
    }
    
    // Check if user is an approver assigned to this material
    $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
    if ($userRole === 'employee' && in_array($userPosition, $allowedPositions)) {
        // Check if they are assigned to ANY step of this material
        $stmt = $conn->prepare("
            SELECT 1 FROM materials_steps 
            WHERE material_id = ? AND assigned_to_employee_id = ? 
        ");
        $stmt->execute([$materialId, $userId]);
        $isAssigned = (bool) $stmt->fetchColumn();
        
        if ($isAssigned) {
            error_log("User $userId is assigned as approver to material $materialId");
            return true;
        }
    }
    
    error_log("User $userId does not have access to material $materialId");
    return false;
}

function getMaterialComments($materialId)
{
    global $conn;

    if (!$materialId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid material id']);
        return;
    }

    if (!userCanAccessMaterialForComments($materialId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }

    try {
        $stmt = $conn->prepare("SELECT n.id, n.material_id, n.parent_note_id, n.note, n.created_at, n.author_id, n.author_role,
                                     CASE
                                         WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                                         WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                                         WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                                         ELSE 'Unknown'
                                     END AS author_name,
                                     CASE
                                         WHEN n.author_role = 'employee' THEN COALESCE(e.position, '')
                                         WHEN n.author_role = 'student' THEN COALESCE(s.position, '')
                                         WHEN n.author_role = 'admin' THEN COALESCE(a.position, '')
                                         ELSE ''
                                     END AS author_position
                              FROM materials_notes n
                              LEFT JOIN employees e ON n.author_role = 'employee' AND n.author_id = e.id
                              LEFT JOIN students s ON n.author_role = 'student' AND n.author_id = s.id
                              LEFT JOIN administrators a ON n.author_role = 'admin' AND n.author_id = a.id
                              WHERE n.material_id = ?
                              ORDER BY n.created_at ASC, n.id ASC");
        $stmt->execute([$materialId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $comments = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'material_id' => $row['material_id'],
                'parent_id' => $row['parent_note_id'] !== null ? (int) $row['parent_note_id'] : null,
                'comment' => $row['note'],
                'created_at' => $row['created_at'],
                'author_id' => $row['author_id'],
                'author_role' => $row['author_role'],
                'author_name' => trim($row['author_name'] ?: 'Unknown'),
                'author_position' => $row['author_position'] ?: ''
            ];
        }, $rows);

        echo json_encode(['success' => true, 'comments' => $comments]);
    } catch (PDOException $e) {
        // If table doesn't exist, return empty comments
        if ($e->getCode() == '42S02') { // Table not found
            echo json_encode(['success' => true, 'comments' => []]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
    }
}

function addMaterialComment($input)
{
    global $conn, $_SESSION;

    error_log("=== addMaterialComment called ===");
    error_log("Input: " . print_r($input, true));
    error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'not set'));
    error_log("Session user_role: " . ($_SESSION['user_role'] ?? 'not set'));

    $rawMaterialId = isset($input['material_id']) ? trim((string)$input['material_id']) : '';
    $comment = isset($input['comment']) ? trim($input['comment']) : '';
    $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;

    // Normalize material id to MAT format (e.g. MAT-005, 5 -> MAT005)
    $materialId = '';
    if (preg_match('/^MAT-?(\\d+)$/i', $rawMaterialId, $m)) {
        $materialId = 'MAT' . str_pad($m[1], 3, '0', STR_PAD_LEFT);
    } elseif (preg_match('/^\\d+$/', $rawMaterialId)) {
        $materialId = 'MAT' . str_pad($rawMaterialId, 3, '0', STR_PAD_LEFT);
    } elseif (preg_match('/^MAT\\d+$/i', $rawMaterialId)) {
        $materialId = strtoupper($rawMaterialId);
    }

    error_log("Parsed values - rawMaterialId: '$rawMaterialId', materialId: '$materialId', comment: '$comment', parentId: " . ($parentId ?? 'null'));

    if ($materialId === '' || $comment === '') {
        error_log("Validation failed: materialId='$materialId', comment='$comment'");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Material and comment are required']);
        return;
    }

    if (!userCanAccessMaterialForComments($materialId)) {
        error_log("Access denied for user {$_SESSION['user_id']} to material $materialId");
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }

    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'materials_notes'");
        if ($tableCheck->rowCount() == 0) {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS materials_notes (
                    id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    material_id VARCHAR(10) NOT NULL,
                    author_id VARCHAR(20) NOT NULL,
                    author_role ENUM('admin','employee','student') NOT NULL DEFAULT 'student',
                    note TEXT NOT NULL,
                    parent_note_id BIGINT(20) NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_material_note (material_id),
                    KEY idx_parent_note (parent_note_id),
                    KEY idx_author (author_id, author_role)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if ($parentId !== null) {
            $parentStmt = $conn->prepare("SELECT id FROM materials_notes WHERE id = ? AND material_id = ?");
            $parentStmt->execute([$parentId, $materialId]);
            if (!$parentStmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Reply target not found']);
                return;
            }
        }

        $insertStmt = $conn->prepare("
            INSERT INTO materials_notes (material_id, author_id, author_role, note, parent_note_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $materialId,
            $_SESSION['user_id'],
            $_SESSION['user_role'],
            $comment,
            $parentId
        ]);

        $newId = (int)$conn->lastInsertId();
        error_log("Comment added successfully with ID: $newId");

        // Generate notification for the material creator (if not self-comment)
        $matStmt = $conn->prepare("SELECT student_id, title FROM materials WHERE id = ?");
        $matStmt->execute([$materialId]);
        $mat = $matStmt->fetch(PDO::FETCH_ASSOC);
        if ($mat) {
            $creatorId = $mat['student_id'];
            $creatorRole = 'student';
            $title = $mat['title'];
            $referenceType = $parentId ? 'material_reply' : 'material_comment';
            $message = $parentId ? "New reply on material '$title'" : "New comment on material '$title'";
            // Check if notifications table exists first
            $notifTableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
            if ($notifTableCheck->rowCount() > 0) {
                $notifStmt = $conn->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_type, reference_id, is_read, created_at) VALUES (?, ?, 'material', ?, ?, ?, ?, ?, 0, NOW())");
                // Notify creator if not self
                if ($_SESSION['user_id'] != $creatorId || $_SESSION['user_role'] != $creatorRole) {
                    $notifStmt->execute([$creatorId, $creatorRole, $title, $message, $materialId, $referenceType, $newId]);
                }
                // Also notify current step assignee if exists and not self
                $stepStmt = $conn->prepare("SELECT assigned_to_employee_id FROM materials_steps WHERE material_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
                $stepStmt->execute([$materialId]);
                $step = $stepStmt->fetch(PDO::FETCH_ASSOC);
                if ($step && $step['assigned_to_employee_id']) {
                    $assigneeId = $step['assigned_to_employee_id'];
                    $assigneeRole = 'employee';
                    if ($_SESSION['user_id'] != $assigneeId || $_SESSION['user_role'] != $assigneeRole) {
                        $notifStmt->execute([$assigneeId, $assigneeRole, $title, $message, $materialId, $referenceType, $newId]);
                    }
                }
            } else {
                error_log("Notifications table does not exist - skipping notification");
            }
        }

        echo json_encode(['success' => true, 'comment_id' => $newId]);
    } catch (PDOException $e) {
        error_log("Error adding comment: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to add comment: ' . $e->getMessage()]);
    }
}

// Helper function to parse multipart data from raw input
function parseMultipartData($rawInput) {
    $data = [];
    
    // Find the boundary
    if (preg_match('/^------([a-zA-Z0-9]+)--/', $rawInput, $matches)) {
        $boundary = $matches[1];
        $parts = explode("--{$boundary}", $rawInput);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') continue;
            
            // Extract name and value
            if (preg_match('/Content-Disposition: form-data; name="([^"]+)"\s*\r?\n\r?\n(.+)/s', $part, $matches)) {
                $name = $matches[1];
                $value = trim($matches[2]);
                $data[$name] = $value;
            }
        }
    }
    
    return $data;
}

?>