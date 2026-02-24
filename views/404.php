<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <title>404 — Page Not Found | Sign-um</title>
    <link rel="icon" type="image/png" href="<?php echo $BASE_URL; ?>assets/images/Sign-UM logo ico.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <?php
    // Define BASE_URL for 404 page — unchanged backend logic
    $BASE_URL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . '/SPCF-Thesis/';
    ?>
    
    <link rel="stylesheet" href="<?php echo $BASE_URL; ?>assets/css/master-css.css">
    
    <style>
        /* Standalone 404 centering and animation */
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            margin: 0;
        }
        
        .error-card {
            max-width: 480px;
            width: 100%;
            animation: slideUpFade var(--duration-300) var(--ease-spring);
        }
        
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* 404 Gradient Typography */
        .ghost-text {
            font-size: 7rem;
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--color-info) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0.9;
            margin-bottom: 0;
            letter-spacing: -4px;
        }
    </style>
</head>
<body>

    <div class="card border-0 shadow-lg rounded-4 error-card text-center p-5 bg-white">
        
        <div class="btn-icon lg rounded-circle bg-primary-subtle mx-auto mb-4" style="width: 80px; height: 80px; font-size: 2.5rem;">
            <i class="bi bi-signpost-split text-primary"></i>
        </div>

        <h1 class="ghost-text mb-2">404</h1>

        <h3 class="fw-bold text-dark mb-3">Page Not Found</h3>

        <p class="text-muted mb-4" style="font-size: 0.95rem;">
            The page you're looking for doesn't exist or may have been moved.
            Double-check the URL, or head back to safety.
        </p>

        <div class="d-flex flex-column flex-sm-row justify-content-center gap-3 mb-4">
            <a href="<?php echo $BASE_URL; ?>" class="btn btn-primary rounded-pill shadow-sm px-4 fw-medium" style="flex: 1;">
                <i class="bi bi-house me-2"></i>Homepage
            </a>
            <a href="javascript:history.back()" class="btn btn-light border rounded-pill shadow-sm px-4 fw-medium" style="flex: 1;">
                <i class="bi bi-arrow-left me-2"></i>Go Back
            </a>
        </div>

        <div class="border-top pt-4 mt-2">
            <a href="<?php echo $BASE_URL; ?>" class="text-decoration-none d-inline-flex align-items-center gap-2 text-muted fw-bold text-sm transition-base hover-lift">
                <i class="bi bi-shield-check text-primary"></i> Sign-um System
            </a>
        </div>
        
    </div>

</body>
</html>