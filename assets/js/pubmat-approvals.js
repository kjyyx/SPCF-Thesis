// Pubmat Approvals JavaScript
var BASE_URL = window.BASE_URL || './';

let currentMaterialId = null;
let currentAction = null;
let threadComments = [];
let replyTarget = null;
let currentMaterial = null;

function normalizeMaterialId(id) {
    if (id == null) return null;
    let value = String(id).trim();
    if (!value) return null;

    if (value.toUpperCase().startsWith('MAT-')) value = value.substring(4).trim();
    
    const matMatch = value.match(/^MAT(\d+)$/i);
    if (matMatch) return `MAT${matMatch[1]}`;
    if (/^\d+$/.test(value)) return `MAT${value.padStart(3, '0')}`;

    return null;
}

document.addEventListener('DOMContentLoaded', function () {
    loadMaterials();
    
    const commentInput = document.getElementById('threadCommentInput');
    if (commentInput) {
        commentInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                postComment();
            }
        });
    }

    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');
    
    if (approveBtn) {
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
            : `<div class="pubmat-preview-placeholder"><i class="bi bi-file-earmark-pdf-fill" style="font-size: 3rem; color: var(--color-danger);"></i></div>`;

        const recentCommentHtml = mat.recent_comment ? `
            <div class="pubmat-comment-preview">
                <i class="bi bi-chat-quote me-1" style="color: var(--color-text-tertiary);"></i>
                <strong>${escapeHtml(mat.recent_comment.author)}:</strong>
                ${escapeHtml(mat.recent_comment.preview)}
            </div>` : '';

        html += `
        <div class="col-md-6 col-lg-4">
            <div class="card pubmat-card h-100 d-flex flex-column" style="overflow: hidden; padding: 0;">
                <div class="pubmat-preview">
                    ${previewHtml}
                    <div class="pubmat-preview-badge">
                        <span class="badge badge-light" style="font-size: var(--text-2xs);">Publication Material</span>
                    </div>
                </div>
                <div class="card-body d-flex flex-column" style="padding: var(--space-4) var(--space-5); flex: 1;">
                    <h6 class="mb-1 text-truncate" title="${escapeHtml(mat.title)}" style="font-size: var(--text-base); font-weight: var(--font-semibold); color: var(--color-text-heading);">
                        ${escapeHtml(mat.title)}
                    </h6>
                    <div class="pubmat-meta mb-3">
                        <span><i class="bi bi-person me-1"></i>${escapeHtml(mat.creator_name)}</span>
                        <span style="color: var(--gray-300);">&bull;</span>
                        <span><i class="bi bi-calendar3 me-1"></i>${new Date(mat.uploaded_at).toLocaleDateString()}</span>
                    </div>
                    ${recentCommentHtml}
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
    currentMaterialId = normalizeMaterialId(id) || id;
    
    const viewer = document.getElementById('materialViewer');
    viewer.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
    
    fetch(BASE_URL + `api/materials.php?action=get_material_details&id=${encodeURIComponent(currentMaterialId)}&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) throw new Error(response.status === 403 ? 'Permission denied' : 'Failed to load material');
            return response.json();
        })
        .then(data => {
            if (data.success && data.material) {
                const material = data.material;
                currentMaterial = material;
                
                const modalTitle = document.getElementById('viewModalTitle');
                modalTitle.innerHTML = `<i class="bi bi-eye text-primary me-2"></i> ${escapeHtml(material.title)}`;
                
                const isImage = material.file_type && material.file_type.startsWith('image/');
                const isPDF = material.file_type === 'application/pdf';
                let fileUrl = BASE_URL + `api/materials.php?action=serve_image&id=${encodeURIComponent(currentMaterialId)}`;
                
                if (isImage) {
                    viewer.innerHTML = `<img src="${fileUrl}" class="img-fluid rounded-3 shadow-sm" style="max-height: 70vh; object-fit: contain;" alt="Material">`;
                } else if (isPDF) {
                    viewer.innerHTML = `<iframe src="${fileUrl}" class="w-100 rounded-3 shadow-sm border-0" style="height: 70vh;"></iframe>`;
                } else {
                    viewer.innerHTML = `<div style="text-align: center; padding: var(--space-6);">
                        <i class="bi bi-file-earmark" style="font-size: 3rem; color: var(--color-text-tertiary); display: block; margin-bottom: var(--space-3);"></i>
                        <p>Preview not available for this file type.</p>
                        <button class="btn btn-primary btn-sm" onclick="downloadMaterial('${currentMaterialId}')"><i class="bi bi-download me-1"></i> Download</button>
                    </div>`;
                }

                document.getElementById('downloadBtnInModal').onclick = () => downloadMaterial(currentMaterialId);
                
                // NEW: Display the approval timeline
                displayMaterialWorkflow(material);
                
                loadThreadComments(currentMaterialId);
            } else {
                viewer.innerHTML = '<div style="color: var(--color-danger); padding: var(--space-4);">Material not found.</div>';
            }
        })
        .catch(error => {
            viewer.innerHTML = `<div style="color: var(--color-danger); padding: var(--space-4);">${escapeHtml(error.message)}</div>`;
            showError(error.message);
        });

    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();
}

function downloadMaterial(id) {
    window.open(BASE_URL + `api/materials.php?download=1&id=${id}`, '_blank');
}

