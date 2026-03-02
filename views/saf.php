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

// Access control: Only students, or employees with specific positions
$hasAccess = $currentUser['role'] === 'student' ||
  ($currentUser['role'] === 'employee' &&
    (stripos($currentUser['position'], 'Accounting') !== false ||
      stripos($currentUser['position'], 'OSA') !== false ||
      stripos($currentUser['position'], 'EVP') !== false));

if (!$hasAccess) {
  header('Location: ' . BASE_URL . 'login&error=access_denied');
  exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
  global $currentUser;
  try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $currentUser['id'],
      $currentUser['role'],
      $currentUser['first_name'] . ' ' . $currentUser['last_name'],
      $action,
      $category,
      $details,
      $targetId,
      $targetType,
      $_SERVER['REMOTE_ADDR'] ?? null,
      null,
      $severity ?? 'INFO'
    ]);
  } catch (Exception $e) {
    error_log("Failed to add audit log: " . $e->getMessage());
  }
}

// Log page view
addAuditLog('SAF_VIEWED', 'Financial Management', 'Viewed Student Allocated Funds page', null, 'Page', 'INFO');

$pageTitle = 'Student Allocated Funds';
$currentPage = 'saf';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
  <title>Sign-um - SAF Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">

  <script>
    // Pass user data to JS
    window.currentUser = <?php
    $jsUser = $currentUser;
    $jsUser['firstName'] = $currentUser['first_name'];
    $jsUser['lastName'] = $currentUser['last_name'];
    $jsUser['position'] = $currentUser['position'] ?? '';
    $jsUser['department'] = $currentUser['department'] ?? '';
    echo json_encode($jsUser);
    ?>;
    window.BASE_URL = "<?php echo BASE_URL; ?>";
  </script>
</head>

