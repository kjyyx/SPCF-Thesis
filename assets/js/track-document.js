// Modern Document Tracker JavaScript

// Pagination and data management
let currentPage = 1;
let itemsPerPage = 10;
let totalDocuments = 0;
let allDocuments = [];
let filteredDocuments = [];
let currentSortField = 'updated_at';
let currentSortDirection = 'desc';
let documentStats = { total: 0, pending: 0, approved: 0, inProgress: 0, underReview: 0 };

// Toast notification function
function showToast(message, type = 'info', title = null) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: type,
            title: title,
            message: message,
            duration: 4000
        });
    } else {
        // Fallback to console if ToastManager not available
        console.log(`[${type.toUpperCase()}] ${title ? title + ': ' : ''}${message}`);
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Set user display name
    if (window.currentUser && document.getElementById('userDisplayName')) {
        document.getElementById('userDisplayName').textContent = `${window.currentUser.firstName} ${window.currentUser.lastName}`;
    }

    // Initialize event listeners
    initializeEventListeners();
    
    // Load student documents
    loadStudentDocuments();

    // Add smooth animations to header
    animatePageLoad();
});

// Initialize all event listeners
function initializeEventListeners() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', handleSearch);
    }

    // Clear search button
    const clearSearch = document.getElementById('clearSearch');
    if (clearSearch) {
        clearSearch.addEventListener('click', () => {
            document.getElementById('searchInput').value = '';
            handleSearch();
        });
    }

    // Filter functionality with modern chips
    const filterButtons = document.querySelectorAll('input[name="statusFilter"]');
    filterButtons.forEach(button => {
        button.addEventListener('change', (e) => {
            // Update active chip visual state
            document.querySelectorAll('.filter-chip').forEach(chip => chip.classList.remove('active'));
            e.target.nextElementSibling.classList.add('active');
            handleFilter();
        });
    });

    // Items per page selector
    const itemsPerPageSelect = document.getElementById('itemsPerPage');
    if (itemsPerPageSelect) {
        itemsPerPageSelect.addEventListener('change', (e) => {
            itemsPerPage = parseInt(e.target.value);
            currentPage = 1;
            renderCurrentPage();
        });
    }

    // Table sorting
    const sortableHeaders = document.querySelectorAll('.sortable');
    sortableHeaders.forEach(header => {
        header.addEventListener('click', () => {
            const sortField = header.getAttribute('data-sort');
            handleSort(sortField);
        });
    });
}

// Enhanced page load animation
function animatePageLoad() {
    // Animate header elements
    const headerElements = document.querySelectorAll('.page-title, .page-subtitle, .stat-card');
    headerElements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(30px)';
        setTimeout(() => {
            element.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Animate controls
    const controls = document.querySelector('.tracker-controls');
    if (controls) {
        controls.style.opacity = '0';
        controls.style.transform = 'translateY(20px)';
        setTimeout(() => {
            controls.style.transition = 'all 0.6s ease';
            controls.style.opacity = '1';
            controls.style.transform = 'translateY(0)';
        }, 300);
    }
}

// Enhanced search functionality with debouncing
let searchTimeout;
function handleSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyCurrentFilters();
    }, 300);
}

// Enhanced filter functionality
function handleFilter() {
    applyCurrentFilters();
}

// Show loading state
function showLoadingState() {
    const loadingEl = document.getElementById('loadingState');
    const emptyEl = document.getElementById('emptyState');
    const tableEl = document.getElementById('documentsTable');
    const paginationEl = document.getElementById('paginationContainer');
    
    if (loadingEl) loadingEl.style.display = 'block';
    if (emptyEl) emptyEl.style.display = 'none';
    if (tableEl) tableEl.style.display = 'none';
    if (paginationEl) paginationEl.style.display = 'none';
}

// Hide loading state
function hideLoadingState() {
    const loadingEl = document.getElementById('loadingState');
    const tableEl = document.getElementById('documentsTable');
    
    if (loadingEl) loadingEl.style.display = 'none';
    if (tableEl) tableEl.style.display = 'table';
}

// Show empty state
function showEmptyState() {
    const loadingEl = document.getElementById('loadingState');
    const emptyEl = document.getElementById('emptyState');
    const tableEl = document.getElementById('documentsTable');
    const paginationEl = document.getElementById('paginationContainer');
    
    if (loadingEl) loadingEl.style.display = 'none';
    if (emptyEl) emptyEl.style.display = 'block';
    if (tableEl) tableEl.style.display = 'none';
    if (paginationEl) paginationEl.style.display = 'none';
}

