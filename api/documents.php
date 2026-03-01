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

if (!defined('DOC_STEP_TIMEOUT_DAYS'))
    define('DOC_STEP_TIMEOUT_DAYS', 5);

// ------------------------------------------------------------------
// Local Helper Functions
// ------------------------------------------------------------------
function enforceTimeouts($db)
{
    try {
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
            $db->prepare("UPDATE document_steps SET status = 'expired', acted_at = NOW(), note = CONCAT(COALESCE(note, ''), ' [Auto-timeout]') WHERE id = ?")->execute([$row['step_id']]);
            $db->prepare("UPDATE documents SET status = 'on_hold', updated_at = NOW() WHERE id = ?")->execute([$row['doc_id']]);

            // --- NEW: TRIGGER 1 - TIMEOUT ALERT TO STUDENT ---
            $doc = $db->query("SELECT student_id, title FROM documents WHERE id = " . $row['doc_id'])->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                pushNotification($db, $doc['student_id'], 'student', 'document', 'Document On Hold', "Your document '{$doc['title']}' has been placed on hold due to a step timeout. Please review and resubmit.", $row['doc_id'], 'document_status_in_review');
            }

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

    $department = $currentUser['department'] ?? ($data['department'] ?? '');
    $data['department'] = $department;
    $departmentFull = getDepartmentFullName($department);
    $data['departmentFull'] = $departmentFull;

    try {
        $db->beginTransaction();

        $title = match ($docType) { 'facility' => $data['eventName'] ?? '', 'communication' => $data['subject'] ?? '', default => $data['title'] ?? ''} ?: 'Untitled';
        $desc = match ($docType) { 'proposal' => $data['rationale'] ?? '', 'facility' => $data['eventName'] ?? '', 'communication' => $data['subject'] ?? 'Communication Letter', default => $data['title'] ?? ''};

        $stmt = $db->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, current_step, uploaded_at, department, departmentFull, data) VALUES (?, ?, ?, ?, 'submitted', 1, NOW(), ?, ?, ?)");
        $stmt->execute([$studentId, $docType, $title, $desc, $department, $departmentFull, json_encode($data)]);
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
                break;

            case 'proposal':
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
                    ['position' => 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'table' => 'employees', 'dept' => false]
                ];

                $needsCpao = !empty($data['f1']) || !empty($data['f2']) || !empty($data['f6']) || !empty($data['f7']) || !empty($data['f21']);
                if ($needsCpao)
                    $workflowPositions[] = ['position' => 'Center for Performing Arts Organization (CPAO)', 'table' => 'employees', 'dept' => false];

                $guest = trim($data['guestSpeaker'] ?? '');
                if (!empty($guest) && strtolower($guest) !== 'none' && strtolower($guest) !== 'n/a') {
                    $workflowPositions[] = ['position' => 'Information Office (IO)', 'table' => 'employees', 'dept' => false];
                    $workflowPositions[] = ['position' => 'Security Head (SH)', 'table' => 'employees', 'dept' => false];
                }

                $needsTech = !empty($data['soundSystem']) || !empty($data['technicalNeeds']) || !empty($data['projector']) || !empty($data['e8']);
                if ($needsTech)
                    $workflowPositions[] = ['position' => 'Technical Support (TS)', 'table' => 'employees', 'dept' => false];

                $needsIts = !empty($data['internetNeeded']) || !empty($data['f3']) || !empty($data['f18']) || !empty($data['f20']);
                if ($needsIts)
                    $workflowPositions[] = ['position' => 'Information Technology Services (ITS)', 'table' => 'employees', 'dept' => false];

                $workflowPositions[] = ['position' => 'Physical Plant and Facilities Office (PPFO)', 'table' => 'employees', 'dept' => false];
                $workflowPositions[] = ['position' => 'Executive Vice-President / Student Services (EVP)', 'table' => 'employees', 'dept' => false];
                break;
        }

        foreach ($signatories as $key => $sig) {
            $data[$key] = $sig['name'];
        }

        foreach (['date', 'reqDate', 'implDate', 'eventDate', 'preEventDate', 'practiceDate', 'setupDate', 'cleanupDate', 'receivingDateFiled'] as $field) {
            if (isset($data[$field]))
                $data[$field] = formatDateForTemplate($data[$field]);
        }

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

        if (file_exists($templatePath)) {
            try {
                $filledPath = fillDocxTemplate($templatePath, $data);
                $pdfPath = convertDocxToPdf($filledPath);
                $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")->execute(['uploads/' . basename($pdfPath), $docId]);
            } catch (Exception $e) {
                error_log("Template filling failed for $docType: " . $e->getMessage());
                $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")->execute(['uploads/fallback.pdf', $docId]);
            }
        }

        $stepOrder = 1;
        $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) VALUES (?, ?, 'Document Creator Signature', ?, ?, 'pending')")
            ->execute([$docId, $stepOrder++, ($currentUser['role'] === 'employee' ? $currentUser['id'] : null), ($currentUser['role'] === 'student' ? $currentUser['id'] : null)]);

        if ($docType !== 'communication') {
            if ($currentUser['position'] === 'Supreme Student Council President') {
                $bypassedRoles = ['College Student Council Adviser', 'College Dean', 'Supreme Student Council President'];
                $workflowPositions = array_filter($workflowPositions, function ($wp) use ($bypassedRoles) {
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

        // --- NEW: TRIGGER 2 - NOTIFY FIRST SIGNEE IMMEDIATELY ---
        $firstSigneeStmt = $db->prepare("SELECT assigned_to_employee_id FROM document_steps WHERE document_id = ? AND step_order = 2 AND status = 'queued'");
        $firstSigneeStmt->execute([$docId]);
        $firstSignee = $firstSigneeStmt->fetchColumn();

        if ($firstSignee) {
            pushNotification($db, $firstSignee, 'employee', 'document', 'New Document Submitted', "{$currentUser['first_name']} submitted a new {$docType} for your review.", $docId, 'employee_document_pending');
        }

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

    if ($signatureMap && is_string($signatureMap))
        $signatureMap = json_decode($signatureMap, true);
    if (!$documentId)
        sendJsonResponse(false, 'Document ID is required', 400);

    $assignCol = ($currentUser['role'] === 'employee') ? 'assigned_to_employee_id' : 'assigned_to_student_id';

    if (!$stepId) {
        $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND $assignCol = ? AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
        $q->execute([$documentId, $currentUser['id']]);
        $stepId = $q->fetchColumn();
        if (!$stepId)
            sendJsonResponse(false, 'No pending step assigned to you for this document', 403);
    }

    $hierarchyCheckStmt = $db->prepare("SELECT COUNT(*) as pending_previous FROM document_steps WHERE document_id = ? AND step_order < (SELECT step_order FROM document_steps WHERE id = ?) AND status NOT IN ('completed', 'skipped')");
    $hierarchyCheckStmt->execute([$documentId, $stepId]);
    if ($hierarchyCheckStmt->fetchColumn() > 0)
        sendJsonResponse(false, 'Cannot sign this step. Previous steps must be completed first.', 403);

    $docStmt = $db->prepare("SELECT file_path, title, doc_type, data, department, student_id FROM documents WHERE id = ?");
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
        if (move_uploaded_file($files['signed_pdf']['tmp_name'], $uploadDir . $newFileName)) {
            $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")->execute(['uploads/' . $newFileName, $documentId]);
        }
    }

    $maxRetries = 3;
    $retryCount = 0;
    $isFullyApproved = false;

    do {
        try {
            $db->beginTransaction();

            $db->prepare("UPDATE document_steps SET status = 'completed', acted_at = NOW(), note = ? WHERE id = ? AND $assignCol = ?")->execute([$notes, $stepId, $currentUser['id']]);

            $stepStmt = $db->prepare("SELECT name FROM document_steps WHERE id = ?");
            $stepStmt->execute([$stepId]);
            $currentStep = $stepStmt->fetch(PDO::FETCH_ASSOC);

            if ($doc['doc_type'] === 'saf' && $currentStep && strpos($currentStep['name'], 'Accounting') !== false) {
                $existingTransStmt = $db->prepare("SELECT COUNT(*) FROM saf_transactions WHERE transaction_description LIKE ?");
                $existingTransStmt->execute(["%Document ID: {$documentId}%"]);
                if ($existingTransStmt->fetchColumn() == 0) {
                    $data = json_decode($doc['data'], true);
                    if ($data) {
                        $reqSSC = $data['reqSSC'] ?? 0;
                        $reqCSC = $data['reqCSC'] ?? 0;
                        $deptId = strtolower(trim($data['department']));
                        $reverseDeptMap = ['supreme student council' => 'ssc', 'college of engineering' => 'coe', 'college of computing and information sciences' => 'ccis', 'college of business' => 'cob', 'college of arts, social sciences and education' => 'casse', 'college of hospitality and tourism management' => 'chtm', 'college of criminology' => 'coc', 'college of nursing' => 'con'];
                        $deptId = $reverseDeptMap[$deptId] ?? $deptId;

                        $transStmt = $db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date, created_by) VALUES (?, 'deduct', ?, ?, NOW(), ?)");
                        $docTitle = $doc['title'] ?? 'SAF Request';
                        $sscDesc = "SAF (SSC) - {$docTitle} (Doc ID: {$documentId})";
                        $cscDesc = "SAF (CSC) - {$docTitle} (Doc ID: {$documentId})";

                        if ($reqSSC > 0) {
                            $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = 'ssc'")->execute([$reqSSC]);
                            $transStmt->execute(['ssc', $reqSSC, $sscDesc, $currentUser['id']]);
                        }
                        if ($reqCSC > 0 && $deptId) {
                            $db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = ?")->execute([$reqCSC, $deptId]);
                            $transStmt->execute([$deptId, $reqCSC, $cscDesc, $currentUser['id']]);
                        }

                        // --- NEW: TRIGGER 3 - SAF FUNDS READY ALERT (Replaces generic approval) ---
                        pushNotification($db, $doc['student_id'], 'student', 'document', 'SAF Funds Ready!', "Your SAF request '{$docTitle}' has been processed. Funds are ready for pickup at Accounting.", $documentId, 'doc_status_approved');
                    }
                }
            }

            if ($signatureMap) {
                $db->prepare("UPDATE document_steps SET signature_map = ? WHERE id = ?")->execute([json_encode($signatureMap), $stepId]);
            }

            $currentStepOrder = $db->prepare("SELECT step_order FROM document_steps WHERE id = ?");
            $currentStepOrder->execute([$stepId]);

            if ($order = $currentStepOrder->fetchColumn()) {
                $db->prepare("UPDATE document_steps SET status = 'pending' WHERE document_id = ? AND step_order = ? AND status = 'queued'")->execute([$documentId, $order + 1]);
            }

            $progress = $db->query("SELECT COUNT(*) as total, SUM(status = 'completed') as done FROM document_steps WHERE document_id = $documentId")->fetch(PDO::FETCH_ASSOC);

            if ($progress['total'] == $progress['done']) {
                $isFullyApproved = true;
                $db->prepare("UPDATE documents SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$documentId]);

                // --- NEW: NOTIFY STUDENT APPROVED (If not a SAF document, since we already pinged them above) ---
                if ($doc['doc_type'] !== 'saf') {
                    pushNotification($db, $doc['student_id'], 'student', 'document', 'Document Fully Approved!', "Your document '{$doc['title']}' has been fully approved.", $documentId, 'doc_status_approved');
                }

            } else {
                $db->prepare("UPDATE documents SET status = 'in_progress', updated_at = NOW() WHERE id = ? AND status IN ('submitted', 'on_hold')")->execute([$documentId]);

                // --- NEW: NOTIFY NEXT ASSIGNEE IN LINE ---
                if (isset($order)) {
                    $nextStep = $db->prepare("SELECT assigned_to_employee_id, assigned_to_student_id FROM document_steps WHERE document_id = ? AND step_order = ?");
                    $nextStep->execute([$documentId, $order + 1]);
                    $next = $nextStep->fetch(PDO::FETCH_ASSOC);

                    if ($next && $next['assigned_to_employee_id']) {
                        pushNotification($db, $next['assigned_to_employee_id'], 'employee', 'document', 'Action Required', "You have a new document pending review: '{$doc['title']}'", $documentId, 'employee_document_pending');
                    } elseif ($next && $next['assigned_to_student_id']) {
                        pushNotification($db, $next['assigned_to_student_id'], 'student', 'document', 'Action Required', "You have a new document pending review: '{$doc['title']}'", $documentId, 'document_pending_signature');
                    }
                }
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

    if ($isFullyApproved && $doc['doc_type'] === 'proposal') {
        $docData = json_decode($doc['data'], true) ?: [];
        $schedules = $docData['schedule'] ?? [['date' => date('Y-m-d'), 'time' => null]];
        $eventTitle = $doc['title'];
        $desc = $docData['rationale'] ?? 'Approved Project Proposal';
        $venue = $docData['venue'] ?? 'TBA';
        $creatorRole = in_array($currentUser['role'] ?? '', ['admin', 'employee']) ? $currentUser['role'] : 'employee';

        try {
            $insertEvent = $db->prepare("INSERT INTO events (title, description, venue, event_date, event_time, created_by, created_by_role, source_document_id, approved, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())");
            foreach ($schedules as $sched) {
                $eventDate = !empty($sched['date']) ? $sched['date'] : date('Y-m-d');
                $eventTime = !empty($sched['time']) ? $sched['time'] : null;
                $checkStmt = $db->prepare("SELECT id FROM events WHERE title = ? AND event_date = ? AND event_time = ? LIMIT 1");
                $checkStmt->execute([$eventTitle, $eventDate, $eventTime]);
                if (!$checkStmt->fetch()) {
                    $insertEvent->execute([$eventTitle, $desc, $venue, $eventDate, $eventTime, $currentUser['id'], $creatorRole, $documentId, $currentUser['id']]);
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

    if (!$stepId) {
        $q = $db->prepare("SELECT id FROM document_steps WHERE document_id = ? AND $assignCol = ? AND status = 'pending' LIMIT 1");
        $q->execute([$documentId, $currentUser['id']]);
        $stepId = $q->fetchColumn();
        if (!$stepId)
            sendJsonResponse(false, 'No pending step assigned to you', 403);
    }

    try {
        $db->beginTransaction();

        $db->prepare("UPDATE document_steps SET status = 'rejected', acted_at = NOW(), note = ? WHERE id = ? AND $assignCol = ?")->execute([$reason, $stepId, $currentUser['id']]);
        $db->prepare("UPDATE documents SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$documentId]);

        // --- NEW: INSTANT NOTIFICATION TO STUDENT ---
        $doc = $db->query("SELECT student_id, title FROM documents WHERE id = $documentId")->fetch(PDO::FETCH_ASSOC);
        if ($doc) {
            pushNotification($db, $doc['student_id'], 'student', 'document', 'Document Rejected', "Your document '{$doc['title']}' was rejected by {$currentUser['first_name']}.", $documentId, 'doc_status_rejected');
        }

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
        $stmt = $db->prepare("SELECT step_order FROM document_steps WHERE document_id = ? AND status = 'expired' LIMIT 1");
        $stmt->execute([$documentId]);
        $expiredStepOrder = $stmt->fetchColumn();

        if ($expiredStepOrder) {
            if ($expiredStepOrder == 1) {
                $db->prepare("UPDATE documents SET uploaded_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$documentId]);
            } else {
                $db->prepare("UPDATE document_steps SET acted_at = NOW() WHERE document_id = ? AND step_order = ?")->execute([$documentId, $expiredStepOrder - 1]);
            }

            $db->prepare("UPDATE document_steps SET status = 'pending', note = REPLACE(note, ' [Auto-timeout]', '') WHERE document_id = ? AND step_order = ?")->execute([$documentId, $expiredStepOrder]);
            $newDocStatus = ($expiredStepOrder == 1) ? 'submitted' : 'in_progress';
            $db->prepare("UPDATE documents SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newDocStatus, $documentId]);

            addAuditLog($db, 'DOCUMENT_RESUBMITTED', 'Document Management', "Document resubmitted from hold", $documentId, 'Document', 'INFO');
            $db->commit();

            // --- NEW: TRIGGER 4 - RESUBMISSION ALERT TO EMPLOYEE ---
            $pendingStmt = $db->prepare("SELECT assigned_to_employee_id, assigned_to_student_id FROM document_steps WHERE document_id = ? AND status = 'pending'");
            $pendingStmt->execute([$documentId]);
            $pendingSignee = $pendingStmt->fetch(PDO::FETCH_ASSOC);

            if ($pendingSignee) {
                if ($pendingSignee['assigned_to_employee_id']) {
                    pushNotification($db, $pendingSignee['assigned_to_employee_id'], 'employee', 'document', 'Document Resubmitted', "A document on hold has been resubmitted and is awaiting your review.", $documentId, 'employee_document_pending');
                } elseif ($pendingSignee['assigned_to_student_id']) {
                    pushNotification($db, $pendingSignee['assigned_to_student_id'], 'student', 'document', 'Document Resubmitted', "A document on hold has been resubmitted and is awaiting your review.", $documentId, 'document_pending_signature');
                }
            }

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

    // --- NEW: INSTANT NOTIFICATION TO CREATOR ---
    $doc = $db->query("SELECT student_id, title FROM documents WHERE id = $documentId")->fetch(PDO::FETCH_ASSOC);
    if ($doc && $currentUser['id'] !== $doc['student_id']) {
        pushNotification($db, $doc['student_id'], 'student', 'document', 'New Comment', "{$currentUser['first_name']} commented on your document '{$doc['title']}'.", $documentId, 'document_comment');
    }

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

    $db->prepare("DELETE FROM document_steps WHERE document_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);

    addAuditLog($db, 'DOCUMENT_DELETED', 'Document Management', "Deleted document $id", $id, 'Document', 'WARNING');
    sendJsonResponse(true);
}

function handleGet($db, $currentUser)
{
    $action = $_GET['action'] ?? null;
    $docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($action === 'approved_events')
        getApprovedEvents($db);
    elseif ($action === 'get_comments' && $docId)
        getDocumentComments($db, $currentUser, $docId);
    elseif ($action === 'my_documents')
        getMyDocuments($db, $currentUser);
    elseif ($action === 'document_details' && $docId)
        getStudentDocumentDetails($db, $currentUser, $docId);
    elseif ($docId)
        getGeneralDocumentDetails($db, $currentUser, $docId);
    else
        getAssignedDocuments($db, $currentUser);
}

function getApprovedEvents($db)
{
    $stmt = $db->query("
        SELECT id, title, doc_type, department, 
               JSON_UNQUOTE(JSON_EXTRACT(data, '$.date')) as event_date, 
               JSON_UNQUOTE(JSON_EXTRACT(data, '$.earliestStartTime')) as event_time, 
               JSON_UNQUOTE(JSON_EXTRACT(data, '$.venue')) as venue
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

    // --- FIX: Added LEFT JOIN to fetch employee and student names ---
    $historyStmt = $db->prepare("
        SELECT ds.id, ds.status, ds.name, ds.acted_at, ds.signature_map,
               e.first_name AS emp_first, e.last_name AS emp_last,
               st.first_name AS stu_first, st.last_name AS stu_last
        FROM document_steps ds
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students st ON ds.assigned_to_student_id = st.id
        WHERE ds.document_id = ? 
        ORDER BY ds.step_order ASC
    ");
    $historyStmt->execute([$docId]);
    $workflow_history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

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

    $scheduleData = [];
    if ($doc['doc_type'] === 'proposal') {
        $scheduleData = json_decode($doc['data'] ?? '{}', true)['schedule'] ?? [];
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
            'workflow_history' => array_map(function ($s) {

                // --- FIX: Safely parse the assignee name ---
                $assignee_name = 'Unknown';
                if (!empty($s['emp_first'])) {
                    $assignee_name = trim($s['emp_first'] . ' ' . $s['emp_last']);
                } elseif (!empty($s['stu_first'])) {
                    $assignee_name = trim($s['stu_first'] . ' ' . $s['stu_last']);
                }

                return [
                    'created_at' => $s['acted_at'] ?: date('Y-m-d H:i:s'),
                    'status' => $s['status'] ?: 'pending',
                    'action' => $s['status'] === 'completed' ? 'Approved' : ($s['status'] === 'rejected' ? 'Rejected' : 'Pending'),
                    'office_name' => $s['name'] ?: 'Unknown',
                    'assignee_name' => $assignee_name, // <--- THIS SENDS IT TO JS
                    'signature_map' => $s['signature_map']
                ];
            }, $workflow_history),
            'notes' => $notesStmt->fetchAll(PDO::FETCH_ASSOC)
        ]
    ]);
}

function getGeneralDocumentDetails($db, $currentUser, $docId)
{
    $docStmt = $db->prepare("SELECT d.id, d.title, d.doc_type, d.status, d.data, d.file_path, s.id AS student_id, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.department AS student_department FROM documents d JOIN students s ON d.student_id = s.id WHERE d.id = ?");
    $docStmt->execute([$docId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc)
        sendJsonResponse(false, 'Document not found', 404);

    $stepsStmt = $db->prepare("
        SELECT ds.*, e.first_name AS emp_first, e.last_name AS emp_last,
               s.first_name AS stu_first, s.last_name AS stu_last
        FROM document_steps ds
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students s ON ds.assigned_to_student_id = s.id
        WHERE ds.document_id = ? ORDER BY ds.step_order ASC
    ");
    $stepsStmt->execute([$docId]);

    $steps = [];
    while ($s = $stepsStmt->fetch(PDO::FETCH_ASSOC)) {
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
            'assignee_id' => $assignee_id,
            'assignee_name' => $assignee_name ?: 'Unknown',
            'assignee_type' => $s['assigned_to_employee_id'] ? 'employee' : 'student',
            'signature_status' => $s['status'] === 'completed' ? 'signed' : 'pending',
            'signed_at' => $s['acted_at']
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

    if ($doc['doc_type'] === 'proposal') {
        $scheduleData = json_decode($doc['data'] ?? '{}', true)['schedule'] ?? [];
        if (!empty($scheduleData))
            $payload['schedule'] = $scheduleData;
    }

    http_response_code(200);
    echo json_encode($payload);
    exit;
}

function getMyDocuments($db, $currentUser)
{
    if ($currentUser['role'] !== 'student')
        sendJsonResponse(false, 'Access denied', 403);

    $processedDocuments = [];

    // ==========================================
    // 1. FETCH STANDARD DOCUMENTS
    // ==========================================
    $docsStmt = $db->prepare("SELECT * FROM documents WHERE student_id = ? ORDER BY uploaded_at DESC");
    $docsStmt->execute([$currentUser['id']]);
    $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($documents as $doc) {
        $docId = $doc['id'];

        $stepsStmt = $db->prepare("
            SELECT ds.*,
                   CASE WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                        WHEN st.id IS NOT NULL THEN CONCAT(st.first_name, ' ', st.last_name)
                        ELSE 'Unknown' END as assignee_name
            FROM document_steps ds
            LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
            LEFT JOIN students st ON ds.assigned_to_student_id = st.id
            WHERE ds.document_id = ? ORDER BY ds.step_order ASC
        ");
        $stepsStmt->execute([$docId]);
        $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

        $notes = [];
        try {
            $notesStmt = $db->prepare("SELECT note as comment, created_at FROM document_notes WHERE document_id = ? ORDER BY created_at ASC");
            $notesStmt->execute([$docId]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }

        $workflow = [];
        $current_location = 'Unknown';
        $current_assignee = 'Unknown';

        $pendingStep = array_filter($steps, fn($s) => $s['status'] === 'pending');
        $rejectedStep = array_filter($steps, fn($s) => $s['status'] === 'rejected');

        if (!empty($rejectedStep)) {
            $rej = reset($rejectedStep);
            $current_location = $rej['name'];
            $current_assignee = trim($rej['assignee_name']);
        } elseif (!empty($pendingStep)) {
            $pend = reset($pendingStep);
            $current_location = $pend['name'];
            $current_assignee = trim($pend['assignee_name']);
        } elseif ($doc['status'] === 'approved') {
            // --- FIX: Location goes back to creator, Assignee shows all signed ---
            $current_location = 'Returned to Creator';
            $current_assignee = 'All Signatories';
        }

        foreach ($steps as $step) {
            $workflow[] = [
                'id' => (int) $step['id'],
                'name' => $step['name'],
                'status' => $step['status'],
                'order' => (int) $step['step_order'],
                'assignee_name' => trim($step['assignee_name']),
                'note' => $step['note'],
                'acted_at' => $step['acted_at']
            ];

            if ($step['status'] === 'rejected' && !empty($step['note'])) {
                $notes[] = ['comment' => 'Rejected: ' . $step['note'], 'created_at' => $step['acted_at']];
            }
            if ($step['status'] === 'expired') {
                $notes[] = ['comment' => 'System: Step expired (Auto-timeout)', 'created_at' => $step['acted_at']];
            }
        }

        $processedDocuments[] = [
            'id' => (int) $doc['id'],
            'title' => $doc['title'],
            'doc_type' => $doc['doc_type'],
            'description' => $doc['description'],
            'status' => $doc['status'],
            'created_at' => $doc['uploaded_at'],
            'updated_at' => $doc['updated_at'] ?? $doc['uploaded_at'],
            'current_location' => $current_location,
            'current_assignee' => $current_assignee ?: 'Unassigned',
            'file_path' => $doc['file_path'],
            'workflow' => $workflow,
            'notes' => $notes,
            'is_material' => false
        ];
    }

    // ==========================================
    // 2. FETCH MATERIALS (PUBMATS)
    // ==========================================
    $matStmt = $db->prepare("SELECT * FROM materials WHERE student_id = ? ORDER BY uploaded_at DESC");
    $matStmt->execute([$currentUser['id']]);
    $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($materials as $mat) {
        $matId = $mat['id'];

        $stepsStmt = $db->prepare("
            SELECT ms.*, e.first_name, e.last_name,
            CASE WHEN ms.step_order = 1 THEN 'College Student Council Adviser'
                 WHEN ms.step_order = 2 THEN 'College Dean'
                 WHEN ms.step_order = 3 THEN 'OIC-OSA' END as step_name
            FROM materials_steps ms
            LEFT JOIN employees e ON ms.assigned_to_employee_id = e.id
            WHERE ms.material_id = ? ORDER BY ms.step_order ASC
        ");
        $stepsStmt->execute([$matId]);
        $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

        $notes = [];
        try {
            $notesStmt = $db->prepare("SELECT note as comment, created_at FROM materials_notes WHERE material_id = ? ORDER BY created_at ASC");
            $notesStmt->execute([$matId]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }

        $workflow = [];
        $current_location = 'Unknown';
        $current_assignee = 'Unknown';

        $pendingStep = array_filter($steps, fn($s) => $s['status'] === 'pending');
        $rejectedStep = array_filter($steps, fn($s) => $s['status'] === 'rejected');

        if (!empty($rejectedStep)) {
            $rej = reset($rejectedStep);
            $current_location = $rej['step_name'];
            $current_assignee = trim(($rej['first_name'] ?? '') . ' ' . ($rej['last_name'] ?? ''));
        } elseif (!empty($pendingStep)) {
            $pend = reset($pendingStep);
            $current_location = $pend['step_name'];
            $current_assignee = trim(($pend['first_name'] ?? '') . ' ' . ($pend['last_name'] ?? ''));
        } elseif ($mat['status'] === 'approved') {
            // --- FIX: Location goes back to creator, Assignee shows all signed ---
            $current_location = 'Returned to Creator';
            $current_assignee = 'All Signatories';
        }

        foreach ($steps as $step) {
            $workflow[] = [
                'id' => (int) $step['id'],
                'name' => $step['step_name'],
                'status' => $step['status'],
                'order' => (int) $step['step_order'],
                'assignee_name' => trim(($step['first_name'] ?? '') . ' ' . ($step['last_name'] ?? '')),
                'note' => $step['note'],
                'acted_at' => $step['completed_at']
            ];

            if ($step['status'] === 'rejected' && !empty($step['note'])) {
                $notes[] = ['comment' => 'Rejected: ' . $step['note'], 'created_at' => $step['completed_at']];
            }
        }

        $updatedAt = $mat['rejected_at'] ?? $mat['approved_at'] ?? $mat['uploaded_at'];

        $processedDocuments[] = [
            'id' => 'MAT-' . $matId,
            'title' => $mat['title'],
            'doc_type' => 'publication',
            'description' => $mat['description'],
            'status' => $mat['status'],
            'created_at' => $mat['uploaded_at'],
            'updated_at' => $updatedAt,
            'current_location' => $current_location,
            'current_assignee' => $current_assignee ?: 'Unassigned',
            'file_path' => $mat['file_path'],
            'workflow' => $workflow,
            'notes' => $notes,
            'is_material' => true
        ];
    }

    usort($processedDocuments, function ($a, $b) {
        return strtotime($b['updated_at']) - strtotime($a['updated_at']);
    });

    sendJsonResponse(true, ['documents' => $processedDocuments]);
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
        SELECT DISTINCT d.id, d.title, d.doc_type, d.description, d.status, d.current_step, d.uploaded_at, d.file_path, d.data,
               s.id as student_id, CONCAT(s.first_name, ' ', s.last_name) as student_name, s.department as student_department,
               CASE WHEN d.status IN ('approved', 'rejected') THEN 1 {$completionLogic} END as user_action_completed,
               ds.id as step_id, ds.step_order, ds.name as step_name, ds.status as step_status, ds.note, ds.acted_at,
               ds.assigned_to_employee_id, ds.assigned_to_student_id,
               CASE 
                   WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                   WHEN st.id IS NOT NULL THEN CONCAT(st.first_name, ' ', st.last_name)
                   ELSE 'Unknown'
               END as assignee_name
        FROM documents d
        LEFT JOIN students s ON d.student_id = s.id
        JOIN document_steps ds ON d.id = ds.document_id
        LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id
        LEFT JOIN students st ON ds.assigned_to_student_id = st.id
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

        $docData = json_decode($row['data'] ?? '{}', true);
        $extractedDate = $docData['date'] ?? null;

        if (!isset($processedDocuments[$docId])) {
            $processedDocuments[$docId] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'doc_type' => $row['doc_type'],
                'description' => $row['description'],
                'status' => $row['status'],
                'current_step' => (int) $row['current_step'],
                'uploaded_at' => $row['uploaded_at'],
                'date' => $extractedDate,
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
                'signature_status' => $row['step_status'] === 'completed' ? 'signed' : 'pending',
                'signed_at' => $row['acted_at']
            ];
        }
    }

    sendJsonResponse(true, ['documents' => array_values($processedDocuments)]);
}

// --- NEW HELPER FUNCTION TO PUSH INSTANT NOTIFICATIONS ---
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