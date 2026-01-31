<?php
/**
 * SAF (Student Allocated Funds) API
 * Manages SAF balances and transactions
 */

require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

// Initialize database connection
$db = (new Database())->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$currentUser = getCurrentUser();

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

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

function handleGet() {
    global $db, $currentUser;

    try {
        // Get all SAF balances
        $balancesStmt = $db->prepare("SELECT * FROM saf_balances");
        $balancesStmt->execute();
        $balances = $balancesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all transactions
        $transactionsStmt = $db->prepare("SELECT * FROM saf_transactions ORDER BY transaction_date DESC");
        $transactionsStmt->execute();
        $transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Combine into data array
        $data = [];
        foreach ($balances as $balance) {
            $data[] = [
                'id' => $balance['id'],
                'type' => 'saf',
                'department_id' => $balance['department_id'],
                'initial_amount' => (float)$balance['initial_amount'],
                'used_amount' => (float)$balance['used_amount']
            ];
        }
        foreach ($transactions as $transaction) {
            $data[] = [
                'id' => $transaction['id'],
                'type' => 'transaction',
                'department_id' => $transaction['department_id'],
                'transaction_type' => $transaction['transaction_type'],
                'transaction_amount' => (float)$transaction['transaction_amount'],
                'transaction_description' => $transaction['transaction_description'],
                'transaction_date' => $transaction['transaction_date']
            ];
        }

        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        error_log("SAF GET error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch SAF data']);
    }
}

function handlePost() {
    global $db, $currentUser;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        return;
    }

    try {
        $db->beginTransaction();

        if ($input['type'] === 'saf') {
            // Create or update SAF balance
            $departmentId = $input['department_id'];
            $initialAmount = (float)($input['initial_amount'] ?? 0);
            $usedAmount = (float)($input['used_amount'] ?? 0);

            $stmt = $db->prepare("INSERT INTO saf_balances (department_id, initial_amount, used_amount) 
                                  VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE initial_amount = VALUES(initial_amount), used_amount = VALUES(used_amount)");
            $stmt->execute([$departmentId, $initialAmount, $usedAmount]);

            // Audit log
            addAuditLog('SAF_BALANCE_CREATED', 'SAF Management', "SAF balance created/updated for $departmentId", null, 'SAF', 'INFO');

        } elseif ($input['type'] === 'transaction') {
            // Create transaction
            $departmentId = $input['department_id'];
            $type = $input['transaction_type'];
            $amount = (float)$input['transaction_amount'];
            $description = $input['transaction_description'] ?? '';
            $date = $input['transaction_date'] ?? date('Y-m-d H:i:s');

            $stmt = $db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$departmentId, $type, $amount, $description, $date, $currentUser['id']]);

            // Audit log
            addAuditLog('SAF_TRANSACTION_CREATED', 'SAF Management', "SAF transaction created for $departmentId: $type $amount", null, 'SAF', 'INFO');
        }

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("SAF POST error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create SAF record']);
    }
}

function handlePut() {
    global $db, $currentUser;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id']) || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        return;
    }

    try {
        $db->beginTransaction();

        if ($input['type'] === 'saf') {
            $id = $input['id'];
            $initialAmount = (float)($input['initial_amount'] ?? 0);
            $usedAmount = (float)($input['used_amount'] ?? 0);

            $stmt = $db->prepare("UPDATE saf_balances SET initial_amount = ?, used_amount = ? WHERE id = ?");
            $stmt->execute([$initialAmount, $usedAmount, $id]);

            // Audit log
            addAuditLog('SAF_BALANCE_UPDATED', 'SAF Management', "SAF balance updated", $id, 'SAF', 'INFO');
        }

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("SAF PUT error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update SAF record']);
    }
}

function handleDelete() {
    global $db, $currentUser;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id']) || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        return;
    }

    try {
        $db->beginTransaction();

        if ($input['type'] === 'saf') {
            $id = $input['id'];
            $stmt = $db->prepare("DELETE FROM saf_balances WHERE id = ?");
            $stmt->execute([$id]);

            // Also delete transactions
            $transStmt = $db->prepare("DELETE FROM saf_transactions WHERE department_id = (SELECT department_id FROM saf_balances WHERE id = ?)");
            $transStmt->execute([$id]);

            // Audit log
            addAuditLog('SAF_BALANCE_DELETED', 'SAF Management', "SAF balance deleted", $id, 'SAF', 'WARNING');
        } elseif ($input['type'] === 'transaction') {
            $id = $input['id'];
            $stmt = $db->prepare("DELETE FROM saf_transactions WHERE id = ?");
            $stmt->execute([$id]);

            // Audit log
            addAuditLog('SAF_TRANSACTION_DELETED', 'SAF Management', "SAF transaction deleted", $id, 'SAF', 'WARNING');
        }

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("SAF DELETE error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete SAF record']);
    }
}

function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    global $currentUser, $db;
    try {
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'],
            $severity
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}
?>