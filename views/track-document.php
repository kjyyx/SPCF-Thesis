<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth(); // Requires login

// Get current user
$currentUser = getCurrentUser();

if (!$currentUser) {
    logoutUser();
    header('Location: ' . BASE_URL . '?page=login');
    exit();
}

// Restrict to students and admins only (employees cannot access)
if ($currentUser['role'] !== 'student' && $currentUser['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '?page=login&error=access_denied');
    exit();
}

// Restrict Accounting employees to only SAF access
if ($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) {
    header('Location: ' . BASE_URL . 'saf');
    exit();
}

// Set page title for navbar
$pageTitle = 'Track Documents';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="<?php echo BASE_URL; ?>assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Document Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css"><link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/track-document.css"><script>
        window.currentUser = <?php
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
        echo json_encode($jsUser);
        ?>;
        window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;
        window.BASE_URL = "<?php echo BASE_URL; ?>";
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>

<body class="has-navbar">
    <?php include ROOT_PATH . 'includes/navbar.php'; ?>
    <?php include ROOT_PATH . 'includes/notifications.php'; ?>

    <div class="container pt-4 pb-5">
        <div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-file-earmark-text text-primary me-2"></i> Document Tracker
                </h1>
                <p class="page-subtitle">Monitor your document signing progress and approval status in real-time</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-ghost rounded-pill" onclick="history.back()" title="Go Back">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button class="btn btn-primary rounded-pill shadow-primary" onclick="window.location.href='<?php echo BASE_URL; ?>?page=notifications'">
                    <i class="bi bi-file-plus me-2"></i> New Document
                </button>
                <button class="btn btn-ghost rounded-pill" onclick="refreshDocuments()">
                    <i class="bi bi-arrow-clockwise me-2"></i> Refresh
                </button>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value" id="totalDocuments">0</div>
                    <div class="stat-label">Total Documents</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card warning">
                    <div class="stat-value" id="pendingDocuments">0</div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card info">
                    <div class="stat-value" id="inProgressDocuments">0</div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card success">
                    <div class="stat-value" id="approvedDocuments">0</div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="search-input-wrapper flex-1" style="min-width: 250px;">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="form-control sm" id="searchInput" placeholder="Search by name, type, or location...">
                </div>
                
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="nav-tabs-glass" role="group" aria-label="Status filter">
                        <input type="radio" class="btn-check" name="statusFilter" id="filterAll" checked>
                        <label class="nav-tab" for="filterAll">All</label>

                        <input type="radio" class="btn-check" name="statusFilter" id="filterPending">
                        <label class="nav-tab text-warning" for="filterPending">Pending</label>

                        <input type="radio" class="btn-check" name="statusFilter" id="filterApproved">
                        <label class="nav-tab text-success" for="filterApproved">Approved</label>

                        <input type="radio" class="btn-check" name="statusFilter" id="filterRejected">
                        <label class="nav-tab text-danger" for="filterRejected">Rejected</label>
                    </div>
                    <div class="divider m-0" style="height: 32px; width: 1px;"></div>
                    <button class="btn btn-ghost btn-sm rounded-pill" onclick="clearFilters()" title="Clear all filters">
                        Clear Filters
                    </button>
                </div>
            </div>
            
            <div class="card-footer bg-surface-sunken d-flex justify-content-between align-items-center py-2 px-4 border-top">
                <small class="text-muted fw-semibold" id="resultsCount">
                    <i class="bi bi-info-circle me-1"></i>Loading documents...
                </small>
                <small class="text-muted text-xs" id="lastUpdated">
                    Last updated: <span id="lastUpdatedTime">--</span>
                </small>
            </div>
        </div>

        <div class="card card-lg p-0 border-0 shadow-md overflow-hidden">
            <div class="table-wrapper border-0 shadow-none rounded-0">
                <table class="table table-hover mb-0" id="documentsTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="title">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Document</span> <i class="bi bi-chevron-expand"></i>
                                </div>
                            </th>
                            <th class="sortable" data-sort="document_type">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Type</span> <i class="bi bi-chevron-expand"></i>
                                </div>
                            </th>
                            <th class="sortable" data-sort="current_status">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Status</span> <i class="bi bi-chevron-expand"></i>
                                </div>
                            </th>
                            <th class="sortable" data-sort="current_location">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Office</span> <i class="bi bi-chevron-expand"></i>
                                </div>
                            </th>
                            <th class="sortable" data-sort="updated_at">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Updated</span> <i class="bi bi-chevron-expand"></i>
                                </div>
                            </th>
                            <th>Notes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="documentsList">
                        </tbody>
                </table>
            </div>

            <div id="loadingState" class="text-center py-5">
                <div class="loading-dots mb-3"><span></span><span></span><span></span></div>
                <h6 class="text-primary fw-bold">Loading Documents</h6>
                <p class="text-muted text-sm">Please wait while we fetch your documents...</p>
            </div>
            
            <div id="emptyState" class="text-center py-5" style="display: none;">
                <div class="empty-state-icon mb-3">
                    <i class="bi bi-inbox text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                </div>
                <h5 class="text-dark fw-bold">No Documents Found</h5>
                <p class="text-muted text-sm mb-4">You haven't submitted any documents yet or no items match your search.</p>
                <button class="btn btn-primary rounded-pill" onclick="window.location.href='<?php echo BASE_URL; ?>?page=create-document'">
                    <i class="bi bi-file-plus me-2"></i> Create Document
                </button>
            </div>

            <div class="card-footer bg-surface-sunken d-flex justify-content-between align-items-center" id="paginationContainer" style="display: none;">
                <span class="text-muted text-xs fw-medium" id="paginationInfo">Showing 1 to 10 of 0 documents</span>
                <div class="d-flex align-items-center gap-3">
                    <select class="form-select sm rounded-pill" id="itemsPerPage" style="width: auto;">
                        <option value="5">5 per page</option>
                        <option value="10" selected>10 per page</option>
                        <option value="25">25 per page</option>
                    </select>
                    <ul class="pagination m-0" id="pagination">
                        </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex flex-column gap-1">
                        <h5 class="modal-title m-0" id="documentModalTitle"><i class="bi bi-file-earmark-text me-2 text-primary"></i> Document Details</h5>
                        <div id="documentModalMeta" class="text-xs text-muted fw-medium mt-1"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-surface-muted" id="documentModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost rounded-pill me-auto" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success rounded-pill" id="downloadDocumentBtn" style="display: none;">
                        <i class="bi bi-download me-2"></i>Download PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Material Modal for Pubmats -->
    <div class="modal fade" id="materialModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex flex-column gap-1">
                        <h5 class="modal-title m-0" id="materialModalTitle"><i class="bi bi-image me-2 text-primary"></i> Publication Material</h5>
                        <div id="materialModalMeta" class="text-xs text-muted fw-medium mt-1"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-surface-muted" id="materialModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost rounded-pill me-auto" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success rounded-pill" id="downloadMaterialBtn" style="display: none;">
                        <i class="bi bi-download me-2"></i>Download
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="profileSettingsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Profile Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="profileSettingsForm">
                    <div class="modal-body d-flex flex-col gap-3">
                        <div id="profileSettingsMessages"></div>
                        <div class="row g-3">
                            <div class="col-md-6 form-group mb-0">
                                <label for="profileFirstName" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="profileFirstName" required>
                            </div>
                            <div class="col-md-6 form-group mb-0">
                                <label for="profileLastName" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="profileLastName" required>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="profileEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="profileEmail" required>
                        </div>
                        <div class="form-group mb-0">
                            <label for="profilePhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="profilePhone">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveProfileSettings()">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="preferencesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-sliders me-2"></i>Preferences</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-col gap-4">
                    <div id="preferencesMessages"></div>
                    <div>
                        <label class="form-label text-muted mb-2">System Behaviors</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                            <label class="form-check-label fw-medium ms-1" for="autoRefresh">Auto-refresh documents every 30s</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                            <label class="form-check-label fw-medium ms-1" for="emailNotifications">Email status notifications</label>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label for="itemsPerPagePref" class="form-label">Default Table Size</label>
                        <select class="form-select sm" id="itemsPerPagePref">
                            <option value="5">5 items per page</option>
                            <option value="10" selected>10 items per page</option>
                            <option value="25">25 items per page</option>
                        </select>
                    </div>
                    <input type="checkbox" id="showRejectedNotes" checked style="display:none;">
                    <input type="checkbox" id="compactView" checked style="display:none;">
                    <input type="checkbox" id="showStats" checked style="display:none;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i>Help & Support</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                   <div class="p-4 bg-surface-sunken">
                       <h6 class="fw-bold mb-2">Tracking Documents</h6>
                       <p class="text-sm text-muted mb-0">Select a document from the table to view its live status, read reviewer comments, check its current office location, or download the completed PDF once approved.</p>
                   </div>
                </div>
                <div class="modal-footer border-0">
                  <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/track-document.js"></script>
</body>
</html>