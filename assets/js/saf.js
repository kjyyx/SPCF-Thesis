// saf.js - Student Allocated Funds JavaScript
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

const DEPARTMENTS = [
  { id: 'casse', name: 'College of Arts, Social Sciences and Education', short: 'CASSE', db_id: 'casse' },
  { id: 'ccis', name: 'College of Computing and Information Sciences', short: 'CCIS', db_id: 'ccis' },
  { id: 'chtm', name: 'College of Hospitality and Tourism Management', short: 'CHTM', db_id: 'chtm' },
  { id: 'cob', name: 'College of Business', short: 'COB', db_id: 'cob' },
  { id: 'coc', name: 'College of Criminology', short: 'COC', db_id: 'coc' },
  { id: 'coe', name: 'College of Engineering', short: 'COE', db_id: 'coe' },
  { id: 'con', name: 'College of Nursing', short: 'CON', db_id: 'con' },
  { id: 'miranda', name: 'SPCF Miranda', short: 'Miranda', db_id: 'miranda' },
  { id: 'ssc', name: 'Supreme Student Council (SSC)', short: 'SSC', db_id: 'ssc' }
];

const deptMap = {};
const deptNameToIdMap = {};
DEPARTMENTS.forEach(dept => {
  deptMap[dept.db_id] = dept; // Key by db_id to match backend references
  deptNameToIdMap[dept.name] = dept.db_id;
});

// Global state
let currentDeptId = null;
let allData = [];
let isLoading = false;
let editingTransactionId = null;
let currentUser = window.currentUser || null;

// Determine access level based on user role and position
const isStudent = currentUser && currentUser.role === 'student';
const isAccounting = currentUser && currentUser.role === 'employee' && currentUser.position && currentUser.position.toLowerCase().includes('accounting');
const isOsa = currentUser && currentUser.role === 'employee' && currentUser.position && currentUser.position.toLowerCase().includes('osa');
const isAdmin = currentUser && currentUser.role === 'admin';
const canEditSaf = isAccounting || isAdmin;

// Modals
let allocateModal, transactionModal, addDeductModal, resetModal, editModal;

document.addEventListener('DOMContentLoaded', async () => {
  allocateModal = new bootstrap.Modal(document.getElementById('allocateModal'));
  transactionModal = new bootstrap.Modal(document.getElementById('transactionModal'));
  addDeductModal = new bootstrap.Modal(document.getElementById('addDeductModal'));
  resetModal = new bootstrap.Modal(document.getElementById('resetModal'));
  editModal = new bootstrap.Modal(document.getElementById('editModal'));

  const allocDeptSelect = document.getElementById('alloc-dept');
  if (allocDeptSelect) {
    DEPARTMENTS.forEach(dept => {
      const option = document.createElement('option');
      option.value = dept.db_id;
      option.textContent = dept.name;
      allocDeptSelect.appendChild(option);
    });
  }

  // Setup UI based on role
  if (canEditSaf || isOsa) {
    document.getElementById('sidebar').style.display = 'block';
  }

  if (canEditSaf) {
    document.getElementById('btn-allocate').style.display = 'inline-flex';
    document.getElementById('btn-edit').style.display = 'inline-flex';
    document.getElementById('btn-reset').style.display = 'inline-flex';
    document.getElementById('btn-add-deduct').style.display = 'inline-flex';
  }

  // Show approvals button for Accounting personnel
  if (isAccounting) {
    document.getElementById('btn-approvals').style.display = 'inline-flex';
  }

  await initDataSdk();

  if (isStudent && currentUser.department) {
    const studentDeptId = deptNameToIdMap[currentUser.department];
    if (studentDeptId) {
      currentDeptId = studentDeptId;
      document.getElementById('sidebar').style.display = 'none';
      document.getElementById('main-content').className = 'col-lg-12';
      renderDashboard(currentDeptId);
    } else {
      document.getElementById('current-dept-name').textContent = "Department not found or mapped incorrectly.";
    }
  } else if ((canEditSaf || isOsa) && DEPARTMENTS.length > 0) {
    currentDeptId = DEPARTMENTS[0].db_id;
    renderSidebar();
    renderDashboard(currentDeptId);
  }
});

function showLoading() {
  isLoading = true;
  const overlay = document.getElementById('loading-overlay');
  if (overlay) overlay.style.display = 'flex';
}

