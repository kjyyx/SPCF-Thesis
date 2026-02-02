// Document Notification System JavaScript
// Enhanced professional version with improved error handling, search, and performance
// Notes for future developers:
// - Keep exported functions (init, openDocument, goBack, etc.) used by HTML intact.
// - This module uses API calls with proper error handling.
// - All DOM lookups are guarded; ensure IDs/classes match the HTML.

class DocumentNotificationSystem {
    constructor() {
        this.documents = [];
        this.pendingDocuments = [];
        this.completedDocuments = [];
        this.filteredDocuments = [];
        this.currentDocument = null;
        this.currentUser = window.currentUser || null;
        this.apiBase = '../api/documents.php';
        this.signatureImage = null;
        this.currentSignatureMap = null;
        this.pdfDoc = null;
        this.filledPdfBytes = null; // Keep filled PDF bytes for signing
        this.currentPage = 1;
        this.totalPages = 1;
        this.scale = 1.0;
        this.canvas = null;
        this.ctx = null;
        this.searchTerm = '';
        this.currentFilter = 'all';
        this.sortOption = 'date_desc';
        this.isLoading = false;
        this.retryCount = 0;
        this.maxRetries = 3;
    }

    // Initialize the application with enhanced error handling
    async init() {
        try {
            // Validate user access
            const userHasAccess = this.currentUser &&
                (this.currentUser.role === 'employee' ||
                    (this.currentUser.role === 'student' && this.currentUser.position === 'SSC President'));

            if (!userHasAccess) {
                console.error('Access denied: Invalid user role or position');
                window.location.href = 'user-login.php?error=access_denied';
                return;
            }

            this.setupLoadingState();
            await this.loadDocuments();
            this.renderDocuments();
            this.setupEventListeners();
            this.updateStatsDisplay();
            this.setupSearchFunctionality();

            // Add resize listener for fit to width
            window.addEventListener('resize', this.debounce(() => {
                if (this.pdfDoc) this.fitToWidth();
            }, 250));

            // Setup periodic refresh for real-time updates
            this.setupPeriodicRefresh();

        } catch (error) {
            console.error('Failed to initialize DocumentNotificationSystem:', error);
            this.showToast({
                type: 'error',
                title: 'Initialization Error',
                message: 'Failed to load the document system. Please refresh the page.'
            });
        }
    }

    // Navigate back to dashboard from detail view
    goBack() {
        const dashboard = document.getElementById('dashboardView');
        const detail = document.getElementById('documentView');
        if (dashboard && detail) {
            detail.style.display = 'none';
            dashboard.style.display = 'block';
        }
        this.currentDocument = null;
        this.pdfDoc = null;
        this.currentPage = 1;
        this.scale = 1.0;
    }

    showNotifications() {
        const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
        const list = document.getElementById('notificationsList');
        if (list && window.notifications) {
            list.innerHTML = window.notifications.map(n => {
                let icon = 'bi-bell'; // Default icon
                if (n.type === 'pending_document' || n.type === 'new_document' || n.type === 'document_status') icon = 'bi-file-earmark-text';
                else if (n.type === 'upcoming_event' || n.type === 'event_reminder') icon = 'bi-calendar-event';
                else if (n.type === 'pending_material' || n.type === 'material_status') icon = 'bi-image';
                else if (n.type === 'new_user') icon = 'bi-person-plus';
                else if (n.type === 'security_alert') icon = 'bi-shield-exclamation';
                else if (n.type === 'account') icon = 'bi-key';
                else if (n.type === 'system') icon = 'bi-gear';

                return `
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1"><i class="bi ${icon} me-2"></i>${n.title}</h6>
                        <small>${new Date(n.timestamp).toLocaleDateString()}</small>
                    </div>
                    <p class="mb-1">${n.message}</p>
                </div>
            `;
            }).join('');
        }
        modal.show();
    }

