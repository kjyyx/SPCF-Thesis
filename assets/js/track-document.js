// Modern Document Tracker JavaScript
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

// Pagination and data management
let currentPage = 1;
let itemsPerPage = 10;
let totalDocuments = 0;
let allDocuments = [];
let filteredDocuments = [];
let currentSortField = 'updated_at';
let currentSortDirection = 'desc';
let documentStats = { total: 0, pending: 0, approved: 0, inProgress: 0, underReview: 0 };

// PDF viewer globals
let pdfDoc = null;
let currentPdfPage = 1;
let totalPdfPages = 0;
let currentConversationDocumentId = null;
let currentReplyTargetId = null;

// Document type display mapping
function getDocumentTypeDisplay(type) {
    const typeMap = {
        'saf': 'Student Activity Form',
        'publication': 'Publication',
        'proposal': 'Project Proposal',
        'facility': 'Facility Request',
        'communication': 'Communication',
        'material': 'Material Request'
    };
    return typeMap[type] || type;
}

function safeText(value, fallback = 'N/A') {
    if (value === null || value === undefined || value === '') return fallback;
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function shortenOfficeName(officeName) {
    const shortNames = {
        'Officer-in-Charge, Office of Student Affairs (OIC-OSA) Approval': 'OIC-OSA',
        'Vice President for Academic Affairs (VPAA) Approval': 'VPAA',
        'Executive Vice-President / Student Services (EVP) Approval': 'EVP',
        'Accounting Personnel (AP) (Documentation Only)': 'Accounting',
        'College Dean Approval': 'College Dean',
        'Document Creator Signature': 'Creator',
        'Supreme Student Council President Approval': 'SSC President',
        'College Student Council President Approval': 'CSC President',
        'College Student Council Adviser Approval': 'CSC Adviser',
        'Physical Plant and Facilities Office (PPFO) Approval': 'PPFO',
        'Center for Performing Arts Organization (CPAO) Approval': 'CPAO'
    };
    return shortNames[officeName] || officeName;
}

function formatStatusDisplay(status) {
    if (!status) return 'Unknown';
    const statusMap = {
        'submitted': 'Submitted',
        'pending': 'Pending',
        'in_progress': 'In Progress',
        'in_review': 'In Review',
        'under_review': 'Under Review',
        'completed': 'Completed',
        'approved': 'Approved',
        'rejected': 'Rejected',
        'timeout': 'Timeout',
        'cancelled': 'Cancelled',
        'deleted': 'Deleted'
    };
    return statusMap[status.toLowerCase()] || status.charAt(0).toUpperCase() + status.slice(1);
}

function showToast(message, type = 'info', title = null) {
    if (window.ToastManager) {
        window.ToastManager.show({ type: type, title: title, message: message, duration: 4000 });
    } else {
        // Toast: message
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.currentUser && document.getElementById('userDisplayName')) {
        document.getElementById('userDisplayName').textContent = `${window.currentUser.firstName} ${window.currentUser.lastName}`;
    }
    loadPreferences();
    initializeEventListeners();
    loadStudentDocuments();
    if (window.addAuditLog) {
        window.addAuditLog('TRACK_DOCUMENT_VIEWED', 'Document Management', 'Viewed document tracking page', null, 'Page', 'INFO');
    }
});

function initializeEventListeners() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.addEventListener('input', handleSearch);

    const filterButtons = document.querySelectorAll('input[name="statusFilter"]');
    filterButtons.forEach(button => {
        button.addEventListener('change', function () {
            if (this.checked) {
                // Remove active class from visual tab wrappers
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                this.nextElementSibling.classList.add('active');
                handleFilter();
            }
        });
    });

    const itemsPerPageSelect = document.getElementById('itemsPerPage');
    if (itemsPerPageSelect) {
        itemsPerPageSelect.addEventListener('change', (e) => {
            itemsPerPage = parseInt(e.target.value);
            currentPage = 1;
            renderCurrentPage();
        });
    }

    const sortableHeaders = document.querySelectorAll('.sortable');
    sortableHeaders.forEach(header => {
        header.addEventListener('click', () => {
            const sortField = header.getAttribute('data-sort');
            handleSort(sortField);
        });
    });
}

let searchTimeout;
function handleSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => { applyCurrentFilters(); }, 300);
}

function handleFilter() {
    applyCurrentFilters();
}

function showLoadingState() {
    document.getElementById('loadingState').style.display = 'block';
    document.getElementById('emptyState').style.display = 'none';
    document.querySelector('.table-wrapper').style.display = 'none';
    document.getElementById('paginationContainer').style.display = 'none';
}

function hideLoadingState() {
    document.getElementById('loadingState').style.display = 'none';
    document.querySelector('.table-wrapper').style.display = 'block';
}

function showEmptyState() {
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('emptyState').style.display = 'block';
    document.querySelector('.table-wrapper').style.display = 'none';
    document.getElementById('paginationContainer').style.display = 'none';
}

async function loadStudentDocuments() {
    showLoadingState();
    try {
        const response = await fetch(BASE_URL + 'api/documents.php?action=my_documents&t=' + Date.now(), {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        });
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        const data = await response.json();

        if (data.success) {
            // Filter out null/undefined documents to prevent errors
            allDocuments = (data.documents || []).filter(doc => doc != null && typeof doc === 'object');
            calculateStatistics();
            updateStatisticsDisplay();
            applyCurrentFilters();
            hideLoadingState();
            if (allDocuments.length === 0) showEmptyState();
        } else {
            showToast(data.message || 'Error loading documents', 'error');
            showEmptyState();
        }
    } catch (error) {
        hideLoadingState();
        showToast('Failed to load documents: ' + error.message, 'error');
        showEmptyState();
    }
}

function refreshDocuments() {
    loadStudentDocuments();
}

function calculateStatistics() {
    documentStats = { total: allDocuments.length, pending: 0, approved: 0, inProgress: 0, underReview: 0 };
    allDocuments.forEach(doc => {
        const status = doc.status.toLowerCase();
        if (status.includes('pending') || status.includes('submitted')) documentStats.pending++;
        else if (status.includes('completed') || status.includes('approved')) documentStats.approved++;
        else if (status.includes('progress') || status.includes('in_review')) documentStats.inProgress++;
        else if (status.includes('review')) documentStats.underReview++;
    });
}

function updateStatisticsDisplay() {
    if (document.getElementById('totalDocuments')) document.getElementById('totalDocuments').textContent = documentStats.total;
    if (document.getElementById('pendingDocuments')) document.getElementById('pendingDocuments').textContent = documentStats.pending;
    if (document.getElementById('approvedDocuments')) document.getElementById('approvedDocuments').textContent = documentStats.approved;
    if (document.getElementById('inProgressDocuments')) document.getElementById('inProgressDocuments').textContent = documentStats.inProgress;
}

