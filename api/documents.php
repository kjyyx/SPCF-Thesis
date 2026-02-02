<?php
/**
 * Documents API - Document Workflow Management
 * ============================================
 *
 * Manages document approval workflows with the following features:
 * - Document creation and status tracking (GET/POST)
 * - Document approval/rejection workflow (PUT)
 * - Automatic timeout handling for stale documents
 * - DOCX template filling and PDF conversion
 */

require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../vendor/autoload.php';

header('Content-Type: application/json');

// Document timeout configuration
if (!defined('DOC_TIMEOUT_DAYS')) {
    define('DOC_TIMEOUT_DAYS', 5); // Auto-timeout threshold in days
}
if (!defined('DOC_TIMEOUT_MODE')) {
    define('DOC_TIMEOUT_MODE', 'reject'); // 'reject' or 'delete'
}

// Initialize database connection early
$database = new Database();
$db = $database->getConnection();

// Ensure $db is assigned
if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

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

// ==================== GET HANDLER ====================

function handleGet()
{
    /**
     * GET /api/documents.php - Retrieve documents
     * ===========================================
     * Returns document list or specific document details.
     * Query parameters:
     * - id: Get specific document details with workflow steps
     * - action=approved_events: Get approved events for calendar
     * - action=my_documents: Get student's own documents
     * - action=document_details: Get detailed document info for modal
     */

    global $db, $currentUser;

    try {
        // Enforce timeouts on stale documents before responding
        enforceTimeouts();

        // Handle different GET actions
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'approved_events':
                    getApprovedEvents();
                    return;
                case 'my_documents':
                    getStudentDocuments();
                    return;
                case 'document_details':
                    getDocumentDetails();
                    return;
            }
        }

        // Get specific document by ID
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            getDocumentById((int) $_GET['id']);
            return;
        }

        // Get documents assigned to current user
        getAssignedDocuments();

    } catch (Exception $e) {
        error_log("Error fetching documents: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch documents']);
    }
}

