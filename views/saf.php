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

// Access control: Only students, or employees with specific positions
$hasAccess = $currentUser['role'] === 'student' ||
            ($currentUser['role'] === 'employee' && 
             (stripos($currentUser['position'], 'Accounting') !== false || 
              stripos($currentUser['position'], 'OSA') !== false));

if (!$hasAccess) {
    header('Location: user-login.php?error=access_denied');
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
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'],
            $severity
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

addAuditLog('SAF_VIEWED', 'SAF Management', 'Accessed Student Allocated Funds page', null, 'Page', 'INFO');

// Set page title
$pageTitle = 'Student Allocated Funds';
?>

<!DOCTYPE html>
<html lang="en" style="height: 100%;">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <link href="../assets/css/global.css" rel="stylesheet"> <!-- Your global styles -->
  <link rel="stylesheet" href="../assets/css/global-notifications.css"><!-- Global notifications styles -->
  <script src="../assets/js/global-notifications.js"></script>
  <script src="../assets/js/toast.js"></script>

  <!-- Pass user data -->
  <script>
      window.currentUser = <?php echo json_encode([
          'id' => $currentUser['id'],
          'firstName' => $currentUser['first_name'],
          'lastName' => $currentUser['last_name'],
          'role' => $currentUser['role'],
          'position' => $currentUser['position'] ?? '',
          'department' => $currentUser['department'] ?? ''
      ]); ?>;
  </script>

  <style>
    body {
      box-sizing: border-box;
      font-family: 'Inter', sans-serif;
      height: 100%;
      overflow-y: auto;
      padding-top: 70px; /* Adjust for fixed navbar height */
    }
    
    .main-wrapper {
      min-height: 100%;
      width: 100%;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .login-card {
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, 0.95);
      border: none;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }
    
    .dashboard-wrapper {
      min-height: calc(100vh - 70px); /* Adjust for navbar */
      width: 100%;
      background: #f8f9fa;
      position: relative; /* Changed from fixed to relative */
    }
    
    .dept-card {
      transition: all 0.3s ease;
      cursor: pointer;
      border: 2px solid transparent;
    }
    
    .dept-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
      border-color: #667eea;
    }
    
    .stat-card {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
    }
    
    .transaction-item {
      transition: background-color 0.2s;
    }
    
    .transaction-item:hover {
      background-color: #f8f9fa;
    }
    
    .badge-dept {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.35rem 0.65rem;
    }
    
    .progress-custom {
      height: 8px;
      border-radius: 10px;
    }
    
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }
    
    .btn-gradient {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border: none;
      color: white;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .btn-gradient:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
      color: white;
    }
    
    .form-control:focus, .form-select:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
    }
    
    .navbar-custom {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .spinner-wrapper {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }
  </style>
  <style>@view-transition { navigation: auto; }</style>
  <script src="https://cdn.tailwindcss.com" type="text/javascript"></script>
 </head>
 <body style="height: 100%;"><!-- Dashboard Screen -->
  <div id="dashboard-screen" class="dashboard-wrapper">
   <?php include '../includes/navbar.php'; ?>
   <?php include '../includes/notifications.php'; ?>
   <!-- All Departments View -->
   <div id="all-depts-view" class="container-fluid p-4" style="display: none;">
    <div class="row mb-4">
     <div class="col-md-8">
      <h3 class="fw-bold">All Departments</h3>
      <p class="text-muted">Overview of Student Allocated Funds across departments</p>
     </div>
     <div class="col-md-4">
      <div class="card stat-card">
       <div class="card-body text-center">
        <h6 class="text-white-50 mb-1">Total SAF Balance</h6>
        <h2 id="total-saf" class="fw-bold mb-0">₱0.00</h2>
       </div>
      </div>
     </div>
    </div>
    <div id="dept-cards" class="row g-4"></div>
   </div><!-- Single Department View -->
   <div id="single-dept-view" class="container-fluid p-4" style="display: none;">
    <div class="row">
     <div class="col-lg-8 mx-auto">
      <!-- Back Button -->
      <div class="mb-3">
       <button class="btn btn-outline-secondary" onclick="goBack()">
        <i class="bi bi-arrow-left me-2"></i>Back to Departments
       </button>
      </div><!-- Department Header -->
      <div class="card shadow-sm mb-4">
       <div class="card-header bg-white border-0 pt-4">
        <h3 id="dept-name" class="fw-bold mb-0"></h3>
       </div>
       <div class="card-body">
        <div class="row g-3">
         <div class="col-md-4">
          <div class="p-3 bg-primary bg-opacity-10 rounded">
           <div class="d-flex align-items-center mb-2"><i class="bi bi-wallet2 text-primary me-2"></i> <small class="text-muted fw-semibold">Initial Amount</small>
           </div>
           <h4 id="initial-amount" class="fw-bold text-primary mb-0">₱0.00</h4>
          </div>
         </div>
         <div class="col-md-4">
          <div class="p-3 bg-danger bg-opacity-10 rounded">
           <div class="d-flex align-items-center mb-2"><i class="bi bi-arrow-down-circle text-danger me-2"></i> <small class="text-muted fw-semibold">Used Amount</small>
           </div>
           <h4 id="used-amount" class="fw-bold text-danger mb-0">₱0.00</h4>
          </div>
         </div>
         <div class="col-md-4">
          <div class="p-3 bg-success bg-opacity-10 rounded">
           <div class="d-flex align-items-center mb-2"><i class="bi bi-check-circle text-success me-2"></i> <small class="text-muted fw-semibold">Current Balance</small>
           </div>
           <h4 id="current-amount" class="fw-bold text-success mb-0">₱0.00</h4>
          </div>
         </div>
        </div>
       </div>
      </div><!-- Level 3 Controls -->
      <div id="level3-controls" class="card shadow-sm mb-4" style="display: none;">
       <div class="card-body">
        <h5 class="card-title mb-3"><i class="bi bi-gear me-2"></i>Manage SAF</h5>
        <div class="d-flex flex-wrap gap-2"><button class="btn btn-success" onclick="showEditModal('add')"> <i class="bi bi-plus-circle me-1"></i>Add Funds </button> <button class="btn btn-danger" onclick="showEditModal('deduct')"> <i class="bi bi-dash-circle me-1"></i>Deduct Funds </button> <button class="btn btn-primary" onclick="showEditModal('set')"> <i class="bi bi-pencil-square me-1"></i>Set Initial Amount </button> <button class="btn btn-outline-danger" onclick="showResetConfirm()" title="Reset all SAF data and transaction history"> <i class="bi bi-arrow-counterclockwise me-1"></i>Reset SAF Data </button>
        </div>
       </div>
      </div><!-- Transaction History -->
      <div class="card shadow-sm">
       <div class="card-header bg-white border-0">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Transaction History</h5>
       </div>
       <div class="card-body p-0">
        <div id="transaction-list" class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
         <div class="text-center py-5 text-muted"><i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3;"></i>
          <p class="mt-3">No transactions yet</p>
         </div>
        </div>
       </div>
      </div>
     </div>
    </div>
   </div>
  </div><!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1">
   <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
     <div class="modal-header">
      <h5 class="modal-title" id="modal-title">Edit SAF</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
     </div>
     <form id="edit-form" onsubmit="handleEditSubmit(event)">
      <div class="modal-body"><input type="hidden" id="edit-type">
       <div class="mb-3"><label for="edit-amount" class="form-label fw-semibold">Amount (₱)</label> <input type="number" class="form-control form-control-lg" id="edit-amount" min="0" step="0.01" required placeholder="0.00">
       </div>
       <div class="mb-3"><label for="edit-description" class="form-label fw-semibold">Description</label> <input type="text" class="form-control" id="edit-description" required placeholder="Enter transaction description">
       </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button> <button type="submit" id="modal-submit-btn" class="btn btn-primary">Confirm</button>
      </div>
     </form>
    </div>
   </div>
  </div><!-- Delete Confirm Modal -->
  <div class="modal fade" id="resetModal" tabindex="-1">
   <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-danger">
     <div class="modal-header bg-danger text-white">
      <h5 class="modal-title">
       <i class="bi bi-exclamation-triangle-fill me-2"></i>
       Reset SAF Data
      </h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
     </div>
     <div class="modal-body text-center p-4">
      <div class="mb-4">
       <i class="bi bi-cash-coin text-danger" style="font-size: 4rem;"></i>
      </div>
      <h4 class="fw-bold mb-3 text-danger">Reset All SAF Funds & History?</h4>
      <div class="alert alert-warning text-start">
       <strong>What will be reset:</strong>
       <ul class="mb-0 mt-2">
        <li>Initial allocated amount → ₱0.00</li>
        <li>Used amount → ₱0.00</li>
        <li>All transaction history → Cleared</li>
       </ul>
      </div>
      <p class="text-muted mb-0"><strong>This action cannot be undone.</strong> All fund allocation data and transaction records for this department will be permanently deleted.</p>
     </div>
     <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
       <i class="bi bi-x-circle me-1"></i>Cancel
      </button>
      <button type="button" class="btn btn-danger" onclick="confirmReset()">
       <i class="bi bi-arrow-counterclockwise me-1"></i>
       Yes, Reset SAF Data
      </button>
     </div>
    </div>
   </div>
  </div><!-- Loading Spinner -->
  <div id="loading-overlay" class="spinner-wrapper" style="display: none;">
   <div class="spinner-border text-light" role="status" style="width: 3rem; height: 3rem;"><span class="visually-hidden">Loading...</span>
   </div>
  </div><!-- Toast Container -->
  <div class="toast-container position-fixed bottom-0 end-0 p-3">
   <div id="toast" class="toast align-items-center border-0" role="alert">
    <div class="d-flex">
     <div class="toast-body fw-semibold" id="toast-message"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
   </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/js/saf.js"></script>
 </body>
</html>