// Master CSS pure utility replacements
function getStatusBadgeClass(status) {
    switch (status.toLowerCase()) {
        case 'submitted': return 'badge-secondary';
        case 'pending': return 'badge-warning';
        case 'in progress':
        case 'in_progress': return 'badge-info';
        case 'under review':
        case 'under_review': return 'badge-primary';
        case 'completed':
        case 'approved': return 'badge-success';
        case 'rejected': return 'badge-danger';
        case 'timeout': return 'badge-secondary';
        case 'deleted': return 'badge-danger';
        default: return 'badge-light';
    }
}

function getLocationBadgeClass(location) {
    if (!location) return 'badge-secondary';
    switch (location.toLowerCase()) {
        case 'finance office':
        case 'cpa office':
        case 'accounting': return 'badge-warning';
        case 'student affairs':
        case 'osa':
        case 'oic osa': return 'badge-primary';
        case 'student council':
        case 'ssc': return 'badge-info';
        case 'dean\'s office':
        case 'college dean': return 'badge-success';
        case 'approved': return 'badge-success';
        default: return 'badge-secondary';
    }
}

function getDocTypeBadgeClass(docType) {
    if (!docType) return 'badge-secondary';
    switch (docType.toLowerCase()) {
        case 'saf': return 'badge-info';
        case 'proposal': return 'badge-primary';
        case 'communication': return 'badge-success';
        case 'material': return 'badge-warning';
        case 'publication': return 'badge-secondary';
        default: return 'badge-secondary';
    }
}

