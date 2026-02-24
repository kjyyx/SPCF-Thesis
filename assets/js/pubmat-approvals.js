// Pubmat Approvals JavaScript
var BASE_URL = window.BASE_URL || './';

let currentMaterialId = null;
let currentAction = null;
let threadComments = [];
let replyTarget = null;
let currentMaterial = null;

// Add a function to check the current state
function debugState() {
    console.log('=== DEBUG STATE ===');
    console.log('currentMaterialId:', currentMaterialId);
    console.log('currentAction:', currentAction);
    console.log('replyTarget:', replyTarget);
    console.log('currentMaterial:', currentMaterial);
}

function normalizeMaterialId(id) {
    if (id == null) return null;

    let value = String(id).trim();
    if (!value) return null;

    // Handle prefixed forms like "MAT-MAT005" or "MAT-005"
    if (value.toUpperCase().startsWith('MAT-')) {
        value = value.substring(4).trim();
    }

    // Already MAT format
    const matMatch = value.match(/^MAT(\d+)$/i);
    if (matMatch) {
        return `MAT${matMatch[1]}`;
    }

    // Numeric fallback -> MAT###
    if (/^\d+$/.test(value)) {
        return `MAT${value.padStart(3, '0')}`;
    }

    return null;
}

document.addEventListener('DOMContentLoaded', function () {
    loadMaterials();
    
    // Add event listener for comment input
    const commentInput = document.getElementById('threadCommentInput');
    if (commentInput) {
        commentInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                postComment();
            }
        });
    }

    // Add event listeners for approval buttons
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');
    
    if (approveBtn) {
        // Remove existing listeners to avoid duplicates
        approveBtn.replaceWith(approveBtn.cloneNode(true));
        document.getElementById('approveBtn').addEventListener('click', submitApproval);
    }
    
    if (rejectBtn) {
        rejectBtn.replaceWith(rejectBtn.cloneNode(true));
        document.getElementById('rejectBtn').addEventListener('click', submitApproval);
    }
});

async function loadMaterials() {
    const container = document.getElementById('materialsContainer');
    if (!container) return;
    
    container.innerHTML = `
        <div class="col-12">
            <div class="pubmat-empty">
                <div class="spinner-border text-primary" role="status" style="width: 2.5rem; height: 2.5rem;"></div>
                <p style="margin-top: var(--space-4); font-size: var(--text-sm); color: var(--color-text-tertiary);">Loading materials…</p>
            </div>
        </div>`;

    try {
        const response = await fetch(BASE_URL + 'api/materials.php?for_approval=1&t=' + Date.now());

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const rawText = await response.text();
            console.error('Non-JSON response received:', rawText.substring(0, 500));
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
        console.error('Error loading materials:', error);
        showError('Error loading materials: ' + error.message);
    }
}

function displayMaterials(materials) {
    const container = document.getElementById('materialsContainer');
    if (!container) return;

    if (materials.length === 0) {
        container.innerHTML = `
            <div class="col-12">
                <div class="pubmat-empty">
                    <i class="bi bi-check2-circle pubmat-empty-icon"></i>
                    <h4>All caught up!</h4>
                    <p>There are no materials awaiting your approval right now.</p>
                </div>
            </div>`;
        return;
    }

    let html = '';
    materials.forEach(mat => {
        const isImage = mat.file_type && mat.file_type.startsWith('image/');
        const previewHtml = isImage
            ? `<img src="${BASE_URL}api/materials.php?action=serve_image&id=${mat.id}" alt="Preview"
                   onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'pubmat-preview-placeholder\\'><i class=\\'bi bi-image fs-1\\'></i></div>';">`
            : `<div class="pubmat-preview-placeholder">
                   <i class="bi bi-file-earmark-pdf-fill" style="font-size: 3rem; color: var(--color-danger);"></i>
               </div>`;

        const recentCommentHtml = mat.recent_comment ? `
            <div class="pubmat-comment-preview">
                <i class="bi bi-chat-quote me-1" style="color: var(--color-text-tertiary);"></i>
                <strong>${escapeHtml(mat.recent_comment.author)}:</strong>
                ${escapeHtml(mat.recent_comment.preview)}
            </div>` : '';

        html += `
        <div class="col-md-6 col-lg-4">
            <div class="card pubmat-card h-100 d-flex flex-column" style="overflow: hidden; padding: 0;">

                <!-- Preview thumbnail -->
                <div class="pubmat-preview">
                    ${previewHtml}
                    <div class="pubmat-preview-badge">
                        <span class="badge badge-light" style="font-size: var(--text-2xs);">Publication Material</span>
                    </div>
                </div>

                <!-- Card body -->
                <div class="card-body d-flex flex-column" style="padding: var(--space-4) var(--space-5); flex: 1;">
                    <h6 class="mb-1 text-truncate"
                        title="${escapeHtml(mat.title)}"
                        style="font-size: var(--text-base); font-weight: var(--font-semibold); color: var(--color-text-heading);">
                        ${escapeHtml(mat.title)}
                    </h6>

                    <div class="pubmat-meta mb-3">
                        <span><i class="bi bi-person me-1"></i>${escapeHtml(mat.creator_name)}</span>
                        <span style="color: var(--gray-300);">&bull;</span>
                        <span><i class="bi bi-calendar3 me-1"></i>${new Date(mat.uploaded_at).toLocaleDateString()}</span>
                    </div>

                    ${recentCommentHtml}

                    <!-- Action buttons pinned to bottom -->
                    <div class="d-flex gap-2 mt-auto" style="padding-top: var(--space-3);">
                        <button class="btn btn-ghost btn-sm border flex-grow-1" onclick="viewMaterial('${mat.id}')">
                            <i class="bi bi-eye me-1"></i> View
                        </button>
                        <button class="btn btn-success btn-sm" title="Approve" onclick="showApprovalModal('${mat.id}', 'approve')" style="min-width: 42px;">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" title="Reject" onclick="showApprovalModal('${mat.id}', 'reject')" style="min-width: 42px;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>

            </div>
        </div>`;
    });

    container.innerHTML = html;
}

