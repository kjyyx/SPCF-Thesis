<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
  logoutUser();
  header('Location: ' . BASE_URL . 'login');
  exit();
}

// Restrict Accounting employees to only SAF access
if ($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) {
  header('Location: ' . BASE_URL . 'saf');
  exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
  global $currentUser;
  try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $currentUser['id'],
      $currentUser['role'],
      $currentUser['first_name'] . ' ' . $currentUser['last_name'],
      $action,
      $category,
      $details,
      $targetId,
      $targetType,
      $severity ?? 'INFO',
      $_SERVER['REMOTE_ADDR'] ?? null,
      null
    ]);
  } catch (Exception $e) {
    error_log("Failed to add audit log: " . $e->getMessage());
  }
}

// Log page view
addAuditLog('UPLOAD_PUBLICATION_VIEWED', 'Document Management', 'Viewed upload publication page', $currentUser['id'], 'User', 'INFO');

$pageTitle = 'Upload Publication';
$currentPage = 'upload-publication';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
  <title>Sign-um - Upload Publication</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/upload-publication.css">

  <script>
    // Pass user data to JS
    window.currentUser = <?php
    $jsUser = $currentUser;
    $jsUser['firstName'] = $currentUser['first_name'];
    $jsUser['lastName'] = $currentUser['last_name'];
    $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
    echo json_encode($jsUser);
    ?>;
    window.BASE_URL = "<?php echo BASE_URL; ?>";
  </script>
</head>

