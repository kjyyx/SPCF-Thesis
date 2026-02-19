<?php
// IMMEDIATE DEBUG - FIRST LINE
error_log("=== DOCUMENTS.PHP STARTED ===");
error_log("Time: " . date('Y-m-d H:i:s'));
error_log("Script: " . __FILE__);

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

// TEMPORARY DEBUGGING - Add at the very top
error_log("=== DOCUMENTS.PHP REQUEST START ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
$rawInput = file_get_contents('php://input');
error_log("Raw input length: " . strlen($rawInput));
error_log("Raw input: " . substr($rawInput, 0, 500)); // First 500 chars only

// Check PHP limits
error_log("Memory limit: " . ini_get('memory_limit'));
error_log("Max execution time: " . ini_get('max_execution_time'));
error_log("Current memory usage: " . memory_get_usage(true) / 1024 / 1024 . " MB");

header('Content-Type: application/json');

// Global exception handler for JSON responses
set_exception_handler(function($e) {
    error_log("Uncaught exception in documents.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
    exit;
});

// Global error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error in documents.php [$errno]: $errstr in $errfile on line $errline");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $errstr
    ]);
    exit;
});

// Shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null) {
        error_log("Fatal error in documents.php: " . print_r($error, true));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'message' => 'Fatal error: ' . $error['message']
        ]);
    }
});

// TEMPORARY TEST - Add after includes
if (isset($_GET['test'])) {
    header('Content-Type: text/plain');
    echo "Testing document creation with minimal data...\n";
    
    $testData = [
        'date' => '2024-01-01',
        'department' => 'College of Engineering',
        'for' => 'Test Recipient',
        'subject' => 'Test Subject',
        'body' => 'Test Body',
        'notedList' => [
            ['name' => 'Test Noted', 'title' => 'Test Title']
        ],
        'approvedList' => [
            ['name' => 'Test Approved', 'title' => 'Test Title']
        ]
    ];
    
    echo "Test data: " . print_r($testData, true) . "\n";
    echo "JSON encoded: " . json_encode($testData) . "\n";
    
    // Test template filling directly
    try {
        $templatePath = ROOT_PATH . 'assets/templates/Communication Letter/College of Engineering (Communication Letter).docx';
        echo "Template path: $templatePath\n";
        echo "Template exists: " . (file_exists($templatePath) ? 'YES' : 'NO') . "\n";
        
        // Test fillDocxTemplate
        $result = fillDocxTemplate($templatePath, $testData);
        echo "fillDocxTemplate result: $result\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit;
}

// Add this at the top for debugging
error_log("=== Starting documents.php ===");
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST data: " . print_r($_POST, true));
    error_log("Raw input: " . file_get_contents('php://input'));
}

