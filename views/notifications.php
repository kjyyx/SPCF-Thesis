<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth(); // Requires login

// Get current user first to check role
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
if (!$currentUser) {
    logoutUser();
    header('Location: ' . BASE_URL . 'login');
    exit();
}

// Define constants for better maintainability
const ALLOWED_ROLES = ['employee', 'student'];

// Allow employees and all students (case insensitive)
$userHasAccess = in_array(strtolower($currentUser['role'] ?? ''), ALLOWED_ROLES);

// Function to check if student has pending signatures
function hasPendingSignatures($userId) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM document_steps ds
            JOIN documents d ON ds.document_id = d.id
            WHERE ds.assignee_type = 'student' 
            AND ds.assignee_id = ? 
            AND ds.status = 'pending'
            AND d.status != 'rejected'
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking pending signatures: " . $e->getMessage());
        return false;
    }
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Audit log helper function with improved error handling
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $currentUser;

    if (!$currentUser) {
        error_log("Audit log failed: No current user");
        return false;
    }

    try {
        $db = new Database();
        $conn = $db->getConnection();

        // Validate inputs
        $action = trim($action);
        $category = trim($category);
        $details = trim($details);
        $severity = in_array(strtoupper($severity), ['INFO', 'WARNING', 'ERROR', 'CRITICAL']) ? strtoupper($severity) : 'INFO';

        if (empty($action) || empty($category) || empty($details)) {
            throw new Exception("Invalid audit log parameters");
        }

        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $result = $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            trim($currentUser['first_name'] . ' ' . $currentUser['last_name']),
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        return $result;
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
        return false;
    }
}

// Log page view with error handling
addAuditLog('NOTIFICATIONS_VIEWED', 'Notifications', 'Viewed notifications page', $currentUser['id'], 'User', 'INFO');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
    <title>Sign-um - Document Notifications</title>
    <meta name="description" content="Modern document notification and digital signature system">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/event-calendar.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/notifications.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/toast.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/global-notifications.css"><script>
        // Provide user data to JS (employee-only)
        window.currentUser = <?php
        $jsUser = [
            'id' => $currentUser['id'],
            'firstName' => $currentUser['first_name'],
            'lastName' => $currentUser['last_name'],
            'role' => $currentUser['role'],
            'email' => $currentUser['email']
        ];
        echo json_encode($jsUser);
        ?>;
        window.BASE_URL = "<?php echo BASE_URL; ?>";
    </script>
</head>

