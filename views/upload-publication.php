<?php
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
      $severity,
      $_SERVER['REMOTE_ADDR'] ?? null,
      null, // Set user_agent to null to avoid storing PII
    ]);
  } catch (Exception $e) {
    error_log("Failed to add audit log: " . $e->getMessage());
  }
}

// Log page view
addAuditLog('UPLOAD_PUBLICATION_VIEWED', 'Materials', 'Viewed upload publication page', $currentUser['id'], 'User', 'INFO');

// Set page title for navbar
$pageTitle = 'Upload Publications';
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/jpeg" href="<?php echo BASE_URL; ?>assets/images/sign-um-favicon.jpg">
  <title>Sign-um - Upload Publication Materials</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <!-- 
    CSS Loading Order (Important):
    1. global.css - OneUI foundation (navbar, buttons, forms, cards, modals, alerts, utilities)
    2. upload-publication.css - Page-specific overrides and upload components
    3. toast.css - Toast notification styles
  -->
  <link href="<?php echo BASE_URL; ?>assets/css/global.css" rel="stylesheet">
  <link href="<?php echo BASE_URL; ?>assets/css/upload-publication.css" rel="stylesheet">
  <link href="<?php echo BASE_URL; ?>assets/css/toast.css" rel="stylesheet">
  <link href="<?php echo BASE_URL; ?>assets/css/global-notifications.css" rel="stylesheet"><!-- Global notifications styles -->

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
    window.BASE_URL = "<?php echo BASE_URL; ?>";

    // REMOVE: Duplicate loadNotifications function and interval (handled by global-notifications.js)
  </script>

  <!-- ADD: Include global notifications module -->
      </head>

  <body class="with-fixed-navbar">
    <?php include ROOT_PATH . 'includes/navbar.php'; ?>
    <?php include ROOT_PATH . 'includes/notifications.php'; ?>

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
          <div class="flex-shrink-0 me-3">
            <div class="d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(251, 191, 36, 0.15)); border-radius: 12px;">
              <i class="bi bi-info-circle-fill text-warning" style="font-size: 1.75rem;"></i>
            </div>
          </div>
          <div class="flex-grow-1">
            <h6 class="text-warning mb-3 d-flex align-items-center">
              <i class="bi bi-shield-check me-2"></i>File Requirements & Guidelines
            </h6>
            <div class="row">
              <div class="col-md-6">
                <ul class="mb-3 mb-md-0 small">
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
          <h6 class="mb-0 d-flex align-items-center">
            <div class="d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.1)); border-radius: 8px;">
              <i class="bi bi-hdd-stack text-primary"></i>
            </div>
            Storage Usage
          </h6>
          <span class="text-muted small fw-semibold" id="usage-text">0 MB / 100 MB (0%)</span>
        </div>
        <div class="progress" style="height: 10px;">
          <div class="progress-bar bg-success" role="progressbar" style="width: 0%" id="usage-bar" aria-valuenow="0"
            aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="mt-3 small d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-files me-1"></i>Files: <strong id="file-count">0</strong></span>
          <span class="text-muted"><i class="bi bi-hdd me-1"></i>Available: <strong id="remaining-space">100 MB</strong></span>
        </div>
      </div>
    </div>

    <!-- Enhanced Upload Section -->
    <div class="card upload-section mb-4" id="upload-section">
      <div class="card-body p-0">
        <div class="upload-zone text-center" id="upload-zone">
          <div class="upload-content">
            <div class="mb-3">
              <i class="bi bi-cloud-arrow-up upload-icon"></i>
            </div>
            <h5 class="mb-2">Drop Your Files Here</h5>
            <p class="text-muted mb-4">
              <i class="bi bi-cursor me-1"></i>or click to browse and select files from your device
            </p>
            <div class="upload-actions">
              <button type="button" class="btn btn-primary btn-lg px-4">
                <i class="bi bi-folder2-open me-2"></i>Browse Files
              </button>
            </div>
            <p class="text-muted small mt-3 mb-0">
              <i class="bi bi-lightning-charge me-1"></i>Supports multiple file selection and drag & drop
            </p>
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
      <div class="card-body text-center py-4">
        <div class="submit-section">
          <div class="submit-info mb-4">
            <div class="d-flex align-items-center justify-content-center mb-3">
              <div class="d-flex align-items-center justify-content-center" style="width: 56px; height: 56px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1)); border-radius: 16px;">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 2rem;"></i>
              </div>
            </div>
            <h6 class="mb-2 fw-bold" style="font-size: 1.15rem;">Ready to Submit?</h6>
            <p class="text-muted mb-0">
              <i class="bi bi-info-circle me-1"></i>Review your uploaded files and click submit when ready
            </p>
          </div>

          <div class="submit-actions">
            <button type="button" class="btn submit-btn" id="submit-btn" disabled>
              <i class="bi bi-send-fill me-2"></i>Submit for Approval
            </button>
          </div>

          <div class="submit-notes mt-4">
            <div class="d-inline-flex align-items-center px-3 py-2" style="background: rgba(59, 130, 246, 0.05); border-radius: 8px; border-left: 3px solid #3b82f6;">
              <i class="bi bi-clock-history me-2 text-primary"></i>
              <small class="text-muted">
                Files will be reviewed within <strong class="text-primary">2-3 business days</strong>. You'll receive email notifications.
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Toast Utils -->
  <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>

  <!-- Custom JavaScript -->
  <script src="<?php echo BASE_URL; ?>assets/js/upload-publication.js"></script>

  <script>
    // Initialize tooltips
      document.addEventListener('DOMContentLoaded', function () {
      var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
      });
    });
  </script>

  <!-- Profile Settings Modal -->
  <div class="modal fade" id="profileSettingsModal" tabindex="-1" aria-labelledby="profileSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="profileSettingsModalLabel"><i class="bi bi-person-gear me-2"></i>Profile Settings</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="profileSettingsForm">
            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="profileFirstName" class="form-label">First Name</label>
                  <input type="text" class="form-control" id="profileFirstName" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="profileLastName" class="form-label">Last Name</label>
                  <input type="text" class="form-control" id="profileLastName" required>
                </div>
              </div>
            </div>
            <div class="mb-3">
              <label for="profileEmail" class="form-label">Email</label>
              <input type="email" class="form-control" id="profileEmail" required>
            </div>
            <div class="mb-3">
              <label for="profilePhone" class="form-label">Phone Number</label>
              <input type="tel" class="form-control" id="profilePhone">
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="saveProfileSettings()">Save Changes</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Preferences Modal -->
  <div class="modal fade" id="preferencesModal" tabindex="-1" aria-labelledby="preferencesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="preferencesModalLabel"><i class="bi bi-sliders me-2"></i>Preferences</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="preferencesForm">
            <h6 class="mb-3">Upload Preferences</h6>
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="autoPreview" checked>
                <label class="form-check-label" for="autoPreview">
                  Automatically show file previews after upload
                </label>
              </div>
            </div>
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="confirmBeforeSubmit" checked>
                <label class="form-check-label" for="confirmBeforeSubmit">
                  Show confirmation dialog before submitting files
                </label>
              </div>
            </div>
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showFileDescriptions" checked>
                <label class="form-check-label" for="showFileDescriptions">
                  Enable file description fields
                </label>
              </div>
            </div>
            <div class="mb-3">
              <label for="maxFilesPerUpload" class="form-label">Maximum files per upload session</label>
              <select class="form-select" id="maxFilesPerUpload">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
            </div>
            <h6 class="mb-3 mt-4">Storage Preferences</h6>
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showStorageWarnings" checked>
                <label class="form-check-label" for="showStorageWarnings">
                  Show storage usage warnings
                </label>
              </div>
            </div>
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="autoCompress" checked>
                <label class="form-check-label" for="autoCompress">
                  Auto-compress large images (recommended)
                </label>
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Help Modal -->
  <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="helpModalLabel"><i class="bi bi-question-circle me-2"></i>Help & Support - Upload Publications</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="accordion" id="helpAccordion">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#gettingStarted">
                  Getting Started with File Uploads
                </button>
              </h2>
              <div id="gettingStarted" class="accordion-collapse collapse show" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>The Upload Publications page allows you to submit supporting documents and materials for your signing requests.</p>
                  <ul>
                    <li><strong>Drag & Drop:</strong> Simply drag files from your computer into the upload area</li>
                    <li><strong>Browse Files:</strong> Click anywhere in the upload area to browse and select files</li>
                    <li><strong>Multiple Files:</strong> You can upload multiple files at once</li>
                    <li><strong>File Previews:</strong> See previews of your uploaded files before submitting</li>
                  </ul>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#fileRequirements">
                  File Requirements and Guidelines
                </button>
              </h2>
              <div id="fileRequirements" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <h6>Supported File Types:</h6>
                  <ul>
                    <li><strong>Images:</strong> JPG, JPEG, PNG, GIF</li>
                    <li><strong>Documents:</strong> PDF, DOC, DOCX</li>
                    <li><strong>Maximum Size:</strong> 10MB per file</li>
                    <li><strong>Total Limit:</strong> 100MB per submission</li>
                  </ul>
                  <p><strong>Important:</strong> Files must have descriptive names using only letters, numbers, underscores, hyphens, and dots. Spaces are not allowed.</p>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#usingPreviews">
                  Using File Previews and Management
                </button>
              </h2>
              <div id="usingPreviews" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>After uploading files, you can:</p>
                  <ul>
                    <li><strong>Reorder Files:</strong> Drag files to change their order</li>
                    <li><strong>Add Descriptions:</strong> Enter descriptions for each file</li>
                    <li><strong>Remove Files:</strong> Click the X button to remove individual files</li>
                    <li><strong>Clear All:</strong> Remove all uploaded files at once</li>
                  </ul>
                  <p><strong>Tip:</strong> File descriptions help reviewers understand the context of each document.</p>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#storageMonitoring">
                  Storage Usage and Limits
                </button>
              </h2>
              <div id="storageMonitoring" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>Monitor your upload progress with the storage usage indicator:</p>
                  <ul>
                    <li><strong>Green:</strong> Low usage (under 70%)</li>
                    <li><strong>Yellow:</strong> Moderate usage (70-90%)</li>
                    <li><strong>Red:</strong> High usage (over 90%)</li>
                  </ul>
                  <p><strong>Note:</strong> The system prevents uploads that would exceed the 100MB total limit.</p>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submissionProcess">
                  Submission and Approval Process
                </button>
              </h2>
              <div id="submissionProcess" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>After uploading your files:</p>
                  <ol>
                    <li>Review all files and add descriptions if needed</li>
                    <li>Click "Submit for Approval" to send your files</li>
                    <li>Files will be reviewed within 2-3 business days</li>
                    <li>You'll receive email notifications about the approval status</li>
                    <li>Track progress using the "Track Documents" page</li>
                  </ol>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#troubleshooting">
                  Troubleshooting Common Issues
                </button>
              </h2>
              <div id="troubleshooting" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <h6>Common Issues:</h6>
                  <ul>
                    <li><strong>"Unsupported file type":</strong> Check that your file is one of the allowed formats</li>
                    <li><strong>"File too large":</strong> Reduce file size or compress images</li>
                    <li><strong>"Invalid file name":</strong> Remove spaces and special characters from filenames</li>
                    <li><strong>"Total upload limit exceeded":</strong> Remove some files or use smaller files</li>
                  </ul>
                  <p>If you continue to experience issues, contact your system administrator.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <a href="track-document.php" class="btn btn-primary">Track Documents</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Notifications Modal - OneUI Enhanced -->
  <div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="notificationsModalLabel">
            <i class="bi bi-bell-fill"></i>
            <span>Notifications</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="notificationsList">
            <!-- Notifications will be populated here -->
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAllAsRead()">
            <i class="bi bi-check-all me-2"></i>Mark All Read
          </button>
          <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-2"></i>Close
          </button>
        </div>
      </div>
    </div>
  </div>
  </body>

</html>