// Add this function after existing includes/requires
function fillDocxTemplate($templatePath, $data)
{
    // ADD THIS DEBUGGING CODE
    error_log("=== fillDocxTemplate START ===");
    error_log("Template path: " . $templatePath);
    error_log("Data keys: " . implode(', ', array_keys($data)));
    
    // Log the structure of notedList and approvedList specifically
    if (isset($data['notedList'])) {
        error_log("notedList type: " . gettype($data['notedList']));
        error_log("notedList content: " . print_r($data['notedList'], true));
    }
    if (isset($data['approvedList'])) {
        error_log("approvedList type: " . gettype($data['approvedList']));
        error_log("approvedList content: " . print_r($data['approvedList'], true));
    }
    
    if (!file_exists($templatePath)) {
        throw new Exception("Template file not found: $templatePath");
    }
    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

    // Handle repeating blocks for noted and approved
    if (isset($data['notedList']) && is_array($data['notedList']) && count($data['notedList']) > 0) {
        // Clone the block for each person
        $templateProcessor->cloneBlock('noted', count($data['notedList']), true, true);

        // Set values for each cloned block with bold name and italic title
        foreach ($data['notedList'] as $index => $person) {
            $num = $index + 1;
            $templateProcessor->setValue('noted_name#' . $num, '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($person['name'])); // Bold name
            $templateProcessor->setValue('noted_title#' . $num, '<w:rPr><w:i/></w:rPr>' . htmlspecialchars($person['title']) . "\n\n"); // Italic title with spacing
        }
    } else {
        // If no noted people, remove the entire block
        $templateProcessor->cloneBlock('noted', 0, true, true);
    }

    if (isset($data['approvedList']) && is_array($data['approvedList']) && count($data['approvedList']) > 0) {
        // Clone the block for each person
        $templateProcessor->cloneBlock('approved', count($data['approvedList']), true, true);

        // Set values for each cloned block with bold name and italic title
        foreach ($data['approvedList'] as $index => $person) {
            $num = $index + 1;
            $templateProcessor->setValue('approved_name#' . $num, '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($person['name'])); // Bold name
            $templateProcessor->setValue('approved_title#' . $num, '<w:rPr><w:i/></w:rPr>' . htmlspecialchars($person['title']) . "\n\n"); // Italic title with spacing
        }
    } else {
        // If no approved people, remove the entire block
        $templateProcessor->cloneBlock('approved', 0, true, true);
    }

    // Remove the list arrays from data to prevent double processing
    unset($data['notedList'], $data['approvedList']);

    // Special handling for "from" field - format with bold name and italic title
    if (isset($data['from']) && isset($data['from_title'])) {
        $formattedFrom = '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($data['from']) . "\n" . '<w:rPr><w:i/></w:rPr>' . htmlspecialchars($data['from_title']); // Bold name, italic title
        $templateProcessor->setValue('from', $formattedFrom);
        unset($data['from'], $data['from_title']);
    }

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
    // Use title in filename for better identification
    $title = $data['title'] ?? $data['eventName'] ?? $data['subject'] ?? 'Untitled';
    $safeTitle = preg_replace('/[^A-Za-z0-9\-_]/', '_', $title);
    $safeTitle = substr($safeTitle, 0, 30); // Shorter limit for initial files
    $outputPath = $outputDir . 'doc_' . $safeTitle . '_' . uniqid() . '.docx';
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
                SELECT ds.id, ds.status, ds.name, ds.acted_at, ds.signature_map
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
                        'from_office' => $step['name'] ?: 'Unknown',
                        'signature_map' => $step['signature_map']
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
        $userId = $currentUser['id'];
        $isEmployee = ($currentUser['role'] === 'employee');
        $isStudentCouncil = ($currentUser['role'] === 'student' && ($currentUser['position'] === 'Supreme Student Council President' || $currentUser['position'] === 'College Student Council President'));
        
        if (!$isEmployee && !$isStudentCouncil) {
            // For regular students, show documents assigned to them with pending signatures
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
                    CASE WHEN d.status IN ('approved', 'rejected') THEN 1
                         WHEN ds.status = 'pending' AND ds.assigned_to_student_id = ? THEN 0
                         ELSE 1 END as user_action_completed,
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
                WHERE EXISTS (
                    SELECT 1 FROM document_steps ds_student
                    WHERE ds_student.document_id = d.id
                    AND ds_student.assigned_to_student_id = ?
                )
                ORDER BY d.uploaded_at DESC, ds.step_order ASC
            ";
            $stmt = $db->prepare($studentDocumentsQuery);
            $stmt->execute([$currentUser['id'], $currentUser['id']]);
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

        // Check if user is Accounting personnel - they should only see SAF documents
        $isAccounting = ($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false);
        $docTypeFilter = $isAccounting ? "AND d.doc_type = 'saf'" : "";

        // For employees and student council presidents: Show all documents assigned to them (past and present)
        $userId = $currentUser['id'];
        $isEmployee = ($currentUser['role'] === 'employee');
        $isStudentCouncil = ($currentUser['role'] === 'student' && ($currentUser['position'] === 'Supreme Student Council President' || $currentUser['position'] === 'College Student Council President'));
        
        $assignmentCondition = $isEmployee ? "ds_employee.assigned_to_employee_id = ?" : "ds_employee.assigned_to_student_id = ?";
        $pendingCheckCondition = $isEmployee ? "ds_check.assigned_to_employee_id = ?" : "ds_check.assigned_to_student_id = ?";
        
        $employeeQuery = "
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
                CASE WHEN d.status IN ('approved', 'rejected') THEN 1
                     WHEN EXISTS (
                         SELECT 1 FROM document_steps ds_check
                         WHERE ds_check.document_id = d.id
                         AND {$pendingCheckCondition}
                         AND ds_check.status = 'pending'
                     ) THEN 0
                     ELSE 1 END as user_action_completed,
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
            WHERE EXISTS (
                SELECT 1 FROM document_steps ds_employee
                WHERE ds_employee.document_id = d.id
                AND {$assignmentCondition}
            )
            {$docTypeFilter}
            ORDER BY d.uploaded_at DESC, ds.step_order ASC
        ";
        $stmt = $db->prepare($employeeQuery);
        $stmt->execute([$userId, $userId]);
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
                try {
                    createDocument($input);
                } catch (Exception $e) {
                    error_log("Error in createDocument: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to create document: ' . $e->getMessage()]);
                }
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
        $required = ['title', 'date', 'organizer', 'venue', 'department', 'lead'];
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
        if (!empty($data['c1']) && $data['reqSSC'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'SSC fund amount must be greater than 0 when selected']);
            return;
        }
        if (!empty($data['c2']) && $data['reqCSC'] <= 0) {
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
        $required = ['subject', 'body', 'date', 'department', 'for'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Required field '$field' is missing"]);
                return;
            }
        }
        if (isset($data['notedList']) && is_array($data['notedList'])) {
            $data['notedList'] = array_map(function ($item) {
                if (is_string($item)) {
                    // If it's a string, parse it (backward compatibility)
                    $decoded = json_decode($item, true);
                    return $decoded ? $decoded : ['name' => $item, 'title' => ''];
                }
                return $item;
            }, $data['notedList']);
        }

        if (isset($data['approvedList']) && is_array($data['approvedList'])) {
            $data['approvedList'] = array_map(function ($item) {
                if (is_string($item)) {
                    $decoded = json_decode($item, true);
                    return $decoded ? $decoded : ['name' => $item, 'title' => ''];
                }
                return $item;
            }, $data['approvedList']);
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

        // Sanitize all date and field values based on document type
        $date = null;
        $implDate = null;
        $eventName = null;
        $eventDate = null;
        $venue = null;
        $scheduleSummary = null;
        $earliestStartTime = null;
        $description = '';

        if ($docType === 'proposal') {
            $date = !empty($data['date']) ? $data['date'] : null;
            $venue = !empty($data['venue']) ? $data['venue'] : null;
            $scheduleSummary = !empty($data['scheduleSummary']) ? $data['scheduleSummary'] : null;
            $earliestStartTime = !empty($data['earliestStartTime']) ? $data['earliestStartTime'] : null;
            $description = $data['rationale'] ?? '';
        } elseif ($docType === 'saf') {
            $implDate = !empty($data['implDate']) ? $data['implDate'] : null;
            $date = !empty($data['reqDate']) ? $data['reqDate'] : null;
            $description = $data['title'] ?? '';
        } elseif ($docType === 'facility') {
            $eventName = !empty($data['eventName']) ? $data['eventName'] : null;
            $eventDate = !empty($data['eventDate']) ? $data['eventDate'] : null;
            $description = $data['eventName'] ?? '';
        } elseif ($docType === 'communication') {
            $date = !empty($data['date']) ? $data['date'] : null;
            // Explicitly set other date fields to null for communication
            $implDate = null;
            $eventDate = null;
            $eventName = null;
            $venue = null;
            $scheduleSummary = null;
            $earliestStartTime = null;
            $description = $data['subject'] ?? '';
        }

        // Ensure description is never empty for NOT NULL columns
        if ($docType === 'communication') {
            $description = !empty($data['subject']) ? $data['subject'] : 'Communication Letter';
        }

        // Insert document
        $stmt = $db->prepare("INSERT INTO documents (
    student_id, doc_type, title, description, status, current_step, uploaded_at,
    department, departmentFull, date, implDate, eventName, eventDate, venue,
    schedule_summary, earliest_start_time, data
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Build parameters array
        // Set title based on document type
        if ($docType === 'facility') {
            $title = $data['eventName'] ?? 'Untitled';
        } elseif ($docType === 'communication') {
            $title = $data['subject'] ?? 'Untitled';
        } else {
            $title = $data['title'] ?? 'Untitled';
        }
        
        $params = [
            $studentId,
            $docType,
            $title,
            $description,
            'submitted',
            1,
            date('Y-m-d H:i:s'),
            $department,
            $departmentFull,
            $date,
            $implDate,
            $eventName,
            $eventDate,
            $venue,
            $scheduleSummary,
            $earliestStartTime,
            json_encode($data)
        ];

        // DEBUG: Log everything
        error_log("=== INSERT DEBUG ===");
        error_log("Document Type: " . $docType);
        error_log("Number of parameters: " . count($params));
        error_log("Parameters array: " . print_r($params, true));

        // Check for null values in NOT NULL columns
        $not_null_columns = ['student_id', 'doc_type', 'title', 'status', 'current_step', 'uploaded_at'];
        foreach ($not_null_columns as $index => $col) {
            if ($params[$index] === null || $params[$index] === '') {
                error_log("ERROR: $col is null or empty!");
            }
        }

        // Execute with error checking
        try {
            $result = $stmt->execute($params);
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                error_log("SQL Error: " . print_r($errorInfo, true));
                throw new Exception("SQL Error: " . $errorInfo[2]);
            }
        } catch (Exception $e) {
            error_log("Exception during INSERT: " . $e->getMessage());
            throw $e;
        }

        $docId = (int) $db->lastInsertId();
        error_log("Document inserted successfully with ID: " . $docId);

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
            error_log("=== Creating COMMUNICATION letter ===");
            error_log("Department: " . ($data['department'] ?? 'NOT SET'));
            error_log("notedList count: " . count($data['notedList'] ?? []));
            error_log("approvedList count: " . count($data['approvedList'] ?? []));
            
            // Log the first noted person if exists
            if (!empty($data['notedList'])) {
                error_log("First noted person: " . print_r($data['notedList'][0], true));
            }
            
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
                'College of Arts, Social Sciences and Education' => ROOT_PATH . 'assets/templates/Communication Letter/College of Arts, Social Sciences, and Education (Communication Letter).docx',
                'College of Business' => ROOT_PATH . 'assets/templates/Communication Letter/College of Business (Communication Letter).docx',
                'College of Computing and Information Sciences' => ROOT_PATH . 'assets/templates/Communication Letter/College of Computing and Information Sciences (Communication Letter).docx',
                'College of Criminology' => ROOT_PATH . 'assets/templates/Communication Letter/College of Criminology (Communication Letter).docx',
                'College of Engineering' => ROOT_PATH . 'assets/templates/Communication Letter/College of Engineering (Communication Letter).docx',
                'College of Hospitality and Tourism Management' => ROOT_PATH . 'assets/templates/Communication Letter/College of Hospitality and Tourism Management (Communication Letter).docx',
                'College of Nursing' => ROOT_PATH . 'assets/templates/Communication Letter/College of Nursing (Communication Letter).docx',
                'SPCF Miranda' => ROOT_PATH . 'assets/templates/Communication Letter/SPCF Miranda (Communication Letter).docx',
                'Supreme Student Council (SSC)' => ROOT_PATH . 'assets/templates/Communication Letter/Supreme Student Council (Communication Letter).docx',
                'default' => ROOT_PATH . 'assets/templates/Communication Letter/Supreme Student Council (Communication Letter).docx'
            ];
            $templatePath = $commTemplateMap[$department] ?? $commTemplateMap['default'];
            $data['for'] = $data['for'] ?? '';
            $data['from'] = $currentUser['first_name'] . ' ' . $currentUser['last_name'];
            $data['from_title'] = $currentUser['position'] . ', ' . $currentUser['department'];

            // Keep notedList and approvedList for block cloning
            // Clean up unused fields
            // Do not unset 'body' as it's used in template

            try {
                $filledPath = fillDocxTemplate($templatePath, $data);
                error_log("fillDocxTemplate successful, path: " . $filledPath);

                if (!file_exists($filledPath)) {
                    throw new Exception("Filled file not found: $filledPath");
                }

                // Convert DOCX to PDF
                $pdfPath = convertDocxToPdf($filledPath);
                error_log("convertDocxToPdf successful, path: " . $pdfPath);

                $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                $stmt->execute([basename($pdfPath), $docId]);
                error_log("Database updated with file_path");

            } catch (Exception $e) {
                error_log("Communication template filling failed: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
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

            // College Dean (Employee)
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'College Dean' AND department = ? LIMIT 1");
            $stmt->execute([$department]);
            $dean = $stmt->fetch(PDO::FETCH_ASSOC);
            $signatories['sig_dean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
            $signatoryIds['sig_dean'] = $dean ? $dean['id'] : null;

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
                ['position' => 'College Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'Supreme Student Council President', 'table' => 'students', 'department_specific' => false],
                ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Center for Performing Arts Organization (CPAO)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Vice President for Academic Affairs (VPAA)', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'department_specific' => false],
                // Documentation only after EVP
            ];
            $approvalEndPosition = 'Executive Vice-President / Student Services (EVP)';
        } elseif ($docType === 'communication') {
            error_log("=== Creating COMMUNICATION workflow ===");
            // Dynamic workflow based on selected notedList and approvedList
            $selectedPositions = [];

            // Parse notedList and approvedList for positions
            $allLists = array_merge($data['notedList'] ?? [], $data['approvedList'] ?? []);
            error_log("All lists combined: " . print_r($allLists, true));
            foreach ($allLists as $person) {
                if (isset($person['title'])) {
                    // Extract position from title (e.g., "College Dean, College of Engineering" -> "College Dean")
                    $parts = explode(',', $person['title'], 2);
                    $position = trim($parts[0]);
                    $department = isset($parts[1]) ? trim($parts[1]) : null;
                    $selectedPositions[$position] = $department; // Use department if specified
                    error_log("Extracted position: '$position', department: '$department'");
                }
            }

            error_log("Selected positions: " . print_r($selectedPositions, true));

            // Define complete hierarchy order (same as Proposal for consistency)
            $hierarchyOrder = [
                'College Student Council Adviser',
                'College Dean',
                'Supreme Student Council President',
                'Officer-in-Charge, Office of Student Affairs (OIC-OSA)',
                'Center for Performing Arts Organization (CPAO)',
                'Vice President for Academic Affairs (VPAA)',
                'Executive Vice-President / Student Services (EVP)'
            ];

            // Sort selected positions by hierarchy
            $sortedPositions = [];
            foreach ($hierarchyOrder as $pos) {
                if (isset($selectedPositions[$pos])) {
                    $sortedPositions[$pos] = $selectedPositions[$pos];
                }
            }

            error_log("Sorted positions: " . print_r($sortedPositions, true));

            // Create workflow steps for each position
            foreach ($sortedPositions as $position => $dept) {
                $table = ($position === 'Supreme Student Council President') ? 'students' : 'employees';
                $query = "SELECT id FROM {$table} WHERE position = ?";
                $params = [$position];
                if ($dept && $table === 'employees') {
                    $query .= " AND department = ?";
                    $params[] = $dept;
                }
                error_log("Querying for position '$position' in table '$table' with params: " . print_r($params, true));
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Found " . count($assignees) . " assignees for position '$position'");

                foreach ($assignees as $assignee) {
                    $stepName = $position . ' Approval';
                    error_log("Creating step: $stepName for assignee ID: " . $assignee['id']);
                    $stmt = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($table === 'employees') {
                        $stmt->execute([$docId, $stepOrder, $stepName, $assignee['id'], null, 'skipped']);
                    } else {
                        $stmt->execute([$docId, $stepOrder, $stepName, null, $assignee['id'], 'skipped']);
                    }
                    $stepOrder++;
                }
            }

            error_log("Total steps created: " . ($stepOrder - 1));

            // Activate the first step
            $firstStepStmt = $db->prepare("UPDATE document_steps SET status = 'pending' WHERE document_id = ? AND step_order = 1");
            $result = $firstStepStmt->execute([$docId]);
            error_log("Activated first step, affected rows: " . $firstStepStmt->rowCount());

            // Check if current user needs to sign immediately
            $needsSigning = false;
            $firstStepQuery = $db->prepare("SELECT assigned_to_employee_id, assigned_to_student_id FROM document_steps WHERE document_id = ? AND step_order = 1");
            $firstStepQuery->execute([$docId]);
            $firstStep = $firstStepQuery->fetch(PDO::FETCH_ASSOC);
            error_log("First step assignee: " . print_r($firstStep, true));
            if ($firstStep) {
                if ($currentUser['role'] === 'employee' && $firstStep['assigned_to_employee_id'] == $currentUser['id']) {
                    $needsSigning = true;
                } elseif ($currentUser['role'] === 'student' && $firstStep['assigned_to_student_id'] == $currentUser['id']) {
                    $needsSigning = true;
                }
            }
            error_log("Needs signing: " . ($needsSigning ? 'YES' : 'NO'));

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
                $stmt->execute([$docId, $stepOrder, $emp['id']]);
                $stepOrder++;
            } else {
                throw new Exception("No employees found to assign the document step");
            }
            $workflowPositions = [];
            $approvalEndPosition = null;
        }

        if ($docType !== 'communication') {
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

            // Activate the first step
            $firstStepStmt = $db->prepare("UPDATE document_steps SET status = 'pending' WHERE document_id = ? AND step_order = 1");
            $firstStepStmt->execute([$docId]);

            // Check if current user needs to sign immediately
            $needsSigning = false;
            $firstStepQuery = $db->prepare("SELECT assigned_to_employee_id, assigned_to_student_id FROM document_steps WHERE document_id = ? AND step_order = 1");
            $firstStepQuery->execute([$docId]);
            $firstStep = $firstStepQuery->fetch(PDO::FETCH_ASSOC);
            if ($firstStep) {
                if ($currentUser['role'] === 'employee' && $firstStep['assigned_to_employee_id'] == $currentUser['id']) {
                    $needsSigning = true;
                } elseif ($currentUser['role'] === 'student' && $firstStep['assigned_to_student_id'] == $currentUser['id']) {
                    $needsSigning = true;
                }
            }
        }

        error_log("=== COMMITTING TRANSACTION ===");
        $db->commit();
        error_log("=== TRANSACTION COMMITTED SUCCESSFULLY ===");

        // Add audit log
        addAuditLog(
            'DOCUMENT_CREATED',
            'Document Management',
            "Document created by {$currentUser['first_name']} {$currentUser['last_name']}: {$docType} - " . (isset($data['title']) ? $data['title'] : 'Untitled'),
            $docId,
            'Document',
            'INFO'
        );
        error_log("=== AUDIT LOG ADDED ===");

        echo json_encode(['success' => true, 'document_id' => $docId, 'needs_signing' => $needsSigning]);
        error_log("=== RESPONSE SENT SUCCESSFULLY ===");
        error_log("Response: " . json_encode(['success' => true, 'document_id' => $docId, 'needs_signing' => $needsSigning]));

    } catch (Exception $e) {
        $db->rollBack();
        error_log("=== EXCEPTION IN CREATEDOCUMENT ===");
        error_log("Exception: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
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
        // Fetch document title for filename
        $docStmt = $db->prepare("SELECT title FROM documents WHERE id = ?");
        $docStmt->execute([$documentId]);
        $docData = $docStmt->fetch(PDO::FETCH_ASSOC);
        $title = $docData['title'] ?? 'Untitled';
        
        // Sanitize the title for safe filename usage
        $safeTitle = preg_replace('/[^A-Za-z0-9\-_]/', '_', $title);
        $safeTitle = substr($safeTitle, 0, 50); // Limit to 50 characters
        
        $newFileName = $safeTitle . '_' . $documentId . '_' . time() . '.pdf';
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
                    $archiveName = 'archived_' . basename($oldFilePath) . '_' . time();
                    $archivedPath = $archiveDir . $archiveName;
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

            // Check if this is EVP approval for SAF document - deduct funds immediately
            $stepStmt = $db->prepare("SELECT name FROM document_steps WHERE id = ?");
            $stepStmt->execute([$stepId]);
            $currentStep = $stepStmt->fetch(PDO::FETCH_ASSOC);
            $docStmt = $db->prepare("SELECT doc_type, data FROM documents WHERE id = ?");
            $docStmt->execute([$documentId]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            if ($doc && $doc['doc_type'] === 'saf' && $currentStep && strpos($currentStep['name'], 'EVP') !== false) {
                // Check if funds have already been deducted for this document
                $existingTransStmt = $db->prepare("SELECT COUNT(*) as count FROM saf_transactions WHERE transaction_description LIKE ?");
                $existingTransStmt->execute(["%Document ID: {$documentId}%"]);
                $existingTrans = $existingTransStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingTrans['count'] > 0) {
                    // Funds already deducted, skip
                    addAuditLog(
                        'SAF_DEDUCT_SKIPPED',
                        'SAF Management',
                        "SAF fund deduction skipped - already processed for document",
                        $documentId,
                        'Document',
                        'INFO'
                    );
                    return ['success' => true, 'message' => 'Document approved successfully'];
                }

                $data = json_decode($doc['data'], true);
                if ($data) {
                    $reqSSC = $data['reqSSC'] ?? 0;
                    $reqCSC = $data['reqCSC'] ?? 0;
                    $department = $data['department']; // This is the CSC department (full name)

                    // Reverse mapping from full names to short IDs (case-insensitive)
                    $reverseDeptMap = [
                        'supreme student council' => 'ssc',
                        'college of arts, social sciences and education' => 'casse',
                        'college of arts, social sciences, and education' => 'casse', // Also handle title case
                        'college of business' => 'cob',
                        'college of computing and information sciences' => 'ccis',
                        'college of criminology' => 'coc',
                        'college of engineering' => 'coe',
                        'college of hospitality and tourism management' => 'chtm',
                        'college of nursing' => 'con',
                        'spcf miranda' => 'miranda'
                    ];
                    $deptLower = strtolower(trim($department));
                    $deptId = $reverseDeptMap[$deptLower] ?? $department; // Fallback to original if not found

                    // Check available balances before deducting
                    $balanceCheckStmt = $db->prepare("SELECT initial_amount - used_amount as available FROM saf_balances WHERE department_id = ?");
                    
                    // Check SSC balance if requesting SSC funds
                    if ($reqSSC > 0) {
                        $balanceCheckStmt->execute(['ssc']);
                        $sscBalance = $balanceCheckStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$sscBalance || $sscBalance['available'] < $reqSSC) {
                            throw new Exception("Insufficient SSC SAF funds. Available: ₱" . ($sscBalance['available'] ?? 0) . ", Requested: ₱{$reqSSC}");
                        }
                    }
                    
                    // Check CSC balance if requesting CSC funds
                    if ($reqCSC > 0 && $deptId) {
                        $balanceCheckStmt->execute([$deptId]);
                        $cscBalance = $balanceCheckStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$cscBalance || $cscBalance['available'] < $reqCSC) {
                            throw new Exception("Insufficient {$deptId} SAF funds. Available: ₱" . ($cscBalance['available'] ?? 0) . ", Requested: ₱{$reqCSC}");
                        }
                    }

                    // Prepare transaction statement
                    $transStmt = $db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date, created_by) VALUES (?, 'deduct', ?, ?, NOW(), ?)");

                    // Deduct SSC funds from 'ssc' department
                    if ($reqSSC > 0) {
                        $updateStmt = $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = ?");
                        $updateStmt->execute([$reqSSC, 'ssc']);

                        // Add transaction record for SSC
                        $transStmt->execute(['ssc', $reqSSC, "SAF Request (SSC): " . ($data['title'] ?? 'Untitled') . " - Document ID: {$documentId}", $currentUser['id']]);

                        // Audit log for SSC fund deduction
                        addAuditLog(
                            'SAF_DEDUCTED',
                            'SAF Management',
                            "Deducted ₱{$reqSSC} from SSC SAF balance for EVP-approved document",
                            $documentId,
                            'Document',
                            'INFO'
                        );
                    }

                    // Deduct CSC funds from selected department (using mapped short ID)
                    if ($reqCSC > 0 && $deptId) {
                        $updateStmt = $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = ?");
                        $updateStmt->execute([$reqCSC, $deptId]);

                        // Add transaction record for CSC
                        $transStmt->execute([$deptId, $reqCSC, "SAF Request (CSC): " . ($data['title'] ?? 'Untitled') . " - Document ID: {$documentId}", $currentUser['id']]);

                        // Audit log for CSC fund deduction
                        addAuditLog(
                            'SAF_DEDUCTED',
                            'SAF Management',
                            "Deducted ₱{$reqCSC} from {$deptId} SAF balance for EVP-approved document",
                            $documentId,
                            'Document',
                            'INFO'
                        );
                    }
                }
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
                // NOTE: Funds are now deducted when EVP approves, not when all steps are completed
                // This block is kept for any future logic that needs to run when fully approved
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
            // Create event only if fully approved and it's a proposal
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
                    // Prepare event data
                    $eventData = [
                        'title' => $eventTitle,
                        'event_date' => $eventDate,
                        'event_time' => $eventTime ?: null,
                        'venue' => $venue ?: 'TBD',
                        'department' => $doc['department'] ?: 'University',
                        'description' => $venue ?: 'Event from approved project proposal',
                        'approved' => 1  // Mark as approved since the proposal is fully approved
                    ];
                    
                    // Use cURL to POST to api/events.php (adjust URL for production)
                    $apiUrl = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false) 
                        ? 'https://spcf-signum.com/SPCF-Thesis/api/events.php'  // Replace with actual production URL
                        : 'http://localhost/SPCF-Thesis/api/events.php';
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $apiUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Cookie: ' . session_name() . '=' . session_id()  // Pass session for auth
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // 10-second timeout
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    
                    if ($httpCode === 200) {
                        error_log("Event created successfully for fully approved proposal: " . $eventTitle);
                    } else {
                        error_log("Failed to create event for proposal: " . $eventTitle . " - HTTP Code: " . $httpCode . " - Response: " . $response . " - cURL Error: " . $curlError);
                    }
                } else {
                    error_log("Event already exists for proposal: " . $eventTitle);
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
    global $db, $currentUser;

    $id = $_GET['id'] ?? 0;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID required']);
        return;
    }

    // Check if user is the creator and is a student
    if ($currentUser['role'] !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only student creators can delete documents']);
        return;
    }

    $stmt = $db->prepare("SELECT student_id FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc || $doc['student_id'] != $currentUser['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only delete documents you created']);
        return;
    }

    // Delete document steps first
    $stmt = $db->prepare("DELETE FROM document_steps WHERE document_id = ?");
    $stmt->execute([$id]);

    // Delete document
    $stmt = $db->prepare("DELETE FROM documents WHERE id = ?");
    $result = $stmt->execute([$id]);

    if ($result) {
        addAuditLog('DOCUMENT_DELETED', 'Document Management', "Deleted document $id", $id, 'Document', 'WARNING');
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete document']);
    }
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