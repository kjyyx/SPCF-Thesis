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
        this.signatureImage = null;
        this.currentSignatureMap = null;
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

    // Navigate back to dashboard from detail view
    goBack() {
        const dashboard = document.getElementById('dashboardView');
        const detail = document.getElementById('documentView');
        if (dashboard && detail) {
            detail.style.display = 'none';
            dashboard.style.display = 'block';
        }
        this.currentDocument = null;
    }

    // Create a mock document via API then reload list
    async createMockDocument() {
        try {
            const res = await fetch('../api/documents.php?action=generate_mock');
            const data = await res.json();
            if (data.success) {
                this.showToast({ type: 'success', title: 'Mock Created', message: 'Mock document generated.' });
                await this.loadDocuments();
                this.renderDocuments();
                this.updateStatsDisplay();
            } else {
                throw new Error(data.message || 'Failed to create mock');
            }
        } catch (e) {
            console.error(e);
            this.showToast({ type: 'error', title: 'Error', message: 'Failed to create mock document.' });
        }
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

    // Render documents in dashboard (old cards design)
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

            this.updateStatsDisplay();
        }, 300);
    }

    // Create document card (old cards design)
    createDocumentCard(doc) {
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4';

        const statusInfo = this.getStatusInfo(doc.status);
        const dueDate = this.getDueDate(doc);
        const daysUntilDue = this.getDaysUntilDue(dueDate);
        const progressPct = this.computeProgress(doc);
        const fromWho = doc.student?.department || doc.from || 'Unknown';
        const docType = this.formatDocType(doc.doc_type || doc.type);

        col.innerHTML = `
            <div class="card document-card h-100" onclick="documentSystem.openDocument(${doc.id})" tabindex="0" role="button" aria-label="Open ${this.escapeHtml(doc.title)}">
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
        cardEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.openDocument(doc.id);
            }
        });

        return col;
    }

    // Helpers for due date and progress (compatible with API + mock)
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
        const done = wf.filter(s => s.status === 'completed').length;
        return Math.round((done / wf.length) * 100);
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

        // PDF host
        const pdfContent = document.getElementById('pdfContent');
        if (pdfContent) {
            pdfContent.innerHTML = '';
            pdfContent.style.position = 'relative';
            if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                const obj = document.createElement('object');
                obj.type = 'application/pdf';
                obj.data = doc.file_path;
                obj.className = 'pdf-embed';
                obj.setAttribute('aria-label', 'PDF Preview');
                pdfContent.appendChild(obj);
            } else {
                const ph = document.createElement('div');
                ph.className = 'pdf-placeholder';
                ph.innerHTML = `<h4>${doc.title}</h4><p>${doc.description || ''}</p>`;
                pdfContent.appendChild(ph);
            }
            this.initClickToPlace(pdfContent); // Enable click-to-place
        }

        // Workflow compact list
        const workflowSteps = document.getElementById('workflowSteps');
        if (workflowSteps) {
            workflowSteps.innerHTML = (doc.workflow || []).map(step => `
                <div class="workflow-step-compact ${step.status}">
                    <div class="workflow-icon ${step.status}">
                        <i class="bi ${step.status === 'completed' ? 'bi-check2-circle' : step.status === 'rejected' ? 'bi-x-circle' : 'bi-hourglass-split'}"></i>
                    </div>
                    <div class="workflow-content">
                        <div class="step-name">${step.name}</div>
                        <div class="step-date">${step.assignee_name || 'Unassigned'}${step.acted_at ? ' • ' + new Date(step.acted_at).toLocaleString() : ''}</div>
                    </div>
                </div>`).join('');
        }

        // Signature target overlay
        this.renderSignatureOverlay(doc);

        // Rebind notes debounce
        const notesInput = document.getElementById('notesInput');
        if (notesInput) {
            notesInput.removeEventListener('input', this._notesHandler || (() => { }));
            this._notesHandler = this.debounce(() => this.saveNotes(), 500);
            notesInput.addEventListener('input', this._notesHandler);
        }
    }

    // Overlay signature target area based on signature_map (inline)
    renderSignatureOverlay(doc) {
        if (!doc || !doc.signature_map) return;
        const { x_pct, y_pct, w_pct, h_pct, label } = doc.signature_map;
        const content = document.getElementById('pdfContent');
        if (!content) return;

        content.style.position = 'relative';

        let box = content.querySelector('.signature-target');
        if (!box) {
            box = document.createElement('div');
            box.className = 'signature-target draggable';
            box.title = 'Drag to move, resize handle to adjust size';
            content.appendChild(box);
            this.makeDraggable(box, content);
            this.makeResizable(box, content);
            box.addEventListener('click', () => {
                const pad = document.getElementById('signaturePadContainer');
                if (pad && pad.classList.contains('d-none')) {
                    pad.classList.remove('d-none');
                    this.initSignaturePad();
                }
            });
        }

        const rect = content.getBoundingClientRect();
        const cw = rect.width || content.clientWidth || 800;
        const ch = content.clientHeight || 600;
        box.style.left = (cw * x_pct) + 'px';
        box.style.top = (ch * y_pct) + 'px';
        box.style.width = (cw * w_pct) + 'px';
        box.style.height = (ch * h_pct) + 'px';
        box.textContent = label || 'Sign here';

        if (this.signatureImage) this.updateSignatureOverlayImage();
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
            // Constrain to container bounds
            newX = Math.max(0, Math.min(newX, containerRect.width - elementRect.width));
            newY = Math.max(0, Math.min(newY, containerRect.height - elementRect.height));
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
        const handle = document.createElement('div');
        handle.className = 'resize-handle';
        element.appendChild(handle);

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
            let newWidth = startWidth + dx;
            let newHeight = startHeight + dy;
            const containerRect = container.getBoundingClientRect();
            // Constrain to container bounds
            newWidth = Math.max(50, Math.min(newWidth, containerRect.width - element.offsetLeft));
            newHeight = Math.max(30, Math.min(newHeight, containerRect.height - element.offsetTop));
            element.style.width = newWidth + 'px';
            element.style.height = newHeight + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                this.updateSignatureMap(element, container); // Save size
            }
        });
    }

    // Update signature map with current position/size (fix: relative to container)
    updateSignatureMap(element, container) {
        const rect = container.getBoundingClientRect();
        const cw = rect.width || container.clientWidth || 1;
        const ch = rect.height || container.clientHeight || 1;
        const elRect = element.getBoundingClientRect();
        const x_pct = (elRect.left - rect.left) / cw;
        const y_pct = (elRect.top - rect.top) / ch;
        const w_pct = elRect.width / cw;
        const h_pct = elRect.height / ch;
        this.currentSignatureMap = { x_pct, y_pct, w_pct, h_pct, label: 'Sign here' };
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
                box.addEventListener('click', () => {
                    const pad = document.getElementById('signaturePadContainer');
                    if (pad && pad.classList.contains('d-none')) {
                        pad.classList.remove('d-none');
                        this.initSignaturePad();
                    }
                });
            }
            box.style.left = x + 'px';
            box.style.top = y + 'px';
            box.style.width = '100px'; // Default size
            box.style.height = '50px';
            box.textContent = 'Sign here';
            this.updateSignatureMap(box, container);
        });
    }

    // Initialize inline signature pad
    initSignaturePad() {
        const container = document.getElementById('signaturePadContainer');
        const canvas = document.getElementById('signatureCanvas');
        if (!container || !canvas) return;

        const width = container.clientWidth ? Math.min(container.clientWidth - 16, 560) : 560;
        canvas.width = width;
        canvas.height = 200;
        const ctx = canvas.getContext('2d');
        // Remove white background fill to keep transparent

        this._initCanvasDrawing(canvas);

        const clearBtn = document.getElementById('sigClearBtn');
        const saveBtn = document.getElementById('sigSaveBtn');
        if (clearBtn) clearBtn.onclick = () => { ctx.clearRect(0, 0, canvas.width, canvas.height); };
        if (saveBtn) saveBtn.onclick = () => {
            this.signatureImage = canvas.toDataURL('image/png');
            this.updateSignatureOverlayImage();
            const placeholder = document.getElementById('signaturePlaceholder');
            const applied = document.getElementById('appliedSignature');
            if (placeholder) placeholder.style.display = 'none';
            if (applied) applied.style.display = 'flex';
            this.showToast({ type: 'success', title: 'Signature Saved', message: 'Signature ready to apply.' });
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
            const body = { action: 'sign', document_id: docId, step_id: stepId };
            if (this.signatureImage) body.signature_image = this.signatureImage;
            if (this.currentSignatureMap) body.signature_map = this.currentSignatureMap; // Include updated map

            const response = await fetch('../api/documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const result = await response.json();

            if (result.success) {
                this.showToast({
                    type: 'success',
                    title: 'Document Signed',
                    message: 'Document has been successfully signed and approved.'
                });

                // Refresh data and return to dashboard (no modal)
                await this.loadDocuments();
                this.renderDocuments();
                this.updateStatsDisplay();
                this.goBack();
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
                });

                // Refresh data and return to dashboard (no modal)
                await this.loadDocuments();
                this.renderDocuments();
                this.updateStatsDisplay();
                this.goBack();
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

    // Save notes (placeholder)
    saveNotes() {
        console.log('Saving notes...');
    }
}

// Initialize the document notification system
document.addEventListener('DOMContentLoaded', function () {
    window.documentSystem = new DocumentNotificationSystem();
    window.documentSystem.init();

    // Expose safe global wrapper for button onclick
    window.createMockDocument = function () {
        if (window.documentSystem && typeof window.documentSystem.createMockDocument === 'function') {
            return window.documentSystem.createMockDocument();
        }
        if (window.ToastManager) {
            window.ToastManager.info('Initializing… please try again.', 'Info');
        } else {
            alert('Initializing… please try again.');
        }
    };
});