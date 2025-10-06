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
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../vendor/autoload.php';

header('Content-Type: application/json');

// Add this function after existing includes/requires
function fillDocxTemplate($templatePath, $data)
{
    if (!file_exists($templatePath)) {
        throw new Exception("Template file not found: $templatePath");
    }
    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

    // Replace simple placeholders
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if ($key === 'objectives' || $key === 'ilos') {
                // Simple bulleted list for arrays of strings
                $value = implode("\n• ", array_map('htmlspecialchars', $value));
            } elseif ($key === 'program') {
                // Format program schedule
                $lines = [];
                foreach ($value as $item) {
                    $lines[] = htmlspecialchars("{$item['start']} - {$item['end']}: {$item['act']}");
                }
                $value = implode("\n", $lines);
            } elseif ($key === 'budget') {
                // Format budget table as text
                $lines = [];
                foreach ($value as $item) {
                    $lines[] = htmlspecialchars("{$item['name']} - {$item['size']} - Qty: {$item['qty']} - Price: ₱{$item['price']} - Total: ₱{$item['total']}");
                }
                $value = implode("\n", $lines);
            } else {
                $value = implode(", ", array_map('htmlspecialchars', $value));
            }
        } elseif (is_string($value)) {
            $value = htmlspecialchars($value);
        }
        $templateProcessor->setValue($key, $value);
    }

    // Generate unique filename for filled document
    $outputDir = '../uploads/';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $outputPath = $outputDir . 'filled_' . uniqid('doc_', true) . '.docx';
    $templateProcessor->saveAs($outputPath);
    return $outputPath;
}

