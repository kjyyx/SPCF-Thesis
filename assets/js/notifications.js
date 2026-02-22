/**
 * Show a generic confirmation modal (global helper)
 * @param {string} title - Modal title
 * @param {string} message - Confirmation message
 * @param {Function} onConfirm - Callback function when confirmed
 * @param {string} confirmText - Text for confirm button (default: 'Confirm')
 * @param {string} confirmClass - Bootstrap class for confirm button (default: 'btn-primary')
 */
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
function showConfirmModal(title, message, onConfirm, confirmText = 'Confirm', confirmClass = 'btn-primary') {
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    const modalLabel = document.getElementById('confirmModalLabel');
    const modalMessage = document.getElementById('confirmModalMessage');
    const confirmBtn = document.getElementById('confirmModalBtn');

    if (modalLabel) modalLabel.textContent = title;
    if (modalMessage) modalMessage.textContent = message;
    if (confirmBtn) {
        confirmBtn.textContent = confirmText;
        confirmBtn.className = `btn ${confirmClass}`;
    }

    // Remove previous event listeners
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    // Add new event listener
    newConfirmBtn.addEventListener('click', function () {
        modal.hide();
        onConfirm();
    });

    modal.show();
}
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
        this.apiBase = BASE_URL + 'api/documents.php';
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
        this.currentGroup = 'none'; // New grouping property
        this.isLoading = false;
        this.retryCount = 0;
        this.maxRetries = 3;
        this.isApplyingFilters = false; // Prevent multiple simultaneous filter applications
        this.linkedTargets = null; // Store linked signature targets for SAF
        this.linkedSignatureMaps = null; // Store positions for both signatures
        this.isSafDualSigning = false; // Flag for SAF dual signature mode
        // Full Document Viewer properties
        this.fullPdfDoc = null;
        this.fullCurrentPage = 1;
        this.fullTotalPages = 1;
        this.fullScale = 1.0;
        this.fullCanvas = null;
        this.fullCtx = null;
        // Drag functionality
        this.isDragMode = false;
        this.isDragging = false;
        this.dragStartX = 0;
        this.dragStartY = 0;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        this.threadComments = [];
        this.replyTarget = null;
    }

    // Initialize the application with enhanced error handling
    async init() {
        try {
            // Validate user access: SSC for approval, students for signing if assigned
            const userHasAccess = this.currentUser &&
                (this.currentUser.role === 'employee' ||
                    (this.currentUser.position === 'Supreme Student Council President' ||
                        this.currentUser.position === 'College Student Council President'));

            if (!userHasAccess) {
                console.error('Access denied: Invalid user role or position');
                window.location.href = BASE_URL + '?page=login&error=access_denied';
                return;
            }

            this.setupLoadingState();
            await this.loadDocuments();
            this.renderDocuments();
            this.setupEventListeners();
            this.updateStatsDisplay();
            this.setupSearchFunctionality();
            this.initGroupingControls(); // Initialize grouping controls

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

    // Add this method to initialize grouping controls
    initGroupingControls() {
        const groupOptions = document.querySelectorAll('.group-option');
        const groupTrigger = document.getElementById('groupTrigger');
        const groupMenu = document.getElementById('groupMenu');

        if (!groupOptions.length) return;

        // Remove any existing event listeners to prevent duplicates
        groupOptions.forEach(option => {
            const clone = option.cloneNode(true);
            option.parentNode.replaceChild(clone, option);
        });

        // Re-query the options after cloning
        const freshGroupOptions = document.querySelectorAll('.group-option');

        // Set initial active state
        freshGroupOptions.forEach(opt => {
            if (opt.dataset.group === this.currentGroup) {
                opt.classList.add('active');
            } else {
                opt.classList.remove('active');
            }
        });

        // Update trigger text
        if (groupTrigger) {
            const activeOption = document.querySelector('.group-option.active');
            if (activeOption) {
                const span = activeOption.querySelector('span');
                if (span) {
                    const labelSpan = groupTrigger.querySelector('.group-label');
                    if (labelSpan) labelSpan.textContent = span.textContent;
                }
            }
        }

        // Add click handlers
        freshGroupOptions.forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // Update active states
                freshGroupOptions.forEach(opt => opt.classList.remove('active'));
                option.classList.add('active');
                
                // Update trigger text
                const span = option.querySelector('span');
                if (span && groupTrigger) {
                    const labelSpan = groupTrigger.querySelector('.group-label');
                    if (labelSpan) labelSpan.textContent = span.textContent;
                }
                
                // Set group and re-render
                const newGroup = option.dataset.group;
                if (this.currentGroup !== newGroup) {
                    this.currentGroup = newGroup;
                    this.applyFiltersAndSearch();
                }
                
                // Close menu after rendering
                setTimeout(() => {
                    if (groupMenu) groupMenu.style.display = 'none';
                }, 50);
            });
        });
    }

    // Check if student has pending signatures
    async hasPendingSignaturesForUser() {
        try {
            const response = await fetch(this.apiBase, { method: 'GET', headers: { 'Content-Type': 'application/json' } });
            const data = await response.json();
            if (data.success && data.documents) {
                return data.documents.some(doc => 
                    doc.workflow?.some(step => 
                        step.status === 'pending' && 
                        step.assignee_type === 'student' && 
                        step.assignee_id === this.currentUser.id
                    )
                );
            }
            return false;
        } catch (error) {
            console.error('Error checking pending signatures:', error);
            return false;
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
        // Prevent multiple simultaneous filter applications
        if (this.isApplyingFilters) {
            return;
        }
        
        this.isApplyingFilters = true;
        
        try {
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
        } finally {
            this.isApplyingFilters = false;
        }
    }

    // Enhanced renderFilteredDocuments method
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
                        <i class="bi bi-search text-muted empty-state-icon"></i>
                        <h4 class="mt-3">No Documents Found</h4>
                        <p class="text-muted">${emptyMessage}</p>
                    </div>
                </div>
            `;
            return;
        }

        // Handle grouping
        if (this.currentGroup !== 'none') {
            this.renderGroupedDocuments(container);
        } else {
            // Create a wrapper for ungrouped documents
            const listWrapper = document.createElement('div');
            listWrapper.className = 'documents-list';
            
            this.filteredDocuments.forEach(doc => {
                const card = this.createDocumentCard(doc, false);
                listWrapper.appendChild(card);
            });
            
            container.appendChild(listWrapper);
        }

        this.updateStatsDisplay();
    }

    // Enhanced renderGroupedDocuments method
    renderGroupedDocuments(container) {
        const groups = this.groupDocuments(this.filteredDocuments, this.currentGroup);
        
        // Create wrapper for all groups
        const groupsWrapper = document.createElement('div');
        groupsWrapper.className = 'groups-container';

        // Sort groups alphabetically
        const sortedGroups = Object.keys(groups).sort();

        sortedGroups.forEach(groupKey => {
            const groupDocs = groups[groupKey];
            if (groupDocs.length === 0) return;

            // Create group section
            const groupSection = document.createElement('div');
            groupSection.className = 'document-group';
            groupSection.setAttribute('data-group', groupKey);

            // Create group header with toggle functionality
            const groupHeader = document.createElement('div');
            groupHeader.className = 'document-group-header';
            groupHeader.innerHTML = `
                <div class="group-header-content" role="button" tabindex="0" aria-expanded="true">
                    <div class="group-title-wrapper">
                        <i class="bi ${this.getGroupIcon(this.currentGroup)} group-icon"></i>
                        <h5 class="group-title">
                            ${this.formatGroupLabel(groupKey, this.currentGroup)}
                        </h5>
                        <span class="group-count-badge">${groupDocs.length}</span>
                    </div>
                    <div class="group-actions">
                        <button class="group-toggle-btn" title="Toggle group">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>
            `;

            // Create group container for documents
            const groupContainer = document.createElement('div');
            groupContainer.className = 'document-group-container expanded';

            // Add documents to group
            groupDocs.forEach(doc => {
                const card = this.createDocumentCard(doc, false);
                groupContainer.appendChild(card);
            });

            // Add group stats
            const groupStats = document.createElement('div');
            groupStats.className = 'group-stats';
            
            // Calculate group statistics
            const pendingCount = groupDocs.filter(d => d.status === 'submitted' || d.status === 'in_review').length;
            const completedCount = groupDocs.filter(d => d.status === 'approved' || d.status === 'rejected').length;
            
            groupStats.innerHTML = `
                <div class="group-stats-content">
                    <span class="stat-item">
                        <i class="bi bi-hourglass-split"></i>
                        ${pendingCount} Pending
                    </span>
                    <span class="stat-item">
                        <i class="bi bi-check-circle"></i>
                        ${completedCount} Completed
                    </span>
                    <span class="stat-item">
                        <i class="bi bi-clock"></i>
                        ${this.getGroupDeadlineInfo(groupDocs)}
                    </span>
                </div>
            `;

            groupSection.appendChild(groupHeader);
            groupSection.appendChild(groupContainer);
            groupSection.appendChild(groupStats);
            groupsWrapper.appendChild(groupSection);

            // Add toggle functionality
            const toggleBtn = groupHeader.querySelector('.group-toggle-btn');
            const headerContent = groupHeader.querySelector('.group-header-content');
            
            const toggleGroup = () => {
                const isExpanded = groupContainer.classList.contains('expanded');
                groupContainer.classList.toggle('expanded');
                groupContainer.classList.toggle('collapsed');
                toggleBtn.querySelector('i').className = isExpanded ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
                headerContent.setAttribute('aria-expanded', !isExpanded);
            };

            toggleBtn?.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleGroup();
            });

            headerContent?.addEventListener('click', (e) => {
                if (!e.target.closest('.group-actions')) {
                    toggleGroup();
                }
            });

            headerContent?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleGroup();
                }
            });
        });

        container.appendChild(groupsWrapper);
    }

    // Helper method to get deadline info for group
    getGroupDeadlineInfo(docs) {
        const urgentCount = docs.filter(doc => {
            const dueDate = this.getDueDate(doc);
            const daysLeft = this.getDaysUntilDue(dueDate);
            return daysLeft !== null && daysLeft <= 2;
        }).length;

        if (urgentCount > 0) {
            return `${urgentCount} Urgent`;
        }
        return 'No urgent deadlines';
    }

    // Enhanced groupDocuments method
    groupDocuments(documents, groupBy) {
        const groups = {};

        documents.forEach(doc => {
            let groupKey = 'Other';
            let groupSortKey = '';

            switch (groupBy) {
                case 'doc_type':
                    groupKey = this.getDocumentTypeDisplay(doc.doc_type || doc.document_type || 'Other');
                    groupSortKey = this.getDocumentTypePriority(doc.doc_type || doc.document_type);
                    break;
                    
                case 'department':
                    // Get department from various possible locations
                    const dept = doc.department || 
                                doc.student?.department || 
                                doc.from_department || 
                                'Unknown Department';
                    groupKey = this.formatDepartmentName(dept);
                    groupSortKey = groupKey;
                    break;
                    
                case 'status':
                    groupKey = this.formatStatus(doc.status || doc.current_status || 'Unknown Status');
                    groupSortKey = this.getStatusPriority(doc.status);
                    break;
                    
                default:
                    groupKey = 'All Documents';
                    groupSortKey = '0';
            }

            if (!groups[groupKey]) {
                groups[groupKey] = {
                    docs: [],
                    sortKey: groupSortKey
                };
            }
            
            groups[groupKey].docs.push(doc);
        });

        // Convert to sorted object
        const sortedGroups = {};
        Object.keys(groups)
            .sort((a, b) => {
                const sortA = groups[a].sortKey || a;
                const sortB = groups[b].sortKey || b;
                return sortA.localeCompare(sortB);
            })
            .forEach(key => {
                sortedGroups[key] = groups[key].docs;
            });

        return sortedGroups;
    }

    // Get icon for group type
    getGroupIcon(groupType) {
        const icons = {
            'doc_type': 'bi-file-earmark-text',
            'department': 'bi-building',
            'status': 'bi-flag'
        };
        return icons[groupType] || 'bi-folder';
    }

    // Format group label for display
    formatGroupLabel(key, groupType) {
        if (groupType === 'doc_type') {
            return this.formatDocType(key);
        } else if (groupType === 'status') {
            return key.charAt(0).toUpperCase() + key.slice(1).replace('_', ' ');
        }
        return key;
    }

    // Format document type for display
    formatDocType(type) {
        const typeMap = {
            'saf': 'Student Activity Form',
            'publication': 'Publication',
            'proposal': 'Project Proposal',
            'facility': 'Facility Request',
            'communication': 'Communication'
        };

        return typeMap[type] || type;
    }

    // Helper methods for grouping
    getDocumentTypeDisplay(type) {
        const typeMap = {
            'saf': 'Student Activity Form',
            'publication': 'Publication Material',
            'proposal': 'Project Proposal',
            'facility': 'Facility Request',
            'communication': 'Communication Letter',
            'material_request': 'Material Request',
            'event': 'Event Proposal'
        };
        return typeMap[type] || type;
    }

    getDocumentTypePriority(type) {
        const priorityMap = {
            'saf': '01',
            'proposal': '02',
            'event': '03',
            'facility': '04',
            'material_request': '05',
            'publication': '06',
            'communication': '07'
        };
        return priorityMap[type] || '99' + type;
    }

    formatDepartmentName(dept) {
        // Common department abbreviations
        const deptMap = {
            'CCS': 'College of Computer Studies',
            'CBA': 'College of Business Administration',
            'COE': 'College of Education',
            'CON': 'College of Nursing',
            'CAS': 'College of Arts and Sciences',
            'SSC': 'Supreme Student Council',
            'CSC': 'College Student Council'
        };
        
        // Return full name if available, otherwise return original
        return deptMap[dept] || dept;
    }

    formatStatus(status) {
        const statusMap = {
            'submitted': 'Pending Review',
            'in_review': 'Under Review',
            'approved': 'Approved',
            'rejected': 'Rejected',
            'draft': 'Draft'
        };
        return statusMap[status] || status;
    }

    getStatusPriority(status) {
        const priorityMap = {
            'submitted': '01',
            'in_review': '02',
            'approved': '03',
            'rejected': '04',
            'draft': '05'
        };
        return priorityMap[status] || '99';
    }

    // Helper methods for deadline calculations
    getDueDate(doc) {
        // Try different possible due date fields
        const possibleFields = ['due_date', 'deadline', 'target_date'];
        for (const field of possibleFields) {
            if (doc[field]) {
                return new Date(doc[field]);
            }
        }
        return null;
    }

    getDaysUntilDue(dueDate) {
        if (!dueDate) return null;
        const now = new Date();
        const diffTime = dueDate.getTime() - now.getTime();
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays;
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
        const emptyState = document.getElementById('emptyState');
        const documentCount = document.getElementById('documentCount');
        if (!container) return;

        // Clear container
        container.innerHTML = '';

        // Show loading state
        container.innerHTML = '<div class="col-12 text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';

        setTimeout(() => {
            container.innerHTML = '';

            // Render pending documents
            this.pendingDocuments.forEach(doc => {
                const listItem = this.createDocumentCard(doc);
                container.appendChild(listItem);
            });

            // Render completed documents as history (only for non-students)
            if (this.completedDocuments.length > 0 && this.currentUser.role !== 'student') {
                // Add section divider
                const divider = document.createElement('div');
                divider.className = 'list-section-divider';
                divider.innerHTML = `
                    <div class="divider-line"></div>
                    <span class="divider-text">Recent History</span>
                    <div class="divider-line"></div>
                `;
                container.appendChild(divider);

                this.completedDocuments.forEach(doc => {
                    const listItem = this.createDocumentCard(doc);
                    container.appendChild(listItem);
                });
            }

            // Update document count
            const totalCount = this.pendingDocuments.length + this.completedDocuments.length;
            if (documentCount) {
                documentCount.textContent = `${totalCount} document${totalCount !== 1 ? 's' : ''}`;
            }

            // Show empty state if no documents
            if (totalCount === 0) {
                if (emptyState) emptyState.style.display = 'flex';
                container.style.display = 'none';
            } else {
                if (emptyState) emptyState.style.display = 'none';
                container.style.display = 'block';
            }

            this.updateStatsDisplay();
        }, 300);
    }

    // Create document card (compact list view)
    createDocumentCard(doc, readOnly = false) {
        const listItem = document.createElement('div');
        
        // Check if current user has completed signing this document
        const userCompletedStep = doc.workflow?.find(step => 
            step.status === 'completed' && 
            ((step.assignee_type === 'employee' && step.assignee_id === this.currentUser?.id) ||
             (step.assignee_type === 'student' && step.assignee_id === this.currentUser?.id))
        );
        const userHasSigned = !!userCompletedStep;
        
        // Apply read-only class for visual indication if user has signed
        listItem.className = `document-list-item ${userHasSigned ? 'read-only' : ''}`;
        
        // Always allow clicking to view document status and progress
        listItem.onclick = () => this.openDocument(doc.id);
        listItem.setAttribute('tabindex', '0');
        listItem.setAttribute('role', 'button');
        listItem.setAttribute('aria-label', `Open ${this.escapeHtml(doc.title)}`);
        
        // Add visual indicator for documents user has already signed
        if (userHasSigned) {
            listItem.setAttribute('title', 'You have already signed this document. Click to view status and progress.');
            
            // Add a small checkmark icon to indicate signed status
            const signedIndicator = document.createElement('div');
            signedIndicator.className = 'signed-indicator';
            signedIndicator.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
            signedIndicator.setAttribute('title', 'You have signed this document');
            listItem.appendChild(signedIndicator);
        }

        const statusInfo = this.getStatusInfo(doc.status);
        const dueDate = this.getDueDate(doc);
        const daysUntilDue = this.getDaysUntilDue(dueDate);
        const progressPct = this.computeProgress(doc);
        const fromWho = doc.student?.department || doc.from || 'Unknown';
        const docType = this.formatDocType(doc.doc_type || doc.type);

        // Priority badge based on days until due
        let priorityClass = 'priority-normal';
        let priorityLabel = 'Normal';
        if (daysUntilDue !== null && daysUntilDue <= 1) {
            priorityClass = 'priority-urgent';
            priorityLabel = 'Urgent';
        } else if (daysUntilDue !== null && daysUntilDue <= 3) {
            priorityClass = 'priority-high';
            priorityLabel = 'High';
        }

        listItem.innerHTML = `
            <div class="list-item-left">
                <div class="document-icon ${doc.status}">
                    <i class="bi bi-file-earmark-text-fill"></i>
                </div>
                <div class="document-main-info">
                    <div class="document-title-row">
                        <h5 class="document-title">${this.escapeHtml(doc.title)}</h5>
                        <span class="priority-badge ${priorityClass}">${priorityLabel}</span>
                    </div>
                    <div class="document-meta-row">
                        <span class="meta-item">
                            <i class="bi bi-folder2"></i>
                            ${this.escapeHtml(docType)}
                        </span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item">
                            <i class="bi bi-person"></i>
                            ${this.escapeHtml(fromWho)}
                        </span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item">
                            <i class="bi bi-calendar3"></i>
                            ${dueDate ? this.formatDate(dueDate) : '—'}
                        </span>
                        ${daysUntilDue !== null ? `
                            <span class="meta-separator">•</span>
                            <span class="meta-item time-remaining ${daysUntilDue <= 1 ? 'text-danger' : daysUntilDue <= 3 ? 'text-warning' : ''}">
                                <i class="bi bi-clock"></i>
                                ${daysUntilDue === 0 ? 'Due Today' : daysUntilDue === 1 ? '1 day left' : `${daysUntilDue} days left`}
                            </span>
                        ` : ''}
                    </div>
                </div>
            </div>
            
            <div class="list-item-right">
                <div class="document-status">
                    <span class="status-badge-compact ${this.escapeHtml(doc.status)}">
                        <i class="bi bi-${statusInfo.icon}"></i>
                        <span>${String(doc.status || '').toUpperCase()}</span>
                    </span>
                </div>
                <div class="document-progress">
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar-fill" style="width: ${progressPct}%"></div>
                    </div>
                    <span class="progress-text">${progressPct}%</span>
                </div>
                <button class="list-item-action" onclick="event.stopPropagation(); documentSystem.openDocument(${doc.id})" aria-label="View document">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        `;

        // Keyboard support
        if (!readOnly) {
            listItem.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.openDocument(doc.id);
                }
            });
        }

        return listItem;
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

        // If document is approved, show 100% regardless of individual steps
        if (doc.status === 'approved') return 100;

        // Count completed steps
        const completedSteps = wf.filter(step => step.status === 'completed').length;
        const totalSteps = wf.length;

        // If all steps are completed, show 100%
        if (completedSteps === totalSteps) return 100;

        // Calculate progress based on completed steps
        return Math.round((completedSteps / totalSteps) * 100);
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
            'saf': 'Student Activity Form',
            'publication': 'Publication',
            'material_request': 'Material Request',
            'proposal': 'Project Proposal',
            'facility': 'Facility Request',
            'communication': 'Communication'
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
        
        // Update stat card active states
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            const cardFilter = card.classList.contains('all') ? 'all' :
                              card.classList.contains('pending') ? 'submitted' :
                              card.classList.contains('in-review') ? 'in_review' :
                              card.classList.contains('approved') ? 'approved' : '';
            if (cardFilter === status) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });
        
        this.applyFiltersAndSearch();
    }

    // Open inline detail instead of modal
    async openDocument(docId) {
        const skeleton = this.documents.find(d => d.id === docId);
        if (!skeleton) return;
        this.currentDocument = skeleton;

        try {
            const response = await fetch(BASE_URL + `api/documents.php?id=${docId}`, {
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

        // Check if current user has completed signing this document (read-only mode)
        const userCompletedStep = doc.workflow?.find(step => 
            step.status === 'completed' && 
            ((step.assignee_type === 'employee' && step.assignee_id === this.currentUser?.id) ||
             (step.assignee_type === 'student' && step.assignee_id === this.currentUser?.id))
        );
        const isReadOnly = !!userCompletedStep;

        // Add read-only class to document view if user has completed their signing
        if (isReadOnly) {
            detail.classList.add('read-only');
        } else {
            detail.classList.remove('read-only');
        }

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
                    pdfUrl = BASE_URL + pdfUrl.substring(1);
                } else if (pdfUrl.startsWith('../')) {
                    // Already relative to views directory, convert to absolute
                    pdfUrl = BASE_URL + pdfUrl.substring(3);
                } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                    // Path includes project name, remove it
                    pdfUrl = BASE_URL + pdfUrl.substring(12);
                } else if (pdfUrl.startsWith('http')) {
                    // Full URL
                } else {
                    // Just filename, assume in uploads directory
                    pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
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

        this.initThreadComments(doc);

        // Conditionally hide hierarchy and notes for students
        const isStudentView = this.currentUser?.role === 'student' && (this.currentUser?.position !== 'SSC President' || doc.student?.id === this.currentUser.id);
        let workflowStepsElement = document.getElementById('workflowSteps');
        const commentsContainer = document.querySelector('.comments-container');

        if (workflowStepsElement) {
            if (isStudentView) {
                workflowStepsElement.closest('.sidebar-card').style.display = 'none'; // Hide hierarchy
            } else {
                workflowStepsElement.closest('.sidebar-card').style.display = 'block';
            }
        }

        if (commentsContainer) {
            if (isStudentView) {
                commentsContainer.closest('.sidebar-card').style.display = 'none';
            } else {
                commentsContainer.closest('.sidebar-card').style.display = 'block';
            }
        }

        // Show/hide approval buttons based on user permissions
        this.updateApprovalButtonsVisibility(doc);

        // Hide download button if user has approval responsibilities or document is not fully approved
        const downloadBtn = document.querySelector('.header-actions .action-btn.primary');
        if (downloadBtn) {
            const pendingStep = doc.workflow?.find(step => step.status === 'pending');
            const isAssigned = this.isUserAssignedToStep(this.currentUser, pendingStep);
            if (isAssigned || doc.status !== 'approved') {
                downloadBtn.style.display = 'none';
            } else {
                downloadBtn.style.display = 'inline-block';
            }
        }
    }

    // Update visibility of approval buttons based on current user and document workflow
    updateApprovalButtonsVisibility(doc) {
        console.log('updateApprovalButtonsVisibility called, signatureImage:', this.signatureImage);
        const signBtn = document.querySelector('.action-btn-full.success');
        const rejectBtn = document.querySelector('.action-btn-full.danger');
        const signaturePadToggle = document.getElementById('signaturePadToggle');
        const signatureStatusContainer = document.getElementById('signatureStatusContainer');

        console.log('signBtn found:', !!signBtn);
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
        console.log('pendingStep:', pendingStep);

        // Check user role and assignment
        const currentUser = this.currentUser;
        console.log('currentUser:', currentUser);

        // Special case: allow student creator to submit their own document
        const isStudentCreator = currentUser.role === 'student' && doc.student && doc.student.id === currentUser.id;
        console.log('isStudentCreator:', isStudentCreator);

        if (!pendingStep && !isStudentCreator) {
            // No pending steps and not student creator, hide all approval UI
            console.log('No pending step and not student creator, hiding buttons');
            signBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
            signaturePadToggle.style.display = 'none';
            signatureStatusContainer.style.display = 'none';
            return;
        }

        const isAssigned = this.isUserAssignedToStep(currentUser, pendingStep) || isStudentCreator;
        console.log('isAssigned:', isAssigned);
        const isStudentView = currentUser.role === 'student' && (currentUser.position !== 'SSC President' || doc.student?.id === currentUser.id);
        console.log('isStudentView:', isStudentView);

        if (isAssigned) {
            if (isStudentView) {
                // Students get Submit Document and Delete Document buttons
                signBtn.style.display = 'flex';
                signBtn.querySelector('.btn-title').textContent = 'Submit Document';
                rejectBtn.style.display = 'flex';
                rejectBtn.querySelector('.btn-title').textContent = 'Delete Document';
                rejectBtn.onclick = () => this.deleteDocument(doc.id);
                signaturePadToggle.style.display = 'block';
                signatureStatusContainer.style.display = 'block';
            } else {
                // Employees retain full functionality (sign and reject)
                signBtn.style.display = 'flex';
                signBtn.querySelector('.btn-title').textContent = 'Sign & Approve'; // Keep original for employees
                rejectBtn.style.display = 'flex'; // Show reject for employees
                signaturePadToggle.style.display = 'block';
                signatureStatusContainer.style.display = 'block';
            }

            // Disable the sign/submit button if no signature is set
            if (!this.signatureImage) {
                console.log('Disabling sign button, no signature');
                signBtn.disabled = true;
                signBtn.title = 'Please add your signature first using the signature pad.';
            } else {
                signBtn.disabled = false;
                signBtn.title = '';
            }
        } else {
            // Hide all action controls for users not assigned to pending step
            signBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
            signaturePadToggle.style.display = 'none';
            signatureStatusContainer.style.display = 'none';
        }
    }

    async initThreadComments(doc) {
                    const input = document.getElementById('threadCommentInput');
                    if (input) input.value = '';
                    this.clearReplyTarget();

                    if (!doc?.id) {
                        this.threadComments = [];
                        this.renderThreadComments();
                        return;
                    }

                    await this.loadThreadComments(doc.id);
    }

                async loadThreadComments(documentId) {
                    try {
                        const response = await fetch(`${this.apiBase}?action=get_comments&id=${documentId}`, {
                            method: 'GET',
                            headers: { 'Content-Type': 'application/json' }
                        });

                        const data = await response.json();
                        if (!data.success) throw new Error(data.message || 'Failed to load comments');

                        this.threadComments = Array.isArray(data.comments) ? data.comments : [];
                        this.renderThreadComments();
                    } catch (error) {
                        console.error('Error loading thread comments:', error);
                        this.threadComments = [];
                        this.renderThreadComments();
                    }
                }

                renderThreadComments() {
                    const commentsList = document.getElementById('threadCommentsList');
                    if (!commentsList) return;

                    if (!this.threadComments.length) {
                        commentsList.innerHTML = '<div class="thread-empty-state">No comments yet. Start the discussion.</div>';
                        return;
                    }

                    const groupedByParent = this.threadComments.reduce((acc, comment) => {
                        const key = comment.parent_id === null ? 'root' : String(comment.parent_id);
                        if (!acc[key]) acc[key] = [];
                        acc[key].push(comment);
                        return acc;
                    }, {});

                    const renderNodes = (parentKey, depth = 0) => {
                        const children = groupedByParent[parentKey] || [];
                        return children.map(comment => {
                            const authorName = this.escapeHtml(comment.author_name || 'Unknown');
                            const authorRole = this.escapeHtml(comment.author_role || '');
                            const authorPosition = this.escapeHtml(comment.author_position || '');
                            const commentText = this.escapeHtml(comment.comment || '');
                            const timeText = comment.created_at ? new Date(comment.created_at).toLocaleString() : '';

                            return `
                                <div class="thread-comment-item depth-${Math.min(depth, 4)}" data-comment-id="${comment.id}">
                                    <div class="thread-comment-header">
                                        <span class="thread-comment-author">${authorName}</span>
                                        <span class="thread-comment-meta">${authorRole}${authorPosition ? ` • ${authorPosition}` : ''}</span>
                                    </div>
                                    <div class="thread-comment-body">${commentText}</div>
                                    <div class="thread-comment-actions">
                                        <span class="thread-comment-time">${this.escapeHtml(timeText)}</span>
                                        <button type="button" class="reply-comment-btn" data-comment-id="${comment.id}" data-author-name="${authorName}">
                                            Reply
                                        </button>
                                    </div>
                                    ${renderNodes(String(comment.id), depth + 1)}
                                </div>
                            `;
                        }).join('');
                    };

                    commentsList.innerHTML = renderNodes('root');

                    commentsList.querySelectorAll('.reply-comment-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const commentId = Number(btn.getAttribute('data-comment-id'));
                            const authorName = btn.getAttribute('data-author-name') || 'Unknown';
                            this.setReplyTarget(commentId, authorName);
                        });
                    });
                }

                setReplyTarget(commentId, authorName) {
                    this.replyTarget = { id: Number(commentId), authorName: authorName || 'Unknown' };

                    const banner = document.getElementById('commentReplyBanner');
                    const replyAuthorName = document.getElementById('replyAuthorName');
                    if (replyAuthorName) replyAuthorName.textContent = this.replyTarget.authorName;
                    if (banner) banner.style.display = 'flex';

                    const input = document.getElementById('threadCommentInput');
                    if (input) input.focus();
                }

                clearReplyTarget() {
                    this.replyTarget = null;
                    const banner = document.getElementById('commentReplyBanner');
                    const replyAuthorName = document.getElementById('replyAuthorName');
                    if (replyAuthorName) replyAuthorName.textContent = '';
                    if (banner) banner.style.display = 'none';
                }

                async postComment() {
                    if (!this.currentDocument?.id) return;

                    const input = document.getElementById('threadCommentInput');
                    if (!input) return;

                    const comment = input.value.trim();
                    if (!comment) {
                        this.showToast({ type: 'warning', title: 'Empty Comment', message: 'Please enter a comment.' });
                        return;
                    }

                    const saveIndicator = document.getElementById('notesSaveIndicator');
                    if (saveIndicator) {
                        saveIndicator.textContent = 'Posting...';
                        saveIndicator.style.color = '#f59e0b';
                    }

                    try {
                        const response = await fetch(this.apiBase, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'add_comment',
                                document_id: this.currentDocument.id,
                                comment,
                                parent_id: this.replyTarget?.id || null
                            })
                        });

                        const data = await response.json();
                        if (!data.success) throw new Error(data.message || 'Failed to post comment');

                        input.value = '';
                        this.clearReplyTarget();
                        await this.loadThreadComments(this.currentDocument.id);

                        if (saveIndicator) {
                            saveIndicator.textContent = 'Posted';
                            saveIndicator.style.color = '#10b981';
                            setTimeout(() => {
                                saveIndicator.textContent = '';
                            }, 1500);
                        }
                    } catch (error) {
                        console.error('Error posting comment:', error);
                        if (saveIndicator) {
                            saveIndicator.textContent = 'Error';
                            saveIndicator.style.color = '#ef4444';
                            setTimeout(() => {
                                saveIndicator.textContent = '';
                            }, 2000);
                        }
                        this.showToast({ type: 'error', title: 'Error', message: error.message || 'Failed to post comment.' });
                    }
    }

    saveNotes() {
        this.postComment();
    }

    async loadPdf(url) {
        try {
            let pdfUrl = url;
            if (pdfUrl.startsWith('/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(1);
            } else if (pdfUrl.startsWith('../')) {
                pdfUrl = BASE_URL + pdfUrl.substring(3);
            } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(12);
            } else if (pdfUrl.startsWith('http')) {
            } else {
                pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
            }

            this.pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
            this.totalPages = this.pdfDoc.numPages;
            this.currentPage = 1;
            this.scale = 1.0;

            await this.renderPage();
            this.fitToWidth();
            this.updateZoomIndicator();
        } catch (error) {
            console.error('Error loading PDF:', error);
            this.showToast({
                type: 'error',
                title: 'PDF Error',
                message: 'Failed to load the document preview.'
            });
        }
    }

    // Render current page to canvas
    async renderPage() {
        if (!this.pdfDoc || !this.canvas) return;

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
    // MODIFIED: Overlay signature target area based on signature_map (inline)
    renderSignatureOverlay(doc) {
        const content = document.getElementById('pdfContent');
        if (!content) return;

        content.style.position = 'relative';

        // Clear previous overlays to prevent duplicates
        content.querySelectorAll('.signature-target, .completed-signature-container, .linked-connection').forEach(el => el.remove());

        // First, render completed signatures as blurred overlays
        this.renderCompletedSignatures(doc, content);

        // Then render the current signature target if applicable
        const hasPendingStep = doc.workflow?.some(step => step.status === 'pending');
        const isCurrentUserAssigned = this.isUserAssignedToPendingStep(doc);

        if (hasPendingStep && isCurrentUserAssigned) {
            // SPECIAL HANDLING FOR SAF DOCUMENTS
            if (doc.doc_type === 'saf') {
                this.isSafDualSigning = true;
                this.createLinkedSafSignatureTargets(content, doc);
            } else {
                // Regular single signature for other document types
                this.createSingleSignatureTarget(content, doc);
            }
        }
    }

    // Render completed signatures as blurred overlays with timestamps
// Replace the renderCompletedSignatures method with this updated version
renderCompletedSignatures(doc, container, canvas = null) {
    if (!doc.workflow) return;

    // Clear existing overlays
    const existing = container.querySelectorAll('.completed-signature-container');
    existing.forEach(el => el.remove());

    // Position completed signatures relative to the rendered canvas
    if (!canvas) canvas = container.querySelector('canvas');
    if (!canvas) return;
    
    const canvasRect = canvas.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    // Find completed steps with signatures
    let completedSignatures = doc.workflow.filter(step =>
        step.status === 'completed' && step.signed_at
    );

    completedSignatures.forEach((step, index) => {
        let signatureMap = null;
        try {
            if (step.signature_map) {
                signatureMap = typeof step.signature_map === 'string'
                    ? JSON.parse(step.signature_map)
                    : step.signature_map;
            } else if (doc.signature_map) {
                signatureMap = typeof doc.signature_map === 'string'
                    ? JSON.parse(doc.signature_map)
                    : doc.signature_map;
            }
        } catch (e) {
            console.warn('Failed to parse signature map', e);
            return;
        }

        if (!signatureMap) return;

        // For SAF, use the accounting and issuer maps directly
        let mapsToRender = [signatureMap];
        if (doc.doc_type === 'saf' && typeof signatureMap === 'object' && signatureMap.accounting && signatureMap.issuer) {
            mapsToRender = [signatureMap.accounting, signatureMap.issuer];
        }

        mapsToRender.forEach((map) => {
            const signaturePage = map.page || 1;
            
            // Check if we're in the modal or main view
            const isModal = container.id === 'fullPdfContainer';
            const currentPage = isModal ? this.fullCurrentPage : this.currentPage;
            
            if (signaturePage !== currentPage) {
                return; // Skip rendering on wrong page
            }
            
            let pixelRect = this.computeSignaturePixelRectForContainer(map, canvas, container);
            if (!pixelRect) return;
            
            // Create container for timestamp and redaction
            const signatureContainer = document.createElement('div');
            signatureContainer.className = 'completed-signature-container';
            signatureContainer.style.position = 'absolute';
            signatureContainer.style.left = pixelRect.left + 'px';
            signatureContainer.style.top = pixelRect.top + 'px';

            // Adjust size and layout based on document type
            const isSaf = doc.doc_type === 'saf';
            if (isSaf) {
                signatureContainer.style.width = '120px';
                signatureContainer.style.height = '80px';
            } else {
                signatureContainer.style.width = '200px';
                signatureContainer.style.height = '60px';
            }
            signatureContainer.style.zIndex = '15';

            // Redaction box with signing user's name and position
            const redactionBox = document.createElement('div');
            redactionBox.className = 'signature-redaction';
            redactionBox.style.width = '100%';
            redactionBox.style.height = '100%';
            redactionBox.style.display = 'flex';
            redactionBox.style.flexDirection = isSaf ? 'column' : 'row';
            redactionBox.style.justifyContent = 'center';
            redactionBox.style.alignItems = 'center';
            redactionBox.style.borderRadius = '4px';
            redactionBox.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            redactionBox.style.color = '#fff';
            redactionBox.style.fontWeight = '600';
            redactionBox.style.fontSize = '12px';
            redactionBox.style.padding = '4px';
            redactionBox.style.position = 'relative';

            // Show signing user's name and position with timestamp
            const name = step.assignee_name || 'Unknown';
            const position = step.assignee_position || '';
            const timestamp = new Date(step.signed_at).toLocaleString();

            if (isSaf) {
                redactionBox.innerHTML = `
                    <div style="text-align: center;">
                        <div>${name}</div>
                        <div style="font-size:11px;font-weight:400;">${position}</div>
                    </div>
                    <div style="font-size:10px;font-weight:400;margin-top:4px;text-align:center;">${timestamp}</div>
                `;
            } else {
                redactionBox.innerHTML = `
                    <div>
                        <div>${name}</div>
                        <div style="font-size:11px;font-weight:400;">${position}</div>
                    </div>
                    <div style="font-size:10px;font-weight:400;text-align:right;">${timestamp}</div>
                `;
            }

            signatureContainer.appendChild(redactionBox);
            container.appendChild(signatureContainer);
        });
    });
}

    // Create linked signature targets for SAF documents - FIXED: Better offset calculation
    createLinkedSafSignatureTargets(content, doc) {
        // Clear any existing targets
        this.linkedTargets = null;
        this.linkedSignatureMaps = null;

        // Get initial position from doc signature_map or use default
        let initialMap = null;
        if (doc.signature_map) {
            try {
                initialMap = typeof doc.signature_map === 'string' ? 
                    JSON.parse(doc.signature_map) : doc.signature_map;
            } catch (e) {
                console.warn('Invalid signature_map, using defaults', e);
            }
        }

        // Default positions if not specified - adjusted for better placement
        if (!initialMap) {
            initialMap = {
                x_pct: 0.65,  // Slightly adjusted for better centering
                y_pct: 0.25,  // Primary signature position (upper position)
                w_pct: 0.25,  // Slightly smaller width
                h_pct: 0.3,  // Slightly smaller height
                page: 1
            };
        }

        // Calculate vertical offset based on signature height plus some spacing
        // This ensures the second signature appears directly below the first with proper spacing
        const verticalOffset = initialMap.h_pct * 1.5; // 1.5x the height of the signature box

        // Create primary target (Accounting)
        const primaryTarget = this.createSignatureTarget(
            content,
            'accounting',
            initialMap,
            'rgba(59, 130, 246, 0.15)', // Blue
            '#3b82f6'
        );

        // Create secondary target (Issuer) - same X position, offset Y by calculated amount
        const issuerMap = {
            ...initialMap,
            y_pct: initialMap.y_pct + verticalOffset
        };

        const secondaryTarget = this.createSignatureTarget(
            content,
            'issuer',
            issuerMap,
            'rgba(16, 185, 129, 0.15)', // Green
            '#10b981'
        );

        // Store references
        this.linkedTargets = {
            primary: primaryTarget,
            secondary: secondaryTarget,
            verticalOffset: verticalOffset
        };

        // Link them together
        this.linkSafSignatureTargets(primaryTarget, secondaryTarget, content);

        // Create visual connection line
        this.createConnectionLine(primaryTarget, secondaryTarget, content);

        // Update signature maps
        this.updateLinkedSignatureMaps(content);

        // Show instruction toast
        this.showToast({
            type: 'info',
            title: 'SAF Signature',
            message: 'Drag the signature box to position your signature. Both copies will be signed simultaneously.'
        });

        // If signature already drawn, update both targets
        if (this.signatureImage) {
            this.updateLinkedSignatureOverlay();
        }
    }

    // Create a signature target element
    createSignatureTarget(content, role, map, bgColor, borderColor) {
        const box = document.createElement('div');
        box.className = `signature-target draggable saf-${role}`;
        box.dataset.role = role;
        box.dataset.saf = 'true';
        
        // Set position
        const rect = this.computeSignaturePixelRect(map);
        if (!rect) return null;
        
        box.style.position = 'absolute';
        box.style.left = rect.left + 'px';
        box.style.top = rect.top + 'px';
        // For SAF, use fixed size to match original signature-target
        if (box.classList.contains('saf-accounting') || box.classList.contains('saf-issuer')) {
            box.style.width = '120px';
            box.style.height = '60px';
        } else {
            box.style.width = rect.width + 'px';
            box.style.height = rect.height + 'px';
        }
        box.style.zIndex = role === 'accounting' ? 25 : 24;
        box.style.backgroundColor = bgColor;
        box.style.border = `2px dashed ${borderColor}`;
        box.style.borderRadius = '4px';
        box.style.display = 'flex';
        box.style.alignItems = 'center';
        box.style.justifyContent = 'center';
        box.style.cursor = 'move';
        box.style.fontWeight = '600';
        box.style.fontSize = '12px';
        box.style.color = borderColor;
        box.style.overflow = 'hidden';
        box.textContent = 'Signature';
        
        // Add role badge
        const badge = document.createElement('div');
        badge.className = 'saf-role-badge';
        badge.textContent = role === 'accounting' ? '1' : '2';
        badge.style.position = 'absolute';
        badge.style.top = '-8px';
        badge.style.right = '-8px';
        badge.style.width = '16px';
        badge.style.height = '16px';
        badge.style.backgroundColor = borderColor;
        badge.style.color = 'white';
        badge.style.borderRadius = '50%';
        badge.style.display = 'flex';
        badge.style.alignItems = 'center';
        badge.style.justifyContent = 'center';
        badge.style.fontSize = '9px';
        badge.style.fontWeight = 'bold';
        badge.style.zIndex = '30';
        
        box.appendChild(badge);
        content.appendChild(box);
        
        // Only add resize handle for non-SAF targets
        if (!box.classList.contains('saf-accounting') && !box.classList.contains('saf-issuer')) {
            this.addResizeHandle(box, content, role);
        }
        
        return box;
    }

    // Add resize handle to target
    addResizeHandle(element, content, role) {
        const handle = document.createElement('div');
        handle.className = 'saf-resize-handle';
        handle.dataset.role = role;
        handle.style.position = 'absolute';
        handle.style.bottom = '0';
        handle.style.right = '0';
        handle.style.width = '12px';
        handle.style.height = '12px';
        handle.style.backgroundColor = role === 'accounting' ? '#3b82f6' : '#10b981';
        handle.style.borderRadius = '2px';
        handle.style.cursor = 'se-resize';
        handle.style.zIndex = '30';
        
        element.appendChild(handle);
        
        // Resize logic
        let isResizing = false;
        let startX, startY, startWidth, startHeight;
        
        handle.addEventListener('mousedown', (e) => {
            isResizing = true;
            startX = e.clientX;
            startY = e.clientY;
            startWidth = element.offsetWidth;
            startHeight = element.offsetHeight;
            e.preventDefault();
            e.stopPropagation();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            
            const newWidth = Math.max(80, startWidth + dx);
            const newHeight = Math.max(50, startHeight + dy);
            
            // Resize primary
            element.style.width = newWidth + 'px';
            element.style.height = newHeight + 'px';
            
            // Resize secondary if it exists
            if (this.linkedTargets && this.linkedTargets.secondary) {
                const otherRole = role === 'accounting' ? 'issuer' : 'accounting';
                const otherTarget = this.linkedTargets[otherRole === 'accounting' ? 'primary' : 'secondary'];
                if (otherTarget) {
                    otherTarget.style.width = newWidth + 'px';
                    otherTarget.style.height = newHeight + 'px';
                }
            }
        });
        
        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                this.updateLinkedSignatureMaps(content);
            }
        });
    }

    // Link the two SAF signature targets together
    linkSafSignatureTargets(primaryTarget, secondaryTarget, content) {
        let isDragging = false;
        let startX, startY, initialPrimaryX, initialPrimaryY;
        let initialSecondaryX, initialSecondaryY;
        
        // Make primary target draggable
        primaryTarget.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('saf-resize-handle')) return;
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            
            const primaryRect = primaryTarget.getBoundingClientRect();
            const secondaryRect = secondaryTarget.getBoundingClientRect();
            const contentRect = content.getBoundingClientRect();
            
            initialPrimaryX = primaryRect.left - contentRect.left;
            initialPrimaryY = primaryRect.top - contentRect.top;
            initialSecondaryX = secondaryRect.left - contentRect.left;
            initialSecondaryY = secondaryRect.top - contentRect.top;
            
            primaryTarget.style.cursor = 'grabbing';
            secondaryTarget.style.cursor = 'grabbing';
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            
            // Calculate new positions
            let newPrimaryX = initialPrimaryX + dx;
            let newPrimaryY = initialPrimaryY + dy;
            
            let newSecondaryX = initialSecondaryX + dx;
            let newSecondaryY = initialSecondaryY + dy;
            
            // Constrain to canvas
            const canvas = this.canvas;
            if (!canvas) return;
            
            const canvasRect = canvas.getBoundingClientRect();
            const contentRect = content.getBoundingClientRect();
            
            const maxX = canvasRect.width - primaryTarget.offsetWidth;
            const maxYPrimary = canvasRect.height - primaryTarget.offsetHeight;
            const maxYSecondary = canvasRect.height - secondaryTarget.offsetHeight;
            
            // Apply constraints
            newPrimaryX = Math.max(0, Math.min(newPrimaryX, maxX));
            newPrimaryY = Math.max(0, Math.min(newPrimaryY, maxYPrimary));
            
            newSecondaryX = Math.max(0, Math.min(newSecondaryX, maxX));
            newSecondaryY = Math.max(0, Math.min(newSecondaryY, maxYSecondary));
            
            // Move both targets
            primaryTarget.style.left = newPrimaryX + 'px';
            primaryTarget.style.top = newPrimaryY + 'px';
            
            secondaryTarget.style.left = newSecondaryX + 'px';
            secondaryTarget.style.top = newSecondaryY + 'px';
            
            // Update connection line
            this.updateConnectionLine(primaryTarget, secondaryTarget, content);
        });
        
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                primaryTarget.style.cursor = 'move';
                secondaryTarget.style.cursor = 'move';
                this.updateLinkedSignatureMaps(content);
            }
        });
        
        // Also make secondary target draggable (moves both)
        secondaryTarget.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('saf-resize-handle')) return;
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            
            const primaryRect = primaryTarget.getBoundingClientRect();
            const secondaryRect = secondaryTarget.getBoundingClientRect();
            const contentRect = content.getBoundingClientRect();
            
            initialPrimaryX = primaryRect.left - contentRect.left;
            initialPrimaryY = primaryRect.top - contentRect.top;
            initialSecondaryX = secondaryRect.left - contentRect.left;
            initialSecondaryY = secondaryRect.top - contentRect.top;
            
            primaryTarget.style.cursor = 'grabbing';
            secondaryTarget.style.cursor = 'grabbing';
            e.preventDefault();
        });
    }

    // Create visual connection line between targets
    createConnectionLine(primaryTarget, secondaryTarget, content) {
        const line = document.createElement('div');
        line.className = 'linked-connection';
        line.style.position = 'absolute';
        line.style.zIndex = '20';
        content.appendChild(line);
        
        this.updateConnectionLine(primaryTarget, secondaryTarget, content);
    }

    // Update connection line position
    updateConnectionLine(primaryTarget, secondaryTarget, content) {
        const line = content.querySelector('.linked-connection');
        if (!line) return;
        
        const primaryRect = primaryTarget.getBoundingClientRect();
        const secondaryRect = secondaryTarget.getBoundingClientRect();
        const contentRect = content.getBoundingClientRect();
        
        // Calculate midpoint positions
        const primaryCenterX = primaryRect.left - contentRect.left + (primaryRect.width / 2);
        const primaryCenterY = primaryRect.top - contentRect.top + (primaryRect.height / 2);
        
        const secondaryCenterX = secondaryRect.left - contentRect.left + (secondaryRect.width / 2);
        const secondaryCenterY = secondaryRect.top - contentRect.top + (secondaryRect.height / 2);
        
        // Calculate line properties
        const length = Math.sqrt(
            Math.pow(secondaryCenterX - primaryCenterX, 2) + 
            Math.pow(secondaryCenterY - primaryCenterY, 2)
        );
        
        const angle = Math.atan2(
            secondaryCenterY - primaryCenterY,
            secondaryCenterX - primaryCenterX
        ) * 180 / Math.PI;
        
        // Position the line
        line.style.left = primaryCenterX + 'px';
        line.style.top = primaryCenterY + 'px';
        line.style.width = length + 'px';
        line.style.height = '2px';
        line.style.transformOrigin = '0 0';
        line.style.transform = `rotate(${angle}deg)`;
        line.style.background = 'linear-gradient(to bottom, #3b82f6, #10b981)';
        line.style.opacity = '0.6';
    }

    // Update linked signature maps for both positions - FIXED: Better coordinate calculation
    updateLinkedSignatureMaps(content) {
        if (!this.linkedTargets || !this.canvas) return;
        
        const canvas = this.canvas;
        const width = canvas.width;
        const height = canvas.height;
        const primary = this.linkedTargets.primary;
        const secondary = this.linkedTargets.secondary;
        
        if (!primary || !secondary) return;
        
        // Get the actual dimensions of the signature boxes
        const primaryWidth = primary.offsetWidth;
        const primaryHeight = primary.offsetHeight;
        const secondaryWidth = secondary.offsetWidth;
        const secondaryHeight = secondary.offsetHeight;
        
        // Get positions relative to the canvas
        const primaryLeft = primary.offsetLeft;
        const primaryTop = primary.offsetTop;
        const secondaryLeft = secondary.offsetLeft;
        const secondaryTop = secondary.offsetTop;
        
        // Calculate percentages - ensure values are between 0 and 1
        const x_pct = Math.max(0, Math.min(1, primaryLeft / width));
        const y_pct = Math.max(0, Math.min(1, primaryTop / height));
        const x_pct2 = Math.max(0, Math.min(1, secondaryLeft / width));
        const y_pct2 = Math.max(0, Math.min(1, secondaryTop / height));
        
        const w_pct = Math.max(0.05, Math.min(0.5, primaryWidth / width));
        const h_pct = Math.max(0.02, Math.min(0.2, primaryHeight / height));
        
        this.linkedSignatureMaps = {
            accounting: {
                x_pct,
                y_pct,
                w_pct,
                h_pct,
                page: this.currentPage
            },
            issuer: {
                x_pct: x_pct2,
                y_pct: y_pct2,
                w_pct,
                h_pct,
                page: this.currentPage
            }
        };
        
        this.currentSignatureMap = this.linkedSignatureMaps.accounting;
        console.log('Linked SAF signature maps updated:', this.linkedSignatureMaps);
    }

    // When signature is drawn, show it in BOTH SAF targets
    updateSignatureOverlayImage() {
        const content = document.getElementById('pdfContent');
        if (!content || !this.signatureImage) return;
        
        // For SAF documents with linked targets
        if (this.isSafDualSigning && this.linkedTargets) {
            this.updateLinkedSignatureOverlay();
            return;
        }
        
        // Regular single signature target (existing code)
        const box = content.querySelector('.signature-target:not(.saf-accounting):not(.saf-issuer)');
        if (!box) return;
        
        box.textContent = '';
        const img = new Image();
        img.src = this.signatureImage;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '100%';
        img.style.objectFit = 'contain';
        box.appendChild(img);
    }

    // Update both linked SAF targets with signature
    updateLinkedSignatureOverlay() {
        if (!this.signatureImage || !this.linkedTargets) return;
        
        // Update primary target
        this.linkedTargets.primary.innerHTML = '';
        const img1 = new Image();
        img1.src = this.signatureImage;
        img1.style.maxWidth = '100%';
        img1.style.maxHeight = '100%';
        img1.style.objectFit = 'contain';
        this.linkedTargets.primary.appendChild(img1);
        
        // Update secondary target
        this.linkedTargets.secondary.innerHTML = '';
        const img2 = new Image();
        img2.src = this.signatureImage;
        img2.style.maxWidth = '100%';
        img2.style.maxHeight = '100%';
        img2.style.objectFit = 'contain';
        this.linkedTargets.secondary.appendChild(img2);
        
        // Update UI status
        this.updateSafDualSignatureUI();
    }

    // Update UI for SAF dual signature
    updateSafDualSignatureUI() {
        const statusContainer = document.getElementById('signatureStatusContainer');
        if (statusContainer) {
            statusContainer.innerHTML = `
                <div class="saf-dual-signature-status">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                        <span class="fw-semibold">SAF Signature Ready</span>
                    </div>
                    <div class="saf-signature-positions">
                        <span class="badge bg-primary">
                            <i class="bi bi-pencil-square me-1"></i>Signature Positioned
                        </span>
                    </div>
                    <small class="text-muted d-block mt-1">
                        Your signature is ready to be applied
                    </small>
                </div>
            `;
        }
    }

    // Helper method for regular single signature target
    createSingleSignatureTarget(content, doc) {
        let map = doc.signature_map;
        try {
            map = (typeof map === 'string') ? JSON.parse(map) : map;
        } catch (e) {
            console.warn('Invalid doc.signature_map JSON', e);
            map = null;
        }

        if (!map) return;

        let box = content.querySelector('.signature-target:not(.saf-accounting):not(.saf-issuer)');
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

        box.style.left = rect.left + 'px';
        box.style.top = rect.top + 'px';
        box.style.width = rect.width + 'px';
        box.style.height = rect.height + 'px';
        box.textContent = map.label || 'Sign here';

        this.updateSignatureMap(box, content);

        if (this.signatureImage) this.updateSignatureOverlayImage();
    }

    // Check if current user is assigned to any pending step
    isUserAssignedToStep(user, step) {
        if (!user || !step) return false;

        const userId = String(user.id ?? '');
        const userRole = String(user.role ?? '').toLowerCase();

        if (!userId) return false;

        if (step.assignee_id !== undefined && step.assignee_id !== null) {
            const stepAssigneeId = String(step.assignee_id);
            const stepAssigneeType = String(step.assignee_type ?? '').toLowerCase();
            if (stepAssigneeId === userId) {
                if (!stepAssigneeType) return true;
                if (stepAssigneeType === userRole) return true;
            }
        }

        if (step.assigned_to !== undefined && step.assigned_to !== null && String(step.assigned_to) === userId) {
            return true;
        }

        if (userRole === 'employee' && step.assigned_to_employee_id !== undefined && step.assigned_to_employee_id !== null) {
            return String(step.assigned_to_employee_id) === userId;
        }

        if (userRole === 'student' && step.assigned_to_student_id !== undefined && step.assigned_to_student_id !== null) {
            return String(step.assigned_to_student_id) === userId;
        }

        return false;
    }

    isUserAssignedToPendingStep(doc) {
        if (!doc.workflow || !this.currentUser) return false;

        // Allow student creator to sign their own document
        if (this.currentUser.role === 'student' && doc.student && doc.student.id === this.currentUser.id) {
            return true;
        }

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

    }

// Add this new helper method for computing pixel rects in any container
computeSignaturePixelRectForContainer(map, canvas, container) {
    if (!canvas) {
        console.log('DEBUG: No canvas provided');
        return null;
    }
    
    console.log('DEBUG ===== computeSignaturePixelRectForContainer START =====');
    console.log('DEBUG map:', JSON.stringify(map, null, 2));
    console.log('DEBUG canvas element:', canvas);
    console.log('DEBUG container element:', container);
    
    const canvasRect = canvas.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();
    
    console.log('DEBUG canvasRect:', {
        left: canvasRect.left,
        top: canvasRect.top,
        right: canvasRect.right,
        bottom: canvasRect.bottom,
        width: canvasRect.width,
        height: canvasRect.height
    });
    
    console.log('DEBUG containerRect:', {
        left: containerRect.left,
        top: containerRect.top,
        right: containerRect.right,
        bottom: containerRect.bottom,
        width: containerRect.width,
        height: containerRect.height
    });

    // Helper to normalize a value that might be percent (0..1) or absolute px (>1)
    const norm = (v, axisSize, canvasAttrSize, axisName) => {
        if (v == null) {
            console.log(`DEBUG norm: ${axisName} value is null, returning 0`);
            return 0;
        }
        if (v <= 1) {
            const result = v * axisSize;
            console.log(`DEBUG norm: ${axisName} is percent ${v} -> ${result}px (axisSize: ${axisSize})`);
            return result;
        }
        const ratio = canvasAttrSize && axisSize ? (axisSize / canvasAttrSize) : 1;
        const result = v * ratio;
        console.log(`DEBUG norm: ${axisName} is absolute ${v}px with ratio ${ratio} -> ${result}px (canvasAttrSize: ${canvasAttrSize}, axisSize: ${axisSize})`);
        return result;
    };

    const canvasAttrWidth = canvas.width || canvasRect.width;
    const canvasAttrHeight = canvas.height || canvasRect.height;
    
    console.log('DEBUG canvas attributes:', {
        width: canvasAttrWidth,
        height: canvasAttrHeight
    });

    const xRaw = map.x_pct ?? map.x_px ?? 0;
    const yRaw = map.y_pct ?? map.y_px ?? 0;
    const wRaw = map.w_pct ?? map.w_px ?? 0;
    const hRaw = map.h_pct ?? map.h_px ?? 0;
    
    console.log('DEBUG raw values:', {
        xRaw,
        yRaw,
        wRaw,
        hRaw
    });

    // Calculate position relative to canvas
    const canvasLeftOffset = canvasRect.left - containerRect.left;
    const canvasTopOffset = canvasRect.top - containerRect.top;
    
    console.log('DEBUG offsets:', {
        canvasLeftOffset,
        canvasTopOffset
    });
    
    const normX = norm(xRaw, canvasRect.width, canvasAttrWidth, 'x');
    const normY = norm(yRaw, canvasRect.height, canvasAttrHeight, 'y');
    const normW = norm(wRaw, canvasRect.width, canvasAttrWidth, 'width');
    const normH = norm(hRaw, canvasRect.height, canvasAttrHeight, 'height');
    
    const left = canvasLeftOffset + normX;
    const top = canvasTopOffset + normY;
    const width = Math.max(1, normW);
    const height = Math.max(1, normH);
    
    console.log('DEBUG final calculated values:', {
        left,
        top,
        width,
        height
    });
    
    // For modal, account for any transform/offset
    const isModal = container.id === 'fullPdfContainer';
    console.log('DEBUG isModal:', isModal);
    
    if (isModal) {
        // Get the container's transform if any (for drag functionality)
        const transform = container.style.transform;
        console.log('DEBUG container transform:', transform);
        
        if (transform && transform.includes('translate')) {
            const matches = transform.match(/translate\(([^,]+)px,\s*([^)]+)px\)/);
            console.log('DEBUG transform matches:', matches);
            
            if (matches) {
                const offsetX = parseFloat(matches[1]);
                const offsetY = parseFloat(matches[2]);
                console.log('DEBUG transform offsets:', { offsetX, offsetY });
                
                // Adjust for the drag offset
                const adjustedLeft = left - offsetX;
                const adjustedTop = top - offsetY;
                
                console.log('DEBUG adjusted values:', {
                    adjustedLeft,
                    adjustedTop
                });
                
                console.log('DEBUG ===== computeSignaturePixelRectForContainer END (with transform) =====');
                
                return {
                    left: adjustedLeft,
                    top: adjustedTop,
                    width,
                    height,
                    canvasRect,
                    containerRect
                };
            }
        }
    }
    
    console.log('DEBUG ===== computeSignaturePixelRectForContainer END (no transform) =====');
    
    return { left, top, width, height, canvasRect, containerRect };
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

    // Helper function to check if canvas has any drawn content
    hasCanvasContent(canvas) {
        const ctx = canvas.getContext('2d');
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const pixelData = imageData.data;
        
        // Check if any pixel has non-zero alpha (drawn content)
        for (let i = 3; i < pixelData.length; i += 4) {
            if (pixelData[i] > 0) {
                return true;
            }
        }
        return false;
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
                            // Set signature image for upload
                            this.signatureImage = canvas.toDataURL('image/png');
                            this.updateSignatureOverlayImage();
                            // Update modern signature status
                            const placeholder = document.getElementById('signaturePlaceholder');
                            const signedStatus = document.getElementById('signedStatus');
                            if (placeholder) placeholder.classList.add('d-none');
                            if (signedStatus) signedStatus.classList.remove('d-none');
                            this.updateApprovalButtonsVisibility(this.currentDocument);
                            this.showToast({ type: 'success', title: 'Signature Uploaded', message: 'Signature ready to apply to document.' });
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
            if (!this.hasCanvasContent(canvas)) {
                this.showToast({ type: 'error', title: 'No Signature', message: 'Please draw or upload a signature first.' });
                return;
            }
            
            this.signatureImage = canvas.toDataURL('image/png');
            this.updateSignatureOverlayImage();
            // Update modern signature status
            const placeholder = document.getElementById('signaturePlaceholder');
            const signedStatus = document.getElementById('signedStatus');
            if (placeholder) placeholder.classList.add('d-none');
            if (signedStatus) signedStatus.classList.remove('d-none');
            // Hide signature pad after saving
            toggleSignaturePad();
            this.updateApprovalButtonsVisibility(this.currentDocument);
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
            console.log('Use Signature clicked, setting signatureImage');
            this.signatureImage = canvas.toDataURL('image/png');
            console.log('signatureImage set:', !!this.signatureImage);
            this.updateSignatureOverlayImage();
            this.showToast({ type: 'success', title: 'Signature Saved', message: 'Signature ready to apply.' });
            // Update button state after signature is set
            this.updateApprovalButtonsVisibility(this.currentDocument);
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
    // FIXED: Proper coordinate calculation for SAF dual signing
    async applySignatureToPdf() {
        if (!this.signatureImage) {
            console.error('No signature image available');
            return null;
        }

        try {
            // Get the original PDF URL
            const doc = this.currentDocument;
            let pdfUrl = doc.file_path;
            if (pdfUrl.startsWith('http')) {
                // Full URL, use as is
            } else if (pdfUrl.startsWith('/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(1);
            } else if (pdfUrl.startsWith('../')) {
                pdfUrl = BASE_URL + pdfUrl.substring(3);
            } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(12);
            } else {
                pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
            }

            // Fetch the original PDF
            const response = await fetch(pdfUrl);
            if (!response.ok) {
                throw new Error(`Failed to fetch PDF: ${response.status}`);
            }

            const arrayBuffer = await response.arrayBuffer();

            // Validate PDF header
            const header = new Uint8Array(arrayBuffer.slice(0, 5));
            const headerStr = String.fromCharCode(...header);
            if (headerStr !== '%PDF-') {
                throw new Error('Invalid PDF header: ' + headerStr);
            }

            // Load with PDF-LIB
            const pdfDoc = await PDFLib.PDFDocument.load(arrayBuffer);
            
            // Embed signature image
            const signatureImage = await pdfDoc.embedPng(this.signatureImage);

            // For SAF documents with dual signing
            if (this.isSafDualSigning && this.linkedSignatureMaps) {
                // Apply to Accounting position (first signature)
                const acct = this.linkedSignatureMaps.accounting;
                // Get the page (usually page 1 for SAF)
                const acctPage = pdfDoc.getPage(acct.page - 1 || 0);
                
                // Calculate coordinates - FIXED: Y coordinate calculation
                // PDF-LIB uses bottom-left origin, so we need to subtract from page height
                const acctX = acct.x_pct * acctPage.getWidth();
                const acctY = acctPage.getHeight() - (acct.y_pct * acctPage.getHeight()) - (acct.h_pct * acctPage.getHeight());
                
                acctPage.drawImage(signatureImage, {
                    x: acctX,
                    y: acctY,
                    width: acct.w_pct * acctPage.getWidth(),
                    height: acct.h_pct * acctPage.getHeight()
                });

                // Apply to Issuer position (second signature)
                const issuer = this.linkedSignatureMaps.issuer;
                const issuerPage = pdfDoc.getPage(issuer.page - 1 || 0);
                
                const issuerX = issuer.x_pct * issuerPage.getWidth();
                const issuerY = issuerPage.getHeight() - (issuer.y_pct * issuerPage.getHeight()) - (issuer.h_pct * issuerPage.getHeight());
                
                issuerPage.drawImage(signatureImage, {
                    x: issuerX,
                    y: issuerY,
                    width: issuer.w_pct * issuerPage.getWidth(),
                    height: issuer.h_pct * issuerPage.getHeight()
                });

            } else {
                // Regular single signature
                const pageIndex = (this.currentSignatureMap.page || this.currentPage) - 1;
                const page = pdfDoc.getPage(pageIndex);
                const { x_pct, y_pct, w_pct, h_pct } = this.currentSignatureMap;
                
                const pageWidth = page.getWidth();
                const pageHeight = page.getHeight();
                
                // FIXED: Y coordinate calculation - subtract from page height
                const x = x_pct * pageWidth;
                const y = pageHeight - (y_pct * pageHeight) - (h_pct * pageHeight);
                const width = w_pct * pageWidth;
                const height = h_pct * pageHeight;

                page.drawImage(signatureImage, { 
                    x, 
                    y, 
                    width, 
                    height 
                });
            }

            const modifiedPdfBytes = await pdfDoc.save();
            return new Blob([modifiedPdfBytes], { type: 'application/pdf' });

        } catch (error) {
            console.error('Error in applySignatureToPdf:', error);
            this.showToast({
                type: 'error',
                title: 'Signature Error',
                message: 'Failed to apply signature to PDF. Please try again.'
            });
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
        const isStudent = this.currentUser?.role === 'student';

        if (!isAssigned) {
            return '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
        }

        if (isStudent) {
            // Students get Submit Document and Delete Document buttons
            return `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-danger" onclick="documentSystem.deleteDocument(${doc.id})">
                    <i class="bi bi-trash me-1"></i>Delete Document
                </button>
                <button type="button" class="btn btn-success" onclick="documentSystem.signDocument(${doc.id})">
                    <i class="bi bi-check-circle me-1"></i>Submit Document
                </button>
            `;
        } else {
            // Employees get full buttons
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
    // MODIFIED: Sign document with SAF dual signature support
    async signDocument(docId) {
        const doc = this.currentDocument && this.currentDocument.id === docId
            ? this.currentDocument
            : this.documents.find(d => d.id === docId);

        // Check if signature has content
        const signatureCanvas = document.getElementById('signatureCanvas');
        if (signatureCanvas && !this.hasCanvasContent(signatureCanvas)) {
            this.showToast({ type: 'error', title: 'No Signature', message: 'Please draw or upload a signature first.' });
            return;
        }

        // Custom message for SAF dual signing
        const isSaf = doc?.doc_type === 'saf';
        const message = isSaf 
            ? 'Your signature will be placed on both Accounting and Issuer copies. Proceed?'
            : 'Are you sure you want to sign and approve this document?';

        showConfirmModal(
            'Sign Document',
            message,
            async () => {
                try {
                    // Apply signature to PDF
                    const signedPdfBlob = await this.applySignatureToPdf();

                    // Find step id
                    const pendingStep = doc?.workflow?.find(s => s.status === 'pending');
                    const stepId = pendingStep?.id || undefined;

                    // Prepare form data
                    const formData = new FormData();
                    formData.append('action', 'sign');
                    formData.append('document_id', docId);
                    formData.append('step_id', stepId);
                    
                    // For SAF, send both signature positions
                    if (isSaf && this.linkedSignatureMaps) {
                        formData.append('signature_map', JSON.stringify(this.linkedSignatureMaps));
                        formData.append('dual_signature', 'true');
                    } else if (this.currentSignatureMap) {
                        formData.append('signature_map', JSON.stringify(this.currentSignatureMap));
                    }
                    
                    if (signedPdfBlob) {
                        formData.append('signed_pdf', signedPdfBlob, 'signed_document.pdf');
                    }

                    const response = await fetch(BASE_URL + 'api/documents.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();

                    if (result.success) {
                        const successMessage = isSaf
                            ? 'Signature applied successfully to the document.'
                            : 'Document has been successfully signed and approved.';
                        
                        this.showToast({
                            type: 'success',
                            title: isSaf ? 'SAF Dual Signed' : 'Document Signed',
                            message: successMessage
                        });

                        // Clear signature data
                        this.signatureImage = null;
                        this.currentSignatureMap = null;
                        this.linkedSignatureMaps = null;
                        this.linkedTargets = null;
                        this.isSafDualSigning = false;

                        // Return to dashboard
                        setTimeout(() => {
                            this.goBack();
                            this.loadDocuments();
                            if (window.refreshNotifications) {
                                window.refreshNotifications(true);
                            }
                        }, 1200);
                    } else {
                        throw new Error(result.message || 'Failed to sign document');
                    }
                } catch (error) {
                    console.error('Error signing document:', error);
                    this.showToast({
                        type: 'error',
                        title: 'Error',
                        message: 'Failed to sign document. Please try again.'
                    });
                }
            },
            isSaf ? 'Sign Both Copies' : 'Sign & Approve',
            'btn-success'
        );
    }

    async rejectDocument(docId, reason) {
        if (!reason || reason.trim() === '') return;

        try {
            const doc = this.currentDocument && this.currentDocument.id === docId
                ? this.currentDocument
                : this.documents.find(d => d.id === docId);
            const pendingStep = doc?.workflow?.find(s => s.status === 'pending');
            const stepId = pendingStep?.id || undefined;

            const response = await fetch(BASE_URL + 'api/documents.php', {
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
                if (window.refreshNotifications) {
                    window.refreshNotifications(true);
                }
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

    // Delete document (for creators only)
    async deleteDocument(docId) {
        const doc = this.currentDocument && this.currentDocument.id === docId
            ? this.currentDocument
            : this.documents.find(d => d.id === docId);

        if (!doc) {
            this.showToast({
                type: 'error',
                title: 'Error',
                message: 'Document not found.'
            });
            return;
        }

        // Confirm deletion
        const confirmed = confirm('Are you sure you want to delete this document? This action cannot be undone.');
        if (!confirmed) return;

        try {
            const response = await fetch(`${this.apiBase}?id=${docId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const result = await response.json();

            if (result.success) {
                this.showToast({
                    type: 'success',
                    title: 'Document Deleted',
                    message: 'The document has been deleted successfully.'
                });

                // Refresh documents
                await this.refreshDocuments();

                // Go back to dashboard
                this.goBack();

                if (window.addAuditLog) {
                    window.addAuditLog('DOCUMENT_DELETED', 'Document Management', `Deleted document ${docId}`, docId, 'Document', 'WARNING');
                }
            } else {
                this.showToast({
                    type: 'error',
                    title: 'Delete Failed',
                    message: result.message || 'Failed to delete document.'
                });
            }
        } catch (error) {
            this.showToast({
                type: 'error',
                title: 'Error',
                message: 'An error occurred while deleting the document.'
            });
        }
    }

    // Update statistics display
    updateStatsDisplay() {
        const totalCount = this.documents.length;
        const submittedCount = this.documents.filter(doc => doc.status === 'submitted').length;
        const inReviewCount = this.documents.filter(doc => doc.status === 'in_review').length;
        const approvedCount = this.documents.filter(doc => doc.status === 'approved').length;

        const elements = {
            totalCount: document.getElementById('totalCount'),
            submittedCount: document.getElementById('submittedCount'),
            inReviewCount: document.getElementById('inReviewCount'),
            approvedCount: document.getElementById('approvedCount'),
            notificationCount: document.getElementById('notificationCount')
        };

        if (elements.totalCount) elements.totalCount.textContent = totalCount;
        if (elements.submittedCount) elements.submittedCount.textContent = submittedCount;
        if (elements.inReviewCount) elements.inReviewCount.textContent = inReviewCount;
        if (elements.approvedCount) elements.approvedCount.textContent = approvedCount;
        if (elements.notificationCount) {
            const pendingActions = submittedCount + inReviewCount;
            elements.notificationCount.textContent = pendingActions;
            elements.notificationCount.style.display = pendingActions > 0 ? 'flex' : 'none';
        }

        // Make stats cards clickable for filtering
        this.setupStatsCardClickHandlers();
    }

    // Setup click handlers for stats cards
    setupStatsCardClickHandlers() {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            // Remove existing listeners to avoid duplicates
            card.removeEventListener('click', this.handleStatCardClick);
            // Add new listener
            card.addEventListener('click', this.handleStatCardClick.bind(this));
            // Add cursor pointer style
            card.style.cursor = 'pointer';
        });
    }

    // Handle stat card click for filtering
    handleStatCardClick(event) {
        const card = event.currentTarget;
        const filterType = card.classList.contains('all') ? 'all' :
                          card.classList.contains('pending') ? 'submitted' :
                          card.classList.contains('in-review') ? 'in_review' :
                          card.classList.contains('approved') ? 'approved' : 'all';

        this.filterDocuments(filterType);

        // Update visual active state for stat cards
        document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
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

    // Full Document Viewer Methods
    openFullViewer() {
        // Reset drag state
        this.isDragMode = false;
        this.isDragging = false;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        
        const modal = new bootstrap.Modal(document.getElementById('fullDocumentModal'));
        const pdfContent = document.getElementById('fullPdfContent');
        if (pdfContent && this.currentDocument) {
            pdfContent.innerHTML = '<div id="fullPdfContainer" style="position: relative; min-width: 100%; min-height: 100%; display: flex; align-items: flex-start; justify-content: center;"><canvas id="fullPdfCanvas" style="max-width: none; image-rendering: -webkit-optimize-contrast;"></canvas></div>';
            this.fullCanvas = document.getElementById('fullPdfCanvas');
            this.fullCtx = this.fullCanvas.getContext('2d');
            this.loadFullPdf(this.currentDocument.file_path);
        }
        
        // Clean up drag listeners when modal is closed
        const modalElement = document.getElementById('fullDocumentModal');
        modalElement.addEventListener('hidden.bs.modal', () => {
            this.removeDragListeners();
            this.isDragMode = false;
            this.isDragging = false;
        });
        
        modal.show();
    }

    async loadFullPdf(url) {
        try {
            // Handle URL as in loadPdf
            let pdfUrl = url;
            if (pdfUrl.startsWith('/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(1);
            } else if (pdfUrl.startsWith('../')) {
                pdfUrl = BASE_URL + pdfUrl.substring(3);
            } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(12);
            } else if (pdfUrl.startsWith('http')) {
                // Full URL
            } else {
                pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
            }

            this.fullPdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
            this.fullTotalPages = this.fullPdfDoc.numPages;
            this.fullCurrentPage = 1;
            this.fullScale = 1.0;
            this.canvasOffsetX = 0;
            this.canvasOffsetY = 0;
            await this.renderFullPage();
            this.updateFullControls();
        } catch (error) {
            console.error('Error loading full PDF:', error);
            this.showToast({ type: 'error', title: 'Error', message: 'Failed to load document for full view.' });
        }
    }

    // Update the renderFullPage method to ensure redaction overlays are rendered
    async renderFullPage() {
        if (!this.fullPdfDoc || !this.fullCanvas) return;

        try {
            const page = await this.fullPdfDoc.getPage(this.fullCurrentPage);
            const viewport = page.getViewport({ scale: this.fullScale });

            this.fullCanvas.height = viewport.height;
            this.fullCanvas.width = viewport.width;

            const renderContext = {
                canvasContext: this.fullCtx,
                viewport: viewport
            };

            await page.render(renderContext).promise;
            
            // Render redaction overlays for completed signatures
            const container = document.getElementById('fullPdfContainer');
            if (container && this.currentDocument) {
                // Clear any existing overlays
                container.querySelectorAll('.completed-signature-container').forEach(el => el.remove());
                
                // Render new overlays
                this.renderCompletedSignatures(this.currentDocument, container, this.fullCanvas);
            }
            
            // Update canvas position after rendering
            this.updateCanvasPosition();
        } catch (error) {
            console.error('Error rendering full page:', error);
        }
    }

    updateFullControls() {
        const pageInput = document.getElementById('fullPageInput');
        const pageTotal = document.getElementById('fullPageTotal');
        const zoomIndicator = document.getElementById('fullZoomIndicator');
        const prevBtn = document.getElementById('fullPrevPageBtn');
        const nextBtn = document.getElementById('fullNextPageBtn');

        if (pageInput) pageInput.value = this.fullCurrentPage;
        if (pageTotal) pageTotal.textContent = this.fullTotalPages;
        if (zoomIndicator) zoomIndicator.textContent = Math.round(this.fullScale * 100) + '%';
        if (prevBtn) prevBtn.disabled = this.fullCurrentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.fullCurrentPage >= this.fullTotalPages;
    }

    async fullPrevPage() {
        if (this.fullCurrentPage > 1) {
            this.fullCurrentPage--;
            await this.renderFullPage();
            this.updateFullControls();
        }
    }

    async fullNextPage() {
        if (this.fullCurrentPage < this.fullTotalPages) {
            this.fullCurrentPage++;
            await this.renderFullPage();
            this.updateFullControls();
        }
    }

    async fullGoToPage(pageNum) {
        const page = parseInt(pageNum);
        if (page >= 1 && page <= this.fullTotalPages) {
            this.fullCurrentPage = page;
            await this.renderFullPage();
            this.updateFullControls();
        }
    }

    async fullZoomIn() {
        this.fullScale = Math.min(this.fullScale + 0.25, 3.0);
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage();
        this.updateFullControls();
    }

    async fullZoomOut() {
        this.fullScale = Math.max(this.fullScale - 0.25, 0.5);
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage();
        this.updateFullControls();
    }

    async fullFitToWidth() {
        if (!this.fullPdfDoc) return;
        // Simple fit to width - can be enhanced
        this.fullScale = 1.0;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage();
        this.updateFullControls();
    }

    async fullResetZoom() {
        this.fullScale = 1.0;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage();
        this.updateFullControls();
    }

    toggleDragMode() {
        this.isDragMode = !this.isDragMode;
        const content = document.getElementById('fullPdfContent');
        const btn = document.getElementById('dragToggleBtn');
        
        if (this.isDragMode) {
            content.style.cursor = 'grab';
            content.classList.add('dragging');
            btn.innerHTML = '<i class="bi bi-hand-index-fill"></i>';
            btn.title = 'Exit Drag Mode';
            this.setupDragListeners();
        } else {
            content.style.cursor = 'grab';
            content.classList.remove('dragging');
            btn.innerHTML = '<i class="bi bi-hand-index"></i>';
            btn.title = 'Toggle Drag Mode';
            this.removeDragListeners();
        }
    }

    setupDragListeners() {
        const content = document.getElementById('fullPdfContent');
        const container = document.getElementById('fullPdfContainer');
        
        content.addEventListener('mousedown', this.handleMouseDown.bind(this));
        content.addEventListener('mousemove', this.handleMouseMove.bind(this));
        content.addEventListener('mouseup', this.handleMouseUp.bind(this));
        content.addEventListener('mouseleave', this.handleMouseUp.bind(this));
        
        // Prevent text selection during drag
        content.addEventListener('selectstart', (e) => {
            if (this.isDragMode) e.preventDefault();
        });
    }

    removeDragListeners() {
        const content = document.getElementById('fullPdfContent');
        
        content.removeEventListener('mousedown', this.handleMouseDown.bind(this));
        content.removeEventListener('mousemove', this.handleMouseMove.bind(this));
        content.removeEventListener('mouseup', this.handleMouseUp.bind(this));
        content.removeEventListener('mouseleave', this.handleMouseUp.bind(this));
        content.removeEventListener('selectstart', this.handleMouseDown.bind(this));
    }

    handleMouseDown(e) {
        if (!this.isDragMode || e.button !== 0) return;
        
        this.isDragging = true;
        this.dragStartX = e.clientX - this.canvasOffsetX;
        this.dragStartY = e.clientY - this.canvasOffsetY;
        
        const content = document.getElementById('fullPdfContent');
        content.style.cursor = 'grabbing';
        e.preventDefault();
    }

    handleMouseMove(e) {
        if (!this.isDragging || !this.isDragMode) return;
        
        this.canvasOffsetX = e.clientX - this.dragStartX;
        this.canvasOffsetY = e.clientY - this.dragStartY;
        
        this.updateCanvasPosition();
        e.preventDefault();
    }

    handleMouseUp(e) {
        if (!this.isDragMode) return;
        
        this.isDragging = false;
        const content = document.getElementById('fullPdfContent');
        content.style.cursor = this.isDragMode ? 'grab' : 'default';
    }

    // Update the updateCanvasPosition method to reposition overlays when dragging
    updateCanvasPosition() {
        if (!this.fullCanvas) return;
        
        const container = document.getElementById('fullPdfContainer');
        container.style.transform = `translate(${this.canvasOffsetX}px, ${this.canvasOffsetY}px)`;
        
        // Re-render overlays to adjust for new position
        if (this.currentDocument) {
            // Clear and re-render overlays with new position
            container.querySelectorAll('.completed-signature-container').forEach(el => el.remove());
            this.renderCompletedSignatures(this.currentDocument, container, this.fullCanvas);
        }
    }
};

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

if (window.NavbarSettings) {
    window.openProfileSettings = window.NavbarSettings.openProfileSettings;
    window.openChangePassword = window.NavbarSettings.openChangePassword;
    window.openPreferences = window.NavbarSettings.openPreferences;
    window.showHelp = window.NavbarSettings.showHelp;
    window.savePreferences = window.NavbarSettings.savePreferences;
    window.saveProfileSettings = window.NavbarSettings.saveProfileSettings;
}

// Handle profile settings form
document.addEventListener('DOMContentLoaded', function () {
    const profileForm = document.getElementById('profileSettingsForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async function (e) {
            if (window.NavbarSettings?.saveProfileSettings) {
                await window.NavbarSettings.saveProfileSettings(e);
            } else {
                e.preventDefault();
            }
        });
    }
});

// Initialize the document notification system
document.addEventListener('DOMContentLoaded', function () {
    window.documentSystem = new DocumentNotificationSystem();
    window.documentSystem.init();
});