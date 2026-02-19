// Pubmat Approvals JavaScript
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

let currentMaterialId = null;
let currentAction = null;

document.addEventListener('DOMContentLoaded', function () {
    loadMaterials();
});

async function loadMaterials() {
    try {
        const response = await fetch(BASE_URL + 'api/materials.php?for_approval=1');
        
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // Log the raw response for debugging
            const rawText = await response.text();
            console.error('Non-JSON response received:', rawText.substring(0, 500)); // First 500 chars
            showError('Server returned invalid response. Check console for details.');
            return;
        }
        
        const result = await response.json();

        if (result.success) {
            displayMaterials(result.materials);
        } else {
            showError('Failed to load materials: ' + result.message);
        }
    } catch (error) {
        showError('Error loading materials: ' + error.message);
    }
}

function displayMaterials(materials) {
    const container = document.getElementById('materialsContainer');

    if (materials.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No materials pending your approval.</div>';
        return;
    }

    let html = '<div class="row g-3">';

    materials.forEach(material => {
        const uploadedDate = new Date(material.uploaded_at).toLocaleDateString();
        const fileSize = (material.file_size_kb / 1024).toFixed(2) + ' MB';

        html += `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">${material.title}</h6>
                        <p class="card-text text-muted small">${material.description || 'No description'}</p>
                        <div class="mb-2">
                            <small class="text-muted">
                                <i class="bi bi-calendar"></i> ${uploadedDate}<br>
                                <i class="bi bi-file-earmark"></i> ${fileSize}<br>
                                <i class="bi bi-person"></i> Step ${material.step_order}
                            </small>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="d-flex gap-1 mb-2">
                            <button class="btn btn-outline-primary btn-sm flex-fill" onclick="viewMaterial('${material.id}')">
                                <i class="bi bi-eye"></i> View
                            </button>
                            <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="downloadMaterial('${material.id}')">
                                <i class="bi bi-download"></i> Download
                            </button>
                        </div>
                        <div class="btn-group w-100">
                            <button class="btn btn-outline-success btn-sm" onclick="showApprovalModal('${material.id}', 'approve')">
                                <i class="bi bi-check-circle"></i> Approve
                            </button>
                            <button class="btn btn-outline-danger btn-sm" onclick="showApprovalModal('${material.id}', 'reject')">
                                <i class="bi bi-x-circle"></i> Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

function showApprovalModal(materialId, action) {
    currentMaterialId = materialId;
    currentAction = action;

    const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
    const modalMessage = document.getElementById('modalMessage');
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');

    if (action === 'approve') {
        modalMessage.textContent = 'Are you sure you want to approve this material?';
        approveBtn.style.display = 'inline-block';
        rejectBtn.style.display = 'none';
    } else {
        modalMessage.textContent = 'Are you sure you want to reject this material?';
        approveBtn.style.display = 'none';
        rejectBtn.style.display = 'inline-block';
    }

    document.getElementById('approvalNote').value = '';
    modal.show();
}

function viewMaterial(materialId) {
    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    const viewer = document.getElementById('materialViewer');
    const title = document.getElementById('viewModalTitle');
    const downloadBtn = document.getElementById('downloadBtnInModal');

    // Set modal title
    title.textContent = 'View Material';

    // Show loading
    viewer.innerHTML = '<div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>';

    // For now, redirect to download since we can't preview files directly
    // In a real implementation, you'd check file type and show appropriate viewer
    viewer.innerHTML = `
        <div class="alert alert-info">
            <i class="bi bi-file-earmark me-2"></i>
            File Preview
            <br><small class="text-muted">Click download to view the file</small>
        </div>
    `;

    // Set up download button
    downloadBtn.onclick = () => downloadMaterial(materialId);

    modal.show();
}

function downloadMaterial(materialId) {
    // Use the real API endpoint
    window.location.href = BASE_URL + 'api/materials.php?download=1&id=' + materialId;
}

async function submitApproval() {
    if (!currentMaterialId || !currentAction) return;

    const note = document.getElementById('approvalNote').value;

    try {
        const formData = new FormData();
        formData.append('action', currentAction);
        if (note) formData.append('note', note);

        const response = await fetch(BASE_URL + `api/materials.php?id=${currentMaterialId}`, {
            method: 'PUT',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            showSuccess(`Material ${currentAction}d successfully`);
            bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
            loadMaterials(); // Refresh the list
        } else {
            showError('Failed to process approval: ' + result.message);
        }
    } catch (error) {
        showError('Error processing approval: ' + error.message);
    }
}

// Event listeners for modal buttons
document.getElementById('approveBtn').addEventListener('click', submitApproval);
document.getElementById('rejectBtn').addEventListener('click', submitApproval);

function showSuccess(message) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: 'success',
            title: 'Success',
            message: message,
            duration: 4000
        });
    } else {
        alert(message);
    }
}

function showError(message) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: 'error',
            title: 'Error',
            message: message,
            duration: 4000
        });
    } else {
        alert(message);
    }
}