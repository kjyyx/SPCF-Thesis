<?php
/**
 * SAF (Student Allocated Funds) API
 * Manages SAF balances and transactions
 */

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/database.php';

header('Content-Type: application/json');

// Department name to ID mapping
$deptNameToIdMap = [
    'college of arts, social sciences and education' => 'casse',
    'college of computing and information sciences' => 'ccis',
    'college of hospitality and tourism management' => 'chtm',
    'college of business' => 'cob',
    'college of criminology' => 'coc',
    'college of engineering' => 'coe',
    'college of nursing' => 'con',
    'spcf miranda' => 'miranda',
    'supreme student council (ssc)' => 'ssc'
];

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

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }

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
                'used_amount' => (float)$balance['used_amount'],
                'current_balance' => (float)$balance['current_balance']
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

        // Role-based filtering
        // if ($currentUser['role'] === 'student') {
        //     $userDeptName = strtolower(trim($currentUser['department'] ?? ''));
        //     $userDeptId = $deptNameToIdMap[$userDeptName] ?? $userDeptName;
        //     error_log("SAF FILTER: User role: " . $currentUser['role']);
        //     error_log("SAF FILTER: User dept from session: '" . $currentUser['department'] . "'");
        //     error_log("SAF FILTER: Lowercased: '$userDeptName'");
        //     error_log("SAF FILTER: Mapped to: '$userDeptId'");
        //     $data = array_filter($data, function($item) use ($userDeptId) {
        //         $itemDept = $item['department_id'] ?? '';
        //         $match = $itemDept === $userDeptId;
        //         error_log("SAF FILTER: Checking item dept '$itemDept' against user '$userDeptId' - match: " . ($match ? 'YES' : 'NO'));
        //         return $match;
        //     });
        //     // Re-index array after filtering
        //     $data = array_values($data);
        //     error_log("SAF FILTER: Final data count: " . count($data));
        // }

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

    // Role-based access control for students
    if ($currentUser['role'] === 'student') {
        $userDeptName = strtolower(trim($currentUser['department'] ?? ''));
        $userDeptId = $GLOBALS['deptNameToIdMap'][$userDeptName] ?? $userDeptName;
        if (!isset($input['department_id']) || $input['department_id'] !== $userDeptId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied: You can only modify your own department\'s data']);
            return;
        }
    }

    try {
        $db->beginTransaction();

        if ($input['type'] === 'saf') {
            // "SET" Logic: Overwrite everything
            $departmentId = $GLOBALS['deptNameToIdMap'][strtolower(trim($input['department_id']))] ?? $input['department_id'];
            $initialAmount = (float)($input['initial_amount'] ?? 0);
            $usedAmount = (float)($input['used_amount'] ?? 0);
            
            // Standard accounting: Balance = Initial - Used
            $currentBalance = $initialAmount - $usedAmount;

            $stmt = $db->prepare("INSERT INTO saf_balances (department_id, initial_amount, used_amount, current_balance) 
                                  VALUES (?, ?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE 
                                  initial_amount = VALUES(initial_amount), 
                                  used_amount = VALUES(used_amount), 
                                  current_balance = VALUES(current_balance)");
            $stmt->execute([$departmentId, $initialAmount, $usedAmount, $currentBalance]);

            addAuditLog('SAF_BALANCE_SET', 'SAF Management', "SAF set for $departmentId", null, 'SAF', 'INFO');

        } elseif ($input['type'] === 'transaction') {
            // "ADD/DEDUCT" Logic
            $departmentId = $GLOBALS['deptNameToIdMap'][strtolower(trim($input['department_id']))] ?? $input['department_id'];
            $type = $input['transaction_type'];
            $amount = (float)$input['transaction_amount'];
            $description = $input['transaction_description'] ?? '';
            $date = $input['transaction_date'] ?? date('Y-m-d H:i:s');

            // 1. Insert Transaction Record
            $stmt = $db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$departmentId, $type, $amount, $description, $date, $currentUser['id']]);

            // 2. Update Balance safely based on Transaction Type
            if ($type === 'add') {
                // Adding funds increases the Initial Allocation AND Current Balance
                $updateStmt = $db->prepare("UPDATE saf_balances 
                                            SET initial_amount = initial_amount + ?, 
                                                current_balance = current_balance + ? 
                                            WHERE department_id = ?");
                $updateStmt->execute([$amount, $amount, $departmentId]);
            } elseif ($type === 'deduct') {
                // Deducting increases Used Amount and decreases Current Balance
                // We also check to ensure they don't go below zero (optional but recommended)
                $updateStmt = $db->prepare("UPDATE saf_balances 
                                            SET used_amount = used_amount + ?, 
                                                current_balance = current_balance - ? 
                                            WHERE department_id = ?");
                $updateStmt->execute([$amount, $amount, $departmentId]);
            }
            // Note: If type is 'set', we do nothing here as the 'saf' block handled the math.

            addAuditLog('SAF_TRANSACTION_CREATED', 'SAF Management', "SAF transaction: $type $amount", null, 'SAF', 'INFO');
        }

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("SAF POST error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to process request']);
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

    // Role-based access control for students
    if ($currentUser['role'] === 'student') {
        $userDeptName = strtolower(trim($currentUser['department'] ?? ''));
        $userDeptId = $deptNameToIdMap[$userDeptName] ?? $userDeptName;
        // Check if the record belongs to the user's department
        if ($input['type'] === 'saf') {
            $stmt = $db->prepare("SELECT department_id FROM saf_balances WHERE id = ?");
        } else {
            $stmt = $db->prepare("SELECT department_id FROM saf_transactions WHERE id = ?");
        }
        $stmt->execute([$input['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record || $record['department_id'] !== $userDeptId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied: You can only modify your own department\'s data']);
            return;
        }
    }

    try {
        $db->beginTransaction();

        if ($input['type'] === 'saf') {
            $id = $input['id'];
            $initialAmount = (float)($input['initial_amount'] ?? 0);
            $usedAmount = (float)($input['used_amount'] ?? 0);

            $stmt = $db->prepare("UPDATE saf_balances SET initial_amount = ?, used_amount = ?, current_balance = ? WHERE id = ?");
            $stmt->execute([$initialAmount, $usedAmount, $initialAmount - $usedAmount, $id]);

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

    // Role-based access control for students
    if ($currentUser['role'] === 'student') {
        $userDeptName = strtolower(trim($currentUser['department'] ?? ''));
        $userDeptId = $deptNameToIdMap[$userDeptName] ?? $userDeptName;
        // Check if the record belongs to the user's department
        if ($input['type'] === 'saf') {
            $stmt = $db->prepare("SELECT department_id FROM saf_balances WHERE id = ?");
        } else {
            $stmt = $db->prepare("SELECT department_id FROM saf_transactions WHERE id = ?");
        }
        $stmt->execute([$input['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record || $record['department_id'] !== $userDeptId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied: You can only modify your own department\'s data']);
            return;
        }
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