function displayMaterialWorkflow(material) {
    const workflowContainer = document.getElementById('workflowContainer');
    if (!workflowContainer || !material.workflow_history || material.workflow_history.length === 0) {
        if(workflowContainer) workflowContainer.innerHTML = '';
        return;
    }
    
    let html = `<div class="d-flex align-items-center mb-3"><i class="bi bi-diagram-3 text-primary me-2"></i><strong style="font-size: var(--text-sm);">Approval Timeline</strong></div>`;
    
    material.workflow_history.forEach((item, index) => {
        let color = 'var(--gray-300)';
        let icon = 'bi-circle';
        
        if (item.action === 'Approved') { color = 'var(--color-success)'; icon = 'bi-check-circle-fill'; }
        else if (item.action === 'Rejected') { color = 'var(--color-danger)'; icon = 'bi-x-circle-fill'; }
        else if (item.action === 'Pending') { color = 'var(--color-warning)'; icon = 'bi-clock-fill'; }

        html += `
            <div class="d-flex align-items-start mb-2" style="font-size: var(--text-sm);">
                <i class="bi ${icon} me-2" style="color: ${color}; font-size: 1.1rem; margin-top: -2px;"></i>
                <div>
                    <div class="fw-semibold" style="color: var(--color-text-heading);">${item.office_name}</div>
                    <div style="color: var(--color-text-tertiary); font-size: var(--text-xs);">
                        ${item.action} &bull; ${new Date(item.created_at).toLocaleDateString()}
                        ${item.note ? `<br><span class="fst-italic">"${escapeHtml(item.note)}"</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    workflowContainer.innerHTML = html;
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
            const modal = bootstrap.Modal.getInstance(document.getElementById('approvalModal'));
            if (modal) modal.hide();
            
            // Close view modal if it's open behind
            const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewModal'));
            if (viewModal) viewModal.hide();

            await loadMaterials();
        } else {
            showError('Failed to process approval: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        showError('Error processing approval: ' + error.message);
    } finally {
        activeBtn.innerHTML = originalText;
        activeBtn.disabled = false;
    }
}

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
        threadComments = [];
        renderThreadComments();
    }
}

function renderThreadComments() {
    const commentsList = document.getElementById('threadCommentsList');
    if (!commentsList) return;

    if (!threadComments || threadComments.length === 0) {
        commentsList.innerHTML = `<div style="text-align: center; padding: var(--space-6) var(--space-4); color: var(--color-text-tertiary); font-size: var(--text-xs);"><i class="bi bi-chat-dots" style="font-size: 1.75rem; display: block; margin-bottom: var(--space-2); opacity: 0.5;"></i>No comments yet. Be the first!</div>`;
        return;
    }

    const commentMap = {};
    const rootComments = [];
    
    threadComments.forEach(c => { commentMap[c.id] = { ...c, replies: [] }; });
    threadComments.forEach(c => {
        if (c.parent_id && commentMap[c.parent_id]) commentMap[c.parent_id].replies.push(commentMap[c.id]);
        else rootComments.push(commentMap[c.id]);
    });

    const renderComment = (comment, depth = 0) => {
        const repliesHtml = comment.replies && comment.replies.length ? `<div class="comment-replies">${comment.replies.map(r => renderComment(r, depth + 1)).join('')}</div>` : '';
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
                <button class="comment-reply-btn" onclick="setReplyTarget(${comment.id}, '${escapeHtml(comment.author_name)}')"><i class="bi bi-reply"></i> Reply</button>
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
    if (input) { input.focus(); input.placeholder = `Replying to ${authorName}...`; }
}

function clearReplyTarget() {
    replyTarget = null;
    const banner = document.getElementById('commentReplyBanner');
    if (banner) banner.classList.add('d-none');
    const input = document.getElementById('threadCommentInput');
    if (input) input.placeholder = "Write a comment...";
}

async function postComment() {
    const materialId = normalizeMaterialId(currentMaterialId);
    if (!materialId) { showError('No material selected'); return; }

    const input = document.getElementById('threadCommentInput');
    const comment = input.value.trim();
    if (!comment) { showError('Please enter a comment'); return; }

    const payload = {
        action: 'add_comment',
        material_id: materialId,
        comment: comment,
        parent_id: replyTarget ? replyTarget.id : null
    };

    try {
        const response = await fetch(BASE_URL + 'api/materials.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (result.success) {
            input.value = '';
            clearReplyTarget();
            
            const saveIndicator = document.getElementById('notesSaveIndicator');
            if (saveIndicator) {
                saveIndicator.classList.remove('d-none');
                setTimeout(() => saveIndicator.classList.add('d-none'), 3000);
            }
            
            showSuccess('Comment posted successfully');
            await loadThreadComments(materialId);
        } else {
            showError('Failed to post comment: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        showError('Error posting comment: ' + error.message);
    }
}

// Utility functions
function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function formatDate(dateString) {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return '';
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
    } catch (e) { return ''; }
}

function showSuccess(message) {
    if (window.ToastManager) window.ToastManager.show({ type: 'success', title: 'Success', message: message, duration: 4000 });
    else alert('Success: ' + message);
}

function showError(message) {
    if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: message, duration: 4000 });
    else alert('Error: ' + message);
}

let autoRefreshInterval = setInterval(() => {
    if (document.getElementById('materialsContainer') && !document.getElementById('approvalModal').classList.contains('show') && !document.getElementById('viewModal').classList.contains('show')) {
        loadMaterials();
    }
}, 30000);

window.addEventListener('beforeunload', () => { if (autoRefreshInterval) clearInterval(autoRefreshInterval); });