<body class="has-navbar bg-light">
  <?php include ROOT_PATH . 'includes/navbar.php'; ?>
  <?php include ROOT_PATH . 'includes/notifications.php'; ?>

  <div class="container pt-4 pb-5">

    <div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h1 class="page-title">
          <i class="bi bi-cash-coin text-success me-2"></i> SAF Dashboard
        </h1>
        <p class="page-subtitle">Manage and monitor department fund allocations and expenses</p>
      </div>
      <div class="page-actions d-flex gap-2 flex-wrap align-items-center" id="action-buttons">

        <div id="global-saf-container"
          class="d-none align-items-center bg-white border rounded-pill px-4 py-2 shadow-sm me-2">
          <span class="text-muted text-xs fw-bold text-uppercase me-2">System Total:</span>
          <span class="text-success fw-bold" id="global-total-saf"
            style="font-size: 1.1rem; letter-spacing: -0.5px;">₱0.00</span>
        </div>

        <button class="btn btn-primary rounded-pill shadow-primary" id="btn-allocate" data-bs-toggle="modal"
          data-bs-target="#allocateModal" style="display:none;">
          <i class="bi bi-plus-circle me-2"></i> Allocate
        </button>
        <button class="btn btn-ghost rounded-pill bg-white border shadow-sm" id="btn-add-deduct" data-bs-toggle="modal"
          data-bs-target="#addDeductModal" style="display:none;">
          <i class="bi bi-calculator me-1"></i> Add/Deduct
        </button>
        <button class="btn btn-ghost rounded-pill bg-white border shadow-sm" id="btn-edit" onclick="openEditSafModal()"
          style="display:none;">
          <i class="bi bi-pencil me-1"></i> Initial Fund
        </button>
        <button class="btn btn-ghost rounded-pill bg-white border shadow-sm text-danger" id="btn-reset"
          data-bs-toggle="modal" data-bs-target="#resetModal" style="display:none;">
          <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
        </button>
        <button class="btn btn-success rounded-pill shadow-sm" id="btn-approvals"
          onclick="window.location.href='<?php echo BASE_URL; ?>notifications'" style="display:none;">
          <i class="bi bi-check-circle me-1"></i> Approvals
        </button>
        <button class="btn btn-info rounded-pill shadow-sm" id="btn-calendar"
          onclick="window.location.href='<?php echo BASE_URL; ?>calendar'" style="display:none;">
          <i class="bi bi-calendar-event me-1"></i> Calendar
        </button>
      </div>
    </div>

    <div class="row g-4 h-full align-items-start">

      <div class="col-lg-3" id="sidebar" style="display: none;">
        <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 80px; z-index: 10;">
          <div class="card-header bg-transparent border-bottom-0 p-4 pb-2">
            <h6 class="fw-bold text-uppercase text-muted mb-0" style="font-size:0.75rem; letter-spacing: 1px;">
              Departments</h6>
          </div>
          <div class="card-body p-3 pt-0">
            <div class="list-group list-group-flush border-0" id="dept-list">
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-9" id="main-content">
        <h4 class="fw-bold text-dark mb-4" id="current-dept-name">Loading Dashboard...</h4>

        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="border-bottom: 4px solid #3b82f6 !important;">
              <div class="card-body p-4">
                <div class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 1px;">
                  Total Allocated</div>
                <div class="display-6 fw-bold text-dark mb-0" id="total-allocated">₱0.00</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="border-bottom: 4px solid #f59e0b !important;">
              <div class="card-body p-4">
                <div class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 1px;">
                  Total Used</div>
                <div class="display-6 fw-bold text-warning mb-0" id="total-used">₱0.00</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="border-bottom: 4px solid #10b981 !important;">
              <div class="card-body p-4">
                <div class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 1px;">
                  Remaining Balance</div>
                <div class="display-6 fw-bold text-success mb-0" id="remaining-balance">₱0.00</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Fund Utilization</span>
              <span class="fw-bold text-dark" id="fund-percentage">0%</span>
            </div>
            <div class="progress rounded-pill bg-light" style="height: 12px; border: 1px solid rgba(0,0,0,0.05);">
              <div id="fund-progress" class="progress-bar rounded-pill bg-primary transition-base" role="progressbar"
                style="width: 0%;"></div>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 mt-5">
          <h5 class="fw-bold text-dark m-0">Transaction History</h5>
        </div>

        <div id="transactions-list">
          <div class="text-center py-5">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <p class="text-muted">Loading records...</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="allocateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-bottom-0 p-4 pb-3">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-wallet2 text-primary me-2"></i>Allocate Funds</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="allocateForm" onsubmit="event.preventDefault(); submitAllocation();">
          <div class="modal-body p-4 pt-0 d-flex flex-column gap-3">
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Department</label>
              <select class="form-select bg-light border-0 rounded-3 p-2 px-3" id="alloc-dept" required>
                <option value="">Select Department</option>
              </select>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Amount (₱)</label>
              <input type="number" step="0.01" min="0"
                class="form-control bg-light border-0 rounded-3 p-2 px-3 fw-bold text-success" id="alloc-amount"
                placeholder="0.00" required>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Notes (Optional)</label>
              <textarea class="form-control bg-light border-0 rounded-3 p-2 px-3" id="alloc-notes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer border-top p-3 px-4">
            <button type="button" class="btn btn-light rounded-pill px-4 me-auto"
              data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">Save Allocation</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="addDeductModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-bottom-0 p-4 pb-3">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-calculator text-primary me-2"></i>Add / Deduct Funds
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="addDeductForm" onsubmit="event.preventDefault(); submitAddDeduct();">
          <div class="modal-body p-4 pt-0 d-flex flex-column gap-3">
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Operation Type</label>
              <div class="d-flex gap-3">
                <div class="form-check flex-1 bg-light p-2 px-3 rounded-3 border">
                  <input class="form-check-input ms-0 me-2" type="radio" name="operationType" id="opAdd" value="add"
                    checked>
                  <label class="form-check-label fw-bold text-success" for="opAdd">Add Funds</label>
                </div>
                <div class="form-check flex-1 bg-light p-2 px-3 rounded-3 border">
                  <input class="form-check-input ms-0 me-2" type="radio" name="operationType" id="opDeduct"
                    value="deduct">
                  <label class="form-check-label fw-bold text-danger" for="opDeduct">Deduct Funds</label>
                </div>
              </div>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Amount (₱)</label>
              <input type="number" step="0.01" min="0.01"
                class="form-control bg-light border-0 rounded-3 p-2 px-3 fw-bold" id="ad-amount" placeholder="0.00"
                required>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Description</label>
              <input type="text" class="form-control bg-light border-0 rounded-3 p-2 px-3" id="ad-desc"
                placeholder="Reason for adjustment" required>
            </div>
          </div>
          <div class="modal-footer border-top p-3 px-4">
            <button type="button" class="btn btn-light rounded-pill px-4 me-auto"
              data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-bottom-0 p-4 pb-3">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit SAF
            Information</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="editForm" onsubmit="event.preventDefault(); submitEditSaf();">
          <input type="hidden" id="edit-saf-id">
          <div class="modal-body p-4 pt-0 d-flex flex-column gap-3">
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Total Allocated (₱)</label>
              <input type="number" step="0.01" min="0"
                class="form-control bg-light border-0 rounded-3 p-2 px-3 fw-bold text-primary" id="edit-alloc-amount"
                required>
              <small class="text-muted text-xs mt-1 d-block">Warning: Adjusting this changes the base allocation for the
                entire department.</small>
            </div>
          </div>
          <div class="modal-footer border-top p-3 px-4">
            <button type="button" class="btn btn-light rounded-pill px-4 me-auto"
              data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-danger-subtle border-bottom-0 p-4 pb-3">
          <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Reset
            Department Data</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 pt-3">
          <p class="text-dark mb-0">Are you sure you want to completely reset the data for <strong id="reset-dept-name"
              class="text-danger"></strong>?</p>
          <div class="bg-light border rounded-3 p-3 mt-3">
            <p class="text-danger text-sm mb-0"><strong><i class="bi bi-info-circle me-1"></i> This action cannot be
                undone.</strong> All fund allocation data and transaction records for this department will be
              permanently deleted.</p>
          </div>
        </div>
        <div class="modal-footer border-top-0 p-3 px-4 pt-0">
          <button type="button" class="btn btn-light rounded-pill px-4 me-auto" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger rounded-pill px-4 shadow-sm" onclick="confirmReset()">Yes, Reset
            Data</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="transactionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-bottom-0 p-4 pb-3">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-receipt text-primary me-2"></i>Transaction Details
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="transactionForm" onsubmit="event.preventDefault(); submitTransaction();">
          <input type="hidden" id="trans-id">
          <div class="modal-body p-4 pt-0 d-flex flex-column gap-3">
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Type</label>
              <select class="form-select bg-light border-0 rounded-3 p-2 px-3" id="trans-type" required>
                <option value="expense">Expense</option>
                <option value="allocation">Allocation Addition</option>
                <option value="deduction">Fund Deduction</option>
              </select>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Amount (₱)</label>
              <input type="number" step="0.01" min="0" class="form-control bg-light border-0 rounded-3 p-2 px-3 fw-bold"
                id="trans-amount" required>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Document Reference</label>
              <input type="text" class="form-control bg-light border-0 rounded-3 p-2 px-3" id="trans-doc-ref">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-muted small fw-bold text-uppercase">Description</label>
              <textarea class="form-control bg-light border-0 rounded-3 p-2 px-3" id="trans-desc" rows="2"
                required></textarea>
            </div>
          </div>
          <div class="modal-footer border-top p-3 px-4">
            <button type="button" class="btn btn-light rounded-pill px-4 me-auto"
              data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">Save Transaction</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/saf.js"></script>
</body>

</html>