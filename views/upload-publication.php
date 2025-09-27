<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
  logoutUser();
  header('Location: user-login.php');
  exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
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
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Log page view
addAuditLog('UPLOAD_PUBLICATION_VIEWED', 'Materials', 'Viewed upload publication page', $currentUser['id'], 'User', 'INFO');

// Debug: Log user data (optional, for development)
error_log("DEBUG upload-publication.php: Current user data: " . json_encode($currentUser));
error_log("DEBUG upload-publication.php: Session data: " . json_encode($_SESSION));
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
  <title>Sign-um - Upload Publication Materials</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <!-- Custom CSS -->
  <link href="../assets/css/global.css" rel="stylesheet"> <!-- Global shared UI styles -->
  <link href="../assets/css/upload-publication.css" rel="stylesheet">
  <link href="../assets/css/toast.css" rel="stylesheet">

  <script>
    // Pass user data to JavaScript (for consistency with event-calendar.php)
    window.currentUser = <?php
    // Convert snake_case to camelCase for JavaScript
    $jsUser = $currentUser;
    $jsUser['firstName'] = $currentUser['first_name'];
    $jsUser['lastName'] = $currentUser['last_name'];
    $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
    echo json_encode($jsUser);
    ?>;
    window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;
  </script>
</head>

<body class="with-fixed-navbar">
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg fixed-top">
    <div class="container-fluid">
      <div class="navbar-brand">
        <i class="bi bi-cloud-upload me-2"></i>
        Sign-um | Upload Materials
      </div>

      <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
        <!-- User Info -->
        <div class="user-info me-3">
          <i class="bi bi-person-circle me-2"></i>
          <span
            id="userDisplayName"><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
          <span class="badge ms-2 <?php
          echo ($currentUser['role'] === 'admin') ? 'bg-danger' :
            (($currentUser['role'] === 'employee') ? 'bg-primary' : 'bg-success');
          ?>" id="userRoleBadge">
            <?php echo strtoupper($currentUser['role']); ?>
          </span>
        </div>

        <!-- Settings Dropdown -->
        <div class="dropdown me-3">
          <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-gear me-2"></i>Settings
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="event-calendar.php"><i class="bi bi-calendar-event me-2"></i>Calendar</a>
            </li>
            <li><a class="dropdown-item" href="create-document.php"><i class="bi bi-file-text me-2"></i>Create
                Document</a></li>
            <li><a class="dropdown-item" href="track-document.php"><i class="bi bi-file-earmark-check me-2"></i>Track
                Documents</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item text-danger" href="user-logout.php"><i
                  class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </nav>

  <!-- Page Header -->
  <div class="page-header-section">
    <div class="container-fluid">
      <div class="page-header-content">
        <h1 class="page-title">
          <i class="bi bi-cloud-upload me-3"></i>
          Upload Document Materials
        </h1>
        <p class="page-subtitle">Submit supporting documents and materials for your signing requests</p>
      </div>
    </div>
  </div>

  <!-- Main Content -->
  <div class="container">
    <!-- File Requirements & Guidelines -->
    <div class="card restrictions-card mb-4">
      <div class="card-body">
        <div class="d-flex align-items-start">
          <i class="bi bi-info-circle-fill text-warning me-3 mt-1" style="font-size: 1.5rem;"></i>
          <div class="flex-grow-1">
            <h6 class="text-warning mb-3">
              <i class="bi bi-shield-check me-2"></i>File Requirements & Guidelines
            </h6>
            <div class="row">
              <div class="col-md-6">
                <ul class="mb-0 small">
                  <li><strong>Supported formats:</strong> JPG, JPEG, PNG, GIF, PDF, DOC, DOCX</li>
                  <li><strong>Maximum file size:</strong> 10MB per file</li>
                  <li><strong>Image resolution:</strong> Minimum 300 DPI for print materials</li>
                </ul>
              </div>
              <div class="col-md-6">
                <ul class="mb-0 small">
                  <li><strong>Total upload limit:</strong> 100MB per submission</li>
                  <li><strong>File naming:</strong> Use descriptive names (no spaces)</li>
                  <li><strong>Not supported:</strong> BMP, TIFF, RAR, ZIP, EXE files</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Storage Usage Monitor -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0">
            <i class="bi bi-hdd-stack me-2 text-primary"></i>Storage Usage
          </h6>
          <span class="text-muted small" id="usage-text">0 MB / 100 MB (0%)</span>
        </div>
        <div class="progress">
          <div class="progress-bar bg-success" role="progressbar" style="width: 0%" id="usage-bar" aria-valuenow="0"
            aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="mt-2 small text-muted d-flex justify-content-between">
          <span>Files uploaded: <span id="file-count">0</span></span>
          <span>Remaining: <span id="remaining-space">100 MB</span></span>
        </div>
      </div>
    </div>

    <!-- Enhanced Upload Section -->
    <div class="card upload-section mb-4" id="upload-section">
      <div class="card-body p-0">
        <div class="upload-zone text-center" id="upload-zone">
          <div class="upload-content">
            <i class="bi bi-cloud-upload upload-icon mb-3"></i>
            <h5 class="mb-2">Drag & Drop Your Files Here</h5>
            <p class="text-muted mb-3">
              <i class="bi bi-cursor me-2"></i>or click anywhere in this area to browse files
            </p>
            <div class="upload-actions">
              <button type="button" class="btn btn-primary me-2">
                <i class="bi bi-plus-circle me-2"></i>Choose Files
              </button>
            </div>
            <input type="file" id="file-input" class="file-input" multiple
              accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.JPG,.JPEG,.PNG,.GIF,.PDF,.DOC,.DOCX">
          </div>
        </div>

        <!-- File Preview Area -->
        <div class="preview-container" id="preview-container" style="display: none;">
          <div class="preview-header">
            <h6 class="mb-0">
              <i class="bi bi-images me-2"></i>Uploaded Files
              <span class="badge bg-primary ms-2" id="file-count-badge">0</span>
            </h6>
            <div class="preview-actions">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="clear-all-btn">
                <i class="bi bi-trash me-2"></i>Clear All
              </button>
            </div>
          </div>
          <div class="preview-grid" id="preview-grid"></div>
        </div>
      </div>
    </div>

    <!-- Enhanced Alert Container -->
    <div id="alert-container" class="mb-4"></div>

    <!-- Enhanced Submit Section -->
    <div class="card">
      <div class="card-body text-center">
        <div class="submit-section">
          <div class="submit-info mb-4">
            <i class="bi bi-check-circle-fill text-success me-2" style="font-size: 1.5rem;"></i>
            <h6 class="mb-2">Ready to Submit?</h6>
            <p class="text-muted mb-0 small">
              Review your uploaded files above, then click submit to send for approval
            </p>
          </div>

          <div class="submit-actions">
            <button type="button" class="btn submit-btn me-3" id="submit-btn" disabled>
              <i class="bi bi-send me-2"></i>Submit for Approval
            </button>
          </div>

          <div class="submit-notes mt-3">
            <small class="text-muted">
              <i class="bi bi-info-circle me-1"></i>
              Files will be reviewed within 2-3 business days. You'll receive notification updates via email.
            </small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Toast Utils -->
  <script src="../assets/js/toast.js"></script>

  <!-- Custom JavaScript -->
  <script src="../assets/js/upload-publication.js"></script>

  <script>
    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function () {
      var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
      });
    });
  </script>
</body>

</html>