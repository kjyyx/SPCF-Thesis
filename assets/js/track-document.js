/* Track Document JavaScript - Extracted from trackdocument.html */

class DocumentManagerV6 {
    constructor() {
        this.selectedDocuments = new Set();
        this.selectedEvents = new Set();
        this.officeNames = [
            'College Student Council',
            'Department Student Council', 
            'Dean\'s Office',
            'Supreme Student Council',
            'Student Affairs Office',
            'Finance Office',
            'Vice President Office',
            'President Office',
            'Accounting Office',
            'Final Approval'
        ];
        this.initializeEventListeners();
        this.calculateInitialProgress();
        this.addEnhancedAnimations();
    }

    calculateInitialProgress() {
        const eventFolders = document.querySelectorAll('.event-folder');
        eventFolders.forEach(folder => {
            this.updateEventProgress(folder);
        });
    }

    updateEventProgress(eventFolder) {
        const eventId = eventFolder.dataset.event;
        const documents = eventFolder.querySelectorAll('.document-item');
        const progressCircle = eventFolder.querySelector(`[data-event-progress="${eventId}"]`);
        const progressText = progressCircle.querySelector('.progress-text');
        
        let totalProgress = 0;
        let docCount = 0;
        
        documents.forEach(doc => {
            const status = doc.dataset.status;
            const office = parseInt(doc.dataset.office) || 0;
            
            if (status === 'completed') {
                totalProgress += 100;
            } else {
                totalProgress += (office / 10) * 100;
            }
            docCount++;
        });
        
        const avgProgress = docCount > 0 ? Math.round(totalProgress / docCount) : 0;
        const degrees = (avgProgress / 100) * 360;
        
        progressCircle.style.background = `conic-gradient(#10b981 0deg ${degrees}deg, #e5e7eb ${degrees}deg 360deg)`;
        progressText.textContent = `${avgProgress}%`;
    }

    calculateOfficeProgress(selectedDocs) {
        const officeProgress = new Array(10).fill(0);
        
        selectedDocs.forEach(docId => {
            const docElement = document.querySelector(`[data-doc="${docId}"]`).closest('.document-item');
            const status = docElement.dataset.status;
            const office = parseInt(docElement.dataset.office) || 0;
            
            if (status === 'completed') {
                for (let i = 0; i < 10; i++) {
                    officeProgress[i]++;
                }
            } else if (office > 0) {
                for (let i = 0; i < office; i++) {
                    officeProgress[i]++;
                }
            }
        });
        
        return officeProgress.map(count => Math.round((count / selectedDocs.size) * 100));
    }

    addEnhancedAnimations() {
        // Add stagger animation to document items
        const documentItems = document.querySelectorAll('.document-item');
        documentItems.forEach((item, index) => {
            item.style.animationDelay = `${index * 0.1}s`;
        });

        // Add hover effects to cards
        const cards = document.querySelectorAll('.system-card, .event-folder');
        cards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    }

    initializeEventListeners() {
        // Search functionality
        document.getElementById('search-input').addEventListener('input', (e) => this.handleSearch(e.target.value));
        
        // Filter buttons
        document.querySelectorAll('.filter-pill').forEach(btn => {
            btn.addEventListener('click', (e) => this.handleFilter(e.target.dataset.filter));
        });

        // Select all checkbox
        document.getElementById('select-all').addEventListener('change', (e) => this.handleSelectAll(e.target.checked));

        // Event checkboxes
        document.querySelectorAll('.event-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => this.handleEventSelection(e.target));
        });