    // Load documents from API
    async loadDocuments() {
        try {
            const response = await fetch(this.apiBase, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });
            const data = await response.json();
            if (data.success) {
                this.documents = data.documents || [];
                // Separate pending and completed
                this.pendingDocuments = this.documents.filter(doc => !doc.user_action_completed);
                this.completedDocuments = this.documents.filter(doc => doc.user_action_completed);
            } else {
                console.error('Failed to load documents:', data.message);
                this.showToast({ type: 'error', title: 'Error', message: 'Failed to load documents' });
            }
        } catch (error) {
            console.error('Error loading documents:', error);
            this.showToast({ type: 'error', title: 'Error', message: 'Error loading documents' });
        }
    }

    // Setup search functionality
    setupSearchFunctionality() {
        const searchInput = document.getElementById('documentSearch');
        const clearButton = document.getElementById('clearSearch');

        if (searchInput) {
            searchInput.addEventListener('input', this.debounce((e) => {
                this.searchTerm = e.target.value.trim().toLowerCase();
                this.applyFiltersAndSearch();
            }, 300));
        }

        if (clearButton) {
            clearButton.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                this.searchTerm = '';
                this.applyFiltersAndSearch();
            });
        }
    }

    // Setup periodic refresh for real-time updates
    setupPeriodicRefresh() {
        // Refresh every 30 seconds when page is visible
        this.refreshInterval = setInterval(() => {
            if (!document.hidden && !this.isLoading) {
                this.refreshDocuments();
            }
        }, 30000);

        // Clear interval when page becomes hidden
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(this.refreshInterval);
            } else {
                this.setupPeriodicRefresh();
            }
        });
    }

    // Refresh documents without full reload
    async refreshDocuments() {
        try {
            const response = await fetch(this.apiBase + '?t=' + Date.now(), {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            if (data.success) {
                const newDocuments = data.documents || [];
                const hasChanges = this.hasDocumentChanges(newDocuments);

                if (hasChanges) {
                    this.documents = newDocuments;
                    this.applyFiltersAndSearch();
                    this.updateStatsDisplay();

                    // Show notification if new documents arrived
                    if (newDocuments.length > this.documents.length) {
                        this.showToast({
                            type: 'info',
                            title: 'New Documents',
                            message: 'New documents are available for review.'
                        });
                    }
                }
            }
        } catch (error) {
            console.warn('Failed to refresh documents:', error);
            // Don't show error toast for background refresh
        }
    }

    // Check if documents have changed
    hasDocumentChanges(newDocuments) {
        if (this.documents.length !== newDocuments.length) return true;

        return newDocuments.some((newDoc, index) => {
            const oldDoc = this.documents[index];
            return !oldDoc || oldDoc.id !== newDoc.id || oldDoc.status !== newDoc.status;
        });
    }

    // Setup loading state management
    setupLoadingState() {
        this.loadingOverlay = document.createElement('div');
        this.loadingOverlay.className = 'loading-overlay d-none';
        this.loadingOverlay.innerHTML = `
            <div class="loading-spinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div class="loading-text mt-2">Loading documents...</div>
            </div>
        `;
        document.body.appendChild(this.loadingOverlay);
    }

    // Show/hide loading overlay
    setLoading(loading) {
        this.isLoading = loading;
        if (this.loadingOverlay) {
            this.loadingOverlay.classList.toggle('d-none', !loading);
        }
    }

    // Setup event listeners with enhanced keyboard support
    setupEventListeners() {
        // Add keyboard navigation support
        document.addEventListener('keydown', (e) => {
            // Global shortcuts
            if (e.key === 'Escape' && document.getElementById('documentView').style.display === 'block') {
                this.goBack();
                return;
            }

            // Search focus shortcut
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('documentSearch');
                if (searchInput) searchInput.focus();
                return;
            }

            // PDF viewer keyboard shortcuts
            if (this.pdfDoc && document.getElementById('documentView').style.display === 'block') {
                switch (e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.prevPage();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.nextPage();
                        break;
                    case '+':
                    case '=':
                        e.preventDefault();
                        this.zoomIn();
                        break;
                    case '-':
                        e.preventDefault();
                        this.zoomOut();
                        break;
                    case 'Home':
                        e.preventDefault();
                        this.goToPage(1);
                        break;
                    case 'End':
                        e.preventDefault();
                        this.goToPage(this.totalPages);
                        break;
                }
            }
        });

        // Sort select event listener
        const sortSelect = document.getElementById('sortSelect');
        if (sortSelect) {
            sortSelect.value = this.sortOption; // Set default value
            sortSelect.addEventListener('change', (e) => {
                this.sortOption = e.target.value;
                this.applyFiltersAndSearch();
            });
        }

        // Add scroll optimization for large lists
        this.setupVirtualScrolling();
    }

    // Setup virtual scrolling for performance
    setupVirtualScrolling() {
        const container = document.getElementById('documentsContainer');
        if (!container) return;

        let scrollTimeout;
        container.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                this.handleScrollVisibility();
            }, 100);
        });
    }

    // Handle scroll visibility optimizations
    handleScrollVisibility() {
        const cards = document.querySelectorAll('.document-card');
        cards.forEach(card => {
            const rect = card.getBoundingClientRect();
            const isVisible = rect.top < window.innerHeight && rect.bottom > 0;

            if (isVisible) {
                card.classList.add('visible');
            }
        });
    }

    // Apply both filters and search
    applyFiltersAndSearch() {
        let filtered = [];

        // Apply status filter
        if (this.currentFilter === 'all') {
            filtered = [...this.pendingDocuments, ...this.completedDocuments];
        } else if (this.currentFilter === 'submitted') {
            filtered = this.pendingDocuments.filter(doc => doc.status !== 'signed' && doc.status !== 'rejected');
        } else if (this.currentFilter === 'in_review') {
            filtered = this.pendingDocuments.filter(doc => doc.status === 'in_review');
        } else if (this.currentFilter === 'approved') {
            filtered = [...this.pendingDocuments, ...this.completedDocuments].filter(doc => doc.status === 'approved');
        } else if (this.currentFilter === 'rejected') {
            filtered = this.completedDocuments.filter(doc => doc.status === 'rejected');
        } else {
            filtered = this.pendingDocuments.filter(doc => doc.status === this.currentFilter);
        }

        // Apply search filter
        if (this.searchTerm) {
            const term = this.searchTerm.toLowerCase();
            filtered = filtered.filter(doc =>
                (doc.title && doc.title.toLowerCase().includes(term)) ||
                (doc.doc_type && this.formatDocType(doc.doc_type).toLowerCase().includes(term)) ||
                (doc.student?.department && doc.student.department.toLowerCase().includes(term)) ||
                (doc.from && doc.from.toLowerCase().includes(term))
            );
        }

        // Apply sorting
        filtered = this.sortDocuments([...filtered], this.sortOption);

        this.filteredDocuments = filtered;
        this.renderFilteredDocuments();
    }

    // Render filtered documents
    renderFilteredDocuments() {
        const container = document.getElementById('documentsContainer');
        if (!container) return;

        container.innerHTML = '';

        if (this.filteredDocuments.length === 0) {
            const emptyMessage = this.searchTerm ?
                'No documents match your search criteria.' :
                'No documents found for the selected filter.';
            container.innerHTML = `
                <div class="col-12 text-center py-5">
                    <div class="empty-state">
                        <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                        <h4 class="mt-3">No Documents Found</h4>
                        <p class="text-muted">${emptyMessage}</p>
                    </div>
                </div>
            `;
            return;
        }

        this.filteredDocuments.forEach(doc => {
            const isCompleted = this.completedDocuments.some(c => c.id === doc.id);
            const card = this.createDocumentCard(doc, isCompleted);
            container.appendChild(card);
        });

        this.updateStatsDisplay();
    }

    // Sort documents based on selected option
    sortDocuments(documents, sortOption) {
        // Create a copy to avoid modifying the original
        const docs = [...documents];

        return docs.sort((a, b) => {
            let result = 0;

            switch (sortOption) {
                case 'date_desc':
                    // Newest first - using uploaded_at field
                    const dateA = a.uploaded_at ? new Date(a.uploaded_at).getTime() : 0;
                    const dateB = b.uploaded_at ? new Date(b.uploaded_at).getTime() : 0;
                    result = dateB - dateA;
                    // Secondary sort by title if dates are equal
                    if (result === 0) {
                        result = (a.title || '').localeCompare(b.title || '');
                    }
                    break;
                case 'date_asc':
                    // Oldest first
                    const dateAscA = a.uploaded_at ? new Date(a.uploaded_at).getTime() : 0;
                    const dateAscB = b.uploaded_at ? new Date(b.uploaded_at).getTime() : 0;
                    result = dateAscA - dateAscB;
                    // Secondary sort by title if dates are equal
                    if (result === 0) {
                        result = (a.title || '').localeCompare(b.title || '');
                    }
                    break;
                case 'due_desc':
                    // Due date - soonest first (earliest dates first)
                    const dueA = (a.date ? new Date(a.date).getTime() :
                        a.earliest_start_time ? new Date(a.earliest_start_time).getTime() :
                            a.uploaded_at ? new Date(a.uploaded_at).getTime() : 0);
                    const dueB = (b.date ? new Date(b.date).getTime() :
                        b.earliest_start_time ? new Date(b.earliest_start_time).getTime() :
                            b.uploaded_at ? new Date(b.uploaded_at).getTime() : 0);
                    result = dueA - dueB;
                    // Secondary sort by title if dates are equal
                    if (result === 0) {
                        result = (a.title || '').localeCompare(b.title || '');
                    }
                    break;
                case 'due_asc':
                    // Due date - latest first (latest dates first)
                    const dueAscA = (a.date ? new Date(a.date).getTime() :
                        a.earliest_start_time ? new Date(a.earliest_start_time).getTime() :
                            a.uploaded_at ? new Date(a.uploaded_at).getTime() : 0);
                    const dueAscB = (b.date ? new Date(b.date).getTime() :
                        b.earliest_start_time ? new Date(b.earliest_start_time).getTime() :
                            b.uploaded_at ? new Date(b.uploaded_at).getTime() : 0);
                    result = dueAscB - dueAscA;
                    // Secondary sort by title if dates are equal
                    if (result === 0) {
                        result = (a.title || '').localeCompare(b.title || '');
                    }
                    break;
                case 'name_asc':
                    // A-Z by title
                    result = (a.title || '').toLowerCase().localeCompare((b.title || '').toLowerCase());
                    break;
                case 'name_desc':
                    // Z-A by title
                    result = (b.title || '').toLowerCase().localeCompare((a.title || '').toLowerCase());
                    break;
                default:
                    result = 0;
            }

            return result;
        });
    }

    // Utility function for debouncing
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Render documents in dashboard (old cards design)
    renderDocuments() {
        const container = document.getElementById('documentsContainer');
        if (!container) return;

        // Add loading state
        container.innerHTML = '<div class="col-12 text-center"><div class="loading" style="height: 200px;"></div></div>';

        setTimeout(() => {
            container.innerHTML = '';

            // Render pending documents
            this.pendingDocuments.forEach(doc => {
                const card = this.createDocumentCard(doc);
                container.appendChild(card);
            });
            // Render completed documents as history
            if (this.completedDocuments.length > 0) {
                const historyHeader = document.createElement('h5');
                historyHeader.className = 'mt-4 mb-3 text-muted';
                historyHeader.textContent = 'Recent History';
                container.appendChild(historyHeader);
                this.completedDocuments.forEach(doc => {
                    const card = this.createDocumentCard(doc, true); // Pass readOnly flag
                    container.appendChild(card);
                });
            }

            this.updateStatsDisplay();
        }, 300);
    }

    // Create document card (old cards design)
    createDocumentCard(doc, readOnly = false) {
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4';

        const statusInfo = this.getStatusInfo(doc.status);
        const dueDate = this.getDueDate(doc);
        const daysUntilDue = this.getDaysUntilDue(dueDate);
        const progressPct = this.computeProgress(doc);
        const fromWho = doc.student?.department || doc.from || 'Unknown';
        const docType = this.formatDocType(doc.doc_type || doc.type);

        col.innerHTML = `
            <div class="card document-card h-100 ${readOnly ? 'opacity-50' : ''}" ${readOnly ? '' : `onclick="documentSystem.openDocument(${doc.id})"`} tabindex="0" role="button" aria-label="Open ${this.escapeHtml(doc.title)}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h6 class="card-title mb-0 fw-semibold">${this.escapeHtml(doc.title)}</h6>
                        <span class="status-badge ${this.escapeHtml(doc.status)}">
                            <i class="bi bi-${statusInfo.icon} me-1"></i>${String(doc.status || '').toUpperCase()}
                        </span>
                    </div>

                    <div class="document-info mb-3">
                        <div class="d-flex align-items-center text-muted mb-2">
                            <i class="bi bi-folder me-2"></i>
                            <span class="fs-sm">${this.escapeHtml(docType)}</span>
                        </div>
                        <div class="d-flex align-items-center text-muted mb-2">
                            <i class="bi bi-person me-2"></i>
                            <span class="fs-sm">From: ${this.escapeHtml(fromWho)}</span>
                        </div>
                        <div class="d-flex align-items-center text-muted">
                            <i class="bi bi-calendar me-2"></i>
                            <span class="fs-sm">Due: ${dueDate ? this.formatDate(dueDate) : '—'}</span>
                            ${daysUntilDue !== null ?
                `<span class="badge ${daysUntilDue <= 1 ? 'bg-danger' : daysUntilDue <= 3 ? 'bg-warning' : 'bg-info'} ms-2 fs-sm">${daysUntilDue === 0 ? 'Today' : daysUntilDue === 1 ? '1 day' : `${daysUntilDue} days`}</span>`
                : '<span class="badge bg-secondary ms-2 fs-sm">Overdue</span>'}
                        </div>
                    </div>

                    <div class="workflow-preview">
                        <small class="text-muted">Workflow Progress:</small>
                        <div class="progress mt-1" style="height: 6px;">
                            <div class="progress-bar ${doc.status === 'submitted' ? 'bg-danger' : doc.status === 'in_review' ? 'bg-warning' : 'bg-info'}" style="width: ${progressPct}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Keyboard support for opening on Enter/Space
        const cardEl = col.querySelector('.document-card');
        if (!readOnly) {
            cardEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.openDocument(doc.id);
                }
            });
        }

        return col;
    }

    // Helpers for due date and progress (compatible with API)
    getDueDate(doc) {
        // Prefer explicit due_date if provided by API
        const explicit = doc.due_date || doc.dueDate;
        if (explicit) {
            const d = new Date(explicit);
            return isNaN(d.getTime()) ? null : d;
        }
        // Fallback: derive from uploaded_at and status
        const uploaded = doc.uploaded_at ? new Date(doc.uploaded_at) : null;
        if (!uploaded || isNaN(uploaded.getTime())) return null;
        const base = new Date(uploaded.getTime());
        const addDays = (n) => new Date(uploaded.getTime() + n * 86400000);
        switch (doc.status) {
            case 'submitted':
                return addDays(2);
            case 'in_review':
                return addDays(4);
            case 'approved':
                return addDays(0);
            case 'rejected':
                return addDays(0);
            default:
                return base;
        }
    }

    getDaysUntilDue(dueDate) {
        if (!dueDate) return null;
        const now = new Date();
        const diffMs = dueDate.getTime() - now.getTime();
        const days = Math.ceil(diffMs / 86400000);
        return days < 0 ? null : days;
    }

    computeProgress(doc) {
        const wf = Array.isArray(doc.workflow) ? doc.workflow : [];
        if (wf.length === 0) return 0;

        // Progress based on current_step (hierarchy level)
        const currentStep = doc.current_step || 1;
        const totalSteps = wf.length;

        // If current_step > totalSteps, it's completed
        if (currentStep > totalSteps) return 100;

        return Math.round(((currentStep - 1) / totalSteps) * 100);
    }

    // Escape for safe HTML injection
    escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Get status information
    getStatusInfo(status) {
        const statusMap = {
            submitted: { icon: 'clock', class: 'warning', label: 'Pending Review' },
            in_review: { icon: 'hourglass-split', class: 'info', label: 'In Review' },
            approved: { icon: 'check-circle', class: 'success', label: 'Approved' },
            rejected: { icon: 'x-circle', class: 'danger', label: 'Rejected' }
        };
        return statusMap[status] || statusMap.submitted;
    }

    // Format document type
    formatDocType(type) {
        const typeMap = {
            proposal: 'Research Proposal',
            saf: 'Student Activity Form',
            facility: 'Facility Request',
            communication: 'Communication'
        };
        return typeMap[type] || type;
    }

    // Format date for display
    formatDate(dateString) {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // Filter documents by status
    filterDocuments(status) {
        this.currentFilter = status;
        this.applyFiltersAndSearch();
    }

    // Open inline detail instead of modal
    async openDocument(docId) {
        const skeleton = this.documents.find(d => d.id === docId);
        if (!skeleton) return;
        this.currentDocument = skeleton;

        try {
            const response = await fetch(`../api/documents.php?id=${docId}`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });
            if (!response.ok) throw new Error('Failed to load document details');
            const fullDoc = await response.json();
            this.currentDocument = fullDoc;
            this.renderDocumentDetail(fullDoc);
        } catch (error) {
            console.error('Error loading document:', error);
            // Fallback to skeleton if API fails
            this.renderDocumentDetail(this.currentDocument);
            this.showToast({ type: 'warning', title: 'Offline', message: 'Showing cached details.' });
        }
    }

    // Render detail section inline
    renderDocumentDetail(doc) {
        const dashboard = document.getElementById('dashboardView');
        const detail = document.getElementById('documentView');
        if (!dashboard || !detail) return;

        dashboard.style.display = 'none';
        detail.style.display = 'block';

        // Status badge
        const statusInfo = this.getStatusInfo(doc.status);
        const docStatus = document.getElementById('docStatus');
        if (docStatus) {
            docStatus.textContent = statusInfo.label;
            docStatus.className = `status-badge ${statusInfo.class}`;
        }

        // Title & file info
        const docTitle = document.getElementById('docTitle');
        const pdfFileName = document.getElementById('pdfFileName');
        const pdfTitle = document.getElementById('pdfTitle');
        if (docTitle) docTitle.textContent = doc.title || 'Document';
        const fileName = (doc.file_path || '').split('/').pop() || 'Document.pdf';
        if (pdfFileName) pdfFileName.textContent = fileName;
        if (pdfTitle) pdfTitle.textContent = doc.description || 'Document content preview';

        // PDF host with PDF.js
        const pdfContent = document.getElementById('pdfContent');
        if (pdfContent) {
            pdfContent.innerHTML = '<canvas id="pdfCanvas"></canvas>';
            this.canvas = document.getElementById('pdfCanvas');
            this.ctx = this.canvas.getContext('2d');
            if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                // Handle absolute paths, relative paths, and filenames for backward compatibility
                let pdfUrl = doc.file_path;
                if (pdfUrl.startsWith('/')) {
                    // Absolute path from server root, use as is
                } else if (pdfUrl.startsWith('../')) {
                    // Already relative to views directory
                } else if (pdfUrl.startsWith('http')) {
                    // Full URL
                } else {
                    // Just filename, assume in uploads directory
                    pdfUrl = '../uploads/' + pdfUrl;
                }
                this.loadPdf(pdfUrl);
            } else {
                const ph = document.createElement('div');
                ph.className = 'pdf-placeholder';
                ph.innerHTML = `<h4>${doc.title}</h4><p>${doc.description || ''}</p>`;
                pdfContent.appendChild(ph);
            }
            this.initClickToPlace(pdfContent); // Enable click-to-place
        }

        // Modern workflow timeline with enhanced hierarchy display
        const workflowSteps = document.getElementById('workflowSteps');
        if (workflowSteps) {
            const steps = doc.workflow || [];
            const currentStepIndex = steps.findIndex(step => step.status === 'pending');

            workflowSteps.innerHTML = steps.map((step, index) => {
                const isCompleted = step.status === 'completed';
                const isPending = step.status === 'pending';
                const isRejected = step.status === 'rejected';
                const isWaiting = step.status === 'waiting';
                const isCurrent = index === currentStepIndex;

                // Determine role hierarchy level
                const hierarchyLevel = this.getHierarchyLevel(step.name);
                const hierarchyIcon = this.getHierarchyIcon(hierarchyLevel);

                // Status-specific styling and messages
                let statusClass = 'waiting';
                let statusMessage = 'Waiting for previous steps';
                let statusIcon = 'bi-circle';

                if (isCompleted) {
                    statusClass = 'completed';
                    statusMessage = 'Approved and signed';
                    statusIcon = 'bi-check-circle-fill';
                } else if (isRejected) {
                    statusClass = 'rejected';
                    statusMessage = 'Rejected - requires revision';
                    statusIcon = 'bi-x-circle-fill';
                } else if (isPending) {
                    statusClass = 'pending';
                    statusMessage = 'Awaiting your approval';
                    statusIcon = 'bi-hourglass-split';
                }

                return `
                    <div class="workflow-step-modern ${statusClass} ${isCurrent ? 'current-step' : ''}" data-step="${index + 1}">
                        <div class="step-icon-modern ${statusClass}">
                            <i class="bi ${statusIcon}"></i>
                        </div>
                        <div class="step-info-modern">
                            <div class="step-name-modern">
                                ${hierarchyIcon} ${this.escapeHtml(step.name)}
                                ${isCurrent ? '<span class="current-indicator">← Current</span>' : ''}
                            </div>
                            <div class="step-assignee-modern">
                                ${this.escapeHtml(step.assignee_name || 'Unassigned')}
                                ${hierarchyLevel > 0 ? `<span class="hierarchy-badge level-${hierarchyLevel}">Level ${hierarchyLevel}</span>` : ''}
                            </div>
                            <div class="step-date-modern">
                                ${isCompleted ? `Completed: ${new Date(step.acted_at).toLocaleDateString()}` :
                        isPending ? 'Pending action required' :
                            isRejected ? `Rejected: ${new Date(step.acted_at).toLocaleDateString()}` :
                                'Not started yet'}
                            </div>
                            ${step.note ? `<div class="step-note-modern">"${this.escapeHtml(step.note)}"</div>` : ''}
                            <div class="step-status-message ${statusClass}">${statusMessage}</div>
                        </div>
                    </div>`;
            }).join('');
        }

        // Load existing notes into textarea (from pending step)
        const notesInput = document.getElementById('notesInput');
        if (notesInput) {
            const pendingStep = doc.workflow?.find(s => s.status === 'pending');
            notesInput.value = pendingStep?.note || '';
        }

        // Rebind notes debounce
        if (notesInput) {
            notesInput.removeEventListener('input', this._notesHandler || (() => { }));
            this._notesHandler = this.debounce(() => this.saveNotes(), 500);
            notesInput.addEventListener('input', this._notesHandler);
        }

        // Show/hide approval buttons based on user permissions
        this.updateApprovalButtonsVisibility(doc);
    }

    // Update visibility of approval buttons based on current user and document workflow
    updateApprovalButtonsVisibility(doc) {
        const signBtn = document.querySelector('.action-btn-full.success');
        const rejectBtn = document.querySelector('.action-btn-full.danger');
        const signaturePadToggle = document.getElementById('signaturePadToggle');
        const signatureStatusContainer = document.getElementById('signatureStatusContainer');

        if (!signBtn || !rejectBtn || !signaturePadToggle || !signatureStatusContainer) return;

        // Check if this is a completed document (read-only history)
        if (doc.user_action_completed) {
            signBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
            signaturePadToggle.style.display = 'none';
            signatureStatusContainer.style.display = 'none';
            return;
        }

        // Find the pending step
        const pendingStep = doc.workflow?.find(step => step.status === 'pending');

        if (!pendingStep) {
            // No pending steps, hide all approval UI
            signBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
            signaturePadToggle.style.display = 'none';
            signatureStatusContainer.style.display = 'none';
            return;
        }

        // Check if current user is assigned to the pending step
        const currentUser = this.currentUser;
        const isAssigned = this.isUserAssignedToStep(currentUser, pendingStep);

        if (isAssigned) {
            // User can approve, show approval buttons
            signBtn.style.display = 'flex';
            rejectBtn.style.display = 'flex';
            signaturePadToggle.style.display = 'block';
            signatureStatusContainer.style.display = 'block';
        } else {
            // User cannot approve, hide approval buttons
            signBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
            signaturePadToggle.style.display = 'none';
            signatureStatusContainer.style.display = 'none';
        }
    }

    // Check if the current user is assigned to the given workflow step
    isUserAssignedToStep(user, step) {
        if (!user || !step || !step.assignee_id) return false;

        // Check if the assignee matches the current user
        if (step.assignee_type === 'employee' && user.role === 'employee' && step.assignee_id === user.id) {
            return true;
        }

        if (step.assignee_type === 'student' && user.role === 'student' && step.assignee_id === user.id) {
            return true;
        }

        return false;
    }

    // Load PDF with PDF.js
    async loadPdf(url) {
        const loadingDiv = document.getElementById('pdfLoading');
        const pdfContent = document.getElementById('pdfContent');
        if (loadingDiv) loadingDiv.style.display = 'flex';
        if (pdfContent) pdfContent.style.display = 'none';
        try {
            this.filledPdfBytes = null; // Clear any previous filled PDF data
            const pdfjsLib = window['pdfjsLib'];
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            this.pdfDoc = await pdfjsLib.getDocument(url).promise;
            this.totalPages = this.pdfDoc.numPages;
            this.currentPage = 1;

            // For SAF documents, fill form fields with data before rendering
            if (this.currentDocument && this.currentDocument.doc_type === 'saf') {
                await this.fillSafFormFields();
            }

            // Create canvas for PDF rendering in modern container
            const canvas = document.createElement('canvas');
            canvas.id = 'pdfCanvas';
            canvas.style.maxWidth = '100%';
            canvas.style.height = 'auto';
            canvas.style.boxShadow = '0 10px 30px rgba(15, 23, 42, 0.15)';
            canvas.style.borderRadius = '12px';
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');

            // Replace loading content with canvas
            if (pdfContent) {
                pdfContent.innerHTML = '';
                pdfContent.appendChild(canvas);
                pdfContent.style.display = 'flex';
            }

            // Auto-fit to width when PDF loads
            this.fitToWidth();

            // Render signature overlay after PDF is loaded
            if (this.currentDocument) {
                this.renderSignatureOverlay(this.currentDocument);
            }
        } catch (error) {
            console.error('Error loading PDF:', error);
            this.showToast({ type: 'error', title: 'Error', message: 'Failed to load PDF' });
        } finally {
            if (loadingDiv) loadingDiv.style.display = 'none';
            if (pdfContent) pdfContent.style.display = 'flex';
        }
    }

    // Render current page to canvas
    async renderPage() {
        if (!this.pdfDoc || !this.canvas) return;

        console.debug('Rendering page', this.currentPage);

        const page = await this.pdfDoc.getPage(this.currentPage);
        const viewport = page.getViewport({ scale: this.scale });
        this.canvas.height = viewport.height;
        this.canvas.width = viewport.width;
        const renderContext = {
            canvasContext: this.ctx,
            viewport: viewport
        };
        await page.render(renderContext).promise;
        this.updatePageControls();

        // Re-render signature overlays after the page is rendered
        setTimeout(() => {
            if (this.currentDocument) {
                console.debug('Re-rendering signature overlays for page', this.currentPage);
                this.renderSignatureOverlay(this.currentDocument);
            }
        }, 100);
    }

    // Update page controls
    updatePageControls() {
        const pageInput = document.getElementById('pageInput');
        const pageTotal = document.getElementById('pageTotal');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');
        if (pageTotal) pageTotal.textContent = this.totalPages;
        if (pageInput) pageInput.value = this.currentPage;
        if (prevBtn) prevBtn.disabled = this.currentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.currentPage >= this.totalPages;
    }

    // Navigate to previous page
    prevPage() {
        if (this.currentPage > 1) {
            this.currentPage--;
            this.renderPage();
        }
    }

    // Navigate to next page
    nextPage() {
        if (this.currentPage < this.totalPages) {
            this.currentPage++;
            this.renderPage();
        }
    }

    // Go to specific page
    goToPage(pageNum) {
        pageNum = parseInt(pageNum);
        if (pageNum >= 1 && pageNum <= this.totalPages) {
            console.debug('Navigating to page', pageNum, 'from', this.currentPage);
            this.currentPage = pageNum;
            this.renderPage().then(() => {
                // Re-render signature overlays when page changes
                if (this.currentDocument) {
                    this.renderSignatureOverlay(this.currentDocument);
                }
            });
        }
    }

    // Zoom in
    zoomIn() {
        this.scale = Math.min(this.scale + 0.25, 3.0);
        this.renderPage();
        this.updateZoomIndicator();
    }

    // Zoom out
    zoomOut() {
        this.scale = Math.max(this.scale - 0.25, 0.5);
        this.renderPage();
        this.updateZoomIndicator();
    }

    // Fit to width
    fitToWidth() {
        if (!this.pdfDoc) return;
        const container = document.getElementById('pdfContent');
        const containerWidth = container.clientWidth - 40; // Padding
        this.pdfDoc.getPage(this.currentPage).then(page => {
            const viewport = page.getViewport({ scale: 1 });
            this.scale = containerWidth / viewport.width;
            // Render page then update overlays after the canvas has been updated
            this.renderPage().then(() => {
                this.updateZoomIndicator();
                // Re-render overlays (signature targets and blurs)
                try { this.renderSignatureOverlay(this.currentDocument); } catch (e) { console.warn('renderSignatureOverlay error', e); }
            });
        });
    }

    // Reset zoom
    resetZoom() {
        this.scale = 1.0;
        this.renderPage();
        this.updateZoomIndicator();
    }

    // Update zoom indicator
    updateZoomIndicator() {
        const zoomIndicator = document.getElementById('zoomIndicator');
        if (zoomIndicator) zoomIndicator.textContent = `${Math.round(this.scale * 100)}%`;
    }

    // Overlay signature target area based on signature_map (inline)
    renderSignatureOverlay(doc) {
        const content = document.getElementById('pdfContent');
        if (!content) return;

        content.style.position = 'relative';

        // Clear previous overlays to prevent duplicates
        content.querySelectorAll('.signature-target, .completed-signature-container').forEach(el => el.remove());

        // First, render completed signatures as blurred overlays
        this.renderCompletedSignatures(doc, content);

        // Then render the current signature target if applicable
        const hasPendingStep = doc.workflow?.some(step => step.status === 'pending');
        const isCurrentUserAssigned = this.isUserAssignedToPendingStep(doc);

        if (hasPendingStep && isCurrentUserAssigned && doc.signature_map) {
            let map = doc.signature_map;
            try {
                map = (typeof map === 'string') ? JSON.parse(map) : map;
            } catch (e) {
                console.warn('Invalid doc.signature_map JSON', e);
            }

            let box = content.querySelector('.signature-target');
            if (!box) {
                box = document.createElement('div');
                box.className = 'signature-target draggable';
                box.title = 'Drag to move, resize handle to adjust size';
                box.style.position = 'absolute';
                box.style.display = 'flex';
                box.style.alignItems = 'center';
                box.style.justifyContent = 'center';
                box.style.cursor = 'grab';
                box.style.zIndex = 20;
                content.appendChild(box);
                this.makeDraggable(box, content);
                this.makeResizable(box, content);
            }

            const rect = this.computeSignaturePixelRect(map);
            if (!rect) return;

            // Debug info
            console.debug('renderSignatureOverlay rect:', rect, 'map:', map);

            box.style.left = rect.left + 'px';
            box.style.top = rect.top + 'px';
            box.style.width = rect.width + 'px';
            box.style.height = rect.height + 'px';
            box.textContent = map.label || 'Sign here';

            // Update the current signature map to reflect the box position
            this.updateSignatureMap(box, content);

            if (this.signatureImage) this.updateSignatureOverlayImage();
        }
    }

    // Render completed signatures as blurred overlays with timestamps
    renderCompletedSignatures(doc, content) {
        if (!doc.workflow) return;

        // Position completed signatures relative to the rendered canvas to keep them in sync
        const canvas = this.canvas;
        if (!canvas) return;
        const canvasRect = canvas.getBoundingClientRect();

        // Find completed steps with signatures
        const completedSignatures = doc.workflow.filter(step =>
            step.status === 'completed' && step.signed_at
        );

        // Check if current user is the document issuer (sender)
        const isIssuer = this.currentUser && doc.student && doc.student.id === this.currentUser.id;

        completedSignatures.forEach((step, index) => {
            // Get signature map for this step
            let signatureMap = null;
            try {
                if (step.signature_map) {
                    signatureMap = typeof step.signature_map === 'string'
                        ? JSON.parse(step.signature_map)
                        : step.signature_map;
                } else if (doc.signature_map) {
                    // Fallback to document signature map
                    signatureMap = typeof doc.signature_map === 'string'
                        ? JSON.parse(doc.signature_map)
                        : doc.signature_map;
                }
            } catch (e) {
                console.warn('Failed to parse signature map', e);
                return;
            }

            if (!signatureMap) return;

            // CRITICAL FIX: Check if this signature belongs on the current page
            const signaturePage = signatureMap.page || 1;
            if (signaturePage !== this.currentPage) {
                return; // Skip rendering on wrong page
            }

            const { x_pct, y_pct, w_pct, h_pct } = signatureMap;

            // Calculate pixel position
            let pixelRect = this.computeSignaturePixelRect({
                x_pct: x_pct || 0.62,
                y_pct: y_pct || 0.78,
                w_pct: w_pct || 0.28,
                h_pct: h_pct || 0.1
            });

            if (!pixelRect) return;

            // Create container for timestamp and redaction
            const signatureContainer = document.createElement('div');
            signatureContainer.className = 'completed-signature-container';
            signatureContainer.style.position = 'absolute';
            signatureContainer.style.left = pixelRect.left + 'px';
            signatureContainer.style.top = pixelRect.top + 'px';
            signatureContainer.style.width = pixelRect.width + 'px';
            signatureContainer.style.height = pixelRect.height + 'px';
            signatureContainer.style.zIndex = '15'; // Below draggable signature target

            if (isIssuer) {
                // Issuer sees the actual signature (unredacted)
                const signatureDetail = document.createElement('div');
                signatureDetail.className = 'signature-detail';
                signatureDetail.style.width = '100%';
                signatureDetail.style.height = '100%';
                signatureDetail.style.display = 'flex';
                signatureDetail.style.alignItems = 'center';
                signatureDetail.style.justifyContent = 'center';
                signatureDetail.style.borderRadius = '4px';
                signatureDetail.style.background = 'rgba(255,255,255,0.9)';
                signatureDetail.style.color = '#111827';
                signatureDetail.style.fontWeight = '600';
                signatureDetail.style.fontSize = '12px';
                signatureDetail.style.padding = '4px';
                signatureDetail.style.textAlign = 'center';
                signatureDetail.textContent = `Signed by ${step.assignee_name || 'Unknown'}`;

                signatureContainer.appendChild(signatureDetail);
            } else {
                // Non-issuer: apply redaction (solid black box, no text, no signature)
                const redactionBox = document.createElement('div');
                redactionBox.className = 'signature-redaction';
                redactionBox.style.width = '100%';
                redactionBox.style.height = '100%';
                redactionBox.style.backgroundColor = '#000000'; // Black for redaction
                redactionBox.style.display = 'block';
                redactionBox.style.borderRadius = '4px';
                // Remove any text or signature image
                signatureContainer.appendChild(redactionBox);
            }

            // Add timestamp below the redaction box
            const timestamp = document.createElement('div');
            timestamp.className = 'signature-timestamp';
            timestamp.textContent = new Date(step.signed_at).toLocaleString();
            timestamp.style.fontSize = '10px';
            timestamp.style.color = '#666';
            timestamp.style.textAlign = 'center';
            timestamp.style.marginTop = '4px';
            timestamp.style.fontWeight = '500';
            timestamp.style.position = 'absolute';
            timestamp.style.top = '100%';
            timestamp.style.left = '0';
            timestamp.style.width = '100%';

            signatureContainer.appendChild(timestamp);
            content.appendChild(signatureContainer);
        });
    }

    // Check if current user is assigned to any pending step
    isUserAssignedToPendingStep(doc) {
        if (!doc.workflow || !this.currentUser) return false;

        const pendingStep = doc.workflow.find(step => step.status === 'pending');
        if (!pendingStep) return false;

        return this.isUserAssignedToStep(this.currentUser, pendingStep);
    }

    // Make signature target draggable
    makeDraggable(element, container) {
        let isDragging = false;
        let startX, startY, initialX, initialY;

        element.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('resize-handle')) return; // Don't drag if resizing
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialX = element.offsetLeft;
            initialY = element.offsetTop;
            element.style.cursor = 'grabbing';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            let newX = initialX + dx;
            let newY = initialY + dy;
            const containerRect = container.getBoundingClientRect();
            const elementRect = element.getBoundingClientRect();
            // Constrain to container bounds, but also ensure it doesn't go outside the PDF canvas area
            const pdfCanvas = document.getElementById('pdf-canvas-container') || container;
            const pdfRect = pdfCanvas.getBoundingClientRect();
            const maxX = Math.min(containerRect.width - elementRect.width, pdfRect.width - elementRect.width);
            const maxY = Math.min(containerRect.height - elementRect.height, pdfRect.height - elementRect.height);
            newX = Math.max(0, Math.min(newX, maxX));
            newY = Math.max(0, Math.min(newY, maxY));
            element.style.left = newX + 'px';
            element.style.top = newY + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                element.style.cursor = 'grab';
                this.updateSignatureMap(element, container); // Save position
            }
        });
    }

    // Make signature target resizable
    makeResizable(element, container) {
        const handleBR = document.createElement('div');
        handleBR.className = 'resize-handle';
        handleBR.style.bottom = '0';
        handleBR.style.right = '0';
        handleBR.style.cursor = 'se-resize';
        element.appendChild(handleBR);

        const handleBL = document.createElement('div');
        handleBL.className = 'resize-handle';
        handleBL.style.bottom = '0';
        handleBL.style.left = '0';
        handleBL.style.cursor = 'sw-resize';
        element.appendChild(handleBL);

        const handleTR = document.createElement('div');
        handleTR.className = 'resize-handle';
        handleTR.style.top = '0';
        handleTR.style.right = '0';
        handleTR.style.cursor = 'ne-resize';
        element.appendChild(handleTR);

        const handleTL = document.createElement('div');
        handleTL.className = 'resize-handle';
        handleTL.style.top = '0';
        handleTL.style.left = '0';
        handleTL.style.cursor = 'nw-resize';
        element.appendChild(handleTL);

        let isResizing = false;
        let resizeMode = null;
        let startX, startY, startWidth, startHeight, startLeft, startTop;

        const startResize = (e, mode) => {
            isResizing = true;
            resizeMode = mode;
            startX = e.clientX;
            startY = e.clientY;
            startWidth = element.offsetWidth;
            startHeight = element.offsetHeight;
            startLeft = element.offsetLeft;
            startTop = element.offsetTop;
            e.preventDefault();
            e.stopPropagation();
        };

        handleBR.addEventListener('mousedown', (e) => startResize(e, 'br'));
        handleBL.addEventListener('mousedown', (e) => startResize(e, 'bl'));
        handleTR.addEventListener('mousedown', (e) => startResize(e, 'tr'));
        handleTL.addEventListener('mousedown', (e) => startResize(e, 'tl'));

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            let newWidth = startWidth;
            let newHeight = startHeight;
            let newLeft = startLeft;
            let newTop = startTop;

            if (resizeMode === 'br') {
                newWidth = startWidth + dx;
                newHeight = startHeight + dy;
            } else if (resizeMode === 'bl') {
                newWidth = startWidth - dx;
                newHeight = startHeight + dy;
                newLeft = startLeft + dx;
            } else if (resizeMode === 'tr') {
                newWidth = startWidth + dx;
                newHeight = startHeight - dy;
                newTop = startTop + dy;
            } else if (resizeMode === 'tl') {
                newWidth = startWidth - dx;
                newHeight = startHeight - dy;
                newLeft = startLeft + dx;
                newTop = startTop + dy;
            }

            const containerRect = container.getBoundingClientRect();
            newWidth = Math.max(80, Math.min(newWidth, containerRect.width - newLeft));
            newHeight = Math.max(50, Math.min(newHeight, containerRect.height - newTop));

            element.style.left = newLeft + 'px';
            element.style.top = newTop + 'px';
            element.style.width = newWidth + 'px';
            element.style.height = newHeight + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                resizeMode = null;
                this.updateSignatureMap(element, container);
            }
        });
    }

    // Update signature map with current position/size (fix: relative to container)
    updateSignatureMap(element, container) {
        const canvas = this.canvas;
        if (!canvas) return;

        const canvasRect = canvas.getBoundingClientRect();
        const elRect = element.getBoundingClientRect();

        const x_pct = (elRect.left - canvasRect.left) / canvasRect.width;
        const y_pct = (elRect.top - canvasRect.top) / canvasRect.height;
        const w_pct = elRect.width / canvasRect.width;
        const h_pct = elRect.height / canvasRect.height;

        // Clamp values between 0 and 1 for safety
        const clamp = (v) => Math.max(0, Math.min(1, v));

        this.currentSignatureMap = {
            x_pct: clamp(x_pct),
            y_pct: clamp(y_pct),
            w_pct: clamp(w_pct),
            h_pct: clamp(h_pct),
            label: 'Sign here',
            page: this.currentPage  // <-- IMPORTANT: Store the page number
        };

        console.debug('updateSignatureMap ->', this.currentSignatureMap);
    }

    // Compute pixel rectangle from a signature map (supports percent values or absolute px)
    computeSignaturePixelRect(map) {
        const canvas = this.canvas;
        if (!canvas) return null;
        const canvasRect = canvas.getBoundingClientRect();
        const content = document.getElementById('pdfContent');
        const contentRect = content ? content.getBoundingClientRect() : { left: 0, top: 0 };

        // Helper to normalize a value that might be percent (0..1) or absolute px (>1)
        const norm = (v, axisSize, canvasAttrSize) => {
            if (v == null) return 0;
            // If value looks like percent
            if (v <= 1) return v * axisSize;
            // If value is larger than 1, assume it's provided in canvas pixel coordinates (canvas.width/canvas.height)
            // Convert from canvas pixel space to CSS layout pixels
            const ratio = canvasAttrSize && axisSize ? (axisSize / canvasAttrSize) : 1;
            return v * ratio;
        };

        // canvas.width/canvas.height refer to actual drawing buffer (device pixels)
        const canvasAttrWidth = canvas.width || canvasRect.width;
        const canvasAttrHeight = canvas.height || canvasRect.height;

        const xRaw = map.x_pct ?? map.x_px ?? 0;
        const yRaw = map.y_pct ?? map.y_px ?? 0;
        const wRaw = map.w_pct ?? map.w_px ?? 0;
        const hRaw = map.h_pct ?? map.h_px ?? 0;

        const left = (canvasRect.left - contentRect.left) + norm(xRaw, canvasRect.width, canvasAttrWidth);
        const top = (canvasRect.top - contentRect.top) + norm(yRaw, canvasRect.height, canvasAttrHeight);
        const width = Math.max(1, norm(wRaw, canvasRect.width, canvasAttrWidth));
        const height = Math.max(1, norm(hRaw, canvasRect.height, canvasAttrHeight));

        const rect = { left, top, width, height, canvasRect, contentRect };
        console.debug('computeSignaturePixelRect', { map, rect });
        return rect;
    }

    // Allow click-to-place new signature target
    initClickToPlace(container) {
        container.addEventListener('click', (e) => {
            if (e.target.closest('.signature-target')) return; // Don't place if clicking existing
            const rect = container.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            let box = container.querySelector('.signature-target');
            if (!box) {
                box = document.createElement('div');
                box.className = 'signature-target draggable';
                box.title = 'Drag to move, resize handle to adjust size';
                container.appendChild(box);
                this.makeDraggable(box, container);
                this.makeResizable(box, container);
                // Removed click listener to prevent accidental reopening of signature pad
            }
            box.style.left = x + 'px';
            box.style.top = y + 'px';
            box.style.width = '120px'; // Default size
            box.style.height = '60px';
            box.textContent = 'Sign here';
            this.updateSignatureMap(box, container);
        });
    }

    // Initialize inline signature pad
    initSignaturePad() {
        const container = document.getElementById('signaturePadContainer');
        const canvas = document.getElementById('signatureCanvas');
        if (!container || !canvas) return;

        // Prevent re-initialization if already set up
        if (canvas.dataset.initialized) return;
        canvas.dataset.initialized = 'true';

        const width = container.clientWidth ? Math.min(container.clientWidth - 16, 560) : 560;
        canvas.width = width;
        canvas.height = 200;
        const ctx = canvas.getContext('2d');
        // Remove white background fill to keep transparent

        this._initCanvasDrawing(canvas);

        // Handle signature upload
        const uploadInput = document.getElementById('signatureUpload');
        if (uploadInput) {
            uploadInput.onchange = (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        const img = new Image();
                        img.onload = () => {
                            // Clear canvas and draw uploaded image
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            // Calculate scaling to fit canvas while maintaining aspect ratio
                            const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
                            const scaledWidth = img.width * scale;
                            const scaledHeight = img.height * scale;
                            const x = (canvas.width - scaledWidth) / 2;
                            const y = (canvas.height - scaledHeight) / 2;
                            ctx.drawImage(img, x, y, scaledWidth, scaledHeight);
                        };
                        img.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            };
        }

        const clearBtn = document.getElementById('sigClearBtn');
        const saveBtn = document.getElementById('sigSaveBtn');
        if (clearBtn) clearBtn.onclick = () => { ctx.clearRect(0, 0, canvas.width, canvas.height); };
        if (saveBtn) saveBtn.onclick = () => {
            this.signatureImage = canvas.toDataURL('image/png');
            this.updateSignatureOverlayImage();
            // Update modern signature status
            const placeholder = document.getElementById('signaturePlaceholder');
            const signedStatus = document.getElementById('signedStatus');
            if (placeholder) placeholder.classList.add('d-none');
            if (signedStatus) signedStatus.classList.remove('d-none');
            // Hide signature pad after saving
            toggleSignaturePad();
            this.showToast({ type: 'success', title: 'Signature Saved', message: 'Signature ready to apply to document.' });
        };
    }

    _initCanvasDrawing(canvas) {
        let drawing = false;
        const ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#111827';
        ctx.lineWidth = 2.2;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';

        const pos = (e) => {
            const r = canvas.getBoundingClientRect();
            const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
            const y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
            return { x, y };
        };

        const start = (e) => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
        const move = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
        const end = () => { drawing = false; };

        canvas.onmousedown = start; canvas.onmousemove = move; window.addEventListener('mouseup', end);
        canvas.ontouchstart = start; canvas.ontouchmove = move; window.addEventListener('touchend', end);

        const clearBtn = document.getElementById('sigClearBtn');
        const saveBtn = document.getElementById('sigSaveBtn');
        if (clearBtn) clearBtn.onclick = () => { ctx.clearRect(0, 0, canvas.width, canvas.height); };
        if (saveBtn) saveBtn.onclick = () => {
            this.signatureImage = canvas.toDataURL('image/png');
            this.updateSignatureOverlayImage();
            this.showToast({ type: 'success', title: 'Signature Saved', message: 'Signature ready to apply.' });
        };
    }

    updateSignatureOverlayImage() {
        let content = document.getElementById('pdfContent');
        const box = content?.querySelector('.signature-target');
        if (!box || !this.signatureImage) return;
        box.textContent = '';
        const img = new Image();
        img.src = this.signatureImage;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '100%';
        img.style.objectFit = 'contain';
        box.appendChild(img);
    }

    // Apply signature to PDF using PDF-LIB and return blob for upload
    async applySignatureToPdf() {
        if (!this.pdfDoc || !this.signatureImage || !this.currentSignatureMap) return null;
        try {
            // Use filled PDF bytes if available (for SAF documents), otherwise get from PDF.js
            const pdfBytes = this.filledPdfBytes || await this.pdfDoc.getData();
            const pdfDoc = await PDFLib.PDFDocument.load(pdfBytes);
            const pageIndex = (this.currentSignatureMap.page || this.currentPage) - 1; // 0-indexed
            const page = pdfDoc.getPage(pageIndex);
            const { x_pct, y_pct, w_pct, h_pct } = this.currentSignatureMap;
            const pageWidth = page.getWidth();
            const pageHeight = page.getHeight();
            const x = x_pct * pageWidth;
            const y = pageHeight - (y_pct * pageHeight) - (h_pct * pageHeight); // Flip Y
            const width = w_pct * pageWidth;
            const height = h_pct * pageHeight;
            const img = await pdfDoc.embedPng(this.signatureImage);
            page.drawImage(img, { x, y, width, height });
            const modifiedPdfBytes = await pdfDoc.save();
            // Return blob for upload
            return new Blob([modifiedPdfBytes], { type: 'application/pdf' });
        } catch (error) {
            console.error('Error applying signature:', error);
            throw error;
        }
    }

    // Fill SAF form fields with document data
    async fillSafFormFields() {
        if (!this.pdfDoc || !this.currentDocument) return;

        try {
            // Get original PDF bytes
            const pdfBytes = await this.pdfDoc.getData();

            // Load with PDF-lib for modification
            const pdfDocLib = await PDFLib.PDFDocument.load(pdfBytes);
            const form = pdfDocLib.getForm();
            const data = this.currentDocument.data || {};

            // Get all form fields to check what exists
            const fields = form.getFields();
            const fieldNames = fields.map(field => field.getName());

            console.log('Available PDF form fields:', fieldNames);
            console.log('SAF data to fill:', data);
            console.log('Current document data:', this.currentDocument.data);

            // Fill date fields - only if they exist in the PDF
            const dateFields = ['reqDate', 'dNoteDate', 'hNoteDate', 'recDate', 'appDate', 'releaseDate'];
            dateFields.forEach(field => {
                try {
                    if (fieldNames.includes(field) && data[field]) {
                        const textField = form.getTextField(field);
                        // Format date nicely for display
                        const dateValue = new Date(data[field]);
                        const formattedDate = dateValue.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                        textField.setText(formattedDate);
                        console.log(`Filled date field ${field} with formatted value: ${formattedDate} (original: ${data[field]})`);
                    }
                } catch (fieldError) {
                    console.warn(`Could not fill date field ${field}:`, fieldError.message);
                }
            });

            // Fill signatory name fields - only if they exist in the PDF
            const signatoryFields = ['studentRepresentative', 'collegeDean', 'oic-osa', 'vpaa', 'evp', 'acp'];
            signatoryFields.forEach(field => {
                try {
                    if (fieldNames.includes(field) && data[field]) {
                        const textField = form.getTextField(field);
                        textField.setText(data[field]);
                        console.log(`Filled signatory ${field} with value: ${data[field]}`);
                    }
                } catch (fieldError) {
                    console.warn(`Could not fill signatory ${field}:`, fieldError.message);
                }
            });

            // Fill checkbox fields - try checkbox fields first, then text fields
            const checkboxFields = ['sscChecked', 'cscChecked'];
            checkboxFields.forEach(field => {
                try {
                    if (fieldNames.includes(field)) {
                        const isChecked = data[field] && data[field] !== '';
                        console.log(`Processing checkbox ${field}, data value: "${data[field]}", should be checked: ${isChecked}`);
                        
                        // Try checkbox field first
                        try {
                            const checkBox = form.getCheckBox(field);
                            if (isChecked) {
                                checkBox.check();
                            } else {
                                checkBox.uncheck();
                            }
                            console.log(`Set checkbox ${field} to ${isChecked ? 'checked' : 'unchecked'}`);
                        } catch (checkboxError) {
                            // If checkbox field doesn't exist, try text field
                            console.warn(`Checkbox field ${field} not found, trying text field:`, checkboxError.message);
                            const textField = form.getTextField(field);
                            textField.setText(isChecked ? '✓' : '');
                            console.log(`Filled text field ${field} with value: ${isChecked ? '✓' : ''}`);
                        }
                    }
                } catch (fieldError) {
                    console.warn(`Could not fill checkbox ${field}:`, fieldError.message);
                }
            });

            // Save modified PDF bytes for signing
            this.filledPdfBytes = await pdfDocLib.save();

            // Reload with PDF.js for display
            const pdfjsLib = window['pdfjsLib'];
            this.pdfDoc = await pdfjsLib.getDocument({data: this.filledPdfBytes}).promise;
            this.totalPages = this.pdfDoc.numPages;
        } catch (error) {
            console.error('Error filling SAF form fields:', error);
            // Continue with original PDF if filling fails
        }
    }

    // Get action buttons for document modal
    getActionButtons(doc) {
        const currentStep = doc.workflow.find(step => step.status === 'pending');
        if (!currentStep) return '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';

        // Check if current user is assigned to this step
        const isAssigned = currentStep.assignee_id === this.currentUser?.id;

        if (!isAssigned) {
            return '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
        }

        return `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-danger" onclick="documentSystem.rejectDocument(${doc.id})">
                <i class="bi bi-x-circle me-1"></i>Reject
            </button>
            <button type="button" class="btn btn-success" onclick="documentSystem.signDocument(${doc.id})">
                <i class="bi bi-check-circle me-1"></i>Sign & Approve
            </button>
        `;
    }

    // Format step status
    formatStepStatus(status) {
        const statusMap = {
            pending: 'Pending',
            completed: 'Completed',
            rejected: 'Rejected'
        };
        return statusMap[status] || status;
    }

    // Sign document
    async signDocument(docId) {
        if (!confirm('Are you sure you want to sign and approve this document?')) return;

        try {
            // Apply signature to PDF if available
            const signedPdfBlob = await this.applySignatureToPdf();

            // Find step id: prefer currentDocument pending step for accuracy
            const doc = this.currentDocument && this.currentDocument.id === docId
                ? this.currentDocument
                : this.documents.find(d => d.id === docId);
            const pendingStep = doc?.workflow?.find(s => s.status === 'pending');
            const stepId = pendingStep?.id || undefined;

            // Prepare form data for file upload
            const formData = new FormData();
            formData.append('action', 'sign');
            formData.append('document_id', docId);
            formData.append('step_id', stepId);
            // Note: signature_image is not sent to avoid saving it on the server
            if (this.currentSignatureMap) formData.append('signature_map', JSON.stringify(this.currentSignatureMap));
            if (signedPdfBlob) formData.append('signed_pdf', signedPdfBlob, 'signed_document.pdf');

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000);  // 30-second timeout

            const response = await fetch('../api/documents.php', {
                method: 'POST',
                body: formData,  // Use FormData for file upload
                signal: controller.signal
            });
            clearTimeout(timeoutId);
            const result = await response.json();

            if (result.success) {
                this.showToast({
                    type: 'success',
                    title: 'Document Signed',
                    message: 'Document has been successfully signed and approved. The updated file has been saved and passed to the next signer.'
                });

                // Clear signature from memory after successful signing
                this.signatureImage = null;
                this.currentSignatureMap = null;

                // Refresh document data from server to get updated dates/names
                try {
                    const response = await fetch(`../api/documents.php?id=${docId}`, {
                        method: 'GET',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    if (response.ok) {
                        const freshDoc = await response.json();
                        this.currentDocument = freshDoc;
                        doc = freshDoc; // Use fresh data for rendering
                        this.filledPdfBytes = null; // Clear filled PDF so it gets re-filled with new data
                    }
                } catch (refreshError) {
                    console.warn('Could not refresh document data after signing:', refreshError);
                }

                // Update local document status to 'approved' and step to 'completed'
                if (doc) {
                    doc.status = 'approved';
                    if (pendingStep) {
                        pendingStep.status = 'completed';
                        pendingStep.acted_at = new Date().toISOString();
                        pendingStep.signed_at = new Date().toISOString();
                    }
                }

                // Re-render the detail view to show updated status and signatures (do NOT go back to dashboard)
                this.renderDocumentDetail(doc);

                // Refresh data in background for dashboard (but stay on current view)
                await this.loadDocuments();
                // Do not call renderDocuments() or goBack() here
            } else {
                throw new Error(result.message || 'Failed to sign document');
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                this.showToast({
                    type: 'error',
                    title: 'Timeout',
                    message: 'Signing timed out. Please refresh and try again.'
                });
            } else {
                console.error('Error signing document:', error);
                this.showToast({
                    type: 'error',
                    title: 'Error',
                    message: 'Failed to sign document. Please try again.'
                });
            }
        }

        if (window.addAuditLog) {
            window.addAuditLog('DOCUMENT_SIGNED', 'Document Management', `Signed document ${docId}`, docId, 'Document', 'INFO');
        }
    }

    async rejectDocument(docId, reason) {
        if (!reason || reason.trim() === '') return;

        try {
            const doc = this.currentDocument && this.currentDocument.id === docId
                ? this.currentDocument
                : this.documents.find(d => d.id === docId);
            const pendingStep = doc?.workflow?.find(s => s.status === 'pending');
            const stepId = pendingStep?.id || undefined;

            const response = await fetch('../api/documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reject', document_id: docId, reason: reason.trim(), step_id: stepId })
            });
            const result = await response.json();

            if (result.success) {
                this.showToast({
                    type: 'warning',
                    title: 'Document Rejected',
                    message: 'Document has been rejected.'
                }
                );

                // Update local document status to 'rejected' and step to 'rejected'
                if (doc) {
                    doc.status = 'rejected';
                    if (pendingStep) {
                        pendingStep.status = 'rejected';
                        pendingStep.acted_at = new Date().toISOString();
                        pendingStep.note = reason.trim();
                    }
                }

                // Re-render the detail view to show updated status (do NOT go back to dashboard)
                this.renderDocumentDetail(doc);

                // Refresh data in background for dashboard (but stay on current view)
                await this.loadDocuments();
                // Do not call renderDocuments() or goBack() here
            } else {
                throw new Error(result.message || 'Failed to reject document');
            }
        } catch (error) {
            console.error('Error rejecting document:', error);
            this.showToast({
                type: 'error',
                title: 'Error',
                message: 'Failed to reject document. Please try again.'
            });
        }

        if (window.addAuditLog) {
            window.addAuditLog('DOCUMENT_REJECTED', 'Document Management', `Rejected document ${docId}: ${reason}`, docId, 'Document', 'WARNING');
        }
    }

    // Update statistics display
    updateStatsDisplay() {
        const urgentCount = this.documents.filter(doc => doc.status === 'submitted').length;
        const highCount = this.documents.filter(doc => doc.status === 'in_review').length;
        const normalCount = this.documents.filter(doc => doc.status === 'approved').length;

        const elements = {
            urgentCount: document.getElementById('urgentCount'),
            highCount: document.getElementById('highCount'),
            normalCount: document.getElementById('normalCount'),
            pendingCount: document.getElementById('pendingCount'),
            notificationCount: document.getElementById('notificationCount')
        };

        if (elements.urgentCount) elements.urgentCount.textContent = urgentCount;
        if (elements.highCount) elements.highCount.textContent = highCount;
        if (elements.normalCount) elements.normalCount.textContent = normalCount;
        if (elements.pendingCount) elements.pendingCount.textContent = this.documents.length;
        if (elements.notificationCount) {
            elements.notificationCount.textContent = urgentCount + highCount;
            elements.notificationCount.style.display = (urgentCount + highCount) > 0 ? 'flex' : 'none';
        }
    }

    showToast(options) {
        if (window.addAuditLog) {
            window.addAuditLog('NOTIFICATION_SHOWN', 'Notifications', `Toast shown: ${options.message}`, null, 'Notification', 'INFO');
        }
        if (window.ToastManager) {
            window.ToastManager.show(options);
        } else {
            alert(`${options.title}: ${options.message}`);
        }
    }

    // Get hierarchy level for visual indication
    getHierarchyLevel(stepName) {
        const name = stepName.toLowerCase();
        if (name.includes('adviser') || name.includes('organization')) return 1;
        if (name.includes('president') || name.includes('dean')) return 2;
        if (name.includes('osa') || name.includes('affairs')) return 3;
        if (name.includes('cpao') || name.includes('planning')) return 4;
        if (name.includes('vpaa') || name.includes('academic')) return 5;
        if (name.includes('evp') || name.includes('executive')) return 6;
        return 0;
    }

    // Get hierarchy icon based on level
    getHierarchyIcon(level) {
        const icons = {
            1: '🎓', // Student/Adviser level
            2: '👨‍🏫', // Faculty level
            3: '🏛️', // Administrative level
            4: '📋', // Operations level
            5: '🎓', // Academic leadership
            6: '👑'  // Executive level
        };
        return icons[level] || '📄';
    }

    // Save notes (placeholder)
    saveNotes() {
        if (!this.currentDocument) {
            console.warn('No current document to save notes for');
            return;
        }

        const notesInput = document.getElementById('notesInput');
        if (!notesInput) {
            console.warn('Notes input element not found');
            return;
        }

        const note = notesInput.value.trim();
        const pendingStep = this.currentDocument.workflow?.find(s => s.status === 'pending');
        if (!pendingStep) {
            console.warn('No pending step found for notes');
            return;
        }

        // Don't save empty notes unless there was a previous note
        // if (note === '' && (pendingStep.note || '') === '') {
        //     return;
        // }

        // Show saving indicator
        const saveIndicator = document.getElementById('notesSaveIndicator');
        if (saveIndicator) {
            saveIndicator.textContent = 'Saving...';
            saveIndicator.style.color = '#f59e0b';
        }

        fetch(this.apiBase, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'update_note',
                document_id: this.currentDocument.id,
                step_id: pendingStep.id,
                note: note
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update local document data
                    pendingStep.note = note;

                    // Update save indicator
                    if (saveIndicator) {
                        saveIndicator.textContent = 'Saved';
                        saveIndicator.style.color = '#10b981';
                        setTimeout(() => {
                            saveIndicator.textContent = '';
                        }, 2000);
                    }

                    // Re-render the document to show updated notes
                    this.renderDocumentDetail(this.currentDocument);

                    console.log('Notes saved successfully');
                } else {
                    throw new Error(data.message || 'Failed to save notes');
                }
            })
            .catch(error => {
                console.error('Error saving notes:', error);

                // Update save indicator with error
                if (saveIndicator) {
                    saveIndicator.textContent = 'Error saving';
                    saveIndicator.style.color = '#ef4444';
                    setTimeout(() => {
                        saveIndicator.textContent = '';
                    }, 3000);
                }

                // Show toast notification
                if (window.ToastManager) {
                    window.ToastManager.error('Failed to save notes: ' + error.message, 'Error');
                }
            });
    }
}

// PDF Zoom Functions
function zoomInPDF(pdfObject) {
    if (pdfObject && pdfObject.style) {
        const currentHeight = parseInt(pdfObject.style.height) || 600;
        const newHeight = Math.min(currentHeight + 100, 1200);
        pdfObject.style.height = newHeight + 'px';
    }
}

function zoomOutPDF(pdfObject) {
    if (pdfObject && pdfObject.style) {
        const currentHeight = parseInt(pdfObject.style.height) || 600;
        const newHeight = Math.max(currentHeight - 100, 300);
        pdfObject.style.height = newHeight + 'px';
    }
}

function resetZoomPDF(pdfObject) {
    if (pdfObject && pdfObject.style) {
        pdfObject.style.height = '600px';
    }
}

// Navbar functions
function openProfileSettings() {
    // Populate form with current user data
    if (window.currentUser) {
        document.getElementById('profileFirstName').value = window.currentUser.firstName || '';
        document.getElementById('profileLastName').value = window.currentUser.lastName || '';
        document.getElementById('profileEmail').value = window.currentUser.email || '';
        document.getElementById('profilePhone').value = '';
        document.getElementById('darkModeToggle').checked = localStorage.getItem('darkMode') === 'true';
    }

    const modal = new bootstrap.Modal(document.getElementById('profileSettingsModal'));
    modal.show();
}

function openPreferences() {
    // Load preferences from localStorage
    const emailNotifications = localStorage.getItem('emailNotifications') !== 'false'; // default true
    const browserNotifications = localStorage.getItem('browserNotifications') !== 'false'; // default true
    const defaultView = localStorage.getItem('defaultView') || 'month';

    document.getElementById('emailNotifications').checked = emailNotifications;
    document.getElementById('browserNotifications').checked = browserNotifications;
    document.getElementById('defaultView').value = defaultView;

    const modal = new bootstrap.Modal(document.getElementById('preferencesModal'));
    modal.show();
}

function showHelp() {
    const modal = new bootstrap.Modal(document.getElementById('helpModal'));
    modal.show();
}

function savePreferences() {
    const emailNotifications = document.getElementById('emailNotifications').checked;
    const browserNotifications = document.getElementById('browserNotifications').checked;
    const defaultView = document.getElementById('defaultView').value;

    // Save to localStorage
    localStorage.setItem('emailNotifications', emailNotifications);
    localStorage.setItem('browserNotifications', browserNotifications);
    localStorage.setItem('defaultView', defaultView);

    // Show success message
    const messagesDiv = document.getElementById('preferencesMessages');
    if (messagesDiv) {
        messagesDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Preferences saved successfully!</div>';
        setTimeout(() => messagesDiv.innerHTML = '', 3000);
    }

    // Close modal
    bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
}

// Handle profile settings form
document.addEventListener('DOMContentLoaded', function () {
    const profileForm = document.getElementById('profileSettingsForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const firstName = document.getElementById('profileFirstName').value;
            const lastName = document.getElementById('profileLastName').value;
            const email = document.getElementById('profileEmail').value;
            const phone = document.getElementById('profilePhone').value;
            const darkMode = document.getElementById('darkModeToggle').checked;
            const messagesDiv = document.getElementById('profileSettingsMessages');

            if (!firstName || !lastName || !email) {
                if (messagesDiv) messagesDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Please fill in all required fields.</div>';
                return;
            }

            // Save dark mode preference
            localStorage.setItem('darkMode', darkMode);

            // Show success message
            if (messagesDiv) messagesDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Profile updated successfully!</div>';

            // Apply theme
            document.body.classList.toggle('dark-theme', darkMode);

            // Close modal after a delay
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('profileSettingsModal')).hide();
            }, 1500);
        });
    }
});

// Initialize the document notification system
document.addEventListener('DOMContentLoaded', function () {
    window.documentSystem = new DocumentNotificationSystem();
    window.documentSystem.init();
});