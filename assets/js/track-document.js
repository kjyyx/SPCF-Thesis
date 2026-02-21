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

// Standardized placeholder for all documents
function getDocumentPlaceholder(docType) {
    return '[Name / Position]';
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

// Function to shorten office/step names for display
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

// Function to format status for display
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
document.addEventListener('DOMContentLoaded', function () {
    // Set user display name
    if (window.currentUser && document.getElementById('userDisplayName')) {
        document.getElementById('userDisplayName').textContent = `${window.currentUser.firstName} ${window.currentUser.lastName}`;
    }

    // Initialize event listeners
    initializeEventListeners();

    // Load student documents
    loadStudentDocuments();

    // Log page view
    if (window.addAuditLog) {
        window.addAuditLog('TRACK_DOCUMENT_VIEWED', 'Document Management', 'Viewed document tracking page', null, 'Page', 'INFO');
    }

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

    // Filter functionality with admin-dashboard button groups
    const filterButtons = document.querySelectorAll('input[name="statusFilter"]');
    filterButtons.forEach(button => {
        button.addEventListener('change', function () {
            if (this.checked) {
                handleFilter();
            }
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
        const response = await fetch(BASE_URL + 'api/documents.php?action=my_documents&t=' + Date.now(), {
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
            
            // Set initial sort indicator
            const initialSortHeader = document.querySelector(`[data-sort="${currentSortField}"]`);
            if (initialSortHeader) {
                initialSortHeader.classList.add(currentSortDirection);
            }
            
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
        console.error('Error loading documents:', error);
        hideLoadingState();

        // Show error state
        const tbody = document.getElementById('documentsList');
        const emptyState = document.getElementById('emptyState');

        if (tbody && emptyState) {
            tbody.innerHTML = '';
            emptyState.style.display = 'block';
            emptyState.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem; opacity: 0.5;"></i>
                    <h4 class="text-dark mt-3">Error Loading Documents</h4>
                    <p class="text-muted mb-4">There was a problem loading your documents. Please try again.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button class="btn btn-primary" onclick="loadStudentDocuments()">
                            <i class="bi bi-arrow-clockwise me-2"></i>Try Again
                        </button>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="bi bi-bootstrap-reboot me-2"></i>Refresh Page
                        </button>
                    </div>
                </div>
            `;
        }

        showToast('Failed to load documents. Please try again.', 'error');
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
        totalEl.textContent = documentStats.total;
    }
    if (pendingEl) {
        pendingEl.textContent = documentStats.pending;
    }
    if (approvedEl) {
        approvedEl.textContent = documentStats.approved;
    }
    if (inProgressEl) {
        inProgressEl.textContent = documentStats.inProgress;
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
    const emptyState = document.getElementById('emptyState');

    tbody.innerHTML = '';

    if (filteredDocuments.length === 0) {
        paginationContainer.style.display = 'none';

        // Show appropriate empty state message
        if (allDocuments.length === 0) {
            // No documents at all
            showEmptyState();
        } else {
            // No results from search/filter
            if (emptyState) {
                emptyState.style.display = 'block';
                emptyState.innerHTML = `
                    <div class="text-center py-5">
                        <div class="empty-state-icon mb-4">
                            <i class="bi bi-search" style="font-size: 4rem; color: #cbd5e1;"></i>
                        </div>
                        <h4 class="text-dark mb-2">No matching documents found</h4>
                        <p class="text-muted mb-4">Try adjusting your search terms or filters to find what you're looking for.</p>
                        <button class="btn btn-primary" onclick="clearFilters()">
                            <i class="bi bi-x-circle me-2"></i>Clear Filters
                        </button>
                    </div>
                `;
            }
        }
        return;
    }

    // Hide empty state if showing results
    if (emptyState) {
        emptyState.style.display = 'none';
    }

    // Calculate pagination
    const totalPages = Math.ceil(totalDocuments / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = Math.min(startIndex + itemsPerPage, totalDocuments);
    const currentPageDocuments = filteredDocuments.slice(startIndex, endIndex);

    // Render documents with admin-dashboard styling
    currentPageDocuments.forEach((doc, index) => {
        const row = document.createElement('tr');

        // Add hover effects and animations
        row.className = 'table-row-hover';
        row.style.animationDelay = `${index * 0.05}s`;

        const statusBadge = getStatusBadgeClass(doc.status || doc.current_status);
        const locationBadge = getLocationBadgeClass(doc.current_location);
        const docTypeBadge = getDocTypeBadgeClass(doc.doc_type);
        const docType = getDocumentTypeDisplay(doc.document_type || doc.doc_type);
        const createdDate = doc.created_at ? new Date(doc.created_at).toLocaleDateString() : 'N/A';
        const safeDocType = safeText(docType);

        row.innerHTML = `
            <td>
                <div class="d-flex align-items-center">
                    <div class="document-icon me-3">
                        <i class="bi bi-file-earmark-text fs-4 text-primary"></i>
                    </div>
                    <div class="document-info">
                        <div class="document-name fw-bold text-dark mb-1">${safeText(doc.title || doc.document_name)}</div>
                        <div class="document-meta-row">
                            <span class="meta-item">
                                <i class="bi bi-folder2"></i>
                                ${safeDocType}
                            </span>
                            <span class="meta-separator">•</span>
                            <span class="meta-item">
                                <i class="bi bi-calendar3"></i>
                                Created: ${createdDate}
                            </span>
                        </div>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge ${docTypeBadge}">${safeDocType}</span>
            </td>
            <td>
                <span class="badge ${statusBadge}">${formatStatusDisplay(doc.status || doc.current_status)}</span>
            </td>
            <td>
                <span class="badge ${locationBadge}">${shortenOfficeName(doc.current_location)}</span>
            </td>
            <td>
                <div class="date-info">
                    <div class="fw-semibold">${new Date(doc.updated_at).toLocaleDateString()}</div>
                    <div class="text-muted small">${new Date(doc.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            </td>
            <td>
                <div class="notes-preview" style="max-width: 200px;">${getNotesPreview(doc.notes || [])}</div>
            </td>
            <td>
                <div class="btn-group" role="group">
                    <button class="btn btn-outline-primary btn-sm" onclick="viewDetails('${doc.id}')" title="View Details">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="downloadDocument('${doc.id}')" title="Download">
                        <i class="bi bi-download"></i>
                    </button>
                </div>
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
    const tableContainer = document.querySelector('.table-container') || document.querySelector('.content-body');
    if (tableContainer) {
        tableContainer.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
}

// Download document function
// Download document function (ensure it downloads signed/rejected PDF)
function downloadDocument(docId) {
    showToast('Preparing download...', 'info');

    // Use API to get the latest file_path (handles signed PDFs)
    fetch(BASE_URL + `api/documents.php?action=document_details&id=${docId}`, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.document && data.document.file_path) {
                // Allow download for all statuses
                const status = (data.document.status || '').toLowerCase();
                // Optionally restrict to issuer: if issuer id field exists, check it
                const currentUser = window.currentUser || null;
                const issuerId = data.document.student_id || data.document.issuer_id || data.document.user_id || null;
                if (!issuerId || !currentUser || currentUser.id == issuerId || currentUser.role === 'admin') {
                    let filePath = data.document.file_path;
                    if (filePath.startsWith('/')) {
                        filePath = BASE_URL + filePath.substring(1);
                    } else if (filePath.startsWith('../')) {
                        filePath = BASE_URL + filePath.substring(3);
                    } else if (filePath.startsWith('SPCF-Thesis/')) {
                        filePath = BASE_URL + filePath.substring(12);
                    } else if (filePath.startsWith('http')) {
                        // Full URL
                    } else {
                        filePath = BASE_URL + 'uploads/' + filePath;
                    }
                    const link = document.createElement('a');
                    link.href = filePath;
                    link.download = (data.document.document_name || data.document.title || 'Document') + '.pdf';
                    link.target = '_blank';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    showToast('Download started', 'success');
                } else {
                    showToast('Only the issuer or admin can download the document.', 'warning');
                }
            } else {
                throw new Error('File not found');
            }
        })
        .catch(error => {
            console.error('Download error:', error);
            showToast('Failed to download document', 'error');
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

        // Handle field fallbacks
        if (currentSortField === 'title') {
            aValue = aValue || a.document_name;
            bValue = bValue || b.document_name;
        } else if (currentSortField === 'document_type') {
            aValue = aValue || a.doc_type;
            bValue = bValue || b.doc_type;
        } else if (currentSortField === 'current_status') {
            aValue = aValue || a.status;
            bValue = bValue || b.status;
        }

        // Handle null/undefined values
        if (aValue == null) aValue = '';
        if (bValue == null) bValue = '';

        // Handle date fields
        if (currentSortField.includes('_at') || currentSortField.includes('date')) {
            aValue = new Date(aValue);
            bValue = new Date(bValue);
            // Handle invalid dates
            if (isNaN(aValue.getTime())) aValue = new Date(0);
            if (isNaN(bValue.getTime())) bValue = new Date(0);
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

    // Apply enhanced search filter
    const searchTerm = document.getElementById('searchInput')?.value.toLowerCase().trim();
    if (searchTerm) {
        documents = documents.filter(doc => {
            const searchFields = [
                doc.title || doc.document_name || '',
                doc.status || doc.current_status || '',
                doc.current_location || '',
                doc.document_type || doc.doc_type || '',
                doc.description || '',
                doc.created_by_name || ''
            ];

            return searchFields.some(field =>
                field.toString().toLowerCase().includes(searchTerm)
            );
        });
    }

    // Apply status filter with improved mapping
    const activeFilter = document.querySelector('input[name="statusFilter"]:checked')?.id;
    if (activeFilter && activeFilter !== 'filterAll') {
        const statusMap = {
            'filterPending': ['submitted', 'pending', 'in progress', 'in_progress', 'under review', 'under_review'],
            'filterApproved': ['approved', 'completed'],
            'filterRejected': ['rejected']
        };
        const filterStatuses = statusMap[activeFilter];
        if (filterStatuses) {
            documents = documents.filter(doc => {
                const docStatus = (doc.status || doc.current_status || '').toLowerCase();
                return filterStatuses.some(status => docStatus === status.toLowerCase());
            });
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

// Update results count display with enhanced messaging
function updateResultsCount() {
    const resultsEl = document.getElementById('resultsCount');
    const lastUpdatedEl = document.getElementById('lastUpdatedTime');

    if (resultsEl) {
        if (totalDocuments === 0) {
            resultsEl.innerHTML = '<i class="bi bi-exclamation-triangle me-1 text-warning"></i>No documents found';
        } else if (totalDocuments === allDocuments.length) {
            resultsEl.innerHTML = `<i class="bi bi-check-circle me-1 text-success"></i>Showing all ${totalDocuments} document${totalDocuments === 1 ? '' : 's'}`;
        } else {
            resultsEl.innerHTML = `<i class="bi bi-filter me-1 text-info"></i>Showing ${totalDocuments} of ${allDocuments.length} documents`;
        }
    }

    // Update last updated time
    if (lastUpdatedEl) {
        const now = new Date();
        lastUpdatedEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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

    // Reset sorting to default (updated_at descending)
    currentSortField = 'updated_at';
    currentSortDirection = 'desc';

    // Reset sort indicators
    const allHeaders = document.querySelectorAll('.sortable');
    allHeaders.forEach(header => {
        header.classList.remove('asc', 'desc');
    });
    const defaultSortHeader = document.querySelector('[data-sort="updated_at"]');
    if (defaultSortHeader) {
        defaultSortHeader.classList.add('desc');
    }

    // Reapply filters
    applyCurrentFilters();

    showToast('Filters and sorting cleared', 'success');
}

// Get notes preview for table display
function getNotesPreview(notes) {
    if (!notes || notes.length === 0) {
        return '<span class="text-muted">No notes</span>';
    }

    // Check if there's a rejection note
    const rejectionNote = notes.find(note => note.is_rejection);
    // Check for system messages like auto-timeout
    const systemNote = notes.find(note => note.note.includes('Auto-timeout'));
    const recentNote = rejectionNote || systemNote || notes[notes.length - 1];

    const preview = recentNote.note.length > 50 ? recentNote.note.substring(0, 50) + '...' : recentNote.note;
    const title = recentNote.note.replace(/"/g, '&quot;');

    if (rejectionNote) {
        return `<span class="text-danger" title="${title}">⚠️ ${preview}</span>`;
    } else if (systemNote) {
        return `<span class="text-warning" title="${title}">⏰ ${preview}</span>`;
    }

    return `<span title="${title}">${preview}</span>`;
}

function formatConversationDate(value) {
    if (!value) return '';
    const dt = new Date(value);
    if (Number.isNaN(dt.getTime())) return '';
    return dt.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function clearReplyTarget() {
    currentReplyTargetId = null;
    const banner = document.getElementById('conversationReplyBanner');
    const author = document.getElementById('conversationReplyAuthor');
    if (author) author.textContent = '';
    if (banner) banner.style.display = 'none';
}

function setReplyTarget(commentId, authorName) {
    currentReplyTargetId = Number(commentId);
    const banner = document.getElementById('conversationReplyBanner');
    const author = document.getElementById('conversationReplyAuthor');
    if (author) author.textContent = authorName || 'comment';
    if (banner) banner.style.display = 'flex';

    const input = document.getElementById('conversationInput');
    if (input) input.focus();
}

function renderConversationThread(comments) {
    const thread = document.getElementById('conversationThread');
    if (!thread) return;

    if (!Array.isArray(comments) || comments.length === 0) {
        thread.innerHTML = '<div class="conversation-empty">No conversation yet. Start by posting a reply.</div>';
        return;
    }

    const byParent = comments.reduce((acc, item) => {
        const key = item.parent_id === null || item.parent_id === undefined ? 'root' : String(item.parent_id);
        if (!acc[key]) acc[key] = [];
        acc[key].push(item);
        return acc;
    }, {});

    const renderNodes = (parentKey, depth = 0) => {
        const nodes = byParent[parentKey] || [];
        return nodes.map((item) => {
            const author = safeText(item.author_name || 'Unknown', 'Unknown');
            const role = safeText(item.author_role || '', '');
            const position = safeText(item.author_position || '', '');
            const content = safeText(item.comment || '', '').replace(/\n/g, '<br>');
            const createdAt = formatConversationDate(item.created_at);
            const safeDepth = Math.min(depth, 4);

            return `
                <div class="conversation-item depth-${safeDepth}">
                    <div class="conversation-meta">
                        <strong>${author}</strong>
                        <span>${role}${position ? ` • ${position}` : ''}</span>
                    </div>
                    <div class="conversation-content">${content}</div>
                    <div class="conversation-footer">
                        <small>${safeText(createdAt, '')}</small>
                        <button type="button" class="btn btn-link btn-sm conversation-reply-btn" data-comment-id="${item.id}" data-author-name="${author}">Reply</button>
                    </div>
                    ${renderNodes(String(item.id), depth + 1)}
                </div>
            `;
        }).join('');
    };

    thread.innerHTML = renderNodes('root');

    thread.querySelectorAll('.conversation-reply-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const commentId = btn.getAttribute('data-comment-id');
            const authorName = btn.getAttribute('data-author-name');
            setReplyTarget(commentId, authorName);
        });
    });
}

async function loadConversationThread(docId) {
    const thread = document.getElementById('conversationThread');
    if (thread) {
        thread.innerHTML = '<div class="conversation-empty">Loading conversation...</div>';
    }

    try {
        const response = await fetch(BASE_URL + `api/documents.php?action=get_comments&id=${docId}`, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to load conversation');
        }

        renderConversationThread(data.comments || []);
    } catch (error) {
        console.error('Error loading conversation thread:', error);
        if (thread) {
            thread.innerHTML = '<div class="conversation-empty">Unable to load conversation right now.</div>';
        }
    }
}

async function postConversationReply() {
    if (!currentConversationDocumentId) {
        showToast('No document selected for replying', 'error');
        return;
    }

    const input = document.getElementById('conversationInput');
    const sendBtn = document.getElementById('sendConversationBtn');
    if (!input || !sendBtn) return;

    const comment = input.value.trim();
    if (!comment) {
        showToast('Please enter a reply first', 'warning');
        return;
    }

    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Posting...';

    try {
        const response = await fetch(BASE_URL + 'api/documents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_comment',
                document_id: currentConversationDocumentId,
                comment: comment,
                parent_id: currentReplyTargetId
            })
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to post reply');
        }

        input.value = '';
        clearReplyTarget();
        await loadConversationThread(currentConversationDocumentId);
        showToast('Reply posted successfully', 'success');
    } catch (error) {
        console.error('Error posting conversation reply:', error);
        showToast(error.message || 'Unable to post reply', 'error');
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<i class="bi bi-send me-1"></i>Reply';
    }
}

// View document details with enhanced modal
// View document details with enhanced modal (add PDF viewing)
async function viewDetails(docId) {
    const modal = new bootstrap.Modal(document.getElementById('documentModal'));
    const modalTitle = document.getElementById('documentModalTitle');
    const modalBody = document.getElementById('documentModalBody');

    try {
        const response = await fetch(BASE_URL + `api/documents.php?action=document_details&id=${docId}`, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        });

        if (!response.ok) {
            if (response.status === 404) {
                throw new Error('Document not found');
            } else if (response.status === 403) {
                throw new Error('Access denied to this document');
            } else {
                throw new Error(`Server error: ${response.status}`);
            }
        }

        const data = await response.json();

        if (data.success && data.document) {
            const doc = data.document;
            const docType = getDocumentTypeDisplay(doc.document_type || doc.doc_type || doc.type || 'unknown');
            console.log('Document loaded:', doc);
            console.log('Workflow history:', doc.workflow_history);
            
            // Store complete document for signature rendering
            window.currentDocument = doc;
            
            modalTitle.innerHTML = `<i class="bi bi-file-earmark-text"></i><span>${safeText(doc.document_name || doc.title)}</span>`;

            const modalMeta = document.getElementById('documentModalMeta');
            if (modalMeta) {
                modalMeta.innerHTML = `
                    <span class="meta-item">
                        <i class="bi bi-folder2"></i>
                        ${safeText(docType)}
                    </span>
                `;
            }

            // PDF Viewer Section
            let pdfHtml = '';
            if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                pdfHtml = `
                    <div class="pdf-viewer-section">
                        <h6><i class="bi bi-file-pdf"></i> Document Preview</h6>
                        <div id="pdfViewer">
                            <canvas id="pdfCanvas"></canvas>
                        </div>
                        <div id="pdfPageControls" class="d-flex justify-content-between align-items-center">
                            <button id="prevPage" class="btn btn-sm btn-outline-primary" disabled>
                                <i class="bi bi-chevron-left"></i> Prev
                            </button>
                            <span id="pageInfo" class="badge bg-primary">Page 1 of 1</span>
                            <button id="nextPage" class="btn btn-sm btn-outline-primary" disabled>
                                Next <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                `;
            }

            // Document Info Section
            let infoHtml = `
                <div class="document-info-section">
                    <h6><i class="bi bi-info-circle"></i> Document Information</h6>
                    <div class="info-item">
                        <span class="info-label">Document Type:</span>
                        <span class="info-value">
                            <span class="meta-item">
                                <i class="bi bi-folder2"></i>
                                ${safeText(docType)}
                            </span>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status:</span>
                        <span class="info-value"><span class="badge ${getStatusBadgeClass(doc.status)}">${formatStatusDisplay(doc.status)}</span></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Current Office:</span>
                        <span class="info-value"><span class="badge ${getLocationBadgeClass(doc.current_location)}">${shortenOfficeName(doc.current_location)}</span></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Submitted:</span>
                        <span class="info-value">${new Date(doc.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
                    </div>
                </div>
            `;

            // System Alert (e.g., Auto-timeout)
            let systemHtml = '';
            if (doc.notes && doc.notes.length > 0) {
                const systemNote = doc.notes.find(note => note.note.includes('Auto-timeout'));
                if (systemNote) {
                    systemHtml = `
                        <div class="system-alert">
                            <h6><i class="bi bi-clock"></i> System Notification</h6>
                            <div class="alert-content">
                                <small class="d-block text-muted mb-2">${new Date(systemNote.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>
                                <p class="mb-0">${systemNote.note}</p>
                            </div>
                        </div>
                    `;
                }
            }

            // Rejection Alert
            let rejectionHtml = '';
            if (doc.notes && doc.notes.length > 0) {
                const rejectionNote = doc.notes.find(note => note.is_rejection);
                if (rejectionNote) {
                    rejectionHtml = `
                        <div class="rejection-alert">
                            <h6><i class="bi bi-exclamation-triangle"></i> Rejection Reason</h6>
                            <div class="alert-content">
                                <strong>${rejectionNote.created_by_name}</strong>
                                <small class="d-block text-muted mb-1">${rejectionNote.position || 'Unknown Role'}</small>
                                <small class="d-block text-muted mb-2">${new Date(rejectionNote.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>
                                <p class="mb-0">${rejectionNote.note}</p>
                            </div>
                        </div>
                    `;
                }
            }

            // Notes Section
            let notesHtml = '';
            if (doc.notes && doc.notes.length > 0) {
                const otherNotes = doc.notes.filter(note => !note.is_rejection && !note.note.includes('Auto-timeout'));
                if (otherNotes.length > 0) {
                    notesHtml = '<div class="notes-section"><h6><i class="bi bi-chat-dots"></i> Notes & Comments</h6>';
                    otherNotes.forEach(note => {
                        const approvalStatus = note.step_status === 'completed' ? 'Completed' : note.step_status === 'rejected' ? 'Rejected' : 'Pending';
                        notesHtml += `
                            <div class="note-item">
                                <div class="note-meta">
                                    <strong>${note.created_by_name}</strong> (${approvalStatus}) · ${new Date(note.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                </div>
                                <div class="note-content">${note.note}</div>
                            </div>
                        `;
                    });
                    notesHtml += '</div>';
                }
            }

            // Timeline Section
            let timelineHtml = '<div class="timeline-section"><h6><i class="bi bi-clock-history"></i> Approval Timeline</h6>';
            if (doc.workflow_history && doc.workflow_history.length > 0) {
                timelineHtml += '<div class="timeline">';
                doc.workflow_history.forEach((item) => {
                    timelineHtml += `
                        <div class="timeline-item">
                            <div class="timeline-marker ${getStatusColorClass(item.status)}"></div>
                            <div class="timeline-content">
                                <div class="timeline-date">${new Date(item.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                                <div class="timeline-action">${item.action} · ${shortenOfficeName(item.office_name || item.from_office)}</div>
                            </div>
                        </div>
                    `;
                });
                timelineHtml += '</div>';
            } else {
                timelineHtml += '<p class="text-muted">No workflow history available</p>';
            }
            timelineHtml += '</div>';

            // Conversation Section (before approval timeline)
            const conversationHtml = `
                <div class="conversation-section">
                    <h6><i class="bi bi-chat-left-text"></i> Conversation</h6>
                    <div id="conversationThread" class="conversation-thread"></div>

                    <div id="conversationReplyBanner" class="conversation-reply-banner" style="display:none;">
                        <span>Replying to <strong id="conversationReplyAuthor"></strong></span>
                        <button type="button" class="btn btn-link btn-sm p-0" id="cancelConversationReply">Cancel</button>
                    </div>

                    <div class="conversation-input-wrap">
                        <textarea id="conversationInput" class="form-control" rows="3" placeholder="Write your reply..."></textarea>
                        <div class="conversation-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="sendConversationBtn">
                                <i class="bi bi-send me-1"></i>Reply
                            </button>
                        </div>
                    </div>
                </div>
            `;

            // Assemble final modal content
            modalBody.innerHTML = `
                <div class="row g-2">
                    <div class="col-lg-6">
                        ${pdfHtml}
                    </div>
                    <div class="col-lg-6">
                        ${infoHtml}
                        ${systemHtml}
                        ${rejectionHtml}
                        ${notesHtml}
                        ${conversationHtml}
                        ${timelineHtml}
                    </div>
                </div>
            `;

            currentConversationDocumentId = Number(doc.id);
            currentReplyTargetId = null;

            const sendConversationBtn = document.getElementById('sendConversationBtn');
            if (sendConversationBtn) {
                sendConversationBtn.addEventListener('click', postConversationReply);
            }

            const conversationInput = document.getElementById('conversationInput');
            if (conversationInput) {
                conversationInput.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        postConversationReply();
                    }
                });
            }

            const cancelConversationReply = document.getElementById('cancelConversationReply');
            if (cancelConversationReply) {
                cancelConversationReply.addEventListener('click', clearReplyTarget);
            }

            loadConversationThread(currentConversationDocumentId);

            // Show download button if PDF available and document is approved or rejected
            const downloadBtn = document.getElementById('downloadDocumentBtn');
            if (downloadBtn && doc.file_path && (doc.status === 'approved' || doc.status === 'rejected')) {
                downloadBtn.style.display = 'inline-block';
                downloadBtn.onclick = () => downloadDocument(doc.id);
            } else if (downloadBtn) {
                downloadBtn.style.display = 'none';
            }

            // Load PDF if available
            if (doc.file_path && /\.pdf(\?|$)/i.test(doc.file_path)) {
                let pdfUrl = doc.file_path;
                if (pdfUrl.startsWith('/')) {
                    pdfUrl = BASE_URL + pdfUrl.substring(1);
                } else if (pdfUrl.startsWith('../')) {
                    pdfUrl = BASE_URL + pdfUrl.substring(3);
                } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                    pdfUrl = BASE_URL + pdfUrl.substring(12);
                } else if (pdfUrl.startsWith('http')) {
                    // Full URL
                } else if (pdfUrl.startsWith('uploads/')) {
                    pdfUrl = BASE_URL + pdfUrl;
                } else {
                    pdfUrl = BASE_URL + 'uploads/' + pdfUrl;
                }
                
                // Store PDF URL and document for loading after modal is shown
                window.pendingPdfLoad = { url: pdfUrl, doc: doc };
            }

            modal.show();
            
            // Load PDF after modal is fully shown
            const modalElement = document.getElementById('documentModal');
            modalElement.addEventListener('shown.bs.modal', async function onModalShown() {
                modalElement.removeEventListener('shown.bs.modal', onModalShown);
                
                if (window.pendingPdfLoad) {
                    const { url, doc } = window.pendingPdfLoad;
                    delete window.pendingPdfLoad;
                    await loadPdfInModal(url, doc);
                }
            });
        } else {
            modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle"></i><span>Error</span>';
            modalBody.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Failed to load document details</div>';
        }
    } catch (error) {
        console.error('Error fetching document details:', error);
        modalTitle.textContent = 'Error Loading Document';
        let errorMessage = 'Failed to load document details';
        if (error.message.includes('not found')) {
            errorMessage = 'Document not found or has been deleted';
        } else if (error.message.includes('Access denied')) {
            errorMessage = 'You do not have permission to view this document';
        } else if (error.message.includes('network') || error.message.includes('fetch')) {
            errorMessage = 'Network error. Please check your connection and try again';
        }
        modalBody.innerHTML = `<p class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>${errorMessage}</p>`;
    }

    modal.show();
}

// Add PDF loading function for modal
// Update the loadPdfInModal function
async function loadPdfInModal(url, doc = null) {
    const canvas = document.getElementById('pdfCanvas');
    const pdfViewer = document.getElementById('pdfViewer');
    
    if (!canvas || !pdfViewer) return;

    try {
        // Clear any existing overlays
        const existing = pdfViewer.querySelectorAll('.completed-signature-container');
        existing.forEach(el => el.remove());
        
        // Store document for signature rendering
        window.currentDocument = doc;
        
        // Ensure PDF viewer has position relative
        pdfViewer.style.position = 'relative';
        
        const pdfjsLib = window['pdfjsLib'];
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        
        pdfDoc = await pdfjsLib.getDocument(url).promise;
        totalPdfPages = pdfDoc.numPages;
        currentPdfPage = 1;
        window.currentPdfPage = 1;

        // Update page controls
        updatePdfPageControls();

        // Render first page
        await renderPdfPage(1);
        
    } catch (error) {
        console.error('Error loading PDF:', error);
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
        
        // Get the container width
        const containerWidth = pdfViewer.clientWidth - 20;
        
        // Get the page's original dimensions
        const viewport = page.getViewport({ scale: 1.0 });
        
        // Calculate scale to fit width while maintaining quality
        // Use device pixel ratio for sharper rendering
        const devicePixelRatio = window.devicePixelRatio || 1;
        const scale = (containerWidth / viewport.width) * devicePixelRatio;
        
        // Set canvas dimensions in physical pixels
        const scaledViewport = page.getViewport({ scale: scale });
        
        canvas.width = scaledViewport.width;
        canvas.height = scaledViewport.height;
        
        // Set canvas CSS dimensions to match container
        canvas.style.width = containerWidth + 'px';
        canvas.style.height = (scaledViewport.height / devicePixelRatio) + 'px';
        
        // Render with high quality
        const renderContext = {
            canvasContext: canvas.getContext('2d', { 
                alpha: false,
                desynchronized: true 
            }),
            viewport: scaledViewport,
            intent: 'display'
        };
        
        // Clear canvas with white background
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        await page.render(renderContext).promise;
        
        console.log(`Page ${pageNum} rendered at scale ${scale} (devicePixelRatio: ${devicePixelRatio})`);
        
        // Update current page for signature filtering
        window.currentPdfPage = pageNum;
        
        // Re-render signatures for this page
        if (window.currentDocument) {
            // Clear existing signatures
            const existing = pdfViewer.querySelectorAll('.completed-signature-container');
            existing.forEach(el => el.remove());
            
            // Render signatures for current page only
            renderCompletedSignatures(window.currentDocument, pdfViewer);
        }
        
    } catch (error) {
        console.error('Error rendering PDF page:', error);
    }
}

// Update PDF page controls
function updatePdfPageControls() {
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    const pageInfo = document.getElementById('pageInfo');

    if (prevBtn) prevBtn.disabled = currentPdfPage <= 1;
    if (nextBtn) nextBtn.disabled = currentPdfPage >= totalPdfPages;
    if (pageInfo) pageInfo.textContent = `Page ${currentPdfPage} of ${totalPdfPages}`;

    // Remove old listeners and add new ones
    if (prevBtn) {
        const newPrevBtn = prevBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
        
        newPrevBtn.addEventListener('click', async () => {
            if (currentPdfPage > 1) {
                currentPdfPage--;
                await renderPdfPage(currentPdfPage);
                updatePdfPageControls();
            }
        });
    }

    if (nextBtn) {
        const newNextBtn = nextBtn.cloneNode(true);
        nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
        
        newNextBtn.addEventListener('click', async () => {
            if (currentPdfPage < totalPdfPages) {
                currentPdfPage++;
                await renderPdfPage(currentPdfPage);
                updatePdfPageControls();
            }
        });
    }
}

// Helper function to get status badge class
function getStatusBadgeClass(status) {
    switch (status.toLowerCase()) {
        case 'submitted': return 'status-submitted';
        case 'pending': return 'status-pending';
        case 'in progress':
        case 'in_progress': return 'status-in-progress';
        case 'under review':
        case 'under_review': return 'status-under-review';
        case 'completed': return 'status-completed';
        case 'approved': return 'status-approved';
        case 'rejected': return 'status-rejected';
        case 'timeout': return 'status-timeout';
        case 'deleted': return 'status-deleted';
        default: return 'status-submitted';
    }
}

// Helper function to get location badge class
function getLocationBadgeClass(location) {
    // Custom colors for different office locations
    switch (location.toLowerCase()) {
        case 'finance office':
        case 'cpa office':
        case 'accounting': return 'location-finance';
        case 'student affairs':
        case 'osa':
        case 'oic osa': return 'location-student-affairs';
        case 'student council':
        case 'ssc':
        case 'Supreme Student Council President': return 'location-student-council';
        case 'dean\'s office':
        case 'college dean':
        case 'dean': return 'location-deans-office';
        case 'vp office':
        case 'vpaa':
        case 'evp': return 'location-vp-office';
        case 'csc office':
        case 'csc adviser': return 'location-csc-office';
        case 'approved': return 'location-approved';
        default: return 'location-default';
    }
}

// Helper function to get document type badge class
function getDocTypeBadgeClass(docType) {
    switch (docType.toLowerCase()) {
        case 'saf': return 'doctype-saf';
        case 'proposal': return 'doctype-proposal';
        case 'communication': return 'doctype-communication';
        case 'material': return 'doctype-material';
        default: return 'doctype-saf'; // default to saf
    }
}

// Helper function to get status color class for timeline
function getStatusColorClass(status) {
    switch (status.toLowerCase()) {
        case 'submitted': return 'timeline-submitted';
        case 'pending': return 'timeline-pending';
        case 'in progress':
        case 'inprogress':
        case 'in_progress': return 'timeline-in-progress';
        case 'under review':
        case 'review':
        case 'under_review': return 'timeline-under-review';
        case 'completed':
        case 'approved': return 'timeline-approved';
        case 'rejected': return 'timeline-rejected';
        case 'timeout': return 'timeline-timeout';
        case 'deleted': return 'timeline-deleted';
        default: return 'timeline-submitted';
    }
}

// Timeline styles are now handled in CSS file
// Add loading dots animation
function addLoadingDotsAnimation() {
    const style = document.createElement('style');
    style.textContent = `
        .loading-dots {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
            margin-top: 1rem;
        }
        
        .loading-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #3b82f6;
            animation: loadingDots 1.4s infinite ease-in-out;
        }
        
        .loading-dots span:nth-child(1) { animation-delay: -0.32s; }
        .loading-dots span:nth-child(2) { animation-delay: -0.16s; }
        .loading-dots span:nth-child(3) { animation-delay: 0s; }
        
        @keyframes loadingDots {
            0%, 80%, 100% {
                transform: scale(0);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .empty-state-icon {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
    `;
    document.head.appendChild(style);
}

// Initialize loading animations on page load
addLoadingDotsAnimation();

// Add loading dots animation
function addLoadingDotsAnimation() {
    const style = document.createElement('style');
    style.textContent = `
        .loading-dots {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
            margin-top: 1rem;
        }
        
        .loading-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #3b82f6;
            animation: loadingDots 1.4s infinite ease-in-out;
        }
        
        .loading-dots span:nth-child(1) { animation-delay: -0.32s; }
        .loading-dots span:nth-child(2) { animation-delay: -0.16s; }
        .loading-dots span:nth-child(3) { animation-delay: 0s; }
        
        @keyframes loadingDots {
            0%, 80%, 100% {
                transform: scale(0);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .empty-state-icon {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
    `;
    document.head.appendChild(style);
}

// Initialize loading animations on page load
addLoadingDotsAnimation();

// Navbar Functions
function openProfileSettings() {
    const modal = new bootstrap.Modal(document.getElementById('profileSettingsModal'));

    // Populate form with current user data
    if (window.currentUser) {
        document.getElementById('profileFirstName').value = window.currentUser.firstName || '';
        document.getElementById('profileLastName').value = window.currentUser.lastName || '';
        document.getElementById('profileEmail').value = window.currentUser.email || '';
        document.getElementById('profilePhone').value = window.currentUser.phone || '';
    }

    modal.show();
}

function openChangePassword() {
    // Redirect to change password page or show modal
    showToast('Redirecting to change password...', 'info');
    // For now, you could implement a password change modal or redirect
    // window.location.href = 'change-password.php';
}

function openPreferences() {
    const modal = new bootstrap.Modal(document.getElementById('preferencesModal'));

    // Load current preferences from localStorage
    const prefs = {
        autoRefresh: localStorage.getItem('trackDoc_autoRefresh') !== 'false',
        emailNotifications: localStorage.getItem('trackDoc_emailNotifications') !== 'false',
        showRejectedNotes: localStorage.getItem('trackDoc_showRejectedNotes') !== 'false',
        itemsPerPage: localStorage.getItem('trackDoc_itemsPerPage') || '10',
        compactView: localStorage.getItem('trackDoc_compactView') !== 'false',
        showStats: localStorage.getItem('trackDoc_showStats') !== 'false'
    };

    document.getElementById('autoRefresh').checked = prefs.autoRefresh;
    document.getElementById('emailNotifications').checked = prefs.emailNotifications;
    document.getElementById('showRejectedNotes').checked = prefs.showRejectedNotes;
    document.getElementById('itemsPerPagePref').value = prefs.itemsPerPage;
    document.getElementById('compactView').checked = prefs.compactView;
    document.getElementById('showStats').checked = prefs.showStats;

    modal.show();
}

function showHelp() {
    const modal = new bootstrap.Modal(document.getElementById('helpModal'));
    modal.show();
}

function saveProfileSettings() {
    const formData = {
        firstName: document.getElementById('profileFirstName').value.trim(),
        lastName: document.getElementById('profileLastName').value.trim(),
        email: document.getElementById('profileEmail').value.trim(),
        phone: document.getElementById('profilePhone').value.trim()
    };

    // Basic validation
    if (!formData.firstName || !formData.lastName || !formData.email) {
        showToast('Please fill in all required fields', 'error');
        return;
    }

    // Here you would typically send to server
    showToast('Profile settings saved successfully', 'success');
    bootstrap.Modal.getInstance(document.getElementById('profileSettingsModal')).hide();

    // Update display name if changed
    if (document.getElementById('userDisplayName')) {
        document.getElementById('userDisplayName').textContent = `${formData.firstName} ${formData.lastName}`;
    }
}

function savePreferences() {
    const prefs = {
        autoRefresh: document.getElementById('autoRefresh').checked,
        emailNotifications: document.getElementById('emailNotifications').checked,
        showRejectedNotes: document.getElementById('showRejectedNotes').checked,
        itemsPerPage: document.getElementById('itemsPerPagePref').value,
        compactView: document.getElementById('compactView').checked,
        showStats: document.getElementById('showStats').checked
    };

    // Save to localStorage
    localStorage.setItem('trackDoc_autoRefresh', prefs.autoRefresh);
    localStorage.setItem('trackDoc_emailNotifications', prefs.emailNotifications);
    localStorage.setItem('trackDoc_showRejectedNotes', prefs.showRejectedNotes);
    localStorage.setItem('trackDoc_itemsPerPage', prefs.itemsPerPage);
    localStorage.setItem('trackDoc_compactView', prefs.compactView);
    localStorage.setItem('trackDoc_showStats', prefs.showStats);

    // Apply items per page immediately
    itemsPerPage = parseInt(prefs.itemsPerPage);
    document.getElementById('itemsPerPage').value = itemsPerPage;

    // Re-render current page
    renderCurrentPage();

    showToast('Preferences saved successfully', 'success');
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

// Enhanced functionality complete

// Function to render completed signatures as redaction overlays
// Replace the renderCompletedSignatures function with this corrected version
function renderCompletedSignatures(doc, container) {
    console.log('renderCompletedSignatures called with doc:', doc, 'container:', container);
    
    // Clear any existing overlays first
    const existing = container.querySelectorAll('.completed-signature-container');
    existing.forEach(el => el.remove());

    // Ensure container has position relative
    container.style.position = 'relative';
    
    const canvas = container.querySelector('canvas');
    if (!canvas) {
        console.log('No canvas found in container');
        return;
    }

    // Wait for canvas to be properly rendered and visible
    if (canvas.width === 0 || canvas.height === 0 || canvas.getBoundingClientRect().width === 0) {
        console.log('Canvas not properly sized yet, waiting...');
        setTimeout(() => renderCompletedSignatures(doc, container), 200);
        return;
    }

    // Get current page from the PDF viewer
    const currentPage = window.currentPdfPage || 1;
    console.log('Current PDF page:', currentPage);

    // Get fresh dimensions
    const containerRect = container.getBoundingClientRect();
    const canvasRect = canvas.getBoundingClientRect();
    
    console.log('Canvas display rect:', canvasRect);

    // Get workflow history - this contains all signers with their info
    const workflowHistory = doc.workflow_history || [];
    console.log('Workflow history:', workflowHistory);

    // Filter for completed signatures (approved/reviewed steps)
    const completedSignatures = workflowHistory.filter(step => 
        step.status === 'completed' || step.status === 'approved'
    );

    console.log('Completed signatures found:', completedSignatures.length);

    completedSignatures.forEach((step, index) => {
        console.log(`Processing step ${index}:`, step);

        // Get signature map from the step
        let signatureMap = step.signature_map;
        
        if (typeof signatureMap === 'string') {
            try {
                signatureMap = JSON.parse(signatureMap);
            } catch (e) {
                console.log('Failed to parse signature_map:', e);
                signatureMap = null;
            }
        }
        
        // Handle different signature map structures
        let mapsToRender = [];
        
        if (signatureMap) {
            // Check if it's a dual signature map (SAF)
            if (signatureMap.accounting && signatureMap.issuer) {
                // Dual signature - add both with proper labels
                mapsToRender.push({
                    ...signatureMap.accounting,
                    label: 'Accounting',
                    step: step
                });
                mapsToRender.push({
                    ...signatureMap.issuer,
                    label: 'Issuer',
                    step: step
                });
            } else {
                // Single signature
                mapsToRender.push({
                    ...signatureMap,
                    step: step
                });
            }
        } else {
            // Default position if no map
            const baseY = 0.78;
            const offset = index * 0.1;
            mapsToRender.push({
                x_pct: 0.62,
                y_pct: Math.max(0.1, baseY - offset),
                w_pct: 0.28,
                h_pct: 0.1,
                page: 1,
                step: step
            });
        }

        // Render each signature map
        mapsToRender.forEach((map) => {
            // Check if this signature belongs on the current page
            const signaturePage = map.page || 1;
            if (signaturePage !== currentPage) {
                console.log(`Skipping signature on page ${signaturePage}, current page is ${currentPage}`);
                return;
            }

            // Calculate position using display dimensions
            const left = (canvasRect.left - containerRect.left) + (map.x_pct * canvasRect.width);
            const top = (canvasRect.top - containerRect.top) + (map.y_pct * canvasRect.height);
            const width = (map.w_pct || 0.28) * canvasRect.width;
            const height = (map.h_pct || 0.1) * canvasRect.height;

            console.log(`Rendering signature at:`, { left, top, width, height, page: signaturePage });

            // Get signer details from the step - MATCHING NOTIFICATIONS PAGE FORMAT
            const stepData = map.step;
            
            // Extract name - try multiple possible fields
            let name = 'Unknown';
            if (stepData.assignee_name) {
                name = stepData.assignee_name;
            } else if (stepData.created_by_name) {
                name = stepData.created_by_name;
            } else if (stepData.from_office) {
                // Use office name as fallback
                name = stepData.from_office;
            }
            
            // Extract position/title
            let position = '';
            if (stepData.assignee_position) {
                position = stepData.assignee_position;
            } else if (stepData.position) {
                position = stepData.position;
            }
            
            // Extract timestamp - use the appropriate date field
            let timestamp = '';
            if (stepData.signed_at) {
                timestamp = new Date(stepData.signed_at).toLocaleString();
            } else if (stepData.created_at) {
                timestamp = new Date(stepData.created_at).toLocaleString();
            } else if (stepData.acted_at) {
                timestamp = new Date(stepData.acted_at).toLocaleString();
            }

            // Get office/role for additional context
            const office = stepData.from_office || stepData.office_name || '';
            
            // For SAF documents, add the role label
            if (map.label) {
                // If it's a dual signature, show the role
                name = `${name} (${map.label})`;
            } else if (office.includes('Accounting')) {
                name = `${name} (Accounting)`;
            } else if (office.includes('Creator') || office.includes('Student')) {
                name = `${name} (Issuer)`;
            }

            console.log(`Signer details:`, { name, position, timestamp, office });

            // Create redaction container
            const signatureContainer = document.createElement('div');
            signatureContainer.className = 'completed-signature-container';
            signatureContainer.setAttribute('data-page', signaturePage);
            signatureContainer.setAttribute('data-signer', name);
            
            signatureContainer.style.position = 'absolute';
            signatureContainer.style.left = left + 'px';
            signatureContainer.style.top = top + 'px';
            signatureContainer.style.width = width + 'px';
            signatureContainer.style.height = height + 'px';
            signatureContainer.style.zIndex = '1060';
            signatureContainer.style.pointerEvents = 'none';
            signatureContainer.style.boxShadow = '0 2px 8px rgba(0,0,0,0.3)';
            signatureContainer.style.borderRadius = '4px';
            signatureContainer.style.overflow = 'hidden';

            // Create redaction box
            const redactionBox = document.createElement('div');
            redactionBox.className = 'signature-redaction';
            redactionBox.style.width = '100%';
            redactionBox.style.height = '100%';
            redactionBox.style.display = 'flex';
            redactionBox.style.alignItems = 'center';
            redactionBox.style.justifyContent = 'center';
            redactionBox.style.backgroundColor = 'rgba(0, 0, 0, 0.85)';
            redactionBox.style.color = 'white';
            redactionBox.style.fontWeight = '600';
            redactionBox.style.fontSize = '12px';
            redactionBox.style.padding = '6px';
            redactionBox.style.textAlign = 'center';
            redactionBox.style.backdropFilter = 'blur(4px)';
            redactionBox.style.WebkitBackdropFilter = 'blur(4px)';
            redactionBox.style.border = '1px solid rgba(255,255,255,0.2)';
            redactionBox.style.boxSizing = 'border-box';
            redactionBox.style.lineHeight = '1.4';
            
            // Format the display - matching notifications page style
            redactionBox.innerHTML = `
                <div style="text-align: center; width: 100%;">
                    <div style="font-weight:700; margin-bottom:2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${name}</div>
                    ${position ? `<div style="font-size:11px; opacity:0.9; margin-bottom:2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${position}</div>` : ''}
                    <div style="font-size:10px; opacity:0.8;">${timestamp}</div>
                </div>
            `;

            signatureContainer.appendChild(redactionBox);
            container.appendChild(signatureContainer);
        });
    });
    
    console.log('Finished rendering signatures');
}

// Add this debug helper (optional, remove in production)
function debugRedactionPositions() {
    const viewer = document.getElementById('pdfViewer');
    const containers = viewer.querySelectorAll('.completed-signature-container');

    console.log('Redaction containers found:', containers.length);
    containers.forEach((container, i) => {
        const rect = container.getBoundingClientRect();
        console.log(`Container ${i}:`, {
            left: rect.left,
            top: rect.top,
            width: rect.width,
            height: rect.height,
            style: container.style.cssText
        });
    });
}

// Call this from browser console to debug
// debugRedactionPositions();

