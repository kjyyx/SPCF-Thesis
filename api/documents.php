<?php
/**
 * Documents API - Document Workflow Management
 * ============================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'includes/document_generators.php';
require_once ROOT_PATH . 'vendor/autoload.php';
require_once ROOT_PATH . 'includes/utilities.php';

header('Content-Type: application/json');

// --- Global Handlers ---
set_exception_handler(function ($e) {
    error_log("Uncaught exception in documents.php: " . $e->getMessage());
    sendJsonResponse(false, 'Internal server error: ' . $e->getMessage(), 500);
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error in documents.php [$errno]: $errstr in $errfile on line $errline");
    sendJsonResponse(false, 'Internal server error', 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        error_log("Fatal error in documents.php: " . print_r($error, true));
        sendJsonResponse(false, 'Fatal error: ' . $error['message'], 500);
    }
});

// --- Constants ---
if (!defined('DOC_STEP_TIMEOUT_DAYS'))
    define('DOC_STEP_TIMEOUT_DAYS', 5);
// if (!defined('DOC_TIMEOUT_MODE'))
//     define('DOC_TIMEOUT_MODE', 'reject');

// ------------------------------------------------------------------
// Local Helper Functions
// ------------------------------------------------------------------
function enforceTimeouts($db)
{
    try {
        // Find steps that have been pending for longer than the timeout limit.
        // It calculates the "pending start time" by looking at the previous step's acted_at time,
        // or the document's uploaded_at time if it's the very first step.
        $query = "
            SELECT ds.id as step_id, d.id as doc_id 
            FROM document_steps ds
            JOIN documents d ON ds.document_id = d.id
            WHERE ds.status = 'pending' 
            AND DATEDIFF(NOW(), COALESCE(
                (SELECT acted_at FROM document_steps prev 
                 WHERE prev.document_id = ds.document_id 
                 AND prev.step_order = ds.step_order - 1 
                 LIMIT 1),
                d.uploaded_at
            )) >= ?
        ";

        $stmt = $db->prepare($query);
        $stmt->execute([DOC_STEP_TIMEOUT_DAYS]);
        $expiredSteps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$expiredSteps)
            return;

        foreach ($expiredSteps as $row) {
            $db->beginTransaction();

            // 1. Mark the specific step as expired
            $db->prepare("UPDATE document_steps SET status = 'expired', acted_at = NOW(), note = CONCAT(COALESCE(note, ''), ' [Auto-timeout]') WHERE id = ?")
                ->execute([$row['step_id']]);

            // 2. Put the overall document on hold
            $db->prepare("UPDATE documents SET status = 'on_hold', updated_at = NOW() WHERE id = ?")
                ->execute([$row['doc_id']]);

            $db->commit();
        }
    } catch (Exception $e) {
        error_log("Error in enforceTimeouts: " . $e->getMessage());
    }
}

// ------------------------------------------------------------------
// Initialization & Routing
// ------------------------------------------------------------------

$db = (new Database())->getConnection();
$auth = new Auth();
$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? null;

if (!$userId || !$userRole)
    sendJsonResponse(false, 'Unauthorized', 401);

$currentUser = $auth->getUser($userId, $userRole);
if (!$currentUser)
    sendJsonResponse(false, 'Unauthorized', 401);

enforceTimeouts($db);

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($db, $currentUser);
        break;
    case 'POST':
        $isFormData = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
        $input = $isFormData ? $_POST : json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        try {
            switch ($action) {
                case 'sign':
                    signDocument($db, $currentUser, $input, $_FILES ?? null);
                    break;
                case 'reject':
                    rejectDocument($db, $currentUser, $input);
                    break;
                case 'create':
                    createDocument($db, $currentUser, $input);
                    break;
                case 'update_note':
                    updateNote($db, $currentUser, $input);
                    break;
                case 'add_comment':
                    addDocumentComment($db, $currentUser, $input);
                    break;
                case 'resubmit':
                    resubmitDocument($db, $currentUser, $input);
                    break;
                default:
                    sendJsonResponse(false, 'Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Document POST error: " . $e->getMessage());
            sendJsonResponse(false, 'Action failed: ' . $e->getMessage(), 500);
        }
        break;
    case 'DELETE':
        handleDelete($db, $currentUser);
        break;
    default:
        sendJsonResponse(false, 'Method not allowed', 405);
}

// ------------------------------------------------------------------
// Core Logic Controllers
// ------------------------------------------------------------------

function createDocument($db, $currentUser, $input)
{
    if ($currentUser['role'] !== 'student' || !in_array($currentUser['position'], ['Supreme Student Council President', 'College Student Council President'])) {
        sendJsonResponse(false, 'Only student council presidents can create documents', 403);
    }

    $docType = $input['doc_type'] ?? '';
    $studentId = $currentUser['id'];
    $data = $input['data'] ?? [];

    if (!$docType || !$studentId || !$data)
        sendJsonResponse(false, 'Missing required fields', 400);

    // FIX: Force the department to be the current user's department, and inject it into the data array
    $department = $currentUser['department'] ?? ($data['department'] ?? '');
    $data['department'] = $department;

    $departmentFull = getDepartmentFullName($department);
    $data['departmentFull'] = $departmentFull;

    try {
        $db->beginTransaction();

        $title = match ($docType) { 'facility' => $data['eventName'], 'communication' => $data['subject'], default => $data['title']} ?? 'Untitled';
        $desc = match ($docType) { 'proposal' => $data['rationale'] ?? '', 'facility' => $data['eventName'] ?? '', 'communication' => $data['subject'] ?? 'Communication Letter', default => $data['title'] ?? ''};

        $date = $data['date'] ?? ($data['reqDate'] ?? null);
        $implDate = $data['implDate'] ?? null;
        $eventName = $data['eventName'] ?? null;
        $eventDate = $data['eventDate'] ?? null;
        $venue = $data['venue'] ?? null;
        $scheduleSummary = !empty($data['schedule']) && $docType === 'proposal' ? json_encode($data['schedule']) : null;
        $earliestStartTime = $data['earliestStartTime'] ?? null;

        $stmt = $db->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, current_step, uploaded_at, department, departmentFull, date, implDate, eventName, eventDate, venue, schedule_summary, earliest_start_time, data) VALUES (?, ?, ?, ?, 'submitted', 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$studentId, $docType, $title, $desc, $department, $departmentFull, $date, $implDate, $eventName, $eventDate, $venue, $scheduleSummary, $earliestStartTime, json_encode($data)]);
        $docId = (int) $db->lastInsertId();

        $signatories = [];
        $templateMap = [];
        $workflowPositions = [];

        switch ($docType) {
            case 'communication':
                $data['from'] = trim($currentUser['first_name'] . ' ' . $currentUser['last_name']);
                $data['from_title'] = $currentUser['position'] . ', ' . $currentUser['department'];

                $signatories = [
                    'sig_cscp' => fetchSignatory($db, 'student', 'College Student Council President', $department),
                    'sig_csca' => fetchSignatory($db, 'employee', 'College Student Council Adviser', $department),
                    'sig_sscp' => fetchSignatory($db, 'student', 'Supreme Student Council President'),
                    'sig_dean' => fetchSignatory($db, 'employee', 'College Dean', $department)
                ];

                $templateMap = [
                    'College of Computing and Information Sciences' => ROOT_PATH . 'assets/templates/Communication Letter/College of Computing and Information Sciences (Communication Letter).docx',
                    'default' => ROOT_PATH . 'assets/templates/Communication Letter/Supreme Student Council (Communication Letter).docx'
                ];
                break;

            case 'proposal':
                // Ensure new proposal placeholder fields exist for template replacement.
                $data['support'] = $data['support'] ?? '';

                $signatories = [
                    'sig_cscp' => fetchSignatory($db, 'student', 'College Student Council President', $department),
                    'sig_csca' => fetchSignatory($db, 'employee', 'College Student Council Adviser', $department),
                    'sig_sscp' => fetchSignatory($db, 'student', 'Supreme Student Council President'),
                    'sig_dean' => fetchSignatory($db, 'employee', 'College Dean', $department)
                ];

                $workflowPositions = [
                    ['position' => 'College Student Council Adviser', 'table' => 'employees', 'dept' => true],
                    ['position' => 'College Dean', 'table' => 'employees', 'dept' => true],
                    ['position' => 'Supreme Student Council President', 'table' => 'students', 'dept' => false],
                    ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Center for Performing Arts Organization (CPAO)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Vice President for Academic Affairs (VPAA)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'dept' => false]
                ];

                $templateMap = [
                    'College of Computing and Information Sciences' => ROOT_PATH . 'assets/templates/Project Proposals/College of Computing and Information Sciences (Project Proposal).docx',
                    'default' => ROOT_PATH . 'assets/templates/Project Proposals/Supreme Student Council (Project Proposal).docx'
                ];
                break;

            case 'saf':
                $data['reqByName'] = trim($currentUser['first_name'] . ' ' . $currentUser['last_name']);
                $isCsc = ($currentUser['position'] === 'College Student Council President');
                $repSig = $isCsc ? fetchSignatory($db, 'student', 'College Student Council President', $department) : fetchSignatory($db, 'student', 'Supreme Student Council President');

                $signatories = [
                    'sig_rep' => $repSig['id'] ? $repSig : ['id' => null, 'name' => 'Student Representative'],
                    'sig_csca' => fetchSignatory($db, 'employee', 'College Student Council Adviser', $department),
                    'sig_dean' => fetchSignatory($db, 'employee', 'College Dean', $department),
                    'sig_oic' => fetchSignatory($db, 'employee', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'),
                    'sig_vpaa' => fetchSignatory($db, 'employee', 'Vice President for Academic Affairs (VPAA)'),
                    'sig_evp' => fetchSignatory($db, 'employee', 'Executive Vice-President / Student Services (EVP)'),
                    'sig_ap' => fetchSignatory($db, 'employee', 'Accounting Personnel (AP)')
                ];

                $workflowPositions = [
                    ['position' => 'College Dean', 'table' => 'employees', 'dept' => true],
                    ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Vice President for Academic Affairs (VPAA)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Accounting Personnel (AP)', 'table' => 'employees', 'dept' => false, 'doc_only' => true]
                ];

                $templateMap = ['default' => ROOT_PATH . 'assets/templates/SAF/SAF REQUEST.docx'];
                break;

            case 'facility':
                $signatories = [
                    'sig_cscp' => fetchSignatory($db, 'student', 'College Student Council President', $department),
                    'sig_ppfo' => fetchSignatory($db, 'employee', 'Physical Plant and Facilities Office (PPFO)'),
                    'sig_evp' => fetchSignatory($db, 'employee', 'Executive Vice-President / Student Services (EVP)'),
                    'sig_dean' => fetchSignatory($db, 'employee', 'College Dean', $department)
                ];

                $workflowPositions = [
                    ['position' => 'College Dean', 'table' => 'employees', 'dept' => true],
                    ['position' => 'Physical Plant and Facilities Office (PPFO)', 'table' => 'employees', 'dept' => false],
                    ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'dept' => false]
                ];

                $templateMap = ['default' => ROOT_PATH . 'assets/templates/Facility Request/FACILITY REQUEST.docx'];
                break;
        }

        foreach ($signatories as $key => $sig) {
            $data[$key] = $sig['name'];
        }

        // Format dates for Word Template
        foreach (['date', 'reqDate', 'implDate', 'eventDate', 'preEventDate', 'practiceDate', 'setupDate', 'cleanupDate', 'receivingDateFiled'] as $field) {
            if (isset($data[$field]))
                $data[$field] = formatDateForTemplate($data[$field]);
        }

        // --- NEW DYNAMIC TEMPLATE RESOLVER ---
        $templatePath = '';
        if ($docType === 'communication') {
            $expected = ROOT_PATH . "assets/templates/Communication Letter/{$departmentFull} (Communication Letter).docx";
            $default = ROOT_PATH . "assets/templates/Communication Letter/Supreme Student Council (Communication Letter).docx";
            $templatePath = file_exists($expected) ? $expected : $default;
        } elseif ($docType === 'proposal') {
            $expected = ROOT_PATH . "assets/templates/Project Proposals/{$departmentFull} (Project Proposal).docx";
            $default = ROOT_PATH . "assets/templates/Project Proposals/Supreme Student Council (Project Proposal).docx";
            $templatePath = file_exists($expected) ? $expected : $default;
        } elseif ($docType === 'saf') {
            $templatePath = ROOT_PATH . 'assets/templates/SAF/SAF REQUEST.docx';
        } elseif ($docType === 'facility') {
            $templatePath = ROOT_PATH . 'assets/templates/Facility Request/FACILITY REQUEST.docx';
        }

        // 4. Generate the Physical Document
        if (file_exists($templatePath)) {
            try {
                // Log for debugging so you can see exactly which template it chose
                error_log("Selected Template Path: " . $templatePath);

                $filledPath = fillDocxTemplate($templatePath, $data);
                $pdfPath = convertDocxToPdf($filledPath);
                $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")->execute(['uploads/' . basename($pdfPath), $docId]);
            } catch (Exception $e) {
                error_log("Template filling failed for $docType: " . $e->getMessage());
                $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")->execute(['uploads/fallback.pdf', $docId]);
            }
        } else {
            error_log("CRITICAL: No template found at $templatePath");
        }

        $stepOrder = 1;
        $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, 'Document Creator Signature', ?, ?, 'pending')")
            ->execute([$docId, $stepOrder++, ($currentUser['role'] === 'employee' ? $currentUser['id'] : null), ($currentUser['role'] === 'student' ? $currentUser['id'] : null)]);

        if ($docType !== 'communication') {
            // --- NEW: Bypass CSC/Dean/SSC steps if the creator is the SSC President ---
            if ($currentUser['position'] === 'Supreme Student Council President') {
                $bypassedRoles = [
                    'College Student Council Adviser', 
                    'College Dean', 
                    'Supreme Student Council President'
                ];
                
                // Filter out the bypassed roles from the workflow array
                $workflowPositions = array_filter($workflowPositions, function($wp) use ($bypassedRoles) {
                    return !in_array($wp['position'], $bypassedRoles);
                });
            }

            $approvalReached = false;
            foreach ($workflowPositions as $wp) {
                $pos = $wp['position'];
                $isDocOnly = $approvalReached || !empty($wp['doc_only']);
                $stepName = $isDocOnly ? "$pos (Documentation Only)" : "$pos Approval";

                $assignee = fetchSignatory($db, $wp['table'] === 'students' ? 'student' : 'employee', $pos, $wp['dept'] ? $department : null);

                if ($assignee['id']) {
                    $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, ?, ?, ?, 'queued')")
                        ->execute([$docId, $stepOrder++, $stepName, ($wp['table'] === 'employees' ? $assignee['id'] : null), ($wp['table'] === 'students' ? $assignee['id'] : null)]);
                }
                if ($pos === 'Executive Vice-President / Student Services (EVP)')
                    $approvalReached = true;
            }
        } else {
            // Dynamic communication letter workflow based on Noted/Approved lists
            $notedList = $data['notedList'] ?? [];
            foreach ($notedList as $person) {
                $assignee = fetchSignatory($db, 'employee', $person['title']);
                if ($assignee['id']) {
                    $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, status) VALUES (?, ?, ?, ?, 'queued')")
                        ->execute([$docId, $stepOrder++, "Noted By: " . $person['title'], $assignee['id']]);
                }
            }
            $approvedList = $data['approvedList'] ?? [];
            foreach ($approvedList as $person) {
                $assignee = fetchSignatory($db, 'employee', $person['title']);
                if ($assignee['id']) {
                    $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, status) VALUES (?, ?, ?, ?, 'queued')")
                        ->execute([$docId, $stepOrder++, "Approved By: " . $person['title'], $assignee['id']]);
                }
            }
        }

        $db->commit();
        addAuditLog($db, 'DOCUMENT_CREATED', 'Document Management', "Document created: $docType - $title", $docId, 'Document', 'INFO');
        sendJsonResponse(true, ['document_id' => $docId, 'needs_signing' => true]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function signDocument($db, $currentUser, $input, $files = null)
{
    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $notes = $input['notes'] ?? '';
    $signatureMap = $input['signature_map'] ?? null;

    if ($signatureMap && is_string($signatureMap)) {
        $signatureMap = json_decode($signatureMap, true);
    }

    if (!$documentId)
        sendJsonResponse(false, 'Document ID is required', 400);

    $assignCol = ($currentUser['role'] === 'employee') ? 'assigned_to_employee_id' : 'assigned_to_student_id';
    $sigCol = ($currentUser['role'] === 'employee') ? 'employee_id' : 'student_id';

    if (!$stepId) {
        $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND $assignCol = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
        $q->execute([$documentId, $currentUser['id']]);
        $stepId = $q->fetchColumn();
        if (!$stepId)
            sendJsonResponse(false, 'No pending step assigned to you for this document', 403);
    }

    $hierarchyCheckStmt = $db->prepare("SELECT COUNT(*) as pending_previous FROM document_steps WHERE document_id = ? AND step_order < (SELECT step_order FROM document_steps WHERE id = ?) AND status NOT IN ('completed', 'skipped')");
    $hierarchyCheckStmt->execute([$documentId, $stepId]);
    if ($hierarchyCheckStmt->fetchColumn() > 0) {
        sendJsonResponse(false, 'Cannot sign this step. Previous steps must be completed first.', 403);
    }

    $docStmt = $db->prepare("SELECT file_path, title, doc_type, data, department, date, earliest_start_time, venue FROM documents WHERE id = ?");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc)
        sendJsonResponse(false, 'Document not found', 404);

    if (isset($files['signed_pdf']) && $files['signed_pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = ROOT_PATH . 'uploads/';
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0755, true);

        $safeTitle = substr(preg_replace('/[^A-Za-z0-9\-_]/', '_', $doc['title'] ?? 'Untitled'), 0, 50);
        $newFileName = $safeTitle . '_' . $documentId . '_' . time() . '.pdf';
        $newPath = $uploadDir . $newFileName;

        if (move_uploaded_file($files['signed_pdf']['tmp_name'], $newPath)) {
            $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")->execute(['uploads/' . $newFileName, $documentId]);
        } else {
            sendJsonResponse(false, 'Failed to save signed document', 500);
        }
    }

    $maxRetries = 3;
    $retryCount = 0;
    $isFullyApproved = false;

    do {
        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO document_signatures (document_id, step_id, $sigCol, status, signed_at) VALUES (?, ?, ?, 'signed', NOW()) ON DUPLICATE KEY UPDATE status = 'signed', signed_at = NOW()")
                ->execute([$documentId, $stepId, $currentUser['id']]);

            $db->prepare("UPDATE document_steps SET status = 'completed', acted_at = NOW(), note = ? WHERE id = ? AND $assignCol = ?")
                ->execute([$notes, $stepId, $currentUser['id']]);

            // SAF Logic
            $stepStmt = $db->prepare("SELECT name FROM document_steps WHERE id = ?");
            $stepStmt->execute([$stepId]);
            $currentStep = $stepStmt->fetch(PDO::FETCH_ASSOC);

            if ($doc['doc_type'] === 'saf' && $currentStep && strpos($currentStep['name'], 'EVP') !== false) {
                $existingTransStmt = $db->prepare("SELECT COUNT(*) FROM saf_transactions WHERE transaction_description LIKE ?");
                $existingTransStmt->execute(["%Document ID: {$documentId}%"]);
                if ($existingTransStmt->fetchColumn() == 0) {
                    $data = json_decode($doc['data'], true);
                    if ($data) {
                        $reqSSC = $data['reqSSC'] ?? 0;
                        $reqCSC = $data['reqCSC'] ?? 0;
                        $deptId = strtolower(trim($data['department']));
                        $reverseDeptMap = ['supreme student council' => 'ssc', 'college of engineering' => 'coe']; // Truncated for brevity
                        $deptId = $reverseDeptMap[$deptId] ?? $deptId;

                        $transStmt = $db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date, created_by) VALUES (?, 'deduct', ?, ?, NOW(), ?)");

                        if ($reqSSC > 0) {
                            $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = 'ssc'")->execute([$reqSSC]);
                            $transStmt->execute(['ssc', $reqSSC, "SAF Request (SSC) - Document ID: {$documentId}", $currentUser['id']]);
                        }
                        if ($reqCSC > 0 && $deptId) {
                            $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = ?")->execute([$reqCSC, $deptId]);
                            $transStmt->execute([$deptId, $reqCSC, "SAF Request (CSC) - Document ID: {$documentId}", $currentUser['id']]);
                        }
                    }
                }
            }

            if ($signatureMap) {
                $db->prepare("UPDATE document_steps SET signature_map = ? WHERE id = ?")->execute([json_encode($signatureMap), $stepId]);
            }

            $currentStepOrder = $db->prepare("SELECT step_order FROM document_steps WHERE id = ?");
            $currentStepOrder->execute([$stepId]);
            if ($order = $currentStepOrder->fetchColumn()) {
                // CHANGED: Look for 'queued' instead of 'skipped' to wake up the next step
                $db->prepare("UPDATE document_steps SET status = 'pending' WHERE document_id = ? AND step_order = ? AND status = 'queued'")
                    ->execute([$documentId, $order + 1]);
            }

            $progress = $db->query("SELECT COUNT(*) as total, SUM(status = 'completed') as done FROM document_steps WHERE document_id = $documentId")->fetch(PDO::FETCH_ASSOC);
            if ($progress['total'] == $progress['done']) {
                $isFullyApproved = true;
                $db->prepare("UPDATE documents SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$documentId]);
            } else {
                // NEW: If it's not fully approved yet, update the main document status to 'in_progress'
                $db->prepare("UPDATE documents SET status = 'in_progress', updated_at = NOW() WHERE id = ? AND status IN ('submitted', 'on_hold')")->execute([$documentId]);
            }

            addAuditLog($db, 'DOCUMENT_SIGNED', 'Document Management', "Document signed by {$currentUser['first_name']}", $documentId, 'Document', 'INFO');
            $db->commit();
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

    // Asynchronous Event Creation for fully approved proposals
    // Asynchronous Event Creation for fully approved proposals
    if ($isFullyApproved && $doc['doc_type'] === 'proposal') {
        $docData = json_decode($doc['data'], true) ?: [];

        // 1. Extract schedules from schedule_summary (or fallback to data->schedule)
        $schedules = [];
        if (!empty($doc['schedule_summary'])) {
            $schedules = json_decode($doc['schedule_summary'], true) ?: [];
        } elseif (!empty($docData['schedule']) && is_array($docData['schedule'])) {
            $schedules = $docData['schedule'];
        }

        // Fallback just in case the array is completely empty
        if (empty($schedules)) {
            $schedules[] = [
                'date' => !empty($doc['date']) ? $doc['date'] : date('Y-m-d'),
                'time' => !empty($doc['earliest_start_time']) ? $doc['earliest_start_time'] : null
            ];
        }

        // 2. Prepare common event data
        $eventTitle = $doc['title'];
        $desc = !empty($doc['description']) ? $doc['description'] : ($docData['rationale'] ?? 'Approved Project Proposal');
        $venue = !empty($doc['venue']) ? $doc['venue'] : ($docData['venue'] ?? 'TBA');

        // Handle ENUM constraint for created_by_role ('admin', 'employee')
        // If a student triggered the final approval, we fallback to 'employee' to satisfy the DB constraint
        $creatorRole = in_array($currentUser['role'] ?? '', ['admin', 'employee']) ? $currentUser['role'] : 'employee';

        try {
            // 3. Prepare the exact INSERT statement based on your schema
            // NOTE: 'department' is omitted since your schema uses 'unit_id' (int). 
            // If you have a way to map department names to unit_id, you can add it here.
            $insertEvent = $db->prepare("
                INSERT INTO events 
                (title, description, venue, event_date, event_time, created_by, created_by_role, source_document_id, approved, approved_by, approved_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ");

            // 4. Loop through the schedule array and create an event for each day
            foreach ($schedules as $sched) {
                $eventDate = !empty($sched['date']) ? $sched['date'] : date('Y-m-d');
                // The JSON uses "time", so we fetch that.
                $eventTime = !empty($sched['time']) ? $sched['time'] : null;

                // Check for duplicate to prevent spamming the calendar if re-run
                $checkStmt = $db->prepare("SELECT id FROM events WHERE title = ? AND event_date = ? AND event_time = ? LIMIT 1");
                $checkStmt->execute([$eventTitle, $eventDate, $eventTime]);

                if (!$checkStmt->fetch()) {
                    $insertEvent->execute([
                        $eventTitle,
                        $desc,
                        $venue,
                        $eventDate,
                        $eventTime,
                        $currentUser['id'],
                        $creatorRole,
                        $documentId,
                        $currentUser['id'] // approved_by
                    ]);
                }
            }
        } catch (Exception $evErr) {
            error_log("Failed to auto-create event schedules: " . $evErr->getMessage());
        }
    }

    sendJsonResponse(true, ['message' => 'Document signed successfully', 'step_id' => $stepId]);
}

function rejectDocument($db, $currentUser, $input)
{
    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $reason = $input['reason'] ?? '';

    if (!$documentId || empty($reason))
        sendJsonResponse(false, 'Document ID and reason required', 400);

    $assignCol = ($currentUser['role'] === 'employee') ? 'assigned_to_employee_id' : 'assigned_to_student_id';
    $sigCol = ($currentUser['role'] === 'employee') ? 'employee_id' : 'student_id';

    if (!$stepId) {
        $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND $assignCol = ? AND status = 'pending' LIMIT 1");
        $q->execute([$documentId, $currentUser['id']]);
        $stepId = $q->fetchColumn();
        if (!$stepId)
            sendJsonResponse(false, 'No pending step assigned to you', 403);
    }

    try {
        $db->beginTransaction();

        $db->prepare("INSERT INTO document_signatures (document_id, step_id, $sigCol, status, signed_at) VALUES (?, ?, ?, 'rejected', NOW()) ON DUPLICATE KEY UPDATE status = 'rejected', signed_at = NOW()")
            ->execute([$documentId, $stepId, $currentUser['id']]);

        $db->prepare("UPDATE document_steps SET status = 'rejected', acted_at = NOW(), note = ? WHERE id = ? AND $assignCol = ?")
            ->execute([$reason, $stepId, $currentUser['id']]);

        $db->prepare("UPDATE documents SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$documentId]);

        addAuditLog($db, 'DOCUMENT_REJECTED', 'Document Management', "Rejected by {$currentUser['first_name']}: $reason", $documentId, 'Document', 'WARNING');
        $db->commit();
        sendJsonResponse(true, ['message' => 'Document rejected', 'step_id' => $stepId]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function resubmitDocument($db, $currentUser, $input)
{
    $documentId = $input['document_id'] ?? 0;
    if (!$documentId)
        sendJsonResponse(false, 'Document ID required', 400);

    // Verify ownership and status
    $stmt = $db->prepare("SELECT student_id, status FROM documents WHERE id = ?");
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc)
        sendJsonResponse(false, 'Document not found', 404);
    if ($doc['student_id'] != $currentUser['id'])
        sendJsonResponse(false, 'Only the creator can resubmit', 403);
    if ($doc['status'] !== 'on_hold')
        sendJsonResponse(false, 'Document is not on hold', 400);

    try {
        $db->beginTransaction();

        // Find the specific expired step so we know WHICH timestamp to reset
        $stmt = $db->prepare("SELECT step_order FROM document_steps WHERE document_id = ? AND status = 'expired' LIMIT 1");
        $stmt->execute([$documentId]);
        $expiredStepOrder = $stmt->fetchColumn();

        if ($expiredStepOrder) {
            
            // 1. RESET THE CLOCK! (This stops the instant re-timeout bug)
            if ($expiredStepOrder == 1) {
                // If it's the very first step, bump the document's creation time to RIGHT NOW
                $db->prepare("UPDATE documents SET uploaded_at = NOW(), updated_at = NOW() WHERE id = ?")
                   ->execute([$documentId]);
            } else {
                // If it's a later step, bump the PREVIOUS step's completion time to RIGHT NOW
                $db->prepare("UPDATE document_steps SET acted_at = NOW() WHERE document_id = ? AND step_order = ?")
                   ->execute([$documentId, $expiredStepOrder - 1]);
            }

            // 2. Re-activate the expired step & clean up the note
            $db->prepare("UPDATE document_steps SET status = 'pending', note = REPLACE(note, ' [Auto-timeout]', '') WHERE document_id = ? AND step_order = ?")
                ->execute([$documentId, $expiredStepOrder]);

            // 3. Determine new document status
            $newDocStatus = ($expiredStepOrder == 1) ? 'submitted' : 'in_progress';

            // 4. Update the overall document status
            $db->prepare("UPDATE documents SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newDocStatus, $documentId]);

            // 5. Log it
            addAuditLog($db, 'DOCUMENT_RESUBMITTED', 'Document Management', "Document resubmitted from hold", $documentId, 'Document', 'INFO');

            $db->commit();
            sendJsonResponse(true, ['message' => 'Document resubmitted successfully']);
            
        } else {
            $db->rollBack();
            sendJsonResponse(false, 'No expired step found to resubmit.', 400);
        }

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function updateNote($db, $currentUser, $input)
{
    $documentId = $input['document_id'] ?? 0;
    $stepId = $input['step_id'] ?? 0;
    $note = trim($input['note'] ?? '');

    if (!$documentId || !$stepId)
        sendJsonResponse(false, 'Invalid parameters', 400);

    $checkStmt = $db->prepare("SELECT id FROM document_steps WHERE id = ? AND (assigned_to_employee_id = ? OR assigned_to_student_id = ?)");
    $checkStmt->execute([$stepId, $currentUser['id'], $currentUser['id']]);
    if (!$checkStmt->fetch())
        sendJsonResponse(false, 'Not authorized', 403);

    $db->prepare("UPDATE document_steps SET note = ? WHERE id = ?")->execute([$note, $stepId]);
    sendJsonResponse(true);
}

function ensureThreadedDocumentNotesSchema($db)
{
    $checkStmt = $db->prepare("SHOW COLUMNS FROM document_notes LIKE 'parent_note_id'");
    $checkStmt->execute();
    if (!$checkStmt->fetch()) {
        $db->exec("ALTER TABLE document_notes ADD COLUMN parent_note_id BIGINT(20) NULL AFTER note");
        $db->exec("ALTER TABLE document_notes ADD KEY idx_parent_note_id (parent_note_id)");
    }
}

function getDocumentComments($db, $currentUser, $documentId)
{
    if (!$documentId)
        sendJsonResponse(false, 'Invalid document id', 400);

    ensureThreadedDocumentNotesSchema($db);

    $stmt = $db->prepare("SELECT n.id, n.document_id, n.parent_note_id, n.note, n.created_at, n.author_id, n.author_role,
                                 CASE
                                     WHEN n.author_role = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                                     WHEN n.author_role = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                                     WHEN n.author_role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                                 END AS author_name
                          FROM document_notes n
                          LEFT JOIN employees e ON n.author_role = 'employee' AND n.author_id = e.id
                          LEFT JOIN students s ON n.author_role = 'student' AND n.author_id = s.id
                          LEFT JOIN administrators a ON n.author_role = 'admin' AND n.author_id = a.id
                          WHERE n.document_id = ? ORDER BY n.created_at ASC");
    $stmt->execute([$documentId]);

    $comments = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'document_id' => (int) $row['document_id'],
            'parent_id' => $row['parent_note_id'] ? (int) $row['parent_note_id'] : null,
            'comment' => $row['note'],
            'created_at' => $row['created_at'],
            'author_name' => trim($row['author_name'] ?: 'Unknown')
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    sendJsonResponse(true, ['comments' => $comments]);
}

function addDocumentComment($db, $currentUser, $input)
{
    $documentId = (int) ($input['document_id'] ?? 0);
    $comment = trim($input['comment'] ?? '');
    $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;

    if (!$documentId || $comment === '')
        sendJsonResponse(false, 'Document and comment required', 400);

    ensureThreadedDocumentNotesSchema($db);

    $db->prepare("INSERT INTO document_notes (document_id, author_id, author_role, note, parent_note_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$documentId, $currentUser['id'], $currentUser['role'], $comment, $parentId]);

    sendJsonResponse(true, ['comment_id' => $db->lastInsertId()]);
}

function handleDelete($db, $currentUser)
{
    $id = $_GET['id'] ?? 0;
    if (!$id)
        sendJsonResponse(false, 'Document ID required', 400);
    if ($currentUser['role'] !== 'student')
        sendJsonResponse(false, 'Only creators can delete', 403);

    $stmt = $db->prepare("SELECT student_id FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() != $currentUser['id'])
        sendJsonResponse(false, 'You can only delete your own documents', 403);

    $db->prepare("DELETE FROM document_signatures WHERE document_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM document_steps WHERE document_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);

    addAuditLog($db, 'DOCUMENT_DELETED', 'Document Management', "Deleted document $id", $id, 'Document', 'WARNING');
    sendJsonResponse(true);
}

function handleGet($db, $currentUser)
{
    $action = $_GET['action'] ?? null;
    $docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($action === 'approved_events') {
        getApprovedEvents($db);
    } elseif ($action === 'get_comments' && $docId) {
        getDocumentComments($db, $currentUser, $docId); // Already exists!
    } elseif ($action === 'my_documents') {
        getMyDocuments($db, $currentUser);
    } elseif ($action === 'document_details' && $docId) {
        getStudentDocumentDetails($db, $currentUser, $docId);
    } elseif ($docId) {
        getGeneralDocumentDetails($db, $currentUser, $docId);
    } else {
        getAssignedDocuments($db, $currentUser);
    }
}

function getApprovedEvents($db)
{
    $stmt = $db->query("
        SELECT id, title, doc_type, department, `date` as event_date, 
               earliest_start_time as event_time, venue
        FROM documents
        WHERE status = 'approved' AND doc_type = 'proposal'
        ORDER BY uploaded_at DESC
    ");
    sendJsonResponse(true, ['events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getStudentDocumentDetails($db, $currentUser, $docId)
{
    if ($currentUser['role'] !== 'student')
        sendJsonResponse(false, 'Access denied', 403);

    $docStmt = $db->prepare("SELECT d.*, s.first_name, s.last_name FROM documents d JOIN students s ON d.student_id = s.id WHERE d.id = ? AND d.student_id = ?");
    $docStmt->execute([$docId, $currentUser['id']]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc)
        sendJsonResponse(false, 'Document not found', 404);

    // Fetch Workflow History
    $historyStmt = $db->prepare("SELECT id, status, name, acted_at, signature_map FROM document_steps WHERE document_id = ? ORDER BY step_order ASC");
    $historyStmt->execute([$docId]);
    $workflow_history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Notes
    $notesStmt = $db->prepare("
        SELECT ds.id, ds.note, ds.acted_at as created_at, ds.status as step_status,
               COALESCE(CONCAT(e.first_name, ' ', e.last_name), CONCAT(s.first_name, ' ', s.last_name), 'Unknown') as created_by_name
        FROM document_steps ds
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students s ON ds.assigned_to_student_id = s.id
        WHERE ds.document_id = ? AND ds.note IS NOT NULL AND ds.note != ''
        ORDER BY ds.acted_at ASC
    ");
    $notesStmt->execute([$docId]);

    // Map the schedule JSON safely
    $scheduleData = [];
    if ($doc['doc_type'] === 'proposal') {
        $scheduleData = !empty($doc['schedule_summary']) ? json_decode($doc['schedule_summary'], true) : (json_decode($doc['data'] ?? '{}', true)['schedule'] ?? []);
    }

    sendJsonResponse(true, [
        'document' => [
            'id' => $doc['id'],
            'document_name' => $doc['title'],
            'doc_type' => $doc['doc_type'],
            'status' => $doc['status'],
            'description' => $doc['description'],
            'file_path' => $doc['file_path'],
            'schedule' => $scheduleData,
            'workflow_history' => array_map(fn($s) => [
                'created_at' => $s['acted_at'] ?: date('Y-m-d H:i:s'),
                'status' => $s['status'] ?: 'pending',
                'action' => $s['status'] === 'completed' ? 'Approved' : ($s['status'] === 'rejected' ? 'Rejected' : 'Pending'),
                'office_name' => $s['name'] ?: 'Unknown',
                'signature_map' => $s['signature_map']
            ], $workflow_history),
            'notes' => $notesStmt->fetchAll(PDO::FETCH_ASSOC)
        ]
    ]);
}

function getGeneralDocumentDetails($db, $currentUser, $docId)
{
    $docStmt = $db->prepare("SELECT d.*, s.id AS student_id, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.department AS student_department FROM documents d JOIN students s ON d.student_id = s.id WHERE d.id = ?");
    $docStmt->execute([$docId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc)
        sendJsonResponse(false, 'Document not found', 404);

    $stepsStmt = $db->prepare("
        SELECT ds.*, e.first_name AS emp_first, e.last_name AS emp_last,
               s.first_name AS stu_first, s.last_name AS stu_last,
               dsg.status AS signature_status, dsg.signed_at
        FROM document_steps ds
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students s ON ds.assigned_to_student_id = s.id
        LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id 
            AND ((dsg.employee_id = ds.assigned_to_employee_id) OR (dsg.student_id = ds.assigned_to_student_id))
        WHERE ds.document_id = ? ORDER BY ds.step_order ASC
    ");
    $stepsStmt->execute([$docId]);

    $steps = [];
    while ($s = $stepsStmt->fetch(PDO::FETCH_ASSOC)) {
        // Safely retrieve the assignee ID and build the name
        $assignee_id = $s['assigned_to_employee_id'] ?: $s['assigned_to_student_id'];
        $assignee_name = 'Unknown';
        if ($s['assigned_to_employee_id']) {
            $assignee_name = trim(($s['emp_first'] ?? '') . ' ' . ($s['emp_last'] ?? ''));
        } elseif ($s['assigned_to_student_id']) {
            $assignee_name = trim(($s['stu_first'] ?? '') . ' ' . ($s['stu_last'] ?? ''));
        }

        $steps[] = [
            'id' => (int) $s['id'],
            'step_order' => (int) $s['step_order'],
            'name' => $s['name'],
            'status' => $s['status'],
            'note' => $s['note'],
            'acted_at' => $s['acted_at'],
            'signature_map' => $s['signature_map'],
            'assignee_id' => $assignee_id,                  // <-- Added back
            'assignee_name' => $assignee_name ?: 'Unknown', // <-- Added back
            'assignee_type' => $s['assigned_to_employee_id'] ? 'employee' : 'student',
            'signature_status' => $s['signature_status'],
            'signed_at' => $s['signed_at']
        ];
    }

    $filePath = $doc['file_path'];
    if ($filePath && strpos($filePath, 'http') !== 0) {
        $filePath = strpos($filePath, 'uploads/') !== 0 ? 'uploads/' . basename($filePath) : $filePath;
        $filePath = BASE_URL . str_replace(basename($filePath), rawurlencode(basename($filePath)), $filePath);
    }

    $payload = [
        'id' => (int) $doc['id'],
        'title' => $doc['title'],
        'doc_type' => $doc['doc_type'],
        'status' => $doc['status'],
        'student' => [
            'id' => $doc['student_id'],
            'name' => $doc['student_name'],
            'department' => $doc['student_department']
        ],
        'workflow' => $steps,
        'file_path' => $filePath
    ];

    // Ensure schedule data is passed correctly if it's a proposal
    if ($doc['doc_type'] === 'proposal') {
        $scheduleData = !empty($doc['schedule_summary']) ? json_decode($doc['schedule_summary'], true) : (json_decode($doc['data'] ?? '{}', true)['schedule'] ?? []);
        if (!empty($scheduleData)) {
            $payload['schedule'] = $scheduleData;
        }
    }

    http_response_code(200);
    echo json_encode($payload);
    exit;
}

function getMyDocuments($db, $currentUser)
{
    $studentId = $currentUser['id'];

    // 1. Fetch Student's Documents & Steps
    $stmt = $db->prepare("
        SELECT d.id, d.title, d.doc_type, d.description, d.status, d.uploaded_at,
               ds.step_order, ds.name AS step_name, ds.status AS step_status, ds.note, ds.acted_at,
               e.first_name AS emp_first, e.last_name AS emp_last,
               st.first_name AS stu_first, st.last_name AS stu_last
        FROM documents d
        LEFT JOIN document_steps ds ON d.id = ds.document_id
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students st ON ds.assigned_to_student_id = st.id
        WHERE d.student_id = ?
        ORDER BY d.uploaded_at DESC, ds.step_order ASC
    ");
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $documents = [];
    foreach ($rows as $row) {
        $docId = $row['id'];
        if (!isset($documents[$docId])) {
            $current_location = 'Pending Setup';
            $current_assignee = 'Unassigned';

            // Find exactly whose desk the document is currently sitting on
            foreach ($rows as $stepRow) {
                if ($stepRow['id'] === $docId) {
                    // Combine the first and last name safely
                    $assigneeName = trim(($stepRow['emp_first'] ?? $stepRow['stu_first'] ?? '') . ' ' . ($stepRow['emp_last'] ?? $stepRow['stu_last'] ?? ''));
                    if (!$assigneeName)
                        $assigneeName = 'Unassigned';

                    if ($row['status'] === 'approved') {
                        $current_location = 'Completed';
                        $current_assignee = 'All Signatories';
                    } elseif ($stepRow['step_status'] === 'rejected') {
                        $current_location = $stepRow['step_name'];
                        $current_assignee = $assigneeName;
                        break;
                    } elseif (in_array($stepRow['step_status'], ['pending', 'expired'])) {
                        $current_location = $stepRow['step_name'];
                        $current_assignee = $assigneeName;
                        break; // Stop at the first active pending step
                    }
                }
            }

            $documents[$docId] = [
                'id' => $row['id'],
                'document_name' => $row['title'],
                'doc_type' => $row['doc_type'],
                'document_type' => $row['doc_type'],
                'status' => $row['status'],
                'current_location' => $current_location,
                'current_assignee' => $current_assignee, // Pass the signee's name to JS!
                'created_at' => $row['uploaded_at'],
                'updated_at' => $row['uploaded_at'],
                'description' => $row['description'],
                'workflow_history' => [],
                'notes' => []
            ];
        }

        if ($row['step_order']) {
            $documents[$docId]['workflow_history'][] = [
                'created_at' => $row['acted_at'] ?: $row['uploaded_at'],
                'action' => $row['step_status'] === 'completed' ? 'Approved' : ($row['step_status'] === 'rejected' ? 'Rejected' : 'Pending'),
                'office_name' => $row['step_name'],
                'from_office' => $row['step_name']
            ];
        }
    }

    // 2. Fetch Notes for these Documents
    if (!empty($documents)) {
        $docIds = array_keys($documents);
        $placeholders = str_repeat('?,', count($docIds) - 1) . '?';
        $notesStmt = $db->prepare("
            SELECT n.id, n.note, n.created_at, d.id as document_id,
                   COALESCE(CONCAT(e.first_name, ' ', e.last_name), CONCAT(s.first_name, ' ', s.last_name), CONCAT(a.first_name, ' ', a.last_name), 'Unknown') as created_by_name,
                   COALESCE(e.position, s.position, a.position, '') as position,
                   NULL as step_status
            FROM document_notes n
            JOIN documents d ON n.document_id = d.id
            LEFT JOIN employees e ON n.author_id = e.id AND n.author_role = 'employee'
            LEFT JOIN students s ON n.author_id = s.id AND n.author_role = 'student'
            LEFT JOIN administrators a ON n.author_id = a.id AND n.author_role = 'admin'
            WHERE n.document_id IN ($placeholders)
            UNION ALL
            SELECT CONCAT('step_', ds.id) as id, ds.note, ds.acted_at as created_at, ds.document_id,
                   COALESCE(CONCAT(e.first_name, ' ', e.last_name), CONCAT(s.first_name, ' ', s.last_name), 'Unknown') as created_by_name,
                   COALESCE(e.position, s.position, '') as position, ds.status as step_status
            FROM document_steps ds
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            LEFT JOIN students s ON ds.assigned_to_student_id = s.id
            WHERE ds.document_id IN ($placeholders) AND ds.note IS NOT NULL AND ds.note != ''
            ORDER BY created_at ASC
        ");
        $notesStmt->execute(array_merge($docIds, $docIds));

        foreach ($notesStmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
            if (isset($documents[$note['document_id']])) {
                $documents[$note['document_id']]['notes'][] = [
                    'id' => $note['id'],
                    'comment' => $note['note'],
                    'created_by_name' => $note['created_by_name'],
                    'created_at' => $note['created_at'],
                    'position' => $note['position'] ?: '',
                    'is_rejection' => ($note['step_status'] === 'rejected')
                ];
            }
        }
    }

    // 3. Fetch Materials (Pubmats)
    $materialsStmt = $db->prepare("
        SELECT m.id, m.title, 'publication' as doc_type, m.description, m.status, m.uploaded_at,
               ms.step_order, ms.status as step_status, ms.note, ms.completed_at as acted_at,
               CASE ms.step_order WHEN 1 THEN 'College Student Council Adviser Approval' WHEN 2 THEN 'College Dean Approval' WHEN 3 THEN 'OIC-OSA Approval' ELSE 'Unknown' END as step_name
        FROM materials m
        LEFT JOIN materials_steps ms ON m.id = ms.material_id
        WHERE m.student_id = ?
        ORDER BY m.uploaded_at DESC, ms.step_order ASC
    ");
    $materialsStmt->execute([$studentId]);

    $materials = [];
    foreach ($materialsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $matId = $row['id'];
        if (!isset($materials[$matId])) {
            $materials[$matId] = [
                'id' => 'MAT-' . $row['id'],
                'original_id' => $row['id'],
                'document_name' => $row['title'],
                'doc_type' => 'publication',
                'document_type' => 'publication',
                'status' => $row['status'],
                'current_location' => $row['status'] === 'completed' ? 'Completed' : 'Pending',
                'created_at' => $row['uploaded_at'],
                'updated_at' => $row['uploaded_at'],
                'description' => $row['description'] ?? '',
                'workflow_history' => [],
                'notes' => [],
                'is_material' => true
            ];
        }
        if ($row['step_order']) {
            $materials[$matId]['workflow_history'][] = [
                'created_at' => $row['acted_at'] ?: $row['uploaded_at'],
                'action' => $row['step_status'] === 'completed' ? 'Approved' : ($row['step_status'] === 'rejected' ? 'Rejected' : 'Pending'),
                'office_name' => $row['step_name'],
                'from_office' => $row['step_name']
            ];
        }
    }

    // 4. Merge and Sort
    $allItems = array_merge(array_values($documents), array_values($materials));
    usort($allItems, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

    sendJsonResponse(true, ['documents' => $allItems]);
}

function getAssignedDocuments($db, $currentUser)
{
    $userId = $currentUser['id'];
    $isEmployee = ($currentUser['role'] === 'employee');
    $isStudentCouncil = ($currentUser['role'] === 'student' && in_array($currentUser['position'], ['Supreme Student Council President', 'College Student Council President']));
    $isAccounting = ($isEmployee && stripos($currentUser['position'] ?? '', 'Accounting') !== false);

    $assignmentCondition = $isEmployee ? "ds_target.assigned_to_employee_id = ?" : "ds_target.assigned_to_student_id = ?";
    $docTypeFilter = $isAccounting ? "AND d.doc_type = 'saf'" : "";

    if (!$isEmployee && !$isStudentCouncil) {
        $completionLogic = "WHEN ds.status = 'pending' AND ds.assigned_to_student_id = ? THEN 0 ELSE 1";
        $params = [$userId, $userId];
    } else {
        $pendingCheckCondition = $isEmployee ? "ds_check.assigned_to_employee_id = ?" : "ds_check.assigned_to_student_id = ?";
        $completionLogic = "WHEN EXISTS (SELECT 1 FROM document_steps ds_check WHERE ds_check.document_id = d.id AND {$pendingCheckCondition} AND ds_check.status = 'pending') THEN 0 ELSE 1";
        $params = [$userId, $userId];
    }

    $query = "
        SELECT DISTINCT d.id, d.title, d.doc_type, d.description, d.status, d.current_step, d.uploaded_at, d.date, d.earliest_start_time, d.file_path,
               s.id as student_id, CONCAT(s.first_name, ' ', s.last_name) as student_name, s.department as student_department,
               CASE WHEN d.status IN ('approved', 'rejected') THEN 1 {$completionLogic} END as user_action_completed,
               ds.id as step_id, ds.step_order, ds.name as step_name, ds.status as step_status, ds.note, ds.acted_at,
               ds.assigned_to_employee_id, ds.assigned_to_student_id,
               CASE 
                   WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                   WHEN st.id IS NOT NULL THEN CONCAT(st.first_name, ' ', st.last_name)
                   ELSE 'Unknown'
               END as assignee_name,
               dsg.status as signature_status, dsg.signed_at
        FROM documents d
        LEFT JOIN students s ON d.student_id = s.id
        JOIN document_steps ds ON d.id = ds.document_id
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students st ON ds.assigned_to_student_id = st.id
        LEFT JOIN document_signatures dsg ON dsg.step_id = ds.id 
            AND ((dsg.employee_id = ds.assigned_to_employee_id) OR (dsg.student_id = ds.assigned_to_student_id))
        WHERE EXISTS (
            SELECT 1 FROM document_steps ds_target
            WHERE ds_target.document_id = d.id AND {$assignmentCondition}
        ) {$docTypeFilter}
        ORDER BY d.uploaded_at DESC, ds.step_order ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $processedDocuments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
                'student' => ['id' => $row['student_id'], 'name' => $row['student_name'], 'department' => $row['student_department']],
                'file_path' => $row['file_path'],
                'workflow' => [],
                'user_action_completed' => (int) ($row['user_action_completed'] ?? 0)
            ];
        }

        if ($row['step_order']) {
            $processedDocuments[$docId]['workflow'][] = [
                'id' => (int) $row['step_id'],
                'name' => $row['step_name'],
                'status' => $row['step_status'],
                'order' => (int) $row['step_order'],
                'assigned_to' => $row['assigned_to_employee_id'] ?: $row['assigned_to_student_id'],
                'assignee_name' => trim($row['assignee_name']),
                'note' => $row['note'],
                'acted_at' => $row['acted_at'],
                'signature_status' => $row['signature_status'],
                'signed_at' => $row['signed_at']
            ];
        }
    }

    sendJsonResponse(true, ['documents' => array_values($processedDocuments)]);
}