function viewMaterial(id) {
    console.log('viewMaterial called with id:', id);
    currentMaterialId = normalizeMaterialId(id) || id;
    console.log('currentMaterialId set to:', currentMaterialId);
    
    // Show loading state
    const viewer = document.getElementById('materialViewer');
    viewer.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
    
    // Fetch material details
    fetch(BASE_URL + `api/materials.php?action=get_material_details&id=${encodeURIComponent(currentMaterialId)}&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                if (response.status === 403) {
                    throw new Error('You do not have permission to view this material');
                }
                throw new Error('Failed to load material');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.material) {
                const material = data.material;
                currentMaterial = material;
                console.log('Material loaded, currentMaterialId still:', currentMaterialId);
                
                const modalTitle = document.getElementById('viewModalTitle');
                modalTitle.innerHTML = `<i class="bi bi-eye text-primary me-2"></i> ${escapeHtml(material.title)}`;
                
                // Determine file preview type
                const isImage = material.file_type && material.file_type.startsWith('image/');
                const isPDF = material.file_type === 'application/pdf';
                
                // Get file URL
                let fileUrl = BASE_URL + `api/materials.php?action=serve_image&id=${encodeURIComponent(currentMaterialId)}`;
                
                if (isImage) {
                    viewer.innerHTML = `<img src="${fileUrl}" class="img-fluid rounded-3 shadow-sm" style="max-height: 70vh; object-fit: contain;" alt="Material" 
                        onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'alert alert-danger m-3\'>Failed to load image. <button class=\'btn btn-sm btn-primary ms-2\' onclick=\'downloadMaterial(${JSON.stringify(currentMaterialId)})\'></button></div>`;
                } else if (isPDF) {
                    viewer.innerHTML = `<iframe src="${fileUrl}" class="w-100 rounded-3 shadow-sm border-0" style="height: 70vh;"></iframe>`;
                } else {
                    viewer.innerHTML = `
                        <div style="text-align: center; padding: var(--space-6);">
                            <i class="bi bi-file-earmark" style="font-size: 3rem; color: var(--color-text-tertiary); display: block; margin-bottom: var(--space-3);"></i>
                            <p style="font-size: var(--text-sm); color: var(--color-text-tertiary); margin-bottom: var(--space-4);">Preview not available for this file type.</p>
                            <button class="btn btn-primary btn-sm" onclick="downloadMaterial(${JSON.stringify(currentMaterialId)})">
                                <i class="bi bi-download me-1"></i> Download to view
                            </button>
                        </div>`;
                }

                // Update download button
                const downloadBtn = document.getElementById('downloadBtnInModal');
                downloadBtn.onclick = function () {
                    downloadMaterial(currentMaterialId);
                };
                
                // Load comments
                loadThreadComments(currentMaterialId);
            } else {
                viewer.innerHTML = '<div style="color: var(--color-danger); font-size: var(--text-sm); padding: var(--space-4);">Material not found.</div>';
            }
        })
        .catch(error => {
            console.error('Error loading material:', error);
            viewer.innerHTML = `<div style="color: var(--color-danger); font-size: var(--text-sm); padding: var(--space-4);">${escapeHtml(error.message)}</div>`;
            showError(error.message);
        });

    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();
}