        // Document checkboxes
        document.querySelectorAll('.document-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => this.handleDocumentSelection(e.target));
        });

        // Action buttons
        document.getElementById('track-selected-btn').addEventListener('click', () => this.trackSelectedDocuments());
        document.getElementById('bulk-download-btn').addEventListener('click', () => this.downloadSelected());
        document.getElementById('clear-selection').addEventListener('click', () => this.clearSelection());
        document.getElementById('back-to-management').addEventListener('click', () => this.backToManagement());

        // Tracker buttons
        document.getElementById('refresh-tracking-btn').addEventListener('click', () => this.refreshTracking());
        document.getElementById('export-tracking-btn').addEventListener('click', () => this.exportTrackingReport());

        // Action button effects
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.handleActionClick(e));
        });
    }

    handleActionClick(e) {
        e.stopPropagation();
        const btn = e.currentTarget;
        
        // Add click animation
        btn.style.transform = 'scale(0.9)';
        setTimeout(() => {
            btn.style.transform = 'scale(1)';
        }, 150);

        // Determine action type
        const action = btn.classList.contains('view') ? 'view' : 
                      btn.classList.contains('download') ? 'download' : 'track';
        
        this.showToast(`${action.charAt(0).toUpperCase() + action.slice(1)} action triggered`, 'info');
    }

    handleSearch(query) {
        const folders = document.querySelectorAll('.event-folder');
        folders.forEach(folder => {
            const eventName = folder.querySelector('.event-title').textContent.toLowerCase();
            const documents = folder.querySelectorAll('.document-item');
            let folderVisible = false;

            documents.forEach(doc => {
                const docName = doc.querySelector('.document-name').textContent.toLowerCase();
                const visible = docName.includes(query.toLowerCase()) || eventName.includes(query.toLowerCase());
                doc.style.display = visible ? 'flex' : 'none';
                if (visible) folderVisible = true;
            });

            folder.style.display = folderVisible || query === '' ? 'block' : 'none';
        });
    }

    handleFilter(filter) {
        // Update active filter button
        document.querySelectorAll('.filter-pill').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-filter="${filter}"]`).classList.add('active');

        // Filter documents
        const documents = document.querySelectorAll('.document-item');
        documents.forEach(doc => {
            const status = doc.dataset.status;
            const visible = filter === 'all' || status === filter;
            doc.style.display = visible ? 'flex' : 'none';
        });

        // Hide empty folders
        const folders = document.querySelectorAll('.event-folder');
        folders.forEach(folder => {
            const visibleDocs = folder.querySelectorAll('.document-item[style*="flex"], .document-item:not([style])');
            folder.style.display = visibleDocs.length > 0 ? 'block' : 'none';
        });
    }

    handleSelectAll(checked) {
        const allCheckboxes = document.querySelectorAll('.document-checkbox, .event-checkbox');
        allCheckboxes.forEach(checkbox => {
            checkbox.checked = checked;
            if (checkbox.classList.contains('document-checkbox')) {
                this.handleDocumentSelection(checkbox);
            } else {
                this.handleEventSelection(checkbox);
            }
        });
    }

    handleEventSelection(checkbox) {
        const eventId = checkbox.dataset.event;
        const eventDocuments = document.querySelectorAll(`[data-event="${eventId}"] .document-checkbox`);
        
        eventDocuments.forEach(docCheckbox => {
            docCheckbox.checked = checkbox.checked;
            this.handleDocumentSelection(docCheckbox);
        });
    }

    handleDocumentSelection(checkbox) {
        const docId = checkbox.dataset.doc;
        const eventId = checkbox.dataset.event;

        if (checkbox.checked) {
            this.selectedDocuments.add(docId);
            this.selectedEvents.add(eventId);
        } else {
            this.selectedDocuments.delete(docId);
            
            const eventDocs = document.querySelectorAll(`[data-event="${eventId}"] .document-checkbox:checked`);
            if (eventDocs.length === 0) {
                this.selectedEvents.delete(eventId);
            }
        }

        this.updateSelectionUI();
    }

    updateSelectionUI() {
        const selectedCount = this.selectedDocuments.size;
        const eventCount = this.selectedEvents.size;

        document.getElementById('selected-count').textContent = selectedCount;
        document.getElementById('selected-events').textContent = eventCount;
        
        const summary = document.getElementById('selection-summary');
        const trackBtn = document.getElementById('track-selected-btn');
        const downloadBtn = document.getElementById('bulk-download-btn');

        if (selectedCount > 0) {
            summary.classList.add('active');
            trackBtn.disabled = false;
            downloadBtn.disabled = false;
        } else {
            summary.classList.remove('active');
            trackBtn.disabled = true;
            downloadBtn.disabled = true;
        }
    }

    clearSelection() {
        this.selectedDocuments.clear();
        this.selectedEvents.clear();
        
        document.querySelectorAll('.document-checkbox, .event-checkbox, #select-all').forEach(checkbox => {
            checkbox.checked = false;
        });
        
        this.updateSelectionUI();
        this.showToast('Selection cleared', 'info');
    }

    trackSelectedDocuments() {
        if (this.selectedDocuments.size === 0) return;

        // Add loading state
        const trackBtn = document.getElementById('track-selected-btn');
        trackBtn.classList.add('loading');
        trackBtn.innerHTML = '<span class="spinner me-2"></span>Loading Tracker...';

        setTimeout(() => {
            document.getElementById('management-interface').style.display = 'none';
            document.getElementById('tracker-interface').style.display = 'block';
            this.updateTrackerInterface();
            
            trackBtn.classList.remove('loading');
            trackBtn.innerHTML = '<i class="bi bi-graph-up me-2"></i>Track Selected';
        }, 1000);
    }

    updateTrackerInterface() {
        const trackingCount = document.getElementById('tracking-count');
        const trackingList = document.getElementById('tracking-documents-list');
        const progressText = document.getElementById('tracker-progress-text');
        const progressDesc = document.getElementById('tracker-progress-desc');
        const officeGrid = document.getElementById('office-progress-grid');

        trackingCount.textContent = this.selectedDocuments.size;
        trackingList.innerHTML = '';

        let totalProgress = 0;
        let completedDocs = 0;
        
        this.selectedDocuments.forEach(docId => {
            const docElement = document.querySelector(`[data-doc="${docId}"]`).closest('.document-item');
            const docName = docElement.querySelector('.document-name').textContent;
            const status = docElement.dataset.status;
            const eventName = docElement.closest('.event-folder').querySelector('.event-title').textContent;
            const office = parseInt(docElement.dataset.office) || 0;
            const officeName = docElement.dataset.officeName || 'Processing';
            
            let docProgress = 0;
            if (status === 'completed') {
                docProgress = 100;
                completedDocs++;
            } else {
                docProgress = (office / 10) * 100;
            }
            totalProgress += docProgress;
            
            const trackingDoc = document.createElement('div');
            trackingDoc.className = 'document-item border rounded-3 mb-3';
            trackingDoc.innerHTML = `
                <div class="document-icon ${docName.includes('.pdf') ? 'pdf' : docName.includes('.doc') ? 'doc' : 'img'}">
                    <i class="bi bi-file-earmark-${docName.includes('.pdf') ? 'pdf' : docName.includes('.doc') ? 'word' : 'image'}"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="document-name">${docName}</div>
                    <div class="document-meta">
                        <span><i class="bi bi-folder me-1"></i>Event: ${eventName}</span>
                        <span><i class="bi bi-graph-up me-1"></i>Progress: ${office}/10 offices (${Math.round(docProgress)}%)</span>
                    </div>
                </div>
                <div class="status-badge ${status === 'completed' ? 'status-completed' : 'status-office'}">
                    ${status === 'completed' ? 'Completed' : 'At ' + officeName}
                </div>
            `;
            trackingList.appendChild(trackingDoc);
        });

        // Update overall progress circle
        const overallPercentage = this.selectedDocuments.size > 0 ? 
            Math.round(totalProgress / this.selectedDocuments.size) : 0;
        const degrees = (overallPercentage / 100) * 360;
        
        document.getElementById('tracker-progress-circle').style.background = 
            `conic-gradient(#10b981 0deg ${degrees}deg, #e5e7eb ${degrees}deg 360deg)`;
        progressText.textContent = `${overallPercentage}%`;
        progressDesc.textContent = `${completedDocs} of ${this.selectedDocuments.size} documents completed (${overallPercentage}% average progress)`;

        // Update office progress grid
        const officeProgressData = this.calculateOfficeProgress(Array.from(this.selectedDocuments));
        officeGrid.innerHTML = '';
        
        for (let i = 0; i < 10; i++) {
            const percentage = officeProgressData[i];
            const officeDegrees = (percentage / 100) * 360;
            
            const officeCard = document.createElement('div');
            officeCard.className = 'col-lg-3 col-md-4 col-sm-6';
            officeCard.innerHTML = `
                <div class="office-card">
                    <div class="progress-circle mx-auto mb-3" style="background: conic-gradient(#10b981 0deg ${officeDegrees}deg, #e5e7eb ${officeDegrees}deg 360deg);">
                        <div class="progress-text">${percentage}%</div>
                    </div>
                    <h6 class="fw-bold">Office ${i + 1}</h6>
                    <small class="text-muted">${this.officeNames[i]}</small>
                </div>
            `;
            officeGrid.appendChild(officeCard);
        }
    }

    backToManagement() {
        document.getElementById('tracker-interface').style.display = 'none';
        document.getElementById('management-interface').style.display = 'block';
    }

    downloadSelected() {
        const downloadBtn = document.getElementById('bulk-download-btn');
        downloadBtn.classList.add('loading');
        downloadBtn.innerHTML = '<span class="spinner me-2"></span>Downloading...';
        
        setTimeout(() => {
            this.showToast(`Successfully downloaded ${this.selectedDocuments.size} documents!`, 'success');
            downloadBtn.classList.remove('loading');
            downloadBtn.innerHTML = '<i class="bi bi-download me-2"></i>Download All';
        }, 2000);
    }

    refreshTracking() {
        const refreshBtn = document.getElementById('refresh-tracking-btn');
        refreshBtn.classList.add('loading');
        refreshBtn.innerHTML = '<span class="spinner me-2"></span>Refreshing...';
        
        setTimeout(() => {
            this.simulateProgressUpdates();
            this.calculateInitialProgress();
            this.updateTrackerInterface();
            
            this.showToast('Tracking status refreshed for all documents!', 'success');
            refreshBtn.classList.remove('loading');
            refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Refresh All Status';
        }, 2000);
    }

    simulateProgressUpdates() {
        const documents = document.querySelectorAll('.document-item[data-status="in-progress"], .document-item[data-status="pending"]');
        
        documents.forEach((doc, index) => {
            if (Math.random() > 0.7) {
                const currentOffice = parseInt(doc.dataset.office) || 1;
                const newOffice = Math.min(currentOffice + 1, 10);
                
                doc.dataset.office = newOffice;
                doc.dataset.officeName = this.officeNames[newOffice - 1];
                
                const statusBadge = doc.querySelector('.status-badge');
                const progressSpan = doc.querySelector('.document-meta span:last-child');
                
                if (newOffice === 10) {
                    doc.dataset.status = 'completed';
                    statusBadge.textContent = 'Completed';
                    statusBadge.className = 'status-badge status-completed';
                    progressSpan.innerHTML = '<i class="bi bi-check-circle me-1"></i>Completed (100%)';
                } else {
                    statusBadge.textContent = `At ${this.officeNames[newOffice - 1]}`;
                    const percentage = Math.round((newOffice / 10) * 100);
                    progressSpan.innerHTML = `<i class="bi bi-arrow-repeat me-1"></i>Progress: ${newOffice}/10 offices (${percentage}%)`;
                }
            }
        });
    }

    exportTrackingReport() {
        const exportBtn = document.getElementById('export-tracking-btn');
        exportBtn.classList.add('loading');
        exportBtn.innerHTML = '<span class="spinner me-2"></span>Generating...';
        
        setTimeout(() => {
            const reportData = `Document Tracking Report - Version 6.0\n` +
                             `Generated: ${new Date().toLocaleString()}\n` +
                             `Documents Tracked: ${this.selectedDocuments.size}\n` +
                             `Events: ${this.selectedEvents.size}\n\n` +
                             `Detailed Progress Report:\n` +
                             `========================\n`;
            
            const blob = new Blob([reportData], { type: 'text/plain' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'tracking-report-v6.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            this.showToast('Tracking report exported successfully!', 'success');
            exportBtn.classList.remove('loading');
            exportBtn.innerHTML = '<i class="bi bi-download me-2"></i>Export Report';
        }, 1500);
    }

    showToast(message, type = 'info') {
        // Create toast notification
        const toastContainer = document.getElementById('toast-container') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} alert-dismissible fade show`;
        toast.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        toastContainer.appendChild(toast);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }

    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 350px;';
        document.body.appendChild(container);
        return container;
    }
}

// Global functions
function toggleEventFolder(header) {
    const folder = header.closest('.event-folder');
    const documentsList = folder.querySelector('.documents-list');
    const expandBtn = folder.querySelector('.expand-btn i');
    
    documentsList.classList.toggle('expanded');
    
    if (documentsList.classList.contains('expanded')) {
        documentsList.style.display = 'block';
        expandBtn.style.transform = 'rotate(180deg)';
    } else {
        documentsList.style.display = 'none';
        expandBtn.style.transform = 'rotate(0deg)';
    }
}

// Initialize the enhanced document manager
const documentManager = new DocumentManagerV6();

// Add enhanced interactions
document.addEventListener('DOMContentLoaded', function() {
    // Add ripple effect to buttons
    document.querySelectorAll('.btn-enhanced').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.cssText = `
                position: absolute;
                width: ${size}px;
                height: ${size}px;
                left: ${x}px;
                top: ${y}px;
                background: rgba(255, 255, 255, 0.3);
                border-radius: 50%;
                transform: scale(0);
                animation: ripple 0.6s linear;
                pointer-events: none;
            `;
            
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
    });
});

// Add CSS animation for ripple effect
const style = document.createElement('style');
style.textContent = `
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
