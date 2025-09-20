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
        this.createToastContainer();
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
        document.body.appendChild(this.container);
    }

    show({ 
        type = 'info', 
        title = '', 
        message = '', 
        duration = 4000,
        showClose = true,
        autohide = true 
    }) {
        const toastId = 'toast-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        
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

        const toastHtml = `
            <div class="toast ${bgMap[type] || bgMap.info}" role="alert" aria-live="assertive" aria-atomic="true" id="${toastId}" data-bs-autohide="${autohide}" data-bs-delay="${duration}">
                <div class="toast-header ${bgMap[type] || bgMap.info}">
                    <i class="bi bi-${iconMap[type] || iconMap.info} ${colorMap[type] || colorMap.info} me-2"></i>
                    <strong class="me-auto">${title || this.getDefaultTitle(type)}</strong>
                    <small class="text-muted">now</small>
                    ${showClose ? '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' : ''}
                </div>
                ${message ? `<div class="toast-body fw-medium">${message}</div>` : ''}
            </div>
        `;

        this.container.insertAdjacentHTML('beforeend', toastHtml);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement);
        
        // Auto-remove from DOM after toast is hidden
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });

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