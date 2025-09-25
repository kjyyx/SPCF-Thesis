<?php
/**
 * Documents API - Document Workflow Management
 * ============================================
 *
 * Manages document approval workflows with the following features:
 * - Document creation and status tracking (GET/POST)
 * - Document approval/rejection workflow (PUT)
 * - Automatic timeout handling for stale documents
 * - Mock document generation for testing
 *
 * Documents go through multiple approval steps assigned to employees.
 * Supports PDF generation and signature collection.
 */

require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

// Document timeout configuration
if (!defined('DOC_TIMEOUT_DAYS')) {
    define('DOC_TIMEOUT_DAYS', 5); // Auto-timeout threshold in days
}
if (!defined('DOC_TIMEOUT_MODE')) {
    // 'reject' or 'delete' (soft-delete by setting documents.status = 'deleted')
    define('DOC_TIMEOUT_MODE', 'reject');
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get current user info
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost();
        break;
    case 'PUT':
        handlePut();
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

function handleGet()
{
    /**
     * GET /api/documents.php - Retrieve documents
     * ===========================================
     * Returns document list or specific document details.
     * Query parameters:
     * - id: Get specific document details with workflow steps
     * - action=generate_mock: Create a test document
     */

    global $db, $currentUser;

    try {
        // Enforce timeouts on stale documents before responding
        enforceTimeouts();
        // Generate a mock document with sample PDF and signature mapping
        if (isset($_GET['action']) && $_GET['action'] === 'generate_mock') {
            generateMockDocument();
            return;
        }

        // If a specific document id is requested, return full details for modal view
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $documentId = (int) $_GET['id'];

            // Load document and student info
            $docStmt = $db->prepare("SELECT d.*, s.id AS student_id, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.department AS student_department
                                     FROM documents d
                                     JOIN students s ON d.student_id = s.id
                                     WHERE d.id = ?");
            $docStmt->execute([$documentId]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Document not found']);
                return;
            }

            // Load workflow steps with assignee and signature info
            $stepsStmt = $db->prepare("SELECT ds.*, e.first_name, e.last_name,
                                              dsg.status AS signature_status, dsg.signed_at
                                       FROM document_steps ds
                                       LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                                       LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id AND dsg.employee_id = ds.assigned_to_employee_id
                                       WHERE ds.document_id = ?
                                       ORDER BY ds.step_order ASC");
            $stepsStmt->execute([$documentId]);
            $steps = [];
            while ($s = $stepsStmt->fetch(PDO::FETCH_ASSOC)) {
                $steps[] = [
                    'id' => (int) $s['id'],
                    'step_order' => (int) $s['step_order'],
                    'name' => $s['name'],
                    'status' => $s['status'],
                    'note' => $s['note'],
                    'acted_at' => $s['acted_at'],
                    'assignee_id' => $s['assigned_to_employee_id'],
                    'assignee_name' => ($s['first_name'] || $s['last_name']) ? trim($s['first_name'] . ' ' . $s['last_name']) : null,
                    'signature_status' => $s['signature_status'],
                    'signed_at' => $s['signed_at']
                ];
            }

            // Optional attachment (first one)
            $filePath = null;
            $attStmt = $db->prepare("SELECT file_path FROM attachments WHERE document_id = ? ORDER BY id ASC LIMIT 1");
            $attStmt->execute([$documentId]);
            if ($att = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $filePath = $att['file_path'];
            }

            $payload = [
                'id' => (int) $doc['id'],
                'title' => $doc['title'],
                'doc_type' => $doc['doc_type'],
                'description' => $doc['description'],
                'status' => $doc['status'],
                'current_step' => (int) $doc['current_step'],
                'uploaded_at' => $doc['uploaded_at'],
                'student' => [
                    'id' => $doc['student_id'],
                    'name' => $doc['student_name'],
                    'department' => $doc['student_department']
                ],
                'workflow' => $steps,
                'file_path' => $filePath
            ];

            // Attach signature mapping if available (percent-based coordinates)
            $mockDir = realpath(__DIR__ . '/../assets') . DIRECTORY_SEPARATOR . 'mock';
            if ($mockDir && is_dir($mockDir)) {
                $mapFile = $mockDir . DIRECTORY_SEPARATOR . 'signature_map_' . $documentId . '.json';
                if (file_exists($mapFile)) {
                    $mapJson = json_decode(file_get_contents($mapFile), true);
                    if (is_array($mapJson)) {
                        $payload['signature_map'] = $mapJson;
                    }
                }
            }

            // Return as a plain object (notifications.js expects no wrapper)
            echo json_encode($payload);
            return;
        }
        // Get documents assigned to current employee that need action
        $stmt = $db->prepare("
            SELECT
                d.id,
                d.title,
                d.doc_type,
                d.description,
                d.status,
                d.current_step,
                d.uploaded_at,
                s.id as student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                s.department as student_department,
                ds.id as step_id,
                ds.step_order,
                ds.name as step_name,
                ds.status as step_status,
                ds.note as step_note,
                ds.acted_at,
                ds.assigned_to_employee_id,
                dsg.status as signature_status,
                dsg.signed_at,
                e.first_name AS assignee_first,
                e.last_name AS assignee_last
            FROM documents d
            JOIN students s ON d.student_id = s.id
            JOIN document_steps ds ON d.id = ds.document_id
            LEFT JOIN document_signatures dsg ON ds.id = dsg.step_id AND dsg.employee_id = ?
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            WHERE ds.assigned_to_employee_id = ?
            AND ds.status = 'pending'
            AND d.status IN ('submitted', 'in_review')
            ORDER BY d.uploaded_at DESC, ds.step_order ASC
        ");

        $stmt->execute([$currentUser['id'], $currentUser['id']]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by document and organize workflow
        $processedDocuments = [];
        foreach ($documents as $doc) {
            $docId = $doc['id'];

            if (!isset($processedDocuments[$docId])) {
                $processedDocuments[$docId] = [
                    'id' => $doc['id'],
                    'title' => $doc['title'],
                    'doc_type' => $doc['doc_type'],
                    'description' => $doc['description'],
                    'status' => $doc['status'],
                    'current_step' => $doc['current_step'],
                    'uploaded_at' => $doc['uploaded_at'],
                    'student' => [
                        'id' => $doc['student_id'],
                        'name' => $doc['student_name'],
                        'department' => $doc['student_department']
                    ],
                    'workflow' => []
                ];
            }

            // Add workflow step
            $processedDocuments[$docId]['workflow'][] = [
                'id' => $doc['step_id'],
                'step_order' => $doc['step_order'],
                'name' => $doc['step_name'],
                'status' => $doc['step_status'],
                'note' => $doc['step_note'],
                'acted_at' => $doc['acted_at'],
                'assignee_id' => $doc['assigned_to_employee_id'],
                'assignee_name' => trim(($doc['assignee_first'] ?? '') . ' ' . ($doc['assignee_last'] ?? '')) ?: null,
                'signature_status' => $doc['signature_status'],
                'signed_at' => $doc['signed_at']
            ];
        }

        echo json_encode([
            'success' => true,
            'documents' => array_values($processedDocuments)
        ]);

    } catch (Exception $e) {
        error_log("Error fetching documents: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch documents']);
    }
}

function handlePost()
{
    global $db, $currentUser;

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        // Enforce timeouts on stale documents before processing actions
        enforceTimeouts();
        switch ($action) {
            case 'sign':
                signDocument($input);
                break;
            case 'reject':
                rejectDocument($input);
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        error_log("Error processing document action: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to process action']);
    }
}

function signDocument($input)
{
    global $db, $currentUser;

    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $notes = $input['notes'] ?? '';
    $signatureMap = $input['signature_map'] ?? null; // optional percent-based coordinates

    if (!$documentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID is required']);
        return;
    }

    // If stepId not provided, infer the current pending step assigned to this employee
    if (!$stepId) {
        $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
        $q->execute([$documentId, $currentUser['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No pending step assigned to you for this document']);
            return;
        }
        $stepId = (int) $row['id'];
    }

    // Optional signature image data URL -> save as PNG
    $signaturePathForDB = null;
    if (!empty($input['signature_image']) && is_string($input['signature_image'])) {
        $prefix = 'data:image/png;base64,';
        if (strpos($input['signature_image'], $prefix) === 0) {
            $base64 = substr($input['signature_image'], strlen($prefix));
            $bin = base64_decode($base64);
            if ($bin !== false) {
                $sigDir = realpath(__DIR__ . '/../assets');
                if (!$sigDir) {
                    $sigDir = __DIR__ . '/../assets';
                }
                $sigDir = rtrim($sigDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'signatures';
                if (!is_dir($sigDir)) {
                    @mkdir($sigDir, 0775, true);
                }
                $fn = 'signature_doc' . $documentId . '_emp' . $currentUser['id'] . '_' . time() . '.png';
                $abs = $sigDir . DIRECTORY_SEPARATOR . $fn;
                if (@file_put_contents($abs, $bin) !== false) {
                    $signaturePathForDB = '../assets/signatures/' . $fn; // web-relative path
                }
            }
        }
    }

    $db->beginTransaction();

    try {
        // Update document signature (store signature image path if provided)
        $stmt = $db->prepare("
            INSERT INTO document_signatures (document_id, step_id, employee_id, status, signed_at, signature_path)
            VALUES (?, ?, ?, 'signed', NOW(), ?)
            ON DUPLICATE KEY UPDATE
            status = 'signed', signed_at = NOW(), signature_path = VALUES(signature_path)
        ");
        $stmt->execute([$documentId, $stepId, $currentUser['id'], $signaturePathForDB]);

        // Update document step
        $stmt = $db->prepare("
            UPDATE document_steps
            SET status = 'completed', acted_at = NOW(), note = ?
            WHERE id = ? AND assigned_to_employee_id = ?
        ");
        $stmt->execute([$notes, $stepId, $currentUser['id']]);

        // Persist signature mapping (percent-based) if provided
        if (is_array($signatureMap)) {
            try {
                $assetsDir = realpath(__DIR__ . '/../assets');
                if (!$assetsDir) {
                    $assetsDir = __DIR__ . '/../assets';
                }
                $mockDir = rtrim($assetsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mock';
                if (!is_dir($mockDir)) {
                    @mkdir($mockDir, 0775, true);
                }
                $mapPath = $mockDir . DIRECTORY_SEPARATOR . 'signature_map_' . $documentId . '.json';
                @file_put_contents($mapPath, json_encode($signatureMap));
            } catch (Exception $e) {
                // Do not fail signing if map persistence fails; just log
                error_log('Failed to persist signature_map: ' . $e->getMessage());
            }
        }

        // Check if all steps are completed
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_steps,
                   COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_steps
            FROM document_steps
            WHERE document_id = ?
        ");
        $stmt->execute([$documentId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($progress['total_steps'] == $progress['completed_steps']) {
            // All steps completed, update document status
            $stmt = $db->prepare("
                UPDATE documents
                SET status = 'approved', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$documentId]);
        } else {
            // Update current step and status
            $stmt = $db->prepare("
                UPDATE documents
                SET current_step = current_step + 1, status = 'in_review', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$documentId]);
        }

        // Add audit log
        addAuditLog(
            'DOCUMENT_SIGNED',
            'Document Management',
            "Document signed by {$currentUser['first_name']} {$currentUser['last_name']}",
            $documentId,
            'Document',
            'INFO'
        );

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Document signed successfully',
            'step_id' => $stepId
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function rejectDocument($input)
{
    global $db, $currentUser;

    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $reason = $input['reason'] ?? '';

    if (!$documentId || empty($reason)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID and rejection reason are required']);
        return;
    }

    // If stepId not provided, infer a step assigned to this employee
    if (!$stepId) {
        // Prefer a pending step owned by this employee
        $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
        $q->execute([$documentId, $currentUser['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Otherwise, allow any step assigned to this employee (any status)
            $q2 = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? ORDER BY step_order ASC LIMIT 1");
            $q2->execute([$documentId, $currentUser['id']]);
            $row = $q2->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No step assigned to you for this document']);
                return;
            }
        }
        $stepId = (int) $row['id'];
    }

    $db->beginTransaction();

    try {
        // Update document signature to rejected
        $stmt = $db->prepare("
            INSERT INTO document_signatures (document_id, step_id, employee_id, status, signed_at)
            VALUES (?, ?, ?, 'rejected', NOW())
            ON DUPLICATE KEY UPDATE
            status = 'rejected', signed_at = NOW()
        ");
        $stmt->execute([$documentId, $stepId, $currentUser['id']]);

        // Update document step to rejected
        $stmt = $db->prepare("
            UPDATE document_steps
            SET status = 'rejected', acted_at = NOW(), note = ?
            WHERE id = ? AND assigned_to_employee_id = ?
        ");
        $stmt->execute([$reason, $stepId, $currentUser['id']]);

        // Update document status to rejected
        $stmt = $db->prepare("
            UPDATE documents
            SET status = 'rejected', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$documentId]);

        // Add audit log
        addAuditLog(
            'DOCUMENT_REJECTED',
            'Document Management',
            "Document rejected by {$currentUser['first_name']} {$currentUser['last_name']}: {$reason}",
            $documentId,
            'Document',
            'WARNING'
        );

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Document rejected successfully',
            'step_id' => $stepId
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Enforce timeouts on documents that have been pending beyond the configured threshold.
 * - If DOC_TIMEOUT_MODE = 'reject', mark document and pending steps as rejected.
 * - If DOC_TIMEOUT_MODE = 'delete', soft delete by setting documents.status = 'deleted'.
 */
function enforceTimeouts()
{
    global $db;

    try {
        // Identify stale documents
        $sel = $db->prepare("
            SELECT id FROM documents
            WHERE status IN ('submitted', 'in_review')
              AND DATEDIFF(NOW(), uploaded_at) >= ?
        ");
        $sel->execute([DOC_TIMEOUT_DAYS]);
        $stale = $sel->fetchAll(PDO::FETCH_COLUMN);

        if (!$stale) {
            return;
        }

        foreach ($stale as $docId) {
            $db->beginTransaction();
            try {
                if (DOC_TIMEOUT_MODE === 'delete') {
                    // Soft delete: mark as deleted
                    $upd = $db->prepare("UPDATE documents SET status = 'deleted', updated_at = NOW() WHERE id = ? AND status IN ('submitted', 'in_review')");
                    $upd->execute([$docId]);
                } else {
                    // Reject: mark pending steps and document as rejected
                    $updSteps = $db->prepare("UPDATE document_steps SET status = 'rejected', acted_at = NOW(), note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note,'') <> '' THEN ' ' ELSE '' END, '[Auto-timeout after ', ?, ' days]') WHERE document_id = ? AND status = 'pending'");
                    $updSteps->execute([DOC_TIMEOUT_DAYS, $docId]);

                    $updDoc = $db->prepare("UPDATE documents SET status = 'rejected', updated_at = NOW() WHERE id = ? AND status IN ('submitted', 'in_review')");
                    $updDoc->execute([$docId]);
                }

                // Audit log entry
                @addAuditLog(
                    'DOCUMENT_TIMEOUT',
                    'Document Management',
                    'Auto-timeout applied to document ID ' . $docId . ' after ' . DOC_TIMEOUT_DAYS . ' days (mode=' . DOC_TIMEOUT_MODE . ')',
                    $docId,
                    'Document',
                    'WARNING'
                );

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                error_log('enforceTimeouts failed for doc ' . $docId . ': ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('enforceTimeouts error: ' . $e->getMessage());
    }
}

function handlePut()
{
    // Handle document updates if needed
    http_response_code(501);
    echo json_encode(['success' => false, 'message' => 'Not implemented']);
}

function handleDelete()
{
    // Handle document deletion if needed
    http_response_code(501);
    echo json_encode(['success' => false, 'message' => 'Not implemented']);
}

function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $db, $currentUser;

    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, user_role, action, category, details, target_id, target_type, severity, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Seed a mock document with a sample PDF and signature mapping JSON
function generateMockDocument()
{
    global $db, $currentUser;

    if (!$currentUser || $currentUser['role'] !== 'employee') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        return;
    }

    try {
        // Ensure demo student exists
        $studentId = 'STU101';
        $q = $db->prepare("SELECT id FROM students WHERE id = ?");
        $q->execute([$studentId]);
        if (!$q->fetch()) {
            $ins = $db->prepare("INSERT INTO students (id, first_name, last_name, email, password, department, position) VALUES (?,?,?,?,?,'College of Engineering','Student')");
            // Use a unique email to avoid violating UNIQUE(email)
            $ins->execute([$studentId, 'Juan', 'Dela Cruz', 'mock.student101@university.edu', password_hash('password', PASSWORD_BCRYPT)]);
        }

        // Create document
        $stmt = $db->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, current_step, uploaded_at) VALUES (?,?,?,?, 'submitted', 1, NOW())");
        $stmt->execute([
            $studentId,
            'proposal',
            'Mock Research Proposal: Energy Efficiency',
            'Auto-generated mock for testing PDF preview and e-signature placement.'
        ]);
        $docId = (int) $db->lastInsertId();

        // Create a pending step assigned to current employee
        $st = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, status, acted_at, note) VALUES (?,?,?,?, 'pending', NULL, NULL)");
        $st->execute([$docId, 1, 'Initial Review', $currentUser['id']]);

        // Ensure mock dir exists
        $assetsDir = realpath(__DIR__ . '/../assets');
        if (!$assetsDir) {
            $assetsDir = __DIR__ . '/../assets';
        }
        $mockDir = rtrim($assetsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mock';
        if (!is_dir($mockDir)) {
            @mkdir($mockDir, 0775, true);
        }

        // Prefer existing sample2.pdf for mock preview; fallback to a tiny generated sample.pdf
        $pdfPathAbs2 = $mockDir . DIRECTORY_SEPARATOR . 'sample2.pdf';
        if (file_exists($pdfPathAbs2)) {
            $pdfWebPath = '../assets/mock/sample2.pdf';
        } else {
            // Write a tiny PDF if fallback not present
            $pdfPathAbs = $mockDir . DIRECTORY_SEPARATOR . 'sample.pdf';
            if (!file_exists($pdfPathAbs)) {
                $pdfBase64 = 'JVBERi0xLjQKJcTl8uXrp/CgIDAgb2JqCjw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+CmVuZG9iagoyIDAgb2JqCjw8L1R5cGUvUGFnZXMvS2lkc1szIDAgUl0vQ291bnQgMT4+CmVuZG9iagozIDAgb2JqCjw8L1R5cGUvUGFnZS9QYXJlbnQgMiAwIFIvTWVkaWFCb3hbMCAwIDU5NSA4NDJdL0NvbnRlbnRzIDQgMCBSL1Jlc291cmNlczw8L0ZvbnQ8PC9GMCA1IDAgUj4+Pj4+Pj4KZW5kb2JqCjQgMCBvYmoKPDwvTGVuZ3RoIDY3Pj4Kc3RyZWFtCkJUCi9GMCAxMiBUZgoxMDAgNzUwIFRkCihNb2NrIFBERiBmb3IgdGVzdGluZyAmIFNpZ25hdHVyZSBNYXBwaW5nKSBUagoKRVQKZW5kc3RyZWFtCmVuZG9iago1IDAgb2JqCjw8L1R5cGUvRm9udC9TdWJ0eXBlL1R5cGUxL0Jhc2VGb250L0hlbHZldGljYT4+CmVuZG9iagp4cmVmCjAgNgowMDAwMDAwMDAwIDY1NTM1IGYgCjAwMDAwMDAwOTcgMDAwMDAgbiAKMDAwMDAwMDE5NyAwMDAwMCBuIAowMDAwMDAwNDExIDAwMDAwIG4gCjAwMDAwMDAxNDMgMDAwMDAgbiAKMDAwMDAwMDUwMyAwMDAwMCBuIAp0cmFpbGVyCjw8L1NpemUgNi9Sb290IDEgMCBSPj4Kc3RhcnR4cmVmCjcyNQolJUVPRg==';
                @file_put_contents($pdfPathAbs, base64_decode($pdfBase64));
            }
            $pdfWebPath = '../assets/mock/sample.pdf';
        }

        // Attach the PDF
        $att = $db->prepare("INSERT INTO attachments (document_id, file_path, file_type, file_size_kb) VALUES (?,?, 'application/pdf', 12)");
        $att->execute([$docId, $pdfWebPath]);

        // Signature mapping JSON (percent-based coords relative to rendered box)
        $map = [
            'page' => 1,
            'x_pct' => 0.62,
            'y_pct' => 0.78,
            'w_pct' => 0.28,
            'h_pct' => 0.10,
            'label' => 'Sign here'
        ];
        @file_put_contents($mockDir . DIRECTORY_SEPARATOR . 'signature_map_' . $docId . '.json', json_encode($map));

        echo json_encode(['success' => true, 'document_id' => $docId]);
    } catch (Exception $e) {
        error_log('generateMockDocument error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to generate mock document']);
    }
}
?>