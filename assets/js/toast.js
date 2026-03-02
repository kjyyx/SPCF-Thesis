/** Bootstrap 5 Toast Utility - Optimized */
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

class ToastManager {
    constructor() { this.container = null; this.init(); }
    init() { document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => this.setup(), { once: true }) : this.setup(); }

    setup() {
        document.getElementById('toast-container')?.remove();
        this.container = document.createElement('div');
        this.container.id = 'toast-container';
        this.container.className = 'toast-container position-fixed top-0 end-0 p-3';
        this.container.style.zIndex = '1080';
        document.body ? document.body.appendChild(this.container) : document.addEventListener('DOMContentLoaded', () => document.body.appendChild(this.container), { once: true });
    }

    show({ type = 'info', title = '', message = '', duration = 4000, showClose = true, autohide = true }) {
        window.addAuditLog?.('TOAST_SHOWN', 'Notifications', `Toast: ${message}`, null, 'Notification', 'INFO');
        if (!this.container || !document.getElementById('toast-container')) this.setup();
        if (!this.container) return message && alert(`${title || type}: ${message}`);

        const map = { success: ['check', 'success'], error: ['exclamation-triangle', 'danger'], warning: ['exclamation-triangle', 'warning'], info: ['info-circle', 'info'], primary: ['info-circle', 'primary'], danger: ['exclamation-triangle', 'danger'] };
        const m = map[type] || map.info;

        const el = document.createElement('div');
        el.className = `toast bg-${m[1]}-subtle border-${m[1]}`;
        Object.assign(el.dataset, { bsAutohide: autohide, bsDelay: duration });
        el.setAttribute('role', 'alert'); el.setAttribute('aria-live', 'assertive');

        // Safe injection: build structure via innerHTML, but set message text safely
        el.innerHTML = `<div class="toast-header bg-${m[1]}-subtle"><i class="bi bi-${m[0]}-fill text-${m[1]} me-2"></i><strong class="me-auto">${title || type.charAt(0).toUpperCase() + type.slice(1)}</strong><small class="text-muted">now</small>${showClose ? '<button type="button" class="btn-close" data-bs-dismiss="toast"></button>' : ''}</div><div class="toast-body fw-medium"></div>`;
        if (message) el.querySelector('.toast-body').textContent = message;

        this.container.appendChild(el);
        try {
            const toast = new bootstrap.Toast(el);
            el.addEventListener('hidden.bs.toast', () => el.remove());
            toast.show(); return toast;
        } catch (e) { setTimeout(() => el.remove(), duration); return null; }
    }

    success(message, title, opt = {}) { return this.show({ type: 'success', title, message, ...opt }); }
    error(message, title, opt = {}) { return this.show({ type: 'error', title, message, ...opt }); }
    warning(message, title, opt = {}) { return this.show({ type: 'warning', title, message, ...opt }); }
    info(message, title, opt = {}) { return this.show({ type: 'info', title, message, ...opt }); }
    primary(message, title, opt = {}) { return this.show({ type: 'primary', title, message, ...opt }); }
}

window.ToastManager = window.ToastManager || new ToastManager();
window.showToast = (opt) => typeof opt === 'string' ? window.ToastManager.info(opt) : window.ToastManager.show(opt);
window.addAuditLog = async (action, category, details, targetId = null, targetType = null, severity = 'INFO') => {
    try { await fetch(BASE_URL + 'api/audit.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, category, details, target_id: targetId, target_type: targetType, severity }) }); } catch (e) { }
};