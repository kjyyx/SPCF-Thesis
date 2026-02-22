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
    header('Location: ' . BASE_URL . '?page=login');
    exit();
}

// Restrict to specific approver positions
$allowedPositions = ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'];
if ($currentUser['role'] !== 'employee' || !in_array($currentUser['position'] ?? '', $allowedPositions)) {
    header('Location: ' . BASE_URL . '?page=user-login');
    exit();
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity ?? 'INFO',
            $_SERVER['REMOTE_ADDR'] ?? null,
            null
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Log page view
addAuditLog('PUBMAT_APPROVALS_VIEWED', 'Approval Management', 'Viewed pubmat approvals page', $currentUser['id'], 'User', 'INFO');

$pageTitle = 'Pubmat Approvals';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="<?php echo BASE_URL; ?>assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Pubmat Approvals</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">

    <style>
        /* ── Pubmat Approvals — page-scoped styles ──────────── */

        /* Lift effect on material cards */
        .pubmat-card {
            transition: transform var(--duration-200) var(--ease-spring),
                        box-shadow var(--duration-200) var(--ease-out);
        }
        .pubmat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg) !important;
        }

        /* Preview thumbnail area */
        .pubmat-preview {
            height: 185px;
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-2xl) var(--radius-2xl) 0 0;
            background: var(--gray-100);
            flex-shrink: 0;
        }
        .pubmat-preview img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .pubmat-preview-placeholder {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            color: var(--gray-400);
        }
        .pubmat-preview-badge {
            position: absolute;
            top: var(--space-3);
            right: var(--space-3);
        }

        /* Meta row (author / date) */
        .pubmat-meta {
            display: flex; align-items: center; flex-wrap: wrap;
            gap: var(--space-2);
            font-size: var(--text-2xs);
            color: var(--color-text-tertiary);
        }

        /* Recent comment preview inside card */
        .pubmat-comment-preview {
            background: var(--gray-50);
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-md);
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-2xs);
            color: var(--color-text-secondary);
            line-height: var(--leading-relaxed);
        }

        /* Empty / loading state */
        .pubmat-empty {
            text-align: center;
            padding: var(--space-16) var(--space-8);
        }
        .pubmat-empty-icon {
            font-size: 3.5rem;
            color: var(--color-success-mid);
            opacity: 0.8;
            display: block;
            margin-bottom: var(--space-4);
        }
        .pubmat-empty h4 {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--color-text-heading);
            margin-bottom: var(--space-2);
        }
        .pubmat-empty p {
            font-size: var(--text-sm);
            color: var(--color-text-tertiary);
            margin: 0;
        }

        /* ── View Modal — file viewer ──────────────────────── */
        .viewer-wrap {
            background: var(--gray-100);
            min-height: 320px;
            display: flex; align-items: center; justify-content: center;
            overflow: auto;
            padding: var(--space-4);
        }

        /* ── Comments section ──────────────────────────────── */
        .comments-section {
            padding: var(--space-5) var(--space-6);
            background: var(--color-surface);
            border-top: 1px solid var(--color-border-subtle);
        }
        .comments-section-title {
            font-size: var(--text-base);
            font-weight: var(--font-semibold);
            color: var(--color-text-heading);
            display: flex; align-items: center; gap: var(--space-2);
            margin-bottom: var(--space-4);
        }
        .comments-list {
            max-height: 340px;
            overflow-y: auto;
            padding-right: var(--space-1);
        }
        .comment-item {
            background: var(--gray-50);
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-xl);
            padding: var(--space-3) var(--space-4);
            margin-bottom: var(--space-2);
        }
        .comment-item:last-child { margin-bottom: 0; }
        .comment-author-name {
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-text-label);
        }
        .comment-author-position {
            font-size: var(--text-2xs);
            color: var(--color-text-tertiary);
            margin-left: var(--space-2);
        }
        .comment-date {
            font-size: var(--text-2xs);
            color: var(--color-text-tertiary);
            margin-left: auto;
        }
        .comment-body {
            font-size: var(--text-xs);
            color: var(--color-text-secondary);
            line-height: var(--leading-body);
            margin-top: var(--space-2);
            margin-bottom: var(--space-2);
            white-space: pre-wrap;
            word-break: break-word;
        }
        .comment-reply-btn {
            background: none; border: none; padding: 0;
            font-size: var(--text-2xs);
            color: var(--brand-primary);
            cursor: pointer;
            display: inline-flex; align-items: center; gap: 4px;
            font-family: var(--font-sans);
        }
        .comment-reply-btn:hover { text-decoration: underline; }
        .comment-replies {
            margin-top: var(--space-2);
            margin-left: var(--space-6);
            border-left: 2px solid var(--color-border);
            padding-left: var(--space-3);
        }

        /* Reply-to banner */
        .reply-banner {
            background: var(--color-info-bg);
            border: 1px solid rgba(2,119,189,0.18);
            border-radius: var(--radius-md);
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-xs);
            color: var(--color-info);
            display: flex; align-items: center; gap: var(--space-2);
            margin-bottom: var(--space-3);
        }
        .reply-banner-close {
            background: none; border: none;
            margin-left: auto; padding: 0 0 0 var(--space-2);
            cursor: pointer; color: var(--color-info);
            font-size: 1rem; line-height: 1; opacity: 0.7;
        }
        .reply-banner-close:hover { opacity: 1; }

        /* Comment input area */
        .comment-input-area { margin-top: var(--space-4); }
        .comment-input-hint {
            font-size: var(--text-2xs);
            color: var(--color-text-tertiary);
            margin-top: var(--space-2);
        }

        /* Save indicator */
        .save-indicator {
            font-size: var(--text-xs);
            color: var(--color-success);
            display: flex; align-items: center; gap: var(--space-2);
            margin-top: var(--space-2);
        }

        /* ── Approval modal ─────────────────────────────────── */
        .approval-field-label {
            font-size: var(--text-2xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: var(--tracking-wider);
            color: var(--color-text-tertiary);
            margin-bottom: var(--space-2);
        }
    </style>

    <script>
        window.currentUser = <?php
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
        echo json_encode($jsUser);
        ?>;
        window.BASE_URL = "<?php echo BASE_URL; ?>";
    </script>
</head>

<body class="has-navbar bg-light">
    <?php include ROOT_PATH . 'includes/navbar.php'; ?>
    <?php include ROOT_PATH . 'includes/notifications.php'; ?>

    <div class="container pt-4 pb-5">
        
        <div class="page-header mb-4">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-file-earmark-check text-primary me-2"></i> Pubmat Approvals
                </h1>
                <p class="page-subtitle mb-0">Review and manage publication materials awaiting your approval.</p>
            </div>
            <div class="page-actions d-flex gap-2">
                <button class="btn btn-ghost border rounded-pill fw-medium shadow-sm px-4" onclick="loadMaterials()">
                    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                </button>
            </div>
        </div>

        <div id="materialsContainer" class="row g-4">
            <div class="col-12">
                <div class="pubmat-empty">
                    <div class="spinner-border text-primary" role="status" style="width: 2.5rem; height: 2.5rem;"></div>
                    <p style="margin-top: var(--space-4); font-size: var(--text-sm); color: var(--color-text-tertiary);">Loading materials...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval / Rejection Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-labelledby="approvalModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
            <div class="modal-content border-0 rounded-4" style="box-shadow: var(--shadow-xl);">
                <div class="modal-header border-0 px-5 pt-5 pb-2">
                    <h5 class="modal-title fw-bold" id="approvalModalTitle" style="font-size: var(--text-xl); color: var(--color-text-heading);">
                        Process Material
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-5 py-4">
                    <div class="approval-field-label">Note</div>
                    <textarea
                        class="form-control"
                        id="approvalNote"
                        rows="4"
                        placeholder="Enter your feedback or reason..."
                        style="border-radius: var(--radius-lg); background: var(--gray-50); border: 1px solid var(--color-border); resize: none; font-size: var(--text-sm); padding: var(--space-3) var(--space-4);"
                    ></textarea>
                    <p class="comment-input-hint mt-2">A reason is required when rejecting.</p>
                </div>
                <div class="modal-footer border-0 px-5 pb-5 pt-0 gap-2">
                    <button type="button" class="btn btn-ghost border" data-bs-dismiss="modal" style="margin-right: auto;">
                        Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="rejectBtn">
                        <i class="bi bi-x-circle me-1"></i> Reject
                    </button>
                    <button type="button" class="btn btn-success" id="approveBtn">
                        <i class="bi bi-check-circle me-1"></i> Approve
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Material Viewer + Comments Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width: 760px;">
            <div class="modal-content border-0 rounded-4" style="box-shadow: var(--shadow-xl); overflow: hidden;">

                <!-- Header -->
                <div class="modal-header border-0 px-5 pt-4 pb-3" style="background: var(--color-surface);">
                    <h5 class="modal-title" id="viewModalTitle"
                        style="font-size: var(--text-lg); font-weight: var(--font-semibold); color: var(--color-text-heading); max-width: calc(100% - 3rem); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <i class="bi bi-eye text-primary me-2"></i> View Material
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- File Viewer -->
                <div class="viewer-wrap" id="materialViewer">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Comments Section -->
                <div class="comments-section">
                    <div class="comments-section-title">
                        <i class="bi bi-chat-dots" style="color: var(--color-info);"></i>
                        Comments
                    </div>

                    <!-- Comments list -->
                    <div class="comments-list" id="threadCommentsList">
                        <!-- populated by JS -->
                    </div>

                    <!-- Reply-to notice -->
                    <div id="commentReplyBanner" class="reply-banner d-none">
                        <i class="bi bi-reply"></i>
                        Replying to <strong id="replyAuthorName"></strong>
                        <button type="button" class="reply-banner-close" onclick="clearReplyTarget()" aria-label="Cancel reply">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <!-- Input area -->
                    <div class="comment-input-area">
                        <textarea
                            id="threadCommentInput"
                            class="form-control"
                            rows="3"
                            placeholder="Write a comment…"
                            style="border-radius: var(--radius-lg); background: var(--gray-50); border: 1px solid var(--color-border); resize: none; font-size: var(--text-sm); padding: var(--space-3) var(--space-4);"
                        ></textarea>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <span class="comment-input-hint">Shift + Enter for a new line</span>
                            <button type="button" class="btn btn-primary btn-sm" onclick="postComment()">
                                <i class="bi bi-send me-1"></i> Post
                            </button>
                        </div>
                    </div>

                    <!-- Success indicator -->
                    <div id="notesSaveIndicator" class="save-indicator d-none">
                        <i class="bi bi-check-circle-fill"></i> Comment posted
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer border-0 px-5 py-3" style="background: var(--color-surface); border-top: 1px solid var(--color-border-subtle) !important;">
                    <button type="button" class="btn btn-ghost border" data-bs-dismiss="modal" style="margin-right: auto;">
                        Close
                    </button>
                    <button type="button" class="btn btn-primary" id="downloadBtnInModal">
                        <i class="bi bi-download me-1"></i> Download
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/pubmat-approvals.js"></script>
</body>
</html>