function getApprovedEvents()
{
    global $db;

    $stmt = $db->prepare("
        SELECT id, title, doc_type, department, `date`, implDate, eventDate, uploaded_at, venue, earliest_start_time
        FROM documents
        WHERE status = 'approved' AND doc_type = 'proposal'
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($rows as $r) {
        $events[] = [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'doc_type' => $r['doc_type'],
            'department' => $r['department'],
            'event_date' => $r['date'],
            'event_time' => $r['earliest_start_time'],
            'venue' => $r['venue']
        ];
    }

    echo json_encode(['success' => true, 'events' => $events]);
}

function getStudentDocuments()
{
    global $db, $currentUser;

    if ($currentUser['role'] !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }

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

    $documents = groupDocumentsWithWorkflow($rows);
    $documents = addNotesToDocuments($documents);

    echo json_encode(['success' => true, 'documents' => array_values($documents)]);
}

function getDocumentDetails()
{
    global $db, $currentUser;

    if ($currentUser['role'] !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }

    $documentId = (int) $_GET['id'];

    // Verify the document belongs to the current student
    $docStmt = $db->prepare("
        SELECT d.*, s.first_name, s.last_name 
        FROM documents d 
        JOIN students s ON d.student_id = s.id 
        WHERE d.id = ? AND d.student_id = ?
    ");
    $docStmt->execute([$documentId, $currentUser['id']]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        return;
    }

    $document = buildDocumentDetail($doc, $documentId);
    echo json_encode(['success' => true, 'document' => $document]);
}

function getDocumentById($documentId)
{
    global $db;

    // Load document and student info
    $docStmt = $db->prepare("
        SELECT d.*, s.id AS student_id, 
               CONCAT(s.first_name,' ',s.last_name) AS student_name, 
               s.department AS student_department
        FROM documents d
        JOIN students s ON d.student_id = s.id
        WHERE d.id = ?
    ");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        return;
    }

    $workflowSteps = getWorkflowSteps($documentId);
    $filePath = getDocumentFilePath($documentId, $doc['file_path']);

    $payload = buildDocumentPayload($doc, $workflowSteps, $filePath);
    echo json_encode($payload);
}

function getAssignedDocuments()
{
    global $db, $currentUser;

    $params = [];
    if ($currentUser['role'] === 'employee') {
        $params = [$currentUser['id'], 'employee', $currentUser['id'], 'student'];
    } elseif ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President') {
        $params = [$currentUser['id'], 'employee', $currentUser['id'], 'student'];
    } else {
        echo json_encode(['success' => true, 'documents' => []]);
        return;
    }

    $pendingDocuments = getPendingDocuments($params);
    $completedDocuments = getCompletedDocuments($params);

    $allDocuments = array_merge($pendingDocuments, $completedDocuments);
    $processedDocuments = processDocumentList($allDocuments);

    echo json_encode([
        'success' => true,
        'documents' => array_values($processedDocuments)
    ]);
}

// ==================== POST HANDLER ====================

function handlePost()
{
    global $currentUser;

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // Handle FormData requests
    if (strpos($contentType, 'multipart/form-data') !== false) {
        handleFormDataRequest();
        return;
    }

    // Handle JSON requests
    handleJsonRequest();
}

function handleFormDataRequest()
{
    $action = $_POST['action'] ?? '';

    try {
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
}

function handleJsonRequest()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
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

// ==================== DOCUMENT CREATION ====================

function createDocument($input)
{
    global $db, $currentUser, $auth;

    if ($currentUser['role'] !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only students can create documents']);
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

    // Fetch fresh user data
    $freshUser = $auth->getUser($currentUser['id'], $currentUser['role']);
    $currentUser = $freshUser;

    try {
        $db->beginTransaction();

        $documentData = prepareDocumentData($docType, $data);
        $docId = insertDocument($studentId, $docType, $documentData);

        processDocumentTemplate($docType, $docId, $documentData);
        createWorkflowSteps($docType, $docId, $documentData['department']);

        $db->commit();

        addAuditLog(
            'DOCUMENT_CREATED',
            'Document Management',
            "Document created by {$currentUser['first_name']} {$currentUser['last_name']}: {$docType} - " . ($data['title'] ?? 'Untitled'),
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

// ==================== DOCUMENT SIGNING ====================

function signDocument($input, $files = null)
{
    global $db, $currentUser;

    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $notes = $input['notes'] ?? '';
    $signatureMap = parseSignatureMap($input['signature_map'] ?? null);

    if (!$documentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID is required']);
        return;
    }

    $stepId = resolveStepId($documentId, $stepId);
    if (!$stepId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No pending step assigned to you for this document']);
        return;
    }

    $doc = getDocumentByIdForSigning($documentId);
    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        return;
    }

    // Handle signed PDF upload
    handleSignedPdfUpload($files, $documentId, $doc['file_path']);

    $maxRetries = 3;
    $retryCount = 0;
    $isFullyApproved = false;
    $signedSuccessfully = false;

    do {
        try {
            $db->beginTransaction();

            createDocumentSignature($documentId, $stepId, $currentUser);
            updateDocumentStep($stepId, $currentUser, $notes, 'completed');
            saveSignatureMapping($stepId, $signatureMap);
            updateDocumentProgress($documentId);

            // Update dates for SAF documents
            if ($doc['doc_type'] === 'saf') {
                updateSafDates($documentId, $stepId);
            }

            // Check if all steps are completed
            $isFullyApproved = isDocumentFullyApproved($documentId);

            if ($isFullyApproved) {
                finalizeDocumentApproval($documentId, $doc['doc_type']);
            }

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
        createEventForApprovedProposal($documentId);
    }
}

// ==================== DOCUMENT REJECTION ====================

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

    $stepId = resolveStepIdForRejection($documentId, $stepId);
    if (!$stepId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No step assigned to you for this document']);
        return;
    }

    $db->beginTransaction();

    try {
        createDocumentSignature($documentId, $stepId, $currentUser, 'rejected');
        updateDocumentStep($stepId, $currentUser, $reason, 'rejected');
        archiveRejectedFile($documentId);

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

// ==================== NOTE MANAGEMENT ====================

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

    // Check authorization
    if (!isUserAssignedToStep($stepId, $currentUser)) {
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
    if (verifyStepExists($stepId)) {
        echo json_encode(['success' => true]);
    } else {
        error_log("Step verification failed after update");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update note']);
    }
}

// ==================== TIMEOUT ENFORCEMENT ====================

function enforceTimeouts()
{
    global $db;

    try {
        $staleDocs = getStaleDocuments();

        if (!$staleDocs) {
            return;
        }

        foreach ($staleDocs as $docId) {
            $db->beginTransaction();
            try {
                applyTimeoutToDocument($docId);
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

// ==================== PUT & DELETE HANDLERS ====================

function handlePut()
{
    http_response_code(501);
    echo json_encode(['success' => false, 'message' => 'Not implemented']);
}

function handleDelete()
{
    http_response_code(501);
    echo json_encode(['success' => false, 'message' => 'Not implemented']);
}

// ==================== TEMPLATE PROCESSING FUNCTIONS ====================

function fillDocxTemplate($templatePath, $data)
{
    if (!file_exists($templatePath)) {
        throw new Exception("Template file not found: $templatePath");
    }

    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

    // Replace simple placeholders
    foreach ($data as $key => $value) {
        $processedValue = processTemplateValue($key, $value);
        $templateProcessor->setValue($key, $processedValue);
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

function processTemplateValue($key, $value)
{
    if (is_array($value)) {
        if ($key === 'objectives' || $key === 'ilos') {
            return implode("\n• ", array_map('htmlspecialchars', $value));
        } elseif ($key === 'program') {
            $lines = [];
            foreach ($value as $item) {
                $lines[] = htmlspecialchars("{$item['start']} - {$item['end']}: {$item['act']}");
            }
            return implode("\n", $lines);
        } elseif ($key === 'budget') {
            $lines = [];
            foreach ($value as $item) {
                $lines[] = htmlspecialchars("{$item['name']} - {$item['size']} - Qty: {$item['qty']} - Price: ₱{$item['price']} - Total: ₱{$item['total']}");
            }
            return implode("\n", $lines);
        } else {
            return implode(", ", array_map('htmlspecialchars', $value));
        }
    } elseif (is_string($value)) {
        return htmlspecialchars($value);
    }

    return $value;
}

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

    if (!file_exists($docxPath)) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: DOCX file does not exist: $docxPath\n", FILE_APPEND);
        return $docxPath;
    }

    try {
        $cloudconvert = new \CloudConvert\CloudConvert([
            'api_key' => $apiKey,
            'sandbox' => false
        ]);

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

        $job = $cloudconvert->jobs()->create($job);
        $uploadTask = $job->getTasks()->whereName('upload-my-file')[0];

        $fileContent = file_get_contents($docxPath);
        $cloudconvert->tasks()->upload($uploadTask, $fileContent, basename($docxPath));
        $cloudconvert->jobs()->wait($job);

        if ($job->getStatus() === 'finished') {
            $exportTask = $job->getTasks()->whereName('export-my-file')[0];
            $result = $exportTask->getResult();

            if (isset($result->files) && count($result->files) > 0) {
                $fileUrl = $result->files[0]->url;
                $pdfContent = file_get_contents($fileUrl);
                $pdfPath = str_replace('.docx', '.pdf', $docxPath);
                file_put_contents($pdfPath, $pdfContent);

                // Clean up DOCX
                if (file_exists($docxPath)) {
                    unlink($docxPath);
                }

                return $pdfPath;
            }
        }

    } catch (Exception $e) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    return $docxPath; // Return original path if conversion fails
}

// ==================== UTILITY FUNCTIONS ====================

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
            null,
            $severity
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// ==================== DATABASE HELPER FUNCTIONS ====================

function getPendingDocuments($params)
{
    global $db;

    $query = "
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
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCompletedDocuments($params)
{
    global $db;

    $query = "
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
        WHERE ds.status IN ('completed', 'rejected')
        AND ds.acted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND (
            (ds.assigned_to_employee_id = ? AND ? = 'employee')
            OR (ds.assigned_to_student_id = ? AND ? = 'student')
        )
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getWorkflowSteps($documentId)
{
    global $db;

    $stepsStmt = $db->prepare("
        SELECT ds.*, e.first_name AS emp_first, e.last_name AS emp_last,
               s.first_name AS stu_first, s.last_name AS stu_last,
               dsg.status AS signature_status, dsg.signed_at
        FROM document_steps ds
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students s ON ds.assigned_to_student_id = s.id
        LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id 
            AND ((dsg.employee_id = ds.assigned_to_employee_id AND ds.assigned_to_employee_id IS NOT NULL) 
                 OR (dsg.student_id = ds.assigned_to_student_id AND ds.assigned_to_student_id IS NOT NULL))
        WHERE ds.document_id = ?
        ORDER BY ds.step_order ASC
    ");
    $stepsStmt->execute([$documentId]);

    $steps = [];
    while ($s = $stepsStmt->fetch(PDO::FETCH_ASSOC)) {
        $steps[] = formatWorkflowStep($s);
    }

    return $steps;
}

function formatWorkflowStep($step)
{
    $assignee_id = $step['assigned_to_employee_id'] ?: $step['assigned_to_student_id'];
    $assignee_name = null;
    $assignee_type = null;

    if ($step['assigned_to_employee_id']) {
        $assignee_name = trim($step['emp_first'] . ' ' . $step['emp_last']);
        $assignee_type = 'employee';
    } elseif ($step['assigned_to_student_id']) {
        $assignee_name = trim($step['stu_first'] . ' ' . $step['stu_last']);
        $assignee_type = 'student';
    }

    return [
        'id' => (int) $step['id'],
        'step_order' => (int) $step['step_order'],
        'name' => $step['name'],
        'status' => $step['status'],
        'note' => $step['note'],
        'acted_at' => $step['acted_at'],
        'signature_map' => $step['signature_map'],
        'assignee_id' => $assignee_id,
        'assignee_name' => $assignee_name,
        'assignee_type' => $assignee_type,
        'signature_status' => $step['signature_status'],
        'signed_at' => $step['signed_at']
    ];
}

function getDocumentFilePath($documentId, $filePath)
{
    global $db;

    // Check for attachments first
    $attStmt = $db->prepare("SELECT file_path FROM attachments WHERE document_id = ? ORDER BY id ASC LIMIT 1");
    $attStmt->execute([$documentId]);

    if ($att = $attStmt->fetch(PDO::FETCH_ASSOC)) {
        $filePath = $att['file_path'];
    }

    // Handle backward compatibility for file paths
    if ($filePath && strpos($filePath, '../') !== 0 && strpos($filePath, 'http') !== 0) {
        $filePath = '../uploads/' . $filePath;
    }

    // Convert server path to web URL
    if ($filePath) {
        $absPath = realpath(__DIR__ . '/' . $filePath);
        if ($absPath) {
            $basePath = realpath(__DIR__ . '/../');
            $filePath = str_replace($basePath, '', $absPath);
            $filePath = str_replace('\\', '/', $filePath);
            $filePath = '/SPCF-Thesis' . $filePath;

            // URL encode the filename
            $pathParts = explode('/', $filePath);
            $filename = end($pathParts);
            $encodedFilename = rawurlencode($filename);
            $filePath = str_replace($filename, $encodedFilename, $filePath);
        }
    }

    return $filePath;
}

function groupDocumentsWithWorkflow($rows)
{
    $documents = [];

    foreach ($rows as $row) {
        $docId = $row['id'];
        if (!isset($documents[$docId])) {
            $documents[$docId] = createDocumentStructure($row);
        }

        if ($row['step_order']) {
            $documents[$docId]['workflow_history'][] = createWorkflowHistoryEntry($row);
        }
    }

    return $documents;
}

function createDocumentStructure($row)
{
    // Determine current location
    $current_location = 'Student Council'; // Default

    return [
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

function createWorkflowHistoryEntry($row)
{
    $timestamp = $row['acted_at'] ?: $row['uploaded_at'];

    return [
        'created_at' => $timestamp,
        'action' => $row['step_status'] === 'completed' ? 'Approved' :
            ($row['step_status'] === 'rejected' ? 'Rejected' : 'Pending'),
        'office_name' => $row['step_name'],
        'from_office' => $row['step_name']
    ];
}

function addNotesToDocuments($documents)
{
    global $db;

    if (empty($documents)) {
        return $documents;
    }

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

    return $documents;
}

function buildDocumentDetail($doc, $documentId)
{
    global $db;

    // Fetch workflow history
    $historyStmt = $db->prepare("
        SELECT ds.id, ds.status, ds.name, ds.acted_at
        FROM document_steps ds
        WHERE ds.document_id = ?
        ORDER BY ds.step_order ASC
    ");
    $historyStmt->execute([$documentId]);
    $workflow_history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch notes/comments
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
    $current_location = 'Student Council';
    foreach ($workflow_history as $step) {
        if ($step['status'] === 'in_progress' || $step['status'] === 'pending') {
            $current_location = $step['name'];
            break;
        }
    }

    return [
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
                'action' => $step['status'] === 'completed' ? 'Approved' :
                    ($step['status'] === 'rejected' ? 'Rejected' : 'Pending'),
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
}

function buildDocumentPayload($doc, $workflowSteps, $filePath)
{
    return [
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
        'workflow' => $workflowSteps,
        'file_path' => $filePath
    ];
}

function processDocumentList($rows)
{
    $processedDocuments = [];

    foreach ($rows as $row) {
        $docId = $row['id'];
        if (!isset($processedDocuments[$docId])) {
            $processedDocuments[$docId] = createProcessedDocument($row);
        }

        // Add step if exists
        if ($row['step_order']) {
            $processedDocuments[$docId]['workflow'][] = createProcessedWorkflowStep($row);
        }
    }

    return $processedDocuments;
}

function createProcessedDocument($row)
{
    return [
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

function createProcessedWorkflowStep($row)
{
    $assigneeName = $row['assigned_to_employee_id'] ?
        trim(($row['assignee_first'] ?? '') . ' ' . ($row['assignee_last'] ?? '')) :
        trim(($row['student_assignee_first'] ?? '') . ' ' . ($row['student_assignee_last'] ?? ''));

    return [
        'id' => (int) $row['step_id'],
        'name' => $row['step_name'],
        'status' => $row['step_status'],
        'order' => (int) $row['step_order'],
        'assigned_to' => $row['assigned_to_employee_id'] ?: $row['assigned_to_student_id'],
        'assignee_name' => $assigneeName,
        'note' => $row['note'],
        'acted_at' => $row['acted_at'],
        'signature_status' => $row['signature_status'],
        'signed_at' => $row['signed_at']
    ];
}

function parseSignatureMap($signatureMap)
{
    if ($signatureMap && !is_array($signatureMap) && is_string($signatureMap)) {
        return json_decode($signatureMap, true);
    }
    return $signatureMap;
}

function resolveStepId($documentId, $stepId)
{
    global $db, $currentUser;

    if ($stepId) {
        return $stepId;
    }

    if ($currentUser['role'] === 'employee') {
        $q = $db->prepare("
            SELECT id FROM document_steps 
            WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' 
            ORDER BY step_order ASC LIMIT 1
        ");
        $q->execute([$documentId, $currentUser['id']]);
    } elseif ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President') {
        $q = $db->prepare("
            SELECT id FROM document_steps 
            WHERE document_id = ? AND assigned_to_student_id = ? AND status = 'pending' 
            ORDER BY step_order ASC LIMIT 1
        ");
        $q->execute([$documentId, $currentUser['id']]);
    } else {
        return null;
    }

    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ? (int) $row['id'] : null;
}

function resolveStepIdForRejection($documentId, $stepId)
{
    global $db, $currentUser;

    if ($stepId) {
        return $stepId;
    }

    if ($currentUser['role'] === 'employee') {
        // Prefer a pending step owned by this employee
        $q = $db->prepare("
            SELECT id FROM document_steps 
            WHERE document_id = ? AND assigned_to_employee_id = ? AND status = 'pending' 
            ORDER BY step_order ASC LIMIT 1
        ");
        $q->execute([$documentId, $currentUser['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Otherwise, allow any step assigned to this employee (any status)
            $q2 = $db->prepare("
                SELECT id FROM document_steps 
                WHERE document_id = ? AND assigned_to_employee_id = ? 
                ORDER BY step_order ASC LIMIT 1
            ");
            $q2->execute([$documentId, $currentUser['id']]);
            $row = $q2->fetch(PDO::FETCH_ASSOC);
        }
    } elseif ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President') {
        // Prefer a pending step owned by this student
        $q = $db->prepare("
            SELECT id FROM document_steps 
            WHERE document_id = ? AND assigned_to_student_id = ? AND status = 'pending' 
            ORDER BY step_order ASC LIMIT 1
        ");
        $q->execute([$documentId, $currentUser['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Otherwise, allow any step assigned to this student (any status)
            $q2 = $db->prepare("
                SELECT id FROM document_steps 
                WHERE document_id = ? AND assigned_to_student_id = ? 
                ORDER BY step_order ASC LIMIT 1
            ");
            $q2->execute([$documentId, $currentUser['id']]);
            $row = $q2->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        return null;
    }

    return $row ? (int) $row['id'] : null;
}

function getDocumentByIdForSigning($documentId)
{
    global $db;

    $docStmt = $db->prepare("SELECT file_path, doc_type, data FROM documents WHERE id = ?");
    $docStmt->execute([$documentId]);
    return $docStmt->fetch(PDO::FETCH_ASSOC);
}

function handleSignedPdfUpload($files, $documentId, $currentFilePath)
{
    if (!isset($files['signed_pdf']) || $files['signed_pdf']['error'] !== UPLOAD_ERR_OK) {
        return;
    }

    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Check if document is fully approved
    $isFullyApproved = isDocumentFullyApproved($documentId);

    if (!$isFullyApproved && $currentFilePath) {
        // Archive old file only if not fully approved
        $oldFilePath = $uploadDir . basename($currentFilePath);
        if (file_exists($oldFilePath)) {
            $archiveDir = $uploadDir . 'archive/';
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }
            $archivedPath = $archiveDir . basename($oldFilePath) . '.old';
            rename($oldFilePath, $archivedPath);
        }
    }

    // Save new signed PDF
    $newFileName = 'signed_doc_' . $documentId . '_' . time() . '.pdf';
    $newPath = $uploadDir . $newFileName;

    if (move_uploaded_file($files['signed_pdf']['tmp_name'], $newPath)) {
        $relativePath = '../uploads/' . $newFileName;
        $updateStmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
        $updateStmt->execute([$relativePath, $documentId]);
    }
}

function createDocumentSignature($documentId, $stepId, $user, $status = 'signed')
{
    global $db;

    if ($user['role'] === 'employee') {
        $stmt = $db->prepare("
            INSERT INTO document_signatures (document_id, step_id, employee_id, status, signed_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            status = ?, signed_at = NOW()
        ");
        $stmt->execute([$documentId, $stepId, $user['id'], $status, $status]);
    } elseif ($user['role'] === 'student') {
        $stmt = $db->prepare("
            INSERT INTO document_signatures (document_id, step_id, student_id, status, signed_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            status = ?, signed_at = NOW()
        ");
        $stmt->execute([$documentId, $stepId, $user['id'], $status, $status]);
    }
}

function updateDocumentStep($stepId, $user, $note, $status)
{
    global $db;

    if ($user['role'] === 'employee') {
        $stmt = $db->prepare("
            UPDATE document_steps
            SET status = ?, acted_at = NOW(), note = ?
            WHERE id = ? AND assigned_to_employee_id = ?
        ");
        $stmt->execute([$status, $note, $stepId, $user['id']]);
    } elseif ($user['role'] === 'student') {
        $stmt = $db->prepare("
            UPDATE document_steps
            SET status = ?, acted_at = NOW(), note = ?
            WHERE id = ? AND assigned_to_student_id = ?
        ");
        $stmt->execute([$status, $note, $stepId, $user['id']]);
    }

    // Fallback if role-restricted update didn't affect rows
    if ($stmt->rowCount() === 0) {
        $fallback = $db->prepare("UPDATE document_steps SET status = ?, acted_at = NOW(), note = ? WHERE id = ?");
        $fallback->execute([$status, $note, $stepId]);
    }
}

function saveSignatureMapping($stepId, $signatureMap)
{
    global $db;

    if ($signatureMap) {
        $stmt = $db->prepare("UPDATE document_steps SET signature_map = ? WHERE id = ?");
        $stmt->execute([json_encode($signatureMap), $stepId]);
    }
}

function updateDocumentProgress($documentId)
{
    global $db;

    $stmt = $db->prepare("
        UPDATE documents
        SET current_step = current_step + 1, status = 'in_review', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$documentId]);
}

function isDocumentFullyApproved($documentId)
{
    global $db;

    $stmt = $db->prepare("
        SELECT COUNT(*) as total_steps,
               COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_steps
        FROM document_steps
        WHERE document_id = ?
    ");
    $stmt->execute([$documentId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    return $progress['total_steps'] == $progress['completed_steps'];
}

function finalizeDocumentApproval($documentId, $docType)
{
    global $db;

    $stmt = $db->prepare("
        UPDATE documents
        SET status = 'approved', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$documentId]);

    if ($docType === 'saf') {
        processSafApproval($documentId);
    }
}

function processSafApproval($documentId)
{
    global $db;

    $docStmt = $db->prepare("SELECT data FROM documents WHERE id = ?");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc && $doc['data']) {
        $data = json_decode($doc['data'], true);
        if ($data) {
            $data['releaseDate'] = date('Y-m-d');

            // Re-fill template
            $templatePath = '../assets/templates/SAF/SAF REQUEST.docx';
            if (file_exists($templatePath)) {
                try {
                    $filledPath = fillDocxTemplate($templatePath, $data);
                    $pdfPath = convertDocxToPdf($filledPath);
                    $updateStmt = $db->prepare("UPDATE documents SET file_path = ?, data = ? WHERE id = ?");
                    $updateStmt->execute([$pdfPath, json_encode($data), $documentId]);
                } catch (Exception $e) {
                    error_log("SAF template re-filling failed: " . $e->getMessage());
                }
            }

            // Deduct SAF funds
            $reqSSC = $data['reqSSC'] ?? 0;
            $reqCSC = $data['reqCSC'] ?? 0;
            $totalDeduct = $reqSSC + $reqCSC;
            $department = $data['department'];

            if ($totalDeduct > 0 && $department) {
                $updateStmt = $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ? WHERE department_id = ?");
                $updateStmt->execute([$totalDeduct, $department]);

                $transStmt = $db->prepare("
                    INSERT INTO saf_transactions (department_id, transaction_type, amount, description, date) 
                    VALUES (?, 'deduct', ?, ?, NOW())
                ");
                $transStmt->execute([$department, $totalDeduct, "SAF Request: " . ($data['title'] ?? 'Untitled')]);

                addAuditLog(
                    'SAF_DEDUCTED',
                    'SAF Management',
                    "Deducted ₱{$totalDeduct} from {$department} SAF balance for approved document",
                    $documentId,
                    'Document',
                    'INFO'
                );
            }
        }
    }
}

function updateSafDates($documentId, $stepId)
{
    global $db;

    $docStmt = $db->prepare("SELECT data FROM documents WHERE id = ?");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc && $doc['data']) {
        $data = json_decode($doc['data'], true);
        if ($data) {
            $stepStmt = $db->prepare("SELECT name FROM document_steps WHERE id = ?");
            $stepStmt->execute([$stepId]);
            $step = $stepStmt->fetch(PDO::FETCH_ASSOC);

            $stepName = $step['name'];
            $dateField = '';

            if ($stepName === 'OIC OSA') {
                $dateField = 'notedDate';
            } elseif ($stepName === 'VPAA') {
                $dateField = 'recDate';
            } elseif ($stepName === 'EVP') {
                $dateField = 'appDate';
            }

            if ($dateField) {
                $data[$dateField] = date('Y-m-d');

                // Re-fill template
                $templatePath = '../assets/templates/SAF/SAF REQUEST.docx';
                if (file_exists($templatePath)) {
                    try {
                        $filledPath = fillDocxTemplate($templatePath, $data);
                        $pdfPath = convertDocxToPdf($filledPath);
                        $updateStmt = $db->prepare("UPDATE documents SET file_path = ?, data = ? WHERE id = ?");
                        $updateStmt->execute([$pdfPath, json_encode($data), $documentId]);
                    } catch (Exception $e) {
                        error_log("SAF template re-filling failed: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

function createEventForApprovedProposal($documentId)
{
    global $db;

    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $docStmt = $db->prepare("
        SELECT doc_type, title, department, departmentFull, date, venue, earliest_start_time 
        FROM documents WHERE id = ?
    ");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc && $doc['doc_type'] === 'proposal') {
        $eventTitle = $doc['title'];
        $eventDate = $doc['date'];
        $venue = $doc['venue'];
        $eventTime = $doc['earliest_start_time'];

        if ($eventTitle && $eventDate) {
            // Check if event already exists
            $stmt = $db->prepare("SELECT id FROM events WHERE title = ? AND event_date = ? LIMIT 1");
            $stmt->execute([$eventTitle, $eventDate]);
            $existingEvent = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingEvent) {
                $eventData = [
                    'title' => $eventTitle,
                    'event_date' => $eventDate,
                    'venue' => $venue,
                    'event_time' => $eventTime,
                    'department' => $doc['department'],
                    'description' => $venue,
                    'approved' => 1
                ];

                // Use cURL to create event
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://localhost/SPCF-Thesis/api/events.php');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Cookie: ' . session_name() . '=' . session_id()
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                $response = curl_exec($ch);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    error_log("CURL error creating event for document $documentId: $curlError");
                }
            }
        }
    }
}

function archiveRejectedFile($documentId)
{
    global $db;

    $docStmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc && $doc['file_path']) {
        $uploadDir = '../uploads/';
        $oldFilePath = $uploadDir . basename($doc['file_path']);
        if (file_exists($oldFilePath)) {
            $archiveDir = $uploadDir . 'archive/';
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }
            $archivedPath = $archiveDir . basename($oldFilePath) . '.rejected';
            rename($oldFilePath, $archivedPath);
        }
    }
}

function isUserAssignedToStep($stepId, $user)
{
    global $db;

    $checkStmt = $db->prepare("
        SELECT id FROM document_steps 
        WHERE id = ? AND (assigned_to_employee_id = ? OR assigned_to_student_id = ?)
    ");
    $checkStmt->execute([$stepId, $user['id'], $user['id']]);
    return (bool) $checkStmt->fetch();
}

function verifyStepExists($stepId)
{
    global $db;

    $verifyStmt = $db->prepare("SELECT id FROM document_steps WHERE id = ?");
    $verifyStmt->execute([$stepId]);
    return (bool) $verifyStmt->fetch();
}

function getStaleDocuments()
{
    global $db;

    $sel = $db->prepare("
        SELECT id FROM documents
        WHERE status IN ('submitted', 'in_review')
          AND DATEDIFF(NOW(), uploaded_at) >= ?
    ");
    $sel->execute([DOC_TIMEOUT_DAYS]);
    return $sel->fetchAll(PDO::FETCH_COLUMN);
}

function applyTimeoutToDocument($docId)
{
    global $db;

    if (DOC_TIMEOUT_MODE === 'delete') {
        $upd = $db->prepare("
            UPDATE documents 
            SET status = 'deleted', updated_at = NOW() 
            WHERE id = ? AND status IN ('submitted', 'in_review')
        ");
        $upd->execute([$docId]);
    } else {
        $updSteps = $db->prepare("
            UPDATE document_steps 
            SET status = 'rejected', acted_at = NOW(), 
                note = CONCAT(COALESCE(note, ''), 
                CASE WHEN COALESCE(note,'') <> '' THEN ' ' ELSE '' END, 
                '[Auto-timeout after ', ?, ' days]') 
            WHERE document_id = ? AND status = 'pending'
        ");
        $updSteps->execute([DOC_TIMEOUT_DAYS, $docId]);

        $updDoc = $db->prepare("
            UPDATE documents 
            SET status = 'rejected', updated_at = NOW() 
            WHERE id = ? AND status IN ('submitted', 'in_review')
        ");
        $updDoc->execute([$docId]);
    }

    addAuditLog(
        'DOCUMENT_TIMEOUT',
        'Document Management',
        'Auto-timeout applied to document ID ' . $docId . ' after ' . DOC_TIMEOUT_DAYS . ' days (mode=' . DOC_TIMEOUT_MODE . ')',
        $docId,
        'Document',
        'WARNING'
    );
}

function prepareDocumentData($docType, $data)
{
    $department = $data['department'] ?? '';

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

    $departmentFull = $departmentFullMap[$department] ?? $department;
    $data['departmentFull'] = $departmentFull;

    // Set document-specific fields
    switch ($docType) {
        case 'proposal':
            $data['date'] = $data['date'] ?? null;
            $data['venue'] = $data['venue'] ?? null;
            $data['scheduleSummary'] = $data['scheduleSummary'] ?? null;
            $data['earliestStartTime'] = $data['earliestStartTime'] ?? null;
            break;
        case 'saf':
            $data['implDate'] = $data['implDate'] ?? null;
            break;
        case 'facility':
            $data['eventName'] = $data['eventName'] ?? null;
            $data['eventDate'] = $data['eventDate'] ?? null;
            break;
        case 'communication':
            $data['date'] = $data['date'] ?? null;
            break;
    }

    return $data;
}

function insertDocument($studentId, $docType, $data)
{
    global $db;

    $stmt = $db->prepare("
        INSERT INTO documents 
        (student_id, doc_type, title, description, status, current_step, uploaded_at, 
         department, departmentFull, date, implDate, eventName, eventDate, venue, 
         schedule_summary, earliest_start_time, data) 
        VALUES (?, ?, ?, ?, 'submitted', 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $studentId,
        $docType,
        $data['title'] ?? 'Untitled',
        $data['rationale'] ?? '',
        $data['department'],
        $data['departmentFull'],
        $data['date'] ?? null,
        $data['implDate'] ?? null,
        $data['eventName'] ?? null,
        $data['eventDate'] ?? null,
        $data['venue'] ?? null,
        $data['scheduleSummary'] ?? null,
        $data['earliestStartTime'] ?? null,
        json_encode($data)
    ]);

    return (int) $db->lastInsertId();
}

function processDocumentTemplate($docType, $docId, $data)
{
    global $db, $currentUser;

    switch ($docType) {
        case 'proposal':
            processProposalTemplate($docId, $data);
            break;
        case 'communication':
            processCommunicationTemplate($docId, $data);
            break;
        case 'saf':
            processSafTemplate($docId, $data);
            break;
        case 'facility':
            processFacilityTemplate($docId, $data);
            break;
    }

    // Set fallback file path
    $checkStmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
    $checkStmt->execute([$docId]);
    $existingFilePath = $checkStmt->fetchColumn();

    if (!$existingFilePath) {
        $fallbackPath = '../uploads/fallback.pdf';
        $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
        $stmt->execute([$fallbackPath, $docId]);
    }
}

function processProposalTemplate($docId, $data)
{
    global $db, $currentUser;

    $department = $data['department'];

    // Fetch signatories
    $signatories = fetchSignatories('proposal', $department, $currentUser['id']);
    $data = array_merge($data, $signatories['names']);

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
        $pdfPath = convertDocxToPdf($filledPath);
        $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
        $stmt->execute([$pdfPath, $docId]);
    } catch (Exception $e) {
        error_log("Proposal template filling failed: " . $e->getMessage());
    }
}

function processCommunicationTemplate($docId, $data)
{
    global $db;

    $department = $data['department'];

    // Fetch signatories
    $signatories = fetchSignatories('communication', $department);
    $data = array_merge($data, $signatories['names']);
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

    unset($data['body']);

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

    try {
        $filledPath = fillDocxTemplate($templatePath, $data);
        $pdfPath = convertDocxToPdf($filledPath);
        $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
        $stmt->execute([$pdfPath, $docId]);
    } catch (Exception $e) {
        error_log("Communication template filling failed: " . $e->getMessage());
    }
}

function processSafTemplate($docId, $data)
{
    global $db, $currentUser;

    $department = $data['department'];

    // Fetch signatories
    $signatories = fetchSignatories('saf', $department, $currentUser['id']);
    $data = array_merge($data, $signatories['names']);
    $data['reqByName'] = $currentUser['first_name'] . ' ' . $currentUser['last_name'];

    $templatePath = '../assets/templates/SAF/SAF REQUEST.docx';
    if (file_exists($templatePath)) {
        try {
            $filledPath = fillDocxTemplate($templatePath, $data);
            $pdfPath = convertDocxToPdf($filledPath);
            $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
            $stmt->execute([$pdfPath, $docId]);
        } catch (Exception $e) {
            error_log("SAF template filling failed: " . $e->getMessage());
        }
    }
}

function processFacilityTemplate($docId, $data)
{
    global $db;

    $department = $data['department'];

    // Fetch signatories
    $signatories = fetchSignatories('facility', $department);
    $data = array_merge($data, $signatories['names']);

    $templatePath = '../assets/templates/Facility Request/FACILITY REQUEST.docx';
    if (file_exists($templatePath)) {
        try {
            $filledPath = fillDocxTemplate($templatePath, $data);
            $pdfPath = convertDocxToPdf($filledPath);
            $stmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
            $stmt->execute([$pdfPath, $docId]);
        } catch (Exception $e) {
            error_log("Facility template filling failed: " . $e->getMessage());
        }
    }
}

function fetchSignatories($docType, $department, $studentId = null)
{
    global $db;

    $signatories = ['names' => [], 'ids' => []];

    switch ($docType) {
        case 'proposal':
        case 'communication':
            $signatories = fetchProposalSignatories($department);
            break;
        case 'saf':
            $signatories = fetchSafSignatories($department, $studentId);
            break;
        case 'facility':
            $signatories = fetchFacilitySignatories($department);
            break;
    }

    return $signatories;
}

function fetchProposalSignatories($department)
{
    global $db;

    $signatories = ['names' => [], 'ids' => []];

    // CSC President
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['cscPresident'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'CSC President';
    $signatories['ids']['cscPresident'] = $cscPresident ? $cscPresident['id'] : null;

    // Adviser
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'CSC Adviser' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['cscAdviser'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'CSC Adviser';
    $signatories['ids']['cscAdviser'] = $adviser ? $adviser['id'] : null;

    // SSC President
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'SSC President' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $ssc = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['sscPresident'] = $ssc ? $ssc['first_name'] . ' ' . $ssc['last_name'] : 'SSC President';
    $signatories['ids']['sscPresident'] = $ssc ? $ssc['id'] : null;

    // College Dean
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Dean' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $dean = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['collegeDean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
    $signatories['ids']['collegeDean'] = $dean ? $dean['id'] : null;

    return $signatories;
}

function fetchSafSignatories($department, $studentId)
{
    global $db;

    $signatories = ['names' => [], 'ids' => []];

    // Get student's position
    $studentStmt = $db->prepare("SELECT position FROM students WHERE id = ? LIMIT 1");
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    $isCscPresident = $student && $student['position'] === 'CSC President';

    // Student Representative
    if ($isCscPresident) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
        $stmt->execute([$department]);
        $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
        $signatories['names']['studentRepresentative'] = $cscPresident['first_name'] . ' ' . $cscPresident['last_name'];
        $signatories['ids']['studentRepresentative'] = $cscPresident['id'];
    } else {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'SSC President' LIMIT 1");
        $stmt->execute([]);
        $ssc = $stmt->fetch(PDO::FETCH_ASSOC);
        $signatories['names']['studentRepresentative'] = $ssc ? $ssc['first_name'] . ' ' . $ssc['last_name'] : 'Student Representative';
        $signatories['ids']['studentRepresentative'] = $ssc ? $ssc['id'] : null;
    }

    // Adviser
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'CSC Adviser' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['cscAdviser'] = $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'CSC Adviser';
    $signatories['ids']['cscAdviser'] = $adviser ? $adviser['id'] : null;

    // College Dean
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'Dean' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $dean = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['collegeDean'] = $dean ? $dean['first_name'] . ' ' . $dean['last_name'] : 'College Dean';
    $signatories['ids']['collegeDean'] = $dean ? $dean['id'] : null;

    // OIC-OSA
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'OIC OSA' LIMIT 1");
    $stmt->execute([]);
    $oicOsa = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['oic-osa'] = $oicOsa ? $oicOsa['first_name'] . ' ' . $oicOsa['last_name'] : 'OIC OSA';
    $signatories['ids']['oic-osa'] = $oicOsa ? $oicOsa['id'] : null;

    // VPAA
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'VPAA' LIMIT 1");
    $stmt->execute([]);
    $vpaa = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['vpaa'] = $vpaa ? $vpaa['first_name'] . ' ' . $vpaa['last_name'] : 'VPAA';
    $signatories['ids']['vpaa'] = $vpaa ? $vpaa['id'] : null;

    // EVP
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'EVP' LIMIT 1");
    $stmt->execute([]);
    $evp = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['evp'] = $evp ? $evp['first_name'] . ' ' . $evp['last_name'] : 'EVP';
    $signatories['ids']['evp'] = $evp ? $evp['id'] : null;

    // Accounting Personnel
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position  = 'Accounting Officer' LIMIT 1");
    $stmt->execute([]);
    $accountingPersonnel = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['acp'] = $accountingPersonnel ? $accountingPersonnel['first_name'] . ' ' . $accountingPersonnel['last_name'] : 'Accounting Personnel';

    return $signatories;
}

function fetchFacilitySignatories($department)
{
    global $db;

    $signatories = ['names' => [], 'ids' => []];

    // CSC President
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM students WHERE position = 'CSC President' AND department = ? LIMIT 1");
    $stmt->execute([$department]);
    $cscPresident = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['cscPresident'] = $cscPresident ? $cscPresident['first_name'] . ' ' . $cscPresident['last_name'] : 'CSC President';
    $signatories['ids']['cscPresident'] = $cscPresident ? $cscPresident['id'] : null;

    // EVP O
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'EVP O' LIMIT 1");
    $stmt->execute([]);
    $evpO = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['evpO'] = $evpO ? $evpO['first_name'] . ' ' . $evpO['last_name'] : 'EVP O';
    $signatories['ids']['evpO'] = $evpO ? $evpO['id'] : null;

    // EVP
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE position = 'EVP' LIMIT 1");
    $stmt->execute([]);
    $evp = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatories['names']['evp'] = $evp ? $evp['first_name'] . ' ' . $evp['last_name'] : 'EVP';
    $signatories['ids']['evp'] = $evp ? $evp['id'] : null;

    return $signatories;
}

function createWorkflowSteps($docType, $docId, $department)
{
    global $db;

    $workflowConfig = getWorkflowConfig($docType, $department);

    $stepOrder = 1;
    foreach ($workflowConfig as $config) {
        $assignee = getWorkflowAssignee($config, $department);

        if ($assignee) {
            $stmt = $db->prepare("
                INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");

            if ($config['table'] === 'employees') {
                $stmt->execute([$docId, $stepOrder, $config['position'] . ' Approval', $assignee['id'], null]);
            } else {
                $stmt->execute([$docId, $stepOrder, $config['position'] . ' Approval', null, $assignee['id']]);
            }
        }
        $stepOrder++;
    }
}

function getWorkflowConfig($docType, $department)
{
    switch ($docType) {
        case 'proposal':
        case 'communication':
            return [
                ['position' => 'CSC Adviser', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'SSC President', 'table' => 'students', 'department_specific' => false],
                ['position' => 'Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'OIC OSA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'CPAO', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'VPAA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP', 'table' => 'employees', 'department_specific' => false]
            ];
        case 'saf':
            return [
                ['position' => 'Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'OIC OSA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'VPAA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP', 'table' => 'employees', 'department_specific' => false],
            ];
        case 'facility':
            return [
                ['position' => 'Dean', 'table' => 'employees', 'department_specific' => true],
                ['position' => 'OIC OSA', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP O', 'table' => 'employees', 'department_specific' => false],
                ['position' => 'EVP', 'table' => 'employees', 'department_specific' => false],
            ];
        default:
            return [];
    }
}

function getWorkflowAssignee($config, $department)
{
    global $db;

    $query = "SELECT id FROM {$config['table']} WHERE position = ?";
    $params = [$config['position']];

    if ($config['department_specific']) {
        $query .= " AND department = ?";
        $params[] = $department;
    }

    $query .= " LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>