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

requireAuth();

$db = new Database();
$conn = $db->getConnection();

function materialAbsolutePath($storedPath) {
    $storedPath = trim((string) $storedPath);
    if ($storedPath === '') return '';
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $storedPath) || str_starts_with($storedPath, '/') || str_starts_with($storedPath, '\\\\')) return $storedPath;
    if (str_starts_with($storedPath, '../uploads/')) return ROOT_PATH . 'uploads/' . basename($storedPath);
    if (str_starts_with($storedPath, 'uploads/')) return ROOT_PATH . ltrim($storedPath, '/');
    return ROOT_PATH . 'uploads/' . basename($storedPath);
}

// --- NEW HELPER FUNCTION FOR INSTANT NOTIFICATIONS ---
// --- SMART NOTIFICATION PUSHER (Database + Targeted Email) ---
function pushNotification($db, $recipId, $recipRole, $type, $title, $msg, $docId = null, $refType = null) {
    if (!$recipId || !$recipRole) return;

    // 1. ALWAYS push to the Database (For the UI Notification Bell)
    try {
        $stmt = $db->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, title, message, related_document_id, reference_type, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())");
        $stmt->execute([$recipId, $recipRole, $type, $title, $msg, $docId, $refType]);
    } catch (Exception $e) {
        error_log("DB Notification Error: " . $e->getMessage());
    }

    // 2. THE GATEKEEPER: Only allow crucial alerts to become Emails
    $crucialEmailTriggers = [
        'employee_document_pending',  // Document needs employee signature
        'document_pending_signature', // Document needs student signature
        'employee_material_pending',  // Pubmat needs approval
        'doc_status_approved',        // Document fully approved / SAF Ready
        'material_status_approved',   // Pubmat fully approved
        'doc_status_rejected',        // Document rejected
        'material_status_rejected'    // Pubmat rejected
    ];

    // If the event isn't in the crucial list (e.g., it's just a comment), stop here.
    if (!in_array($refType, $crucialEmailTriggers)) {
        return; 
    }

    // 3. SEND THE EMAIL
    try {
        // Look up the user's actual email address
        $table = ($recipRole === 'student') ? 'students' : 'employees';
        $userStmt = $db->prepare("SELECT email, first_name, last_name FROM $table WHERE id = ? LIMIT 1");
        $userStmt->execute([$recipId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['email'])) {
            require_once ROOT_PATH . 'includes/Mailer.php';
            $mailer = new Mailer();

            // Map the refType to a color status for the email template
            $emailStatus = 'pending';
            if (strpos($refType, 'approved') !== false) $emailStatus = 'approved';
            if (strpos($refType, 'rejected') !== false) $emailStatus = 'rejected';

            // Send the 1-to-1 targeted email
            $mailer->send(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                "Sign-um Update: " . $title, 
                'document_status', // The template file we created
                [
                    'recipientName' => $user['first_name'],
                    'documentTitle' => $title, 
                    'status'        => $emailStatus, 
                    'message'       => $msg
                ]
            );
        }
    } catch (Exception $e) {
        error_log("Email Trigger Failed: " . $e->getMessage());
    }
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['download']) && $_GET['download'] == '1') {
            $id = $_GET['id'] ?? null;
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Material ID required']); exit(); }

            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $userPosition = $_SESSION['position'] ?? '';
            $isPpfo = ($userRole === 'employee' && $userPosition === 'Physical Plant and Facilities Office (PPFO)');
            
            $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
            $isApprover = ($userRole === 'employee' && in_array($userPosition, $allowedPositions));
            
            if ($isApprover) {
                $stepOrder = getStepOrderForPosition($userPosition);
                $checkStmt = $conn->prepare("SELECT 1 FROM materials_steps WHERE material_id = ? AND assigned_to_employee_id = ? AND step_order = ?");
                $checkStmt->execute([$id, $userId, $stepOrder]);
                $hasAccess = $checkStmt->fetch();
            } else {
                $hasAccess = false;
            }
            
            if ($userRole === 'admin') {
                $hasAccess = true;
            } elseif (!$hasAccess) {
                $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
                $stmt->execute([$id]);
                $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['student_id'] ?? null;
                $hasAccess = ($ownerId == $userId);
            }

            if (!$hasAccess && $isPpfo) {
                $stmt = $conn->prepare("SELECT 1 FROM materials WHERE id = ? AND status = 'approved'");
                $stmt->execute([$id]);
                $hasAccess = (bool) $stmt->fetch();
            }
            
            if (!$hasAccess) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }

            $stmt = $conn->prepare("SELECT * FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$material) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Material not found']); exit(); }

            $filePath = materialAbsolutePath($material['file_path'] ?? '');
            if (!$filePath || !file_exists($filePath)) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'File not found']); exit(); }

            $stmt = $conn->prepare("UPDATE materials SET downloads = downloads + 1 WHERE id = ?");
            $stmt->execute([$id]);

            $fileName = basename($filePath);
            header('Content-Type: ' . mime_content_type($filePath));
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'serve_image' && isset($_GET['id'])) {
            $id = $_GET['id'];
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $userPosition = $_SESSION['position'] ?? '';
            $isPpfo = ($userRole === 'employee' && $userPosition === 'Physical Plant and Facilities Office (PPFO)');
            
            $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
            $isApprover = ($userRole === 'employee' && in_array($userPosition, $allowedPositions));
            
            if ($isApprover) {
                $stepOrder = getStepOrderForPosition($userPosition);
                $checkStmt = $conn->prepare("SELECT 1 FROM materials_steps WHERE material_id = ? AND assigned_to_employee_id = ? AND step_order = ?");
                $checkStmt->execute([$id, $userId, $stepOrder]);
                $hasAccess = $checkStmt->fetch();
            } else {
                $hasAccess = false;
            }
            
            if ($userRole === 'admin') {
                $hasAccess = true;
            } elseif (!$hasAccess) {
                $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
                $stmt->execute([$id]);
                $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['student_id'] ?? null;
                $hasAccess = ($ownerId == $userId);
            }

            if (!$hasAccess && $isPpfo) {
                $stmt = $conn->prepare("SELECT 1 FROM materials WHERE id = ? AND status = 'approved'");
                $stmt->execute([$id]);
                $hasAccess = (bool) $stmt->fetch();
            }
            
            if (!$hasAccess) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
            
            $stmt = $conn->prepare("SELECT file_path, file_type FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $absolutePath = materialAbsolutePath($material['file_path'] ?? '');
            if (!$material || !$absolutePath || !file_exists($absolutePath)) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'File not found']); exit(); }
            
            header('Content-Type: ' . $material['file_type']);
            header('Content-Length: ' . filesize($absolutePath));
            header('Cache-Control: public, max-age=86400');
            readfile($absolutePath);
            exit();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'get_comments' && isset($_GET['id'])) {
            getMaterialComments($_GET['id']);
            exit();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'get_material_details' && isset($_GET['id'])) {
            $id = $_GET['id'];
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $userPosition = $_SESSION['position'] ?? '';
            
            $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
            $isApprover = ($userRole === 'employee' && in_array($userPosition, $allowedPositions));
            
            if ($isApprover) {
                $stepOrder = getStepOrderForPosition($userPosition);
                $checkStmt = $conn->prepare("SELECT 1 FROM materials_steps WHERE material_id = ? AND assigned_to_employee_id = ? AND step_order = ?");
                $checkStmt->execute([$id, $userId, $stepOrder]);
                $hasAccess = $checkStmt->fetch();
                
                if (!$hasAccess) {
                    $checkStmt = $conn->prepare("SELECT 1 FROM materials_steps WHERE material_id = ? AND assigned_to_employee_id = ?");
                    $checkStmt->execute([$id, $userId]);
                    $hasAccess = $checkStmt->fetch();
                }
            } else {
                $hasAccess = false;
            }
            
            if ($userRole === 'admin') {
                $hasAccess = true;
            } elseif (!$hasAccess) {
                $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
                $stmt->execute([$id]);
                $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['student_id'] ?? null;
                $hasAccess = ($ownerId == $userId);
            }
            
            if (!$hasAccess) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
            
            $stmt = $conn->prepare("
                SELECT m.*, CONCAT(s.first_name, ' ', s.last_name) as creator_name, s.department
                FROM materials m LEFT JOIN students s ON m.student_id = s.id WHERE m.id = ?
            ");
            $stmt->execute([$id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$material) { echo json_encode(['success' => false, 'message' => 'Material not found']); exit(); }

            $material['file_path'] = basename($material['file_path']);
            
            $stepsStmt = $conn->prepare("
                SELECT ms.*, e.first_name as assignee_first, e.last_name as assignee_last, e.position as assignee_position,
                       CASE WHEN ms.step_order = 1 THEN 'College Student Council Adviser Approval' WHEN ms.step_order = 2 THEN 'College Dean Approval' WHEN ms.step_order = 3 THEN 'OIC-OSA Approval' END as step_name
                FROM materials_steps ms LEFT JOIN employees e ON ms.assigned_to_employee_id = e.id WHERE ms.material_id = ? ORDER BY ms.step_order ASC
            ");
            $stepsStmt->execute([$id]);
            $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $workflow_history = [];
            $current_location = 'Pending';
            
            foreach ($steps as $step) {
                $action = 'Pending';
                if ($step['status'] === 'completed') $action = 'Approved';
                elseif ($step['status'] === 'rejected') $action = 'Rejected';
                
                if ($step['status'] === 'pending') $current_location = $step['step_name'];
                elseif ($step['status'] === 'completed' && $step['step_order'] == 3) $current_location = 'Completed';
                elseif ($step['status'] === 'rejected') $current_location = $step['step_name'] . ' (Rejected)';
                
                $workflow_history[] = [
                    'created_at' => $step['completed_at'] ?: $material['uploaded_at'],
                    'action' => $action,
                    'office_name' => $step['step_name'],
                    'status' => $step['status'],
                    'note' => $step['note']
                ];
            }
            
            $formattedComments = [];
            try {
                $tableCheck = $conn->query("SHOW TABLES LIKE 'materials_notes'");
                if ($tableCheck->rowCount() > 0) {
                    $commentsStmt = $conn->prepare("
                        SELECT n.*,
                               CASE WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name) WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name) WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name) END as author_name,
                               CASE WHEN n.author_role = 'employee' THEN e.position WHEN n.author_role = 'student' THEN s.position WHEN n.author_role = 'admin' THEN a.position END as author_position
                        FROM materials_notes n
                        LEFT JOIN students s ON n.author_id = s.id AND n.author_role = 'student'
                        LEFT JOIN employees e ON n.author_id = e.id AND n.author_role = 'employee'
                        LEFT JOIN administrators a ON n.author_id = a.id AND n.author_role = 'admin'
                        WHERE n.material_id = ? ORDER BY n.created_at ASC
                    ");
                    $commentsStmt->execute([$id]);
                    $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $formattedComments = array_map(function($comment) {
                        return [
                            'id' => (int) $comment['id'], 'parent_id' => $comment['parent_note_id'], 'comment' => $comment['note'], 'created_at' => $comment['created_at'], 'author_name' => $comment['author_name'] ?: 'Unknown', 'author_role' => $comment['author_role'], 'author_position' => $comment['author_position'] ?: ''
                        ];
                    }, $comments);
                }
            } catch (PDOException $e) {}
            
            $material['workflow_history'] = $workflow_history;
            $material['comments'] = $formattedComments;
            $material['current_location'] = $current_location;
            
            echo json_encode(['success' => true, 'material' => $material]);
            exit();
        }

        $forApproval = isset($_GET['for_approval']) && $_GET['for_approval'] == '1';
        if ($forApproval) {
            try {
                if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Session invalid']); exit(); }

                $userRole = $_SESSION['user_role'];
                $userPosition = $_SESSION['position'] ?? '';
                $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
                
                if ($userRole !== 'employee' || !in_array($userPosition, $allowedPositions)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }

                $stepOrder = getStepOrderForPosition($userPosition);
                $department = $_SESSION['department'] ?? null; 

                $whereClause = "ms.assigned_to_employee_id = :user_id AND ms.status = 'pending' AND ms.step_order = :step_order";
                $params = [':user_id' => $_SESSION['user_id'], ':step_order' => $stepOrder];
                
                if ($userPosition === 'College Student Council Adviser' && $department) {
                    $whereClause .= " AND s.department = :department";
                    $params[':department'] = $department;
                }

                $stmt = $conn->prepare("
                    SELECT m.id, m.student_id, m.title, m.description, REPLACE(REPLACE(m.file_path, :root_uploads, ''), '\\\\', '/') as file_path, m.file_type, m.file_size_kb, m.uploaded_at, m.status, m.downloads, m.approved_by, m.approved_at, m.rejected_by, m.rejected_at, m.rejection_reason,
                           ms.step_order, ms.status as step_status, ms.assigned_to_employee_id, CONCAT(s.first_name, ' ', s.last_name) as creator_name, s.department
                    FROM materials m JOIN materials_steps ms ON m.id = ms.material_id LEFT JOIN students s ON m.student_id = s.id
                    WHERE " . $whereClause . " ORDER BY m.uploaded_at DESC
                ");
                $params[':root_uploads'] = ROOT_PATH . 'uploads/';
                foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
                $stmt->execute();
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($materials as &$material) {
                    $commentStmt = $conn->prepare("
                        SELECT n.note, CASE WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name) WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name) ELSE 'Admin' END as author_name
                        FROM materials_notes n LEFT JOIN employees e ON n.author_role = 'employee' AND n.author_id = e.id LEFT JOIN students s ON n.author_role = 'student' AND n.author_id = s.id
                        WHERE n.material_id = ? ORDER BY n.created_at DESC LIMIT 1
                    ");
                    $commentStmt->execute([$material['id']]);
                    $recentComment = $commentStmt->fetch(PDO::FETCH_ASSOC);
                    if ($recentComment) {
                        $material['recent_comment'] = ['author' => $recentComment['author_name'] ?? 'Unknown', 'preview' => substr($recentComment['note'], 0, 50) . (strlen($recentComment['note']) > 50 ? '...' : '')];
                    }
                }

                echo json_encode(['success' => true, 'materials' => $materials]);
            } catch (Exception $e) {
                http_response_code(500); echo json_encode(['success' => false, 'message' => 'Internal server error']);
            }
            exit();
        }

        $forDisplay = isset($_GET['for_display']) && $_GET['for_display'] == '1';
        if ($forDisplay) {
            $userPosition = $_SESSION['position'] ?? '';
            if ($userPosition !== 'Physical Plant and Facilities Office (PPFO)') { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }

            $stmt = $conn->prepare("SELECT m.id, m.title, REPLACE(REPLACE(m.file_path, :root_uploads, ''), '\\\\', '/') as file_path, m.file_type, m.status FROM materials m WHERE m.status = 'approved' ORDER BY m.uploaded_at DESC");
            $stmt->execute([':root_uploads' => ROOT_PATH . 'uploads/']);
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'materials' => $materials]);
            exit();
        }
        break;

    case 'POST':
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJsonRequest = strpos($contentType, 'application/json') !== false;

        if ($isJsonRequest) {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input && isset($input['action']) && $input['action'] === 'add_comment') {
                addMaterialComment($input);
            }
            break;
        }

        $action = $_POST['action'] ?? null;
        if ($action === 'upload') {
            if (!isset($_FILES['file'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'No file uploaded']); exit(); }

            $file = $_FILES['file'];
            $studentId = $_POST['student_id'] ?? null;
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            
            if (!$studentId || !$title) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing fields']); exit(); }

            $stmt = $conn->prepare("SELECT GREATEST(COALESCE(MAX(CASE WHEN id REGEXP '^MAT[0-9]+$' THEN CAST(SUBSTRING(id, 4) AS UNSIGNED) ELSE 0 END), 0), 0) as max_id FROM materials");
            $stmt->execute();
            $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
            $newId = 'MAT' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);

            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $uniqueName = uniqid('pubmat_', true) . '.' . $fileExtension;
            $relativePath = 'uploads/' . $uniqueName;
            $filePath = ROOT_PATH . $relativePath;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $stmt = $conn->prepare("INSERT INTO materials (id, student_id, title, description, file_path, file_type, status, file_size_kb, downloads) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 0)");
                $stmt->execute([$newId, $studentId, $title, $description, $relativePath, $file['type'], round($file['size'] / 1024)]);

                createMaterialWorkflow($conn, $newId, $studentId);
                echo json_encode(['success' => true, 'id' => $newId]);
            }
        }
        break;

    case 'PUT':
        $id = $_GET['id'] ?? null;
        $rawInput = file_get_contents('php://input');
        $action = null; $note = null;

        if (strpos($rawInput, '------') !== false) {
            preg_match_all('/name="([^"]+)"\s*\r?\n\r?\n([^-\r\n]+)/', $rawInput, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if ($match[1] === 'action') $action = trim($match[2]);
                elseif ($match[1] === 'note') $note = trim($match[2]);
            }
        } else {
            parse_str($rawInput, $parsed);
            $action = $parsed['action'] ?? null;
            $note = $parsed['note'] ?? null;
        }

        if (!$id || !in_array($action, ['approve', 'reject'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }

        $userId = $_SESSION['user_id'];
        $userPosition = $_SESSION['position'] ?? '';
        $stepOrder = getStepOrderForPosition($userPosition);

        $stmt = $conn->prepare("SELECT ms.* FROM materials_steps ms WHERE ms.material_id = ? AND ms.assigned_to_employee_id = ? AND ms.status = 'pending' AND ms.step_order = ?");
        $stmt->execute([$id, $userId, $stepOrder]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$step) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Not authorized']); exit(); }

        // Fetch material details for notification
        $matStmt = $conn->prepare("SELECT student_id, title FROM materials WHERE id = ?");
        $matStmt->execute([$id]);
        $matDetails = $matStmt->fetch(PDO::FETCH_ASSOC);
        $studentId = $matDetails['student_id'] ?? null;
        $matTitle = $matDetails['title'] ?? 'Pubmat';

        $conn->beginTransaction();
        try {
            $newStatus = $action === 'approve' ? 'completed' : 'rejected';
            $stmt = $conn->prepare("UPDATE materials_steps SET status = ?, completed_at = NOW(), note = ? WHERE id = ?");
            $stmt->execute([$newStatus, $note, $step['id']]);

            if ($action === 'approve') {
                if ($stepOrder < 3) {
                    $nextStepOrder = $stepOrder + 1;
                    $nextAssignee = getAssigneeForStep($conn, $id, $nextStepOrder);
                    if ($nextAssignee) {
                        $stmt = $conn->prepare("INSERT INTO materials_steps (material_id, step_order, assigned_to_employee_id, status) VALUES (?, ?, ?, 'pending')");
                        $stmt->execute([$id, $nextStepOrder, $nextAssignee]);
                        
                        // NOTIFY NEXT APPROVER
                        pushNotification($conn, $nextAssignee, 'employee', 'document', 'Pubmat Approval Required', "The publication material '$matTitle' has been forwarded for your approval.", $id, 'employee_material_pending');
                    }
                } else {
                    $stmt = $conn->prepare("UPDATE materials SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$userId, $id]);
                    
                    // NOTIFY STUDENT OF FULL APPROVAL
                    pushNotification($conn, $studentId, 'student', 'document', 'Pubmat Fully Approved!', "Your publication material '$matTitle' has been fully approved and is ready for display.", $id, 'material_status_approved');
                }
            } else {
                $stmt = $conn->prepare("UPDATE materials SET status = 'rejected', rejected_by = ?, rejected_at = NOW() WHERE id = ?");
                $stmt->execute([$userId, $id]);
                
                // NOTIFY STUDENT OF REJECTION
                pushNotification($conn, $studentId, 'student', 'document', 'Pubmat Rejected', "Your publication material '$matTitle' was rejected by {$userPosition}.", $id, 'material_status_rejected');
            }

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollBack();
            http_response_code(500); echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        break;
}

// Helper functions
function getStepOrderForPosition($position) {
    $steps = ['College Student Council Adviser' => 1, 'College Dean' => 2, 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)' => 3];
    return $steps[$position] ?? null;
}

function createMaterialWorkflow($conn, $materialId, $studentId) {
    $stmt = $conn->prepare("SELECT department FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC)['department'] ?? '';

    $cscAdviser = getEmployeeByPositionAndDepartment($conn, 'College Student Council Adviser', $department);
    if ($cscAdviser) {
        $stmt = $conn->prepare("INSERT INTO materials_steps (material_id, step_order, assigned_to_employee_id, status) VALUES (?, 1, ?, 'pending')");
        $stmt->execute([$materialId, $cscAdviser]);
        
        // NOTIFY FIRST APPROVER (Adviser)
        $matStmt = $conn->prepare("SELECT title FROM materials WHERE id = ?");
        $matStmt->execute([$materialId]);
        $title = $matStmt->fetchColumn();
        pushNotification($conn, $cscAdviser, 'employee', 'document', 'New Pubmat Submitted', "A new publication material '$title' requires your approval.", $materialId, 'employee_material_pending');
    }
}

function getAssigneeForStep($conn, $materialId, $stepOrder) {
    $stmt = $conn->prepare("SELECT s.department FROM materials m JOIN students s ON m.student_id = s.id WHERE m.id = ?");
    $stmt->execute([$materialId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC)['department'] ?? '';

    if ($stepOrder == 2) return getEmployeeByPositionAndDepartment($conn, 'College Dean', $department);
    elseif ($stepOrder == 3) return getEmployeeByPosition($conn, 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)');
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

function userCanAccessMaterialForComments($materialId) {
    global $conn, $_SESSION;
    if (!$materialId || empty($_SESSION['user_id'])) return false;
    if (($_SESSION['user_role'] ?? '') === 'admin') return true;

    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? '';
    $userPosition = $_SESSION['position'] ?? '';
    
    $stmt = $conn->prepare("SELECT student_id FROM materials WHERE id = ?");
    $stmt->execute([$materialId]);
    if ($stmt->fetchColumn() == $userId) return true;
    
    $allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
    if ($userRole === 'employee' && in_array($userPosition, $allowedPositions)) {
        $stmt = $conn->prepare("SELECT 1 FROM materials_steps WHERE material_id = ? AND assigned_to_employee_id = ?");
        $stmt->execute([$materialId, $userId]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

function getMaterialComments($materialId) {
    global $conn;
    if (!userCanAccessMaterialForComments($materialId)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); return; }

    try {
        $stmt = $conn->prepare("SELECT n.*, CASE WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name) WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name) ELSE 'Admin' END AS author_name, CASE WHEN n.author_role = 'employee' THEN COALESCE(e.position, '') WHEN n.author_role = 'student' THEN COALESCE(s.position, '') ELSE '' END AS author_position FROM materials_notes n LEFT JOIN employees e ON n.author_role = 'employee' AND n.author_id = e.id LEFT JOIN students s ON n.author_role = 'student' AND n.author_id = s.id WHERE n.material_id = ? ORDER BY n.created_at ASC");
        $stmt->execute([$materialId]);
        
        $comments = array_map(function ($row) {
            return ['id' => (int) $row['id'], 'material_id' => $row['material_id'], 'parent_id' => $row['parent_note_id'] !== null ? (int) $row['parent_note_id'] : null, 'comment' => $row['note'], 'created_at' => $row['created_at'], 'author_id' => $row['author_id'], 'author_role' => $row['author_role'], 'author_name' => trim($row['author_name'] ?: 'Unknown'), 'author_position' => $row['author_position'] ?: ''];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'comments' => $comments]);
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'comments' => []]);
    }
}

function addMaterialComment($input) {
    global $conn, $_SESSION;
    $rawMaterialId = trim((string)($input['material_id'] ?? ''));
    $comment = trim($input['comment'] ?? '');
    $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;

    $materialId = '';
    if (preg_match('/^MAT-?(\\d+)$/i', $rawMaterialId, $m)) $materialId = 'MAT' . str_pad($m[1], 3, '0', STR_PAD_LEFT);
    elseif (preg_match('/^\\d+$/', $rawMaterialId)) $materialId = 'MAT' . str_pad($rawMaterialId, 3, '0', STR_PAD_LEFT);
    elseif (preg_match('/^MAT\\d+$/i', $rawMaterialId)) $materialId = strtoupper($rawMaterialId);

    if ($materialId === '' || $comment === '') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Material and comment are required']); return; }
    if (!userCanAccessMaterialForComments($materialId)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); return; }

    try {
        $insertStmt = $conn->prepare("INSERT INTO materials_notes (material_id, author_id, author_role, note, parent_note_id) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->execute([$materialId, $_SESSION['user_id'], $_SESSION['user_role'], $comment, $parentId]);
        $newId = (int)$conn->lastInsertId();

        $matStmt = $conn->prepare("SELECT student_id, title FROM materials WHERE id = ?");
        $matStmt->execute([$materialId]);
        $mat = $matStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($mat) {
            $creatorId = $mat['student_id'];
            $title = $mat['title'];
            $referenceType = $parentId ? 'document_reply' : 'document_comment';
            $message = $parentId ? "New reply on pubmat '$title'" : "New comment on pubmat '$title'";

            if ($_SESSION['user_id'] != $creatorId) {
                pushNotification($conn, $creatorId, 'student', 'document', 'New Comment', $message, $materialId, $referenceType);
            }
            
            $stepStmt = $conn->prepare("SELECT assigned_to_employee_id FROM materials_steps WHERE material_id = ? AND status = 'pending' LIMIT 1");
            $stepStmt->execute([$materialId]);
            $step = $stepStmt->fetch(PDO::FETCH_ASSOC);
            if ($step && $step['assigned_to_employee_id'] && $_SESSION['user_id'] != $step['assigned_to_employee_id']) {
                pushNotification($conn, $step['assigned_to_employee_id'], 'employee', 'document', 'New Comment', $message, $materialId, $referenceType);
            }
        }

        echo json_encode(['success' => true, 'comment_id' => $newId]);
    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}
?>