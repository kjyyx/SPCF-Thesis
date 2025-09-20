// Document Notification System JavaScript
// High-level: Presents a dashboard of actionable documents with statuses, details view, and simple toasts.
// Notes for future developers:
// - Keep exported functions (init, openDocument, goBack, etc.) used by HTML intact.
// - This module uses mock data; replace with API calls when backend is ready.
// - All DOM lookups are guarded; ensure IDs/classes match the HTML.

class DocumentNotificationSystem {
    constructor() {
        this.documents = [];
        this.currentDocument = null;
        this.currentUser = window.currentUser || null;
        this.apiBase = '../api/documents.php';
    }

    // Initialize the application
    async init() {
        // Employee-only guard (defense-in-depth; server already restricts)
        if (!this.currentUser || this.currentUser.role !== 'employee') {
            window.location.href = 'event-calendar.php';
            return;
        }

        this.documents = [];
        await this.loadDocuments();
        this.renderDocuments();
        this.setupEventListeners();
        this.updateStatsDisplay();
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
                if (this.documents.length === 0 && this.isMockMode()) {
                    this.documents = this.generateMockDocuments();
                }
            } else {
                console.error('Failed to load documents:', data.message);
                if (this.isMockMode()) {
                    this.documents = this.generateMockDocuments();
                } else {
                    this.showToast({ type: 'error', title: 'Error', message: 'Failed to load documents' });
                }
            }
        } catch (error) {
            console.error('Error loading documents:', error);
            if (this.isMockMode()) {
                this.documents = this.generateMockDocuments();
            } else {
                this.showToast({ type: 'error', title: 'Error', message: 'Error loading documents' });
            }
        }
    }

    // Detect mock mode via query string (?mock=1) or global flag
    isMockMode() {
        try {
            const params = new URLSearchParams(window.location.search);
            return params.get('mock') === '1' || window.USE_MOCK_DATA === true;
        } catch (e) {
            return false;
        }
    }

    // Create three mock documents for testing UI
    generateMockDocuments() {
        const empId = this.currentUser?.id || 'EMP001';
        const now = new Date();
        const daysAgo = (d) => new Date(now.getTime() - d * 86400000).toISOString();

        return [
            {
                id: 1001,
                title: 'Research Proposal: IoT Campus Network',
                doc_type: 'proposal',
                description: 'Proposal to deploy IoT sensors across campus for energy efficiency.',
                status: 'submitted', // Urgent
                current_step: 1,
                uploaded_at: daysAgo(1),
                student: { id: 'STU001', name: 'Juan Dela Cruz', department: 'College of Engineering' },
                workflow: [
                    { id: 5001, step_order: 1, name: 'Initial Review', status: 'pending', note: null, acted_at: null, assignee_id: empId, assignee_name: 'You' },
                    { id: 5002, step_order: 2, name: 'Dean Approval', status: 'pending', note: null, acted_at: null, assignee_id: 'EMP999', assignee_name: 'Dean' }
                ]
            },
            {
                id: 1002,
                title: 'Student Activity Form: TechWeek 2025',
                doc_type: 'saf',
                description: 'Annual technology week with workshops and hackathons.',
                status: 'in_review', // High
                current_step: 2,
                uploaded_at: daysAgo(3),
                student: { id: 'STU002', name: 'Ana Reyes', department: 'College of Computing and Information Sciences' },
                workflow: [
                    { id: 5003, step_order: 1, name: 'Organization Adviser', status: 'completed', note: 'Looks good.', acted_at: daysAgo(2), assignee_id: empId, assignee_name: 'You' },
                    { id: 5004, step_order: 2, name: 'Student Affairs Office', status: 'pending', note: null, acted_at: null, assignee_id: empId, assignee_name: 'You' }
                ]
            },
            {
                id: 1003,
                title: 'Facility Request: Auditorium Booking',
                doc_type: 'facility',
                description: 'Request to use the main auditorium for orientation.',
                status: 'approved', // Normal
                current_step: 2,
                uploaded_at: daysAgo(5),
                student: { id: 'STU003', name: 'Mark Cruz', department: 'College of Business' },
                workflow: [
                    { id: 5005, step_order: 1, name: 'Facilities Review', status: 'completed', note: 'Available', acted_at: daysAgo(4), assignee_id: empId, assignee_name: 'You' },
                    { id: 5006, step_order: 2, name: 'Dean Approval', status: 'completed', note: 'Approved', acted_at: daysAgo(3), assignee_id: empId, assignee_name: 'You' }
                ]
            }
        ];
    }

    // Setup event listeners
    setupEventListeners() {
        // Add keyboard navigation support
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.getElementById('documentView').style.display === 'block') {
                this.goBack();
            }
        });

        // Add auto-save for notes
        const notesInput = document.getElementById('notesInput');
        if (notesInput) {
            notesInput.addEventListener('input', this.debounce(() => {
                this.saveNotes();
            }, 500));
        }
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

    // Render documents in dashboard
    renderDocuments() {
        const container = document.getElementById('documentsContainer');
        if (!container) return;

        // Add loading state
        container.innerHTML = '<div class="col-12 text-center"><div class="loading" style="height: 200px;"></div></div>';

        setTimeout(() => {
            container.innerHTML = '';
            if (this.documents.length === 0) {
                container.innerHTML = `
                    <div class="col-12 text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                            <h4 class="mt-3">All Caught Up!</h4>
                            <p class="text-muted">No documents require your attention at this time.</p>
                        </div>
                    </div>
                `;
                return;
            }

            this.documents.forEach(doc => {
                const card = this.createDocumentCard(doc);
                container.appendChild(card);
            });
        }, 300);
    }

    // Create document card
    createDocumentCard(doc) {
        const col = document.createElement('div');
        col.className = 'col-lg-6 col-xl-4';

        const statusInfo = this.getStatusInfo(doc.status);
        const currentStep = doc.workflow.find(step => step.status === 'pending') || doc.workflow[0];

        col.innerHTML = `
            <div class="document-card" onclick="documentSystem.openDocument(${doc.id})">
                <div class="card-header">
                    <div class="card-type">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>${this.formatDocType(doc.doc_type)}</span>
                    </div>
                    <div class="card-status status-${statusInfo.class}">
                        <i class="bi bi-${statusInfo.icon}"></i>
                        <span>${statusInfo.label}</span>
                    </div>
                </div>
                <div class="card-body">
                    <h5 class="card-title">${doc.title}</h5>
                    <p class="card-description">${doc.description || 'No description provided'}</p>
                    <div class="card-meta">
                        <div class="meta-item">
                            <i class="bi bi-person"></i>
                            <span>${doc.student.name}</span>
                        </div>
                        <div class="meta-item">
                            <i class="bi bi-building"></i>
                            <span>${doc.student.department}</span>
                        </div>
                        <div class="meta-item">
                            <i class="bi bi-calendar"></i>
                            <span>${this.formatDate(doc.uploaded_at)}</span>
                        </div>
                    </div>
                    <div class="current-step">
                        <div class="step-info">
                            <span class="step-label">Current Step:</span>
                            <span class="step-name">${currentStep ? currentStep.name : 'N/A'}</span>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="action-buttons">
                        <button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); documentSystem.openDocument(${doc.id})">
                            <i class="bi bi-eye me-1"></i>Review
                        </button>
                    </div>
                </div>
            </div>
        `;

        return col;
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
        const container = document.getElementById('documentsContainer');
        if (!container) return;

        // Show loading state
        container.innerHTML = '<div class="col-12 text-center"><div class="loading" style="height: 200px;"></div></div>';

        setTimeout(() => {
            container.innerHTML = '';
            let filteredDocs = this.documents;
            if (status !== 'all') {
                filteredDocs = this.documents.filter(doc => doc.status === status);
            }

            if (filteredDocs.length === 0) {
                container.innerHTML = `
                    <div class="col-12 text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                            <h4 class="mt-3">No Documents Found</h4>
                            <p class="text-muted">No documents match the selected filter.</p>
                        </div>
                    </div>
                `;
                return;
            }

            filteredDocs.forEach(doc => {
                const card = this.createDocumentCard(doc);
                container.appendChild(card);
            });
        }, 300);
    }

    // Open document modal
        async openDocument(docId) {
        const doc = this.documents.find(d => d.id === docId);
        if (!doc) return;

        // track in controller (used by Sign button outside modal)
        this.currentDocument = doc;

        // Show loading modal
        this.showDocumentModal(doc, true);

        try {
            const response = await fetch(`../api/documents.php?id=${docId}`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });
            if (!response.ok) throw new Error('Failed to load document details');
            const fullDoc = await response.json();
            // ensure currentDocument reflects latest payload
            this.currentDocument = fullDoc;
            this.showDocumentModal(fullDoc, false);
        } catch (error) {
            console.error('Error loading document:', error);
            this.showToast({
                type: 'error',
                title: 'Error',
                message: 'Failed to load document details. Please try again.'
            });
        }
    }

    // Show document modal
    showDocumentModal(doc, loading = false) {
        const modal = document.getElementById('documentModal');
        if (!modal) return;

        if (loading) {
            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Loading Document...</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-center">
                            <div class="loading" style="height: 200px;"></div>
                        </div>
                    </div>
                </div>
            `;
        } else {
            const statusInfo = this.getStatusInfo(doc.status);
            const currentStep = doc.workflow.find(step => step.status === 'pending') || doc.workflow[0];

            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-file-earmark-text me-2"></i>${doc.title}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="document-details">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="detail-item">
                                            <label class="detail-label">Document Type:</label>
                                            <span class="detail-value">${this.formatDocType(doc.doc_type)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <label class="detail-label">Status:</label>
                                            <span class="detail-value status-${statusInfo.class}">${statusInfo.label}</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="detail-item">
                                            <label class="detail-label">Student:</label>
                                            <span class="detail-value">${doc.student.name}</span>
                                        </div>
                                        <div class="detail-item">
                                            <label class="detail-label">Department:</label>
                                            <span class="detail-value">${doc.student.department}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="document-description mb-4">
                                    <h6>Description:</h6>
                                    <p>${doc.description || 'No description provided'}</p>
                                </div>
                                <div class="workflow-section mb-4">
                                    <h6>Workflow Progress:</h6>
                                    <div class="workflow-steps">
                                        ${doc.workflow.map((step, index) => `
                                            <div class="workflow-step ${step.status}">
                                                <div class="step-number">${index + 1}</div>
                                                <div class="step-content">
                                                    <div class="step-name">${step.name}</div>
                                                    <div class="step-assignee">${step.assignee_name || 'Unassigned'}</div>
                                                    <div class="step-status">${this.formatStepStatus(step.status)}</div>
                                                </div>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                                ${doc.file_path ? `
                                    <div class="document-file mb-4">
                                        <h6>Attached File:</h6>
                                        <div class="file-preview">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                            <span>${doc.file_path.split('/').pop()}</span>
                                            <a href="${doc.file_path}" target="_blank" class="btn btn-sm btn-outline-primary ms-2">
                                                <i class="bi bi-download me-1"></i>Download
                                            </a>
                                        </div>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        <div class="modal-footer">
                            ${this.getActionButtons(doc)}
                        </div>
                    </div>
                </div>
            `;
        }

        // Show modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    // Get action buttons based on document status and user role
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
            // Find step id: prefer currentDocument pending step for accuracy
            const doc = this.currentDocument && this.currentDocument.id === docId
                ? this.currentDocument
                : this.documents.find(d => d.id === docId);
            const pendingStep = doc?.workflow?.find(s => s.status === 'pending');
            const stepId = pendingStep?.id || undefined;

            const response = await fetch('../api/documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'sign', document_id: docId, step_id: stepId })
            });
            const result = await response.json();

            if (result.success) {
                this.showToast({
                    type: 'success',
                    title: 'Document Signed',
                    message: 'Document has been successfully signed and approved.'
                });

                const modal = bootstrap.Modal.getInstance(document.getElementById('documentModal'));
                if (modal) modal.hide();

                // refresh data + UI
                await this.loadDocuments();
                this.renderDocuments();
                this.updateStatsDisplay();
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
    }

    async rejectDocument(docId) {
        const reason = prompt('Please provide a reason for rejection:');
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
                });

                const modal = bootstrap.Modal.getInstance(document.getElementById('documentModal'));
                if (modal) modal.hide();

                // refresh data + UI
                await this.loadDocuments();
                this.renderDocuments();
                this.updateStatsDisplay();
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
        if (window.ToastManager) {
            window.ToastManager.show(options);
        } else {
            alert(`${options.title}: ${options.message}`);
        }
    }

    // Save notes (placeholder)
    saveNotes() {
        console.log('Saving notes...');
    }
}

// Initialize the document notification system
document.addEventListener('DOMContentLoaded', function() {
    // Initialize toast manager
    if (typeof ToastManager !== 'undefined') {
        window.toastManager = new ToastManager();
    }

    // Initialize document system
    window.documentSystem = new DocumentNotificationSystem();
    window.documentSystem.init();
});