<body class="has-navbar bg-light">
  <?php include ROOT_PATH . 'includes/navbar.php'; ?>
  <?php include ROOT_PATH . 'includes/notifications.php'; ?>

  <div class="container pt-4 pb-5">

    <div class="page-header mb-4">
      <div>
        <h1 class="page-title">
          <i class="bi bi-cloud-upload text-primary me-2"></i> Upload Publication
        </h1>
        <p class="page-subtitle mb-0">Submit articles, photos, and materials for campus publication review.</p>
      </div>
      <div class="page-actions d-flex gap-2 flex-wrap">
        <button class="btn btn-ghost border rounded-pill fw-medium shadow-sm px-4" onclick="history.back()">
          <i class="bi bi-arrow-left me-1"></i> Back
        </button>
        <button class="btn btn-primary rounded-pill shadow-primary px-4 fw-medium" id="submit-btn" disabled>
          <i class="bi bi-send me-1"></i> Submit Files
        </button>
      </div>
    </div>

    <div id="alert-container"></div>

    <div class="row g-4">
      <div class="col-lg-8">

        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
          <div class="card-body p-0">
            <div id="upload-zone"
              class="upload-zone d-flex flex-column align-items-center justify-content-center p-5 text-center transition-base cursor-pointer">
              <input type="file" id="file-input" multiple accept="image/jpeg,image/png,image/gif,application/pdf"
                style="display: none;">

              <div class="btn-icon lg rounded-circle bg-primary-subtle mb-3">
                <i class="bi bi-cloud-arrow-up text-primary fs-3"></i>
              </div>
              <h5 class="fw-bold text-dark mb-2">Drag & Drop files here</h5>
              <p class="text-muted text-sm mb-4">or click to browse your computer</p>

              <div class="d-flex gap-2 flex-wrap justify-content-center">
                <span class="badge bg-light text-dark border rounded-pill px-3 py-2 text-xs"><i
                    class="bi bi-image me-1"></i> JPEG, PNG, GIF</span>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-2 text-xs"><i
                    class="bi bi-file-pdf me-1"></i> PDF</span>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-2 text-xs"><i
                    class="bi bi-hdd me-1"></i> Max 10MB per file</span>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-bold text-dark m-0">Uploaded Files <span id="file-count"
              class="badge bg-primary rounded-pill ms-2">0</span></h6>
          <button type="button" class="btn btn-light btn-sm rounded-pill fw-medium border shadow-sm" id="clear-all-btn"
            style="display: none;">Clear All</button>
        </div>
        <div id="preview-grid" class="preview-grid pb-4">
          <div class="text-center py-5 w-100 bg-white border border-dashed rounded-4" id="empty-preview-state">
            <p class="text-muted mb-0">No files selected yet.</p>
          </div>
        </div>
      </div>

      <div class="col-lg-4">

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-transparent border-bottom-0 p-4 pb-0">
            <h6 class="fw-bold text-dark m-0"><i class="bi bi-info-circle text-info me-2"></i> Publication Details</h6>
          </div>
          <div class="card-body p-4 d-flex flex-column gap-3">

            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Title <span
                  class="text-danger">*</span></label>
              <input type="text" class="form-control bg-light border-0 rounded-3 p-2 px-3" id="pub-title"
                placeholder="Enter publication title" required>
            </div>

            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Description / Notes</label>
              <textarea class="form-control bg-light border-0 rounded-3 p-2 px-3" id="pub-desc" rows="3"
                placeholder="Provide context or instructions for the publisher..."></textarea>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-body p-4">
            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-hdd-network text-secondary me-2"></i> Storage Usage</h6>

            <div class="d-flex justify-content-between text-xs fw-medium mb-2">
              <span class="text-muted" id="usage-text">0 MB / 50 MB Used</span>
              <span class="text-dark" id="usage-percent">0%</span>
            </div>
            <div class="progress rounded-pill bg-light" style="height: 8px;">
              <div id="usage-bar" class="progress-bar rounded-pill bg-primary transition-base" role="progressbar"
                style="width: 0%;"></div>
            </div>

            <hr class="my-4 text-muted">

            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-list-check text-success me-2"></i> Guidelines</h6>
            <ul class="text-sm text-muted ps-3 mb-0 d-flex flex-column gap-2">
              <li>Images will be automatically compressed if they exceed 2MB.</li>
              <li>You can drag to reorder files after uploading.</li>
              <li>Please ensure files do not contain sensitive personal information.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4 text-center p-4">
        <div class="modal-body py-4">
          <div class="btn-icon lg rounded-circle bg-success-subtle mx-auto mb-4"
            style="width: 80px; height: 80px; font-size: 2.5rem;">
            <i class="bi bi-check-circle-fill"></i>
          </div>
          <h4 class="fw-bold text-dark mb-2">Upload Successful!</h4>
          <p class="text-muted mb-4">Your publication materials have been submitted and are now awaiting review.</p>
          <div class="d-flex gap-3 justify-content-center">
            <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal"
              onclick="location.reload()">Upload More</button>
            <a href="<?php echo BASE_URL; ?>?page=dashboard" class="btn btn-primary rounded-pill px-4 shadow-sm">Return
              to Dashboard</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="profileSettingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-bottom-0 p-4">
          <h5 class="modal-title fw-bold"><i class="bi bi-person-gear me-2 text-primary"></i>Profile Settings</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="profileSettingsForm">
          <div class="modal-body p-4 pt-0 d-flex flex-column gap-3">
            <div id="profileSettingsMessages"></div>
            <div class="row g-3">
              <div class="col-md-6 form-group mb-0">
                <label for="profileFirstName" class="form-label text-muted small fw-bold text-uppercase">First
                  Name</label>
                <input type="text" class="form-control bg-light border-0 rounded-3 p-2 px-3" id="profileFirstName"
                  required <?php if ($currentUser['role'] !== 'admin')
                    echo 'readonly'; ?>>
              </div>
              <div class="col-md-6 form-group mb-0">
                <label for="profileLastName" class="form-label text-muted small fw-bold text-uppercase">Last
                  Name</label>
                <input type="text" class="form-control bg-light border-0 rounded-3 p-2 px-3" id="profileLastName"
                  required <?php if ($currentUser['role'] !== 'admin')
                    echo 'readonly'; ?>>
              </div>
            </div>
            <div class="form-group mb-0">
              <label for="profileEmail" class="form-label text-muted small fw-bold text-uppercase">Email Address</label>
              <input type="email" class="form-control bg-light border-0 rounded-3 p-2 px-3" id="profileEmail"
                pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" required>
              <div class="invalid-feedback">Please enter a valid email address.</div>
            </div>
          </div>
          <div class="modal-footer border-top p-3 px-4">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm"
              onclick="saveProfileSettings()">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="preferencesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-bottom-0 p-4">
          <h5 class="modal-title fw-bold"><i class="bi bi-sliders me-2 text-primary"></i>Preferences</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 pt-0 d-flex flex-column gap-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="autoPreview" checked>
            <label class="form-check-label fw-medium ms-2" for="autoPreview">Generate image previews</label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="autoCompress" checked>
            <label class="form-check-label fw-medium ms-2" for="autoCompress">Auto-compress large images</label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="showFileDescriptions" checked>
            <label class="form-check-label fw-medium ms-2" for="showFileDescriptions">Enable per-file
              descriptions</label>
          </div>
          <input type="checkbox" id="confirmBeforeSubmit" checked style="display:none;">
          <input type="checkbox" id="showStorageWarnings" checked style="display:none;">
          <input type="number" id="maxFilesPerUpload" value="10" style="display:none;">
        </div>
        <div class="modal-footer border-top p-3 px-4">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm"
            onclick="savePreferences()">Save</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/upload-publication.js"></script>
</body>

</html>