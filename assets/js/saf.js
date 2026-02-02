// saf.js - Student Allocated Funds JavaScript
// Departments data with mapping
const DEPARTMENTS = [
  { id: 'casse', name: 'College of Arts and Social Sciences and Education', short: 'CASSE', db_id: 'casse' },
  { id: 'ccis', name: 'College of Computing and Information Sciences', short: 'CCIS', db_id: 'ccis' },
  { id: 'chtm', name: 'College of Hospitality and Tourism Management', short: 'CHTM', db_id: 'chtm' },
  { id: 'cob', name: 'College of Business', short: 'COB', db_id: 'cob' },
  { id: 'coc', name: 'College of Criminology', short: 'COC', db_id: 'coc' },
  { id: 'coe', name: 'College of Engineering', short: 'COE', db_id: 'coe' },
  { id: 'con', name: 'College of Nursing', short: 'CON', db_id: 'con' },
  { id: 'miranda', name: 'SPCF Miranda', short: 'Miranda', db_id: 'miranda' },
  { id: 'ssc', name: 'Supreme Student Council', short: 'SSC', db_id: 'ssc' }
];

// Department mapping
const deptMap = {};
const deptNameToIdMap = {};
DEPARTMENTS.forEach(dept => {
  deptMap[dept.db_id] = dept.id;
  deptNameToIdMap[dept.name.trim().toLowerCase()] = dept.id;
});

// State
let currentLevel = null;
let currentDeptId = null;
let allData = [];
let isLoading = false;
let editModal, resetModal, toastInstance;

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
  resetModal = new bootstrap.Modal(document.getElementById('resetModal'));
  toastInstance = new bootstrap.Toast(document.getElementById('toast'));

  // Set level and dept based on user
  if (window.currentUser.role === 'student') {
    currentLevel = 'level1';
    const originalDept = window.currentUser.department;
    currentDeptId = deptNameToIdMap[originalDept.trim().toLowerCase()] || originalDept;
    console.log('DEBUG: Student department mapping:', { originalDept, currentDeptId, level: currentLevel });
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

// Data handler - not needed since we use fetch
// const dataHandler = {
//   onDataChanged(data) {
//     allData = data;
//     renderCurrentView();
//   }
// };

// Initialize Data SDK
async function initDataSdk() {
  try {
    const response = await fetch('../api/saf.php', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
    });
    const result = await response.json();
    if (result.success) {
      allData = result.data;
      renderCurrentView();
    } else {
      showToast('Failed to load SAF data', 'danger');
    }
  } catch (error) {
    showToast('Failed to initialize SAF data', 'danger');
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
    const saf = getDeptSAF(dept.db_id);
    const initial = saf.initial_amount || 0;
    const used = saf.used_amount || 0;
    const current = initial - used;
    totalBalance += current;
    const percentage = initial > 0 ? ((current / initial) * 100).toFixed(0) : 0;

    return `
      <div class="col-md-6 col-lg-4 col-xl-3">
        <div class="card dept-card h-100" onclick="showSingleDept('${dept.db_id}')">
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
  console.log('DEBUG: renderDeptDetails called with deptId:', deptId);
  // Find department by db_id first
  let dept = DEPARTMENTS.find(d => d.db_id === deptId);
  console.log('DEBUG: Found department:', dept);

  // If department not found in predefined list, create a fallback
  if (!dept) {
    dept = {
      id: deptId,
      name: deptId, // Use deptId as name if not found
      short: deptId.length > 10 ? deptId.substring(0, 10) + '...' : deptId
    };
  }

  const saf = getDeptSAF(dept.id);
  const transactions = getDeptTransactions(dept.id);
  console.log('DEBUG: SAF data:', saf);
  console.log('DEBUG: Transactions:', transactions);

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
  resetModal.show();
}

function showResetConfirm() {
  resetModal.show();
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
        const response = await fetch('../api/saf.php', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            id: existingSAF.id,
            type: 'saf',
            initial_amount: amount,
            used_amount: 0
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error('Failed to update SAF');
      } else {
        const response = await fetch('../api/saf.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            type: 'saf',
            department_id: currentDeptId,
            initial_amount: amount,
            used_amount: 0
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error('Failed to create SAF');
      }

      const transResponse = await fetch('../api/saf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          type: 'transaction',
          department_id: currentDeptId,
          transaction_type: 'set',
          transaction_amount: amount,
          transaction_description: description || 'Initial amount set',
          transaction_date: new Date().toISOString()
        })
      });
      const transResult = await transResponse.json();
      if (!transResult.success) throw new Error('Failed to create transaction');

      showToast('Initial amount set successfully');
    } else if (type === 'add') {
      const currentInitial = existingSAF?.initial_amount || 0;

      if (existingSAF) {
        const response = await fetch('../api/saf.php', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            id: existingSAF.id,
            type: 'saf',
            initial_amount: currentInitial + amount,
            used_amount: existingSAF.used_amount
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error('Failed to update SAF');
      } else {
        const response = await fetch('../api/saf.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            type: 'saf',
            department_id: currentDeptId,
            initial_amount: amount,
            used_amount: 0
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error('Failed to create SAF');
      }

      const transResponse = await fetch('../api/saf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          type: 'transaction',
          department_id: currentDeptId,
          transaction_type: 'add',
          transaction_amount: amount,
          transaction_description: description || 'Funds added',
          transaction_date: new Date().toISOString()
        })
      });
      const transResult = await transResponse.json();
      if (!transResult.success) throw new Error('Failed to create transaction');

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
        const response = await fetch('../api/saf.php', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            id: existingSAF.id,
            type: 'saf',
            initial_amount: currentInitial,
            used_amount: currentUsed + amount
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error('Failed to update SAF');
      } else {
        showToast('No SAF record found', 'danger');
        hideLoading();
        return;
      }

      const transResponse = await fetch('../api/saf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          type: 'transaction',
          department_id: currentDeptId,
          transaction_type: 'deduct',
          transaction_amount: amount,
          transaction_description: description || 'Funds deducted',
          transaction_date: new Date().toISOString()
        })
      });
      const transResult = await transResponse.json();
      if (!transResult.success) throw new Error('Failed to create transaction');

      showToast('Funds deducted successfully');
    }

    // Reload data
    await initDataSdk();
  } catch (error) {
    showToast('Operation failed. Please try again.', 'danger');
  }

  hideLoading();
}

async function confirmDelete() {
  if (isLoading || !currentDeptId) return;

  showLoading();
  resetModal.hide();

  try {
    // Handle document deletion logic here
    showToast('Document deleted successfully');
  } catch (error) {
    showToast('Delete failed. Please try again.', 'danger');
  }

  hideLoading();
}

async function confirmReset() {
  if (isLoading || !currentDeptId) return;

  showLoading();
  resetModal.hide();

  try {
    const safRecord = allData.find(d => d.type === 'saf' && d.department_id === currentDeptId);
    if (safRecord) {
      const response = await fetch('../api/saf.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          id: safRecord.id,
          type: 'saf'
        })
      });
      const result = await response.json();
      if (!result.success) throw new Error('Failed to reset SAF data');
    }

    // Reload data
    await initDataSdk();
    showToast('SAF data reset successfully');
  } catch (error) {
    showToast('Reset failed. Please try again.', 'danger');
  }

  hideLoading();
}