function renderCurrentPage() {
    const tbody = document.getElementById('documentsList');
    const paginationContainer = document.getElementById('paginationContainer');

    tbody.innerHTML = '';

    if (filteredDocuments.length === 0) {
        showEmptyState();
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.querySelector('.table-wrapper').style.display = 'block';

    const totalPages = Math.ceil(totalDocuments / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = Math.min(startIndex + itemsPerPage, totalDocuments);
    const currentPageDocuments = filteredDocuments.slice(startIndex, endIndex);

    currentPageDocuments.forEach((doc) => {
        const row = document.createElement('tr');
        const docType = getDocumentTypeDisplay(doc.document_type || doc.doc_type);
        const createdDate = doc.created_at ? new Date(doc.created_at).toLocaleDateString() : 'N/A';

        row.innerHTML = `
            <td>
                <div class="d-flex align-items-center gap-3">
                    <div class="btn-icon sm bg-primary-subtle text-primary border-0"><i class="bi ${doc.is_material ? 'bi-image' : 'bi-file-text'}"></i></div>
                    <div>
                        <div class="fw-bold text-dark">${safeText(doc.title || doc.document_name)}</div>
                        <div class="text-xs text-muted mt-1">Created: ${createdDate}</div>
                    </div>
                </div>
            </td>
            <td><span class="badge ${getDocTypeBadgeClass(doc.doc_type)}">${safeText(docType)}</span></td>
            <td><span class="badge ${getStatusBadgeClass(doc.status || doc.current_status)}">${formatStatusDisplay(doc.status || doc.current_status)}</span></td>
            <td><span class="badge ${getLocationBadgeClass(doc.current_location)}">${shortenOfficeName(doc.current_location)}</span></td>
            <td>
                <div class="text-sm fw-medium">${new Date(doc.updated_at).toLocaleDateString()}</div>
                <div class="text-xs text-muted">${new Date(doc.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
            </td>
            <td><div class="text-xs text-muted truncate" style="max-width: 150px;">
    ${doc.is_material ? getMaterialNotesPreview(doc) : getNotesPreview(doc.notes || [])}
</div></td>
            <td class="text-end">
                <button class="btn btn-ghost btn-sm rounded-pill px-3 me-1" onclick="viewDetails('${doc.id}')"><i class="bi bi-eye"></i></button>
                <button class="btn btn-ghost btn-icon sm rounded-pill" onclick="downloadDocument('${doc.id}')" title="Download"><i class="bi bi-download"></i></button>
            </td>
        `;
        tbody.appendChild(row);
    });

    renderPagination(totalPages);
    updatePaginationInfo(startIndex + 1, endIndex, totalDocuments);
    paginationContainer.style.display = totalPages > 1 ? 'flex' : 'none';
}

function renderPagination(totalPages) {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    if (totalPages <= 1) return;

    const prevItem = document.createElement('li');
    prevItem.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
    prevItem.innerHTML = `<a class="page-link" href="#" onclick="changePage(${currentPage - 1})"><i class="bi bi-chevron-left"></i></a>`;
    pagination.appendChild(prevItem);

    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    if (endPage - startPage + 1 < maxVisiblePages) startPage = Math.max(1, endPage - maxVisiblePages + 1);

    for (let i = startPage; i <= endPage; i++) {
        const pageItem = document.createElement('li');
        pageItem.className = `page-item ${i === currentPage ? 'active' : ''}`;
        pageItem.innerHTML = `<a class="page-link" href="#" onclick="changePage(${i})">${i}</a>`;
        pagination.appendChild(pageItem);
    }

    const nextItem = document.createElement('li');
    nextItem.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
    nextItem.innerHTML = `<a class="page-link" href="#" onclick="changePage(${currentPage + 1})"><i class="bi bi-chevron-right"></i></a>`;
    pagination.appendChild(nextItem);
}

function changePage(page) {
    const totalPages = Math.ceil(totalDocuments / itemsPerPage);
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    renderCurrentPage();
}

function downloadDocument(docId) {
    showToast('Preparing download...', 'info');
    fetch(BASE_URL + `api/documents.php?action=document_details&id=${docId}`, {
        method: 'GET', headers: { 'Content-Type': 'application/json' }
    }).then(r => r.json()).then(data => {
        if (data.success && data.document && data.document.file_path) {
            let filePath = data.document.file_path;
            if (filePath.startsWith('/')) filePath = BASE_URL + filePath.substring(1);
            else if (filePath.startsWith('../')) filePath = BASE_URL + filePath.substring(3);
            else if (!filePath.startsWith('http') && !filePath.startsWith('uploads/')) filePath = BASE_URL + 'uploads/' + filePath;

            const link = document.createElement('a');
            link.href = filePath;
            link.download = (data.document.document_name || data.document.title || 'Document') + '.pdf';
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } else throw new Error('File not found');
    }).catch(error => { showToast('Failed to download document', 'error'); });
}

function updatePaginationInfo(start, end, total) {
    if (document.getElementById('paginationInfo')) document.getElementById('paginationInfo').textContent = `Showing ${start} to ${end} of ${total} documents`;
}

function handleSort(field) {
    if (currentSortField === field) currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
    else { currentSortField = field; currentSortDirection = 'asc'; }
    sortDocuments();
    renderCurrentPage();
}

function sortDocuments() {
    filteredDocuments.sort((a, b) => {
        // Ensure both documents are valid objects
        if (!a || typeof a !== 'object') return 1;
        if (!b || typeof b !== 'object') return -1;
        
        let aValue = a[currentSortField] || a.document_name || a.doc_type || a.status || '';
        let bValue = b[currentSortField] || b.document_name || b.doc_type || b.status || '';
        if (currentSortField.includes('_at') || currentSortField.includes('date')) {
            aValue = new Date(aValue); bValue = new Date(bValue);
            if (isNaN(aValue.getTime())) aValue = new Date(0);
            if (isNaN(bValue.getTime())) bValue = new Date(0);
        }
        if (typeof aValue === 'string') { aValue = aValue.toLowerCase(); bValue = bValue.toLowerCase(); }
        if (aValue < bValue) return currentSortDirection === 'asc' ? -1 : 1;
        if (aValue > bValue) return currentSortDirection === 'asc' ? 1 : -1;
        return 0;
    });
}

function applyCurrentFilters() {
    let documents = [...allDocuments];
    const searchTerm = document.getElementById('searchInput')?.value.toLowerCase().trim();
    if (searchTerm) {
        documents = documents.filter(doc => {
            // Ensure doc is valid object
            if (!doc || typeof doc !== 'object') return false;
            return [doc.title || doc.document_name, doc.status || doc.current_status, doc.current_location, doc.document_type || doc.doc_type].some(field => {
                if (field == null) return false;
                return field.toString().toLowerCase().includes(searchTerm);
            });
        });
    }
    const activeFilter = document.querySelector('input[name="statusFilter"]:checked')?.id;
    if (activeFilter && activeFilter !== 'filterAll') {
        const statusMap = {
            'filterPending': ['submitted', 'pending', 'in progress', 'in_progress', 'under review', 'under_review'],
            'filterApproved': ['approved', 'completed'],
            'filterRejected': ['rejected']
        };
        const filterStatuses = statusMap[activeFilter];
        if (filterStatuses) documents = documents.filter(doc => filterStatuses.includes((doc.status || doc.current_status || '').toLowerCase()));
    }
    filteredDocuments = documents;
    totalDocuments = filteredDocuments.length;
    currentPage = 1;
    sortDocuments();
    updateResultsCount();
    renderCurrentPage();
}

function updateResultsCount() {
    const resultsEl = document.getElementById('resultsCount');
    if (resultsEl) resultsEl.innerHTML = `<i class="bi bi-info-circle me-1"></i> Showing ${totalDocuments} of ${allDocuments.length} documents`;
    if (document.getElementById('lastUpdatedTime')) document.getElementById('lastUpdatedTime').textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function clearFilters() {
    if (document.getElementById('searchInput')) document.getElementById('searchInput').value = '';
    const allFilter = document.getElementById('filterAll');
    if (allFilter) {
        allFilter.checked = true;
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
        allFilter.nextElementSibling.classList.add('active');
    }
    currentSortField = 'updated_at'; currentSortDirection = 'desc';
    applyCurrentFilters();
}

function getNotesPreview(notes) {
    if (!notes || notes.length === 0) return 'No notes';
    const recentNote = notes.find(n => n.is_rejection) || notes.find(n => n.comment && n.comment.includes('Auto-timeout')) || notes[notes.length - 1];
    if (!recentNote) return 'No notes';
    const noteText = recentNote.comment || recentNote.note || '';
    return noteText.replace(/"/g, '&quot;');
}

function getMaterialNotesPreview(material) {
    if (!material.notes || material.notes.length === 0) return 'No comments';
    const recentNote = material.notes[material.notes.length - 1];
    if (!recentNote) return 'No comments';
    const noteText = recentNote.comment || recentNote.note || '';
    return `${recentNote.author_name || 'Unknown'}: ${noteText.substring(0, 30)}${noteText.length > 30 ? '...' : ''}`;
}

/* ── MODAL HTML INJECTION REWRITE (Pure OneUI) ────────────────────────────────── */

async function viewDetails(docId) {
    // Check if this is a material (has MAT- prefix)
    const isMaterial = docId.toString().startsWith('MAT-');
    const originalId = isMaterial ? docId.toString().replace('MAT-', '') : docId;
    
    if (isMaterial) {
        // Handle material view
        viewMaterialDetails(originalId);
        return;
    }
    
    const modal = new bootstrap.Modal(document.getElementById('documentModal'));
    const modalTitle = document.getElementById('documentModalTitle');
    const modalBody = document.getElementById('documentModalBody');

    try {
        const response = await fetch(BASE_URL + `api/documents.php?action=document_details&id=${docId}`);
        if (!response.ok) throw new Error('Document not found');
        const data = await response.json();

        if (data.success && data.document) {
            const doc = data.document;
            window.currentDocument = doc;
            const docType = getDocumentTypeDisplay(doc.document_type || doc.doc_type || 'unknown');

            modalTitle.innerHTML = `<i class="bi bi-file-earmark-text text-primary me-2"></i>${safeText(doc.document_name || doc.title)}`;
            document.getElementById('documentModalMeta').innerHTML = `<i class="bi bi-folder2 me-1"></i> ${safeText(docType)} • Created ${new Date(doc.created_at).toLocaleDateString()}`;

            let pdfHtml = '';
            if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                pdfHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-header bg-transparent pb-0 border-0">
                            <h6 class="m-0 fw-bold"><i class="bi bi-file-pdf text-danger me-2"></i>Document Preview</h6>
                        </div>
                        <div class="card-body p-0">
                            <div id="pdfViewer" class="bg-surface-sunken border rounded-lg d-flex align-items-start justify-content-center overflow-visible position-relative" style="min-height: 450px; padding: 1rem; width: 100%;">
                                <canvas id="pdfCanvas" class="shadow-sm bg-white"></canvas>
                            </div>
                            <div id="pdfPageControls" class="d-flex justify-content-between align-items-center mt-3 bg-surface border p-2 rounded-lg shadow-xs">
                                <button id="prevPage" class="btn btn-sm btn-ghost rounded-pill" disabled><i class="bi bi-chevron-left me-1"></i> Prev</button>
                                <span id="pageInfo" class="badge badge-primary px-3 py-2 text-xs rounded-pill">Page 1 of 1</span>
                                <button id="nextPage" class="btn btn-sm btn-ghost rounded-pill" disabled>Next <i class="bi bi-chevron-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                `;
            }

            let infoHtml = `
                <div class="card card-flat mb-3 shadow-none">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-xs text-muted fw-semibold">Status</span>
                            <span class="badge ${getStatusBadgeClass(doc.status)}">${formatStatusDisplay(doc.status)}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-xs text-muted fw-semibold">Location</span>
                            <span class="badge ${getLocationBadgeClass(doc.current_location)}">${shortenOfficeName(doc.current_location)}</span>
                        </div>
                    </div>
                </div>
            `;

            // Schedule information for proposal documents
            let scheduleHtml = '';
            if ((doc.document_type === 'proposal' || doc.doc_type === 'proposal') && doc.schedule && Array.isArray(doc.schedule) && doc.schedule.length > 0) {
                scheduleHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-header bg-transparent pb-2 border-bottom">
                            <h6 class="m-0 fw-bold"><i class="bi bi-calendar-event text-warning me-2"></i>Event Schedule</h6>
                        </div>
                        <div class="card-body p-3">
                            <div class="schedule-list">
                                ${doc.schedule.map(item => `
                                    <div class="schedule-item d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-calendar-date text-primary"></i>
                                            <span class="text-sm fw-medium">${new Date(item.date + 'T' + item.time).toLocaleDateString('en-US', {
                                                weekday: 'long',
                                                year: 'numeric',
                                                month: 'long',
                                                day: 'numeric'
                                            })}</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-clock text-info"></i>
                                            <span class="text-sm text-muted">${new Date('1970-01-01T' + item.time).toLocaleTimeString('en-US', {
                                                hour: 'numeric',
                                                minute: '2-digit',
                                                hour12: true
                                            })}</span>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                            <div class="text-xs text-muted mt-2">
                                <i class="bi bi-info-circle me-1"></i> These events will be created in the calendar when the document is approved.
                            </div>
                        </div>
                    </div>
                `;
            }

            let systemHtml = '';
            if (doc.notes && doc.notes.find(n => n.comment.includes('Auto-timeout'))) {
                const sysNote = doc.notes.find(n => n.comment.includes('Auto-timeout'));
                systemHtml = `<div class="alert alert-warning p-3 rounded-xl mb-3"><i class="bi bi-clock me-2"></i><strong>System Alert:</strong> ${sysNote.comment}</div>`;
            }

            let rejectionHtml = '';
            if (doc.notes && doc.notes.find(n => n.is_rejection)) {
                const rejNote = doc.notes.find(n => n.is_rejection);
                rejectionHtml = `
                    <div class="alert alert-danger p-3 rounded-xl mb-3 flex-col gap-2">
                        <div class="fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Rejection Reason</div>
                        <div class="text-sm px-2 bg-white rounded-md p-2 mt-1 shadow-xs border text-dark">${rejNote.comment}</div>
                        <div class="text-2xs text-muted text-end mt-1">${rejNote.created_by_name} • ${new Date(rejNote.created_at).toLocaleDateString()}</div>
                    </div>
                `;
            }


            // --- Improved Semantic Timeline Rendering ---
            let timelineHtml = `
    <div class="card card-flat mb-3 shadow-none">
        <div class="card-header bg-transparent pb-0 border-0">
            <h6 class="m-0 fw-bold"><i class="bi bi-clock-history text-info me-2"></i>Timeline</h6>
        </div>
        <div class="card-body px-4 pb-4">
            <div class="timeline-container">
`;
            if (doc.workflow_history && doc.workflow_history.length > 0) {
                // Find the current step: first pending/in_progress/in_review/under_review, or last approved if all approved
                let currentStepIdx = -1;
                for (let i = 0; i < doc.workflow_history.length; i++) {
                    const status = (doc.workflow_history[i].status || '').toLowerCase();
                    if (
                        status === 'pending' || status === 'in_progress' ||
                        status === 'in review' || status === 'in_review' ||
                        status === 'under_review' || status === 'under review'
                    ) {
                        currentStepIdx = i;
                        break;
                    }
                    if (status === 'rejected') {
                        currentStepIdx = i; // If rejected, mark as current for red pulse
                        break;
                    }
                }
                if (currentStepIdx === -1) {
                    // All approved/completed
                    for (let i = doc.workflow_history.length - 1; i >= 0; i--) {
                        const status = (doc.workflow_history[i].status || '').toLowerCase();
                        if (status === 'approved' || status === 'completed') {
                            currentStepIdx = i;
                            break;
                        }
                    }
                }

                doc.workflow_history.forEach((item, index) => {
                    const isLast = index === doc.workflow_history.length - 1;
                    const status = (item.status || '').toLowerCase();
                    let stepClass = '';
                    let markerClass = '';
                    let textClass = '';
                    let icon = '';
                    let pulse = '';

                    if (status === 'rejected') {
                        stepClass = 'timeline-step-rejected';
                        markerClass = 'bg-danger';
                        textClass = 'text-danger fw-semibold';
                        icon = 'bi-x-circle-fill';
                        pulse = (index === currentStepIdx) ? 'timeline-marker-pulse-red' : '';
                    } else if (index < currentStepIdx) {
                        // Approved steps before current
                        stepClass = 'timeline-step-approved';
                        markerClass = 'bg-success';
                        textClass = 'text-success fw-semibold';
                        icon = 'bi-check-circle-fill';
                    } else if (index === currentStepIdx) {
                        // Current step (pending/in review/under review)
                        if (
                            status === 'pending' || status === 'in_progress' ||
                            status === 'in review' || status === 'in_review' ||
                            status === 'under_review' || status === 'under review'
                        ) {
                            stepClass = 'timeline-step-current';
                            markerClass = 'bg-primary';
                            textClass = 'text-primary fw-semibold';
                            icon = 'bi-hourglass-split';
                            pulse = 'timeline-marker-pulse-blue';
                        } else if (status === 'approved' || status === 'completed') {
                            stepClass = 'timeline-step-approved';
                            markerClass = 'bg-success';
                            textClass = 'text-success fw-semibold';
                            icon = 'bi-check-circle-fill';
                        } else {
                            // fallback
                            stepClass = 'timeline-step-default';
                            markerClass = 'bg-secondary';
                            textClass = 'text-muted';
                            icon = 'bi-circle';
                        }
                    } else {
                        // Pending steps after current
                        stepClass = 'timeline-step-pending';
                        markerClass = 'bg-secondary';
                        textClass = 'text-muted';
                        icon = 'bi-circle';
                    }

                    timelineHtml += `
                        <div class="timeline-item ${isLast ? 'last' : ''} ${stepClass}">
                            <div class="timeline-marker ${markerClass} ${pulse}">
                                <i class="bi ${icon}"></i>
                            </div>
                            <div class="timeline-content bg-surface-raised border rounded-lg p-3 shadow-xs">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div>
                                        <div class="text-sm fw-semibold ${textClass}">
                                            ${item.action || 'Status Update'}
                                        </div>
                                        <div class="text-2xs ${textClass === 'text-muted' ? 'text-muted' : textClass}">
                                            ${shortenOfficeName(item.office_name || item.from_office || 'Unknown office')}
                                        </div>
                                    </div>
                                    <div class="text-2xs text-muted">
                                        ${new Date(item.created_at).toLocaleDateString()} 
                                        ${new Date(item.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}
                                    </div>
                                </div>
                                ${item.note || item.comment ? `
                                    <div class="text-xs mt-2 pt-2 border-top">
                                        ${safeText(item.note || item.comment)}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                });
            } else {
                timelineHtml += `
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-clock-history fs-3 opacity-50"></i>
                        <p class="mt-2 mb-0">No timeline events recorded yet</p>
                    </div>
                `;
            }
            timelineHtml += `</div></div></div>`;

            const conversationHtml = `
                <div class="card card-flat mb-3 shadow-none">
                    <div class="card-header bg-transparent pb-2 border-bottom">
                        <h6 class="m-0 fw-bold"><i class="bi bi-chat-dots text-success me-2"></i>Conversation</h6>
                    </div>
                    <div class="card-body p-3">
                        <div id="conversationThread" class="conversation-thread mb-3"></div>
                        <div id="conversationReplyBanner" class="alert alert-info py-2 px-3 d-flex justify-content-between align-items-center" style="display:none;">
                            <span class="text-xs">Replying to <strong id="conversationReplyAuthor"></strong></span>
                            <button type="button" class="btn-close ms-2" id="cancelConversationReply"></button>
                        </div>
                        <div class="conversation-input-wrap">
                            <textarea id="conversationInput" class="form-control sm mb-2" rows="2" placeholder="Write a comment..."></textarea>
                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-primary btn-sm rounded-pill" id="sendConversationBtn">Reply</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            modalBody.innerHTML = `
                <div class="row g-3">
                    <div class="col-lg-7">${pdfHtml}</div>
                    <div class="col-lg-5">${infoHtml}${scheduleHtml}${systemHtml}${rejectionHtml}${conversationHtml}${timelineHtml}</div>
                </div>
            `;

            currentConversationDocumentId = Number(doc.id);
            currentReplyTargetId = null;

            document.getElementById('sendConversationBtn')?.addEventListener('click', postConversationReply);
            document.getElementById('cancelConversationReply')?.addEventListener('click', clearReplyTarget);
            loadConversationThread(currentConversationDocumentId);

            const dlBtn = document.getElementById('downloadDocumentBtn');
            if (dlBtn && doc.file_path && (doc.status === 'approved' || doc.status === 'rejected')) {
                dlBtn.style.display = 'inline-flex';
                dlBtn.onclick = () => downloadDocument(doc.id);
            } else if (dlBtn) dlBtn.style.display = 'none';

            if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                let pdfUrl = doc.file_path;
                if (!pdfUrl.startsWith('http') && !pdfUrl.startsWith('upload')) pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
                window.pendingPdfLoad = { url: pdfUrl, doc: doc };
            }
            modal.show();

            const modalEl = document.getElementById('documentModal');
            modalEl.addEventListener('shown.bs.modal', async function handler() {
                modalEl.removeEventListener('shown.bs.modal', handler);
                if (window.pendingPdfLoad) {
                    await loadPdfInModal(window.pendingPdfLoad.url, window.pendingPdfLoad.doc);
                    delete window.pendingPdfLoad;
                }
            });
        }
    } catch (error) {
        modalBody.innerHTML = `<div class="alert alert-danger">Failed to load document details.</div>`;
        modal.show();
    }
}

async function viewMaterialDetails(materialId) {
    currentMaterialId = normalizeMaterialId(materialId) || materialId;
    
    const modal = new bootstrap.Modal(document.getElementById('materialModal'));
    const modalTitle = document.getElementById('materialModalTitle');
    const modalBody = document.getElementById('materialModalBody');
    
    try {
        const response = await fetch(
            BASE_URL + `api/materials.php?action=get_material_details&id=${encodeURIComponent(currentMaterialId)}&t=${Date.now()}`
        );
        if (!response.ok) throw new Error('Material not found');
        const data = await response.json();
        
        if (data.success && data.material) {
            const material = data.material;
            window.currentMaterial = material;
            
            modalTitle.innerHTML = `<i class="bi bi-image text-primary me-2"></i>${safeText(material.title)}`;
            document.getElementById('materialModalMeta').innerHTML = `<i class="bi bi-person me-1"></i> ${safeText(material.creator_name)} • ${safeText(material.department)} • Created ${new Date(material.uploaded_at).toLocaleDateString()}`;
            
            // Determine file preview type
            const isImage = material.file_type && material.file_type.startsWith('image/');
            const isPDF = material.file_type === 'application/pdf';
            
            let previewHtml = '';
            let fileUrl = '';
            
            // ===== FIXED FILE PATH HANDLING =====
            if (material.file_path) {
                // Use the API endpoint for serving images (more secure)
                fileUrl = BASE_URL + `api/materials.php?action=serve_image&id=${materialId}`;
                
            }
            
            if (isImage) {
                previewHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-header bg-transparent pb-0 border-0">
                            <h6 class="m-0 fw-bold"><i class="bi bi-image text-primary me-2"></i>Publication Material Preview</h6>
                        </div>
                        <div class="card-body p-0 text-center">
                            <img src="${fileUrl}" class="img-fluid rounded-3 shadow-sm" style="max-height: 70vh; object-fit: contain;" alt="Publication Material"
                                 onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'alert alert-warning m-3\'>Image failed to load. <button class=\'btn btn-sm btn-primary ms-2\' onclick=\'downloadMaterial(${materialId}, \\'${material.file_path}\\', \\'${material.title}\\')\'>Download</button></div>';">
                        </div>
                    </div>
                `;
            } else if (isPDF) {
                previewHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-header bg-transparent pb-0 border-0">
                            <h6 class="m-0 fw-bold"><i class="bi bi-file-pdf text-danger me-2"></i>PDF Preview</h6>
                        </div>
                        <div class="card-body p-0">
                            <iframe src="${fileUrl}" class="w-100 rounded-3 shadow-sm border-0" style="height: 70vh;"></iframe>
                        </div>
                    </div>
                `;
            } else {
                previewHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-file-earmark text-muted" style="font-size: 4rem;"></i>
                            <p class="text-muted mt-3">Preview not available for this file type.</p>
                            <button class="btn btn-primary btn-sm rounded-pill mt-2" onclick="downloadMaterial(${materialId}, '${material.file_path}', '${material.title}')">
                                <i class="bi bi-download me-2"></i>Download File
                            </button>
                        </div>
                    </div>
                `;
            }
            
            // Status and location info
            let infoHtml = `
                <div class="card card-flat mb-3 shadow-none">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-xs text-muted fw-semibold">Status</span>
                            <span class="badge ${getMaterialStatusBadgeClass(material.status)}">${formatStatusDisplay(material.status)}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-xs text-muted fw-semibold">Current Office</span>
                            <span class="badge ${getMaterialLocationBadgeClass(material.current_location || 'Pending')}">${shortenOfficeName(material.current_location || 'Pending')}</span>
                        </div>
                        ${material.description ? `
                        <div class="mt-3 pt-2 border-top">
                            <div class="text-xs text-muted fw-semibold mb-1">Description</div>
                            <div class="text-sm">${safeText(material.description)}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            // Timeline/Workflow
            let timelineHtml = `
                <div class="card card-flat mb-3 shadow-none">
                    <div class="card-header bg-transparent pb-0 border-0">
                        <h6 class="m-0 fw-bold"><i class="bi bi-clock-history text-info me-2"></i>Approval Timeline</h6>
                    </div>
                    <div class="card-body px-4">
                        <div class="timeline">
            `;
            
            if (material.workflow_history && material.workflow_history.length > 0) {
                material.workflow_history.forEach(item => {
                    const actionClass = item.action === 'Approved' ? 'bg-success' : (item.action === 'Rejected' ? 'bg-danger' : 'bg-warning');
                    timelineHtml += `
                        <div class="timeline-item pb-3 position-relative">
                            <div class="timeline-marker position-absolute rounded-full border border-2 border-white ${actionClass} shadow-xs" style="left: -23px; top: 3px; width: 12px; height: 12px;"></div>
                            <div class="bg-surface-raised border rounded-lg p-2 shadow-xs">
                                <div class="text-2xs text-muted fw-semibold mb-1">${new Date(item.created_at).toLocaleDateString()}</div>
                                <div class="text-xs fw-semibold text-dark">${item.action} • ${shortenOfficeName(item.office_name)}</div>
                                ${item.note ? `<div class="text-2xs text-muted mt-1">${safeText(item.note)}</div>` : ''}
                            </div>
                        </div>
                    `;
                });
            } else {
                timelineHtml += `<div class="text-muted text-sm py-2">No workflow history available</div>`;
            }
            timelineHtml += `</div></div></div>`;
            
            // Comments/Conversation section
            const conversationHtml = `
                <div class="card card-flat mb-3 shadow-none">
                    <div class="card-header bg-transparent pb-2 border-bottom">
                        <h6 class="m-0 fw-bold"><i class="bi bi-chat-dots text-success me-2"></i>Comments</h6>
                    </div>
                    <div class="card-body p-3">
                        <div id="materialConversationThread" class="conversation-thread mb-3"></div>
                        <div id="materialReplyBanner" class="alert alert-info py-2 px-3 d-flex justify-content-between align-items-center" style="display:none;">
                            <span class="text-xs">Replying to <strong id="materialReplyAuthor"></strong></span>
                            <button type="button" class="btn-close ms-2" id="cancelMaterialReply"></button>
                        </div>
                        <div class="conversation-input-wrap">
                            <textarea id="materialCommentInput" class="form-control sm mb-2" rows="2" placeholder="Write a comment..."></textarea>
                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-primary btn-sm rounded-pill" id="sendMaterialCommentBtn">Post Comment</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Assemble modal content
            modalBody.innerHTML = `
                <div class="row g-3">
                    <div class="col-lg-7">${previewHtml}</div>
                    <div class="col-lg-5">
                        ${infoHtml}
                        ${timelineHtml}
                        ${conversationHtml}
                    </div>
                </div>
            `;
            
            // Setup comment functionality - MAKE SURE THESE ARE SET
            currentMaterialId = materialId;  // Set again to be sure
            currentMaterialReplyTarget = null;
            
            document.getElementById('sendMaterialCommentBtn')?.addEventListener('click', postMaterialComment);
            document.getElementById('cancelMaterialReply')?.addEventListener('click', clearMaterialReplyTarget);
            
            // Load comments
            loadMaterialComments(materialId);
            
            // Setup download button
            const dlBtn = document.getElementById('downloadMaterialBtn');
            if (dlBtn && material.file_path) {
                dlBtn.style.display = 'inline-flex';
                dlBtn.onclick = () => downloadMaterial(materialId, material.file_path, material.title);
            }
            
            modal.show();
        }
    } catch (error) {
        modalBody.innerHTML = `<div class="alert alert-danger">Failed to load material details.</div>`;
        modal.show();
    }
}

// Material comment functionality
let currentMaterialId = null;
let currentMaterialReplyTarget = null;

function normalizeMaterialId(id) {
    if (id == null) return null;

    let value = String(id).trim();
    if (!value) return null;

    if (value.toUpperCase().startsWith('MAT-')) {
        value = value.substring(4).trim();
    }

    const matMatch = value.match(/^MAT(\d+)$/i);
    if (matMatch) return `MAT${matMatch[1]}`;

    if (/^\d+$/.test(value)) return `MAT${value.padStart(3, '0')}`;

    return null;
}

function clearMaterialReplyTarget() {
    currentMaterialReplyTarget = null;
    document.getElementById('materialReplyBanner').style.display = 'none';
}

function setMaterialReplyTarget(commentId, authorName) {
    currentMaterialReplyTarget = Number(commentId);
    document.getElementById('materialReplyAuthor').textContent = authorName || 'comment';
    document.getElementById('materialReplyBanner').style.display = 'flex';
    document.getElementById('materialCommentInput').focus();
}

async function loadMaterialComments(materialId) {
    try {
        const response = await fetch(BASE_URL + `api/materials.php?action=get_comments&id=${materialId}`);
        const data = await response.json();
        
        if (data.success) {
            renderMaterialComments(data.comments || []);
        }
    } catch (error) {
        // Error loading comments
    }
}

function renderMaterialComments(comments) {
    const thread = document.getElementById('materialConversationThread');
    if (!thread) return;
    
    if (!Array.isArray(comments) || comments.length === 0) {
        thread.innerHTML = '<div class="text-center text-muted p-3 border border-dashed rounded-lg">No comments yet.</div>';
        return;
    }
    
    const byParent = comments.reduce((acc, item) => {
        const key = item.parent_id == null ? 'root' : String(item.parent_id);
        if (!acc[key]) acc[key] = [];
        acc[key].push(item);
        return acc;
    }, {});
    
    const renderNodes = (parentKey, depth = 0) => {
        const nodes = byParent[parentKey] || [];
        return nodes.map((item) => `
            <div class="conversation-item mb-2 depth-${Math.min(depth, 4)}">
                <div class="bg-white border rounded-lg p-2 shadow-xs">
                    <div class="d-flex gap-2 text-xs mb-1">
                        <strong class="text-dark">${safeText(item.author_name)}</strong>
                        <span class="text-muted">${safeText(item.author_role)}${item.author_position ? ` • ${item.author_position}` : ''}</span>
                    </div>
                    <div class="text-sm text-dark mb-1">${safeText(item.comment).replace(/\n/g, '<br>')}</div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted" style="font-size:10px;">${formatDate(item.created_at)}</small>
                        <button type="button" class="btn btn-link text-decoration-none p-0 text-xs" onclick="setMaterialReplyTarget(${item.id}, '${safeText(item.author_name)}')">Reply</button>
                    </div>
                </div>
                ${renderNodes(String(item.id), depth + 1)}
            </div>
        `).join('');
    };
    
    thread.innerHTML = renderNodes('root');
}

async function postMaterialComment() {

    const materialId = normalizeMaterialId(currentMaterialId);

    if (!materialId) {
        showToast('No material selected', 'error');
        return;
    }
    
    const input = document.getElementById('materialCommentInput');
    if (!input) {
        return;
    }

    const comment = input.value.trim();
    if (!comment) {
        showToast('Please enter a comment', 'error');
        return;
    }
    
    const payload = {
        action: 'add_comment',
        material_id: materialId,
        comment: comment,
        parent_id: currentMaterialReplyTarget || null
    };
    
    try {
        const response = await fetch(BASE_URL + 'api/materials.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const data = await response.json();
        
        if (data.success) {
            input.value = '';
            clearMaterialReplyTarget();
            await loadMaterialComments(materialId);
            showToast('Comment posted successfully', 'success');
        } else {
            showToast('Failed to post comment: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        showToast('Error posting comment: ' + error.message, 'error');
    }
}

function downloadMaterial(materialId, filePath, title) {
    showToast('Preparing download...', 'info');
    
    // Extract just the filename from the full path
    let filename = filePath;
    if (filename.includes(':\\')) {
        filename = filename.split('\\').pop();
    }
    if (filename.includes('/')) {
        filename = filename.split('/').pop();
    }
    
    // Construct download URL using the API endpoint
    const downloadUrl = BASE_URL + `api/materials.php?download=1&id=${materialId}`;
    
    // Use the API endpoint for download instead of direct file access
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = title + '_pubmat.' + (filename.split('.').pop() || 'file');
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showToast('Download started', 'success');
}

async function loadMaterialComments(materialId) {
    const normalizedId = normalizeMaterialId(materialId);
    if (!normalizedId) {
        renderMaterialComments([]);
        return;
    }

    try {
        const response = await fetch(BASE_URL + `api/materials.php?action=get_comments&id=${encodeURIComponent(normalizedId)}`);
        const data = await response.json();

        if (data.success) {
            renderMaterialComments(data.comments || []);
        }
    } catch (error) {
        // Error loading comments silently ignored
    }
}

function getMaterialStatusBadgeClass(status) {
    switch (status.toLowerCase()) {
        case 'pending': return 'badge-warning';
        case 'approved': return 'badge-success';
        case 'rejected': return 'badge-danger';
        default: return 'badge-secondary';
    }
}

function getMaterialLocationBadgeClass(location) {
    if (location.toLowerCase().includes('adviser')) return 'badge-info';
    if (location.toLowerCase().includes('dean')) return 'badge-primary';
    if (location.toLowerCase().includes('osa')) return 'badge-success';
    if (location.toLowerCase().includes('completed')) return 'badge-success';
    return 'badge-secondary';
}

// PDF Viewer Core
async function loadPdfInModal(url, doc = null) {
    const canvas = document.getElementById('pdfCanvas');
    const pdfViewer = document.getElementById('pdfViewer');
    if (!canvas || !pdfViewer) return;

    try {
        pdfViewer.querySelectorAll('.completed-signature-container').forEach(el => el.remove());
        window.currentDocument = doc;

        const pdfjsLib = window['pdfjsLib'];
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        pdfDoc = await pdfjsLib.getDocument(url).promise;
        totalPdfPages = pdfDoc.numPages;
        currentPdfPage = 1;
        window.currentPdfPage = 1;

        updatePdfPageControls();
        await renderPdfPage(1);
    } catch (error) {
        pdfViewer.innerHTML = '<p class="text-danger p-3">Failed to load PDF preview</p>';
    }
}

async function renderPdfPage(pageNum) {
    if (!pdfDoc) return;
    const canvas = document.getElementById('pdfCanvas');
    const pdfViewer = document.getElementById('pdfViewer');
    if (!canvas || !pdfViewer) return;

    try {
        const page = await pdfDoc.getPage(pageNum);
        const containerWidth = pdfViewer.clientWidth - 32; // 1rem padding per side
        const viewport = page.getViewport({ scale: 1.0 });
        const devicePixelRatio = window.devicePixelRatio || 1;
        const scale = (containerWidth / viewport.width) * devicePixelRatio;
        const scaledViewport = page.getViewport({ scale: scale });

        canvas.width = scaledViewport.width;
        canvas.height = scaledViewport.height;
        canvas.style.width = containerWidth + 'px';
        canvas.style.height = (scaledViewport.height / devicePixelRatio) + 'px';

        const renderContext = {
            canvasContext: canvas.getContext('2d'),
            viewport: scaledViewport
        };

        await page.render(renderContext).promise;
        window.currentPdfPage = pageNum;

        if (window.currentDocument) {
            pdfViewer.querySelectorAll('.completed-signature-container').forEach(el => el.remove());
            renderCompletedSignatures(window.currentDocument, pdfViewer);
        }
    } catch (error) {
        // Error rendering PDF silently ignored
    }
}

function updatePdfPageControls() {
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    if (prevBtn) prevBtn.disabled = currentPdfPage <= 1;
    if (nextBtn) nextBtn.disabled = currentPdfPage >= totalPdfPages;
    if (document.getElementById('pageInfo')) document.getElementById('pageInfo').textContent = `Page ${currentPdfPage} of ${totalPdfPages}`;

    if (prevBtn) {
        const newPrev = prevBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrev, prevBtn);
        newPrev.addEventListener('click', async () => { if (currentPdfPage > 1) { currentPdfPage--; await renderPdfPage(currentPdfPage); updatePdfPageControls(); } });
    }
    if (nextBtn) {
        const newNext = nextBtn.cloneNode(true);
        nextBtn.parentNode.replaceChild(newNext, nextBtn);
        newNext.addEventListener('click', async () => { if (currentPdfPage < totalPdfPages) { currentPdfPage++; await renderPdfPage(currentPdfPage); updatePdfPageControls(); } });
    }
}

// Redactions mapping untouched logic wise
function renderCompletedSignatures(doc, container) {
    container.querySelectorAll('.completed-signature-container').forEach(el => el.remove());
    container.style.position = 'relative';
    const canvas = container.querySelector('canvas');
    if (!canvas || canvas.width === 0) { setTimeout(() => renderCompletedSignatures(doc, container), 200); return; }

    const currentPage = window.currentPdfPage || 1;
    const containerRect = container.getBoundingClientRect();
    const canvasRect = canvas.getBoundingClientRect();
    const workflowHistory = doc.workflow_history || [];
    const completedSignatures = workflowHistory.filter(step => step.status === 'completed' || step.status === 'approved');

    completedSignatures.forEach((step, index) => {
        let signatureMap = step.signature_map;
        if (typeof signatureMap === 'string') { try { signatureMap = JSON.parse(signatureMap); } catch (e) { signatureMap = null; } }
        let mapsToRender = [];

        if (signatureMap) {
            if (signatureMap.accounting && signatureMap.issuer) {
                mapsToRender.push({ ...signatureMap.accounting, label: 'Accounting', step: step });
                mapsToRender.push({ ...signatureMap.issuer, label: 'Issuer', step: step });
            } else mapsToRender.push({ ...signatureMap, step: step });
        } else {
            mapsToRender.push({ x_pct: 0.62, y_pct: Math.max(0.1, 0.78 - (index * 0.1)), w_pct: 0.28, h_pct: 0.1, page: 1, step: step });
        }

        mapsToRender.forEach((map) => {
            if ((map.page || 1) !== currentPage) return;

            const left = (canvasRect.left - containerRect.left) + (map.x_pct * canvasRect.width);
            const top = (canvasRect.top - containerRect.top) + (map.y_pct * canvasRect.height);
            const width = (map.w_pct || 0.28) * canvasRect.width;
            const height = (map.h_pct || 0.1) * canvasRect.height;

            let name = map.step.assignee_name || map.step.created_by_name || map.step.from_office || 'Unknown';
            let position = map.step.assignee_position || map.step.position || '';
            let timestamp = map.step.signed_at || map.step.created_at || map.step.acted_at || '';
            if (timestamp) timestamp = new Date(timestamp).toLocaleString();

            const office = map.step.from_office || map.step.office_name || '';
            if (map.label) name = `${name} (${map.label})`;
            else if (office.includes('Accounting')) name = `${name} (Accounting)`;
            else if (office.includes('Creator') || office.includes('Student')) name = `${name} (Issuer)`;

            const signatureContainer = document.createElement('div');
            signatureContainer.className = 'completed-signature-container bg-dark text-white rounded shadow-sm d-flex flex-col justify-content-center align-items-center text-center p-1';
            signatureContainer.style.cssText = `position:absolute;left:${left}px;top:${top}px;width:${width}px;height:${height}px;z-index:1060;pointer-events:none;`;
            signatureContainer.innerHTML = `
                <div class="fw-bold truncate text-xs w-full">${name}</div>
                ${position ? `<div class="text-2xs opacity-75 truncate w-full">${position}</div>` : ''}
                <div class="text-2xs opacity-50">${timestamp}</div>
            `;
            container.appendChild(signatureContainer);
        });
    });
}

// Conversational threading
function formatConversationDate(value) {
    if (!value) return '';
    const dt = new Date(value);
    return Number.isNaN(dt.getTime()) ? '' : dt.toLocaleString();
}

function clearReplyTarget() {
    currentReplyTargetId = null;
    document.getElementById('conversationReplyBanner').style.display = 'none';
}

function setReplyTarget(commentId, authorName) {
    currentReplyTargetId = Number(commentId);
    document.getElementById('conversationReplyAuthor').textContent = authorName || 'comment';
    document.getElementById('conversationReplyBanner').style.display = 'flex';
    document.getElementById('conversationInput').focus();
}

function renderConversationThread(comments) {
    const thread = document.getElementById('conversationThread');
    if (!thread) return;
    if (!Array.isArray(comments) || comments.length === 0) {
        thread.innerHTML = '<div class="text-center text-muted p-3 border border-dashed rounded-lg">No conversation yet. Post a reply.</div>';
        return;
    }

    const byParent = comments.reduce((acc, item) => {
        const key = item.parent_id == null ? 'root' : String(item.parent_id);
        if (!acc[key]) acc[key] = [];
        acc[key].push(item);
        return acc;
    }, {});

    const renderNodes = (parentKey, depth = 0) => {
        const nodes = byParent[parentKey] || [];
        return nodes.map((item) => `
            <div class="conversation-item mb-2 depth-${Math.min(depth, 4)}">
                <div class="bg-white border rounded-lg p-2 shadow-xs">
                    <div class="d-flex gap-2 text-xs mb-1">
                        <strong class="text-dark">${safeText(item.author_name)}</strong>
                        <span class="text-muted">${safeText(item.author_role)}${item.author_position ? ` • ${item.author_position}` : ''}</span>
                    </div>
                    <div class="text-sm text-dark mb-1">${safeText(item.comment).replace(/\n/g, '<br>')}</div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted" style="font-size:10px;">${formatConversationDate(item.created_at)}</small>
                        <button type="button" class="btn btn-link text-decoration-none p-0 text-xs" onclick="setReplyTarget(${item.id}, '${safeText(item.author_name)}')">Reply</button>
                    </div>
                </div>
                ${renderNodes(String(item.id), depth + 1)}
            </div>
        `).join('');
    };
    thread.innerHTML = renderNodes('root');
}

async function loadConversationThread(docId) {
    try {
        const r = await fetch(BASE_URL + `api/documents.php?action=get_comments&id=${docId}`);
        const data = await r.json();
        if (data.success) renderConversationThread(data.comments || []);
    } catch (e) { }
}

async function postConversationReply() {
    if (!currentConversationDocumentId) return;
    const input = document.getElementById('conversationInput');
    const comment = input.value.trim();
    if (!comment) return;

    try {
        const r = await fetch(BASE_URL + 'api/documents.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_comment', document_id: currentConversationDocumentId, comment, parent_id: currentReplyTargetId })
        });
        const d = await r.json();
        if (d.success) {
            input.value = ''; clearReplyTarget();
            await loadConversationThread(currentConversationDocumentId);
        }
    } catch (e) { }
}

// Prefs and Profile
function openProfileSettings() {
    if (window.currentUser) {
        document.getElementById('profileFirstName').value = window.currentUser.firstName || '';
        document.getElementById('profileLastName').value = window.currentUser.lastName || '';
        document.getElementById('profileEmail').value = window.currentUser.email || '';
    }
    new bootstrap.Modal(document.getElementById('profileSettingsModal')).show();
}

function openPreferences() {
    document.getElementById('emailNotifications').checked = localStorage.getItem('trackDoc_emailNotifications') !== 'false';
    document.getElementById('autoRefresh').checked = localStorage.getItem('trackDoc_autoRefresh') !== 'false';
    document.getElementById('itemsPerPagePref').value = localStorage.getItem('trackDoc_itemsPerPage') || '10';
    new bootstrap.Modal(document.getElementById('preferencesModal')).show();
}

function savePreferences() {
    localStorage.setItem('trackDoc_emailNotifications', document.getElementById('emailNotifications').checked);
    localStorage.setItem('trackDoc_autoRefresh', document.getElementById('autoRefresh').checked);
    localStorage.setItem('trackDoc_itemsPerPage', document.getElementById('itemsPerPagePref').value);
    localStorage.setItem('trackDoc_currentPage', currentPage);
    localStorage.setItem('trackDoc_currentSortField', currentSortField);
    localStorage.setItem('trackDoc_currentSortDirection', currentSortDirection);
    itemsPerPage = parseInt(document.getElementById('itemsPerPagePref').value);
    renderCurrentPage();
    bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
}

function loadPreferences() {
    const emailNotifs = localStorage.getItem('trackDoc_emailNotifications');
    const autoRefresh = localStorage.getItem('trackDoc_autoRefresh');
    const savedItemsPerPage = localStorage.getItem('trackDoc_itemsPerPage');
    const savedCurrentPage = localStorage.getItem('trackDoc_currentPage');
    const savedSortField = localStorage.getItem('trackDoc_currentSortField');
    const savedSortDirection = localStorage.getItem('trackDoc_currentSortDirection');

    if (emailNotifs !== null) document.getElementById('emailNotifications').checked = emailNotifs === 'true';
    if (autoRefresh !== null) document.getElementById('autoRefresh').checked = autoRefresh === 'true';
    if (savedItemsPerPage) {
        itemsPerPage = parseInt(savedItemsPerPage);
        document.getElementById('itemsPerPagePref').value = savedItemsPerPage;
        document.getElementById('itemsPerPage').value = savedItemsPerPage;
    }
    if (savedCurrentPage) currentPage = parseInt(savedCurrentPage);
    if (savedSortField) currentSortField = savedSortField;
    if (savedSortDirection) currentSortDirection = savedSortDirection;
}
function showHelp() { new bootstrap.Modal(document.getElementById('helpModal')).show(); }

if (window.NavbarSettings) {
    window.openProfileSettings = window.NavbarSettings.openProfileSettings;
    window.openPreferences = window.NavbarSettings.openPreferences;
    window.showHelp = window.NavbarSettings.showHelp;
    window.savePreferences = window.NavbarSettings.savePreferences;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });
}