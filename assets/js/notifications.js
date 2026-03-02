/**
 * Main Document Notification System Controller
 * ============================================
 */

var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

class DocumentNotificationSystem {
    constructor() {
        // Data State
        this.documents = [];
        this.pendingDocuments = [];
        this.completedDocuments = [];
        this.filteredDocuments = [];
        this.currentDocument = null;
        this.currentUser = window.currentUser || null;
        this.apiBase = BASE_URL + 'api/documents.php';
        
        // UI State
        this.searchTerm = '';
        this.currentFilter = 'all';
        this.sortOption = 'date_desc';
        this.currentGroup = 'none';
        this.isLoading = false;
        this.isApplyingFilters = false;

        // Initialize Sub-Managers
        this.commentsManager = new CommentsManager(this.apiBase);
        this.signatureManager = new SignatureManager();
        this.pdfViewer = new PdfViewer(this.signatureManager);
    }

    // ------------------------------------------------------------------
    // Initialization & Data Loading
    // ------------------------------------------------------------------

    async init() {
        // ALLOW ALL STUDENTS & EMPLOYEES TO LOAD THE PAGE
        // The applyAccessRules() function will act as the bouncer to hide/show specific docs
        const userHasAccess = this.currentUser && 
            (this.currentUser.role === 'employee' || this.currentUser.role === 'student');

        if (!userHasAccess) {
            window.location.href = BASE_URL + '?page=login&error=access_denied';
            return;
        }

        // Load persisted state
        this.currentPage = parseInt(localStorage.getItem('notifications_currentPage')) || 1;
        this.sortOption = localStorage.getItem('notifications_sortOption') || 'date_desc';
        this.currentGroup = localStorage.getItem('notifications_groupBy') || 'none';

        this.setupLoadingState();
        await this.loadDocuments();
        this.setupEventListeners();
        this.initGroupingControls();
        this.filterDocuments(this.currentFilter);
        
        if (this.signatureManager) {
            this.signatureManager.initSignaturePad();
        }
        
        // Resize listener for PDF viewer
        window.addEventListener('resize', this.debounce(() => {
            if (this.pdfViewer && this.pdfViewer.pdfDoc) this.pdfViewer.fitToWidth(this.currentDocument);
        }, 250));

        this.setupPeriodicRefresh();
    }

    setupPeriodicRefresh() {
        setInterval(() => {
            if (!document.hidden && !this.isLoading) {
                this.refreshDocuments();
            }
        }, 30000);
    }

    // --- STRICT NOTIFICATION ACCESS RULES ---
    applyAccessRules(docs) {
        // ONLY SSC Presidents and Employees get elevated view.
        // CSC Presidents and regular students are restricted.
        const isElevatedUser = this.currentUser.role === 'employee' || 
                               this.currentUser.position === 'Supreme Student Council President';

        return docs.filter(doc => {
            const docStatus = this.normalizeDocumentStatus(doc.status);
            const pendingStep = doc.workflow?.find(step => step.status === 'pending');
            
            // Check if the logged-in user is EXACTLY the person who needs to sign right now
            const isCurrentSignatory = pendingStep && (pendingStep.assignee_id == this.currentUser.id || pendingStep.assigned_to == this.currentUser.id);

            if (!isElevatedUser) {
                // RULE 1: Restricted Users (CSC Presidents & Regular Students)
                // They ONLY see documents in notifications if it is their exact turn to sign.
                // Once they sign it, it disappears from their feed immediately.
                return isCurrentSignatory;
            } else {
                // RULE 2: Elevated Users (Employees & SSC Presidents)
                // Hide "Newly Submitted" documents unless they are the active signatory
                if (docStatus === 'submitted' && !isCurrentSignatory) {
                    return false;
                }
                // Allow them to see their history of other documents (in progress, approved, etc.)
                return true;
            }
        });
    }

