<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Management System v6.0 - University Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/track-document.css"> <!-- Updated path -->
    <link rel="stylesheet" href="../assets/css/toast.css">
</head>

<body>
    <div class="main-wrapper">
        <div class="container-fluid">
            <!-- Document Management Interface -->
            <div class="system-card" id="management-interface">
                <!-- Enhanced Header -->
                <div class="system-header">
                    <div class="header-content">
                        <div class="version-badge">
                            <i class="bi bi-star-fill me-2"></i>Version 6.0
                        </div>
                        <div class="row align-items-center">
                            <div class="col-lg-8">
                                <h1 class="display-6 fw-bold mb-3">
                                    <i class="bi bi-folder2-open me-3"></i>
                                    Document Management System
                                </h1>
                                <p class="lead mb-0 opacity-90">
                                    Advanced tracking and management for university document workflows
                                </p>
                            </div>
                            <div class="col-lg-4">
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <span class="stat-number">24</span>
                                        <span class="stat-label">Total Docs</span>
                                    </div>
                                    <div class="stat-card">
                                        <span class="stat-number">8</span>
                                        <span class="stat-label">In Progress</span>
                                    </div>
                                    <div class="stat-card">
                                        <span class="stat-number">12</span>
                                        <span class="stat-label">Completed</span>
                                    </div>
                                    <div class="stat-card">
                                        <span class="stat-number">4</span>
                                        <span class="stat-label">Pending</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Controls -->
                <div class="controls-section">
                    <!-- Search -->
                    <div class="search-container">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" class="form-control search-input"
                            placeholder="Search documents, events, or status..." id="search-input">
                    </div>

                    <!-- Filter Pills -->
                    <div class="filter-pills">
                        <button class="filter-pill active" data-filter="all">
                            <i class="bi bi-grid me-2"></i>All Documents
                        </button>
                        <button class="filter-pill" data-filter="pending">
                            <i class="bi bi-clock me-2"></i>Pending
                        </button>
                        <button class="filter-pill" data-filter="in-progress">
                            <i class="bi bi-arrow-repeat me-2"></i>In Progress
                        </button>
                        <button class="filter-pill" data-filter="completed">
                            <i class="bi bi-check-circle me-2"></i>Completed
                        </button>
                    </div>

                    <!-- Bulk Controls -->
                    <div class="bulk-controls">
                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="select-all">
                                <label class="form-check-label fw-semibold" for="select-all">
                                    Select All Documents
                                </label>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-enhanced btn-primary-enhanced" id="track-selected-btn" disabled>
                                <i class="bi bi-graph-up me-2"></i>Track Selected
                            </button>
                            <button class="btn btn-enhanced btn-outline-enhanced" id="bulk-download-btn" disabled>
                                <i class="bi bi-download me-2"></i>Download All
                            </button>
                        </div>
                    </div>

                    <!-- Selection Summary -->
                    <div class="selection-summary" id="selection-summary">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong id="selected-count">0</strong> documents selected from
                                <strong id="selected-events">0</strong> events
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" id="clear-selection">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Documents Section -->
                <div class="tracker-section">
                    <!-- Event Folder 1: Annual Budget Planning 2024 -->
                    <div class="event-folder" data-event="budget-planning">
                        <div class="event-header" onclick="toggleEventFolder(this)">
                            <div class="row align-items-center">
                                <div class="col-lg-8">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="event-icon">
                                            <i class="bi bi-calculator"></i>
                                        </div>
                                        <div>
                                            <h5 class="event-title">Annual Budget Planning 2024</h5>
                                            <div class="event-meta">
                                                <div class="event-meta-item">
                                                    <i class="bi bi-file-earmark"></i>
                                                    <span>4 documents</span>
                                                </div>
                                                <div class="event-meta-item">
                                                    <i class="bi bi-calendar"></i>
                                                    <span>March 15, 2024</span>
                                                </div>
                                                <div class="event-meta-item">
                                                    <i class="bi bi-person"></i>
                                                    <span>Finance Committee</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="d-flex align-items-center justify-content-end gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input event-checkbox" type="checkbox"
                                                data-event="budget-planning">
                                            <label class="form-check-label fw-semibold">Select All</label>
                                        </div>
                                        <div class="progress-circle" data-event-progress="budget-planning">
                                            <div class="progress-text">0%</div>
                                        </div>
                                        <button class="btn btn-link p-0 expand-btn">
                                            <i class="bi bi-chevron-down fs-4"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="documents-list">
                            <div class="document-item" data-status="in-progress" data-office="5"
                                data-office-name="Student Affairs Office">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-001"
                                    data-event="budget-planning">
                                <div class="document-icon pdf">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Annual Budget Proposal 2024.pdf</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>2.4 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 15, 2024</span>
                                        <span><i class="bi bi-graph-up me-1"></i>Progress: 5/10 offices (50%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-office">At Student Affairs Office</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="Track Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="document-item" data-status="completed">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-002"
                                    data-event="budget-planning">
                                <div class="document-icon doc">
                                    <i class="bi bi-file-earmark-word"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Financial Report Q1 2024.docx</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>1.8 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 15, 2024</span>
                                        <span><i class="bi bi-check-circle me-1"></i>Completed (100%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-completed">Completed</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="View History">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="document-item" data-status="pending" data-office="1"
                                data-office-name="College Student Council">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-003"
                                    data-event="budget-planning">
                                <div class="document-icon pdf">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Budget Allocation Breakdown.pdf</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>3.2 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 15, 2024</span>
                                        <span><i class="bi bi-clock me-1"></i>Progress: 1/10 offices (10%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-office">At College Student Council</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="Track Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="document-item" data-status="in-progress" data-office="3"
                                data-office-name="Dean's Office">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-004"
                                    data-event="budget-planning">
                                <div class="document-icon img">
                                    <i class="bi bi-file-earmark-image"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Budget Presentation Slides.jpg</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>5.1 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 15, 2024</span>
                                        <span><i class="bi bi-arrow-repeat me-1"></i>Progress: 3/10 offices (30%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-office">At Dean's Office</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="Track Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Event Folder 2: Student Activities Week -->
                    <div class="event-folder" data-event="activities-week">
                        <div class="event-header" onclick="toggleEventFolder(this)">
                            <div class="row align-items-center">
                                <div class="col-lg-8">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="event-icon">
                                            <i class="bi bi-calendar-event"></i>
                                        </div>
                                        <div>
                                            <h5 class="event-title">Student Activities Week 2024</h5>
                                            <div class="event-meta">
                                                <div class="event-meta-item">
                                                    <i class="bi bi-file-earmark"></i>
                                                    <span>3 documents</span>
                                                </div>
                                                <div class="event-meta-item">
                                                    <i class="bi bi-calendar"></i>
                                                    <span>March 18, 2024</span>
                                                </div>
                                                <div class="event-meta-item">
                                                    <i class="bi bi-person"></i>
                                                    <span>Activities Committee</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="d-flex align-items-center justify-content-end gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input event-checkbox" type="checkbox"
                                                data-event="activities-week">
                                            <label class="form-check-label fw-semibold">Select All</label>
                                        </div>
                                        <div class="progress-circle" data-event-progress="activities-week">
                                            <div class="progress-text">0%</div>
                                        </div>
                                        <button class="btn btn-link p-0 expand-btn">
                                            <i class="bi bi-chevron-down fs-4"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="documents-list">
                            <div class="document-item" data-status="in-progress" data-office="2"
                                data-office-name="Department Student Council">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-005"
                                    data-event="activities-week">
                                <div class="document-icon doc">
                                    <i class="bi bi-file-earmark-word"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Activities Schedule & Logistics.docx</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>2.1 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 18, 2024</span>
                                        <span><i class="bi bi-arrow-repeat me-1"></i>Progress: 2/10 offices (20%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-office">At Department Student Council</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="Track Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="document-item" data-status="pending" data-office="1"
                                data-office-name="College Student Council">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-006"
                                    data-event="activities-week">
                                <div class="document-icon pdf">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Venue Booking Request.pdf</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>1.5 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 18, 2024</span>
                                        <span><i class="bi bi-clock me-1"></i>Progress: 1/10 offices (10%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-office">At College Student Council</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="Track Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="document-item" data-status="pending" data-office="1"
                                data-office-name="College Student Council">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-007"
                                    data-event="activities-week">
                                <div class="document-icon img">
                                    <i class="bi bi-file-earmark-image"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Event Promotional Materials.png</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>4.3 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 18, 2024</span>
                                        <span><i class="bi bi-clock me-1"></i>Progress: 1/10 offices (10%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-office">At College Student Council</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="Track Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Event Folder 3: Scholarship Applications -->
                    <div class="event-folder" data-event="scholarship">
                        <div class="event-header" onclick="toggleEventFolder(this)">
                            <div class="row align-items-center">
                                <div class="col-lg-8">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="event-icon">
                                            <i class="bi bi-mortarboard"></i>
                                        </div>
                                        <div>
                                            <h5 class="event-title">Scholarship Application Review</h5>
                                            <div class="event-meta">
                                                <div class="event-meta-item">
                                                    <i class="bi bi-file-earmark"></i>
                                                    <span>2 documents</span>
                                                </div>
                                                <div class="event-meta-item">
                                                    <i class="bi bi-calendar"></i>
                                                    <span>March 20, 2024</span>
                                                </div>
                                                <div class="event-meta-item">
                                                    <i class="bi bi-person"></i>
                                                    <span>Academic Committee</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="d-flex align-items-center justify-content-end gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input event-checkbox" type="checkbox"
                                                data-event="scholarship">
                                            <label class="form-check-label fw-semibold">Select All</label>
                                        </div>
                                        <div class="progress-circle" data-event-progress="scholarship">
                                            <div class="progress-text">0%</div>
                                        </div>
                                        <button class="btn btn-link p-0 expand-btn">
                                            <i class="bi bi-chevron-down fs-4"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="documents-list">
                            <div class="document-item" data-status="completed">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-008"
                                    data-event="scholarship">
                                <div class="document-icon pdf">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Scholarship Criteria Guidelines.pdf</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>1.2 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 20, 2024</span>
                                        <span><i class="bi bi-check-circle me-1"></i>Completed (100%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-completed">Completed</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="View History">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="document-item" data-status="completed">
                                <input class="form-check-input document-checkbox" type="checkbox" data-doc="doc-009"
                                    data-event="scholarship">
                                <div class="document-icon doc">
                                    <i class="bi bi-file-earmark-word"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="document-name">Application Review Process.docx</div>
                                    <div class="document-meta">
                                        <span><i class="bi bi-hdd me-1"></i>0.9 MB</span>
                                        <span><i class="bi bi-calendar me-1"></i>Mar 20, 2024</span>
                                        <span><i class="bi bi-check-circle me-1"></i>Completed (100%)</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="status-badge status-completed">Completed</div>
                                    <div class="d-flex gap-2">
                                        <button class="action-btn view" title="View Document">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="action-btn download" title="Download">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="action-btn track" title="View History">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enhanced Tracker Interface -->
            <div class="system-card" id="tracker-interface" style="display: none;">
                <!-- Tracker Header -->
                <div class="system-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="display-6 fw-bold mb-2">
                                <i class="bi bi-graph-up me-3"></i>
                                Document Tracking Dashboard
                            </h1>
                            <p class="lead mb-0 opacity-90">
                                Monitoring progress for <span id="tracking-count" class="fw-bold">0</span> selected
                                documents
                            </p>
                        </div>
                        <button type="button" class="btn btn-enhanced btn-outline-enhanced" id="back-to-management">
                            <i class="bi bi-arrow-left me-2"></i>Back to Documents
                        </button>
                    </div>
                </div>

                <!-- Overall Progress -->
                <div class="tracker-section">
                    <div class="overall-progress-card">
                        <div class="large-progress-circle" id="tracker-progress-circle">
                            <div class="large-progress-text" id="tracker-progress-text">0%</div>
                        </div>
                        <h4 class="fw-bold mb-2">Overall Progress</h4>
                        <p class="text-muted mb-0" id="tracker-progress-desc">0 of 0 documents completed</p>
                    </div>

                    <!-- Documents Being Tracked -->
                    <div class="row">
                        <div class="col-12">
                            <div class="system-card mb-4">
                                <div class="p-4">
                                    <h5 class="fw-bold mb-3">
                                        <i class="bi bi-list-check me-2"></i>Documents Being Tracked
                                    </h5>
                                    <div id="tracking-documents-list">
                                        <!-- Dynamic content will be inserted here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Office Progress Grid -->
                    <div class="system-card mb-4">
                        <div class="p-4">
                            <h5 class="fw-bold mb-4 text-center">
                                <i class="bi bi-building me-2"></i>Signatory Workflow Progress
                            </h5>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                This shows the collective progress of all selected documents through the signatory
                                workflow.
                                Individual document progress may vary.
                            </div>
                            <div class="row g-3" id="office-progress-grid">
                                <!-- Dynamic content will be generated here -->
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="text-center">
                        <div class="d-flex gap-3 justify-content-center flex-wrap">
                            <button type="button" class="btn btn-enhanced btn-primary-enhanced"
                                id="refresh-tracking-btn">
                                <i class="bi bi-arrow-clockwise me-2"></i>Refresh All Status
                            </button>
                            <button type="button" class="btn btn-enhanced btn-outline-enhanced"
                                id="export-tracking-btn">
                                <i class="bi bi-download me-2"></i>Export Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Toast Utils -->
    <script src="../assets/js/toast.js"></script>

    <!-- Custom JavaScript -->
    <script src="../assets/js/track-document.js"></script> <!-- Updated path -->
</body>

</html>