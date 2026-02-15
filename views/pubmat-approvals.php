<?php
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth(); // Requires login

// Get current user first to check role
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
if (!$currentUser) {
    logoutUser();
    header('Location: ' . BASE_URL . '?page=login');
    exit();
}

// Restrict to specific approver positions
$allowedPositions = ['CSC Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
if ($currentUser['role'] !== 'employee' || !in_array($currentUser['position'] ?? '', $allowedPositions)) {
    header('Location: ' . BASE_URL . '?page=user-login');
    exit();
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            trim($currentUser['first_name'] . ' ' . $currentUser['last_name']),
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

// Log page view
addAuditLog('PUBMAT_APPROVALS_VIEWED', 'Materials', 'Viewed pubmat approvals page', $currentUser['id'], 'User', 'INFO');

// Set page title for navbar
$pageTitle = 'Pubmat Approvals';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="<?php echo BASE_URL; ?>assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Pubmat Approvals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/toast.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/global-notifications.css">

    <script>
        window.currentUser = <?php echo json_encode([
            'id' => $currentUser['id'],
            'firstName' => $currentUser['first_name'],
            'lastName' => $currentUser['last_name'],
            'role' => $currentUser['role'],
            'position' => $currentUser['position'],
            'email' => $currentUser['email']
        ]); ?>;
        window.BASE_URL = "<?php echo BASE_URL; ?>";
        window.csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
    </script>
</head>

<body class="with-fixed-navbar">
    <?php
    include ROOT_PATH . 'includes/navbar.php';
    include ROOT_PATH . 'includes/notifications.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header-section">
            <div class="container-fluid">
                <div class="page-header-content">
                    <div class="d-flex align-items-center">
                        <button class="back-button me-3" onclick="history.back()" title="Go Back">
                            <i class="bi bi-arrow-left"></i>Back
                        </button>
                        <div>
                            <h1 class="page-title">
                                <i class="bi bi-file-earmark-text me-3"></i>
                                Pubmat Approvals
                            </h1>
                            <p class="page-subtitle">Review and approve public materials submissions</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Materials List -->
        <div class="container-fluid mt-4">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Pending Approvals</h5>
                        </div>
                        <div class="card-body">
                            <div id="materialsContainer">
                                <div class="text-center">
                                    <div class="spinner-border" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="modalMessage"></p>
                    <div class="mb-3">
                        <label for="approvalNote" class="form-label">Note (optional)</label>
                        <textarea class="form-control" id="approvalNote" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="rejectBtn">Reject</button>
                    <button type="button" class="btn btn-success" id="approveBtn">Approve</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Material Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewModalTitle">View Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="materialViewer">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="downloadBtnInModal">Download</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/pubmat-approvals.js"></script>
</body>

</html>