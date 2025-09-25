<?php
/**
 * Audit API - System Activity Logging
 * ====================================
 *
 * This API handles audit logging for the entire application. It allows:
 * - Admins to view audit logs with filtering and pagination (GET)
 * - Any logged-in user to create new audit entries (POST)
 * - Admins to clear all audit logs (DELETE)
 *
 * Audit logs track user actions, system events, and security-related activities
 * to provide accountability and monitoring capabilities.
 *
 * Security: Requires authentication. Viewing/clearing logs requires admin role.
 * All actions are logged to prevent tampering.
 */

require_once '../includes/session.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';

requireAuth(); // Ensure user is logged in

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        /**
         * GET /api/audit.php - Retrieve audit logs
         * ========================================
         * Only administrators can view audit logs.
         * Supports optional query parameters for filtering and pagination:
         * - category: Filter by log category (e.g., 'Security', 'User Management')
         * - severity: Filter by severity level ('INFO', 'WARNING', 'ERROR')
         * - search: Search in user_id, action, or details fields
         * - page: Page number for pagination (default: 1)
         * - limit: Number of logs per page (default: 50)
         */

        // Only admins can view audit logs
        if ($_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }

        // Fetch audit logs with optional filters and pagination

        // Parse query parameters with defaults
        $category = $_GET['category'] ?? null;
        $severity = $_GET['severity'] ?? null;
        $search = $_GET['search'] ?? null;
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit; // Calculate offset for SQL LIMIT

        // Build dynamic SQL query with optional WHERE conditions
        $query = "SELECT * FROM audit_logs WHERE 1=1"; // Start with always-true condition
        $countQuery = "SELECT COUNT(*) as total FROM audit_logs WHERE 1=1"; // For total count
        $params = []; // Parameters for main query
        $countParams = []; // Parameters for count query

        // Add category filter if provided
        if ($category) {
            $query .= " AND category = :category";
            $countQuery .= " AND category = :category";
            $params[':category'] = $category;
            $countParams[':category'] = $category;
        }

        // Add severity filter if provided
        if ($severity) {
            $query .= " AND severity = :severity";
            $countQuery .= " AND severity = :severity";
            $params[':severity'] = $severity;
            $countParams[':severity'] = $severity;
        }

        // Add search filter if provided (searches multiple fields)
        if ($search) {
            $query .= " AND (user_id LIKE :search1 OR action LIKE :search2 OR details LIKE :search3)";
            $countQuery .= " AND (user_id LIKE :search1 OR action LIKE :search2 OR details LIKE :search3)";
            $searchTerm = "%$search%"; // Add wildcards for LIKE search
            $params[':search1'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $countParams[':search1'] = $searchTerm;
            $countParams[':search2'] = $searchTerm;
            $countParams[':search3'] = $searchTerm;
        }

        // Add ordering and pagination to main query
        $query .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        // Execute main query to get logs
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT); // Bind as integer
            } else {
                $stmt->bindValue($key, $value); // Bind as string
            }
        }
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Execute count query to get total number of matching logs
        $countStmt = $conn->prepare($countQuery);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Return paginated results with metadata
        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit) // Calculate total pages
        ]);
        break;

    case 'POST':
        /**
         * POST /api/audit.php - Create new audit log entry
         * ================================================
         * Any authenticated user can create audit entries.
         * Expects JSON payload with audit details.
         * Used by frontend JavaScript to log user actions.
         */

        // Allow all logged-in users to log audit entries
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            exit();
        }

        // Parse JSON input from request body
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['action'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
            exit();
        }

        // Get current user information
        $userId = $_SESSION['user_id'];

        // Fetch user name from database (union of all user tables)
        $userStmt = $conn->prepare("
            SELECT first_name, last_name FROM (
                SELECT id, first_name, last_name FROM administrators WHERE id = ?
                UNION ALL
                SELECT id, first_name, last_name FROM employees WHERE id = ?
                UNION ALL
                SELECT id, first_name, last_name FROM students WHERE id = ?
            ) AS users WHERE id = ? LIMIT 1
        ");
        $userStmt->execute([$userId, $userId, $userId, $userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        $userName = $userRow ? $userRow['first_name'] . ' ' . $userRow['last_name'] : 'Unknown User';

        // Extract audit data from request
        $action = $data['action'];
        $category = $data['category'] ?? 'General';
        $details = $data['details'] ?? '';
        $targetId = $data['target_id'] ?? null;
        $targetType = $data['target_type'] ?? null;
        $severity = $data['severity'] ?? 'INFO';

        // Insert new audit log entry
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $userName,
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $_SERVER['REMOTE_ADDR'] ?? null, // Client IP address
            $_SERVER['HTTP_USER_AGENT'] ?? null, // Browser/client info
            $severity
        ]);

        echo json_encode(['success' => true]);
        break;

    case 'DELETE':
        /**
         * DELETE /api/audit.php - Clear all audit logs
         * ============================================
         * Only administrators can clear audit logs.
         * This permanently removes all audit entries from the database.
         * Use with caution - this action cannot be undone.
         */

        // Only admins can clear audit logs
        if ($_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM audit_logs");
        $stmt->execute();

        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>