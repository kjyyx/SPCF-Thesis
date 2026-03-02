<?php
/**
 * Users API - User Management (Admin Only)
 * ========================================
 *
 * Complete CRUD operations for user management:
 * - GET: List users with pagination and search
 * - POST: Create new users
 * - PUT: Update existing users
 * - DELETE: Remove users
 *
 * Restricted to administrators only.
 * Handles users across all roles: admin, employee, student.
 * Uses separate database tables for each role.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'vendor/autoload.php';
use PragmaRX\Google2FA\Google2FA;

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            'admin',
            'Unknown User', // Admin user name not fetched here for simplicity
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $_SERVER['REMOTE_ADDR'] ?? null,
            null, // Set user_agent to null to avoid storing PII
            $severity ?? 'INFO',
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// --------------------------------------
// Guard: Require authentication + admin role
// --------------------------------------
$__usersMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$__usersRaw = file_get_contents('php://input');
$__usersPayload = json_decode($__usersRaw, true);
if (!is_array($__usersPayload)) {
    $__usersPayload = [];
}
$__isProfileUpdate = ($__usersMethod === 'POST' && ($__usersPayload['action'] ?? '') === 'update_profile');

if (!isLoggedIn() || (!$__isProfileUpdate && ($_SESSION['user_role'] ?? null) !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// --------------------------------------
// Utility helpers (response + parsing)
// --------------------------------------
/**
 * Emit a JSON response and terminate script.
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

/**
 * Shortcut for a simple error JSON body.
 */
function json_error(string $message, int $status = 400): void
{
    json_response(['success' => false, 'message' => $message], $status);
}

/**
 * Read and decode JSON body safely.
 */
function get_json_payload(): array
{
    global $__usersPayload;
    return is_array($__usersPayload) ? $__usersPayload : [];
}

// --------------------------------------
// Role/table mapping + row shaping
// --------------------------------------
/**
 * Map domain role to its backing DB table.
 */
function get_table_by_role(?string $role): ?string
{
    switch ($role) {
        case 'admin':
            return 'administrators';
        case 'employee':
            return 'employees';
        case 'student':
            return 'students';
        default:
            return null;
    }
}

/**
 * Normalize DB row to a dashboard-friendly user shape.
 */
function unify_user_row(array $row, string $role): array
{
    $user = [
        'id' => $row['id'],
        'firstName' => $row['first_name'] ?? '',
        'lastName' => $row['last_name'] ?? '',
        'role' => $role,
        'email' => $row['email'] ?? '',
        'phone' => $row['phone'] ?? '',
        'status' => $row['status'] ?? 'active',
        'twoFactorEnabled' => ($row['2fa_enabled'] ?? 0) == 1
    ];

    // Always include these fields, even if null
    if ($role === 'student') {
        $user['department'] = $row['department'] ?? null;
        $user['position'] = $row['position'] ?? null;
        $user['office'] = null; // Students don't have office
    } elseif ($role === 'employee') {
        $user['department'] = $row['department'] ?? null;
        $user['position'] = $row['position'] ?? null;
        $user['office'] = null; // Employees might have office, but not in current schema
    } else { // admin
        $user['office'] = $row['office'] ?? null;
        $user['position'] = $row['position'] ?? null;
        $user['department'] = null; // Admins don't have department
    }
    return $user;
}