// Convert DOCX to PDF using CloudConvert
function convertDocxToPdf($docxPath)
{
    $logFile = __DIR__ . '/../conversion.log';
    $log = date('Y-m-d H:i:s') . " - Starting conversion for: " . $docxPath . "\n";
    file_put_contents($logFile, $log, FILE_APPEND);
    
    $apiKey = $_ENV['CLOUDCONVERT_API_KEY'] ?? null;
    if (!$apiKey) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: API key not found\n", FILE_APPEND);
        return $docxPath; // Return original path if conversion fails
    }
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - API key found\n", FILE_APPEND);

    if (!file_exists($docxPath)) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: DOCX file does not exist: $docxPath\n", FILE_APPEND);
        return $docxPath;
    }
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - DOCX file exists, size: " . filesize($docxPath) . "\n", FILE_APPEND);

    try {
        $cloudconvert = new \CloudConvert\CloudConvert([
            'api_key' => $apiKey,
            'sandbox' => false
        ]);
        
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - CloudConvert client created\n", FILE_APPEND);

        $job = (new \CloudConvert\Models\Job())
            ->addTask(new \CloudConvert\Models\Task('import/upload', 'upload-my-file'))
            ->addTask(
                (new \CloudConvert\Models\Task('convert', 'convert-my-file'))
                    ->set('input', 'upload-my-file')
                    ->set('output_format', 'pdf')
            )
            ->addTask(
                (new \CloudConvert\Models\Task('export/url', 'export-my-file'))
                    ->set('input', 'convert-my-file')
            );
        
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Creating job\n", FILE_APPEND);
        $job = $cloudconvert->jobs()->create($job);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Job created: " . $job->getId() . "\n", FILE_APPEND);

        $uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Uploading file\n", FILE_APPEND);
        $fileContent = file_get_contents($docxPath);
        $cloudconvert->tasks()->upload($uploadTask, $fileContent, basename($docxPath));
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - File uploaded\n", FILE_APPEND);

        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Waiting for completion\n", FILE_APPEND);
        $cloudconvert->jobs()->wait($job);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Job completed, status: " . $job->getStatus() . "\n", FILE_APPEND);

        if ($job->getStatus() === 'finished') {
            $exportTask = $job->getTasks()->whereName('export-my-file')[0];
            $result = $exportTask->getResult();
            if (isset($result->files) && count($result->files) > 0) {
                $fileUrl = $result->files[0]->url;
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - Downloading PDF from: $fileUrl\n", FILE_APPEND);
                $pdfContent = file_get_contents($fileUrl);
                $pdfPath = str_replace('.docx', '.pdf', $docxPath);
                file_put_contents($pdfPath, $pdfContent);
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - PDF saved to: $pdfPath\n", FILE_APPEND);

                // Clean up DOCX
                if (file_exists($docxPath)) {
                    $unlinkResult = unlink($docxPath);
                    if ($unlinkResult) {
                        file_put_contents($logFile, date('Y-m-d H:i:s') . " - DOCX cleaned up successfully\n", FILE_APPEND);
                    } else {
                        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Failed to clean up DOCX\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents($logFile, date('Y-m-d H:i:s') . " - WARNING: DOCX file not found for cleanup\n", FILE_APPEND);
                }

                file_put_contents($logFile, date('Y-m-d H:i:s') . " - Conversion successful\n", FILE_APPEND);
                return $pdfPath;
            } else {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: No files in result\n", FILE_APPEND);
            }
        } else {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Job failed with status: " . $job->getStatus() . "\n", FILE_APPEND);
        }

    } catch (Exception $e) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Returning original path: $docxPath\n", FILE_APPEND);
    return $docxPath; // Return original path if conversion fails
}

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

        // Seed mock data for testing signature hierarchy
        if (isset($_GET['action']) && $_GET['action'] === 'seed_mock_data') {
            seedMockData();
            return;
        }

        // New: Handle student document fetching
        if (isset($_GET['action']) && $_GET['action'] === 'my_documents') {
            if ($currentUser['role'] !== 'student') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                return;
            }

            // Fetch student's documents with workflow steps and notes
            $stmt = $db->prepare("
                SELECT d.id, d.title, d.doc_type, d.description, d.status, d.uploaded_at,
                       ds.step_order, ds.name AS step_name, ds.status AS step_status, ds.note, ds.acted_at,
                       e.first_name AS assignee_first, e.last_name AS assignee_last
                FROM documents d
                LEFT JOIN document_steps ds ON d.id = ds.document_id
                LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                WHERE d.student_id = ?
                ORDER BY d.uploaded_at DESC, ds.step_order ASC
            ");
            $stmt->execute([$currentUser['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by document
            $documents = [];
            foreach ($rows as $row) {
                $docId = $row['id'];
                if (!isset($documents[$docId])) {
                    // Determine current location
                    $current_location = 'Student Council'; // Default
                    foreach ($rows as $stepRow) {
                        if ($stepRow['id'] === $docId && ($stepRow['step_status'] === 'in_progress' || $stepRow['step_status'] === 'pending')) {
                            $current_location = $stepRow['step_name'];
                            break;
                        }
                    }

                    $documents[$docId] = [
                        'id' => $row['id'],
                        'document_name' => $row['title'],
                        'status' => $row['status'],
                        'current_location' => $current_location,
                        'created_at' => $row['uploaded_at'],
                        'updated_at' => $row['uploaded_at'],
                        'description' => $row['description'],
                        'workflow_history' => [],
                        'notes' => []
                    ];
                }
                if ($row['step_order']) {  // Only add if step exists
                    $documents[$docId]['workflow_history'][] = [
                        'created_at' => $row['acted_at'] ?: $row['uploaded_at'],
                        'action' => $row['step_status'] === 'completed' ? 'Approved' : ($row['step_status'] === 'rejected' ? 'Rejected' : 'Pending'),
                        'office_name' => $row['step_name'],
                        'from_office' => $row['step_name']
                    ];
                }
            }

            // Fetch notes for all documents
            if (!empty($documents)) {
                $docIds = array_keys($documents);
                $placeholders = str_repeat('?,', count($docIds) - 1) . '?';
                $notesStmt = $db->prepare("
                    SELECT n.id, n.note, n.created_at, d.id as document_id,
                           CASE 
                               WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                               WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                               WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                               ELSE 'Unknown'
                           END as created_by_name,
                           NULL as step_status
                    FROM document_notes n
                    JOIN documents d ON n.document_id = d.id
                    LEFT JOIN employees e ON n.author_id = e.id AND n.author_role = 'employee'
                    LEFT JOIN students s ON n.author_id = s.id AND n.author_role = 'student'
                    LEFT JOIN administrators a ON n.author_id = a.id AND n.author_role = 'admin'
                    WHERE n.document_id IN ($placeholders)
                    
                    UNION ALL
                    
                    SELECT CONCAT('step_', ds.id) as id, ds.note, ds.acted_at as created_at, ds.document_id,
                           CONCAT(e.first_name, ' ', e.last_name) as created_by_name, ds.status as step_status
                    FROM document_steps ds
                    JOIN employees e ON ds.assigned_to_employee_id = e.id
                    WHERE ds.document_id IN ($placeholders) 
                    AND ds.note IS NOT NULL 
                    AND ds.note != ''
                    
                    ORDER BY created_at ASC
                ");
                $notesStmt->execute(array_merge($docIds, $docIds));
                $allNotes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

                // Group notes by document
                foreach ($allNotes as $note) {
                    $docId = $note['document_id'];
                    if (isset($documents[$docId])) {
                        $isRejection = isset($note['step_status']) && $note['step_status'] === 'rejected';
                        $documents[$docId]['notes'][] = [
                            'id' => $note['id'],
                            'note' => $note['note'],
                            'created_by_name' => $note['created_by_name'],
                            'created_at' => $note['created_at'],
                            'is_rejection' => $isRejection
                        ];
                    }
                }
            }

            echo json_encode(['success' => true, 'documents' => array_values($documents)]);
            return;
        }

        // Handle document details request for modal view
        if (isset($_GET['action']) && $_GET['action'] === 'document_details' && isset($_GET['id'])) {
            if ($currentUser['role'] !== 'student') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $documentId = (int) $_GET['id'];

            // Verify the document belongs to the current student
            $docStmt = $db->prepare("SELECT d.*, s.first_name, s.last_name 
                                     FROM documents d 
                                     JOIN students s ON d.student_id = s.id 
                                     WHERE d.id = ? AND d.student_id = ?");
            $docStmt->execute([$documentId, $currentUser['id']]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$doc) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Document not found']);
                return;
            }

            // Fetch workflow history
            $historyStmt = $db->prepare("
                SELECT ds.*, e.first_name, e.last_name,
                       CONCAT(e.first_name, ' ', e.last_name) as employee_name
                FROM document_steps ds
                LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                WHERE ds.document_id = ?
                ORDER BY ds.step_order ASC
            ");
            $historyStmt->execute([$documentId]);
            $workflow_history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch notes/comments (both general notes and step notes)
            $notesStmt = $db->prepare("
                SELECT n.id, n.note, n.created_at, n.document_id,
                       CASE
                           WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                           WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                           WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                           ELSE 'Unknown'
                       END as created_by_name,
                       NULL as step_status
                FROM document_notes n
                LEFT JOIN employees e ON n.author_id = e.id AND n.author_role = 'employee'
                LEFT JOIN students s ON n.author_id = s.id AND n.author_role = 'student'
                LEFT JOIN administrators a ON n.author_id = a.id AND n.author_role = 'admin'
                WHERE n.document_id = ?

                UNION ALL

                SELECT CONCAT('step_', ds.id) as id, ds.note, ds.acted_at as created_at, ds.document_id,
                       CONCAT(e.first_name, ' ', e.last_name) as created_by_name, ds.status as step_status
                FROM document_steps ds
                JOIN employees e ON ds.assigned_to_employee_id = e.id
                WHERE ds.document_id = ?
                AND ds.note IS NOT NULL
                AND ds.note != ''

                ORDER BY created_at ASC
            ");
            $notesStmt->execute([$documentId, $documentId]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Determine current location
            $current_location = 'Student Council'; // Default
            foreach ($workflow_history as $step) {
                if ($step['status'] === 'in_progress' || $step['status'] === 'pending') {
                    $current_location = $step['name'];
                    break;
                }
            }

            $document = [
                'id' => $doc['id'],
                'document_name' => $doc['title'],
                'status' => $doc['status'],
                'current_location' => $current_location,
                'created_at' => $doc['uploaded_at'],
                'updated_at' => $doc['uploaded_at'], // You might want to track last update separately
                'description' => $doc['description'],
                'workflow_history' => array_map(function($step) {
                    return [
                        'created_at' => $step['acted_at'] ?: $step['created_at'],
                        'action' => $step['status'] === 'completed' ? 'Approved' : ($step['status'] === 'rejected' ? 'Rejected' : 'Pending'),
                        'office_name' => $step['name'],
                        'from_office' => $step['name']
                    ];
                }, $workflow_history),
                'notes' => array_map(function($note) {
                    return [
                        'id' => $note['id'],
                        'note' => $note['note'],
                        'created_by_name' => $note['created_by_name'],
                        'created_at' => $note['created_at'] ?? null,
                        'is_rejection' => ($note['step_status'] === 'rejected')
                    ];
                }, $notes)
            ];

            echo json_encode(['success' => true, 'document' => $document]);
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
            $stepsStmt = $db->prepare("SELECT ds.*, e.first_name AS emp_first, e.last_name AS emp_last,
                                              s.first_name AS stu_first, s.last_name AS stu_last,
                                              dsg.status AS signature_status, dsg.signed_at
                                       FROM document_steps ds
                                       LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                                       LEFT JOIN students s ON ds.assigned_to_student_id = s.id
                                       LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id 
                                           AND ((dsg.employee_id = ds.assigned_to_employee_id AND ds.assigned_to_employee_id IS NOT NULL) 
                                                OR (dsg.student_id = ds.assigned_to_student_id AND ds.assigned_to_student_id IS NOT NULL))
                                       WHERE ds.document_id = ?
                                       ORDER BY ds.step_order ASC");
            $stepsStmt->execute([$documentId]);
            $steps = [];
            while ($s = $stepsStmt->fetch(PDO::FETCH_ASSOC)) {
                // Determine assignee info (employee or student)
                $assignee_id = $s['assigned_to_employee_id'] ?: $s['assigned_to_student_id'];
                $assignee_name = null;
                if ($s['assigned_to_employee_id']) {
                    $assignee_name = ($s['emp_first'] || $s['emp_last']) ? trim($s['emp_first'] . ' ' . $s['emp_last']) : null;
                } elseif ($s['assigned_to_student_id']) {
                    $assignee_name = ($s['stu_first'] || $s['stu_last']) ? trim($s['stu_first'] . ' ' . $s['stu_last']) : null;
                }
                
                $steps[] = [
                    'id' => (int) $s['id'],
                    'step_order' => (int) $s['step_order'],
                    'name' => $s['name'],
                    'status' => $s['status'],
                    'note' => $s['note'],
                    'acted_at' => $s['acted_at'],
                    'assignee_id' => $assignee_id,
                    'assignee_name' => $assignee_name,
                    'assignee_type' => $s['assigned_to_employee_id'] ? 'employee' : 'student',
                    'signature_status' => $s['signature_status'],
                    'signed_at' => $s['signed_at']
                ];
            }

            // Optional attachment (first one) or generated file
            $filePath = null;
            $attStmt = $db->prepare("SELECT file_path FROM attachments WHERE document_id = ? ORDER BY id ASC LIMIT 1");
            $attStmt->execute([$documentId]);
            if ($att = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $filePath = $att['file_path'];
            }
            // Use generated file path if no attachment
            if (!$filePath && $doc['file_path']) {
                $filePath = $doc['file_path'];
                // Handle backward compatibility: if file_path doesn't start with '../', assume it's in uploads
                if ($filePath && strpos($filePath, '../') !== 0 && strpos($filePath, 'http') !== 0) {
                    $filePath = '../uploads/' . $filePath;
                }
            }
            // Convert server path to web URL
            if ($filePath) {
                $absPath = realpath(__DIR__ . '/' . $filePath);
                if ($absPath) {
                    $basePath = realpath(__DIR__ . '/../');
                    $filePath = str_replace($basePath, '', $absPath);
                    $filePath = str_replace('\\', '/', $filePath);
                    $filePath = '/SPCF-Thesis' . $filePath;
                    // URL encode the filename to handle spaces and special characters
                    $pathParts = explode('/', $filePath);
                    $filename = end($pathParts);
                    $encodedFilename = rawurlencode($filename);
                    $filePath = str_replace($filename, $encodedFilename, $filePath);
                }
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
        // Get documents assigned to current user (employee or SSC President student) that need action
        $query = "
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
                ds.assigned_to_student_id,
                CASE
                    WHEN ds.assigned_to_employee_id IS NOT NULL THEN dsg.status
                    WHEN ds.assigned_to_student_id IS NOT NULL THEN dssg.status
                    ELSE NULL
                END as signature_status,
                CASE
                    WHEN ds.assigned_to_employee_id IS NOT NULL THEN dsg.signed_at
                    WHEN ds.assigned_to_student_id IS NOT NULL THEN dssg.signed_at
                    ELSE NULL
                END as signed_at,
                e.first_name AS assignee_first,
                e.last_name AS assignee_last,
                st.first_name AS student_assignee_first,
                st.last_name AS student_assignee_last
            FROM documents d
            JOIN students s ON d.student_id = s.id
            JOIN document_steps ds ON d.id = ds.document_id
            LEFT JOIN document_signatures dsg ON ds.id = dsg.step_id AND dsg.employee_id = ds.assigned_to_employee_id
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            LEFT JOIN students st ON ds.assigned_to_student_id = st.id
            LEFT JOIN document_signatures dssg ON ds.id = dssg.step_id AND dssg.student_id = ds.assigned_to_student_id
            WHERE ";

        $params = [];

        if ($currentUser['role'] === 'employee') {
            $query .= "ds.assigned_to_employee_id = ?";
            $params[] = $currentUser['id'];
        } elseif ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President') {
            $query .= "ds.assigned_to_student_id = ?";
            $params[] = $currentUser['id'];
        } else {
            // No documents for other student roles
            echo json_encode(['success' => true, 'documents' => []]);
            return;
        }

        $query .= "
            AND ds.status = 'pending'
            AND d.status IN ('submitted', 'in_review')
            ORDER BY d.uploaded_at DESC, ds.step_order ASC
        ";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
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
                'assignee_id' => $doc['assigned_to_employee_id'] ?: $doc['assigned_to_student_id'],
                'assignee_name' => $doc['assigned_to_employee_id'] ?
                    trim(($doc['assignee_first'] ?? '') . ' ' . ($doc['assignee_last'] ?? '')) :
                    trim(($doc['student_assignee_first'] ?? '') . ' ' . ($doc['student_assignee_last'] ?? '')),
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

    // Check if this is a FormData request (for file uploads)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Handle FormData requests
        $action = $_POST['action'] ?? '';
        try {
            // Enforce timeouts on stale documents before processing actions
            enforceTimeouts();
            switch ($action) {
                case 'sign':
                    signDocument($_POST, $_FILES);
                    break;
                case 'reject':
                    rejectDocument($_POST);
                    break;
                case 'create':
                    createDocument($_POST);
                    break;
                case 'update_note':
                    updateNote($_POST);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
                    break;
            }
        } catch (Exception $e) {
            error_log("Error processing document action: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to process action: ' . $e->getMessage()]);
        }
        return;
    }

    // Handle JSON requests (legacy)
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
            case 'create':
                createDocument($input);
                break;
            case 'update_note':
                updateNote($input);
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        error_log("Error processing document action: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to process action: ' . $e->getMessage()]);
    }
}

function createDocument($input)
{
    global $db, $currentUser;

    if ($currentUser['role'] !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only students can create documents']);
        return;
    }

    $docType = $input['doc_type'] ?? '';
    $studentId = $input['student_id'] ?? '';
    $data = $input['data'] ?? [];

    if (!$docType || !$studentId || !$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }

    try {
        $db->beginTransaction();

        // Insert document
        $stmt = $db->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, current_step, uploaded_at) VALUES (?, ?, ?, ?, 'submitted', 1, NOW())");
        $stmt->execute([$studentId, $docType, $data['title'] ?? 'Untitled', $data['rationale'] ?? '']);

        $docId = (int) $db->lastInsertId();

        // Get department
        $department = $data['department'] ?? '';

        // Department full names
        $departmentFullMap = [
            'College of Arts, Social Sciences, and Education' => 'College of Arts, Social Sciences, and Education',
            'College of Business' => 'College of Business',
            'College of Computing and Information Sciences' => 'College of Computing and Information Sciences',
            'College of Criminology' => 'College of Criminology',
            'College of Engineering' => 'College of Engineering',
            'College of Hospitality and Tourism Management' => 'College of Hospitality and Tourism Management',
            'College of Nursing' => 'College of Nursing',
            'SPCF Miranda' => 'SPCF Miranda',
            'Supreme Student Council' => 'Supreme Student Council',
        ];

        if ($docType === 'proposal') {

            $data['departmentFull'] = $departmentFullMap[$department] ?? $department;
            $data['department'] = $data['departmentFull'];

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // CSC President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['cscPresident'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'CSC President';
            $signatoryIds['cscPresident'] = $cscPresident ? $cscPresident['id'] : null;

            // Adviser (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'CSC Adviser' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['cscAdviser'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'CSC Adviser';
            $signatoryIds['cscAdviser'] = $adviser ? $adviser['id'] : null;

            // SSC President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'SSC President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $ssc = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sscPresident'] = $ssc ? $ssc['first_name'] . ' ' . $ssc['last_name'] : 'SSC President';
            $signatoryIds['sscPresident'] = $ssc ? $ssc['id'] : null;

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['collegeDean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['collegeDean'] = $dean ? $dean['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $templateMap = [
                'College of Arts, Social Sciences, and Education' => '../assets/templates/Project Proposals/College of Arts, Social Sciences, and Education (Project Proposal).docx',
                'College of Business' => '../assets/templates/Project Proposals/College of Business (Project Proposal).docx',
                'College of Computing and Information Sciences' => '../assets/templates/Project Proposals/College of Computing and Information Sciences (Project Proposal).docx',
                'College of Criminology' => '../assets/templates/Project Proposals/College of Criminology (Project Proposal).docx',
                'College of Engineering' => '../assets/templates/Project Proposals/College of Engineering (Project Proposal).docx',
                'College of Hospitality and Tourism Management' => '../assets/templates/Project Proposals/College of Hospitality and Tourism Management (Project Proposal).docx',
                'College of Nursing' => '../assets/templates/Project Proposals/College of Nursing (Project Proposal).docx',
                'SPCF Miranda' => '../assets/templates/Project Proposals/SPCF Miranda (Project Proposal).docx',
                'Supreme Student Council' => '../assets/templates/Project Proposals/Supreme Student Council (Project Proposal).docx',
                'default' => '../assets/templates/Project Proposals/Supreme Student Council (Project Proposal).docx'
            ];
            $templatePath = $templateMap[$department] ?? $templateMap['default'];

            try {
                $filledPath = fillDocxTemplate($templatePath, $data);
                if (!file_exists($filledPath)) {
                    throw new Exception("Filled file not found: $filledPath");
                }
                error_log("DEBUG: DOCX template filled successfully: " . $filledPath);
                // Convert DOCX to PDF
                $pdfPath = convertDocxToPdf($filledPath);
                error_log("DEBUG: PDF conversion result: " . $pdfPath);
                $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                $stmt->execute([$pdfPath, $docId]);
            } catch (Exception $e) {
                error_log("Proposal template filling failed: " . $e->getMessage());
                // Fallback: Do not set file_path, document can still be viewed as HTML
            }
        } elseif ($docType === 'communication') {
            // Map department to template file for communication letters
            $department = $data['department'] ?? '';

            $data['departmentFull'] = $departmentFullMap[$department] ?? $department;
            $data['department'] = $data['departmentFull'];

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // CSC President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['cscPresident'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'CSC President';
            $signatoryIds['cscPresident'] = $cscPresident ? $cscPresident['id'] : null;

            // Adviser (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'CSC Adviser' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['cscAdviser'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'CSC Adviser';
            $signatoryIds['cscAdviser'] = $adviser ? $adviser['id'] : null;

            // SSC President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'SSC President' LIMIT 1");
            $stmt->execute([]);
            $ssc = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sscPresident'] = $ssc ? $ssc['first_name'] . ' ' . $ssc['last_name'] : 'SSC President';
            $signatoryIds['sscPresident'] = $ssc ? $ssc['id'] : null;

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['collegeDean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['collegeDean'] = $dean ? $dean['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $commTemplateMap = [
                'College of Arts, Social Sciences, and Education' => '../assets/templates/Communication Letter/College of Arts, Social Sciences, and Education (Comm Letter).docx',
                'College of Business' => '../assets/templates/Communication Letter/College of Business (Comm Letter).docx',
                'College of Computing and Information Sciences' => '../assets/templates/Communication Letter/College of Computing and Information Sciences (Comm Letter).docx',
                'College of Criminology' => '../assets/templates/Communication Letter/College of Criminology (Comm Letter).docx',
                'College of Engineering' => '../assets/templates/Communication Letter/College of Engineering (Comm Letter).docx',
                'College of Hospitality and Tourism Management' => '../assets/templates/Communication Letter/College of Hospitality and Tourism Management (Comm Letter).docx',
                'College of Nursing' => '../assets/templates/Communication Letter/College of Nursing (Comm Letter).docx',
                'SPCF Miranda' => '../assets/templates/Communication Letter/SPCF Miranda (Comm Letter).docx',
                'Supreme Student Council' => '../assets/templates/Communication Letter/Supreme Student Council (Comm Letter).docx',
                'default' => '../assets/templates/Communication Letter/Supreme Student Council (Comm Letter).docx'
            ];
            $templatePath = $commTemplateMap[$department] ?? $commTemplateMap['default'];
            $data['content'] = $data['body'];

            // Process personnel lists
            if (isset($data['notedList']) && is_array($data['notedList'])) {
                $data['noted'] = implode("\n", array_map(function($p) {
                    return htmlspecialchars($p['name'] . ' - ' . $p['title']);
                }, $data['notedList']));
                unset($data['notedList']);
            }

            if (isset($data['approvedList']) && is_array($data['approvedList'])) {
                $data['approved'] = implode("\n", array_map(function($p) {
                    return htmlspecialchars($p['name'] . ' - ' . $p['title']);
                }, $data['approvedList']));
                unset($data['approvedList']);
            }

            // Clean up unused fields
            unset($data['body']);

            try {
                $filledPath = fillDocxTemplate($templatePath, $data);
                if (!file_exists($filledPath)) {
                    throw new Exception("Filled file not found: $filledPath");
                }
                // Convert DOCX to PDF
                $pdfPath = convertDocxToPdf($filledPath);
                $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                $stmt->execute([$pdfPath, $docId]);
            } catch (Exception $e) {
                error_log("Communication template filling failed: " . $e->getMessage());
                // Fallback: Do not set file_path
            }
        } elseif ($docType === 'saf') {
            $data['departmentFull'] = $departmentFullMap[$department] ?? $department;

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // Get the student's position to determine the shared student representative
            $studentStmt = $db->prepare("SELECT position FROM students WHERE id = ? LIMIT 1");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            $isCscPresident = $student && $student['position'] === 'CSC President';

            // CSC President (Student) - fetched for logic, but not directly added to data
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);

            // SSC President (Student) - fetched for logic, but not directly added to data
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'SSC President' LIMIT 1");
            $stmt->execute([]);
            $ssc = $stmt->fetch(PDO::FETCH_ASSOC);

            // Set the shared student representative placeholder based on creator's role
            if ($isCscPresident && $cscPresident) {
                $signatories['studentRepresentative'] = $cscPresident['first_name'] . ' ' . $cscPresident['last_name'];
                $signatoryIds['studentRepresentative'] = $cscPresident['id'];
            } elseif ($ssc) {
                $signatories['studentRepresentative'] = $ssc['first_name'] . ' ' . $ssc['last_name'];
                $signatoryIds['studentRepresentative'] = $ssc['id'];
            } else {
                $signatories['studentRepresentative'] = 'Student Representative';  // Fallback
                $signatoryIds['studentRepresentative'] = null;
            }

            // Adviser (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'CSC Adviser' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['cscAdviser'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'CSC Adviser';
            $signatoryIds['cscAdviser'] = $adviser ? $adviser['id'] : null;

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['collegeDean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['collegeDean'] = $dean ? $dean['id'] : null;

            // OIC-OSA (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'OIC OSA' LIMIT 1");
            $stmt->execute([]);
            $oicOsa = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['oic-osa'] = $oicOsa ? $oicOsa['first_name'] . ' ' . $oicOsa['last_name'] : 'OIC OSA';
            $signatoryIds['oic-osa'] = $oicOsa ? $oicOsa['id'] : null;

            // VPAA (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'VPAA' LIMIT 1");
            $stmt->execute([]);
            $vpaa = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['vpaa'] = $vpaa ? $vpaa['first_name'] . ' ' . $vpaa['last_name'] : 'VPAA';
            $signatoryIds['vpaa'] = $vpaa ? $vpaa['id'] : null;

            // EVP (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'EVP' LIMIT 1");
            $stmt->execute([]);
            $evp = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['evp'] = $evp ? $evp['first_name'] . ' ' . $evp['last_name'] : 'EVP';
            $signatoryIds['evp'] = $evp ? $evp['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $templatePath = '../assets/templates/SAF/SAF REQUEST.docx';
            if (file_exists($templatePath)) {
                try {
                    $filledPath = fillDocxTemplate($templatePath, $data);
                    // Convert DOCX to PDF
                    $pdfPath = convertDocxToPdf($filledPath);
                    $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                    $stmt->execute([$pdfPath, $docId]);
                } catch (Exception $e) {
                    error_log("SAF template filling failed: " . $e->getMessage());
                }
            }
        } elseif ($docType === 'facility') {
            $data['departmentFull'] = $departmentFullMap[$department] ?? $department;

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // CSC President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['cscPresident'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'CSC President';
            $signatoryIds['cscPresident'] = $cscPresident ? $cscPresident['id'] : null;

            // EVP O (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'EVP O' LIMIT 1");
            $stmt->execute([]);
            $evpO = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['evpO'] = $evpO ? $evpO['first_name'] . ' ' . $evpO['last_name'] : 'EVP O';
            $signatoryIds['evpO'] = $evpO ? $evpO['id'] : null;

            // EVP (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'EVP' LIMIT 1");
            $stmt->execute([]);
            $evp = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['evp'] = $evp ? $evp['first_name'] . ' ' . $evp['last_name'] : 'EVP';
            $signatoryIds['evp'] = $evp ? $evp['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $templatePath = '../assets/templates/Facility Request/FACILITY REQUEST.docx';
            if (file_exists($templatePath)) {
                try {
                    $filledPath = fillDocxTemplate($templatePath, $data);
                    // Convert DOCX to PDF
                    $pdfPath = convertDocxToPdf($filledPath);
                    $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                    $stmt->execute([$pdfPath, $docId]);
                } catch (Exception $e) {
                    error_log("Facility template filling failed: " . $e->getMessage());
                }
            }
        }

        // Create workflow steps based on document type
        if ($docType === 'proposal' || $docType === 'communication') {
            // Full workflow for proposal and communication
            $workflowPositions = [
                ['position' => 'CSC Adviser', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'SSC President', 'table' => 'students', 'department_specific' => false],
                ['position' => 'Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'OIC OSA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'CPAO', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'VPAA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP', 'table' => 'employees', 'department_specific' => false]
            ];

            $stepOrder = 1;
            foreach ($workflowPositions as $wp) {
                $position = $wp['position'];
                $table = $wp['table'];
                $query = "SELECT id FROM {$table} WHERE position = ?";
                $params = [$position];
                if ($wp['department_specific']) {
                    $query .= " AND department = ?";
                    $params[] = $department;
                }
                $query .= " LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $assignee = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($assignee) {
                    $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    if ($table === 'employees') {
                        $stmt->execute([$docId, $stepOrder, $position . ' Approval', $assignee['id'], null]);
                    } else {
                        $stmt->execute([$docId, $stepOrder, $position . ' Approval', null, $assignee['id']]);
                    }
                }
                $stepOrder++;
            }
        } elseif ($docType === 'saf') {
            // Updated SAF workflow: College Dean -> OIC-OSA -> VPAA -> EVP
            $workflowPositions = [
                ['position' => 'Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'OIC OSA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'VPAA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP', 'table' => 'employees', 'department_specific' => false],
            ];

            $stepOrder = 1;
            foreach ($workflowPositions as $wp) {
                $query = "SELECT id FROM {$wp['table']} WHERE position = ?";
                $params = [$wp['position']];
                if ($wp['department_specific']) {
                    $query .= " AND department = ?";
                    $params[] = $department;
                }
                $query .= " LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $assignee = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($assignee) {
                    $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    if ($wp['table'] === 'employees') {
                        $stmt->execute([$docId, $stepOrder, $wp['position'] . ' Approval', $assignee['id'], null]);
                    } else {
                        $stmt->execute([$docId, $stepOrder, $wp['position'] . ' Approval', null, $assignee['id']]);
                    }
                }
                $stepOrder++;
            }
        } elseif ($docType === 'facility') {
            // Updated Facility workflow: College Dean -> OIC-OSA -> EVP O -> EVP
            $workflowPositions = [
                ['position' => 'Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'OIC OSA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP O', 'table' => 'employees', 'department_specific' => false],  // EVP O for EVPO
                ['position' => 'EVP', 'table' => 'employees', 'department_specific' => false],
            ];

            $stepOrder = 1;
            foreach ($workflowPositions as $wp) {
                $query = "SELECT id FROM {$wp['table']} WHERE position = ?";
                $params = [$wp['position']];
                if ($wp['department_specific']) {
                    $query .= " AND department = ?";
                    $params[] = $department;
                }
                $query .= " LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $assignee = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($assignee) {
                    $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    if ($wp['table'] === 'employees') {
                        $stmt->execute([$docId, $stepOrder, $wp['position'] . ' Approval', $assignee['id'], null]);
                    } else {
                        $stmt->execute([$docId, $stepOrder, $wp['position'] . ' Approval', null, $assignee['id']]);
                    }
                }
                $stepOrder++;
            }
        } else {
            // Fallback for other types
            $empStmt = $db->prepare("SELECT id FROM employees LIMIT 1");
            $empStmt->execute();
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            if ($emp) {
                $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, status) VALUES (?, 1, 'Initial Review', ?, 'pending')");
                $stmt->execute([$docId, $emp['id']]);
            } else {
                throw new Exception("No employees found to assign the document step");
            }
        }

        $db->commit();

        echo json_encode(['success' => true, 'document_id' => $docId]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function signDocument($input, $files = null)
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

    // If stepId not provided, infer the current pending step assigned to this user (employee or SSC President student)
    if (!$stepId) {
        if ($currentUser['role'] === 'employee') {
            $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
            $q->execute([$documentId, $currentUser['id']]);
        } elseif ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President') {
            $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_student_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
            $q->execute([$documentId, $currentUser['id']]);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No pending step assigned to you for this document']);
            return;
        }

        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No pending step assigned to you for this document']);
            return;
        }
        $stepId = (int) $row['id'];
    }

    // Get current document info for file path
    $docStmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        return;
    }

    // Handle signed PDF upload
    if (isset($files['signed_pdf']) && $files['signed_pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Archive or delete old file (optional: move to archive folder)
        $oldFilePath = $uploadDir . basename($doc['file_path']);
        if (file_exists($oldFilePath)) {
            $archiveDir = $uploadDir . 'archive/';
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }
            $archivedPath = $archiveDir . basename($oldFilePath) . '.old';
            if (!rename($oldFilePath, $archivedPath)) {
                error_log("Failed to archive old file: $oldFilePath to $archivedPath");
            }
        }

        // Save new signed PDF
        $newFileName = 'signed_doc_' . $documentId . '_' . time() . '.pdf';
        $newPath = $uploadDir . $newFileName;
        if (move_uploaded_file($files['signed_pdf']['tmp_name'], $newPath)) {
            // Update database with new file path (store full relative path)
            $relativePath = '../uploads/' . $newFileName;
            $updateStmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
            $updateStmt->execute([$relativePath, $documentId]);
        } else {
            error_log("Failed to move uploaded file to: $newPath");
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save signed document']);
            return;
        }
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
        if ($currentUser['role'] === 'employee') {
            $stmt = $db->prepare("
                INSERT INTO document_signatures (document_id, step_id, employee_id, status, signed_at, signature_path)
                VALUES (?, ?, ?, 'signed', NOW(), ?)
                ON DUPLICATE KEY UPDATE
                status = 'signed', signed_at = NOW(), signature_path = VALUES(signature_path)
            ");
            $stmt->execute([$documentId, $stepId, $currentUser['id'], $signaturePathForDB]);
        } elseif ($currentUser['role'] === 'student') {
            $stmt = $db->prepare("
                INSERT INTO document_signatures (document_id, step_id, student_id, status, signed_at, signature_path)
                VALUES (?, ?, ?, 'signed', NOW(), ?)
                ON DUPLICATE KEY UPDATE
                status = 'signed', signed_at = NOW(), signature_path = VALUES(signature_path)
            ");
            $stmt->execute([$documentId, $stepId, $currentUser['id'], $signaturePathForDB]);
        }

        // Update document step
        if ($currentUser['role'] === 'employee') {
            $stmt = $db->prepare("
                UPDATE document_steps
                SET status = 'completed', acted_at = NOW(), note = ?
                WHERE id = ? AND assigned_to_employee_id = ?
            ");
            $stmt->execute([$notes, $stepId, $currentUser['id']]);
        } elseif ($currentUser['role'] === 'student') {
            $stmt = $db->prepare("
                UPDATE document_steps
                SET status = 'completed', acted_at = NOW(), note = ?
                WHERE id = ? AND assigned_to_student_id = ?
            ");
            $stmt->execute([$notes, $stepId, $currentUser['id']]);
        }

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
            'message' => 'Document signed successfully. The updated file has been saved and passed to the next signer.',
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

    // If stepId not provided, infer a step assigned to this user (employee or SSC President student)
    if (!$stepId) {
        if ($currentUser['role'] === 'employee') {
            // Prefer a pending step owned by this employee
            $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
            $q->execute([$documentId, $currentUser['id']]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // Otherwise, allow any step assigned to this employee (any status)
                $q2 = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? ORDER BY step_order ASC LIMIT 1");
                $q2->execute([$documentId, $currentUser['id']]);
                $row = $q2->fetch(PDO::FETCH_ASSOC);
            }
        } elseif ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President') {
            // Prefer a pending step owned by this student
            $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_student_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
            $q->execute([$documentId, $currentUser['id']]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // Otherwise, allow any step assigned to this student (any status)
                $q2 = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_student_id = ? ORDER BY step_order ASC LIMIT 1");
                $q2->execute([$documentId, $currentUser['id']]);
                $row = $q2->fetch(PDO::FETCH_ASSOC);
            }
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No step assigned to you for this document']);
            return;
        }

        if (!$row) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No step assigned to you for this document']);
            return;
        }
        $stepId = (int) $row['id'];
    }

    $db->beginTransaction();

    try {
        // Update document signature to rejected
        if ($currentUser['role'] === 'employee') {
            $stmt = $db->prepare("
                INSERT INTO document_signatures (document_id, step_id, employee_id, status, signed_at)
                VALUES (?, ?, ?, 'rejected', NOW())
                ON DUPLICATE KEY UPDATE
                status = 'rejected', signed_at = NOW()
            ");
            $stmt->execute([$documentId, $stepId, $currentUser['id']]);
        } elseif ($currentUser['role'] === 'student') {
            $stmt = $db->prepare("
                INSERT INTO document_signatures (document_id, step_id, student_id, status, signed_at)
                VALUES (?, ?, ?, 'rejected', NOW())
                ON DUPLICATE KEY UPDATE
                status = 'rejected', signed_at = NOW()
            ");
            $stmt->execute([$documentId, $stepId, $currentUser['id']]);
        }

        // Update document step to rejected
        if ($currentUser['role'] === 'employee') {
            $stmt = $db->prepare("
                UPDATE document_steps
                SET status = 'rejected', acted_at = NOW(), note = ?
                WHERE id = ? AND assigned_to_employee_id = ?
            ");
            $stmt->execute([$reason, $stepId, $currentUser['id']]);
        } elseif ($currentUser['role'] === 'student') {
            $stmt = $db->prepare("
                UPDATE document_steps
                SET status = 'rejected', acted_at = NOW(), note = ?
                WHERE id = ? AND assigned_to_student_id = ?
            ");
            $stmt->execute([$reason, $stepId, $currentUser['id']]);
        }

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
            INSERT INTO audit_logs (user_id, action, category, details, target_id, target_type, severity, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $currentUser['id'],
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

        // Use the SAMPLEPDF.pdf for mock preview
        $samplePdfPath = __DIR__ . '/../assets/mock/SAMPLEPDF.pdf';
        if (file_exists($samplePdfPath)) {
            $pdfWebPath = '../assets/mock/SAMPLEPDF.pdf';
        } else {
            // Fallback to FACILITY REQUEST.pdf
            $fallbackPath = __DIR__ . '/../assets/files/FACILITY REQUEST.pdf';
            if (file_exists($fallbackPath)) {
                $pdfWebPath = '../assets/files/FACILITY REQUEST.pdf';
            } else {
                // Write a tiny PDF if fallback not present
                $pdfPathAbs = $mockDir . DIRECTORY_SEPARATOR . 'sample.pdf';
                if (!file_exists($pdfPathAbs)) {
                    $pdfBase64 = 'JVBERi0xLjQKJcTl8uXrp/CgIDAgb2JqCjw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+CmVuZG9iagoyIDAgb2JqCjw8L1R5cGUvUGFnZXMvS2lkc1szIDAgUl0vQ291bnQgMT4+CmVuZG9iagozIDAgb2JqCjw8L1R5cGUvUGFnZS9QYXJlbnQgMiAwIFIvTWVkaWFCb3hbMCAwIDU5NSA4NDJdL0NvbnRlbnRzIDQgMCBSL1Jlc291cmNlczw8L0ZvbnQ8PC9GMCA1IDAgUj4+Pj4+Pj4KZW5kb2JqCjQgMCBvYmoKPDwvTGVuZ3RoIDY3Pj4Kc3RyZWFtCkJUCi9GMCAxMiBUZgoxMDAgNzUwIFRkCihNb2NrIFBERiBmb3IgdGVzdGluZyAmIFNpZ25hdHVyZSBNYXBwaW5nKSBUagoKRVQKZW5kc3RyZWFtCmVuZG9iago1IDAgb2JqCjw8L1R5cGUvRm9udC9TdWJ0eXBlL1R5cGUxL0Jhc2VGb250L0hlbHZldGljYT4+CmVuZG9iagp4cmVmCjAgNgowMDAwMDAwMDAwIDY1NTM1IGYgCjAwMDAwMDAwOTcgMDAwMDAgbiAKMDAwMDAwMDE5NyAwMDAwMCBuIAowMDAwMDAwNDExIDAwMDAwIG4gCjAwMDAwMDAxNDMgMDAwMDAgbiAKMDAwMDAwMDUwMyAwMDAwMCBuIAp0cmFpbGVyCjw8L1NpemUgNi9Sb290IDEgMCBSPj4Kc3RhcnR4cmVmCjcyNQolJUVPRg==';
                    @file_put_contents($pdfPathAbs, base64_decode($pdfBase64));
                }
                $pdfWebPath = '../assets/mock/sample.pdf';
            }
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

function updateNote($input)
{
    global $db, $currentUser;

    // Only employees can update notes
    if ($currentUser['role'] !== 'employee') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only employees can update notes']);
        return;
    }

    $docId = $input['document_id'] ?? '';
    $stepId = $input['step_id'] ?? 0;
    $note = $input['note'] ?? '';

    if (!$docId || !is_numeric($docId) || !$stepId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID and Step ID are required']);
        return;
    }

    try {
        // Check if user is assigned to this step
        $stmt = $db->prepare("SELECT id FROM document_steps WHERE id = ? AND assigned_to_employee_id = ? AND status = 'pending'");
        $stmt->execute([$stepId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized to update this step']);
            return;
        }

        // Update note on the step
        $stmt = $db->prepare("UPDATE document_steps SET note = ? WHERE id = ?");
        $stmt->execute([$note, $stepId]);

        addAuditLog('NOTE_UPDATED', 'Document Management', "Note updated for document $docId step $stepId", $docId, 'Document', 'INFO');

        echo json_encode(['success' => true, 'message' => 'Note updated successfully']);
    } catch (Exception $e) {
        error_log("Error updating note: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update note: ' . $e->getMessage()]);
    }
}

// Seed mock data for testing signature hierarchy
function seedMockData()
{
    global $db, $currentUser;

    // Only admins can seed data
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admins can seed mock data']);
        return;
    }

    $departments = [
        'College of Arts, Social Sciences, and Education',
        'College of Business',
        'College of Computing and Information Sciences',
        'College of Criminology',
        'College of Engineering',
        'College of Hospitality and Tourism Management',
        'College of Nursing',
        'SPCF Miranda'
    ];

    // Proper names for advisers and deans
    $adviserNames = [
        'Dr. Maria Santos',
        'Dr. Jose Reyes',
        'Dr. Ana Garcia',
        'Dr. Roberto Cruz',
        'Dr. Elena Mendoza',
        'Dr. Carlos Fernandez',
        'Dr. Sofia Ramirez',
        'Dr. Miguel Torres'
    ];

    $deanNames = [
        'Dr. Juan dela Cruz',
        'Dr. Patricia Lopez',
        'Dr. Ricardo Bautista',
        'Dr. Carmen Aquino',
        'Dr. Antonio Villanueva',
        'Dr. Rosa Morales',
        'Dr. Eduardo Castillo',
        'Dr. Lourdes Rivera'
    ];

    $cscPresidentNames = [
        'Mark Angelo Reyes',
        'Samantha Louise Cruz',
        'Gabriel Antonio Santos',
        'Isabella Marie Garcia',
        'Rafael Jose Mendoza',
        'Camille Andrea Lopez',
        'Daniel Patrick Fernandez',
        'Sophia Rose Ramirez'
    ];

    try {
        $db->beginTransaction();

        // Seed Employees
        $empIdCounter = 1;

        // CSC Advisers and College Deans per department
        foreach ($departments as $index => $dept) {
            $adviserName = explode(' ', $adviserNames[$index]);
            $deanName = explode(' ', $deanNames[$index]);

            // CSC Adviser
            $empId = 'EMP' . str_pad($empIdCounter++, 3, '0', STR_PAD_LEFT);
            $email = 'adviser.' . strtolower(str_replace([' ', ','], ['.', ''], $dept)) . '@university.edu';
            $stmt = $db->prepare("INSERT INTO employees (id, first_name, last_name, email, password, office, department, position, phone, must_change_password, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                                  ON DUPLICATE KEY UPDATE id=id");
            $stmt->execute([$empId, $adviserName[1], $adviserName[2] ?? $adviserName[1], $email, password_hash('password', PASSWORD_BCRYPT), 'Student Affairs', $dept, 'CSC Adviser', '09123456789']);

            // College Dean
            $empId = 'EMP' . str_pad($empIdCounter++, 3, '0', STR_PAD_LEFT);
            $email = 'dean.' . strtolower(str_replace([' ', ','], ['.', ''], $dept)) . '@university.edu';
            $stmt->execute([$empId, $deanName[1], $deanName[2] ?? $deanName[1], $email, password_hash('password', PASSWORD_BCRYPT), 'Academic Affairs', $dept, 'Dean', '09123456790']);
        }

        // University-wide employees
        $universityEmployees = [
            ['Dr. Francisco Alvarez', 'OIC OSA', 'Office of Student Affairs', 'University', 'Officer-in-Charge, Office of Student Affairs'],
            ['Prof. Regina Bautista', 'CPAO', 'Center for Performing Arts Organization', 'University', 'Center for Performing Arts Organization'],
            ['Dr. Emmanuel Santos', 'VPAA', 'Academic Affairs', 'University', 'Vice President for Academic Affairs'],
            ['Dr. Victoria Mendoza', 'EVP', 'Student Services', 'University', 'Executive Vice-President/Student Services']
        ];

        foreach ($universityEmployees as $emp) {
            $empId = 'EMP' . str_pad($empIdCounter++, 3, '0', STR_PAD_LEFT);
            $email = strtolower(str_replace([' ', ','], ['.', ''], $emp[1])) . '@university.edu';
            $stmt = $db->prepare("INSERT INTO employees (id, first_name, last_name, email, password, office, department, position, phone, must_change_password, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                                  ON DUPLICATE KEY UPDATE id=id");
            $stmt->execute([$empId, explode(' ', $emp[0])[1], explode(' ', $emp[0])[2] ?? explode(' ', $emp[0])[1], $email, password_hash('password', PASSWORD_BCRYPT), $emp[2], $emp[3], $emp[1], '09123456791']);
        }

        // Seed Students
        $stuIdCounter = 1;

        // CSC Presidents per department
        foreach ($departments as $index => $dept) {
            $stuName = explode(' ', $cscPresidentNames[$index]);
            $stuId = 'STU' . str_pad($stuIdCounter++, 3, '0', STR_PAD_LEFT);
            $email = 'csc.president.' . strtolower(str_replace([' ', ','], ['.', ''], $dept)) . '@university.edu';
            $stmt = $db->prepare("INSERT INTO students (id, first_name, last_name, email, password, department, position, phone, must_change_password, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                                  ON DUPLICATE KEY UPDATE id=id");
            $stmt->execute([$stuId, $stuName[0], $stuName[1] . ' ' . ($stuName[2] ?? ''), $email, password_hash('password', PASSWORD_BCRYPT), $dept, 'CSC President', '09123456792']);
        }

        // SSC President
        $stuId = 'STU' . str_pad($stuIdCounter++, 3, '0', STR_PAD_LEFT);
        $email = 'ssc.president@university.edu';
        $stmt = $db->prepare("INSERT INTO students (id, first_name, last_name, email, password, department, position, phone, must_change_password, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                              ON DUPLICATE KEY UPDATE id=id");
        $stmt->execute([$stuId, 'Supreme', 'President', $email, password_hash('password', PASSWORD_BCRYPT), 'Supreme Student Council', 'SSC President', '09123456793']);

        // Seed Admin
        $stmt = $db->prepare("INSERT INTO administrators (id, first_name, last_name, email, password, must_change_password, created_at)
                              VALUES (?, ?, ?, ?, ?, 0, NOW())
                              ON DUPLICATE KEY UPDATE id=id");
        $stmt->execute(['ADM001', 'System', 'Administrator', 'admin@university.edu', password_hash('password', PASSWORD_BCRYPT)]);

        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Mock data seeded successfully']);

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error seeding mock data: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to seed mock data: ' . $e->getMessage()]);
    }
}
?>