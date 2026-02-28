/**
 * Modern Document Tracker System
 * ==============================
 * Handles the student-facing document tracking interface.
 * Delegates PDF rendering to PdfViewer and comments to CommentsManager.
 * * Note: Material logic is preserved globally at the bottom of this file.
 */
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

class DocumentTrackerSystem {
    constructor() {
        // Data State
        this.allDocuments = [];
        this.filteredDocuments = [];
        this.currentDocument = null;
        this.documentStats = { total: 0, pending: 0, approved: 0, inProgress: 0, underReview: 0 };

        // Pagination & Sorting State
        this.currentPage = 1;
        this.itemsPerPage = 10;
        this.totalDocuments = 0;
        this.currentSortField = 'updated_at';
        this.currentSortDirection = 'desc';

        // UI & Search State
        this.searchTerm = '';
        this.searchTimeout = null;

        // Sub-Managers
        this.apiBase = BASE_URL + 'api/documents.php';
        this.commentsManager = new CommentsManager(this.apiBase);

        this.signatureManager = new SignatureManager();
        this.signatureManager.isReadOnly = true;

        this.pdfViewer = new PdfViewer(this.signatureManager);
    }

    async init() {
        if (window.currentUser && document.getElementById('userDisplayName')) {
            document.getElementById('userDisplayName').textContent = `${window.currentUser.firstName} ${window.currentUser.lastName}`;
        }

        this.loadPreferences();
        this.initializeEventListeners();
        await this.loadStudentDocuments();

        if (window.addAuditLog) {
            window.addAuditLog('TRACK_DOCUMENT_VIEWED', 'Document Management', 'Viewed document tracking page', null, 'Page', 'INFO');
        }

        // Periodic auto-refresh
        setInterval(() => {
            const autoRefresh = document.getElementById('autoRefresh');
            if (autoRefresh && autoRefresh.checked && !document.hidden && document.getElementById('documentModal').style.display !== 'block') {
                this.loadStudentDocuments(true);
            }
        }, 30000);
    }

    // ------------------------------------------------------------------
    // Data Loading & Processing
    // ------------------------------------------------------------------

