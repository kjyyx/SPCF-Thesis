// Modern Document Tracker JavaScript

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Set user display name
    if (window.currentUser && document.getElementById('userDisplayName')) {
        document.getElementById('userDisplayName').textContent = `${window.currentUser.firstName} ${window.currentUser.lastName}`;
    }

    // Initialize search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', handleSearch);
    }

    // Initialize filter functionality
    const filterButtons = document.querySelectorAll('input[name="statusFilter"]');
    filterButtons.forEach(button => {
        button.addEventListener('change', handleFilter);
    });

    // Add smooth animations
    initializeAnimations();
});

// Initialize animations
function initializeAnimations() {
    // Add fade-in animation to table rows
    const rows = document.querySelectorAll('#documentsList tr');
    rows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        setTimeout(() => {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, index * 50);
    });
}

// Search functionality with debouncing
let searchTimeout;
function handleSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
        const tableRows = document.querySelectorAll('#documentsList tr');

        tableRows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const matches = text.includes(searchTerm);
            row.style.display = matches ? '' : 'none';

            // Add animation for showing/hiding
            if (matches) {
                row.style.animation = 'fadeIn 0.3s ease';
            }
        });

        updateNoResultsMessage();
    }, 300);
}

// Filter functionality
function handleFilter() {
    const selectedFilter = document.querySelector('input[name="statusFilter"]:checked').id;
    const tableRows = document.querySelectorAll('#documentsList tr');

    tableRows.forEach(row => {
        const statusBadge = row.querySelector('.badge');
        if (!statusBadge) return;

        const statusText = statusBadge.textContent.toLowerCase().replace(' ', '');
        let show = false;

        switch (selectedFilter) {
            case 'filterAll':
                show = true;
                break;
            case 'filterPending':
                show = statusText === 'pending';
                break;
            case 'filterProgress':
                show = statusText === 'inprogress';
                break;
            case 'filterReview':
                show = statusText === 'underreview';
                break;
            case 'filterCompleted':
                show = statusText === 'completed';
                break;
        }

        row.style.display = show ? '' : 'none';

        // Add animation
        if (show) {
            row.style.animation = 'fadeIn 0.3s ease';
        }
    });

    updateNoResultsMessage();
}

// Update no results message
function updateNoResultsMessage() {
    const visibleRows = document.querySelectorAll('#documentsList tr[style*="display: none"]');
    const totalRows = document.querySelectorAll('#documentsList tr').length;

    // Remove existing message
    const existingMessage = document.querySelector('.no-results');
    if (existingMessage) {
        existingMessage.remove();
    }

    if (visibleRows.length === totalRows) {
        const tbody = document.getElementById('documentsList');
        const message = document.createElement('tr');
        message.className = 'no-results';
        message.innerHTML = `
            <td colspan="5" class="text-center py-5">
                <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                <h5 class="text-muted mt-3">No documents found</h5>
                <p class="text-muted">Try adjusting your search or filter criteria</p>
            </td>
        `;
        tbody.appendChild(message);
    }
}

// View document details with enhanced modal
function viewDetails(docId) {
    const modal = new bootstrap.Modal(document.getElementById('documentModal'));
    const modalTitle = document.getElementById('documentModalTitle');
    const modalBody = document.getElementById('documentModalBody');

    // Sample document details - in real app, this would fetch from server
    const documentDetails = {
        'doc-001': {
            title: 'Budget Proposal 2024',
            status: 'In Progress',
            location: 'Finance Office',
            submitted: '2024-01-15',
            description: 'Annual budget proposal for student activities and events.',
            history: [
                { date: '2024-01-15', action: 'Submitted', office: 'Student Council', status: 'pending' },
                { date: '2024-01-16', action: 'Received', office: 'Finance Office', status: 'inprogress' },
                { date: '2024-01-18', action: 'Under Review', office: 'Finance Office', status: 'review' }
            ]
        },
        'doc-002': {
            title: 'Event Request Form',
            status: 'Completed',
            location: 'Approved',
            submitted: '2024-01-10',
            description: 'Request for university-wide cultural event approval.',
            history: [
                { date: '2024-01-10', action: 'Submitted', office: 'Student Council', status: 'pending' },
                { date: '2024-01-11', action: 'Approved', office: 'Dean\'s Office', status: 'completed' }
            ]
        },
        'doc-003': {
            title: 'Facility Reservation',
            status: 'Pending',
            location: 'Student Council',
            submitted: '2024-01-12',
            description: 'Reservation request for auditorium for student meeting.',
            history: [
                { date: '2024-01-12', action: 'Submitted', office: 'Student Council', status: 'pending' }
            ]
        },
        'doc-004': {
            title: 'Activity Report',
            status: 'Under Review',
            location: 'Dean\'s Office',
            submitted: '2024-01-08',
            description: 'Monthly activity report for student organizations.',
            history: [
                { date: '2024-01-08', action: 'Submitted', office: 'Student Council', status: 'pending' },
                { date: '2024-01-09', action: 'Forwarded', office: 'Dean\'s Office', status: 'review' }
            ]
        }
    };

    const doc = documentDetails[docId];
    if (doc) {
        modalTitle.textContent = doc.title;

        let statusHtml = `<div class="mb-3">
            <strong>Current Status:</strong>
            <span class="badge ${getStatusBadgeClass(doc.status)} ms-2">${doc.status}</span>
        </div>`;

        let locationHtml = `<div class="mb-3">
            <strong>Current Location:</strong>
            <span class="badge bg-primary">${doc.location}</span>
        </div>`;

        let submittedHtml = `<div class="mb-3">
            <strong>Submitted:</strong> ${doc.submitted}
        </div>`;

        let descriptionHtml = `<div class="mb-3">
            <strong>Description:</strong> ${doc.description}
        </div>`;

        let historyHtml = '<div class="mb-3"><h6>Document History:</h6><div class="timeline">';
        doc.history.forEach((item, index) => {
            historyHtml += `
                <div class="timeline-item">
                    <div class="timeline-marker ${getStatusColorClass(item.status)}"></div>
                    <div class="timeline-content">
                        <div class="timeline-date">${item.date}</div>
                        <div class="timeline-action">${item.action} - ${item.office}</div>
                    </div>
                </div>
            `;
        });
        historyHtml += '</div></div>';

        modalBody.innerHTML = `
            ${statusHtml}
            ${locationHtml}
            ${submittedHtml}
            ${descriptionHtml}
            ${historyHtml}
        `;
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
        default: return 'bg-secondary';
    }
}

// Add CSS for timeline and animations
const style = document.createElement('style');
style.textContent = `
    .timeline {
        position: relative;
        padding-left: 30px;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, #e2e8f0, #cbd5e1);
    }

    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }

    .timeline-marker {
        position: absolute;
        left: -22px;
        top: 6px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .timeline-content {
        background: #f8fafc;
        padding: 12px 16px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }

    .timeline-date {
        font-weight: 600;
        color: #374151;
        font-size: 0.875rem;
    }

    .timeline-action {
        color: #6b7280;
        font-size: 0.875rem;
        margin-top: 2px;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .no-results {
        animation: fadeIn 0.3s ease;
    }
`;
document.head.appendChild(style);