function hideLoading() {
  isLoading = false;
  const overlay = document.getElementById('loading-overlay');
  if (overlay) overlay.style.display = 'none';
}

function showToast(message, type = 'info') {
  if (window.ToastManager) {
    window.ToastManager.show({ type: type, message: message });
  } else {
    alert(message); // Fallback
  }
}

function formatCurrency(amount) {
  return '₱' + parseFloat(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function safeText(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

async function initDataSdk() {
  showLoading();
  try {
    const response = await fetch(BASE_URL + 'api/saf.php', {
      method: 'GET',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include'
    });
    const result = await response.json();
    if (result.success) {
      allData = result.data || [];
      
      // NEW: Calculate and show the Global Total for Employees
      if (canEditSaf || isOsa) {
          let globalTotal = 0;
          allData.forEach(d => {
              if (d.type === 'saf') {
                  const initial = parseFloat(d.initial_amount) || 0;
                  const used = parseFloat(d.used_amount) || 0;
                  const balance = d.current_balance !== undefined ? parseFloat(d.current_balance) : (initial - used);
                  globalTotal += balance;
              }
          });
          const globalContainer = document.getElementById('global-saf-container');
          const globalTotalEl = document.getElementById('global-total-saf');
          if (globalContainer && globalTotalEl) {
              globalContainer.classList.remove('d-none');
              globalContainer.classList.add('d-inline-flex');
              globalTotalEl.textContent = formatCurrency(globalTotal);
          }
      }

    } else {
      showToast('Failed to load data: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error(error);
    showToast('Failed to connect to the server', 'danger');
  }
  hideLoading();
  if (currentDeptId) renderDashboard(currentDeptId);
}

function renderSidebar() {
  const container = document.getElementById('dept-list');
  if (!container) return;
  container.innerHTML = '';

  DEPARTMENTS.forEach(dept => {
    const a = document.createElement('a');
    a.href = '#';
    const isActive = dept.db_id === currentDeptId;
    
    // OneUI Styling for Sidebar items
    a.className = `list-group-item list-group-item-action border-0 rounded-3 mb-2 px-3 py-2 fw-medium ${isActive ? 'active bg-primary text-white shadow-sm' : 'text-dark bg-light'}`;
    a.onclick = (e) => {
      e.preventDefault();
      currentDeptId = dept.db_id;
      renderSidebar();
      renderDashboard(dept.db_id);
    };

    const nameSpan = document.createElement('span');
    nameSpan.className = 'text-sm';
    nameSpan.textContent = dept.short;

    // Check if Department has SAF allocated
    const safRecord = allData.find(d => d.type === 'saf' && d.department_id === dept.db_id);
    if (safRecord) {
        const icon = document.createElement('i');
        icon.className = 'bi bi-check-circle-fill text-success opacity-75 ms-2 float-end mt-1';
        icon.style.fontSize = '0.75rem';
        if (isActive) icon.classList.replace('text-success', 'text-white');
        nameSpan.appendChild(icon);
    }

    a.appendChild(nameSpan);
    container.appendChild(a);
  });
}

function renderDashboard(deptId) {
  const dept = deptMap[deptId];
  if (!dept) return;

  document.getElementById('current-dept-name').textContent = dept.name;
  if (document.getElementById('reset-dept-name')) {
    document.getElementById('reset-dept-name').textContent = dept.name;
  }

  // Use the specific keys expected by the database (initial_amount, used_amount, etc.)
  const safRecord = allData.find(d => d.type === 'saf' && d.department_id === deptId);
  const transactions = allData.filter(d => d.type === 'transaction' && d.department_id === deptId);

  if (!safRecord) {
    document.getElementById('total-allocated').textContent = '₱0.00';
    document.getElementById('total-used').textContent = '₱0.00';
    document.getElementById('remaining-balance').textContent = '₱0.00';
    updateProgressBar(0, 0);

    if (canEditSaf) {
      document.getElementById('btn-edit').style.display = 'none';
      document.getElementById('btn-reset').style.display = 'none';
      document.getElementById('btn-add-deduct').style.display = 'none';
      document.getElementById('btn-allocate').style.display = 'inline-flex';
      document.getElementById('alloc-dept').value = deptId;
    }

    document.getElementById('transactions-list').innerHTML = `
      <div class="text-center py-5 bg-white border border-dashed rounded-4">
        <i class="bi bi-wallet2 text-muted mb-3 d-block" style="font-size: 3rem; opacity: 0.3;"></i>
        <h6 class="text-dark fw-bold">No Funds Allocated</h6>
        <p class="text-muted text-sm mb-0">This department has not been allocated any funds yet.</p>
      </div>`;
    return;
  }

  if (canEditSaf) {
    document.getElementById('btn-allocate').style.display = 'none';
    document.getElementById('btn-edit').style.display = 'inline-flex';
    document.getElementById('btn-reset').style.display = 'inline-flex';
    document.getElementById('btn-add-deduct').style.display = 'inline-flex';
  }

  const allocated = parseFloat(safRecord.initial_amount) || 0;
  const used = parseFloat(safRecord.used_amount) || 0;
  const balance = safRecord.current_balance !== undefined ? parseFloat(safRecord.current_balance) : (allocated - used);

  document.getElementById('total-allocated').textContent = formatCurrency(allocated);
  document.getElementById('total-used').textContent = formatCurrency(used);
  document.getElementById('remaining-balance').textContent = formatCurrency(balance);

  updateProgressBar(used, allocated);
  renderTransactions(transactions);
}

function updateProgressBar(used, allocated) {
  const progressEl = document.getElementById('fund-progress');
  const percentageEl = document.getElementById('fund-percentage');
  
  if (!progressEl || !percentageEl) return;

  if (allocated <= 0) {
    progressEl.style.width = '0%';
    progressEl.className = 'progress-bar bg-secondary';
    percentageEl.textContent = '0%';
    return;
  }

  const percentage = Math.min(100, Math.max(0, (used / allocated) * 100));
  progressEl.style.width = percentage + '%';
  percentageEl.textContent = percentage.toFixed(1) + '%';

  progressEl.classList.remove('bg-success', 'bg-warning', 'bg-danger', 'bg-primary');
  
  if (percentage < 50) {
    progressEl.classList.add('bg-success');
  } else if (percentage < 85) {
    progressEl.classList.add('bg-warning');
  } else {
    progressEl.classList.add('bg-danger');
  }
}

function renderTransactions(records) {
  const container = document.getElementById('transactions-list');
  if (!container) return;

  if (!records || records.length === 0) {
    container.innerHTML = `
      <div class="text-center py-5 bg-white border rounded-4">
        <i class="bi bi-clock-history text-muted mb-3 d-block" style="font-size: 3rem; opacity: 0.3;"></i>
        <h6 class="text-dark fw-bold">No Transaction History</h6>
        <p class="text-muted text-sm mb-0">There are no recorded transactions for this department.</p>
      </div>`;
    return;
  }

  // Sort descending by date
  records.sort((a, b) => new Date(b.transaction_date) - new Date(a.transaction_date));

  let html = '';
  records.forEach(record => {
    // Database mapping for transaction attributes
    const type = record.transaction_type;
    const amount = record.transaction_amount;
    const date = record.transaction_date;
    const desc = record.transaction_description;
      
    // Determine colors and prefixes based on transaction type
    const isDeduction = type === 'expense' || type === 'deduct';
    const amountColor = isDeduction ? 'text-danger' : 'text-success';
    const amountPrefix = isDeduction ? '-' : '+';
    
    const iconClass = type === 'expense' ? 'bi-cart-dash' :
                      type === 'allocation' || type === 'add' || type === 'set' ? 'bi-cash-coin' :
                      type === 'deduct' ? 'bi-arrow-down-circle' : 'bi-plus-circle';
                      
    const iconBg = isDeduction ? 'bg-danger text-danger' : 'bg-success text-success';

    let controlsHtml = '';
    if (canEditSaf) {
      controlsHtml = `
        <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top">
          <button class="btn btn-light btn-sm rounded-pill px-3 fw-medium text-muted border shadow-sm" onclick="editTransaction(${record.id})">
            <i class="bi bi-pencil me-1"></i> Edit
          </button>
          <button class="btn btn-light btn-sm rounded-pill px-3 fw-medium text-danger border shadow-sm" onclick="deleteTransaction(${record.id})">
            <i class="bi bi-trash me-1"></i> Delete
          </button>
        </div>`;
    }

    const typeName = type === 'expense' ? 'Expense' :
                     type === 'add' ? 'Allocation Added' :
                     type === 'deduct' ? 'Funds Deducted' :
                     type === 'set' ? 'Initial Allocation' : 'Transaction';

    // OneUI Inspired Transaction Card
    html += `
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="btn-icon sm rounded-circle ${iconBg} bg-opacity-10 border-0">
                        <i class="bi ${iconClass}"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark">${typeName}</div>
                        <div class="text-xs text-muted fw-medium">${formatDate(date)}</div>
                    </div>
                </div>
                <div class="text-end">
                    <div class="fs-5 fw-bold ${amountColor}" style="letter-spacing:-0.5px;">${amountPrefix}${formatCurrency(amount)}</div>
                    ${record.document_ref ? `<div class="text-xs text-muted mt-1"><i class="bi bi-file-earmark-text me-1"></i>Ref: ${safeText(record.document_ref)}</div>` : ''}
                </div>
            </div>
            ${desc ? `<div class="bg-light p-3 rounded-3 text-sm text-dark mb-0">${safeText(desc)}</div>` : ''}
            ${controlsHtml}
        </div>
    </div>
    `;
  });

  container.innerHTML = html;
}

// -----------------------------------------------------------------------------
// API & Submit Actions (Mapped strictly to specific Database Keys)
// -----------------------------------------------------------------------------

async function submitAllocation() {
  if (isLoading) return;

  const deptId = document.getElementById('alloc-dept').value;
  const amount = document.getElementById('alloc-amount').value;
  const notes = document.getElementById('alloc-notes').value;

  if (!deptId || !amount) {
    showToast('Please fill in all required fields.', 'warning');
    return;
  }

  showLoading();
  allocateModal.hide();

  try {
    const response = await fetch(BASE_URL + 'api/saf.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        type: 'saf',
        department_id: deptId,
        initial_amount: amount,
        used_amount: 0,
        notes: notes
      })
    });
    const result = await response.json();
    
    if (result.success) {
      if (window.addAuditLog) {
        window.addAuditLog('SAF_ALLOCATED', 'Financial Management', `Allocated ${formatCurrency(amount)} to ${deptId}`, result.id, 'SAF');
      }
      showToast('Funds allocated successfully', 'success');
      currentDeptId = deptId;
      document.getElementById('allocateForm').reset();
      await initDataSdk();
    } else {
      showToast('Allocation failed: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error(error);
    showToast('Operation failed.', 'danger');
  }
  hideLoading();
}

function openEditSafModal() {
  const safRecord = allData.find(d => d.type === 'saf' && d.department_id === currentDeptId);
  if (!safRecord) return;

  document.getElementById('edit-saf-id').value = safRecord.id;
  document.getElementById('edit-alloc-amount').value = safRecord.initial_amount;

  editModal.show();
}

async function submitEditSaf() {
  if (isLoading) return;

  const safId = document.getElementById('edit-saf-id').value;
  const amount = document.getElementById('edit-alloc-amount').value;

  if (!safId || !amount) {
    showToast('Please fill in all required fields.', 'warning');
    return;
  }

  showLoading();
  editModal.hide();

  try {
    const response = await fetch(BASE_URL + 'api/saf.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        id: safId,
        type: 'saf',
        initial_amount: amount
      })
    });
    const result = await response.json();

    if (result.success) {
      if (window.addAuditLog) {
        window.addAuditLog('SAF_UPDATED', 'Financial Management', `Updated SAF allocation for ${currentDeptId}`, safId, 'SAF');
      }
      showToast('SAF information updated successfully', 'success');
      await initDataSdk();
    } else {
      showToast('Update failed: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error(error);
    showToast('Operation failed.', 'danger');
  }
  hideLoading();
}

async function submitAddDeduct() {
  if (isLoading || !currentDeptId) return;

  const type = document.querySelector('input[name="operationType"]:checked').value;
  const amount = document.getElementById('ad-amount').value;
  const desc = document.getElementById('ad-desc').value;

  if (!amount || !desc) {
    showToast('Please enter both amount and description.', 'warning');
    return;
  }

  const safRecord = allData.find(d => d.type === 'saf' && d.department_id === currentDeptId);
  if (!safRecord) {
    showToast('Department has no SAF record. Allocate funds first.', 'danger');
    return;
  }

  showLoading();
  addDeductModal.hide();

  try {
    const transType = type === 'add' ? 'add' : 'deduct';
    
    // 1. Create transaction record
    await fetch(BASE_URL + 'api/saf.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        type: 'transaction',
        department_id: currentDeptId,
        transaction_type: transType,
        transaction_amount: amount,
        transaction_description: desc,
        transaction_date: new Date().toISOString()
      })
    });

    // 2. Update SAF initial_amount directly to reflect adjustment
    let newTotal = parseFloat(safRecord.initial_amount);
    if (type === 'add') {
      newTotal += parseFloat(amount);
    } else {
      newTotal -= parseFloat(amount);
    }

    const updateResponse = await fetch(BASE_URL + 'api/saf.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        id: safRecord.id,
        type: 'saf',
        initial_amount: newTotal
      })
    });
    
    const updateResult = await updateResponse.json();

    if (updateResult.success) {
        if (window.addAuditLog) {
            window.addAuditLog('SAF_ADJUSTED', 'Financial Management', `${type === 'add' ? 'Added' : 'Deducted'} ${formatCurrency(amount)} for ${currentDeptId}`, safRecord.id, 'SAF');
        }
        showToast(type === 'add' ? 'Funds added successfully' : 'Funds deducted successfully', 'success');
        document.getElementById('addDeductForm').reset();
    }

    await initDataSdk();
  } catch (error) {
    console.error(error);
    showToast('Operation failed.', 'danger');
  }

  hideLoading();
}