// Load student documents from API with enhanced features
async function loadStudentDocuments() {
    showLoadingState();
    
    try {
        const response = await fetch('../api/documents.php?action=my_documents', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Failed to fetch documents');
        }

        const data = await response.json();
        
        if (data.success) {
            allDocuments = data.documents || [];
            calculateStatistics();
            updateStatisticsDisplay();
            applyCurrentFilters();
            hideLoadingState();
            
            if (allDocuments.length === 0) {
                showEmptyState();
            }
        } else {
            console.error('Error loading documents:', data.message);
            showToast(data.message || 'Error loading documents', 'error');
            showEmptyState();
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to load documents. Please try again.', 'error');
        showEmptyState();
    }
}

// Refresh documents function
function refreshDocuments() {
    showToast('Refreshing documents...', 'info');
    loadStudentDocuments();
}

// Calculate document statistics
function calculateStatistics() {
    documentStats = {
        total: allDocuments.length,
        pending: 0,
        approved: 0,
        inProgress: 0,
        underReview: 0
    };

    allDocuments.forEach(doc => {
        const status = doc.status.toLowerCase();
        if (status.includes('pending') || status.includes('submitted')) documentStats.pending++;
        else if (status.includes('completed') || status.includes('approved')) documentStats.approved++;
        else if (status.includes('progress') || status.includes('in_review')) documentStats.inProgress++;
        else if (status.includes('review')) documentStats.underReview++;
    });
}

// Update statistics display
function updateStatisticsDisplay() {
    const totalEl = document.getElementById('totalDocuments');
    const pendingEl = document.getElementById('pendingDocuments');
    const approvedEl = document.getElementById('approvedDocuments');
    const inProgressEl = document.getElementById('inProgressDocuments');
    
    if (totalEl) {
        animateNumber(totalEl, documentStats.total);
    }
    if (pendingEl) {
        animateNumber(pendingEl, documentStats.pending);
    }
    if (approvedEl) {
        animateNumber(approvedEl, documentStats.approved);
    }
    if (inProgressEl) {
        animateNumber(inProgressEl, documentStats.inProgress);
    }
}

// Animate number counting
function animateNumber(element, targetNumber) {
    const startNumber = parseInt(element.textContent) || 0;
    const duration = 1000;
    const steps = 20;
    const increment = (targetNumber - startNumber) / steps;
    let current = startNumber;
    let step = 0;
    
    const timer = setInterval(() => {
        step++;
        current += increment;
        element.textContent = Math.round(current);
        
        if (step >= steps) {
            element.textContent = targetNumber;
            clearInterval(timer);
        }
    }, duration / steps);
}

// Render current page of documents with pagination
function renderCurrentPage() {
    const tbody = document.getElementById('documentsList');
    const paginationContainer = document.getElementById('paginationContainer');
    
    tbody.innerHTML = '';
    
    if (filteredDocuments.length === 0) {
        paginationContainer.style.display = 'none';
        return;
    }
    
    // Calculate pagination
    const totalPages = Math.ceil(totalDocuments / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = Math.min(startIndex + itemsPerPage, totalDocuments);
    const currentPageDocuments = filteredDocuments.slice(startIndex, endIndex);
    
    // Render documents
    currentPageDocuments.forEach((doc, index) => {
        const row = document.createElement('tr');
        
        // Add animation classes
        row.className = 'fade-in';
        row.style.animationDelay = `${index * 0.05}s`;
        
        const statusBadge = getStatusBadgeClass(doc.status);
        const locationBadge = getLocationBadgeClass(doc.current_location);
        
        row.innerHTML = `
            <td>
                <div class="d-flex align-items-center">
                    <div class="document-icon me-2">
                        <i class="bi bi-file-earmark-text text-primary"></i>
                    </div>
                    <div class="document-info">
                        <div class="document-name fw-semibold">${doc.document_name}</div>
                        <div class="document-type text-muted small">${doc.doc_type || 'Document'}</div>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge ${statusBadge}">${doc.status}</span>
            </td>
            <td>
                <span class="badge ${locationBadge}">${doc.current_location}</span>
            </td>
            <td>
                <div class="date-info">
                    <div class="date-primary">${new Date(doc.updated_at).toLocaleDateString()}</div>
                    <div class="date-secondary text-muted small">${new Date(doc.updated_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                </div>
            </td>
            <td>
                <div class="notes-preview">${getNotesPreview(doc.notes || [])}</div>
            </td>
            <td>
                <button class="modern-action-btn secondary" onclick="viewDetails('${doc.id}')" title="View document details">
                    <i class="bi bi-eye"></i>
                    <span>View</span>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
    });
    
    // Update pagination
    renderPagination(totalPages);
    updatePaginationInfo(startIndex + 1, endIndex, totalDocuments);
    
    // Show pagination if needed
    paginationContainer.style.display = totalPages > 1 ? 'flex' : 'none';
}

// Render pagination controls
function renderPagination(totalPages) {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    
    if (totalPages <= 1) return;
    
    // Previous button
    const prevItem = document.createElement('li');
    prevItem.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
    prevItem.innerHTML = `
        <a class="page-link" href="#" onclick="changePage(${currentPage - 1})" aria-label="Previous">
            <i class="bi bi-chevron-left"></i>
        </a>
    `;
    pagination.appendChild(prevItem);
    
    // Page numbers with smart truncation
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    // First page and ellipsis
    if (startPage > 1) {
        addPageItem(1);
        if (startPage > 2) {
            const ellipsis = document.createElement('li');
            ellipsis.className = 'page-item disabled';
            ellipsis.innerHTML = '<span class="page-link">...</span>';
            pagination.appendChild(ellipsis);
        }
    }
    
    // Visible pages
    for (let i = startPage; i <= endPage; i++) {
        addPageItem(i);
    }
    
    // Last page and ellipsis
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('li');
            ellipsis.className = 'page-item disabled';
            ellipsis.innerHTML = '<span class="page-link">...</span>';
            pagination.appendChild(ellipsis);
        }
        addPageItem(totalPages);
    }
    
    // Next button
    const nextItem = document.createElement('li');
    nextItem.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
    nextItem.innerHTML = `
        <a class="page-link" href="#" onclick="changePage(${currentPage + 1})" aria-label="Next">
            <i class="bi bi-chevron-right"></i>
        </a>
    `;
    pagination.appendChild(nextItem);
    
    function addPageItem(pageNum) {
        const pageItem = document.createElement('li');
        pageItem.className = `page-item ${pageNum === currentPage ? 'active' : ''}`;
        pageItem.innerHTML = `
            <a class="page-link" href="#" onclick="changePage(${pageNum})">${pageNum}</a>
        `;
        pagination.appendChild(pageItem);
    }
}

// Change page function
function changePage(page) {
    const totalPages = Math.ceil(totalDocuments / itemsPerPage);
    if (page < 1 || page > totalPages) return;
    
    currentPage = page;
    renderCurrentPage();
    
    // Smooth scroll to top of table
    document.querySelector('.tracker-table-container').scrollIntoView({ 
        behavior: 'smooth', 
        block: 'start' 
    });
}

// Update pagination info
function updatePaginationInfo(start, end, total) {
    const paginationInfo = document.getElementById('paginationInfo');
    if (paginationInfo) {
        paginationInfo.textContent = `Showing ${start}-${end} of ${total} documents`;
    }
}

// Handle table sorting
function handleSort(field) {
    const header = document.querySelector(`[data-sort="${field}"]`);
    const allHeaders = document.querySelectorAll('.sortable');
    
    // Remove sort classes from other headers
    allHeaders.forEach(h => {
        if (h !== header) {
            h.classList.remove('asc', 'desc');
        }
    });
    
    // Toggle sort direction
    if (currentSortField === field) {
        currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        currentSortField = field;
        currentSortDirection = 'asc';
    }
    
    // Update header classes
    header.classList.remove('asc', 'desc');
    header.classList.add(currentSortDirection);
    
    // Sort and render
    sortDocuments();
    renderCurrentPage();
}

// Sort documents based on current field and direction
function sortDocuments() {
    filteredDocuments.sort((a, b) => {
        let aValue = a[currentSortField];
        let bValue = b[currentSortField];
        
        // Handle date fields
        if (currentSortField.includes('_at') || currentSortField.includes('date')) {
            aValue = new Date(aValue);
            bValue = new Date(bValue);
        }
        
        // Handle string comparisons
        if (typeof aValue === 'string') {
            aValue = aValue.toLowerCase();
            bValue = bValue.toLowerCase();
        }
        
        if (aValue < bValue) return currentSortDirection === 'asc' ? -1 : 1;
        if (aValue > bValue) return currentSortDirection === 'asc' ? 1 : -1;
        return 0;
    });
}

// Apply current filters and search
function applyCurrentFilters() {
    let documents = [...allDocuments];
    
    // Apply search filter
    const searchTerm = document.getElementById('searchInput')?.value.toLowerCase().trim();
    if (searchTerm) {
        documents = documents.filter(doc => 
            doc.document_name.toLowerCase().includes(searchTerm) ||
            doc.status.toLowerCase().includes(searchTerm) ||
            doc.current_location.toLowerCase().includes(searchTerm) ||
            (doc.description && doc.description.toLowerCase().includes(searchTerm))
        );
    }
    
    // Apply status filter
    const activeFilter = document.querySelector('input[name="statusFilter"]:checked')?.id;
    if (activeFilter && activeFilter !== 'filterAll') {
        const statusMap = {
            'filterPending': 'pending',
            'filterProgress': 'progress',
            'filterReview': 'review',
            'filterCompleted': 'completed'
        };
        const filterStatus = statusMap[activeFilter];
        if (filterStatus) {
            documents = documents.filter(doc => 
                doc.status.toLowerCase().includes(filterStatus)
            );
        }
    }
    
    filteredDocuments = documents;
    totalDocuments = filteredDocuments.length;
    
    // Reset to first page when filters change
    currentPage = 1;
    
    // Sort documents
    sortDocuments();
    
    // Update results count
    updateResultsCount();
    
    // Render current page
    renderCurrentPage();
}

// Update results count display
function updateResultsCount() {
    const resultsEl = document.getElementById('resultsCount');
    if (resultsEl) {
        if (totalDocuments === 0) {
            resultsEl.textContent = 'No documents found';
        } else if (totalDocuments === allDocuments.length) {
            resultsEl.textContent = `Showing ${totalDocuments} document${totalDocuments === 1 ? '' : 's'}`;
        } else {
            resultsEl.textContent = `Showing ${totalDocuments} of ${allDocuments.length} documents`;
        }
    }
}

// Clear all filters function
function clearFilters() {
    // Reset search
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = '';
    }
    
    // Reset filter to "All"
    const allFilter = document.getElementById('filterAll');
    if (allFilter) {
        allFilter.checked = true;
        // Update visual state
        document.querySelectorAll('.filter-chip').forEach(chip => chip.classList.remove('active'));
        allFilter.nextElementSibling.classList.add('active');
    }
    
    // Reapply filters
    applyCurrentFilters();
    
    showToast('Filters cleared', 'success');
}

// Get notes preview for table display
function getNotesPreview(notes) {
    if (!notes || notes.length === 0) {
        return '<span class="text-muted">No notes</span>';
    }
    
    // Check if there's a rejection note
    const rejectionNote = notes.find(note => note.is_rejection);
    const recentNote = rejectionNote || notes[notes.length - 1];
    
    const preview = recentNote.note.length > 50 ? recentNote.note.substring(0, 50) + '...' : recentNote.note;
    const title = recentNote.note.replace(/"/g, '&quot;');
    
    if (rejectionNote) {
        return `<span class="text-danger" title="${title}">⚠️ ${preview}</span>`;
    }
    
    return `<span title="${title}">${preview}</span>`;
}

// View document details with enhanced modal
async function viewDetails(docId) {
    const modal = new bootstrap.Modal(document.getElementById('documentModal'));
    const modalTitle = document.getElementById('documentModalTitle');
    const modalBody = document.getElementById('documentModalBody');

    try {
        // Fetch document details from API
        const response = await fetch(`../api/documents.php?action=document_details&id=${docId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Failed to fetch document details');
        }

        const data = await response.json();

        if (data.success && data.document) {
            const doc = data.document;
            modalTitle.textContent = doc.document_name;

            let statusHtml = `<div class="mb-3">
                <strong>Current Status:</strong>
                <span class="badge ${getStatusBadgeClass(doc.status)} ms-2">${doc.status}</span>
            </div>`;

            let locationHtml = `<div class="mb-3">
                <strong>Current Location:</strong>
                <span class="badge bg-primary">${doc.current_location}</span>
            </div>`;

            let submittedHtml = `<div class="mb-3">
                <strong>Submitted:</strong> ${new Date(doc.created_at).toLocaleDateString()}
            </div>`;

            let descriptionHtml = `<div class="mb-3">
                <strong>Description:</strong> ${doc.description || 'No description available'}
            </div>`;

            // Notes section - Separate rejection reason from comments
            let rejectionReasonHtml = '';
            let notesHtml = '<div class="mb-3"><h6>Notes/Comments:</h6>';
            if (doc.notes && doc.notes.length > 0) {
                const rejectionNote = doc.notes.find(note => note.is_rejection);
                const otherNotes = doc.notes.filter(note => !note.is_rejection);

                // Rejection Reason section
                if (rejectionNote) {
                    rejectionReasonHtml = `
                        <div class="mb-3">
                            <h6 class="text-danger"><i class="bi bi-exclamation-triangle"></i> Rejection Reason:</h6>
                            <div class="alert alert-danger">
                                <strong>${rejectionNote.created_by_name}</strong> - ${new Date(rejectionNote.created_at).toLocaleString()}<br>
                                ${rejectionNote.note}
                            </div>
                        </div>
                    `;
                }

                // Notes/Comments section
                if (otherNotes.length > 0) {
                    notesHtml += '<div class="notes-list">';
                    otherNotes.forEach(note => {
                        notesHtml += `
                            <div class="note-item mb-2 p-2 border rounded">
                                <div class="note-meta text-muted small d-flex justify-content-between align-items-center">
                                    <span><strong>${note.created_by_name}</strong> - ${new Date(note.created_at).toLocaleString()}</span>
                                </div>
                                <div class="note-content">${note.note}</div>
                            </div>
                        `;
                    });
                    notesHtml += '</div>';
                } else {
                    notesHtml += '<p class="text-muted">No additional notes</p>';
                }
            } else {
                notesHtml += '<p class="text-muted">No notes available</p>';
            }
            notesHtml += '</div>';

            // History/Timeline section
            let historyHtml = '<div class="mb-3"><h6>Document History:</h6><div class="timeline">';
            if (doc.workflow_history && doc.workflow_history.length > 0) {
                doc.workflow_history.forEach((item, index) => {
                    historyHtml += `
                        <div class="timeline-item">
                            <div class="timeline-marker ${getStatusColorClass(item.status)}"></div>
                            <div class="timeline-content">
                                <div class="timeline-date">${new Date(item.created_at).toLocaleDateString()}</div>
                                <div class="timeline-action">${item.action} - ${item.office_name || item.from_office}</div>
                            </div>
                        </div>
                    `;
                });
            } else {
                historyHtml += '<p class="text-muted">No history available</p>';
            }
            historyHtml += '</div></div>';

            modalBody.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="glass-card mb-3">
                            ${statusHtml}
                            ${locationHtml}
                            ${submittedHtml}
                        </div>
                        ${rejectionReasonHtml}
                        ${notesHtml}
                    </div>
                    <div class="col-md-6">
                        <div class="glass-card mb-3">
                            ${descriptionHtml}
                        </div>
                        ${historyHtml}
                    </div>
                </div>
            `;
        } else {
            modalTitle.textContent = 'Error';
            modalBody.innerHTML = '<p class="text-danger">Failed to load document details</p>';
        }
    } catch (error) {
        console.error('Error fetching document details:', error);
        modalTitle.textContent = 'Error';
        modalBody.innerHTML = '<p class="text-danger">Failed to load document details</p>';
    }

    modal.show();
}

// Helper function to get status badge class
function getStatusBadgeClass(status) {
    switch (status) {
        case 'Pending': return 'bg-secondary';
        case 'In Progress': return 'bg-warning';
        case 'Under Review': return 'bg-info';
        case 'Completed': return 'bg-success';
        case 'Approved': return 'bg-success';
        case 'Rejected': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

// Helper function to get location badge class
function getLocationBadgeClass(location) {
    // You can customize these based on your office types
    switch (location) {
        case 'Finance Office': return 'bg-info';
        case 'Student Affairs': return 'bg-success';
        case 'Student Council': return 'bg-warning';
        case 'Dean\'s Office': return 'bg-primary';
        case 'Approved': return 'bg-success';
        default: return 'bg-secondary';
    }
}

// Helper function to get status color class for timeline
function getStatusColorClass(status) {
    switch (status) {
        case 'pending': return 'bg-secondary';
        case 'inprogress': return 'bg-warning';
        case 'review': return 'bg-info';
        case 'completed': return 'bg-success';
        case 'approved': return 'bg-success';
        case 'rejected': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

// Timeline styles are now handled in CSS file
// Enhanced functionality complete