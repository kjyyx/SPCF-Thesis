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
  <script src="/_sdk/element_sdk.js"></script>
  <script src="/_sdk/data_sdk.js"></script>
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
     <div class="col-lg-8 mx-auto"><!-- Department Header -->
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
        <div class="d-flex flex-wrap gap-2"><button class="btn btn-success" onclick="showEditModal('add')"> <i class="bi bi-plus-circle me-1"></i>Add Funds </button> <button class="btn btn-danger" onclick="showEditModal('deduct')"> <i class="bi bi-dash-circle me-1"></i>Deduct Funds </button> <button class="btn btn-primary" onclick="showEditModal('set')"> <i class="bi bi-pencil-square me-1"></i>Set Initial Amount </button> <button class="btn btn-outline-danger" onclick="showDeleteConfirm()"> <i class="bi bi-arrow-clockwise me-1"></i>Reset Department </button>
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
  <div class="modal fade" id="deleteModal" tabindex="-1">
   <div class="modal-dialog modal-dialog-centered">
    <div class="modal-body text-center p-4">
     <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 4rem;"></i>
     </div>
     <h4 class="fw-bold mb-3">Reset Department?</h4>
     <p class="text-muted">This will reset all SAF data and transaction history for this department. This action cannot be undone.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button> <button type="button" class="btn btn-danger" onclick="confirmDelete()"> <i class="bi bi-arrow-clockwise me-1"></i>Reset </button>
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
  <script>
    // Departments data
    const DEPARTMENTS = [
      { id: 'casse', name: 'College of Arts and Social Sciences and Education', short: 'CASSE' },
      { id: 'ccis', name: 'College of Computing and Information Sciences', short: 'CCIS' },
      { id: 'chtm', name: 'College of Hospitality and Tourism Management', short: 'CHTM' },
      { id: 'cob', name: 'College of Business', short: 'COB' },
      { id: 'coc', name: 'College of Criminology', short: 'COC' },
      { id: 'coe', name: 'College of Engineering', short: 'COE' },
      { id: 'con', name: 'College of Nursing', short: 'CON' },
      { id: 'miranda', name: 'SPCF Miranda', short: 'Miranda' }
    ];

    // State
    let currentLevel = null;
    let currentDeptId = null;
    let allData = [];
    let isLoading = false;
    let editModal, deleteModal, toastInstance;

    // Config
    const defaultConfig = {
      system_title: 'Student Allocated Funds',
      currency_symbol: '₱',
      primary_color: '#667eea',
      secondary_color: '#764ba2',
      success_color: '#10b981',
      danger_color: '#ef4444',
      text_color: '#1f2937'
    };

    // Initialize Element SDK
    if (window.elementSdk) {
      window.elementSdk.init({
        defaultConfig,
        onConfigChange: async (config) => {
          const title = config.system_title || defaultConfig.system_title;
          const titleElements = document.querySelectorAll('#login-title, #dashboard-title');
          titleElements.forEach(el => el.textContent = title);
        },
        mapToCapabilities: (config) => ({
          recolorables: [
            {
              get: () => config.primary_color || defaultConfig.primary_color,
              set: (v) => { config.primary_color = v; window.elementSdk.setConfig({ primary_color: v }); }
            },
            {
              get: () => config.secondary_color || defaultConfig.secondary_color,
              set: (v) => { config.secondary_color = v; window.elementSdk.setConfig({ secondary_color: v }); }
            },
            {
              get: () => config.success_color || defaultConfig.success_color,
              set: (v) => { config.success_color = v; window.elementSdk.setConfig({ success_color: v }); }
            },
            {
              get: () => config.danger_color || defaultConfig.danger_color,
              set: (v) => { config.danger_color = v; window.elementSdk.setConfig({ danger_color: v }); }
            },
            {
              get: () => config.text_color || defaultConfig.text_color,
              set: (v) => { config.text_color = v; window.elementSdk.setConfig({ text_color: v }); }
            }
          ],
          borderables: [],
          fontEditable: undefined,
          fontSizeable: undefined
        }),
        mapToEditPanelValues: (config) => new Map([
          ['system_title', config.system_title || defaultConfig.system_title],
          ['currency_symbol', config.currency_symbol || defaultConfig.currency_symbol]
        ])
      });
    }

    // Initialize Bootstrap components
    document.addEventListener('DOMContentLoaded', function() {
      editModal = new bootstrap.Modal(document.getElementById('editModal'));
      deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
      toastInstance = new bootstrap.Toast(document.getElementById('toast'));

      // Set level and dept based on user
      if (window.currentUser.role === 'student') {
        currentLevel = 'level1';
        currentDeptId = window.currentUser.department; // Assume department matches IDs
      } else if (window.currentUser.role === 'employee') {
        if (window.currentUser.position && window.currentUser.position.includes('Accounting')) {
          currentLevel = 'level3';
        } else if (window.currentUser.position && window.currentUser.position.includes('OSA')) {
          currentLevel = 'level2';
        }
      }

      initDataSdk();

      // Show initial view
      if (currentLevel === 'level1' && currentDeptId) {
        showSingleDept(currentDeptId);
      } else if (currentLevel === 'level2' || currentLevel === 'level3') {
        showAllDepts();
      }
    });

    // Data handler
    const dataHandler = {
      onDataChanged(data) {
        allData = data;
        renderCurrentView();
      }
    };

    // Initialize Data SDK
    async function initDataSdk() {
      if (window.dataSdk) {
        const result = await window.dataSdk.init(dataHandler);
        if (!result.isOk) {
          showToast('Failed to initialize data', 'danger');
        }
      }
    }

    // Utility functions
    function getCurrencySymbol() {
      return window.elementSdk?.config?.currency_symbol || defaultConfig.currency_symbol;
    }

    function formatCurrency(amount) {
      return `${getCurrencySymbol()}${(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function showToast(message, type = 'success') {
      const toast = document.getElementById('toast');
      const toastMsg = document.getElementById('toast-message');
      toastMsg.textContent = message;
      
      toast.className = `toast align-items-center border-0 ${type === 'danger' ? 'bg-danger' : 'bg-success'} text-white`;
      toastInstance.show();
    }

    function showLoading() {
      document.getElementById('loading-overlay').style.display = 'flex';
      isLoading = true;
    }

    function hideLoading() {
      document.getElementById('loading-overlay').style.display = 'none';
      isLoading = false;
    }

    // Logout function - redirect to system logout
    function logout() {
      window.location.href = 'user-logout.php';
    }

    function goBack() {
      if (currentDeptId && (currentLevel === 'level2' || currentLevel === 'level3')) {
        currentDeptId = null;
        showAllDepts();
      } else {
        logout();
      }
    }

    // Get department SAF data
    function getDeptSAF(deptId) {
      const safRecord = allData.find(d => d.type === 'saf' && d.department_id === deptId);
      return safRecord || { initial_amount: 0, used_amount: 0 };
    }

    function getDeptTransactions(deptId) {
      return allData
        .filter(d => d.type === 'transaction' && d.department_id === deptId)
        .sort((a, b) => new Date(b.transaction_date) - new Date(a.transaction_date));
    }

    // View functions
    function showAllDepts() {
      document.getElementById('all-depts-view').style.display = 'block';
      document.getElementById('single-dept-view').style.display = 'none';
      renderDeptCards();
    }

    function showSingleDept(deptId) {
      currentDeptId = deptId;
      document.getElementById('all-depts-view').style.display = 'none';
      document.getElementById('single-dept-view').style.display = 'block';
      
      // Show/hide level 3 controls
      document.getElementById('level3-controls').style.display = currentLevel === 'level3' ? 'block' : 'none';
      
      renderDeptDetails(deptId);
    }

    // Render functions
    function renderCurrentView() {
      if (currentDeptId) {
        renderDeptDetails(currentDeptId);
      } else if (currentLevel === 'level2' || currentLevel === 'level3') {
        renderDeptCards();
      }
    }

    function renderDeptCards() {
      const container = document.getElementById('dept-cards');
      let totalBalance = 0;

      const cardsHtml = DEPARTMENTS.map(dept => {
        const saf = getDeptSAF(dept.id);
        const initial = saf.initial_amount || 0;
        const used = saf.used_amount || 0;
        const current = initial - used;
        totalBalance += current;
        const percentage = initial > 0 ? ((current / initial) * 100).toFixed(0) : 0;
        
        return `
          <div class="col-md-6 col-lg-4 col-xl-3">
            <div class="card dept-card h-100" onclick="showSingleDept('${dept.id}')">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                  <span class="badge bg-primary badge-dept">${dept.short}</span>
                  <small class="text-muted fw-semibold">${percentage}%</small>
                </div>
                <h6 class="card-title mb-3" style="min-height: 48px;">${dept.name}</h6>
                <div class="mb-2">
                  <div class="d-flex justify-content-between text-sm mb-1">
                    <small class="text-muted">Initial</small>
                    <small class="text-primary fw-semibold">${formatCurrency(initial)}</small>
                  </div>
                  <div class="d-flex justify-content-between text-sm mb-1">
                    <small class="text-muted">Used</small>
                    <small class="text-danger fw-semibold">${formatCurrency(used)}</small>
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between">
                    <small class="fw-semibold">Balance</small>
                    <small class="text-success fw-bold">${formatCurrency(current)}</small>
                  </div>
                </div>
                <div class="progress progress-custom">
                  <div class="progress-bar bg-success" role="progressbar" 
                       style="width: ${Math.max(0, Math.min(100, percentage))}%"></div>
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');

      container.innerHTML = cardsHtml;
      document.getElementById('total-saf').textContent = formatCurrency(totalBalance);
    }

    function renderDeptDetails(deptId) {
      const dept = DEPARTMENTS.find(d => d.id === deptId);
      const saf = getDeptSAF(deptId);
      const transactions = getDeptTransactions(deptId);
      
      const initial = saf.initial_amount || 0;
      const used = saf.used_amount || 0;
      const current = initial - used;

      document.getElementById('dept-name').textContent = dept.name;
      document.getElementById('initial-amount').textContent = formatCurrency(initial);
      document.getElementById('used-amount').textContent = formatCurrency(used);
      document.getElementById('current-amount').textContent = formatCurrency(current);

      const listContainer = document.getElementById('transaction-list');
      
      if (transactions.length === 0) {
        listContainer.innerHTML = `
          <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3;"></i>
            <p class="mt-3">No transactions yet</p>
          </div>
        `;
        return;
      }

      const transactionsHtml = transactions.map(t => {
        const isDeduction = t.transaction_type === 'deduct';
        const isSet = t.transaction_type === 'set';
        const date = new Date(t.transaction_date);
        const formattedDate = date.toLocaleDateString('en-US', { 
          month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' 
        });
        
        let iconClass = 'bi-arrow-up-circle text-success';
        let amountClass = 'text-success';
        let prefix = '+';
        
        if (isSet) {
          iconClass = 'bi-pencil-square text-primary';
          amountClass = 'text-primary';
          prefix = '';
        } else if (isDeduction) {
          iconClass = 'bi-arrow-down-circle text-danger';
          amountClass = 'text-danger';
          prefix = '-';
        }
        
        return `
          <div class="list-group-item transaction-item border-0 border-bottom">
            <div class="d-flex align-items-center">
              <div class="me-3">
                <i class="bi ${iconClass}" style="font-size: 1.5rem;"></i>
              </div>
              <div class="flex-grow-1">
                <h6 class="mb-1">${t.transaction_description || 'No description'}</h6>
                <small class="text-muted">${formattedDate}</small>
              </div>
              <div class="text-end">
                <h6 class="mb-0 ${amountClass} fw-bold">${prefix}${formatCurrency(t.transaction_amount)}</h6>
              </div>
            </div>
          </div>
        `;
      }).join('');

      listContainer.innerHTML = transactionsHtml;
    }

    // Modal functions
    function showEditModal(type) {
      const titleEl = document.getElementById('modal-title');
      const submitBtn = document.getElementById('modal-submit-btn');
      
      document.getElementById('edit-type').value = type;
      document.getElementById('edit-amount').value = '';
      document.getElementById('edit-description').value = '';
      
      switch(type) {
        case 'add':
          titleEl.textContent = 'Add Funds';
          submitBtn.textContent = 'Add Funds';
          submitBtn.className = 'btn btn-success';
          break;
        case 'deduct':
          titleEl.textContent = 'Deduct Funds';
          submitBtn.textContent = 'Deduct Funds';
          submitBtn.className = 'btn btn-danger';
          break;
        case 'set':
          titleEl.textContent = 'Set Initial Amount';
          submitBtn.textContent = 'Set Amount';
          submitBtn.className = 'btn btn-primary';
          break;
      }
      
      editModal.show();
    }

    function showDeleteConfirm() {
      deleteModal.show();
    }

    async function handleEditSubmit(e) {
      e.preventDefault();
      if (isLoading || !currentDeptId) return;

      const type = document.getElementById('edit-type').value;
      const amount = parseFloat(document.getElementById('edit-amount').value);
      const description = document.getElementById('edit-description').value.trim();

      if (isNaN(amount) || amount <= 0) {
        showToast('Please enter a valid amount', 'danger');
        return;
      }

      showLoading();
      editModal.hide();

      try {
        const existingSAF = allData.find(d => d.type === 'saf' && d.department_id === currentDeptId);
        
        if (type === 'set') {
          if (existingSAF) {
            const result = await window.dataSdk.update({
              ...existingSAF,
              initial_amount: amount,
              used_amount: 0
            });
            if (!result.isOk) throw new Error('Failed to update SAF');
          } else {
            if (allData.length >= 999) {
              showToast('Maximum records reached (999)', 'danger');
              hideLoading();
              return;
            }
            const result = await window.dataSdk.create({
              type: 'saf',
              department_id: currentDeptId,
              initial_amount: amount,
              used_amount: 0
            });
            if (!result.isOk) throw new Error('Failed to create SAF');
          }

          if (allData.length < 999) {
            await window.dataSdk.create({
              type: 'transaction',
              department_id: currentDeptId,
              transaction_type: 'set',
              transaction_amount: amount,
              transaction_description: description || 'Initial amount set',
              transaction_date: new Date().toISOString()
            });
          }
          showToast('Initial amount set successfully');
        } else if (type === 'add') {
          const currentInitial = existingSAF?.initial_amount || 0;

          if (existingSAF) {
            const result = await window.dataSdk.update({
              ...existingSAF,
              initial_amount: currentInitial + amount
            });
            if (!result.isOk) throw new Error('Failed to update SAF');
          } else {
            if (allData.length >= 999) {
              showToast('Maximum records reached (999)', 'danger');
              hideLoading();
              return;
            }
            const result = await window.dataSdk.create({
              type: 'saf',
              department_id: currentDeptId,
              initial_amount: amount,
              used_amount: 0
            });
            if (!result.isOk) throw new Error('Failed to create SAF');
          }

          if (allData.length < 999) {
            await window.dataSdk.create({
              type: 'transaction',
              department_id: currentDeptId,
              transaction_type: 'add',
              transaction_amount: amount,
              transaction_description: description || 'Funds added',
              transaction_date: new Date().toISOString()
            });
          }
          showToast('Funds added successfully');
        } else if (type === 'deduct') {
          const currentInitial = existingSAF?.initial_amount || 0;
          const currentUsed = existingSAF?.used_amount || 0;
          const currentBalance = currentInitial - currentUsed;

          if (amount > currentBalance) {
            showToast('Insufficient funds', 'danger');
            hideLoading();
            return;
          }

          if (existingSAF) {
            const result = await window.dataSdk.update({
              ...existingSAF,
              used_amount: currentUsed + amount
            });
            if (!result.isOk) throw new Error('Failed to update SAF');
          } else {
            showToast('No SAF record found', 'danger');
            hideLoading();
            return;
          }

          if (allData.length < 999) {
            await window.dataSdk.create({
              type: 'transaction',
              department_id: currentDeptId,
              transaction_type: 'deduct',
              transaction_amount: amount,
              transaction_description: description || 'Funds deducted',
              transaction_date: new Date().toISOString()
            });
          }
          showToast('Funds deducted successfully');
        }
      } catch (error) {
        showToast('Operation failed. Please try again.', 'danger');
      }

      hideLoading();
    }

    async function confirmDelete() {
      if (isLoading || !currentDeptId) return;

      showLoading();
      deleteModal.hide();

      try {
        const safRecord = allData.find(d => d.type === 'saf' && d.department_id === currentDeptId);
        if (safRecord) {
          const result = await window.dataSdk.delete(safRecord);
          if (!result.isOk) throw new Error('Failed to delete SAF');
        }

        const transactions = allData.filter(d => d.type === 'transaction' && d.department_id === currentDeptId);
        for (const t of transactions) {
          await window.dataSdk.delete(t);
        }

        showToast('Department SAF reset successfully');
      } catch (error) {
        showToast('Reset failed. Please try again.', 'danger');
      }

      hideLoading();
    }
  </script>
 </body>
</html>