function editTransaction(id) {
  const trans = allData.find(d => d.type === 'transaction' && parseInt(d.id) === parseInt(id));
  if (!trans) return;

  editingTransactionId = id;
  document.getElementById('trans-id').value = trans.id;
  document.getElementById('trans-type').value = trans.transaction_type || 'expense';
  document.getElementById('trans-amount').value = trans.transaction_amount;
  document.getElementById('trans-doc-ref').value = trans.document_ref || '';
  document.getElementById('trans-desc').value = trans.transaction_description || '';

  transactionModal.show();
}

async function submitTransaction() {
  if (isLoading) return;

  const id = document.getElementById('trans-id').value;
  const type = document.getElementById('trans-type').value;
  const amount = document.getElementById('trans-amount').value;
  const docRef = document.getElementById('trans-doc-ref').value;
  const desc = document.getElementById('trans-desc').value;

  if (!amount || !desc) {
    showToast('Amount and description are required', 'warning');
    return;
  }

  showLoading();
  transactionModal.hide();

  try {
    const response = await fetch(BASE_URL + 'api/saf.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        id: id,
        type: 'transaction',
        transaction_type: type,
        transaction_amount: amount,
        document_ref: docRef,
        transaction_description: desc
      })
    });
    const result = await response.json();

    if (result.success) {
      if (window.addAuditLog) {
        window.addAuditLog('TRANSACTION_UPDATED', 'Financial Management', `Updated transaction ID: ${id}`, id, 'Transaction');
      }
      showToast('Transaction updated successfully', 'success');
      editingTransactionId = null;
      document.getElementById('transactionForm').reset();
      
      await initDataSdk();
    } else {
      showToast('Update failed: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error(error);
    showToast('Operation failed.', 'danger');
  }
  hideLoading();
}

