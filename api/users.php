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
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/session.php';

// --------------------------------------
// Guard: Require authentication + admin role
// --------------------------------------
if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'admin') {
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
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
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
        'phone' => $row['phone'] ?? ''
    ];
    if ($role === 'student') {
        $user['department'] = $row['department'] ?? null;
        $user['position'] = $row['position'] ?? null;
    } else {
        $user['office'] = $row['office'] ?? null;
        $user['position'] = $row['position'] ?? null;
    }
    return $user;
}

try {
    $db = (new Database())->getConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ----------------------------------
    // READ: List users (optionally by role) with pagination
    // ----------------------------------
    if ($method === 'GET') {
        $role = $_GET['role'] ?? null; // optional filter
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

            // Get total count for this role
            $countSql = "SELECT COUNT(*) as count FROM $table";
            $countStmt = $db->query($countSql);
            $total += $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Select role-specific fields with pagination
            if ($r === 'student') {
                $sql = "SELECT id, first_name, last_name, email, department, position, phone FROM $table ORDER BY id LIMIT :limit OFFSET :offset";
            } else {
                $sql = "SELECT id, first_name, last_name, email, office, position, phone FROM $table ORDER BY id LIMIT :limit OFFSET :offset";
            }
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
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
    if ($method === 'POST') {
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
        $department = $role === 'student' ? trim($payload['department'] ?? '') : null;

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

        // Default password can be provided via payload['default_password']
        $defaultPassword = $payload['default_password'] ?? 'ChangeMe123!';
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
            json_response([
                'success' => true,
                'message' => 'User created',
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
        $office = $role !== 'student' ? trim($payload['office'] ?? '') : null;
        $department = $role === 'student' ? trim($payload['department'] ?? '') : null;

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
            $sql = "UPDATE $table SET first_name=:first, last_name=:last, email=:email, office=:office, position=:position, phone=:phone WHERE id=:id";
            $params = [
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':office' => $office,
                ':position' => $position,
                ':phone' => $phone,
                ':id' => $id
            ];
        }
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute($params);
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
        json_response(['success' => $ok, 'message' => $ok ? 'User deleted' : 'Delete failed']);
    }

    // Fallback for unsupported methods
    json_error('Method not allowed', 405);
} catch (PDOException $e) {
    error_log('Users API error: ' . $e->getMessage());
    json_error('Server error', 500);
}

?>