try {
    $db = (new Database())->getConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $payload = get_json_payload();
    $action = $payload['action'] ?? '';

    // ----------------------------------
    // UPDATE PROFILE: Allow users to update their own profile
    // ----------------------------------
    if ($method === 'POST' && $action === 'update_profile') {
        // Require authentication
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
            json_error('Authentication required', 401);
        }

        $userId = $_SESSION['user_id'];
        $role = $_SESSION['user_role'];
        $table = get_table_by_role($role);

        if (!$table) {
            json_error('Invalid user role', 400);
        }

        $firstName = trim($payload['first_name'] ?? '');
        $lastName = trim($payload['last_name'] ?? '');
        $email = trim($payload['email'] ?? '');
        $phone = trim($payload['phone'] ?? '');

        if (!$firstName || !$lastName || !$email) {
            json_error('First name, last name, and email are required', 400);
        }

        // Check if email is already used by another user
        $emailCheckSql = "SELECT id FROM $table WHERE email = :email AND id != :userId";
        $emailCheck = $db->prepare($emailCheckSql);
        $emailCheck->execute([':email' => $email, ':userId' => $userId]);

        if ($emailCheck->fetch()) {
            json_error('Email address is already in use', 409);
        }

        // Update user profile
        $updateSql = "UPDATE $table SET first_name = :firstName, last_name = :lastName, email = :email, phone = :phone WHERE id = :userId";
        $update = $db->prepare($updateSql);
        $result = $update->execute([
            ':firstName' => $firstName,
            ':lastName' => $lastName,
            ':email' => $email,
            ':phone' => $phone,
            ':userId' => $userId
        ]);

        if ($result) {
            addAuditLog('PROFILE_UPDATED', 'User Management', 'User updated their profile', $userId, 'User', 'INFO');
            json_response(['success' => true, 'message' => 'Profile updated successfully']);
        } else {
            json_error('Failed to update profile', 500);
        }
    }

    // ----------------------------------
    // READ: List users (optionally by role) with pagination
    // ----------------------------------
    if ($method === 'GET') {
        $role = $_GET['role'] ?? null; // optional filter
        $search = $_GET['search'] ?? null; // optional search
        $status = $_GET['status'] ?? null; // optional status filter
        $startDate = $_GET['start_date'] ?? null; // optional start date
        $endDate = $_GET['end_date'] ?? null; // optional end date
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;

        $users = [];
        $total = 0;
        $rolesToFetch = $role ? [$role] : ['admin', 'employee', 'student'];
        foreach ($rolesToFetch as $r) {
            $table = get_table_by_role($r);
            if (!$table) {
                continue; // ignore invalid roles in query param
            }

            // Build WHERE clause for search
            $whereClause = '';
            $params = [];
            if ($search) {
                $searchTerm = "%$search%";
                if ($r === 'student') {
                    $whereClause = " WHERE (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR department LIKE :search OR position LIKE :search OR phone LIKE :search)";
                } elseif ($r === 'employee') {
                    $whereClause = " WHERE (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR department LIKE :search OR position LIKE :search OR phone LIKE :search)";
                } else {
                    $whereClause = " WHERE (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR office LIKE :search OR position LIKE :search OR phone LIKE :search)";
                }
                $params[':search'] = $searchTerm;
            }
            if ($status) {
                $whereClause .= ($whereClause ? ' AND' : ' WHERE') . " status = :status";
                $params[':status'] = $status;
            }
            if ($startDate && $endDate) {
                $whereClause .= ($whereClause ? ' AND' : ' WHERE') . " DATE(created_at) BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date'] = $endDate;
            } elseif ($startDate) {
                $whereClause .= ($whereClause ? ' AND' : ' WHERE') . " DATE(created_at) >= :start_date";
                $params[':start_date'] = $startDate;
            } elseif ($endDate) {
                $whereClause .= ($whereClause ? ' AND' : ' WHERE') . " DATE(created_at) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            // Get total count for this role
            $countSql = "SELECT COUNT(*) as count FROM $table" . $whereClause;
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $total += $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Select role-specific fields with pagination
            if ($r === 'student') {
                $sql = "SELECT id, first_name, last_name, email, department, position, phone, status, 2fa_enabled FROM $table" . $whereClause . " ORDER BY id LIMIT :limit OFFSET :offset";
            } elseif ($r === 'employee') {
                $sql = "SELECT id, first_name, last_name, email, department, position, phone, status, 2fa_enabled FROM $table" . $whereClause . " ORDER BY id LIMIT :limit OFFSET :offset";
            } else {
                $sql = "SELECT id, first_name, last_name, email, office, position, phone, status, 2fa_enabled FROM $table" . $whereClause . " ORDER BY id LIMIT :limit OFFSET :offset";
            }
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Use ID as key so the UI can access quickly by ID
                $users[$row['id']] = unify_user_row($row, $r);
            }
        }

        $totalPages = ceil($total / $limit);
        json_response(['success' => true, 'users' => $users, 'total' => $total, 'page' => $page, 'limit' => $limit, 'totalPages' => $totalPages]);
    }

    // For write operations, parse JSON body once
    $payload = get_json_payload();

    // ----------------------------------
    // CREATE: Create a user
    // ----------------------------------
    if ($method === 'POST' && $action === 'create') {
        $role = $payload['role'] ?? '';
        $table = get_table_by_role($role);
        if (!$table) {
            json_error('Invalid role', 400);
        }

        $id = trim($payload['id'] ?? '');
        $first = trim($payload['first_name'] ?? $payload['firstName'] ?? '');
        $last = trim($payload['last_name'] ?? $payload['lastName'] ?? '');
        $email = trim($payload['email'] ?? '');
        $phone = trim($payload['phone'] ?? '');
        $position = trim($payload['position'] ?? '');
        $office = $role !== 'student' ? trim($payload['office'] ?? '') : null;
        $department = trim($payload['department'] ?? ''); // Department can be for both students and employees

        if (!$id || !$first || !$last || !$email) {
            json_error('Missing required fields', 400);
        }

        // Enforce ID uniqueness across all user tables
        $checkSql = "SELECT id FROM administrators WHERE id=:id
                     UNION SELECT id FROM employees WHERE id=:id
                     UNION SELECT id FROM students WHERE id=:id";
        $chk = $db->prepare($checkSql);
        $chk->execute([':id' => $id]);
        if ($chk->fetch()) {
            json_error('User ID already exists', 409);
        }

        // --- NEW: STRICT BACKEND PASSWORD VALIDATION ---
        $defaultPassword = $payload['default_password'] ?? 'ChangeMe123!';

        // Pentest check: Minimum 8 characters, 1 Uppercase, 1 Number, 1 Special Character
        if (
            strlen($defaultPassword) < 8 ||
            !preg_match('/[A-Z]/', $defaultPassword) ||
            !preg_match('/[0-9]/', $defaultPassword) ||
            !preg_match('/[^A-Za-z0-9]/', $defaultPassword)
        ) {

            json_error('Password must be at least 8 characters long and contain at least one uppercase letter, one number, and one special character.', 400);
        }
        // -----------------------------------------------

        $hash = password_hash($defaultPassword, PASSWORD_BCRYPT);

        if ($role === 'student') {
            $ins = $db->prepare("INSERT INTO $table (id, first_name, last_name, email, password, department, position, phone, must_change_password)
                                 VALUES (:id,:first,:last,:email,:password,:department,:position,:phone,1)");
            $ok = $ins->execute([
                ':id' => $id,
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':password' => $hash,
                ':department' => $department,
                ':position' => $position,
                ':phone' => $phone
            ]);
        } elseif ($role === 'employee') {
            $ins = $db->prepare("INSERT INTO $table (id, first_name, last_name, email, password, department, position, phone, must_change_password)
                                 VALUES (:id,:first,:last,:email,:password,:department,:position,:phone,1)");
            $ok = $ins->execute([
                ':id' => $id,
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':password' => $hash,
                ':department' => $department,
                ':position' => $position,
                ':phone' => $phone
            ]);
        } else {
            $ins = $db->prepare("INSERT INTO $table (id, first_name, last_name, email, password, office, position, phone, must_change_password)
                                 VALUES (:id,:first,:last,:email,:password,:office,:position,:phone,1)");
            $ok = $ins->execute([
                ':id' => $id,
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':password' => $hash,
                ':office' => $office,
                ':position' => $position,
                ':phone' => $phone
            ]);
        }

        if ($ok) {
            // Generate TOTP secret for 2FA
            $google2fa = new Google2FA();
            $secret = $google2fa->generateSecretKey();

            // Update user with 2FA secret and mark as not enabled
            $updateStmt = $db->prepare("UPDATE $table SET 2fa_secret = ?, 2fa_enabled = 0 WHERE id = ?");
            $updateStmt->execute([$secret, $id]);

            addAuditLog('USER_CREATED', 'User Management', "Created new $role: $first $last ($id) with temporary password and 2FA pending setup", $id, 'User', 'INFO');
            json_response([
                'success' => true,
                'message' => 'User created successfully. User must set up 2FA on first login.',
                'user' => [
                    'id' => $id,
                    'firstName' => $first,
                    'lastName' => $last,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                    'office' => $office,
                    'department' => $department,
                    'position' => $position
                ]
            ]);
        } else {
            json_error('Create failed', 500);
        }
    }

    // ----------------------------------
    // RESET 2FA: Reset 2FA for a user
    // ----------------------------------
    if ($method === 'POST' && $action === 'reset_2fa') {
        $id = $payload['id'] ?? '';
        $role = $payload['role'] ?? '';
        $table = get_table_by_role($role);
        if (!$table || !$id) {
            json_error('Missing id or role', 400);
        }

        $stmt = $db->prepare("UPDATE $table SET 2fa_secret = NULL, 2fa_enabled = 0 WHERE id = :id");
        $ok = $stmt->execute([':id' => $id]);
        if ($ok) {
            addAuditLog('USER_2FA_RESET', 'User Management', "Reset 2FA for $role: $id", $id, 'User', 'WARNING');
        }
        json_response(['success' => $ok, 'message' => $ok ? '2FA reset successfully' : 'Reset failed']);
    }

    // ----------------------------------
    // TOGGLE STATUS: Deactivate/Reactivate user
    // ----------------------------------
    if ($method === 'POST' && $action === 'toggle_status') {
        $id = $payload['id'] ?? '';
        $role = $payload['role'] ?? '';
        $newStatus = $payload['status'] ?? 'active'; // 'active' or 'inactive'
        
        $table = get_table_by_role($role);
        if (!$table || !$id) {
            json_error('Missing id, role, or status', 400);
        }
        
        if (!in_array($newStatus, ['active', 'inactive'])) {
            json_error('Invalid status value', 400);
        }

        if ($role === 'admin' && ($id === ($_SESSION['user_id'] ?? '')) && $newStatus === 'inactive') {
            json_error('Cannot deactivate your own admin account', 400);
        }

        // If deactivating, we update the status. 
        // NOTE: Unique email constraint remains. ID uniqueness remains.
        // If "position becomes available" implies releasing a unique constraint on position, 
        // we would need updates to how positions are checked. 
        // Currently, there is NO unique constraint on position in the schema or code, 
        // so setting status to inactive naturally "frees" it up in a logical sense (e.g. they can't login).
        
        $stmt = $db->prepare("UPDATE $table SET status = :status WHERE id = :id");
        $ok = $stmt->execute([':status' => $newStatus, ':id' => $id]);
        
        if ($ok) {
            $logAction = $newStatus === 'active' ? 'USER_REACTIVATED' : 'USER_DEACTIVATED';
            addAuditLog($logAction, 'User Management', ucfirst($newStatus) . " user $role: $id", $id, 'User', 'WARNING');
        }
        json_response(['success' => $ok, 'message' => $ok ? "User marked as $newStatus" : 'Status update failed']);
    }

    // ----------------------------------
    // UPDATE: Update a user (role required to select table)
    // ----------------------------------
    if ($method === 'PUT') {
        $id = $_GET['id'] ?? ($payload['id'] ?? '');
        $role = $_GET['role'] ?? ($payload['role'] ?? '');
        $table = get_table_by_role($role);
        if (!$table || !$id) {
            json_error('Missing id or role', 400);
        }

        $first = trim($payload['first_name'] ?? $payload['firstName'] ?? '');
        $last = trim($payload['last_name'] ?? $payload['lastName'] ?? '');
        $email = trim($payload['email'] ?? '');
        $phone = trim($payload['phone'] ?? '');
        $position = trim($payload['position'] ?? '');
        $office = $role === 'admin' ? trim($payload['office'] ?? '') : null;  // Only for admins
        $department = $role === 'student' ? trim($payload['department'] ?? '') : ($role === 'employee' ? trim($payload['department'] ?? '') : null);

        if ($role === 'student') {
            $sql = "UPDATE $table SET first_name=:first, last_name=:last, email=:email, department=:department, position=:position, phone=:phone WHERE id=:id";
            $params = [
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':department' => $department,
                ':position' => $position,
                ':phone' => $phone,
                ':id' => $id
            ];
        } else {
            // For employees and admins
            $sql = $role === 'employee'
                ? "UPDATE $table SET first_name=:first, last_name=:last, email=:email, department=:department, position=:position, phone=:phone WHERE id=:id"
                : "UPDATE $table SET first_name=:first, last_name=:last, email=:email, office=:office, position=:position, phone=:phone WHERE id=:id";
            $params = [
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':position' => $position,
                ':phone' => $phone,
                ':id' => $id
            ];
            if ($role === 'employee') {
                $params[':department'] = $department; // This is included
            } else {
                $params[':office'] = $office;
            }
        }
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute($params);
        if ($ok) {
            addAuditLog('USER_UPDATED', 'User Management', "Updated $role: $first $last ($id)", $id, 'User', 'INFO');
        }
        json_response(['success' => $ok, 'message' => $ok ? 'User updated' : 'Update failed']);
    }

    // ----------------------------------
    // DELETE: Delete a user (prevent self-deletion for admin)
    // ----------------------------------
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        $role = $_GET['role'] ?? '';
        $table = get_table_by_role($role);
        if (!$table || !$id) {
            json_error('Missing id or role', 400);
        }

        if ($role === 'admin' && ($id === ($_SESSION['user_id'] ?? ''))) {
            json_error('Cannot delete your own admin account', 400);
        }

        $stmt = $db->prepare("DELETE FROM $table WHERE id=:id");
        $ok = $stmt->execute([':id' => $id]);
        if ($ok) {
            addAuditLog('USER_DELETED', 'User Management', "Deleted $role: $id", $id, 'User', 'WARNING');
        }
        json_response(['success' => $ok, 'message' => $ok ? 'User deleted' : 'Delete failed']);
    }

    // Fallback for unsupported methods
    json_error('Method not allowed', 405);
} catch (PDOException $e) {
    error_log('Users API error: ' . $e->getMessage());
    json_error('Server error', 500);
}

?>