async function deleteTransaction(id) {
  if (!confirm('Are you sure you want to delete this transaction? This will affect the department balance.')) {
    return;
  }

  if (isLoading) return;
  showLoading();

  try {
    const response = await fetch(BASE_URL + 'api/saf.php', {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        id: id,
        type: 'transaction'
      })
    });
    const result = await response.json();

    if (result.success) {
      if (window.addAuditLog) {
        window.addAuditLog('TRANSACTION_DELETED', 'Financial Management', `Deleted transaction ID: ${id}`, id, 'Transaction');
      }
      showToast('Transaction deleted successfully', 'success');
      await initDataSdk();
    } else {
      showToast('Delete failed: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error(error);
    showToast('Operation failed.', 'danger');
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
      const response = await fetch(BASE_URL + 'api/saf.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          id: safRecord.id,
          type: 'saf'
        })
      });
      const result = await response.json();
      
      if (result.success) {
        if (window.addAuditLog) {
          window.addAuditLog('SAF_RESET', 'Financial Management', `Reset all SAF data for ${currentDeptId}`, safRecord.id, 'SAF', 'WARNING');
        }
        showToast('Department data has been reset', 'success');
        await initDataSdk();
      } else {
        showToast('Reset failed: ' + result.message, 'danger');
      }
    }
  } catch (error) {
    console.error(error);
    showToast('Reset failed. Please try again.', 'danger');
  }

  hideLoading();
}