    async loadStudentDocuments(isSilentRefresh = false) {
        if (!isSilentRefresh) this.showLoadingState();

        try {
            const response = await fetch(this.apiBase + '?action=my_documents&t=' + Date.now(), {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (data.success) {
                this.allDocuments = data.documents || [];
                this.calculateStatistics();
                this.updateStatisticsDisplay();
                this.applyCurrentFilters();

                if (!isSilentRefresh) {
                    this.hideLoadingState();
                    if (this.allDocuments.length === 0) this.showEmptyState();
                }
            } else {
                if (!isSilentRefresh) {
                    this.showToast(data.message || 'Error loading documents', 'error');
                    this.showEmptyState();
                }
            }
        } catch (error) {
            if (!isSilentRefresh) {
                this.hideLoadingState();
                this.showToast('Failed to load documents: ' + error.message, 'error');
                this.showEmptyState();
            }
        }
    }

    refreshDocuments() {
        this.loadStudentDocuments();
    }

    calculateStatistics() {
        this.documentStats = { total: this.allDocuments.length, pending: 0, approved: 0, inProgress: 0, underReview: 0 };
        this.allDocuments.forEach(doc => {
            const status = this.normalizeDocumentStatus(doc.status || doc.current_status);
            if (status === 'submitted' || status === 'on_hold') this.documentStats.pending++;
            else if (status === 'approved') this.documentStats.approved++;
            else if (status === 'in_progress') this.documentStats.inProgress++;
        });
    }

    // ------------------------------------------------------------------
    // UI Updates & Table Rendering
    // ------------------------------------------------------------------

    renderCurrentPage() {
        const tbody = document.getElementById('documentsList');
        const paginationContainer = document.getElementById('paginationContainer');
        if (!tbody) return;

        tbody.innerHTML = '';

        if (this.filteredDocuments.length === 0) {
            this.showEmptyState();
            return;
        }

        document.getElementById('emptyState').style.display = 'none';
        document.querySelector('.table-wrapper').style.display = 'block';

        const totalPages = Math.ceil(this.totalDocuments / this.itemsPerPage);
        const startIndex = (this.currentPage - 1) * this.itemsPerPage;
        const endIndex = Math.min(startIndex + this.itemsPerPage, this.totalDocuments);
        const currentPageDocs = this.filteredDocuments.slice(startIndex, endIndex);

        currentPageDocs.forEach((doc) => {
            const row = document.createElement('tr');
            const docType = this.getDocumentTypeDisplay(doc.document_type || doc.doc_type);
            const createdDate = doc.created_at ? new Date(doc.created_at).toLocaleDateString() : 'N/A';

            // Call the new global helper for the beautiful badges
            const statusBadgeHTML = typeof window.getStatusBadge === 'function'
                ? window.getStatusBadge(doc.status || doc.current_status, 'document')
                : `<span class="badge ${this.getStatusBadgeClass(doc.status || doc.current_status)}">${this.formatStatusDisplay(doc.status || doc.current_status)}</span>`;

            row.innerHTML = `
                <td>
                    <div class="d-flex align-items-center gap-3">
                        <div class="btn-icon sm bg-primary-subtle text-primary border-0"><i class="bi ${doc.is_material ? 'bi-image' : 'bi-file-text'}"></i></div>
                        <div>
                            <div class="fw-bold text-dark">${this.safeText(doc.title || doc.document_name)}</div>
                            <div class="text-xs text-muted mt-1">Created: ${createdDate}</div>
                        </div>
                    </div>
                </td>
                <td><span class="badge ${this.getDocTypeBadgeClass(doc.doc_type)}">${this.safeText(docType)}</span></td>
                <td>${statusBadgeHTML}</td>
                
                <td>
                    <div class="d-flex flex-column gap-1 align-items-start">
                        <span class="badge ${this.getLocationBadgeClass(doc.current_location)}">${this.shortenOfficeName(doc.current_location)}</span>
                        <span class="text-xs text-muted fw-medium mt-1"><i class="bi bi-person me-1"></i>${this.safeText(doc.current_assignee || 'Unassigned')}</span>
                    </div>
                </td>

                <td>
                    <div class="text-sm fw-medium">${new Date(doc.updated_at).toLocaleDateString()}</div>
                    <div class="text-xs text-muted">${new Date(doc.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                </td>
                <td><div class="text-xs text-muted truncate" style="max-width: 150px;">
                    ${doc.is_material ? this.getMaterialNotesPreview(doc) : this.getNotesPreview(doc.notes || [])}
                </div></td>
                <td class="text-end">
                    <button class="btn btn-ghost btn-sm rounded-pill px-3 me-1" onclick="documentTracker.viewDetails('${doc.id}')"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-ghost btn-icon sm rounded-pill" onclick="documentTracker.downloadDocument('${doc.id}')" title="Download"><i class="bi bi-download"></i></button>
                </td>
            `;
            tbody.appendChild(row);
        });

        this.renderPagination(totalPages);
        this.updatePaginationInfo(startIndex + 1, endIndex, this.totalDocuments);
        paginationContainer.style.display = totalPages > 1 ? 'flex' : 'none';
    }

    // ------------------------------------------------------------------
    // Modal View Details (PDF & Comments Injection)
    // ------------------------------------------------------------------

    async viewDetails(docId) {
        // Route Material clicks to the global handler
        const isMaterial = String(docId).startsWith('MAT-');
        const originalId = isMaterial ? String(docId).replace('MAT-', '') : docId;

        if (isMaterial) {
            viewMaterialDetails(originalId);
            return;
        }

        const modalElement = document.getElementById('documentModal');
        let modal = bootstrap.Modal.getInstance(modalElement);
        if (!modal) modal = new bootstrap.Modal(modalElement);

        const modalTitle = document.getElementById('documentModalTitle');
        const modalBody = document.getElementById('documentModalBody');

        try {
            // Fetch the document details
            const response = await fetch(BASE_URL + `api/documents.php?id=${docId}`);
            if (!response.ok) throw new Error('Document not found');

            const doc = await response.json();

            if (doc && doc.id) {
                this.currentDocument = doc;
                const docType = this.getDocumentTypeDisplay(doc.document_type || doc.doc_type || 'unknown');

                modalTitle.innerHTML = `<i class="bi bi-file-earmark-text text-primary me-2"></i>${this.safeText(doc.title || doc.document_name)}`;
                document.getElementById('documentModalMeta').innerHTML = `<i class="bi bi-folder2 me-1"></i> ${this.safeText(docType)}`;

                // Setup layout mimicking the original UI but integrating the PdfViewer HTML
                let pdfHtml = '';
                if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                    pdfHtml = `
                        <div class="card card-flat mb-3 shadow-none h-100">
                            <div class="card-header bg-transparent pb-0 border-0">
                                <h6 class="m-0 fw-bold"><i class="bi bi-file-pdf text-danger me-2"></i>Document Preview</h6>
                            </div>
                            <div class="card-body p-0 d-flex flex-column">
                                <div class="pdf-viewer-modern border rounded-lg overflow-hidden flex-grow-1 d-flex flex-column">
                                    <div class="pdf-toolbar" style="display: flex; justify-content: space-between; padding: 10px; background: #fff; border-bottom: 1px solid #e5e7eb;">
                                        <div class="toolbar-group d-flex align-items-center gap-2">
                                            <button class="btn btn-sm btn-light" onclick="documentTracker.pdfViewer.prevPage(documentTracker.currentDocument)" id="prevPageBtn"><i class="bi bi-chevron-left"></i></button>
                                            <div class="page-info d-flex align-items-center gap-1">
                                                <input type="number" id="pageInput" min="1" onchange="documentTracker.pdfViewer.goToPage(this.value, documentTracker.currentDocument)" class="form-control form-control-sm text-center" style="width: 50px;" />
                                                <span class="text-muted">/</span><span id="pageTotal" class="fw-bold">1</span>
                                            </div>
                                            <button class="btn btn-sm btn-light" onclick="documentTracker.pdfViewer.nextPage(documentTracker.currentDocument)" id="nextPageBtn"><i class="bi bi-chevron-right"></i></button>
                                        </div>
                                        <div class="toolbar-group d-flex align-items-center gap-2">
                                            <button class="btn btn-sm btn-light" onclick="documentTracker.pdfViewer.openFullViewer(documentTracker.currentDocument)"><i class="bi bi-eye"></i></button>
                                        </div>
                                    </div>
                                    <div id="pdfContent" class="bg-surface-sunken position-relative flex-grow-1 overflow-auto text-center" style="min-height: 500px; padding: 1rem;">
                                        <div id="pdfLoading" class="d-flex flex-column align-items-center justify-content-center h-100">
                                            <div class="spinner-border text-primary mb-3"></div><p class="text-muted">Loading document...</p>
                                        </div>
                                        <canvas id="pdfCanvas" class="shadow-sm bg-white" style="display: none; margin: 0 auto;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }

                let resubmitHtml = '';
                if (this.normalizeDocumentStatus(doc.status) === 'on_hold') {
                    resubmitHtml = `
                        <div class="mt-3 pt-3 border-top text-center" id="resubmitContainer-${doc.id}">
                            <div class="alert alert-warning py-2 px-3 text-start mb-3 border-warning-subtle">
                                <p class="text-xs text-dark mb-0 fw-medium">
                                    <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                                    A signatory failed to respond within 5 days. You may resubmit this to reset their timer and send a new notification.
                                </p>
                            </div>
                            
                            <button id="resubmitInitBtn-${doc.id}" class="btn btn-warning btn-sm rounded-pill w-100 fw-bold shadow-sm" onclick="documentTracker.showResubmitConfirm('${doc.id}')">
                                <i class="bi bi-arrow-clockwise me-2"></i>Resubmit Document
                            </button>
                            
                            <div id="resubmitConfirmBox-${doc.id}" class="mt-2 p-3 bg-surface-raised border rounded-lg shadow-sm text-start" style="display: none;">
                                <p class="text-sm text-dark fw-bold mb-1">Are you sure?</p>
                                <p class="text-xs text-muted mb-3">This will reset the 5-day timer and alert the signatory.</p>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-light btn-sm rounded-pill flex-fill" onclick="documentTracker.hideResubmitConfirm('${doc.id}')">Cancel</button>
                                    <button class="btn btn-primary btn-sm rounded-pill flex-fill" onclick="documentTracker.executeResubmit('${doc.id}')">Yes, Resubmit</button>
                                </div>
                            </div>
                        </div>
                    `;
                }

                // Status Info
                let infoHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-xs text-muted fw-semibold">Current Status</span>
                                ${this.renderDocumentStatusBadge(doc.status)}
                            </div>
                            ${resubmitHtml}
                        </div>
                    </div>
                `;

                // Integrate CommentsManager HTML
                const conversationHtml = `
                    <div class="card card-flat mb-3 shadow-none">
                        <div class="card-header bg-transparent pb-2 border-bottom">
                            <h6 class="m-0 fw-bold"><i class="bi bi-chat-dots text-success me-2"></i>Conversation</h6>
                        </div>
                        <div class="card-body p-3">
                            <div id="threadCommentsList" class="conversation-thread mb-3"></div>
                            
                            <div id="commentReplyBanner" class="alert alert-info py-2 px-3 d-flex justify-content-between align-items-center" style="display:none;">
                                <span class="text-xs">Replying to <strong id="replyAuthorName"></strong></span>
                                <button type="button" class="btn-close ms-2" onclick="documentTracker.commentsManager.clearReplyTarget()"></button>
                            </div>
                            
                            <div class="conversation-input-wrap">
                                <textarea id="threadCommentInput" class="form-control sm mb-2" rows="2" placeholder="Write a comment..."></textarea>
                                <div class="d-flex justify-content-end align-items-center gap-3">
                                    <span id="notesSaveIndicator" class="text-xs fw-medium"></span>
                                    <button type="button" class="btn btn-primary btn-sm rounded-pill" onclick="documentTracker.commentsManager.postComment()">Reply</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                // Correctly Structured Timeline HTML
                let timelineHtml = `<div class="card card-flat mb-3 shadow-none"><div class="card-header bg-transparent pb-0 border-0"><h6 class="m-0 fw-bold"><i class="bi bi-clock-history text-info me-2"></i>Approval Timeline</h6></div><div class="card-body px-4 pb-4"><div class="timeline-container">`;
                const workflow = doc.workflow || [];

                if (workflow.length > 0) {
                    workflow.forEach((item, index) => {
                        const isLast = index === workflow.length - 1;
                        const rawStepStatus = item.status || item.step_status || item.signature_status || 'queued';

                        // Use your new global ui-helper to perfectly normalize the status
                        const status = (typeof normalizeWorkflowStatus === 'function'
                            ? normalizeWorkflowStatus(rawStepStatus, 'step')
                            : String(rawStepStatus || '').toLowerCase());

                        // Step Name goes on top, Assignee goes on bottom
                        const stepName = this.shortenOfficeName(item.name || item.step_name || 'Unknown Step');
                        const assigneeName = (item.assignee_name && item.assignee_name !== 'Unknown') ? item.assignee_name : 'Unassigned';

                        // Styling for the timeline marker
                        let stepClass = status === 'rejected' ? 'timeline-step-rejected' : (status === 'completed' ? 'timeline-step-approved' : '');
                        let markerClass = status === 'rejected' ? 'bg-danger' : (status === 'completed' ? 'bg-success' : (status === 'pending' ? 'bg-warning text-dark' : 'bg-secondary'));
                        let icon = status === 'rejected' ? 'bi-x-circle-fill' : (status === 'completed' ? 'bi-check-circle-fill' : (status === 'pending' ? 'bi-hourglass-split' : 'bi-circle'));

                        // Use your new ui-helper to get the exact semantic status text
                        let actionText = '';
                        if (typeof getStatusText === 'function') {
                            actionText = getStatusText(status, 'step');
                        } else {
                            actionText = status.charAt(0).toUpperCase() + status.slice(1);
                        }

                        // Right-side badge logic (Shows Date AND Time if acted upon)
                        let dateText = actionText;
                        if (item.acted_at) {
                            const actedDate = new Date(item.acted_at);
                            const formattedDate = actedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                            const formattedTime = actedDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                            dateText = `${formattedDate} • ${formattedTime}`;
                        }

                        let badgeClass = 'bg-light text-muted';
                        if (status === 'completed') badgeClass = 'bg-success-subtle text-success';
                        if (status === 'pending') badgeClass = 'bg-warning-subtle text-dark border-warning';
                        if (status === 'rejected') badgeClass = 'bg-danger-subtle text-danger border-danger';

                        timelineHtml += `
                            <div class="timeline-item ${isLast ? 'last' : ''} ${stepClass}">
                                <div class="timeline-marker ${markerClass}">
                                    <i class="bi ${icon}"></i>
                                </div>
                                <div class="timeline-content bg-surface-raised border rounded-lg p-3 shadow-xs">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <div>
                                            <div class="text-sm fw-bold text-dark">${stepName}</div>
                                            <div class="text-xs text-muted mt-1"><i class="bi bi-person me-1"></i>${assigneeName}</div>
                                        </div>
                                        <div class="text-xs fw-medium px-2 py-1 rounded ${badgeClass} border border-opacity-25">${dateText}</div>
                                    </div>
                                    ${item.note ? `<div class="text-xs text-muted mt-2 pt-2 border-top fst-italic">"${this.safeText(item.note)}"</div>` : ''}
                                </div>
                            </div>`;
                    });
                } else {
                    timelineHtml += `
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-clock-history fs-3 opacity-50 mb-2 d-block"></i>
                            <p class="mb-0 text-sm">No timeline events recorded yet</p>
                        </div>
                    `;
                }
                timelineHtml += `</div></div></div>`;

                // Inject into DOM
                modalBody.innerHTML = `
                    <div class="row g-3">
                        <div class="col-lg-7">${pdfHtml}</div>
                        <div class="col-lg-5">${infoHtml}${conversationHtml}${timelineHtml}</div>
                    </div>
                `;

                // Download Button logic
                const dlBtn = document.getElementById('downloadDocumentBtn');
                if (dlBtn && doc.file_path && (doc.status === 'approved' || doc.status === 'rejected')) {
                    dlBtn.style.display = 'inline-flex';
                    dlBtn.onclick = () => this.downloadDocument(doc.id);
                } else if (dlBtn) dlBtn.style.display = 'none';

                modal.show();

                // Initialize the managers once the modal is visible
                modalElement.addEventListener('shown.bs.modal', function handler() {
                    modalElement.removeEventListener('shown.bs.modal', handler);

                    if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                        let pdfUrl = doc.file_path;
                        if (!pdfUrl.startsWith('http') && !pdfUrl.startsWith('upload')) pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
                        documentTracker.pdfViewer.loadPdf(pdfUrl, doc);
                    }

                    // Initialize threaded comments
                    documentTracker.commentsManager.init(doc.id);
                });
            }
        } catch (error) {
            console.error(error);
            modalBody.innerHTML = `<div class="alert alert-danger">Failed to load document details.</div>`;
            modal.show();
        }
    }

    // ------------------------------------------------------------------
    // Event Listeners & Utilities
    // ------------------------------------------------------------------

    initializeEventListeners() {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.applyCurrentFilters(), 300);
            });
        }

        document.querySelectorAll('input[name="statusFilter"]').forEach(button => {
            button.addEventListener('change', (e) => {
                if (e.target.checked) {
                    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                    e.target.nextElementSibling.classList.add('active');
                    this.applyCurrentFilters();
                }
            });
        });

        const itemsPerPageSelect = document.getElementById('itemsPerPage');
        if (itemsPerPageSelect) {
            itemsPerPageSelect.addEventListener('change', (e) => {
                this.itemsPerPage = parseInt(e.target.value);
                this.currentPage = 1;
                this.renderCurrentPage();
            });
        }
    }

    applyCurrentFilters() {
        let docs = [...this.allDocuments];
        const searchVal = document.getElementById('searchInput')?.value.toLowerCase().trim();

        if (searchVal) {
            docs = docs.filter(doc =>
                [doc.title, doc.document_name, doc.status, doc.current_location, doc.document_type, doc.doc_type]
                    .some(f => f && String(f).toLowerCase().includes(searchVal))
            );
        }

        const activeFilter = document.querySelector('input[name="statusFilter"]:checked')?.id;
        if (activeFilter && activeFilter !== 'filterAll') {
            const map = {
                'filterPending': ['draft', 'submitted', 'in_progress', 'on_hold'],
                'filterApproved': ['approved'],
                'filterRejected': ['rejected']
            };
            const allow = map[activeFilter] || [];
            docs = docs.filter(doc => allow.includes(this.normalizeDocumentStatus(doc.status || doc.current_status)));
        }

        this.filteredDocuments = docs;
        this.totalDocuments = docs.length;
        this.currentPage = 1;
        this.sortDocuments();
        this.updateResultsCount();
        this.renderCurrentPage();
    }

    clearFilters() {
        const search = document.getElementById('searchInput');
        if (search) search.value = '';

        const allFilter = document.getElementById('filterAll');
        if (allFilter) {
            allFilter.checked = true;
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
            allFilter.nextElementSibling.classList.add('active');
        }

        this.currentSortField = 'updated_at';
        this.currentSortDirection = 'desc';
        this.applyCurrentFilters();
    }

    handleSort(field) {
        if (this.currentSortField === field) {
            this.currentSortDirection = this.currentSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            this.currentSortField = field;
            this.currentSortDirection = 'asc';
        }
        this.sortDocuments();
        this.renderCurrentPage();
    }

    sortDocuments() {
        this.filteredDocuments.sort((a, b) => {
            let valA = this.getSortValue(a, this.currentSortField);
            let valB = this.getSortValue(b, this.currentSortField);

            if (this.currentSortField.includes('_at') || this.currentSortField.includes('date')) {
                valA = new Date(valA).getTime() || 0;
                valB = new Date(valB).getTime() || 0;
            } else {
                valA = String(valA).toLowerCase();
                valB = String(valB).toLowerCase();
            }

            if (valA < valB) return this.currentSortDirection === 'asc' ? -1 : 1;
            if (valA > valB) return this.currentSortDirection === 'asc' ? 1 : -1;
            return 0;
        });
    }

    getSortValue(doc, field) {
        switch (field) {
            case 'title':
                return doc.title || doc.document_name || '';
            case 'document_type':
                return this.getDocumentTypeDisplay(doc.document_type || doc.doc_type || '');
            case 'current_status':
                return this.formatStatusDisplay(doc.status || doc.current_status || '');
            case 'current_location':
                return this.shortenOfficeName(doc.current_location || '');
            case 'updated_at':
                return doc.updated_at || doc.uploaded_at || '';
            default:
                return doc[field] || doc.title || doc.document_name || '';
        }
    }

    changePage(page) {
        const totalPages = Math.ceil(this.totalDocuments / this.itemsPerPage);
        if (page >= 1 && page <= totalPages) {
            this.currentPage = page;
            this.renderCurrentPage();
        }
    }

    changeItemsPerPage(value) {
        this.itemsPerPage = parseInt(value);
        this.currentPage = 1;
        this.renderCurrentPage();
    }

    renderPagination(totalPages) {
        const pagination = document.getElementById('pagination');
        if (!pagination) return;

        pagination.innerHTML = '';
        if (totalPages <= 1) return;

        const p = document.createElement('li');
        p.className = `page-item ${this.currentPage === 1 ? 'disabled' : ''}`;
        p.innerHTML = `<a class="page-link" href="#" onclick="documentTracker.changePage(${this.currentPage - 1})"><i class="bi bi-chevron-left"></i></a>`;
        pagination.appendChild(p);

        let start = Math.max(1, this.currentPage - 2);
        let end = Math.min(totalPages, start + 4);
        if (end - start < 4) start = Math.max(1, end - 4);

        for (let i = start; i <= end; i++) {
            const item = document.createElement('li');
            item.className = `page-item ${i === this.currentPage ? 'active' : ''}`;
            item.innerHTML = `<a class="page-link" href="#" onclick="documentTracker.changePage(${i})">${i}</a>`;
            pagination.appendChild(item);
        }

        const n = document.createElement('li');
        n.className = `page-item ${this.currentPage === totalPages ? 'disabled' : ''}`;
        n.innerHTML = `<a class="page-link" href="#" onclick="documentTracker.changePage(${this.currentPage + 1})"><i class="bi bi-chevron-right"></i></a>`;
        pagination.appendChild(n);
    }

    downloadDocument(docId) {
        this.showToast('Preparing download...', 'info');
        fetch(BASE_URL + `api/documents.php?action=document_details&id=${docId}`)
            .then(r => r.json())
            .then(data => {
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
            })
            .catch(() => this.showToast('Failed to download document', 'error'));
    }

    // Formatting & Helpers

    // --- Inline Resubmit Confirmation Handlers ---
    showResubmitConfirm(docId) {
        const btn = document.getElementById(`resubmitInitBtn-${docId}`);
        const box = document.getElementById(`resubmitConfirmBox-${docId}`);
        if (btn) btn.style.display = 'none';
        if (box) box.style.display = 'block';
    }

    hideResubmitConfirm(docId) {
        const btn = document.getElementById(`resubmitInitBtn-${docId}`);
        const box = document.getElementById(`resubmitConfirmBox-${docId}`);
        if (btn) btn.style.display = 'inline-flex';
        if (box) box.style.display = 'none';
    }

    async executeResubmit(docId) {
        this.showToast('Resubmitting document...', 'info');

        const box = document.getElementById(`resubmitConfirmBox-${docId}`);
        if (box) box.style.opacity = '0.5';

        // FIX: Use FormData instead of JSON so PHP's $_POST can read it natively!
        const formData = new FormData();
        formData.append('action', 'resubmit');
        formData.append('document_id', docId);

        try {
            const response = await fetch(this.apiBase, {
                method: 'POST',
                body: formData // Send as standard form data
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Document successfully resubmitted!', 'success');

                const modalEl = document.getElementById('documentModal');
                if (modalEl) {
                    const closeBtn = modalEl.querySelector('.btn-close');
                    if (closeBtn) closeBtn.click();
                }

                this.loadStudentDocuments(true); // Silent refresh
            } else {
                this.showToast(data.message || 'Failed to resubmit document.', 'error');
                if (box) box.style.opacity = '1';
            }
        } catch (error) {
            console.error('Resubmit Error:', error);
            this.showToast('An error occurred while resubmitting.', 'error');
            if (box) box.style.opacity = '1';
        }
    }

    safeText(value) { return value ? String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') : 'N/A'; }
    normalizeDocumentStatus(status) {
        if (typeof normalizeWorkflowStatus === 'function') {
            return normalizeWorkflowStatus(status, 'document');
        }
        const fallback = String(status || '').toLowerCase();
        const alias = {
            pending: 'submitted',
            completed: 'approved',
            in_review: 'in_progress',
            under_review: 'in_progress',
            'under review': 'in_progress',
            'in review': 'in_progress',
            timeout: 'on_hold',
            expired: 'on_hold',
            deleted: 'cancelled'
        };
        return alias[fallback] || fallback;
    }
    renderDocumentStatusBadge(status) {
        const normalized = this.normalizeDocumentStatus(status);
        if (typeof getStatusBadge === 'function') {
            return getStatusBadge(normalized, 'document');
        }
        return `<span class="badge badge-secondary">${this.safeText(this.formatStatusDisplay(normalized))}</span>`;
    }
    getStatusBadgeClass(status) {
        switch (this.normalizeDocumentStatus(status)) {
            case 'submitted': return 'bg-info text-dark';
            case 'in_progress': return 'bg-primary';
            case 'on_hold': return 'bg-warning text-dark';
            case 'approved': return 'bg-success';
            case 'rejected': return 'bg-danger';
            case 'cancelled': return 'bg-dark';
            default: return 'bg-secondary';
        }
    }
    formatStatusDisplay(status) {
        if (!status) return 'Unknown';
        if (typeof getStatusText === 'function') return getStatusText(this.normalizeDocumentStatus(status), 'document');
        const normalized = this.normalizeDocumentStatus(status);
        return normalized.charAt(0).toUpperCase() + normalized.slice(1);
    }
    shortenOfficeName(name) {
        if (!name) return 'Unknown';
        // Returns the complete office name but removes the word "Approval"
        return String(name).replace(/\s*Approval/gi, '').trim();
    }
    getDocumentTypeDisplay(type) {
        const map = { 'saf': 'Student Activity Form', 'publication': 'Publication', 'proposal': 'Project Proposal', 'facility': 'Facility Request', 'communication': 'Communication' };
        return map[type] || type;
    }
    getNotesPreview(notes) {
        if (!notes || !notes.length) return 'No notes';
        const n = notes.find(n => n.is_rejection) || notes.find(n => n.comment && n.comment.includes('Auto-timeout')) || notes[notes.length - 1];
        return n ? (n.comment || n.note || '').replace(/"/g, '&quot;') : 'No notes';
    }
    getMaterialNotesPreview(mat) {
        if (!mat.notes || !mat.notes.length) return 'No comments';
        const n = mat.notes[mat.notes.length - 1];
        return `${n.author_name || 'Unknown'}: ${n.comment || n.note || ''}`.substring(0, 45) + '...';
    }
    updateStatisticsDisplay() {
        if (document.getElementById('totalDocuments')) document.getElementById('totalDocuments').textContent = this.documentStats.total;
        if (document.getElementById('pendingDocuments')) document.getElementById('pendingDocuments').textContent = this.documentStats.pending;
        if (document.getElementById('approvedDocuments')) document.getElementById('approvedDocuments').textContent = this.documentStats.approved;
        if (document.getElementById('inProgressDocuments')) document.getElementById('inProgressDocuments').textContent = this.documentStats.inProgress;
    }
    updatePaginationInfo(start, end, total) {
        const info = document.getElementById('paginationInfo');
        if (info) info.textContent = `Showing ${start} to ${end} of ${total} documents`;
    }
    updateResultsCount() {
        const res = document.getElementById('resultsCount');
        if (res) res.innerHTML = `<i class="bi bi-info-circle me-1"></i> Showing ${this.totalDocuments} of ${this.allDocuments.length} documents`;
        const time = document.getElementById('lastUpdatedTime');
        if (time) time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    showLoadingState() {
        document.getElementById('loadingState').style.display = 'block';
        document.getElementById('emptyState').style.display = 'none';
        document.querySelector('.table-wrapper').style.display = 'none';
        document.getElementById('paginationContainer').style.display = 'none';
    }
    hideLoadingState() {
        document.getElementById('loadingState').style.display = 'none';
        document.querySelector('.table-wrapper').style.display = 'block';
    }
    showEmptyState() {
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('emptyState').style.display = 'block';
        document.querySelector('.table-wrapper').style.display = 'none';
        document.getElementById('paginationContainer').style.display = 'none';
    }
    showToast(message, type = 'info', title = null) {
        if (window.ToastManager) window.ToastManager.show({ type: type, title: title, message: message, duration: 4000 });
    }

    getLocationBadgeClass(location) {
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
            case 'completed':
            case 'approved': return 'badge-success';
            default: return 'badge-secondary';
        }
    }
    getDocTypeBadgeClass(docType) {
        switch (docType.toLowerCase()) {
            case 'saf': return 'badge-info';
            case 'proposal': return 'badge-primary';
            case 'communication': return 'badge-success';
            case 'material': return 'badge-warning';
            case 'publication': return 'badge-secondary';
            default: return 'badge-secondary';
        }
    }

    loadPreferences() {
        const perPage = localStorage.getItem('trackDoc_itemsPerPage');
        if (perPage) {
            this.itemsPerPage = parseInt(perPage);
            if (document.getElementById('itemsPerPage')) document.getElementById('itemsPerPage').value = perPage;
        }
    }
    savePreferences() {
        localStorage.setItem('trackDoc_emailNotifications', document.getElementById('emailNotifications').checked);
        localStorage.setItem('trackDoc_autoRefresh', document.getElementById('autoRefresh').checked);
        localStorage.setItem('trackDoc_itemsPerPage', document.getElementById('itemsPerPagePref').value);
        this.itemsPerPage = parseInt(document.getElementById('itemsPerPagePref').value);
        this.renderCurrentPage();
        bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
    }
}

// Instantiate Global Tracker
document.addEventListener('DOMContentLoaded', () => {
    window.documentTracker = new DocumentTrackerSystem();
    window.documentTracker.init();
});

// UI Helper Wrappers for onclick elements
function refreshDocuments() { window.documentTracker.refreshDocuments(); }
function clearFilters() { window.documentTracker.clearFilters(); }
function formatDate(dateString) { return dateString ? new Date(dateString).toLocaleDateString() : ''; }

// Legacy Settings 
if (window.NavbarSettings) {
    window.openProfileSettings = window.NavbarSettings.openProfileSettings;
    window.openPreferences = window.NavbarSettings.openPreferences;
    window.showHelp = window.NavbarSettings.showHelp;
}

// ====================================================================================
// MATERIAL LOGIC (PRESERVED COMPLETELY UNTOUCHED AS REQUESTED)
// ====================================================================================

let currentMaterialId = null;
let currentMaterialReplyTarget = null;

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

            modalTitle.innerHTML = `<i class="bi bi-image text-primary me-2"></i>${window.documentTracker.safeText(material.title)}`;
            document.getElementById('materialModalMeta').innerHTML = `<i class="bi bi-person me-1"></i> ${window.documentTracker.safeText(material.creator_name)} • ${window.documentTracker.safeText(material.department)} • Created ${new Date(material.uploaded_at).toLocaleDateString()}`;

            const isImage = material.file_type && material.file_type.startsWith('image/');
            const isPDF = material.file_type === 'application/pdf';

            let previewHtml = '';
            let fileUrl = '';

            if (material.file_path) {
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

            let infoHtml = `
                <div class="card card-flat mb-3 shadow-none">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-xs text-muted fw-semibold">Status</span>
                            ${window.documentTracker.renderDocumentStatusBadge(material.status)}
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-xs text-muted fw-semibold">Current Office</span>
                            <span class="badge ${getMaterialLocationBadgeClass(material.current_location || 'Pending')}">${window.documentTracker.shortenOfficeName(material.current_location || 'Pending')}</span>
                        </div>
                        ${material.description ? `
                        <div class="mt-3 pt-2 border-top">
                            <div class="text-xs text-muted fw-semibold mb-1">Description</div>
                            <div class="text-sm">${window.documentTracker.safeText(material.description)}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;

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
                                <div class="text-xs fw-semibold text-dark">${item.action} • ${window.documentTracker.shortenOfficeName(item.office_name)}</div>
                                ${item.note ? `<div class="text-2xs text-muted mt-1">${window.documentTracker.safeText(item.note)}</div>` : ''}
                            </div>
                        </div>
                    `;
                });
            } else {
                timelineHtml += `<div class="text-muted text-sm py-2">No workflow history available</div>`;
            }
            timelineHtml += `</div></div></div>`;

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

            currentMaterialId = materialId;
            currentMaterialReplyTarget = null;

            document.getElementById('sendMaterialCommentBtn')?.addEventListener('click', postMaterialComment);
            document.getElementById('cancelMaterialReply')?.addEventListener('click', clearMaterialReplyTarget);

            loadMaterialComments(materialId);

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
    } catch (error) { }
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
                        <strong class="text-dark">${window.documentTracker.safeText(item.author_name)}</strong>
                        <span class="text-muted">${window.documentTracker.safeText(item.author_role)}${item.author_position ? ` • ${item.author_position}` : ''}</span>
                    </div>
                    <div class="text-sm text-dark mb-1">${window.documentTracker.safeText(item.comment).replace(/\n/g, '<br>')}</div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted" style="font-size:10px;">${formatDate(item.created_at)}</small>
                        <button type="button" class="btn btn-link text-decoration-none p-0 text-xs" onclick="setMaterialReplyTarget(${item.id}, '${window.documentTracker.safeText(item.author_name)}')">Reply</button>
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
        window.documentTracker.showToast('No material selected', 'error');
        return;
    }

    const input = document.getElementById('materialCommentInput');
    if (!input) return;

    const comment = input.value.trim();
    if (!comment) {
        window.documentTracker.showToast('Please enter a comment', 'error');
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
            window.documentTracker.showToast('Comment posted successfully', 'success');
        } else {
            window.documentTracker.showToast('Failed to post comment: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        window.documentTracker.showToast('Error posting comment: ' + error.message, 'error');
    }
}

function downloadMaterial(materialId, filePath, title) {
    window.documentTracker.showToast('Preparing download...', 'info');
    let filename = filePath;
    if (filename.includes(':\\')) filename = filename.split('\\').pop();
    if (filename.includes('/')) filename = filename.split('/').pop();

    const downloadUrl = BASE_URL + `api/materials.php?download=1&id=${materialId}`;
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = title + '_pubmat.' + (filename.split('.').pop() || 'file');
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.documentTracker.showToast('Download started', 'success');
}

function getMaterialLocationBadgeClass(location) {
    if (location.toLowerCase().includes('adviser')) return 'badge-info';
    if (location.toLowerCase().includes('dean')) return 'badge-primary';
    if (location.toLowerCase().includes('osa')) return 'badge-success';
    if (location.toLowerCase().includes('completed')) return 'badge-success';
    return 'badge-secondary';
}