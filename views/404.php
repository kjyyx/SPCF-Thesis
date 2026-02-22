<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page Not Found | Sign-um</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php
    // Define BASE_URL for 404 page — unchanged
    $BASE_URL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . '/SPCF-Thesis/';
    ?>
    <style>
        /* ── Master design tokens (subset for standalone page) ── */
        :root {
            --font-sans:          'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

            --brand-primary:      #3b82f6;
            --brand-primary-dark: #2563eb;
            --brand-primary-deep: #1d4ed8;

            --color-danger:       #ef4444;

            --gray-400:  #94a3b8;
            --gray-500:  #64748b;
            --gray-600:  #475569;
            --gray-700:  #334155;
            --gray-800:  #1e293b;
            --gray-900:  #0f172a;

            --color-page-bg:        linear-gradient(160deg, #f0f4f8 0%, #e8eef5 50%, #dce4ec 100%);
            --color-surface-raised: #ffffff;
            --color-border-subtle:  rgba(226, 232, 240, 0.6);
            --color-text-primary:   var(--gray-900);
            --color-text-secondary: var(--gray-600);
            --color-text-tertiary:  var(--gray-500);

            --glass-bg-strong:   rgba(255, 255, 255, 0.88);
            --glass-blur-strong: blur(32px);
            --glass-highlight:   rgba(255, 255, 255, 0.70);
            --glass-border:      rgba(255, 255, 255, 0.55);

            --gradient-primary:  linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);

            --shadow-sm:  0 1px 4px rgba(15, 23, 42, 0.07), 0 1px 2px rgba(15, 23, 42, 0.04);
            --shadow-md:  0 2px 8px rgba(15, 23, 42, 0.08), 0 1px 3px rgba(15, 23, 42, 0.05);
            --shadow-lg:  0 4px 16px rgba(15, 23, 42, 0.10), 0 2px 6px rgba(15, 23, 42, 0.06);
            --shadow-xl:  0 8px 28px rgba(15, 23, 42, 0.12), 0 4px 10px rgba(15, 23, 42, 0.07);
            --shadow-primary: 0 2px 10px rgba(59, 130, 246, 0.28);

            --radius-sm:   4px;
            --radius-md:   5px;
            --radius-lg:   6px;
            --radius-xl:   8px;
            --radius-2xl:  10px;
            --radius-3xl:  12px;
            --radius-full: 9999px;

            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-6: 1.5rem;
            --space-8: 2rem;

            --text-2xs:  0.6875rem;
            --text-xs:   0.75rem;
            --text-sm:   0.8125rem;
            --text-base: 0.875rem;
            --text-lg:   1rem;
            --text-xl:   1.25rem;
            --text-2xl:  1.5rem;

            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
            --ease-out:    cubic-bezier(0, 0, 0.2, 1);
        }

        /* ── Reset ─────────────────────────────────────────────── */
        *, *::before, *::after {
            margin: 0; padding: 0;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Page shell ────────────────────────────────────────── */
        html, body {
            height: 100%;
        }

        body {
            font-family: var(--font-sans);
            background: var(--color-page-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: var(--space-4);
            color: var(--color-text-secondary);
        }

        /* ── Ambient background blobs ──────────────────────────── */
        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: var(--radius-full);
            pointer-events: none;
            z-index: 0;
        }

        /* Soft blue blob — top left */
        body::before {
            width: 480px;
            height: 480px;
            top: -120px;
            left: -120px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.10) 0%, transparent 70%);
        }

        /* Soft indigo blob — bottom right */
        body::after {
            width: 560px;
            height: 560px;
            bottom: -160px;
            right: -140px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.09) 0%, transparent 70%);
        }

        /* ── Glass card ────────────────────────────────────────── */
        .error-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            background: var(--glass-bg-strong);
            backdrop-filter: var(--glass-blur-strong);
            -webkit-backdrop-filter: var(--glass-blur-strong);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-3xl);
            box-shadow:
                0 1px 0 var(--glass-highlight),
                var(--shadow-xl);
            padding: var(--space-8) var(--space-6);
            text-align: center;
            animation: card-in 0.5s var(--ease-spring);
        }

        @keyframes card-in {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0)    scale(1);    }
        }

        /* ── Error icon ────────────────────────────────────────── */
        .error-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-2xl);
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--space-4);
            animation: icon-in 0.45s 0.15s var(--ease-spring) both;
        }

        .error-icon-wrap i {
            font-size: 24px;
            color: var(--color-danger);
        }

        @keyframes icon-in {
            from { opacity: 0; transform: scale(0.7); }
            to   { opacity: 1; transform: scale(1);   }
        }

        /* ── 404 display number ────────────────────────────────── */
        .error-code {
            font-size: var(--text-2xl);          /* 24px — H1 per spec */
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--color-text-primary);
            line-height: 1.2;
            margin-bottom: var(--space-2);
            animation: text-in 0.4s 0.2s var(--ease-out) both;
        }

        /* Accent the numbers with gradient */
        .error-code .code-number {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Headline ──────────────────────────────────────────── */
        .error-headline {
            font-size: var(--text-lg);           /* 16px — H3 */
            font-weight: 700;
            color: var(--color-text-primary);
            margin-bottom: var(--space-2);
            animation: text-in 0.4s 0.25s var(--ease-out) both;
        }

        /* ── Description ───────────────────────────────────────── */
        .error-description {
            font-size: var(--text-sm);           /* 13px — body */
            color: var(--color-text-tertiary);
            line-height: 1.5;
            margin-bottom: var(--space-6);
            animation: text-in 0.4s 0.3s var(--ease-out) both;
        }

        @keyframes text-in {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0);   }
        }

        /* ── Action buttons ────────────────────────────────────── */
        .error-actions {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
            animation: text-in 0.4s 0.35s var(--ease-out) both;
        }

        /* Primary — Go Home */
        .btn-home {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            height: 36px;                        /* large button */
            padding: 0 16px;
            border-radius: var(--radius-lg);     /* 6px */
            border: none;
            font-family: var(--font-sans);
            font-size: var(--text-base);         /* 14px */
            font-weight: 600;
            color: #ffffff;
            background: var(--gradient-primary);
            box-shadow: var(--shadow-primary);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .btn-home::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,0.18) 50%, transparent 60%);
            transform: translateX(-100%);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-home:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.38);
            color: #ffffff;
        }

        .btn-home:hover::after  { transform: translateX(100%); }
        .btn-home:active        { transform: translateY(0) scale(0.98); }

        /* Secondary — Go Back */
        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            height: 30px;                        /* default button */
            padding: 0 12px;
            border-radius: var(--radius-md);     /* 5px */
            border: 1px solid var(--color-border-subtle);
            font-family: var(--font-sans);
            font-size: var(--text-sm);           /* 13px */
            font-weight: 600;
            color: var(--color-text-secondary);
            background: var(--glass-bg-strong);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
        }

        .btn-back:hover {
            background: var(--color-surface-raised);
            border-color: var(--gray-400);
            color: var(--color-text-primary);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-back:active { transform: translateY(0) scale(0.98); }

        /* ── Divider ────────────────────────────────────────────── */
        .error-divider {
            border: none;
            border-top: 1px solid var(--color-border-subtle);
            margin: var(--space-6) 0 var(--space-4);
        }

        /* ── Brand footer ───────────────────────────────────────── */
        .error-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            font-size: var(--text-xs);           /* 12px */
            font-weight: 600;
            color: var(--color-text-tertiary);
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .error-brand:hover { color: var(--brand-primary); }

        .error-brand-mark {
            width: 20px;
            height: 20px;
            border-radius: var(--radius-sm);
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 10px;
        }

        /* ── Responsive ─────────────────────────────────────────── */
        @media (max-width: 480px) {
            .error-card {
                padding: var(--space-6) var(--space-4);
                border-radius: var(--radius-2xl);
            }

            .error-code {
                font-size: var(--text-xl);
            }
        }
    </style>
</head>
<body>

    <div class="error-card" role="main">

        <!-- Icon -->
        <div class="error-icon-wrap" aria-hidden="true">
            <i class="bi bi-signpost-split"></i>
        </div>

        <!-- Code -->
        <div class="error-code" aria-label="Error 404">
            <span class="code-number">404</span>
        </div>

        <!-- Headline -->
        <h1 class="error-headline">Page not found</h1>

        <!-- Description -->
        <p class="error-description">
            The page you're looking for doesn't exist or may have been moved.
            Double-check the URL, or head back to the homepage.
        </p>

        <!-- Actions -->
        <div class="error-actions">
            <a href="<?php echo $BASE_URL; ?>" class="btn-home">
                <i class="bi bi-house" aria-hidden="true"></i>
                Go to Homepage
            </a>
            <a href="javascript:history.back()" class="btn-back">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Go Back
            </a>
        </div>

        <!-- Divider + Brand -->
        <hr class="error-divider">
        <a href="<?php echo $BASE_URL; ?>" class="error-brand" aria-label="Sign-um home">
            <span class="error-brand-mark" aria-hidden="true">
                <i class="bi bi-building"></i>
            </span>
            Sign-um
        </a>

    </div>

</body>
</html>