<body class="with-fixed-navbar" data-user-role="<?php echo htmlspecialchars($currentUser['role']); ?>">
    <?php
    // Set page title for navbar
    $pageTitle = 'Document Notifications';
    $currentPage = 'notifications';
    include ROOT_PATH . 'includes/navbar.php';
    include ROOT_PATH . 'includes/notifications.php';
    ?>

    <div class="main-content">
        <div class="page-header-section">
            <div class="container-fluid">
                <div class="page-header-content">
                    <div class="header-main">
                        <button class="back-button" onclick="history.back()" title="Go Back">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <div class="header-icon">
                            <i class="bi bi-bell-fill"></i>
                        </div>
                        <div class="header-text">
                            <h1 class="page-title">Document Notifications</h1>
                            <p class="page-subtitle">Track and manage all your document approvals, rejections, and signing workflows</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="notification-header-modern">
            <div class="container-fluid">
                <div class="header-modern-wrapper">
                    <div class="header-controls-row" style="gap: 2rem; align-items: flex-start;">
                        <div class="stats-cards-modern" style="margin-right: 1.5rem;">
                            <div class="stat-card all" onclick="filterDocuments('all')" role="button" tabindex="0">
                                <div class="stat-icon">
                                    <i class="bi bi-files"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value" id="totalCount">0</div>
                                    <div class="stat-label">Total</div>
                                </div>
                            </div>
                            <div class="stat-card pending" onclick="filterDocuments('submitted')" role="button" tabindex="0">
                                <div class="stat-icon">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value" id="submittedCount">0</div>
                                    <div class="stat-label">Newly Submitted</div>
                                </div>
                            </div>
                            <div class="stat-card in-review" onclick="filterDocuments('in_progress')" role="button" tabindex="0">
                                <div class="stat-icon">
                                    <i class="bi bi-eye-fill"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value" id="inReviewCount">0</div>
                                    <div class="stat-label">Under Review</div>
                                </div>
                            </div>
                            <div class="stat-card approved" onclick="filterDocuments('approved')" role="button" tabindex="0">
                                <div class="stat-icon">
                                    <i class="bi bi-check-circle-fill"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value" id="approvedCount">0</div>
                                    <div class="stat-label">Fully Approved</div>
                                </div>
                            </div>
                        </div>

                        <div class="search-box-modern" style="flex: 1; max-width: 600px;">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="search-input" id="documentSearch"
                                placeholder="Search documents by title, type, or department..."
                                aria-label="Search documents">
                            <button class="clear-search-btn" type="button" id="clearSearch"
                                aria-label="Clear search" style="display: none;">
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>

                        <div class="sort-dropdown-modern">
                            <button class="sort-trigger" id="sortTrigger">
                                <i class="bi bi-funnel"></i>
                                <span class="sort-label">Sort</span>
                                <i class="bi bi-chevron-down"></i>
                            </button>
                            <div class="sort-menu" id="sortMenu" style="display: none;">
                                <div class="sort-menu-header">Sort by</div>
                                <button class="sort-option" data-sort="due_desc">
                                    <i class="bi bi-clock-history"></i>
                                    <span>Due Date (Soonest)</span>
                                    <i class="bi bi-check-circle-fill sort-check"></i>
                                </button>
                                <button class="sort-option" data-sort="due_asc">
                                    <i class="bi bi-clock-history"></i>
                                    <span>Due Date (Latest)</span>
                                    <i class="bi bi-check-circle-fill sort-check"></i>
                                </button>
                                <button class="sort-option" data-sort="name_asc">
                                    <i class="bi bi-sort-alpha-down"></i>
                                    <span>Name (A-Z)</span>
                                    <i class="bi bi-check-circle-fill sort-check"></i>
                                </button>
                                <button class="sort-option" data-sort="name_desc">
                                    <i class="bi bi-sort-alpha-up"></i>
                                    <span>Name (Z-A)</span>
                                    <i class="bi bi-check-circle-fill sort-check"></i>
                                </button>
                            </div>
                        </div>

                        <div class="group-dropdown-modern">
                            <button class="group-trigger" id="groupTrigger">
                                <i class="bi bi-folder"></i>
                                <span class="group-label">Group</span>
                                <i class="bi bi-chevron-down"></i>
                            </button>
                            <div class="group-menu" id="groupMenu" style="display: none;">
                                <div class="group-menu-header">Group by</div>
                                <button class="group-option active" data-group="none">
                                    <i class="bi bi-list-ul"></i>
                                    <span>No Grouping</span>
                                    <i class="bi bi-check-circle-fill group-check"></i>
                                </button>
                                <button class="group-option" data-group="doc_type">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span>Document Type</span>
                                    <i class="bi bi-check-circle-fill group-check"></i>
                                </button>
                                <button class="group-option" data-group="department">
                                    <i class="bi bi-building"></i>
                                    <span>Department</span>
                                    <i class="bi bi-check-circle-fill group-check"></i>
                                </button>
                                <button class="group-option" data-group="status">
                                    <i class="bi bi-flag"></i>
                                    <span>Status</span>
                                    <i class="bi bi-check-circle-fill group-check"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Enhanced sort and group dropdown functionality (Refactored to map to new JS Managers)
        document.addEventListener('DOMContentLoaded', function() {
            const sortTrigger = document.getElementById('sortTrigger');
            const sortMenu = document.getElementById('sortMenu');
            const sortOptions = document.querySelectorAll('.sort-option');
            const groupTrigger = document.getElementById('groupTrigger');
            const groupMenu = document.getElementById('groupMenu');
            const searchInput = document.getElementById('documentSearch');
            const clearSearchBtn = document.getElementById('clearSearch');
            const sortLabel = document.querySelector('#sortTrigger .sort-label');

            function syncSortUi(sortValue) {
                let selected = null;
                sortOptions.forEach(opt => {
                    const isActive = opt.dataset.sort === sortValue;
                    opt.classList.toggle('active', isActive);
                    if (isActive) selected = opt;
                });
                if (sortLabel && selected) {
                    const text = selected.querySelector('span')?.textContent?.trim();
                    if (text) sortLabel.textContent = text;
                }
            }
            
            // Toggle sort menu
            if (sortTrigger) {
                sortTrigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sortMenu.style.display = sortMenu.style.display === 'none' ? 'block' : 'none';
                    if (groupMenu) groupMenu.style.display = 'none'; // Close group menu
                });
            }
            
            // Toggle group menu
            if (groupTrigger) {
                groupTrigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    groupMenu.style.display = groupMenu.style.display === 'none' ? 'block' : 'none';
                    if (sortMenu) sortMenu.style.display = 'none'; // Close sort menu
                });
            }
            
            // Close menus when clicking outside
            document.addEventListener('click', function() {
                if (sortMenu) sortMenu.style.display = 'none';
                if (groupMenu) groupMenu.style.display = 'none';
            });
            
            // Sort option selection
            sortOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const sortValue = this.dataset.sort;
                    syncSortUi(sortValue);
                    
                    if (window.documentSystem) {
                        window.documentSystem.sortOption = sortValue;
                        window.documentSystem.applyFiltersAndSearch();
                    }
                    if (sortMenu) sortMenu.style.display = 'none';
                });
            });

            // Initialize sort label/selection from persisted value
            const initialSort = localStorage.getItem('notifications_sortOption') || 'date_desc';
            syncSortUi(initialSort);
            
            // Search input clear button
            if (searchInput && clearSearchBtn) {
                searchInput.addEventListener('input', function() {
                    clearSearchBtn.style.display = this.value ? 'flex' : 'none';
                });
                
                clearSearchBtn.addEventListener('click', function() {
                    searchInput.value = '';
                    this.style.display = 'none';
                    if (window.documentSystem) {
                        window.documentSystem.searchTerm = '';
                        window.documentSystem.applyFiltersAndSearch();
                    }
                });
            }
        });
        </script>

        <div id="dashboardView" class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="documents-list-wrapper">
                        <div class="list-header">
                            <div class="list-header-left">
                                <h3 class="list-title">
                                    <i class="bi bi-file-earmark-text me-2"></i>
                                    Documents
                                </h3>
                                <span class="document-count" id="documentCount">0 documents</span>
                            </div>
                        </div>
                        
                        <div class="documents-list-container" id="documentsContainer">
                            </div>
                        
                        <div class="empty-state" id="emptyState" style="display: none;">
                            <div class="empty-icon">
                                <i class="bi bi-inbox"></i>
                            </div>
                            <h4 class="empty-title">No Documents Found</h4>
                            <p class="empty-message">There are no documents matching your current filters.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="documentView" class="document-detail" style="display: none;">
        <div class="document-header">
            <div class="container-fluid">
                <div class="header-content">
                    <div class="header-left">
                        <button class="back-btn" onclick="goBack()" title="Back to Dashboard">
                            <i class="bi bi-arrow-left"></i>
                            <span>Back to Documents</span>
                        </button>
                        <div class="document-info">
                            <h2 id="docTitle" class="document-title">Document Title</h2>
                            <div class="document-meta">
                                <span id="docStatus" class="status-badge">Status</span>
                                <span class="meta-separator">•</span>
                                <span id="pdfFileName" class="file-name">Document.pdf</span>
                            </div>
                        </div>
                    </div>
                    <div class="header-actions">
                        <button class="action-btn primary" onclick="downloadPDF()" title="Download PDF">
                            <i class="bi bi-download"></i>
                            <span>Download</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="document-layout">
            <div class="container-fluid">
                <div class="layout-grid">
                    <div class="pdf-section">
                        <div class="pdf-viewer-modern">
                            <div class="pdf-toolbar" style="display: flex; justify-content: space-between; padding: 10px; background: #fff; border-bottom: 1px solid #e5e7eb;">
                                <div class="toolbar-group d-flex align-items-center">
                                    <div class="page-controls d-flex align-items-center gap-2">
                                        <button class="tool-btn btn btn-sm btn-light" onclick="documentSystem.pdfViewer.prevPage(documentSystem.currentDocument)" id="prevPageBtn" title="Previous Page">
                                            <i class="bi bi-chevron-left"></i>
                                        </button>
                                        <div class="page-info d-flex align-items-center gap-1">
                                            <input type="number" id="pageInput" min="1" onchange="documentSystem.pdfViewer.goToPage(this.value, documentSystem.currentDocument)" class="form-control form-control-sm text-center" style="width: 50px;" title="Current Page" />
                                            <span class="page-separator text-muted">/</span>
                                            <span id="pageTotal" class="page-total fw-bold">1</span>
                                        </div>
                                        <button class="tool-btn btn btn-sm btn-light" onclick="documentSystem.pdfViewer.nextPage(documentSystem.currentDocument)" id="nextPageBtn" title="Next Page">
                                            <i class="bi bi-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="toolbar-group d-flex align-items-center gap-2">
                                    <!-- <button class="tool-btn btn btn-sm btn-light" onclick="documentSystem.pdfViewer.zoomOut(documentSystem.currentDocument)" id="zoomOutBtn" title="Zoom Out">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <div class="zoom-info" style="min-width: 50px; text-align: center; font-size: 13px; font-weight: 600;">
                                        <span id="zoomIndicator" class="zoom-level">100%</span>
                                    </div>
                                    <button class="tool-btn btn btn-sm btn-light" onclick="documentSystem.pdfViewer.zoomIn(documentSystem.currentDocument)" id="zoomInBtn" title="Zoom In">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                    
                                    <div class="border-start mx-1" style="height: 24px;"></div> -->

                                    <button class="tool-btn btn btn-sm btn-light" onclick="documentSystem.pdfViewer.fitToWidth(documentSystem.currentDocument)" title="Fit to Width">
                                        <i class="bi bi-arrows-angle-expand"></i>
                                    </button>
                                    <button class="tool-btn btn btn-sm btn-light" onclick="documentSystem.pdfViewer.openFullViewer(documentSystem.currentDocument)" title="View Full Document">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="pdf-canvas-container" id="pdfContent" style="position: relative; overflow: auto; text-align: center; background-color: #f3f4f6; min-height: 500px; padding: 20px;">
                                <div id="pdfLoading" class="pdf-loading-modern" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                                    <div class="spinner-border text-primary mb-3" role="status"></div>
                                    <p class="loading-text text-muted">Loading document...</p>
                                </div>
                                
                                <canvas id="pdfCanvas" style="display: none; margin: 0 auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="actions-sidebar">
                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-lightning-charge"></i>
                                    Quick Actions
                                </h3>
                            </div>
                            <div class="card-content">
                                <div class="action-buttons">
                                    <button class="action-btn-full success" onclick="signDocument()"
                                        title="Sign this document">
                                        <div class="btn-icon">
                                            <i class="bi bi-pen-fill"></i>
                                        </div>
                                        <div class="btn-content">
                                            <span class="btn-title">Sign Document</span>
                                            <span class="btn-subtitle">Apply your signature</span>
                                        </div>
                                        <i class="bi bi-chevron-right btn-arrow"></i>
                                    </button>

                                    <button class="action-btn-full danger" onclick="showRejectModal()"
                                        title="Reject this document">
                                        <div class="btn-icon">
                                            <i class="bi bi-x-circle"></i>
                                        </div>
                                        <div class="btn-content">
                                            <span class="btn-title">Reject Document</span>
                                            <span class="btn-subtitle">Provide rejection reason</span>
                                        </div>
                                        <i class="bi bi-chevron-right btn-arrow"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-pen"></i>
                                    Signature Status
                                </h3>
                            </div>
                            <div class="card-content">
                                <div class="signature-status" id="signatureStatusContainer">
                                    <div class="signature-placeholder" id="signaturePlaceholder">
                                        <div class="placeholder-icon">
                                            <i class="bi bi-pen"></i>
                                        </div>
                                        <div class="placeholder-text">
                                            <span class="status-title">Ready to Sign</span>
                                            <span class="status-subtitle">Click above to add your signature</span>
                                        </div>
                                    </div>

                                    <div class="signed-status d-none" id="signedStatus">
                                        <div class="status-icon success">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </div>
                                        <div class="status-text">
                                            <span class="status-title">Document Signed</span>
                                            <span class="status-subtitle">Signature applied successfully</span>
                                        </div>
                                    </div>
                                </div>

                                <button class="signature-pad-toggle" onclick="toggleSignaturePad()"
                                    id="signaturePadToggle">
                                    <i class="bi bi-pencil-square me-2"></i>
                                    Open Signature Pad
                                </button>

                                <div id="signaturePadContainer" class="signature-pad-modern d-none">
                                    <div class="signature-header">
                                        <span class="signature-title">Add Your Signature</span>
                                        <button class="close-signature" onclick="toggleSignaturePad()">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                    <div class="signature-upload-section">
                                        <label for="signatureUpload" class="upload-label">
                                            <i class="bi bi-upload me-2"></i>
                                            Upload Signature Image
                                        </label>
                                        <input type="file" id="signatureUpload" accept="image/*"
                                            class="signature-upload-input">
                                        <div class="upload-hint">
                                            <i class="bi bi-info-circle"></i>
                                            Upload a PNG, JPG, or GIF image of your signature
                                        </div>
                                    </div>
                                    <div class="signature-divider">
                                        <span>or</span>
                                    </div>
                                    <div class="signature-draw-section">
                                        <div class="draw-header">Draw Your Signature</div>
                                        <div class="signature-canvas-wrapper">
                                            <canvas id="signatureCanvas" class="signature-canvas"></canvas>
                                        </div>
                                    </div>
                                    <div class="signature-actions">
                                        <button type="button" class="btn-signature clear" id="sigClearBtn">
                                            <i class="bi bi-eraser"></i>
                                            Clear
                                        </button>
                                        <button type="button" class="btn-signature save" id="sigSaveBtn">
                                            <i class="bi bi-check2"></i>
                                            Use Signature
                                        </button>
                                    </div>
                                    <div class="signature-hint">
                                        <i class="bi bi-info-circle"></i>
                                        Upload an image or draw your signature using your mouse or touch device
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-journal-text"></i>
                                    Notes & Comments
                                </h3>
                            </div>
                            <div class="card-content">
                                <div class="comments-container">
                                    <div id="threadCommentsList" class="thread-comments-list"></div>

                                    <div id="commentReplyBanner" class="comment-reply-banner" style="display:none;">
                                        <div class="reply-banner-text">
                                            Replying to <span id="replyAuthorName"></span>
                                        </div>
                                        <button type="button" class="reply-cancel-btn" onclick="documentSystem.commentsManager.clearReplyTarget()">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>

                                    <textarea class="notes-input" id="threadCommentInput" rows="3"
                                        placeholder="Write a comment..."></textarea>

                                    <div class="notes-actions">
                                        <span id="notesSaveIndicator"></span>
                                        <button class="btn-notes save" id="postCommentBtn" onclick="documentSystem.commentsManager.postComment()">
                                            <i class="bi bi-send"></i>
                                            Post Comment
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-diagram-3"></i>
                                    Approval Hierarchy
                                </h3>
                                <p class="card-subtitle">Document routing through organizational levels</p>
                            </div>
                            <div class="card-content">
                                <div id="workflowSteps" class="workflow-timeline">
                                    </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-key me-2"></i>Change Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="changePasswordForm">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-body">
                        <div id="changePasswordMessages"></div>
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label">Current Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="currentPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('currentPassword')">
                                    <i class="bi bi-eye" id="currentPasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="newPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('newPassword')">
                                    <i class="bi bi-eye" id="newPasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="confirmPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('confirmPassword')">
                                    <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="profileSettingsModal" tabindex="-1" aria-labelledby="profileSettingsLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileSettingsLabel">
                        <i class="bi bi-person-gear me-2"></i>Profile Settings
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="profileSettingsForm">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-body">
                        <div id="profileSettingsMessages"></div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="profileFirstName" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="profileFirstName" required <?php if ($currentUser['role'] !== 'admin') echo 'readonly'; ?>>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="profileLastName" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="profileLastName" required <?php if ($currentUser['role'] !== 'admin') echo 'readonly'; ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="profileEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="profileEmail" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" required>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        <div class="mb-3">
                            <label for="profilePhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="profilePhone" pattern="^(09|\+639)\d{9}$">
                            <div class="invalid-feedback">Please enter a valid Philippine phone number (e.g., 09123456789 or +639123456789).</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="preferencesModal" tabindex="-1" aria-labelledby="preferencesLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="preferencesLabel">
                        <i class="bi bi-sliders me-2"></i>Preferences
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="preferencesMessages"></div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="emailNotifications">
                            <label class="form-check-label" for="emailNotifications">
                                Email Notifications
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="browserNotifications">
                            <label class="form-check-label" for="browserNotifications">
                                Browser Notifications
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="defaultView" class="form-label">Default View</label>
                        <select class="form-select" id="defaultView">
                            <option value="month">Month View</option>
                            <option value="week">Week View</option>
                            <option value="agenda">Agenda View</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="helpLabel">
                        <i class="bi bi-question-circle me-2"></i>Help & Support
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="helpAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#gettingStarted">
                                    Getting Started
                                </button>
                            </h2>
                            <div id="gettingStarted" class="accordion-collapse collapse show"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Welcome to the Document Notifications system! Here you can:</p>
                                    <ul>
                                        <li>View documents requiring your approval or signature</li>
                                        <li>Track document status and workflow progress</li>
                                        <li>Sign documents electronically</li>
                                        <li>Communicate with other approvers</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#documentWorkflow">
                                    Document Workflow
                                </button>
                            </h2>
                            <div id="documentWorkflow" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Documents follow a sequential approval process:</p>
                                    <ol>
                                        <li><strong>Draft:</strong> Document is created but not yet submitted for approval</li>
                                        <li><strong>Newly Submitted:</strong> Document is uploaded and pending initial review
                                        </li>
                                        <li><strong>Under Review:</strong> Document is being reviewed by assigned personnel
                                        </li>
                                        <li><strong>Action Required:</strong> Workflow is paused due to a 7-day signer timeout</li>
                                        <li><strong>Fully Approved:</strong> Document has been approved and signed</li>
                                        <li><strong>Declined:</strong> Document was rejected (requires revision)</li>
                                        <li><strong>Cancelled:</strong> Document was withdrawn by the student or an administrator</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#signingDocuments">
                                    Signing Documents
                                </button>
                            </h2>
                            <div id="signingDocuments" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>To sign a document:</p>
                                    <ol>
                                        <li>Open the document from your dashboard</li>
                                        <li>Click the signature placeholder area</li>
                                        <li>Draw your signature using the signature pad</li>
                                        <li>Click "Apply Signature" to place it on the document</li>
                                        <li>Click "Sign Document" to complete the approval</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#notifications">
                                    Notifications
                                </button>
                            </h2>
                            <div id="notifications" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>You will receive notifications for:</p>
                                    <ul>
                                        <li>New documents requiring your attention</li>
                                        <li>Document status changes</li>
                                        <li>Upcoming deadlines</li>
                                        <li>Messages from other approvers</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#troubleshooting">
                                    Troubleshooting
                                </button>
                            </h2>
                            <div id="troubleshooting" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p><strong>Common Issues:</strong></p>
                                    <ul>
                                        <li><strong>Document won't load:</strong> Check your internet connection and try
                                            refreshing</li>
                                        <li><strong>Signature not saving:</strong> Ensure you've drawn a complete
                                            signature</li>
                                        <li><strong>Notifications not showing:</strong> Check your browser notification
                                            settings</li>
                                        <li><strong>Access denied:</strong> Contact your administrator for permission
                                            issues</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationsModalLabel">
                        <i class="bi bi-bell-fill"></i>
                        <span>Notifications</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="notificationsList">
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAllAsRead()">
                        <i class="bi bi-check-all me-2"></i>Mark All Read
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectDocumentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-x-circle me-2"></i>Reject Document
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="rejectDocumentForm" autocomplete="off">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejectReason" class="form-label">Reason for rejection</label>
                            <textarea class="form-control" id="rejectReason" rows="3" required
                                placeholder="Enter reason..."></textarea>
                        </div>
                        <div id="rejectError" class="text-danger small d-none"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmModalMessage">Are you sure you want to proceed?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmModalBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="fullDocumentModal" tabindex="-1">
        <div class="modal-dialog modal-lg" style="max-width: 90vw; max-height: 90vh;">
            <div class="modal-content" style="height: 85vh;">
                <div class="modal-header">
                    <h5 class="modal-title">Full Document View</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding: 0; height: calc(100% - 60px); overflow: hidden;">
                    <div id="fullPdfToolbar" class="pdf-toolbar">
                        <div class="toolbar-group">
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.fullPrevPage(documentSystem.currentDocument)" id="fullPrevPageBtn" title="Previous Page">
                                <i class="bi bi-chevron-left"></i>
                            </button>
                            <div class="page-info">
                                <input type="number" id="fullPageInput" min="1" onchange="documentSystem.pdfViewer.fullGoToPage(this.value, documentSystem.currentDocument)" class="page-input" title="Current Page" />
                                <span class="page-separator">/</span>
                                <span id="fullPageTotal" class="page-total">1</span>
                            </div>
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.fullNextPage(documentSystem.currentDocument)" id="fullNextPageBtn" title="Next Page">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                        <div class="toolbar-group">
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.fullZoomOut(documentSystem.currentDocument)" title="Zoom Out">
                                <i class="bi bi-dash"></i>
                            </button>
                            <div class="zoom-info">
                                <span id="fullZoomIndicator" class="zoom-level">100%</span>
                            </div>
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.fullZoomIn(documentSystem.currentDocument)" title="Zoom In">
                                <i class="bi bi-plus"></i>
                            </button>
                            <div class="toolbar-separator"></div>
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.fullFitToWidth(documentSystem.currentDocument)" title="Fit to Width">
                                <i class="bi bi-arrows-angle-expand"></i>
                            </button>
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.fullResetZoom(documentSystem.currentDocument)" title="Reset Zoom">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <div class="toolbar-separator"></div>
                            <button class="tool-btn" onclick="documentSystem.pdfViewer.toggleDragMode()" id="dragToggleBtn" title="Toggle Drag Mode">
                                <i class="bi bi-hand-index"></i>
                            </button>
                        </div>
                    </div>
                    <div id="fullPdfContent" class="pdf-content-full" style="overflow: auto; height: calc(100% - 50px); cursor: grab;">
                        <div id="fullPdfContainer" style="position: relative; min-width: 100%; min-height: 100%; display: flex; align-items: flex-start; justify-content: center;">
                            <canvas id="fullPdfCanvas" style="max-width: none; image-rendering: -webkit-optimize-contrast;"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/ui-helpers.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/comments-manager.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/signature-manager.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/pdf-viewer.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/notifications.js"></script>

    <script>
        // Pass user data to JavaScript
        window.currentUser = <?php
        // Convert snake_case to camelCase for JavaScript
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['position'] = $currentUser['position'] ?? '';
        echo json_encode($jsUser);
        ?>;
    </script>

    <script>
        // Navbar handlers are centralized in assets/js/navbar-settings.js
        if (window.NavbarSettings) {
            window.openProfileSettings = window.NavbarSettings.openProfileSettings;
            window.openChangePassword = window.NavbarSettings.openChangePassword;
            window.openPreferences = window.NavbarSettings.openPreferences;
            window.showHelp = window.NavbarSettings.showHelp;
            window.savePreferences = window.NavbarSettings.savePreferences;
            window.saveProfileSettings = window.NavbarSettings.saveProfileSettings;
        }

        // ----------------------------------------------------------------------
        // UI WRAPPER FUNCTIONS (Connecting HTML elements to the JS Controllers)
        // ----------------------------------------------------------------------

        function filterDocuments(status) {
            if (window.documentSystem) {
                window.documentSystem.filterDocuments(status);
            }
        }

        function goBack() {
            if (window.documentSystem) {
                window.documentSystem.goBack();
            }
        }

        function signDocument() {
            if (window.documentSystem && window.documentSystem.currentDocument) {
                window.documentSystem.signDocument(window.documentSystem.currentDocument.id);
            } else if (window.ToastManager) {
                window.ToastManager.show({ type: 'warning', title: 'Notice', message: 'Please open a document first.' });
            }
        }

        function toggleSignaturePad() {
            const pad = document.getElementById('signaturePadContainer');
            if (!pad) return;
            pad.classList.toggle('d-none');
            
            // Delegate initialization to the new signature manager
            if (!pad.classList.contains('d-none') && window.documentSystem && window.documentSystem.signatureManager) {
                window.documentSystem.signatureManager.initSignaturePad();
            }
        }

        function applySignature() { 
            toggleSignaturePad(); 
        }

        function downloadPDF() {
            if (window.documentSystem && window.documentSystem.currentDocument) {
                let filePath = window.documentSystem.currentDocument.file_path;
                if (filePath.startsWith('/')) {
                    filePath = window.BASE_URL + filePath.substring(1);
                } else if (filePath.startsWith('../')) {
                    filePath = window.BASE_URL + filePath.substring(3);
                } else if (filePath.startsWith('SPCF-Thesis/')) {
                    filePath = window.BASE_URL + filePath.substring(12);
                } else if (filePath.startsWith('http')) {
                    // Full URL
                } else {
                    filePath = window.BASE_URL + 'uploads/' + filePath;
                }
                const link = document.createElement('a');
                link.href = filePath;
                link.download = window.documentSystem.currentDocument.title + '.pdf';
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                if (window.ToastManager) {
                    window.ToastManager.show({ type: 'success', title: 'Download', message: 'PDF download started.' });
                }
            } else {
                if (window.ToastManager) {
                    window.ToastManager.show({ type: 'warning', title: 'Notice', message: 'Please open a document first.' });
                }
            }
        }

        // Reject modal functions
        function showRejectModal() {
            const modal = new bootstrap.Modal(document.getElementById('rejectDocumentModal'));
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectError').classList.add('d-none');
            modal.show();
        }

        // Handle reject form submit
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('rejectDocumentForm');
            if (form) {
                form.onsubmit = function (e) {
                    e.preventDefault();
                    const reason = document.getElementById('rejectReason').value.trim();
                    const errorDiv = document.getElementById('rejectError');
                    if (!reason) {
                        errorDiv.textContent = 'Please provide a reason for rejection.';
                        errorDiv.classList.remove('d-none');
                        return;
                    }
                    errorDiv.classList.add('d-none');
                    
                    // Call the JS controller to reject
                    if (window.documentSystem && window.documentSystem.currentDocument) {
                        window.documentSystem.rejectDocument(window.documentSystem.currentDocument.id, reason);
                    }
                    bootstrap.Modal.getInstance(document.getElementById('rejectDocumentModal')).hide();
                };
            }
        });

        // Handle profile settings form
        document.addEventListener('DOMContentLoaded', function () {
            const profileForm = document.getElementById('profileSettingsForm');
            if (profileForm) {
                profileForm.addEventListener('submit', async function (e) {
                    if (window.NavbarSettings?.saveProfileSettings) {
                        await window.NavbarSettings.saveProfileSettings(e);
                    } else {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>

    <script>
        // Initialize Application
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const signDocId = urlParams.get('sign_doc');
            
            if (signDocId) {
                window.documentSystem.openDocument(signDocId);
            }
        });
    </script>
</body>

</html>
