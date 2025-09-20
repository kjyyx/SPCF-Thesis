// Document Notification System JavaScript
// High-level: Presents a dashboard of actionable documents with statuses, details view, and simple toasts.
// Notes for future developers:
// - Keep exported functions (init, openDocument, goBack, etc.) used by HTML intact.
// - This module uses mock data; replace with API calls when backend is ready.
// - All DOM lookups are guarded; ensure IDs/classes match the HTML.

class DocumentNotificationSystem {
    constructor() {
        this.documents = [
            {
                id: 1,
                title: "Annual Budget Approval",
                type: "Financial Document",
                status: "urgent",
                dueDate: "2024-01-15",
                from: "Finance Department",
                fileName: "annual_budget_2024.pdf",
                content: `
                    <p><strong>EXECUTIVE SUMMARY</strong></p>
                    <p>This document presents the comprehensive annual budget proposal for fiscal year 2024, totaling $12.5 million across all departments and initiatives.</p>
                    
                    <p><strong>KEY BUDGET ALLOCATIONS:</strong></p>
                    <ul>
                        <li>Research & Development: $3.2M (15% increase from 2023)</li>
                        <li>Operations: $4.8M</li>
                        <li>Marketing & Sales: $2.1M</li>
                        <li>Human Resources: $1.4M</li>
                        <li>Digital Transformation: $1.0M (New Initiative)</li>
                    </ul>
                    
                    <p><strong>APPROVAL REQUIRED:</strong></p>
                    <p>Your signature is required to authorize the budget allocation and proceed with implementation for Q1 2024.</p>
                `,
                workflow: [
                    { name: "Finance Manager", status: "completed", date: "2024-01-10" },
                    { name: "You", status: "pending", date: null },
                    { name: "CEO", status: "waiting", date: null },
                    { name: "Board of Directors", status: "waiting", date: null }
                ]
            },
            {
                id: 2,
                title: "Employee Handbook Update",
                type: "HR Policy",
                status: "normal",
                dueDate: "2024-01-20",
                from: "Human Resources",
                fileName: "employee_handbook_v2024.pdf",
                content: `
                    <p><strong>POLICY UPDATES SUMMARY</strong></p>
                    <p>The following updates have been made to the Employee Handbook effective January 2024:</p>
                    
                    <p><strong>1. REMOTE WORK POLICY</strong></p>
                    <ul>
                        <li>Flexible hybrid work arrangements (3 days office, 2 days remote)</li>
                        <li>Home office equipment allowance: $500 annually</li>
                        <li>Core collaboration hours: 10 AM - 3 PM local time</li>
                    </ul>
                    
                    <p><strong>2. VACATION POLICY</strong></p>
                    <ul>
                        <li>Increased PTO: 20 days (up from 15 days)</li>
                        <li>Mental health days: 3 additional days per year</li>
                        <li>Unlimited sick leave policy</li>
                    </ul>
                    
                    <p><strong>ACKNOWLEDGMENT:</strong> Your signature confirms receipt and understanding of these policy changes.</p>
                `,
                workflow: [
                    { name: "HR Director", status: "completed", date: "2024-01-08" },
                    { name: "You", status: "pending", date: null },
                    { name: "Legal Team", status: "waiting", date: null }
                ]
            },
            {
                id: 3,
                title: "Vendor Contract Renewal",
                type: "Legal Contract",
                status: "high",
                dueDate: "2024-01-18",
                from: "Procurement Team",
                fileName: "vendor_contract_techcorp_2024.pdf",
                content: `
                    <p><strong>CONTRACT RENEWAL TERMS</strong></p>
                    <p>TechCorp Solutions - Software Licensing Agreement Renewal</p>
                    
                    <p><strong>CONTRACT DETAILS:</strong></p>
                    <ul>
                        <li>Contract Period: January 1, 2024 - December 31, 2026</li>
                        <li>Annual License Fee: $180,000 (5% increase from previous term)</li>
                        <li>User Licenses: Up to 500 concurrent users</li>
                        <li>Support Level: Premium 24/7 support included</li>
                    </ul>
                    
                    <p><strong>NEW TERMS:</strong></p>
                    <ul>
                        <li>99.9% uptime SLA guarantee</li>
                        <li>Quarterly business reviews included</li>
                        <li>Free migration to cloud infrastructure</li>
                        <li>Advanced security compliance (SOC 2, ISO 27001)</li>
                    </ul>
                    
                    <p><strong>AUTHORIZATION:</strong> Department head signature required to proceed with contract execution.</p>
                `,
                workflow: [
                    { name: "Procurement Manager", status: "completed", date: "2024-01-09" },
                    { name: "You", status: "pending", date: null },
                    { name: "Legal Counsel", status: "waiting", date: null },
                    { name: "CFO", status: "waiting", date: null }
                ]
            }
        ];

        this.currentDocument = null;
        this.signatureApplied = false;
        this.currentZoom = 100;
        
        this.init();
    }