// Add download function
function downloadMaterial(id) {
    window.open(BASE_URL + `api/materials.php?download=1&id=${id}`, '_blank');
}

function displayMaterialWorkflow(material) {
    // You can add a workflow timeline section to your modal if desired
    // This would show the approval steps like in documents
    const workflowContainer = document.getElementById('workflowContainer');
    if (!workflowContainer || !material.workflow_history) return;
    
    let workflowHtml = '<div class="mt-3"><h6>Approval Timeline</h6><div class="timeline">';
    
    material.workflow_history.forEach(item => {
        const statusClass = item.action === 'Approved' ? 'bg-success' : 
                           (item.action === 'Rejected' ? 'bg-danger' : 'bg-warning');
        
        workflowHtml += `
            <div class="timeline-item mb-2">
                <div class="d-flex">
                    <div class="timeline-marker ${statusClass} me-2" style="width: 10px; height: 10px; border-radius: 50%; margin-top: 6px;"></div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                            <strong>${item.office_name}</strong>
                            <small class="text-muted">${new Date(item.created_at).toLocaleDateString()}</small>
                        </div>
                        <div>${item.action}</div>
                        ${item.note ? `<small class="text-muted">Note: ${escapeHtml(item.note)}</small>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    workflowHtml += '</div></div>';
    workflowContainer.innerHTML = workflowHtml;
}

function showApprovalModal(id, action) {
    currentMaterialId = id;
    currentAction = action;

    const title = document.getElementById('approvalModalTitle');
    const note = document.getElementById('approvalNote');
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');

    if (!title || !note || !approveBtn || !rejectBtn) return;

    note.value = '';

    if (action === 'approve') {
        title.innerHTML = '<i class="bi bi-check-circle text-success me-2"></i> Approve Material';
        approveBtn.style.display = 'inline-block';
        rejectBtn.style.display = 'none';
        note.placeholder = "Enter an optional note...";
        note.required = false;
    } else {
        title.innerHTML = '<i class="bi bi-x-circle text-danger me-2"></i> Reject Material';
        approveBtn.style.display = 'none';
        rejectBtn.style.display = 'inline-block';
        note.placeholder = "Please specify the reason for rejection (Required)...";
        note.required = true;
    }

    const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
    modal.show();
}

async function submitApproval() {
    const note = document.getElementById('approvalNote').value.trim();

    if (currentAction === 'reject' && !note) {
        showError('A reason is required when rejecting a material.');
        return;
    }

    // Update button states while processing
    const activeBtn = currentAction === 'approve' ? document.getElementById('approveBtn') : document.getElementById('rejectBtn');
    if (!activeBtn) return;
    
    const originalText = activeBtn.innerHTML;
    activeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
    activeBtn.disabled = true;

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
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('approvalModal'));
            if (modal) modal.hide();
            
            // Reload materials list
            await loadMaterials();
        } else {
            showError('Failed to process approval: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error processing approval:', error);
        showError('Error processing approval: ' + error.message);
    } finally {
        activeBtn.innerHTML = originalText;
        activeBtn.disabled = false;
    }
}

// Comment functionality
async function loadThreadComments(materialId) {
    try {
        const response = await fetch(BASE_URL + `api/materials.php?action=get_comments&id=${materialId}&t=${Date.now()}`);
        const result = await response.json();
        
        if (result.success) {
            threadComments = result.comments || [];
            renderThreadComments();
        } else {
            console.error('Failed to load comments:', result.message);
        }
    } catch (error) {
        console.error('Error loading comments:', error);
        threadComments = [];
        renderThreadComments();
    }
}

function renderThreadComments() {
    const commentsList = document.getElementById('threadCommentsList');
    if (!commentsList) return;

    if (!threadComments || threadComments.length === 0) {
        commentsList.innerHTML = `
            <div style="text-align: center; padding: var(--space-6) var(--space-4); color: var(--color-text-tertiary); font-size: var(--text-xs);">
                <i class="bi bi-chat-dots" style="font-size: 1.75rem; display: block; margin-bottom: var(--space-2); opacity: 0.5;"></i>
                No comments yet. Be the first!
            </div>`;
        return;
    }

    // Build comment tree
    const commentMap = {};
    const rootComments = [];
    
    threadComments.forEach(comment => {
        commentMap[comment.id] = { ...comment, replies: [] };
    });
    
    threadComments.forEach(comment => {
        if (comment.parent_id && commentMap[comment.parent_id]) {
            commentMap[comment.parent_id].replies.push(commentMap[comment.id]);
        } else {
            rootComments.push(commentMap[comment.id]);
        }
    });

    const renderComment = (comment, depth = 0) => {
        const repliesHtml = comment.replies && comment.replies.length
            ? `<div class="comment-replies">${comment.replies.map(r => renderComment(r, depth + 1)).join('')}</div>`
            : '';

        return `
            <div class="comment-item">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                    <div>
                        <span class="comment-author-name">${escapeHtml(comment.author_name || 'Unknown')}</span>
                        ${comment.author_position ? `<span class="comment-author-position">${escapeHtml(comment.author_position)}</span>` : ''}
                    </div>
                    <span class="comment-date flex-shrink-0">${formatDate(comment.created_at)}</span>
                </div>
                <div class="comment-body">${escapeHtml(comment.comment || '').replace(/\n/g, '<br>')}</div>
                <button class="comment-reply-btn" onclick="setReplyTarget(${comment.id}, '${escapeHtml(comment.author_name)}')">
                    <i class="bi bi-reply"></i> Reply
                </button>
                ${repliesHtml}
            </div>`;
    };

    commentsList.innerHTML = rootComments.map(c => renderComment(c)).join('');
}

