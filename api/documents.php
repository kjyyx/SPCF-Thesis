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

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'vendor/autoload.php';

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
    $outputDir = ROOT_PATH . 'uploads/';
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
     */

    global $db, $currentUser;

    try {
        // Enforce timeouts on stale documents before responding
        enforceTimeouts();

        // Return approved documents as calendar events for the frontend
        if (isset($_GET['action']) && $_GET['action'] === 'approved_events') {
            // Only allow employees/admins or student council presidents to fetch approved events
            // but approved events are generally public, so allow all authenticated users
            $stmt = $db->prepare("SELECT id, title, doc_type, department, `date`, implDate, eventDate, uploaded_at, venue, earliest_start_time
                                   FROM documents
                                   WHERE status = 'approved' AND doc_type = 'proposal'
                                   ORDER BY uploaded_at DESC");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $events = [];
            foreach ($rows as $r) {
                // Use the proposal date and earliest start time for calendar events
                $event_date = $r['date'];
                $event_time = $r['earliest_start_time'];
                $venue = $r['venue'];
                $events[] = [
                    'id' => (int) $r['id'],
                    'title' => $r['title'],
                    'doc_type' => $r['doc_type'],
                    'department' => $r['department'],
                    'event_date' => $event_date,
                    'event_time' => $event_time,
                    'venue' => $venue
                ];
            }
            echo json_encode(['success' => true, 'events' => $events]);
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
                    $rejected_location = null;
                    $pending_location = null;
                    foreach ($rows as $stepRow) {
                        if ($stepRow['id'] === $docId) {
                            if ($stepRow['step_status'] === 'rejected') {
                                $rejected_location = $stepRow['step_name'];
                            } elseif ($stepRow['step_status'] === 'pending' || $stepRow['step_status'] === 'in_progress') {
                                if (!$pending_location) {
                                    $pending_location = $stepRow['step_name'];
                                }
                            }
                        }
                    }
                    if ($rejected_location) {
                        $current_location = $rejected_location;
                    } elseif ($pending_location) {
                        $current_location = $pending_location;
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
                           CASE 
                               WHEN n.author_role = 'employee' THEN e.position
                               WHEN n.author_role = 'student' THEN s.position
                               WHEN n.author_role = 'admin' THEN a.position
                               ELSE ''
                           END as position,
                           NULL as step_status
                    FROM document_notes n
                    JOIN documents d ON n.document_id = d.id
                    LEFT JOIN employees e ON n.author_id = e.id AND n.author_role = 'employee'
                    LEFT JOIN students s ON n.author_id = s.id AND n.author_role = 'student'
                    LEFT JOIN administrators a ON n.author_id = a.id AND n.author_role = 'admin'
                    WHERE n.document_id IN ($placeholders)
                    
                    UNION ALL
                    
                    SELECT CONCAT('step_', ds.id) as id, ds.note, ds.acted_at as created_at, ds.document_id,
                           CASE 
                               WHEN ds.assigned_to_employee_id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                               WHEN ds.assigned_to_student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
                               ELSE 'Unknown'
                           END as created_by_name,
                           CASE 
                               WHEN ds.assigned_to_employee_id IS NOT NULL THEN e.position
                               WHEN ds.assigned_to_student_id IS NOT NULL THEN s.position
                               ELSE ''
                           END as position, ds.status as step_status
                    FROM document_steps ds
                    LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                    LEFT JOIN students s ON ds.assigned_to_student_id = s.id
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
                            'position' => $note['position'] ?: '',
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
                SELECT ds.id, ds.status, ds.name, ds.acted_at
                FROM document_steps ds
                WHERE ds.document_id = ?
                ORDER BY ds.step_order ASC
            ");
            $historyStmt->execute([$documentId]);
            $workflow_history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch notes/comments from document_steps
            $notesStmt = $db->prepare("
                SELECT ds.id, ds.note, ds.acted_at as created_at, ds.document_id,
                       CASE
                           WHEN ds.assigned_to_employee_id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                           WHEN ds.assigned_to_student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
                           ELSE 'Unknown'
                       END as created_by_name,
                       ds.status as step_status
                FROM document_steps ds
                LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                LEFT JOIN students s ON ds.assigned_to_student_id = s.id
                WHERE ds.document_id = ? AND ds.note IS NOT NULL AND ds.note != ''
                ORDER BY ds.acted_at ASC
            ");
            $notesStmt->execute([$documentId]);
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
                'updated_at' => $doc['uploaded_at'],
                'description' => $doc['description'],
                'file_path' => $doc['file_path'],
                'workflow_history' => array_map(function ($step) {
                    $timestamp = $step['acted_at'] ?: date('Y-m-d H:i:s');
                    return [
                        'created_at' => $timestamp,
                        'status' => $step['status'] ?: 'pending',
                        'action' => $step['status'] === 'completed' ? 'Approved' : ($step['status'] === 'rejected' ? 'Rejected' : 'Pending'),
                        'office_name' => $step['name'] ?: 'Unknown',
                        'from_office' => $step['name'] ?: 'Unknown'
                    ];
                }, $workflow_history),
                'notes' => array_map(function ($note) {
                    return [
                        'id' => $note['id'] ?: null,
                        'note' => $note['note'] ?: '',
                        'created_by_name' => $note['created_by_name'] ?: 'Unknown',
                        'created_at' => $note['created_at'] ?: date('Y-m-d H:i:s'),
                        'is_rejection' => ($note['step_status'] === 'rejected'),
                        'step_status' => $note['step_status'] ?: 'pending'
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
                    'signature_map' => $s['signature_map'],
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
                // Handle backward compatibility and absolute paths
                if ($filePath && strpos($filePath, 'http') !== 0) {
                    // Check if it's an absolute path (starts with drive letter or /)
                    if (preg_match('/^[A-Za-z]:/', $filePath) || strpos($filePath, '/') === 0) {
                        // For absolute paths, extract filename and assume it's in uploads
                        $filePath = 'uploads/' . basename($filePath);
                    }
                    // For relative paths, ensure they start with uploads/
                    if (strpos($filePath, 'uploads/') !== 0) {
                        $filePath = 'uploads/' . $filePath;
                    }
                    // Convert to full URL
                    $filePath = BASE_URL . $filePath;
                    // URL encode the filename
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

            // Attach signature mappings if available (now per step) - using database column

            // Return as a plain object (notifications.js expects no wrapper)
            echo json_encode($payload);
            return;
        }
        // Get documents assigned to current user (employee or student council president) that need action or were recently completed
        $params = [];
        if ($currentUser['role'] === 'employee') {
            $params = [$currentUser['id'], 'employee', $currentUser['id'], 'student'];
        } elseif ($currentUser['role'] === 'student' && ($currentUser['position'] === 'Supreme Student Council President' || $currentUser['position'] === 'College Student Council President')) {
            $params = [$currentUser['id'], 'employee', $currentUser['id'], 'student'];
        } else {
            // For regular students, show their own documents
            $studentDocumentsQuery = "
                SELECT DISTINCT
                    d.id,
                    d.title,
                    d.doc_type,
                    d.description,
                    d.status,
                    d.current_step,
                    d.uploaded_at,
                    d.date,
                    d.earliest_start_time,
                    s.id as student_id,
                    CONCAT(s.first_name, ' ', s.last_name) as student_name,
                    s.department as student_department,
                    d.file_path,
                    CASE WHEN d.status = 'approved' THEN 1 ELSE 0 END as user_action_completed,
                    ds.id as step_id,
                    ds.step_order,
                    ds.name as step_name,
                    ds.status as step_status,
                    ds.note,
                    ds.acted_at,
                    ds.assigned_to_employee_id,
                    ds.assigned_to_student_id,
                    e.first_name as assignee_first,
                    e.last_name as assignee_last,
                    st.first_name as student_assignee_first,
                    st.last_name as student_assignee_last,
                    dsg.status as signature_status,
                    dsg.signed_at
                FROM documents d
                LEFT JOIN students s ON d.student_id = s.id
                LEFT JOIN document_steps ds ON d.id = ds.document_id
                LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
                LEFT JOIN students st ON ds.assigned_to_student_id = st.id
                LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id
                    AND ((dsg.employee_id = ds.assigned_to_employee_id AND ds.assigned_to_employee_id IS NOT NULL)
                         OR (dsg.student_id = ds.assigned_to_student_id AND ds.assigned_to_student_id IS NOT NULL))
                WHERE d.student_id = ?
                ORDER BY d.uploaded_at DESC, ds.step_order ASC
            ";
            $stmt = $db->prepare($studentDocumentsQuery);
            $stmt->execute([$currentUser['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by document and organize workflow
            $processedDocuments = [];
            foreach ($rows as $row) {
                $docId = $row['id'];
                if (!isset($processedDocuments[$docId])) {
                    $processedDocuments[$docId] = [
                        'id' => (int) $row['id'],
                        'title' => $row['title'],
                        'doc_type' => $row['doc_type'],
                        'description' => $row['description'],
                        'status' => $row['status'],
                        'current_step' => (int) $row['current_step'],
                        'uploaded_at' => $row['uploaded_at'],
                        'date' => $row['date'],
                        'earliest_start_time' => $row['earliest_start_time'],
                        'student' => [
                            'id' => $row['student_id'],
                            'name' => $row['student_name'],
                            'department' => $row['student_department']
                        ],
                        'file_path' => $row['file_path'],
                        'workflow' => [],
                        'user_action_completed' => (int) ($row['user_action_completed'] ?? 0)
                    ];
                }
                // Add step if exists
                if ($row['step_order']) {
                    $processedDocuments[$docId]['workflow'][] = [
                        'id' => (int) $row['step_id'],
                        'name' => $row['step_name'],
                        'status' => $row['step_status'],
                        'order' => (int) $row['step_order'],
                        'assigned_to' => $row['assigned_to_employee_id'] ?: $row['assigned_to_student_id'],
                        'assignee_name' => $row['assigned_to_employee_id'] ?
                            trim(($row['assignee_first'] ?? '') . ' ' . ($row['assignee_last'] ?? '')) :
                            trim(($row['student_assignee_first'] ?? '') . ' ' . ($row['student_assignee_last'] ?? '')),
                        'note' => $row['note'],
                        'acted_at' => $row['acted_at'],
                        'signature_status' => $row['signature_status'],
                        'signed_at' => $row['signed_at']
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'documents' => array_values($processedDocuments)
            ]);
            return;
        }

        // For employees: Show pending documents assigned to them + all approved documents
        $pendingQuery = "
            SELECT DISTINCT
                d.id,
                d.title,
                d.doc_type,
                d.description,
                d.status,
                d.current_step,
                d.uploaded_at,
                d.date,
                d.earliest_start_time,
                s.id as student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                s.department as student_department,
                d.file_path,
                NULL as user_action_completed,
                ds.id as step_id,
                ds.step_order,
                ds.name as step_name,
                ds.status as step_status,
                ds.note,
                ds.acted_at,
                ds.assigned_to_employee_id,
                ds.assigned_to_student_id,
                e.first_name as assignee_first,
                e.last_name as assignee_last,
                st.first_name as student_assignee_first,
                st.last_name as student_assignee_last,
                dsg.status as signature_status,
                dsg.signed_at
            FROM documents d
            LEFT JOIN students s ON d.student_id = s.id
            JOIN document_steps ds ON d.id = ds.document_id
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            LEFT JOIN students st ON ds.assigned_to_student_id = st.id
            LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id
                AND ((dsg.employee_id = ds.assigned_to_employee_id AND ds.assigned_to_employee_id IS NOT NULL)
                     OR (dsg.student_id = ds.assigned_to_student_id AND ds.assigned_to_student_id IS NOT NULL))
            WHERE ds.status = 'pending'
            AND (
                (ds.assigned_to_employee_id = ? AND ? = 'employee')
                OR (ds.assigned_to_student_id = ? AND ? = 'student')
            )
            AND NOT EXISTS (
                SELECT 1 FROM document_steps ds_prev
                WHERE ds_prev.document_id = ds.document_id
                AND ds_prev.step_order < ds.step_order
                AND ds_prev.status = 'pending'
            )
        ";
        $stmt = $db->prepare($pendingQuery);
        $stmt->execute($params);
        $pendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Query for approved documents (all approved documents for employees)
        $approvedQuery = "
            SELECT DISTINCT
                d.id,
                d.title,
                d.doc_type,
                d.description,
                d.status,
                d.current_step,
                d.uploaded_at,
                d.date,
                d.earliest_start_time,
                s.id as student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                s.department as student_department,
                d.file_path,
                1 as user_action_completed,
                ds.id as step_id,
                ds.step_order,
                ds.name as step_name,
                ds.status as step_status,
                ds.note,
                ds.signature_map,
                ds.acted_at,
                ds.assigned_to_employee_id,
                ds.assigned_to_student_id,
                e.first_name as assignee_first,
                e.last_name as assignee_last,
                st.first_name as student_assignee_first,
                st.last_name as student_assignee_last,
                dsg.status as signature_status,
                dsg.signed_at
            FROM documents d
            LEFT JOIN students s ON d.student_id = s.id
            JOIN document_steps ds ON d.id = ds.document_id
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            LEFT JOIN students st ON ds.assigned_to_student_id = st.id
            LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id
                AND ((dsg.employee_id = ds.assigned_to_employee_id AND ds.assigned_to_employee_id IS NOT NULL)
                     OR (dsg.student_id = ds.assigned_to_student_id AND ds.assigned_to_student_id IS NOT NULL))
            WHERE d.status = 'approved'
        ";
        $stmt2 = $db->prepare($approvedQuery);
        $stmt2->execute();
        $approvedRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Merge rows
        $rows = array_merge($pendingRows, $approvedRows);

        // Group by document and organize workflow
        $processedDocuments = [];
        foreach ($rows as $row) {
            $docId = $row['id'];
            if (!isset($processedDocuments[$docId])) {
                $processedDocuments[$docId] = [
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                    'doc_type' => $row['doc_type'],
                    'description' => $row['description'],
                    'status' => $row['status'],
                    'current_step' => (int) $row['current_step'],
                    'uploaded_at' => $row['uploaded_at'],
                    'date' => $row['date'],
                    'earliest_start_time' => $row['earliest_start_time'],
                    'student' => [
                        'id' => $row['student_id'],
                        'name' => $row['student_name'],
                        'department' => $row['student_department']
                    ],
                    'file_path' => $row['file_path'],
                    'workflow' => [],
                    'user_action_completed' => (int) ($row['user_action_completed'] ?? 0)
                ];
            }
            // Add step if exists
            if ($row['step_order']) {
                $processedDocuments[$docId]['workflow'][] = [
                    'id' => (int) $row['step_id'],
                    'name' => $row['step_name'],
                    'status' => $row['step_status'],
                    'order' => (int) $row['step_order'],
                    'assigned_to' => $row['assigned_to_employee_id'] ?: $row['assigned_to_student_id'],
                    'assignee_name' => $row['assigned_to_employee_id'] ?
                        trim(($row['assignee_first'] ?? '') . ' ' . ($row['assignee_last'] ?? '')) :
                        trim(($row['student_assignee_first'] ?? '') . ' ' . ($row['student_assignee_last'] ?? '')),
                    'note' => $row['note'],
                    'acted_at' => $row['acted_at'],
                    'signature_status' => $row['signature_status'],
                    'signed_at' => $row['signed_at']
                ];
            }
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

function updateNote($input)
{
    global $db, $currentUser;

    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $note = trim($input['note'] ?? '');

    error_log("updateNote called with document_id: $documentId, step_id: $stepId, note: '$note'");

    if (!$documentId || !$stepId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }

    // Check if the current user is assigned to this step (security check)
    $checkStmt = $db->prepare("SELECT id FROM document_steps WHERE id = ? AND (assigned_to_employee_id = ? OR assigned_to_student_id = ?)");
    $checkStmt->execute([$stepId, $currentUser['id'], $currentUser['id']]);
    $stepExists = $checkStmt->fetch();
    if (!$stepExists) {
        error_log("Authorization failed for user {$currentUser['id']} on step $stepId");
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized to update this note']);
        return;
    }

    error_log("Authorization passed, proceeding with update");

    // Update the note in document_steps
    $stmt = $db->prepare("UPDATE document_steps SET note = ? WHERE id = ?");
    $result = $stmt->execute([$note, $stepId]);
    $affectedRows = $stmt->rowCount();

    error_log("Update executed, result: $result, affected rows: $affectedRows");

    // Verify the step still exists after update
    $verifyStmt = $db->prepare("SELECT id FROM document_steps WHERE id = ?");
    $verifyStmt->execute([$stepId]);
    if ($verifyStmt->fetch()) {
        echo json_encode(['success' => true]);
    } else {
        error_log("Step verification failed after update");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update note']);
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
    global $db, $currentUser, $auth;

    // Allow both SSC and CSC Presidents to create documents
    if (
        $currentUser['role'] !== 'student' ||
        !in_array($currentUser['position'], [
            'Supreme Student Council President',
            'College Student Council President'
        ])
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only student council presidents can create documents']);
        return;
    }

    $docType = $input['doc_type'] ?? '';
    $studentId = $currentUser['id'];
    $data = $input['data'] ?? [];

    if (!$docType || !$studentId || !$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }

    // SERVER-SIDE VALIDATION: Check required fields before any processing
    if ($docType === 'proposal') {
        $required = ['title', 'date', 'organizer', 'venue', 'department', 'leadFacilitator'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Required field '$field' is missing"]);
                return;
            }
        }
        if (empty($data['objectives']) || !is_array($data['objectives']) || count($data['objectives']) === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'At least one objective is required']);
            return;
        }
    } elseif ($docType === 'saf') {
        $required = ['title', 'reqDate', 'implDate', 'department'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Required field '$field' is missing"]);
                return;
            }
        }
        // Validate that checked categories have amounts > 0
        if (isset($data['c1']) && $data['reqSSC'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'SSC fund amount must be greater than 0 when selected']);
            return;
        }
        if (isset($data['c2']) && $data['reqCSC'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'CSC fund amount must be greater than 0 when selected']);
            return;
        }
        // Ensure at least one category is selected
        if (!isset($data['c1']) && !isset($data['c2'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'At least one fund category must be selected']);
            return;
        }
    } elseif ($docType === 'facility') {
        $required = ['eventName', 'eventDate', 'department'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Required field '$field' is missing"]);
                return;
            }
        }
    } elseif ($docType === 'communication') {
        $required = ['subject', 'body', 'date', 'department'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Required field '$field' is missing"]);
                return;
            }
        }
        if (empty($data['notedList']) || !is_array($data['notedList']) || count($data['notedList']) === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'At least one recipient is required']);
            return;
        }
        if (empty($data['approvedList']) || !is_array($data['approvedList']) || count($data['approvedList']) === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'At least one sender is required']);
            return;
        }
    }

    // Fetch fresh user data to ensure latest names
    $freshUser = $auth->getUser($currentUser['id'], $currentUser['role']);
    $currentUser = $freshUser;

    try {
        $db->beginTransaction();

        // Get department
        $department = $data['department'] ?? '';

        // Department full names
        $departmentFullMap = [
            'College of Arts, Social Sciences and Education' => 'College of Arts, Social Sciences, and Education',
            'College of Business' => 'College of Business',
            'College of Computing and Information Sciences' => 'College of Computing and Information Sciences',
            'College of Criminology' => 'College of Criminology',
            'College of Engineering' => 'College of Engineering',
            'College of Hospitality and Tourism Management' => 'College of Hospitality and Tourism Management',
            'College of Nursing' => 'College of Nursing',
            'SPCF Miranda' => 'SPCF Miranda',
            'Supreme Student Council (SSC)' => 'Supreme Student Council (SSC)',
        ];

        $departmentFull = $departmentFullMap[$department] ?? $department;
        $data['departmentFull'] = $departmentFull;
        $date = null;
        $implDate = null;
        $eventName = null;
        $eventDate = null;
        $venue = null;
        $scheduleSummary = null;
        $earliestStartTime = null;

        if ($docType === 'proposal') {
            $date = $data['date'] ?? null;
            $venue = $data['venue'] ?? null;
            $scheduleSummary = $data['scheduleSummary'] ?? null;
            $earliestStartTime = $data['earliestStartTime'] ?? null;
        } elseif ($docType === 'saf') {
            $implDate = $data['implDate'] ?? null;
        } elseif ($docType === 'facility') {
            $eventName = $data['eventName'] ?? null;
            $eventDate = $data['eventDate'] ?? null;
        } elseif ($docType === 'communication') {
            $date = $data['date'] ?? null;
        }

        // Insert document
        $stmt = $db->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, current_step, uploaded_at, department, departmentFull, date, implDate, eventName, eventDate, venue, schedule_summary, earliest_start_time, data) VALUES (?, ?, ?, ?, 'submitted', 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$studentId, $docType, $data['title'] ?? 'Untitled', $data['rationale'] ?? '', $department, $departmentFull, $date, $implDate, $eventName, $eventDate, $venue, $scheduleSummary, $earliestStartTime, json_encode($data)]);

        $docId = (int) $db->lastInsertId();

        if ($docType === 'proposal') {

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // College Student Council President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'College Student Council President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_cscp'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'College Student Council President';
            $signatoryIds['sig_cscp'] = $cscPresident ? $cscPresident['id'] : null;

            // Adviser (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Student Council Adviser' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_csca'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'College Student Council Adviser';
            $signatoryIds['sig_csca'] = $adviser ? $adviser['id'] : null;

            // Supreme Student Council President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'Supreme Student Council President' LIMIT 1");
            $stmt->execute([]);
            $ssc = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_sscp'] = $ssc ? $ssc['first_name'] . ' ' . $ssc['last_name'] : 'Supreme Student Council President';
            $signatoryIds['sig_sscp'] = $ssc ? $ssc['id'] : null;

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_dean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['sig_dean'] = $dean ? $dean['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $templateMap = [
                'College of Arts, Social Sciences and Education' => ROOT_PATH . 'assets/templates/Project Proposals/College of Arts, Social Sciences, and Education (Project Proposal).docx',
                'College of Business' => ROOT_PATH . 'assets/templates/Project Proposals/College of Business (Project Proposal).docx',
                'College of Computing and Information Sciences' => ROOT_PATH . 'assets/templates/Project Proposals/College of Computing and Information Sciences (Project Proposal).docx',
                'College of Criminology' => ROOT_PATH . 'assets/templates/Project Proposals/College of Criminology (Project Proposal).docx',
                'College of Engineering' => ROOT_PATH . 'assets/templates/Project Proposals/College of Engineering (Project Proposal).docx',
                'College of Hospitality and Tourism Management' => ROOT_PATH . 'assets/templates/Project Proposals/College of Hospitality and Tourism Management (Project Proposal).docx',
                'College of Nursing' => ROOT_PATH . 'assets/templates/Project Proposals/College of Nursing (Project Proposal).docx',
                'SPCF Miranda' => ROOT_PATH . 'assets/templates/Project Proposals/SPCF Miranda (Project Proposal).docx',
                'Supreme Student Council (SSC)' => ROOT_PATH . 'assets/templates/Project Proposals/Supreme Student Council (Project Proposal).docx',
                'default' => ROOT_PATH . 'assets/templates/Project Proposals/Supreme Student Council (Project Proposal).docx'
            ];
            $templatePath = $templateMap[$department] ?? $templateMap['default'];

            try {
                $filledPath = fillDocxTemplate($templatePath, $data);
                if (!file_exists($filledPath)) {
                    throw new Exception("Filled file not found: $filledPath");
                }
                // Convert DOCX to PDF
                $pdfPath = convertDocxToPdf($filledPath);
                $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                $stmt->execute(['uploads/' . basename($pdfPath), $docId]);
            } catch (Exception $e) {
                error_log("Proposal template filling failed: " . $e->getMessage());
                // Fallback: Do not set file_path, document can still be viewed as HTML
            }
        } elseif ($docType === 'communication') {
            // Map department to template file for communication letters
            $department = $data['department'] ?? '';

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // College Student Council President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'College Student Council President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_cscp'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'College Student Council President';
            $signatoryIds['sig_cscp'] = $cscPresident ? $cscPresident['id'] : null;

            // Adviser (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Student Council Adviser' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_csca'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'College Student Council Adviser';
            $signatoryIds['sig_csca'] = $adviser ? $adviser['id'] : null;

            // Supreme Student Council President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'Supreme Student Council President' LIMIT 1");
            $stmt->execute([]);
            $ssc = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_sscp'] = $ssc ? $ssc['first_name'] . ' ' . $ssc['last_name'] : 'Supreme Student Council President';
            $signatoryIds['sig_sscp'] = $ssc ? $ssc['id'] : null;

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_dean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['sig_dean'] = $dean ? $dean['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $commTemplateMap = [
                'College of Arts, Social Sciences and Education' => ROOT_PATH . 'assets/templates/Communication Letter/College of Arts, Social Sciences, and Education (Comm Letter).docx',
                'College of Business' => ROOT_PATH . 'assets/templates/Communication Letter/College of Business (Comm Letter).docx',
                'College of Computing and Information Sciences' => ROOT_PATH . 'assets/templates/Communication Letter/College of Computing and Information Sciences (Comm Letter).docx',
                'College of Criminology' => ROOT_PATH . 'assets/templates/Communication Letter/College of Criminology (Comm Letter).docx',
                'College of Engineering' => ROOT_PATH . 'assets/templates/Communication Letter/College of Engineering (Comm Letter).docx',
                'College of Hospitality and Tourism Management' => ROOT_PATH . 'assets/templates/Communication Letter/College of Hospitality and Tourism Management (Comm Letter).docx',
                'College of Nursing' => ROOT_PATH . 'assets/templates/Communication Letter/College of Nursing (Comm Letter).docx',
                'SPCF Miranda' => ROOT_PATH . 'assets/templates/Communication Letter/SPCF Miranda (Comm Letter).docx',
                'Supreme Student Council (SSC)' => ROOT_PATH . 'assets/templates/Communication Letter/Supreme Student Council (Comm Letter).docx',
                'default' => ROOT_PATH . 'assets/templates/Communication Letter/Supreme Student Council (Comm Letter).docx'
            ];
            $templatePath = $commTemplateMap[$department] ?? $commTemplateMap['default'];
            $data['content'] = $data['body'];

            // Process personnel lists
            if (isset($data['notedList']) && is_array($data['notedList'])) {
                $data['noted'] = implode("\n", array_map(function ($p) {
                    return htmlspecialchars($p['name'] . ' - ' . $p['title']);
                }, $data['notedList']));
                unset($data['notedList']);
            }

            if (isset($data['approvedList']) && is_array($data['approvedList'])) {
                $data['approved'] = implode("\n", array_map(function ($p) {
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
                $stmt->execute(['uploads/' . basename($pdfPath), $docId]);
            } catch (Exception $e) {
                error_log("Communication template filling failed: " . $e->getMessage());
                // Fallback: Do not set file_path
            }
        } elseif ($docType === 'saf') {

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // Get the student's position to determine the shared student representative
            $studentStmt = $db->prepare("SELECT position FROM students WHERE id = ? LIMIT 1");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            $isCscPresident = $student && $student['position'] === 'College Student Council President';

            // College Student Council President (Student) - fetched for logic, but not directly added to data
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'College Student Council President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);

            // Supreme Student Council President (Student) - fetched for logic, but not directly added to data
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'Supreme Student Council President' LIMIT 1");
            $stmt->execute([]);
            $ssc = $stmt->fetch(PDO::FETCH_ASSOC);

            // Set the shared student representative placeholder based on creator's role
            if ($isCscPresident && $cscPresident) {
                $signatories['sig_rep'] = $cscPresident['first_name'] . ' ' . $cscPresident['last_name'];
                $signatoryIds['sig_rep'] = $cscPresident['id'];
            } elseif ($ssc) {
                $signatories['sig_rep'] = $ssc['first_name'] . ' ' . $ssc['last_name'];
                $signatoryIds['sig_rep'] = $ssc['id'];
            } else {
                $signatories['sig_rep'] = 'Student Representative';  // Fallback
                $signatoryIds['sig_rep'] = null;
            }

            // Adviser (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Student Council Adviser' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_csca'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'College Student Council Adviser';
            $signatoryIds['sig_csca'] = $adviser ? $adviser['id'] : null;

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_dean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['sig_dean'] = $dean ? $dean['id'] : null;

            // OIC-OSA (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)' LIMIT 1");
            $stmt->execute([]);
            $oicOsa = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_oic'] = $oicOsa ? $oicOsa['first_name'] . ' ' . $oicOsa['last_name'] : 'OIC OSA';
            $signatoryIds['sig_oic'] = $oicOsa ? $oicOsa['id'] : null;

            // VPAA (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Vice President for Academic Affairs (VPAA)' LIMIT 1");
            $stmt->execute([]);
            $vpaa = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_vpaa'] = $vpaa ? $vpaa['first_name'] . ' ' . $vpaa['last_name'] : 'VPAA';
            $signatoryIds['sig_vpaa'] = $vpaa ? $vpaa['id'] : null;

            // EVP (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Executive Vice-President / Student Services (EVP)' LIMIT 1");
            $stmt->execute([]);
            $evp = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_evp'] = $evp ? $evp['first_name'] . ' ' . $evp['last_name'] : 'EVP';
            $signatoryIds['sig_evp'] = $evp ? $evp['id'] : null;

            // Fetch Accounting Personnel
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position  = 'Accounting Personnel (AP)' LIMIT 1");
            $stmt->execute([]);
            $accountingPersonnel = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_ap'] = $accountingPersonnel ? $accountingPersonnel['first_name'] . ' ' . $accountingPersonnel['last_name'] : 'Accounting Personnel';
            $signatoryIds['sig_ap'] = $accountingPersonnel ? $accountingPersonnel['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);
            $data['reqByName'] = $currentUser['first_name'] . ' ' . $currentUser['last_name'];

            $templatePath = ROOT_PATH . 'assets/templates/SAF/SAF REQUEST.docx';
            if (file_exists($templatePath)) {
                try {
                    $filledPath = fillDocxTemplate($templatePath, $data);
                    // Convert DOCX to PDF
                    $pdfPath = convertDocxToPdf($filledPath);
                    $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                    $stmt->execute(['uploads/' . basename($pdfPath), $docId]);
                } catch (Exception $e) {
                    error_log("SAF template filling failed: " . $e->getMessage());
                }
            }
        } elseif ($docType === 'facility') {

            // Fetch signatories based on department
            $signatories = [];
            $signatoryIds = [];

            // College Student Council President (Student)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'College Student Council President' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_cscp'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'College Student Council President';
            $signatoryIds['sig_cscp'] = $cscPresident ? $cscPresident['id'] : null;

            // PPFO (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Physical Plant and Facilities Office (PPFO)' LIMIT 1");
            $stmt->execute([]);
            $ppfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_ppfo'] = $ppfo ? $ppfo['first_name'] . ' ' . $ppfo['last_name'] : 'PPFO';
            $signatoryIds['sig_ppfo'] = $ppfo ? $ppfo['id'] : null;

            // EVP (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Executive Vice-President / Student Services (EVP)' LIMIT 1");
            $stmt->execute([]);
            $evp = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_evp'] = $evp ? $evp['first_name'] . ' ' . $evp['last_name'] : 'EVP';
            $signatoryIds['sig_evp'] = $evp ? $evp['id'] : null;

            // Add signatories to data
            $data = array_merge($data, $signatories);

            $templatePath = ROOT_PATH . 'assets/templates/Facility Request/FACILITY REQUEST.docx';
            if (file_exists($templatePath)) {
                try {
                    $filledPath = fillDocxTemplate($templatePath, $data);
                    // Convert DOCX to PDF
                    $pdfPath = convertDocxToPdf($filledPath);
                    $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                    $stmt->execute(['uploads/' . basename($pdfPath), $docId]);
                } catch (Exception $e) {
                    error_log("Facility template filling failed: " . $e->getMessage());
                }
            }
        }

        // Ensure file_path is set for document tracking
        $checkStmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
        $checkStmt->execute([$docId]);
        $existingFilePath = $checkStmt->fetchColumn();
        if (!$existingFilePath) {
            // Set a fallback file_path for tracking purposes
            $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
            $stmt->execute(['uploads/fallback.pdf', $docId]);
        }

        // Create workflow steps based on document type
        // Always add the creator as the first signatory/step
        $stepOrder = 1;
        $creatorIsStudent = ($currentUser['role'] === 'student');
        $creatorName = $currentUser['first_name'] . ' ' . $currentUser['last_name'];
        $creatorStepName = 'Document Creator Signature';
        if ($creatorIsStudent) {
            $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$docId, $stepOrder, $creatorStepName, null, $currentUser['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$docId, $stepOrder, $creatorStepName, $currentUser['id'], null]);
        }
        $stepOrder++;

        // Define workflow positions for each doctype
        if ($docType === 'proposal') {
            $workflowPositions = [
                ['position' => 'College Student Council Adviser', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Supreme Student Council President', 'table' => 'students', 'department_specific' => false],
                ['position' => 'College Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Center for Performing Arts Organization (CPAO)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Vice President for Academic Affairs (VPAA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'department_specific' => false],
                // Documentation only after EVP
            ];
            $approvalEndPosition = 'Executive Vice-President / Student Services (EVP)';
        } elseif ($docType === 'communication') {
            $workflowPositions = [
                ['position' => 'College Student Council Adviser', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Supreme Student Council President', 'table' => 'students', 'department_specific' => false],
                ['position' => 'College Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Vice President for Academic Affairs (VPAA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'department_specific' => false],
            ];
            $approvalEndPosition = 'Executive Vice-President / Student Services (EVP)';
        } elseif ($docType === 'saf') {
            $workflowPositions = [
                ['position' => 'College Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Vice President for Academic Affairs (VPAA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'department_specific' => false],
                // Documentation only after EVP
                ['position' => 'Accounting Personnel (AP)', 'table' => 'employees', 'department_specific' => false, 'documentation_only' => true],
            ];
            $approvalEndPosition = 'Executive Vice-President / Student Services (EVP)';
        } elseif ($docType === 'facility') {
            $workflowPositions = [
                ['position' => 'College Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Physical Plant and Facilities Office (PPFO)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'department_specific' => false],
            ];
            $approvalEndPosition = 'Executive Vice-President / Student Services (EVP)';
        } else {
            // Fallback for other types
            $empStmt = $db->prepare("SELECT id FROM employees LIMIT 1");
            $empStmt->execute();
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            if ($emp) {
                $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, status) VALUES (?, ?, 'Initial Review', ?, 'pending')");
                $stmt->execute([$docId, $emp['id']]);
            } else {
                throw new Exception("No employees found to assign the document step");
            }
            $workflowPositions = [];
            $approvalEndPosition = null;
        }

        // Add workflow steps, marking those after EVP as documentation only
        $approvalReached = false;
        $isFirstWorkflowStep = true;
        foreach ($workflowPositions as $wp) {
            $position = $wp['position'];
            $table = $wp['table'];
            $documentationOnly = isset($wp['documentation_only']) && $wp['documentation_only'];
            $query = "SELECT id FROM {$table} WHERE position = ?";
            $params = [$position];
            if (isset($wp['department_specific']) && $wp['department_specific']) {
                $query .= " AND department = ?";
                $params[] = $department;
            }
            $query .= " LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $assignee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($assignee) {
                $stepName = $position . ' Approval';
                // If we've reached EVP, mark all subsequent steps as documentation only
                if ($approvalReached || $documentationOnly) {
                    $stepName = $position . ' (Documentation Only)';
                }
                // Only the first workflow step should be pending initially
                $initialStatus = $isFirstWorkflowStep ? 'pending' : 'skipped';
                $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, ?)");
                if ($table === 'employees') {
                    $stmt->execute([$docId, $stepOrder, $stepName, $assignee['id'], null, $initialStatus]);
                } else {
                    $stmt->execute([$docId, $stepOrder, $stepName, null, $assignee['id'], $initialStatus]);
                }
                $isFirstWorkflowStep = false;
            }
            if ($position === $approvalEndPosition) {
                $approvalReached = true;
            }
            $stepOrder++;
        }

        $db->commit();

        // Add audit log
        addAuditLog(
            'DOCUMENT_CREATED',
            'Document Management',
            "Document created by {$currentUser['first_name']} {$currentUser['last_name']}: {$docType} - " . (isset($data['title']) ? $data['title'] : 'Untitled'),
            $docId,
            'Document',
            'INFO'
        );

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

    // Decode signature_map if it's a JSON string (from FormData)
    if ($signatureMap && !is_array($signatureMap) && is_string($signatureMap)) {
        $signatureMap = json_decode($signatureMap, true);
    }

    if (!$documentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID is required']);
        return;
    }

    // If stepId not provided, infer the current pending step assigned to this user (employee or SSC/College Student Council President student)
    if (!$stepId) {
        if ($currentUser['role'] === 'employee') {
            $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
            $q->execute([$documentId, $currentUser['id']]);
        } elseif ($currentUser['role'] === 'student' && ($currentUser['position'] === 'Supreme Student Council President' || $currentUser['position'] === 'College Student Council President')) {
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

    // HIERARCHY ENFORCEMENT: Ensure no previous steps are pending or skipped (not yet activated)
    $hierarchyCheckStmt = $db->prepare("
        SELECT COUNT(*) as pending_previous
        FROM document_steps
        WHERE document_id = ? AND step_order < (
            SELECT step_order FROM document_steps WHERE id = ?
        ) AND status NOT IN ('completed', 'skipped')
    ");
    $hierarchyCheckStmt->execute([$documentId, $stepId]);
    $hierarchyResult = $hierarchyCheckStmt->fetch(PDO::FETCH_ASSOC);

    if ($hierarchyResult['pending_previous'] > 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cannot sign this step. Previous steps must be completed first.']);
        return;
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

    // Handle signed PDF upload - only archive old file if NOT fully approved yet
    if (isset($files['signed_pdf']) && $files['signed_pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = ROOT_PATH . 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Check if all steps are completed AFTER this sign
        $progressStmt = $db->prepare("
            SELECT COUNT(*) as total_steps,
                   COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_steps
            FROM document_steps
            WHERE document_id = ?
        ");
        $progressStmt->execute([$documentId]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);
        $isFullyApproved = ($progress['total_steps'] == $progress['completed_steps']);

        if (!$isFullyApproved) {
            // Archive old file only if new file is successfully saved
            // Moved archive logic after successful upload
        }

        // Save new signed PDF
        $newFileName = 'signed_doc_' . $documentId . '_' . time() . '.pdf';
        $newPath = $uploadDir . $newFileName;
        if (move_uploaded_file($files['signed_pdf']['tmp_name'], $newPath)) {
            // Archive old file after successful save
            if (!$isFullyApproved) {
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
            }

            // Update database with new file path (store relative path)
            $relativePath = 'uploads/' . $newFileName;
            $updateStmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
            $updateStmt->execute([$relativePath, $documentId]);
        } else {
            error_log("Failed to move uploaded file to: $newPath");
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save signed document']);
            return;
        }
    }

    $maxRetries = 3;
    $retryCount = 0;
    $isFullyApproved = false;
    $signedSuccessfully = false;

    do {
        try {
            $db->beginTransaction();

            // Update document signature
            if ($currentUser['role'] === 'employee') {
                $stmt = $db->prepare("
                    INSERT INTO document_signatures (document_id, step_id, employee_id, status, signed_at)
                    VALUES (?, ?, ?, 'signed', NOW())
                    ON DUPLICATE KEY UPDATE
                    status = 'signed', signed_at = NOW()
                ");
                $stmt->execute([$documentId, $stepId, $currentUser['id']]);
            } elseif ($currentUser['role'] === 'student') {
                $stmt = $db->prepare("
                    INSERT INTO document_signatures (document_id, step_id, student_id, status, signed_at)
                    VALUES (?, ?, ?, 'signed', NOW())
                    ON DUPLICATE KEY UPDATE
                    status = 'signed', signed_at = NOW()
                ");
                $stmt->execute([$documentId, $stepId, $currentUser['id']]);
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

            // Persist signature mapping (percent-based) to database
            if ($signatureMap && $stepId) {
                $stmt = $db->prepare("UPDATE document_steps SET signature_map = ? WHERE id = ?");
                $stmt->execute([json_encode($signatureMap), $stepId]);
            }

            // Fallback: if the role-restricted update did not affect any rows (e.g., assignment mismatch),
            // ensure the step is still marked completed because the signature record was created above.
            try {
                if (isset($stmt) && $stmt->rowCount() === 0) {
                    $fallback = $db->prepare("UPDATE document_steps SET status = 'completed', acted_at = NOW(), note = ? WHERE id = ?");
                    $fallback->execute([$notes, $stepId]);
                    error_log("Fallback: marked step $stepId completed for document $documentId (role-restricted update affected 0 rows)");
                }
            } catch (Exception $e) {
                // Log but do not fail the signing operation
                error_log('Fallback step update failed: ' . $e->getMessage());
            }

            // Activate the next step in the workflow
            $stepOrderStmt = $db->prepare("SELECT step_order FROM document_steps WHERE id = ?");
            $stepOrderStmt->execute([$stepId]);
            $currentStep = $stepOrderStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($currentStep) {
                $nextStepOrder = $currentStep['step_order'] + 1;
                $activateNextStmt = $db->prepare("
                    UPDATE document_steps 
                    SET status = 'pending' 
                    WHERE document_id = ? AND step_order = ? AND status = 'skipped'
                ");
                $activateNextStmt->execute([$documentId, $nextStepOrder]);
            }

            // Update dates for SAF documents
            $docStmt = $db->prepare("SELECT doc_type, data FROM documents WHERE id = ?");
            $docStmt->execute([$documentId]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            if ($doc && $doc['doc_type'] === 'saf') {
                $data = json_decode($doc['data'], true);
                if ($data) {
                    // Ensure departmentFull is set
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
                    $dept = $data['department'] ?? '';
                    $data['departmentFull'] = $departmentFullMap[$dept] ?? $dept;

                    $stepStmt = $db->prepare("SELECT name FROM document_steps WHERE id = ?");
                    $stepStmt->execute([$stepId]);
                    $step = $stepStmt->fetch(PDO::FETCH_ASSOC);
                    $stepName = $step['name'];
                    $dateField = '';
                    $nameField = '';
                    if ($stepName === 'OIC OSA Approval') {
                        $dateField = 'dNoteDate';
                        $nameField = 'notedBy';
                    } elseif ($stepName === 'VPAA Approval') {
                        $dateField = 'recDate';
                        $nameField = 'recBy';
                    } elseif ($stepName === 'EVP Approval') {
                        $dateField = 'appDate';
                        $nameField = 'appBy';
                    }
                    if ($dateField) {
                        $data[$dateField] = date('Y-m-d');
                    }
                    if ($nameField) {
                        // Get the current approver's name
                        $approverStmt = $db->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
                        $approverStmt->execute([$currentUser['id']]);
                        $approver = $approverStmt->fetch(PDO::FETCH_ASSOC);
                        if ($approver) {
                            $data[$nameField] = $approver['first_name'] . ' ' . $approver['last_name'];
                        }
                    }
                    // Update data without re-converting to save CloudConvert credits
                    $updateStmt = $db->prepare("UPDATE documents SET data = ? WHERE id = ?");
                    $updateStmt->execute([json_encode($data), $documentId]);
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
                $isFullyApproved = true;
                // All steps completed, update document status
                $stmt = $db->prepare("
                    UPDATE documents
                    SET status = 'approved', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$documentId]);

                // Set releaseDate for SAF documents
                $docStmt = $db->prepare("SELECT doc_type, data FROM documents WHERE id = ?");
                $docStmt->execute([$documentId]);
                $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
                if ($doc && $doc['doc_type'] === 'saf') {
                    $data = json_decode($doc['data'], true);
                    if ($data) {
                        // Ensure departmentFull is set
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
                        $dept = $data['department'] ?? '';
                        $data['departmentFull'] = $departmentFullMap[$dept] ?? $dept;

                        $data['releaseDate'] = date('Y-m-d');
                        // Get the accounting officer's name for relBy
                        $accountingStmt = $db->prepare("SELECT first_name, last_name FROM employees WHERE position = 'Accounting Officer' LIMIT 1");
                        $accountingStmt->execute([]);
                        $accounting = $accountingStmt->fetch(PDO::FETCH_ASSOC);
                        if ($accounting) {
                            $data['relBy'] = $accounting['first_name'] . ' ' . $accounting['last_name'];
                        }
                        // Update data without re-converting to save CloudConvert credits
                        $updateStmt = $db->prepare("UPDATE documents SET data = ? WHERE id = ?");
                        $updateStmt->execute([json_encode($data), $documentId]);
                    }
                }

                // Deduct SAF funds if fully approved SAF document
                if ($doc['doc_type'] === 'saf') {
                    $data = json_decode($doc['data'], true);
                    if ($data) {
                        $reqSSC = $data['reqSSC'] ?? 0;
                        $reqCSC = $data['reqCSC'] ?? 0;
                        $department = $data['department']; // This is the CSC department

                        // Prepare transaction statement
                        $transStmt = $db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date) VALUES (?, 'deduct', ?, ?, NOW())");

                        // Deduct SSC funds from 'ssc' department
                        if ($reqSSC > 0) {
                            $updateStmt = $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ? WHERE department_id = ?");
                            $updateStmt->execute([$reqSSC, 'ssc']);

                            // Add transaction record for SSC
                            $transStmt->execute(['ssc', $reqSSC, "SAF Request (SSC): " . ($data['title'] ?? 'Untitled')]);

                            // Audit log for SSC fund deduction
                            addAuditLog(
                                'SAF_DEDUCTED',
                                'SAF Management',
                                "Deducted ₱{$reqSSC} from SSC SAF balance for approved document",
                                $documentId,
                                'Document',
                                'INFO'
                            );
                        }

                        // Deduct CSC funds from selected department
                        if ($reqCSC > 0 && $department) {
                            $updateStmt = $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ? WHERE department_id = ?");
                            $updateStmt->execute([$reqCSC, $department]);

                            // Add transaction record for CSC
                            $transStmt->execute([$department, $reqCSC, "SAF Request (CSC): " . ($data['title'] ?? 'Untitled')]);

                            // Audit log for CSC fund deduction
                            addAuditLog(
                                'SAF_DEDUCTED',
                                'SAF Management',
                                "Deducted ₱{$reqCSC} from {$department} SAF balance for approved document",
                                $documentId,
                                'Document',
                                'INFO'
                            );
                        }
                    }
                }
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
            $signedSuccessfully = true;

            break;

        } catch (Exception $e) {
            $db->rollBack();
            if (strpos($e->getMessage(), 'Lock wait timeout') !== false && $retryCount < $maxRetries) {
                $retryCount++;
                sleep(1);
                continue;
            }
            throw $e;
        }
    } while ($retryCount < $maxRetries);

    // Send response immediately
    if ($signedSuccessfully) {
        echo json_encode([
            'success' => true,
            'message' => 'Document signed successfully. The updated file has been saved and passed to the next signer.',
            'step_id' => $stepId
        ]);
    }

    // Handle event creation asynchronously after response
    if ($signedSuccessfully && $isFullyApproved) {
        ignore_user_abort(true);  // Continue even if client disconnects
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();  // Send response immediately if using FastCGI
        }

        $docStmt = $db->prepare("SELECT doc_type, title, department, departmentFull, date, venue, earliest_start_time FROM documents WHERE id = ?");
        $docStmt->execute([$documentId]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

        if ($doc && $doc['doc_type'] === 'proposal') {
            $eventTitle = $doc['title'];
            $eventDate = $doc['date'];
            $venue = $doc['venue'];
            $eventTime = $doc['earliest_start_time'];

            if ($eventTitle && $eventDate) {
                // Check if event already exists for this document to avoid duplicates
                $stmt = $db->prepare("SELECT id FROM events WHERE title = ? AND event_date = ? LIMIT 1");
                $stmt->execute([$eventTitle, $eventDate]);
                $existingEvent = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingEvent) {
                    // Create event via internal API call (use cURL to POST to api/events.php)
                    $eventData = [
                        'title' => $eventTitle,
                        'event_date' => $eventDate,
                        'venue' => $venue,
                        'event_time' => $eventTime,
                        'department' => $doc['department'],
                        'description' => $venue, // Use venue as description
                        'approved' => 1  // Mark as approved
                    ];

                    // Use cURL to POST to api/events.php (assuming session context is available)
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'http://localhost/SPCF-Thesis/api/events.php'); // Adjust URL as needed
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Cookie: ' . session_name() . '=' . session_id() // Pass session for auth
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // 10-second timeout to prevent hanging
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);  // 5-second connection timeout
                    $response = curl_exec($ch);
                    $curlError = curl_error($ch);
                    curl_close($ch);

                    if ($curlError) {
                        error_log("CURL error creating event for document $documentId: $curlError");
                    } else {
                        $result = json_decode($response, true);
                        if (!$result || !$result['success']) {
                            error_log("Failed to create calendar event for document ID $documentId: " . ($result['message'] ?? 'Unknown error'));
                        }
                    }
                }
            }
        }
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

    // If stepId not provided, infer a step assigned to this user (employee or student council president)
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
        } elseif ($currentUser['role'] === 'student' && ($currentUser['position'] === 'Supreme Student Council President' || $currentUser['position'] === 'College Student Council President')) {
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
        $stmt = $db->prepare("UPDATE documents SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$documentId]);

        // Archive the file for rejected documents to optimize storage
        $docStmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
        $docStmt->execute([$documentId]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        if ($doc && $doc['file_path']) {
            $uploadDir = ROOT_PATH . 'uploads/';
            $oldFilePath = $uploadDir . basename($doc['file_path']);
            if (file_exists($oldFilePath)) {
                $archiveDir = $uploadDir . 'archive/';
                if (!is_dir($archiveDir)) {
                    mkdir($archiveDir, 0755, true);
                }
                $archivedPath = $archiveDir . basename($oldFilePath) . '.rejected';
                if (!rename($oldFilePath, $archivedPath)) {
                    error_log("Failed to archive rejected file: $oldFilePath to $archivedPath");
                }
            }
        }

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
            INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $_SERVER['REMOTE_ADDR'] ?? null,
            null, // Set user_agent to null to avoid storing PII
            $severity
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}
?>