/**
 * Bootstrap 5 Toast Utility for SPCF-Thesis Project
 * Provides consistent toast notifications across all views
 */
class ToastManager {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        // If DOM not ready yet, defer container creation
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.createToastContainer(), { once: true });
        } else {
            this.createToastContainer();
        }
    }

    createToastContainer() {
        // Remove existing container if any
        const existing = document.getElementById('toast-container');
        if (existing) {
            existing.remove();
        }

        // Create new container
        this.container = document.createElement('div');
        this.container.id = 'toast-container';
        this.container.className = 'toast-container position-fixed top-0 end-0 p-3';
        this.container.style.zIndex = '1080';
        if (document.body) {
            document.body.appendChild(this.container);
        } else {
            // In unlikely case body is missing, wait until DOMContentLoaded
            document.addEventListener('DOMContentLoaded', () => {
                if (!document.getElementById('toast-container')) {
                    document.body.appendChild(this.container);
                }
            }, { once: true });
        }
    }

        show({
        type = 'info',
        title = '',
        message = '',
        duration = 4000,
        showClose = true,
        autohide = true
    }) {
        if (window.addAuditLog) {
            window.addAuditLog('TOAST_SHOWN', 'Notifications', `Toast shown: ${message}`, null, 'Notification', 'INFO');
        }
        // Ensure container exists
        if (!this.container || !document.getElementById('toast-container')) {
            this.createToastContainer();
        }
        if (!this.container) {
            // As a last resort, avoid crashing
            console.warn('ToastManager: container not available, falling back to alert');
            if (message) alert(`${title || this.getDefaultTitle(type)}: ${message}`);
            return null;
        }

        const iconMap = {
            success: 'check-circle-fill',
            error: 'exclamation-triangle-fill',
            warning: 'exclamation-triangle-fill',
            info: 'info-circle-fill',
            primary: 'info-circle-fill',
            danger: 'exclamation-triangle-fill'
        };

        const colorMap = {
            success: 'text-success',
            error: 'text-danger',
            warning: 'text-warning',
            info: 'text-info',
            primary: 'text-primary',
            danger: 'text-danger'
        };

        const bgMap = {
            success: 'bg-success-subtle border-success',
            error: 'bg-danger-subtle border-danger',
            warning: 'bg-warning-subtle border-warning',
            info: 'bg-info-subtle border-info',
            primary: 'bg-primary-subtle border-primary',
            danger: 'bg-danger-subtle border-danger'
        };

        // Build DOM elements safely (no innerHTML parsing)
        const toastEl = document.createElement('div');
        toastEl.className = `toast ${bgMap[type] || bgMap.info}`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.dataset.bsAutohide = String(autohide);
        toastEl.dataset.bsDelay = String(duration);

        const header = document.createElement('div');
        header.className = `toast-header ${bgMap[type] || bgMap.info}`;

        const icon = document.createElement('i');
        icon.className = `bi bi-${iconMap[type] || iconMap.info} ${colorMap[type] || colorMap.info} me-2`;

        const strong = document.createElement('strong');
        strong.className = 'me-auto';
        strong.textContent = title || this.getDefaultTitle(type);

        const small = document.createElement('small');
        small.className = 'text-muted';
        small.textContent = 'now';

        header.appendChild(icon);
        header.appendChild(strong);
        header.appendChild(small);

        if (showClose) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-close';
            btn.setAttribute('data-bs-dismiss', 'toast');
            btn.setAttribute('aria-label', 'Close');
            header.appendChild(btn);
        }

        toastEl.appendChild(header);

        if (message) {
            const body = document.createElement('div');
            body.className = 'toast-body fw-medium';
            body.textContent = message;
            toastEl.appendChild(body);
        }

        this.container.appendChild(toastEl);

        // Initialize bootstrap toast
        let toast;
        try {
            if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
                throw new Error('Bootstrap Toast not available');
            }
            toast = new bootstrap.Toast(toastEl);
        } catch (e) {
            console.warn('ToastManager: Bootstrap Toast init failed, fallback to timeout removal', e);
            setTimeout(() => toastEl.remove(), duration);
            return null;
        }

        // Auto-remove from DOM after toast is hidden
        if (typeof toastEl.addEventListener === 'function') {
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        } else {
            setTimeout(() => toastEl.remove(), duration + 1000);
        }

        toast.show();
        return toast;
    }

    getDefaultTitle(type) {
        const titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information',
            primary: 'Notice',
            danger: 'Error'
        };
        return titles[type] || 'Notice';
    }

    // Convenience methods
    success(message, title = 'Success', options = {}) {
        return this.show({ type: 'success', title, message, ...options });
    }

    error(message, title = 'Error', options = {}) {
        return this.show({ type: 'error', title, message, ...options });
    }

    warning(message, title = 'Warning', options = {}) {
        return this.show({ type: 'warning', title, message, ...options });
    }

    info(message, title = 'Info', options = {}) {
        return this.show({ type: 'info', title, message, ...options });
    }

    primary(message, title = 'Notice', options = {}) {
        return this.show({ type: 'primary', title, message, ...options });
    }
}

// Global instance
window.ToastManager = window.ToastManager || new ToastManager();
window.showToast = (options) => window.ToastManager.show(options);