function setReplyTarget(commentId, authorName) {
    replyTarget = { id: Number(commentId), authorName: authorName };

    const banner = document.getElementById('commentReplyBanner');
    const replyAuthorName = document.getElementById('replyAuthorName');
    if (replyAuthorName) replyAuthorName.textContent = authorName;
    if (banner) banner.classList.remove('d-none');

    const input = document.getElementById('threadCommentInput');
    if (input) {
        input.focus();
        input.placeholder = `Replying to ${authorName}...`;
    }
}

function clearReplyTarget() {
    replyTarget = null;
    const banner = document.getElementById('commentReplyBanner');
    if (banner) banner.classList.add('d-none');
    
    const input = document.getElementById('threadCommentInput');
    if (input) {
        input.placeholder = "Write a comment...";
    }
}

async function loadThreadComments(materialId) {
    const normalizedId = normalizeMaterialId(materialId);
    if (!normalizedId) {
        console.error('Invalid material ID for comments:', materialId);
        threadComments = [];
        renderThreadComments();
        return;
    }

    try {
        const response = await fetch(BASE_URL + `api/materials.php?action=get_comments&id=${encodeURIComponent(normalizedId)}&t=${Date.now()}`);
        const result = await response.json();
        
        if (result.success) {
            threadComments = result.comments || [];
            renderThreadComments();
        } else {
            console.error('Failed to load comments:', result.message);
        }
    } catch (error) {
        console.error('Error loading comments:', error);
        threadComments = [];
        renderThreadComments();
    }
}

async function postComment() {
    console.log('postComment called');
    console.log('currentMaterialId before check:', currentMaterialId);
    
    const materialId = normalizeMaterialId(currentMaterialId);
    if (!materialId) {
        console.error('No/invalid material selected:', currentMaterialId);
        showError('No material selected');
        return;
    }

    const input = document.getElementById('threadCommentInput');
    if (!input) {
        console.error('Comment input not found');
        return;
    }

    const comment = input.value.trim();
    if (!comment) {
        showError('Please enter a comment');
        return;
    }

    // Create the payload
    const payload = {
        action: 'add_comment',
        material_id: materialId,  // Use the captured value
        comment: comment,
        parent_id: replyTarget ? replyTarget.id : null
    };
    
    console.log('Posting comment payload:', JSON.stringify(payload, null, 2));

    const saveIndicator = document.getElementById('notesSaveIndicator');

    try {
        const response = await fetch(BASE_URL + 'api/materials.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        console.log('Comment response:', result);

        if (result.success) {
            input.value = '';
            clearReplyTarget();
            
            if (saveIndicator) {
                saveIndicator.classList.remove('d-none');
                setTimeout(() => saveIndicator.classList.add('d-none'), 3000);
            }
            
            showSuccess('Comment posted successfully');
            
            // Reload comments
            await loadThreadComments(materialId);
        } else {
            showError('Failed to post comment: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error posting comment:', error);
        showError('Error posting comment: ' + error.message);
    }
}

// Utility functions
function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDate(dateString) {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return '';
        
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
    } catch (e) {
        return '';
    }
}

function showSuccess(message) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: 'success',
            title: 'Success',
            message: message,
            duration: 4000
        });
    } else {
        console.log('Success:', message);
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
        console.error('Error:', message);
    }
}

// Auto-refresh every 30 seconds (like documents)
let autoRefreshInterval = setInterval(() => {
    if (document.getElementById('materialsContainer') && 
        !document.getElementById('approvalModal').classList.contains('show')) {
        loadMaterials();
    }
}, 30000);

// Clean up interval when page unloads
window.addEventListener('beforeunload', function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
});