    // Initialize the application
    init() {
        this.renderDocuments();
        this.setupEventListeners();
        this.checkPendingNotifications();
        this.updateStatsDisplay();
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

    // Check for urgent notifications
    checkPendingNotifications() {
        const urgentDocs = this.documents.filter(doc => doc.status === 'urgent');
        if (urgentDocs.length > 0) {
            this.showNotificationAlert(urgentDocs.length);
        }
    }

    // Show notification alert
    showNotificationAlert(count) {
        setTimeout(() => {
            this.showToast({
                type: 'warning',
                title: 'Urgent Documents',
                message: `You have ${count} urgent document${count > 1 ? 's' : ''} requiring immediate attention.`,
                duration: 5000
            });
        }, 1000);
    }

    // Render documents in dashboard
    renderDocuments() {
    const container = document.getElementById('documentsContainer');
    if (!container) return;
        
        // Add loading state
        container.innerHTML = '<div class="col-12 text-center"><div class="loading" style="height: 200px;"></div></div>';
        
        setTimeout(() => {
            container.innerHTML = '';

            this.documents.forEach(doc => {
                const statusInfo = this.getStatusInfo(doc.status);
                const daysUntilDue = this.getDaysUntilDue(doc.dueDate);
                
                const card = document.createElement('div');
                card.className = 'col-md-6 col-lg-4';
                card.innerHTML = `
                    <div class="card document-card h-100" onclick="documentSystem.openDocument(${doc.id})" tabindex="0" role="button" aria-label="Open ${doc.title}">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h6 class="card-title mb-0 fw-semibold">${doc.title}</h6>
                                <span class="status-badge ${doc.status}">
                                    <i class="bi bi-${statusInfo.icon} me-1"></i>${doc.status.toUpperCase()}
                                </span>
                            </div>
                            
                            <div class="document-info mb-3">
                                <div class="d-flex align-items-center text-muted mb-2">
                                    <i class="bi bi-folder me-2"></i>
                                    <span class="fs-sm">${doc.type}</span>
                                </div>
                                <div class="d-flex align-items-center text-muted mb-2">
                                    <i class="bi bi-person me-2"></i>
                                    <span class="fs-sm">From: ${doc.from}</span>
                                </div>
                                <div class="d-flex align-items-center text-muted">
                                    <i class="bi bi-calendar me-2"></i>
                                    <span class="fs-sm">Due: ${this.formatDate(doc.dueDate)}</span>
                                    ${daysUntilDue !== null ? 
                                        `<span class="badge ${daysUntilDue <= 1 ? 'bg-danger' : daysUntilDue <= 3 ? 'bg-warning' : 'bg-info'} ms-2 fs-sm">
                                            ${daysUntilDue === 0 ? 'Today' : daysUntilDue === 1 ? '1 day' : `${daysUntilDue} days`}
                                        </span>` : '<span class="badge bg-secondary ms-2 fs-sm">Overdue</span>'
                                    }
                                </div>
                            </div>
                            
                            <div class="workflow-preview">
                                <small class="text-muted">Workflow Progress:</small>
                                <div class="progress mt-1" style="height: 6px;">
                                    <div class="progress-bar ${doc.status === 'urgent' ? 'bg-danger' : doc.status === 'high' ? 'bg-warning' : 'bg-info'}" 
                                         style="width: ${(doc.workflow.filter(step => step.status === 'completed').length / doc.workflow.length) * 100}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Add keyboard support for cards
                const cardElement = card.querySelector('.document-card');
                cardElement.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.openDocument(doc.id);
                    }
                });
                
                container.appendChild(card);
            });

            this.updateStatsDisplay();
        }, 300);
    }

    // Get status information
    getStatusInfo(status) {
        const statusMap = {
            urgent: { icon: 'exclamation-triangle-fill', class: 'danger' },
            high: { icon: 'clock-fill', class: 'warning' },
            normal: { icon: 'info-circle-fill', class: 'info' }
        };
        return statusMap[status] || statusMap.normal;
    }

    // Calculate days until due date
    getDaysUntilDue(dueDate) {
        const today = new Date();
        const due = new Date(dueDate);
        const diffTime = due - today;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays >= 0 ? diffDays : null;
    }

    // Format date for display
    formatDate(dateString) {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // Update statistics display
    updateStatsDisplay() {
        const urgentCount = this.documents.filter(doc => doc.status === 'urgent').length;
        const highCount = this.documents.filter(doc => doc.status === 'high').length;
        const normalCount = this.documents.filter(doc => doc.status === 'normal').length;
        const totalPending = urgentCount + highCount + normalCount;

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
        if (elements.pendingCount) elements.pendingCount.textContent = totalPending;
        if (elements.notificationCount) elements.notificationCount.textContent = totalPending;
    }

    // Filter documents by status
    filterDocuments(status) {
    const container = document.getElementById('documentsContainer');
    if (!container) return;

        // Show loading state
        container.innerHTML = '<div class="col-12 text-center"><div class="loading" style="height: 200px;"></div></div>';

        setTimeout(() => {
            let filteredDocs = this.documents;
            if (status !== 'all') {
                filteredDocs = this.documents.filter(doc => doc.status === status);
            }

            container.innerHTML = '';
            filteredDocs.forEach(doc => {
                const statusInfo = this.getStatusInfo(doc.status);
                const daysUntilDue = this.getDaysUntilDue(doc.dueDate);
                
                const colDiv = document.createElement('div');
                colDiv.className = 'col-md-6 col-lg-4';
                
                colDiv.innerHTML = `
                    <div class="card document-card h-100" onclick="openDocument(${doc.id})">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h6 class="card-title mb-0">${doc.title}</h6>
                                <span class="status-badge ${doc.status}">
                                    <i class="bi bi-${statusInfo.icon} me-1"></i>${doc.status.toUpperCase()}
                                </span>
                            </div>
                            
                            <div class="document-info mb-3">
                                <div class="d-flex align-items-center text-muted mb-2">
                                    <i class="bi bi-folder me-2"></i>
                                    <span>${doc.type}</span>
                                </div>
                                <div class="d-flex align-items-center text-muted mb-2">
                                    <i class="bi bi-person me-2"></i>
                                    <span>From: ${doc.from}</span>
                                </div>
                                <div class="d-flex align-items-center text-muted">
                                    <i class="bi bi-calendar me-2"></i>
                                    <span>Due: ${this.formatDate(doc.dueDate)}</span>
                                    ${daysUntilDue !== null ? 
                                        `<span class="badge ${daysUntilDue <= 1 ? 'bg-danger' : daysUntilDue <= 3 ? 'bg-warning' : 'bg-info'} ms-2">
                                            ${daysUntilDue === 0 ? 'Today' : daysUntilDue === 1 ? '1 day' : `${daysUntilDue} days`}
                                        </span>` : '<span class="badge bg-secondary ms-2">Overdue</span>'
                                    }
                                </div>
                            </div>
                            
                            <div class="workflow-preview">
                                <small class="text-muted">Workflow Progress:</small>
                                <div class="progress mt-1" style="height: 6px;">
                                    <div class="progress-bar ${doc.status === 'urgent' ? 'bg-danger' : doc.status === 'high' ? 'bg-warning' : 'bg-info'}" 
                                         style="width: ${(doc.workflow.filter(step => step.status === 'completed').length / doc.workflow.length) * 100}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                container.appendChild(colDiv);
            });

            // Show empty state if no documents
            if (filteredDocs.length === 0) {
                container.innerHTML = `
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h5 class="text-muted mt-3">No documents found</h5>
                        <p class="text-muted">No documents match the selected filter.</p>
                    </div>
                `;
            }
        }, 300);
    }

    // Update pending count
    updatePendingCount() {
        const countElement = document.getElementById('pendingCount');
        if (countElement) {
            countElement.textContent = `${this.documents.length} Pending`;
        }
    }

    // Open document detail view
    openDocument(docId) {
    this.currentDocument = this.documents.find(doc => doc.id === docId);
        if (!this.currentDocument) return;

        // Add loading state
        document.getElementById('dashboardView').style.display = 'none';
        document.getElementById('documentView').style.display = 'block';
        document.getElementById('documentView').classList.add('loading');

        setTimeout(() => {
            this.populateDocumentDetails();
            document.getElementById('documentView').classList.remove('loading');
        }, 500);
    }

    // Populate document details
    populateDocumentDetails() {
        const elements = {
            docTitle: document.getElementById('docTitle'),
            pdfFileName: document.getElementById('pdfFileName'),
            pdfTitle: document.getElementById('pdfTitle'),
            pdfContent: document.getElementById('pdfContent'),
            docStatus: document.getElementById('docStatus'),
            zoomLevel: document.getElementById('zoomLevel')
        };

        if (elements.docTitle) elements.docTitle.textContent = this.currentDocument.title;
        if (elements.pdfFileName) elements.pdfFileName.textContent = this.currentDocument.fileName;
        if (elements.pdfTitle) elements.pdfTitle.textContent = this.currentDocument.title;
        if (elements.pdfContent) elements.pdfContent.innerHTML = this.currentDocument.content;
        
        if (elements.docStatus) {
            const statusInfo = this.getStatusInfo(this.currentDocument.status);
            elements.docStatus.className = `status-badge ${this.currentDocument.status}`;
            elements.docStatus.innerHTML = `<i class="bi bi-${statusInfo.icon} me-1"></i>${this.currentDocument.status.toUpperCase()}`;
        }

        if (elements.zoomLevel) elements.zoomLevel.textContent = `${this.currentZoom}%`;

        this.renderWorkflow();
        this.resetSignatureState();
        
        // Clear previous inputs
        const notesInput = document.getElementById('notesInput');
        if (notesInput) notesInput.value = '';
        this.currentZoom = 100;
        this.updateZoom();
    }

    // Render workflow steps - Compact Version
    renderWorkflow() {
        const container = document.getElementById('workflowSteps');
        if (!container) return;
        
        container.innerHTML = '';

        this.currentDocument.workflow.forEach((step, index) => {
            const stepDiv = document.createElement('div');
            stepDiv.className = 'workflow-step-compact';
            
            let statusIcon = '';
            let statusClass = '';
            
            switch(step.status) {
                case 'completed':
                    statusIcon = '✓';
                    statusClass = 'completed';
                    break;
                case 'pending':
                    statusIcon = '●';
                    statusClass = 'pending';
                    break;
                case 'waiting':
                    statusIcon = '○';
                    statusClass = 'waiting';
                    break;
            }
            
            stepDiv.innerHTML = `
                <div class="workflow-icon-compact ${statusClass}">
                    ${statusIcon}
                </div>
                <div class="step-info">
                    <div class="step-name" title="${step.name}">${step.name}</div>
                    ${step.date ? `<div class="step-date">${this.formatDate(step.date)}</div>` : ''}
                </div>
            `;
            
            container.appendChild(stepDiv);
        });
    }

    // Apply signature from database - Compact Version
    applySignature() {
    const signaturePlaceholder = document.getElementById('signaturePlaceholder');
        const appliedSignature = document.getElementById('appliedSignature');
        const applySignatureBtn = document.getElementById('applySignatureBtn');

        if (!signaturePlaceholder || !appliedSignature || !applySignatureBtn) return;

        this.signatureApplied = true;
        
        // Simple transition for compact layout
        signaturePlaceholder.style.display = 'none';
        appliedSignature.style.display = 'flex';

        applySignatureBtn.innerHTML = '<i class="bi bi-check me-1"></i>Applied';
        applySignatureBtn.className = 'btn btn-outline-success btn-sm';
        applySignatureBtn.disabled = true;

        this.showToast({
            type: 'success',
            title: 'Signature Applied',
            message: 'Your signature has been applied.',
            duration: 2000
        });
    }

    // Reset signature state - Compact Version
    resetSignatureState() {
    const signaturePlaceholder = document.getElementById('signaturePlaceholder');
        const appliedSignature = document.getElementById('appliedSignature');
        const applySignatureBtn = document.getElementById('applySignatureBtn');

        if (!signaturePlaceholder || !appliedSignature || !applySignatureBtn) return;

        this.signatureApplied = false;
        signaturePlaceholder.style.display = 'flex';
        appliedSignature.style.display = 'none';
        applySignatureBtn.innerHTML = '<i class="bi bi-person-check me-1"></i>Apply';
        applySignatureBtn.className = 'btn btn-outline-primary btn-sm';
        applySignatureBtn.disabled = false;
    }

    // PDF viewer functions
    zoomIn() {
        this.currentZoom = Math.min(this.currentZoom + 10, 200);
        this.updateZoom();
    }

    zoomOut() {
        this.currentZoom = Math.max(this.currentZoom - 10, 50);
        this.updateZoom();
    }

    updateZoom() {
        const pdfContent = document.getElementById('pdfContent');
        const zoomLevel = document.getElementById('zoomLevel');
        
        if (pdfContent) {
            pdfContent.style.transform = `scale(${this.currentZoom / 100})`;
            pdfContent.style.transformOrigin = 'top left';
        }
        
        if (zoomLevel) {
            zoomLevel.textContent = `${this.currentZoom}%`;
        }
    }

    // Download PDF functionality
    downloadPDF() {
        // Simulate PDF download
        const link = document.createElement('a');
        link.href = '#';
        link.download = this.currentDocument.fileName;
        link.click();
        
        this.showToast({
            type: 'info',
            title: 'Download Started',
            message: `${this.currentDocument.fileName} download has begun.`
        });
    }

    // Sign document
    signDocument() {
        const notes = document.getElementById('notesInput')?.value || '';
        
        if (!this.signatureApplied) {
            this.showToast({
                type: 'error',
                title: 'Signature Required',
                message: 'Please apply your signature before proceeding.',
                duration: 4000
            });
            
            // Highlight signature section
            const signatureSection = document.querySelector('.signature-section');
            if (signatureSection) {
                signatureSection.style.animation = 'shake 0.5s ease-in-out';
                setTimeout(() => {
                    signatureSection.style.animation = '';
                }, 500);
            }
            return;
        }

        const signButton = document.getElementById('signButton');
        if (signButton) {
            signButton.classList.add('loading');
            signButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
            signButton.disabled = true;
        }

        setTimeout(() => {
            this.processDocumentSigning(notes);
        }, 1500);
    }

    // Process document signing
    processDocumentSigning(notes) {
        // Update workflow
        const currentStep = this.currentDocument.workflow.find(step => step.status === 'pending');
        if (currentStep) {
            currentStep.status = 'completed';
            currentStep.date = new Date().toISOString().split('T')[0];
            
            // Move to next step
            const nextStepIndex = this.currentDocument.workflow.findIndex(step => step.status === 'waiting');
            if (nextStepIndex !== -1) {
                this.currentDocument.workflow[nextStepIndex].status = 'pending';
            }
        }

        // Save notes if provided
        if (notes.trim()) {
            this.currentDocument.notes = notes;
        }

        // Remove document from pending list if workflow is complete
        const allCompleted = this.currentDocument.workflow.every(step => step.status === 'completed');
        if (allCompleted) {
            const index = this.documents.findIndex(doc => doc.id === this.currentDocument.id);
            if (index !== -1) {
                this.documents.splice(index, 1);
            }
        }

        // Update UI
        const signButton = document.getElementById('signButton');
        if (signButton) {
            signButton.classList.remove('loading');
            signButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Document Signed Successfully!';
            signButton.className = 'btn btn-success w-100';
        }

        this.renderWorkflow();

        this.showToast({
            type: 'success',
            title: 'Document Signed',
            message: `${this.currentDocument.title} has been signed and sent to the next recipient.`,
            duration: 4000
        });

        setTimeout(() => {
            this.goBack();
        }, 2000);
    }

    // Save notes function
    saveNotes() {
        const notes = document.getElementById('notesInput')?.value || '';
        if (this.currentDocument && notes.trim()) {
            this.currentDocument.tempNotes = notes;
            
            // Show subtle save indicator
            const notesInput = document.getElementById('notesInput');
            if (notesInput) {
                notesInput.style.borderColor = '#10b981';
                setTimeout(() => {
                    notesInput.style.borderColor = '';
                }, 1000);
            }
        }
    }

    // Go back to dashboard
    goBack() {
        document.getElementById('documentView').style.display = 'none';
        document.getElementById('dashboardView').style.display = 'block';
        this.renderDocuments();
        
        // Reset states
        const signButton = document.getElementById('signButton');
        if (signButton) {
            signButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Sign & Send to Next Recipient';
            signButton.className = 'btn btn-success w-100';
            signButton.disabled = false;
            signButton.classList.remove('loading');
        }
        
        this.currentZoom = 100;
        this.currentDocument = null;
    }

    // Show toast notification
    showToast({ type = 'info', title, message, duration = 3000 }) {
        const toastContainer = this.getOrCreateToastContainer();
        
        const toast = document.createElement('div');
        toast.className = 'toast show';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        
        const iconMap = {
            success: 'check-circle',
            error: 'exclamation-triangle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        
        const colorMap = {
            success: 'success',
            error: 'danger',
            warning: 'warning',
            info: 'primary'
        };
        
        toast.innerHTML = `
            <div class="toast-header">
                <i class="bi bi-${iconMap[type]} text-${colorMap[type]} me-2"></i>
                <strong class="me-auto">${title}</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        `;
        
        toastContainer.appendChild(toast);
        
        // Auto remove after duration
        setTimeout(() => {
            toast.remove();
        }, duration);
        
        // Add close functionality
        const closeBtn = toast.querySelector('.btn-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => toast.remove());
        }
    }

    // Get or create toast container
    getOrCreateToastContainer() {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1080';
            document.body.appendChild(container);
        }
        return container;
    }
}

// Global functions for backward compatibility
let documentSystem;

function init() {
    documentSystem = new DocumentNotificationSystem();
}

function openDocument(docId) {
    documentSystem.openDocument(docId);
}

function goBack() {
    documentSystem.goBack();
}

function applySignature() {
    documentSystem.applySignature();
}

function signDocument() {
    documentSystem.signDocument();
}

function zoomIn() {
    documentSystem.zoomIn();
}

function zoomOut() {
    documentSystem.zoomOut();
}

function downloadPDF() {
    documentSystem.downloadPDF();
}

function saveNotes() {
    documentSystem.saveNotes();
}

function filterDocuments(status) {
    documentSystem.filterDocuments(status);
}

function printDocument() {
    window.print();
}

// CSS for shake animation
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
`;
document.head.appendChild(style);

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', init);
