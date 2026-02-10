/**
 * Global Notifications Module
 * - Centralized polling + modal rendering
 * - Replaces per-view duplicate scripts
 * - Exposes: window.refreshNotifications(), window.showNotifications(), window.markAllAsRead()
 */
(function () {
    var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
    const API = BASE_URL + 'api/notifications.php';
    const POLL_INTERVAL = 300000; // 5 min
    let pollHandle = null;
    let cache = [];

    const ICONS = {
        pending_document: 'bi-file-earmark-text',
        new_document: 'bi-file-earmark-text',
        document_status: 'bi-file-earmark-text',
        upcoming_event: 'bi-calendar-event',
        event_reminder: 'bi-calendar-event',
        pending_material: 'bi-image',
        material_status: 'bi-image',
        new_user: 'bi-person-plus',
        security_alert: 'bi-shield-exclamation',
        account: 'bi-key',
        system: 'bi-gear'
    };

    function qs(id) { return document.getElementById(id); }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"'\/]/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#47;' }[c]));
    }

    async function fetchNotifications(showToastOnError = false) {
        try {
            const res = await fetch(API, { cache: 'no-store' });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed');
            cache = data.notifications || [];
            updateBadge(data.unread_count ?? cache.length);
            window.notifications = cache; // legacy compatibility
        } catch (e) {
            console.error('Notifications fetch failed:', e);
            if (showToastOnError && window.ToastManager) {
                window.ToastManager.error('Failed to load notifications', 'Error');
            }
        }
    }

    function updateBadge(count) {
        const badge = qs('notificationCount');
        if (!badge) return;
        badge.textContent = count;
        badge.style.display = count > 0 ? 'flex' : 'none';
    }

    function buildListHTML() {
        if (!cache.length) {
            return `
                <div class="notification-empty-state">
                    <div class="empty-icon">📭</div>
                    <div class="empty-title">No notifications yet</div>
                    <div class="empty-message">You're all caught up! Check back later for updates.</div>
                </div>
            `;
        }
        return cache.map(n => {
            const icon = ICONS[n.type] || 'bi-bell';
            const iconClass = `notification-icon-${n.type.replace(/_/g, '-')}`;
            const dt = new Date(n.timestamp);
            const dateStr = isNaN(dt.getTime()) ? '' : dt.toLocaleDateString();
            return `
                <div class="list-group-item notification-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">
                            <i class="bi ${icon} me-2 ${iconClass}"></i>${escapeHtml(n.title || 'Notification')}
                        </h6>
                        <small class="notification-date">${dateStr}</small>
                    </div>
                    <p class="mb-1 notification-message">${escapeHtml(n.message || '')}</p>
                </div>
            `;
        }).join('');
    }

    function showNotifications() {
        const modalEl = qs('notificationsModal');
        const list = qs('notificationsList');
        if (list) list.innerHTML = buildListHTML();
        if (modalEl && window.bootstrap) new bootstrap.Modal(modalEl).show();
    }

    function markAllAsRead() {
        updateBadge(0);
        if (window.ToastManager) window.ToastManager.success('All notifications marked as read.', 'Done');
    }

    function startPolling() {
        stopPolling();
        fetchNotifications();
        pollHandle = setInterval(fetchNotifications, POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollHandle) clearInterval(pollHandle);
        pollHandle = null;
    }

    function init() {
        if (window.__GLOBAL_NOTIFS_INIT) return;
        window.__GLOBAL_NOTIFS_INIT = true;
        fetchNotifications();
        startPolling();
    }

    // Expose globals
    window.refreshNotifications = fetchNotifications;
    window.showNotifications = function () {
        if (!cache.length) fetchNotifications().then(showNotifications); else showNotifications();
    };
    window.markAllAsRead = markAllAsRead;
    window.__stopNotificationsPolling = stopPolling;

    document.addEventListener('DOMContentLoaded', init);
})();