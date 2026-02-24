<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth();

$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
$isPpfo = $currentUser
    && ($currentUser['role'] ?? '') === 'employee'
    && ($currentUser['position'] ?? '') === 'Physical Plant and Facilities Office (PPFO)';

if (!$isPpfo) {
    header('Location: ' . BASE_URL . 'login');
    exit();
}

// Fetch approved pubmats via API (client-side will handle this, but we can preload if needed)
// For simplicity, let JS handle the fetch to keep it dynamic.

$pageTitle = 'Approved Pubmat Display';
$currentPage = 'pubmat-display';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
    <title>Sign-um - Pubmat Display</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">
    <style>
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
        .gallery-item { cursor: pointer; border-radius: 0.5rem; overflow: hidden; }
        .gallery-item-wrap { position: relative; }
        .gallery-item img { width: 100%; height: 150px; object-fit: cover; }
        .gallery-delete-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            z-index: 3;
        }
        .slideshow-progress {
            position: absolute;
            top: 0;
            left: 0;
            height: 0.25rem;
            background: #6366f1;
            width: 0%;
            z-index: 10;
        }
        .slideshow-info {
            position: absolute;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            z-index: 10;
        }
        .slideshow-title {
            padding: 0.5rem 1.5rem;
            border-radius: 9999px;
            background: rgba(0, 0, 0, 0.7);
            color: #f8fafc;
            font-weight: 500;
        }
        .slideshow-counter {
            font-size: 0.875rem;
            color: #94a3b8;
        }
        #slideshow-view {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            z-index: 1060 !important;
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            background: #000;
            overflow: hidden;
        }
        #slideshow-bg {
            position: absolute;
            inset: 0;
            background-position: center;
            background-size: cover;
            filter: blur(28px) brightness(0.55);
            transform: scale(1.15);
            z-index: 1;
        }
        #slideshow-content {
            position: relative;
            z-index: 5;
        }
        #slideshow-view.show {
            display: flex !important;
        }
        #slideshow-content {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .slideshow-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            position: relative;
            z-index: 5;
        }
        .close-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.2s;
            z-index: 10;
        }
        .close-btn:hover {
            transform: scale(1.1);
        }
        .slideshow-info.is-hidden {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .slideshow-info,
        .close-btn,
        #slideshow-delete-btn {
            transition: opacity 0.25s ease;
        }
        #slideshow-view.controls-hidden .close-btn,
        #slideshow-view.controls-hidden #slideshow-delete-btn {
            opacity: 0;
            pointer-events: none;
        }
    </style>
</head>
<body class="has-navbar bg-light">
    <?php include ROOT_PATH . 'includes/navbar.php'; ?>
    <div class="container pt-4 pb-5">
        <div class="page-header mb-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h1 class="page-title"><i class="bi bi-images text-primary me-2"></i> Approved Pubmat Display</h1>
                <p class="page-subtitle">View approved publication materials in slideshow mode.</p>
            </div>
            <div class="d-flex gap-2">
                <button id="slideshow-btn" class="btn btn-dark" type="button" disabled>
                    <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Slideshow
                </button>
            </div>
        </div>
        <div id="gallery" class="gallery-grid"><!-- Populated by JS --></div>
        <div id="emptyState" class="alert alert-light border d-none">No approved pubmats available.</div>
        <div id="slideshow-view" class="slideshow-view">
            <div id="slideshow-bg"></div>
            <div class="slideshow-progress" id="slideshow-progress"></div>
            <div id="slideshow-content"></div>
            <div class="slideshow-info">
                <p id="slideshow-title" class="mb-0 slideshow-title"></p>
                <p id="slideshow-counter" class="mb-0 slideshow-counter"></p>
            </div>
            <button id="slideshow-delete-btn" class="btn btn-danger btn-sm" type="button" style="position:absolute;top:1rem;left:1rem;z-index:10;">Delete</button>
            <button class="close-btn" onclick="closeSlideshow()">×</button>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/pubmat-display.js"></script>
</body>
</html>