    async loadDocuments() {
        this.setLoading(true);
        try {
            const response = await fetch(this.apiBase);
            const data = await response.json();
            if (data.success) {
                // Apply the strict access rules before doing anything else
                this.documents = this.applyAccessRules(data.documents || []);
                
                this.pendingDocuments = this.documents.filter(doc => !doc.user_action_completed);
                this.completedDocuments = this.documents.filter(doc => doc.user_action_completed);
                this.applyFiltersAndSearch();
            }
        } catch (error) {
            console.error("Error loading documents", error);
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: 'Failed to load documents' });
        } finally {
            this.setLoading(false);
        }
    }

    async refreshDocuments() {
        try {
            const response = await fetch(this.apiBase + '?t=' + Date.now());
            const data = await response.json();
            if (data.success) {
                // Apply the strict access rules on background refresh
                this.documents = this.applyAccessRules(data.documents || []);
                
                this.pendingDocuments = this.documents.filter(doc => !doc.user_action_completed);
                this.completedDocuments = this.documents.filter(doc => doc.user_action_completed);
                this.applyFiltersAndSearch();
            }
        } catch (error) {}
    }

    // ------------------------------------------------------------------
    // Event Listeners & UI Helpers
    // ------------------------------------------------------------------

    setupEventListeners() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.getElementById('documentView')?.style.display === 'block') {
                this.goBack();
            }
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('documentSearch')?.focus();
            }
        });

        document.getElementById('sortSelect')?.addEventListener('change', (e) => {
            this.sortOption = e.target.value;
            this.applyFiltersAndSearch();
        });

        const searchInput = document.getElementById('documentSearch');
        if (searchInput) {
            searchInput.addEventListener('input', this.debounce((e) => {
                this.searchTerm = e.target.value.trim().toLowerCase();
                this.applyFiltersAndSearch();
            }, 300));
        }

        document.getElementById('clearSearch')?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            this.searchTerm = '';
            this.applyFiltersAndSearch();
        });
    }

    initGroupingControls() {
        const groupOptions = document.querySelectorAll('.group-option');
        groupOptions.forEach(opt => {
            opt.classList.toggle('active', opt.dataset.group === this.currentGroup);
            opt.addEventListener('click', (e) => {
                e.preventDefault();
                groupOptions.forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
                
                const triggerLabel = document.querySelector('#groupTrigger .group-label');
                if (triggerLabel) triggerLabel.textContent = opt.querySelector('span')?.textContent;
                
                if (this.currentGroup !== opt.dataset.group) {
                    this.currentGroup = opt.dataset.group;
                    this.applyFiltersAndSearch();
                }
            });
        });
    }

    filterDocuments(status) {
        this.currentFilter = status;
        document.querySelectorAll('.stat-card').forEach(card => {
            const cardFilter = card.classList.contains('all') ? 'all' :
                              card.classList.contains('pending') ? 'submitted' :
                              card.classList.contains('in-review') ? 'in_progress' :
                              card.classList.contains('approved') ? 'approved' : '';
            card.classList.toggle('active', cardFilter === status);
        });
        this.applyFiltersAndSearch();
    }

    applyFiltersAndSearch() {
        if (this.isApplyingFilters) return;
        this.isApplyingFilters = true;
        
        try {
            localStorage.setItem('notifications_sortOption', this.sortOption);
            localStorage.setItem('notifications_groupBy', this.currentGroup);
            
            let filtered = [];
            
            // Status Filter
            if (this.currentFilter === 'all') {
                filtered = [...this.documents];
            } else if (this.currentFilter === 'submitted') {
                filtered = this.documents.filter(doc => this.normalizeDocumentStatus(doc.status) === 'submitted');
            } else if (this.currentFilter === 'in_progress') {
                filtered = this.documents.filter(doc => this.normalizeDocumentStatus(doc.status) === 'in_progress');
            } else if (this.currentFilter === 'approved') {
                filtered = this.documents.filter(doc => this.normalizeDocumentStatus(doc.status) === 'approved');
            } else if (this.currentFilter === 'rejected') {
                filtered = this.documents.filter(doc => this.normalizeDocumentStatus(doc.status) === 'rejected');
            } else {
                filtered = this.documents.filter(doc => this.normalizeDocumentStatus(doc.status) === this.currentFilter);
            }

            // Search Filter
            if (this.searchTerm) {
                const term = this.searchTerm;
                filtered = filtered.filter(doc =>
                    (doc.title?.toLowerCase().includes(term)) ||
                    (doc.student?.department?.toLowerCase().includes(term)) ||
                    (doc.department?.toLowerCase().includes(term)) ||
                    (this.formatDocType(doc.doc_type || '').toLowerCase().includes(term)) ||
                    (this.getStatusInfo(doc.status).label.toLowerCase().includes(term))
                );
            }

            // Sort
            filtered = this.sortDocuments([...filtered], this.sortOption);

            this.filteredDocuments = filtered;
            this.renderFilteredDocuments();
        } finally {
            this.isApplyingFilters = false;
        }
    }

    // ------------------------------------------------------------------
    // Dashboard Rendering
    // ------------------------------------------------------------------

    renderFilteredDocuments() {
        const container = document.getElementById('documentsContainer');
        if (!container) return;
        container.innerHTML = '';

        if (this.filteredDocuments.length === 0) {
            container.innerHTML = `
                <div class="col-12 text-center py-5">
                    <div class="empty-state">
                        <i class="bi bi-search text-muted empty-state-icon" style="font-size: 2rem;"></i>
                        <h4 class="mt-3">No Documents Found</h4>
                        <p class="text-muted">No documents match your current filters.</p>
                    </div>
                </div>`;
            return;
        }

        if (this.currentGroup !== 'none') {
            this.renderGroupedDocuments(container);
        } else {
            const listWrapper = document.createElement('div');
            listWrapper.className = 'documents-list';
            this.filteredDocuments.forEach(doc => {
                listWrapper.appendChild(this.createDocumentCard(doc));
            });
            container.appendChild(listWrapper);
        }

        this.updateStatsDisplay();
    }

    renderGroupedDocuments(container) {
        const groups = this.groupDocuments(this.filteredDocuments, this.currentGroup);
        const wrapper = document.createElement('div');
        wrapper.className = 'groups-container';

        Object.keys(groups).sort().forEach(groupKey => {
            const groupDocs = groups[groupKey];
            if (groupDocs.length === 0) return;

            const section = document.createElement('div');
            section.className = 'document-group mb-4';
            
            section.innerHTML = `
                <div class="document-group-header d-flex justify-content-between align-items-center mb-2" style="cursor:pointer;" onclick="this.nextElementSibling.classList.toggle('d-none')">
                    <h5 class="m-0"><i class="bi bi-folder me-2"></i>${groupKey} <span class="badge bg-secondary ms-2">${groupDocs.length}</span></h5>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="document-group-container"></div>
            `;
            
            const groupContainer = section.querySelector('.document-group-container');
            groupDocs.forEach(doc => groupContainer.appendChild(this.createDocumentCard(doc)));
            wrapper.appendChild(section);
        });

        container.appendChild(wrapper);
    }

    createDocumentCard(doc) {
        const listItem = document.createElement('div');
        const userHasSigned = !!doc.workflow?.find(step => 
            step.status === 'completed' && step.assignee_id === this.currentUser?.id
        );
        
        listItem.className = `document-list-item ${userHasSigned ? 'read-only' : ''}`;
        listItem.onclick = () => this.openDocument(doc.id);
        
        const docType = this.formatDocType(doc.doc_type || doc.type || 'Document');
        const fromWho = doc.student?.department || doc.from || 'Unknown';
        const progressPct = this.computeProgress(doc);
        const dueDate = this.getDueDate(doc);
        const daysUntilDue = this.getDaysUntilDue(dueDate);
        
        const createdDate = doc.uploaded_at || doc.created_at;
        const dateString = createdDate ? this.formatDate(createdDate) : '—';

        const statusBadgeHTML = typeof getStatusBadge === 'function' 
            ? getStatusBadge(doc.status, 'document') 
            : `<span class="badge bg-secondary">${doc.status}</span>`;

        let priorityLabel = '';
        if (daysUntilDue !== null && daysUntilDue <= 1) {
            priorityLabel = '<span class="priority-badge priority-urgent">Urgent</span>';
        } else if (daysUntilDue !== null && daysUntilDue <= 3) {
            priorityLabel = '<span class="priority-badge priority-high">High</span>';
        }

        listItem.innerHTML = `
            <div class="list-item-left">
                <div class="document-icon ${doc.status}">
                    <i class="bi bi-file-earmark-text-fill"></i>
                </div>
                <div class="document-main-info">
                    <div class="document-title-row">
                        <h5 class="document-title">${this.escapeHtml(doc.title)}</h5>
                        ${priorityLabel}
                    </div>
                    <div class="document-meta-row text-muted small">
                        <span class="meta-item"><i class="bi bi-folder2 me-1"></i>${this.escapeHtml(docType)}</span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item"><i class="bi bi-person me-1"></i>${this.escapeHtml(fromWho)}</span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item"><i class="bi bi-calendar3 me-1"></i>${dateString}</span>
                        ${daysUntilDue !== null ? `
                            <span class="meta-separator">•</span>
                            <span class="meta-item time-remaining ${daysUntilDue <= 1 ? 'text-danger' : daysUntilDue <= 3 ? 'text-warning' : ''}">
                                <i class="bi bi-clock me-1"></i>${daysUntilDue === 0 ? 'Due Today' : daysUntilDue === 1 ? '1 day left' : `${daysUntilDue} days left`}
                            </span>
                        ` : ''}
                    </div>
                </div>
            </div>
            
            <div class="list-item-right text-end d-flex align-items-center gap-4">
                <div class="document-status mb-0">
                    ${statusBadgeHTML}
                </div>
                <div class="document-progress d-flex align-items-center gap-2" style="width: 140px;">
                    <div class="progress flex-grow-1" style="height: 6px; background-color: #e5e7eb; border-radius: 10px; overflow: hidden;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: ${progressPct}%"></div>
                    </div>
                    <span class="progress-text text-xs fw-bold" style="min-width: 35px; text-align: right;">${progressPct}%</span>
                </div>
                <button class="list-item-action btn btn-ghost btn-sm rounded-circle p-1" aria-label="View document" onclick="event.stopPropagation(); documentSystem.openDocument(${doc.id})">
                    <i class="bi bi-chevron-right fs-5 text-muted"></i>
                </button>
            </div>
        `;

        return listItem;
    }

    // ------------------------------------------------------------------
    // Document Detail View & Restrictions
    // ------------------------------------------------------------------

    async openDocument(docId) {
        try {
            const response = await fetch(`${this.apiBase}?id=${docId}`);
            if (!response.ok) throw new Error('Failed to load document details');
            
            const fullDoc = await response.json();
            this.currentDocument = fullDoc;
            
            // Toggle Views
            document.getElementById('dashboardView').style.display = 'none';
            document.getElementById('documentView').style.display = 'block';

            // Populate Metadata
            document.getElementById('docTitle').textContent = fullDoc.title || 'Document';
            
            const docStatusEl = document.getElementById('docStatus');
            if (docStatusEl) {
                const statusInfo = this.getStatusInfo(fullDoc.status);
                docStatusEl.textContent = statusInfo.label || fullDoc.status;
                docStatusEl.className = `status-badge ${fullDoc.status}`;
            }
            
            // Delegate PDF Loading to PdfViewer
            const pdfUrl = fullDoc.file_path; 
            await this.pdfViewer.loadPdf(pdfUrl, fullDoc);

            // CSC PRESIDENTS ARE RESTRICTED HERE JUST LIKE REGULAR STUDENTS
            const isRestrictedView = this.currentUser?.role === 'student' && 
                                     this.currentUser?.position !== 'Supreme Student Council President';

            const timelineCard = document.getElementById('workflowSteps')?.closest('.sidebar-card');
            if (timelineCard) timelineCard.style.display = isRestrictedView ? 'none' : 'block';

            const commentsCard = document.querySelector('.comments-container')?.closest('.sidebar-card');
            if (commentsCard) commentsCard.style.display = isRestrictedView ? 'none' : 'block';

            // Only generate timeline and comments if authorized to view them
            if (!isRestrictedView) {
                this.commentsManager.init(fullDoc.id);
                this.renderWorkflowTimeline(fullDoc);
            }

            // Update Action Buttons
            this.updateApprovalButtonsVisibility(fullDoc);

        } catch (error) {
            console.error(error);
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: 'Failed to open document.' });
        }
    }

    renderWorkflowTimeline(doc) {
        const workflowSteps = document.getElementById('workflowSteps');
        if (!workflowSteps) return;

        const steps = doc.workflow || [];
        const currentStepIndex = steps.findIndex(step => step.status === 'pending');

        workflowSteps.innerHTML = steps.map((step, index) => {
            const isCurrent = index === currentStepIndex;
            const isCompleted = step.status === 'completed';
            const isRejected = step.status === 'rejected';
            const isPending = step.status === 'pending';
            
            let statusClass = isCompleted ? 'completed' : (isRejected ? 'rejected' : (isPending ? 'pending' : 'waiting'));
            let statusIcon = isCompleted ? 'bi-check-circle-fill' : (isRejected ? 'bi-x-circle-fill' : (isPending ? 'bi-hourglass-split' : 'bi-circle'));
            let statusMsg = isCompleted ? 'Approved and signed' : (isRejected ? 'Rejected' : (isPending ? 'Awaiting approval' : 'Waiting in line'));

            return `
                <div class="workflow-step-modern ${statusClass} ${isCurrent ? 'current-step' : ''}">
                    <div class="step-icon-modern ${statusClass}">
                        <i class="bi ${statusIcon}"></i>
                    </div>
                    <div class="step-info-modern">
                        <div class="step-name-modern">
                            ${this.escapeHtml(step.name)}
                            ${isCurrent ? '<span class="current-indicator">← Current</span>' : ''}
                        </div>
                        <div class="step-assignee-modern">${this.escapeHtml(step.assignee_name || 'Unassigned')}</div>
                        <div class="step-date-modern">
                            ${step.acted_at ? new Date(step.acted_at).toLocaleDateString() : statusMsg}
                        </div>
                        ${step.note ? `<div class="step-note-modern">"${this.escapeHtml(step.note)}"</div>` : ''}
                    </div>
                </div>`;
        }).join('');
    }

    goBack() {
        document.getElementById('documentView').style.display = 'none';
        document.getElementById('dashboardView').style.display = 'block';
        this.currentDocument = null;
    }

    // ------------------------------------------------------------------
    // Core Actions (Sign / Reject / Delete)
    // ------------------------------------------------------------------

    updateApprovalButtonsVisibility(doc) {
        const signBtn = document.querySelector('.action-btn-full.success');
        const rejectBtn = document.querySelector('.action-btn-full.danger');
        const signaturePadToggle = document.getElementById('signaturePadToggle');
        const signatureStatusContainer = document.getElementById('signatureStatusContainer');

        if (!signBtn || !rejectBtn) return;

        // Reset visibility
        signBtn.style.display = 'none';
        rejectBtn.style.display = 'none';
        if (signaturePadToggle) signaturePadToggle.style.display = 'none';
        if (signatureStatusContainer) signatureStatusContainer.style.display = 'none';

        const docStatus = this.normalizeDocumentStatus(doc.status);

        if (docStatus === 'approved' || docStatus === 'rejected' || docStatus === 'cancelled' || doc.user_action_completed) {
            return; 
        }

        const isStudentCreator = this.currentUser.role === 'student' && doc.student?.id === this.currentUser.id;
        
        // BUG FIX: Strictly verify if the user is assigned to the current step. 
        // This ensures creators cannot sign out of turn or interact improperly.
        const isAssigned = this.signatureManager.isUserAssignedToPendingStep(doc);

        if (isAssigned) {
            signBtn.style.display = 'flex';
            rejectBtn.style.display = 'flex';
            
            if (isStudentCreator) {
                signBtn.querySelector('.btn-title').textContent = 'Submit Document';
                rejectBtn.querySelector('.btn-title').textContent = 'Delete Document';
                rejectBtn.onclick = () => this.deleteDocument(doc.id);
            } else {
                signBtn.querySelector('.btn-title').textContent = 'Sign & Approve';
                rejectBtn.querySelector('.btn-title').textContent = 'Reject';
                rejectBtn.onclick = () => showRejectModal(); // Link to original UI modal
            }

            if (signaturePadToggle) signaturePadToggle.style.display = 'block';
            if (signatureStatusContainer) signatureStatusContainer.style.display = 'block';

            // Disable signature button if pad hasn't been used yet
            signBtn.disabled = !this.signatureManager.signatureImage;
            signBtn.onclick = () => this.signDocument(doc.id);
        }
    }

    async signDocument(docId) {
        const doc = this.currentDocument;
        const isSaf = doc.doc_type === 'saf';

        if (!this.signatureManager.signatureImage) {
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Action Required', message: 'Please set your signature first.' });
            return;
        }

        const msg = isSaf 
            ? 'Your signature will be placed on both Accounting and Issuer copies. Proceed?' 
            : 'Are you sure you want to sign and approve this document?';

        if (typeof showConfirmModal === 'function') {
            showConfirmModal('Sign Document', msg, () => this.executeSign(docId, doc, isSaf), isSaf ? 'Sign Both' : 'Sign & Approve', 'btn-success');
        } else {
            if (confirm(msg)) this.executeSign(docId, doc, isSaf);
        }
    }

    async executeSign(docId, doc) {
        try {
            const signedPdfBlob = await this.signatureManager.applySignatureToPdf(doc);
            const pendingStep = doc.workflow?.find(s => s.status === 'pending');

            const formData = new FormData();
            formData.append('action', 'sign');
            formData.append('document_id', docId);
            formData.append('step_id', pendingStep?.id || '');
            
            // --- FIX: Pass the entire array of dynamically placed signatures ---
            formData.append('signature_map', JSON.stringify(this.signatureManager.placedSignatures));
            
            if (signedPdfBlob) formData.append('signed_pdf', signedPdfBlob, 'signed_document.pdf');

            const res = await fetch(this.apiBase, { method: 'POST', body: formData });
            const result = await res.json();

            if (result.success) {
                if (window.ToastManager) window.ToastManager.show({ type: 'success', title: 'Success', message: 'Document signed successfully.' });
                
                this.signatureManager.signatureImage = null;
                this.signatureManager.placedSignatures = []; // Reset the array
                
                setTimeout(() => {
                    this.goBack();
                    this.loadDocuments();
                }, 1000);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            console.error(error);
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: error.message || 'Failed to sign document.' });
        }
    }

    async rejectDocument(docId, reason) {
        try {
            const pendingStep = this.currentDocument?.workflow?.find(s => s.status === 'pending');
            
            const res = await fetch(this.apiBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'reject', 
                    document_id: docId, 
                    reason: reason.trim(), 
                    step_id: pendingStep?.id 
                })
            });
            const result = await res.json();

            if (result.success) {
                if (window.ToastManager) window.ToastManager.show({ type: 'warning', title: 'Rejected', message: 'Document has been rejected.' });
                this.goBack();
                this.loadDocuments();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: 'Failed to reject.' });
        }
    }

    async deleteDocument(docId) {
        if (!confirm('Are you sure you want to delete this document? This cannot be undone.')) return;

        try {
            const response = await fetch(`${this.apiBase}?id=${docId}`, { method: 'DELETE' });
            const result = await response.json();

            if (result.success) {
                if (window.ToastManager) window.ToastManager.show({ type: 'success', title: 'Deleted', message: 'Document deleted successfully.' });
                this.goBack();
                this.loadDocuments();
            }
        } catch (error) {
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: 'Failed to delete document.' });
        }
    }

    // ------------------------------------------------------------------
    // UI Formatters & Helpers
    // ------------------------------------------------------------------

    getStatusInfo(status) {
        const s = typeof normalizeWorkflowStatus === 'function'
            ? normalizeWorkflowStatus(status, 'document')
            : (status || '').toLowerCase();
        const label = typeof getStatusText === 'function'
            ? getStatusText(s, 'document')
            : (status || '');
        const map = {
            draft: { icon: 'pencil-square', class: 'secondary', label: label },
            submitted: { icon: 'clock', class: 'warning', label: label },
            in_progress: { icon: 'arrow-repeat', class: 'primary', label: label },
            approved: { icon: 'check-circle', class: 'success', label: label },
            rejected: { icon: 'x-circle', class: 'danger', label: label },
            on_hold: { icon: 'exclamation-triangle', class: 'warning', label: label },
            cancelled: { icon: 'slash-circle', class: 'dark', label: label }
        };
        return map[s] || { icon: 'circle', class: 'secondary', label: label || status };
    }

    normalizeDocumentStatus(status) {
        if (typeof normalizeWorkflowStatus === 'function') {
            return normalizeWorkflowStatus(status, 'document');
        }
        return String(status || '').toLowerCase();
    }

    formatDocType(type) {
        const typeMap = {
            'saf': 'Student Activity Fund',
            'publication': 'Publication',
            'proposal': 'Project Proposal',
            'facility': 'Facility Request',
            'communication': 'Communication Letter'
        };
        return typeMap[type] || type;
    }

    formatDate(dateString) {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
    }

    getDueDate(doc) {
        const status = this.normalizeDocumentStatus(doc.status);
        if (['approved', 'rejected', 'cancelled', 'on_hold'].includes(status)) {
            return null;
        }

        let timerStartTime = doc.uploaded_at ? new Date(doc.uploaded_at).getTime() : Date.now();
        
        const wf = doc.workflow || [];
        if (wf.length > 0) {
            const completedSteps = wf.filter(step => step.status === 'completed' && step.acted_at);
            if (completedSteps.length > 0) {
                completedSteps.sort((a, b) => new Date(b.acted_at).getTime() - new Date(a.acted_at).getTime());
                timerStartTime = new Date(completedSteps[0].acted_at).getTime();
            }
        }

        return new Date(timerStartTime + (5 * 86400000));
    }

    getDaysUntilDue(dueDate) {
        if (!dueDate) return null;
        
        const now = new Date().getTime();
        const diffInMs = dueDate.getTime() - now;
        const days = Math.ceil(diffInMs / 86400000);
        
        return days < 0 ? 0 : days; 
    }

    computeProgress(doc) {
        if (this.normalizeDocumentStatus(doc.status) === 'approved') return 100;
        const wf = doc.workflow || [];
        if (wf.length === 0) return 0;
        const completed = wf.filter(step => step.status === 'completed').length;
        return Math.round((completed / wf.length) * 100);
    }

    escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ------------------------------------------------------------------
    // Helpers (Sorting, Grouping, etc.)
    // ------------------------------------------------------------------

    sortDocuments(documents, sortOption) {
        return documents.sort((a, b) => {
            let result = 0;
            const dateA = a.uploaded_at ? new Date(a.uploaded_at).getTime() : 0;
            const dateB = b.uploaded_at ? new Date(b.uploaded_at).getTime() : 0;
            const dueA = this.getDueDate(a)?.getTime() ?? Number.MAX_SAFE_INTEGER;
            const dueB = this.getDueDate(b)?.getTime() ?? Number.MAX_SAFE_INTEGER;

            switch (sortOption) {
                case 'date_desc': result = dateB - dateA; break;
                case 'date_asc':  result = dateA - dateB; break;
                case 'due_desc':  result = dueA - dueB; break; // Soonest first
                case 'due_asc':   result = dueB - dueA; break; // Latest first
                case 'name_asc':  result = (a.title || '').localeCompare(b.title || ''); break;
                case 'name_desc': result = (b.title || '').localeCompare(a.title || ''); break;
            }
            return result;
        });
    }

    groupDocuments(documents, groupBy) {
        const groups = {};
        documents.forEach(doc => {
            let key = 'Other';
            if (groupBy === 'doc_type') key = this.formatDocType(doc.doc_type || 'Unknown');
            if (groupBy === 'department') key = doc.student?.department || doc.department || 'Unknown';
            if (groupBy === 'status') key = this.getStatusInfo(doc.status).label;

            if (!groups[key]) groups[key] = [];
            groups[key].push(doc);
        });
        return groups;
    }

    setupLoadingState() {
        this.loadingOverlay = document.createElement('div');
        this.loadingOverlay.className = 'loading-overlay d-none';
        this.loadingOverlay.innerHTML = `<div class="spinner-border text-primary"></div>`;
        document.body.appendChild(this.loadingOverlay);
    }

    setLoading(loading) {
        this.isLoading = loading;
        if (this.loadingOverlay) this.loadingOverlay.classList.toggle('d-none', !loading);
    }

    updateStatsDisplay() {
        const normalize = (value) => this.normalizeDocumentStatus(value);

        const stats = {
            totalCount: this.documents.length,
            submittedCount: this.documents.filter(d => normalize(d.status) === 'submitted').length,
            inReviewCount: this.documents.filter(d => normalize(d.status) === 'in_progress').length,
            approvedCount: this.documents.filter(d => normalize(d.status) === 'approved').length
        };

        for (const [id, value] of Object.entries(stats)) {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        }
    }

    debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func(...args), wait);
        };
    }
}

// ------------------------------------------------------------------
// Global Bootstrapping
// ------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', function () {
    window.documentSystem = new DocumentNotificationSystem();
    window.documentSystem.init();
});

// Settings Modals (Legacy support from original file)
if (window.NavbarSettings) {
    window.openProfileSettings = window.NavbarSettings.openProfileSettings;
    window.openChangePassword = window.NavbarSettings.openChangePassword;
    window.openPreferences = window.NavbarSettings.openPreferences;
    window.showHelp = window.NavbarSettings.showHelp;
}