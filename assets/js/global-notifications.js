/**
 * Real-time Notification System (Optimized for Cross-Tab Sync)
 */
(function() {
    const BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
    const API = BASE_URL + 'api/notifications.php';
    const POLL_INTERVAL = 30000; // 30 seconds
    const FAST_POLL_INTERVAL = 5000; // 5 seconds when modal is open
    
    let pollHandle = null;
    let lastFetchTime = null;
    let isModalOpen = false;
    let notificationsCache = [];
    let unreadCount = 0;
    let hasInitialFetchCompleted = false;
    let typeFilters = {
        document: true,
        event: true,
        system: true
    };

    // Notification icons based on type
    const ICONS = {
        document: {
            pending_document: 'bi-file-earmark-text',
            document_pending_signature: 'bi-pen text-warning',
            document_status_approved: 'bi-check-circle-fill text-success',
            document_status_rejected: 'bi-x-circle-fill text-danger',
            document_status_in_review: 'bi-clock-history text-warning',
            document_comment: 'bi-chat-left-text text-info',
            document_reply: 'bi-reply-fill text-primary',
            employee_document_pending: 'bi-file-earmark-lock text-warning',
            employee_material_pending: 'bi-images text-warning',
            material_status_approved: 'bi-check2-circle text-success',
            material_status_rejected: 'bi-x-circle text-danger',
            doc_status_approved: 'bi-check-circle-fill text-success',
            doc_status_rejected: 'bi-x-circle-fill text-danger',
            doc_status_in_review: 'bi-clock-history text-warning',
            new_note: 'bi-chat-dots text-info',
            default: 'bi-file-earmark'
        },
        event: {
            event: 'bi-calendar-event',
            event_reminder: 'bi-calendar-check-fill',
            event_status_approved: 'bi-calendar2-check text-success',
            event_status_disapproved: 'bi-calendar2-x text-danger',
            default: 'bi-calendar'
        },
        system: {
            new_user: 'bi-person-plus-fill',
            security: 'bi-shield-exclamation',
            default: 'bi-gear'
        }
    };

    function qs(id) {
        return document.getElementById(id);
    }

    // Initialize
    function init() {
        // Initial fetch
        fetchNotifications(true);
        
        // Start polling
        startPolling();
        
        // Handle visibility change
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopPolling();
            } else {
                fetchNotifications(false);
                startPolling();
            }
        });
        
        // Handle online/offline
        window.addEventListener('online', () => {
            fetchNotifications(false);
            startPolling();
        });
        
        window.addEventListener('offline', () => {
            stopPolling();
        });

        // ✨ NEW: Cross-Tab Sync Listener ✨
        // If another tab triggers a read/delete action, instantly fetch the new state here.
        window.addEventListener('storage', (e) => {
            if (e.key === 'spcf_notif_sync') {
                fetchNotifications(false);
            }
        });
        
        // Setup event listeners
        setupEventListeners();
    }

    function setupEventListeners() {
        // Keyboard shortcut: Ctrl+N to open notifications
        document.addEventListener('keydown', (e) => {
            if (e.key === 'n' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                showNotificationsModal();
            }
        });
    }

    async function fetchNotifications(showLoading = false) {
        try {
            const url = new URL(API, window.location.origin);
            url.searchParams.append('t', Date.now()); // Cache bust
            url.searchParams.append('include_read', '1');
            url.searchParams.append('limit', '50');
            url.searchParams.append('debug', '1'); 
            
            if (showLoading && qs('notificationsList')) {
                qs('notificationsList').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading notifications...</p>
                    </div>
                `;
            }
            
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.status === 401) {
                stopPolling();
                updateBadge(0);
                return;
            }
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                const previousUnreadCount = unreadCount;
                
                notificationsCache = data.notifications || [];
                unreadCount = data.unread_count || 0;
                lastFetchTime = data.timestamp || new Date().toISOString();
                
                updateBadge(unreadCount);
                
                if (hasInitialFetchCompleted && unreadCount > previousUnreadCount) {
                    const newCount = unreadCount - previousUnreadCount;
                    handleNewNotifications(newCount);
                }

                hasInitialFetchCompleted = true;
                
                // ✨ FIX: Always refresh the list in the background so it is ready immediately ✨
                refreshNotificationsList();
                
                window.dispatchEvent(new CustomEvent('notificationsUpdated', {
                    detail: { 
                        count: unreadCount, 
                        notifications: notificationsCache,
                        timestamp: lastFetchTime
                    }
                }));
            }
        } catch (error) {
            if (showLoading && window.ToastManager) {
                window.ToastManager.error('Failed to load notifications', 'Error');
            }
        }
    }

    function handleNewNotifications(newCount) {
        // ✨ NEW: Cross-Tab Spam Prevention ✨
        const now = Date.now();
        const lastAnnounced = localStorage.getItem('spcf_last_notif_time');

        // If another tab announced a notification in the last 10 seconds, stay silent here.
        if (lastAnnounced && (now - parseInt(lastAnnounced)) < 10000) {
            return; 
        }

        // Claim the announcement for this tab
        localStorage.setItem('spcf_last_notif_time', now.toString());

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted' && document.visibilityState === 'visible') {
            new Notification('New Notification' + (newCount > 1 ? 's' : ''), {
                body: `You have ${newCount} new notification${newCount > 1 ? 's' : ''}`,
                icon: BASE_URL + 'assets/images/notification-icon.png',
                badge: BASE_URL + 'assets/images/notification-badge.png',
                silent: false
            });
        }
        
        const bell = qs('notificationBell') || document.querySelector('.notification-bell');
        if (bell) {
            bell.classList.add('bell-ring');
            setTimeout(() => bell.classList.remove('bell-ring'), 1000);
        }
        
        if (window.ToastManager) {
            window.ToastManager.info(
                `You have ${newCount} new notification${newCount > 1 ? 's' : ''}`,
                'New Notification',
                { duration: 5000, position: 'top-right' }
            );
        }
    }

    function updateBadge(count) {
        const badge = qs('notificationCount');
        if (!badge) return;
        
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
            badge.classList.add('badge-pop');
            setTimeout(() => badge.classList.remove('badge-pop'), 300);
        } else {
            badge.style.display = 'none';
        }
    }

    // Helper to notify other tabs to sync
    function triggerCrossTabSync() {
        localStorage.setItem('spcf_notif_sync', Date.now().toString());
    }

    async function markAsRead(notificationId) {
        try {
            const response = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId }),
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.success) {
                const notification = notificationsCache.find(n => n.id == notificationId);
                if (notification) {
                    notification.is_read = 1;
                }
                
                unreadCount = notificationsCache.filter(n => !n.is_read).length;
                updateBadge(unreadCount);
                
                const item = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (item) {
                    item.classList.remove('notification-unread');
                    item.classList.add('notification-read');
                    const markReadBtn = item.querySelector('.mark-read-btn');
                    if (markReadBtn) markReadBtn.remove();
                }
                
                triggerCrossTabSync(); // Update other tabs
            }
        } catch (error) {
        }
    }

    async function markAllAsRead() {
        const unreadIds = notificationsCache.filter(n => !n.is_read).map(n => n.id);
        if (unreadIds.length === 0) return;

        try {
            const response = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' }),
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.success) {
                notificationsCache.forEach(n => n.is_read = 1);
                unreadCount = 0;
                updateBadge(0);
                
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.classList.remove('notification-unread');
                    item.classList.add('notification-read');
                    const markReadBtn = item.querySelector('.mark-read-btn');
                    if (markReadBtn) markReadBtn.remove();
                });
                
                triggerCrossTabSync(); // Update other tabs
            }
        } catch (error) {
        }
    }

    function getIconForNotification(notification) {
        const type = notification.type;
        const refType = notification.reference_type || 'default';
        
        if (ICONS[type] && ICONS[type][refType]) {
            return ICONS[type][refType];
        } else if (ICONS[type] && ICONS[type].default) {
            return ICONS[type].default;
        }
        return 'bi-bell';
    }

    function buildNotificationsHTML() {

        const filteredNotifications = notificationsCache.filter(n => typeFilters[n.type] !== false);
        
        if (filteredNotifications.length === 0) {
            return `
                <div class="notification-empty-state">
                    <i class="bi bi-bell-slash" style="font-size: 4rem; color: #cbd5e1;"></i>
                    <h5 class="mt-3">No notifications</h5>
                    <p class="text-muted mb-0">You're all caught up!</p>
                </div>
            `;
        }
        
        return filteredNotifications.map((n, index) => {
            const icon = getIconForNotification(n);
            const date = new Date(n.created_at);
            const timeStr = n.time_ago || formatTimeAgo(date);
            const readClass = n.is_read ? 'notification-read' : 'notification-unread';
            
            let actionLink = '#';
            if (n.related_document_id) {
                actionLink = `${BASE_URL}?page=track-document`;
            } else if (n.related_event_id) {
                actionLink = `${BASE_URL}?page=calendar`;
            } else if ((n.reference_type || '').includes('material_') || (n.reference_type || '').includes('employee_material')) {
                actionLink = `${BASE_URL}?page=pubmat-approvals`;
            }
            
            return `
                <div class="list-group-item notification-item ${readClass}" 
                     data-notification-id="${n.id}"
                     data-notification-type="${n.type}"
                     data-reference-type="${n.reference_type || ''}"
                     style="animation: slideInUp 0.3s ease ${index * 0.05}s both">
                    <div class="d-flex w-100 justify-content-between align-items-start">
                        <h6 class="mb-1">
                            <i class="bi ${icon} me-2"></i>
                            ${escapeHtml(n.title)}
                        </h6>
                        <div class="dropdown">
                            <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="#" onclick="event.preventDefault(); NotificationFeatures.archiveNotification(${n.id})">
                                        <i class="bi bi-archive me-2"></i>Archive
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick="event.preventDefault(); NotificationFeatures.deleteNotification(${n.id})">
                                        <i class="bi bi-trash me-2"></i>Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p class="mb-2">${escapeHtml(n.message)}</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted timestamp" title="${formatFullDate(date)}">
                            <i class="bi bi-clock me-1"></i>${timeStr}
                        </small>
                    </div>
                </div>
            `;
        }).join('');
    }

    function refreshNotificationsList() {
        const list = qs('notificationsList');

        if (!list) {
            return;
        }

        if (!list) return;
        
        const scrollPos = list.scrollTop;
        list.innerHTML = buildNotificationsHTML();
        list.scrollTop = scrollPos;
        setupNotificationClickHandlers();
    }

    function setupNotificationClickHandlers() {
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (e.target.closest('button') || e.target.closest('a')) return;
                
                const id = this.dataset.notificationId;
                if (id && this.classList.contains('notification-unread')) {
                    markAsRead(id);
                }
                
                const link = this.querySelector('a[href]');
                if (link && link.href !== '#') {
                    window.location.href = link.href;
                }
            });
        });
    }

    function formatTimeAgo(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins} min${diffMins > 1 ? 's' : ''} ago`;
        if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return `${diffDays} days ago`;
        
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function formatFullDate(date) {
        return date.toLocaleString('en-US', { 
            month: 'long', 
            day: 'numeric', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function showNotificationsModal() {
        const modalEl = qs('notificationsModal');
        if (!modalEl) return;
        
        isModalOpen = true;
        
        if (window.bootstrap) {
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            
            modalEl.addEventListener('hidden.bs.modal', () => {
                isModalOpen = false;
                startPolling();
            }, { once: true });
        }
        
        fetchNotifications(true);
        startFastPolling();
    }

    function startPolling() {
        stopPolling();
        pollHandle = setInterval(() => fetchNotifications(false), POLL_INTERVAL);
    }

    function startFastPolling() {
        stopPolling();
        pollHandle = setInterval(() => fetchNotifications(false), FAST_POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollHandle) {
            clearInterval(pollHandle);
            pollHandle = null;
        }
    }

    // Public API
    window.refreshNotifications = () => fetchNotifications(true);
    window.showNotifications = showNotificationsModal;
    window.markNotificationRead = markAsRead;
    window.markAllNotificationsRead = markAllAsRead;
    window.markAllAsRead = markAllAsRead;
    window.markAsRead = markAsRead;

    const NotificationFeatures = {
        async archiveNotification(notificationId) {
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'archive', notification_id: notificationId }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                if (data.success) {
                    notificationsCache = notificationsCache.filter(n => n.id != notificationId);
                    unreadCount = notificationsCache.filter(n => !n.is_read).length;
                    updateBadge(unreadCount);
                    refreshNotificationsList();
                    triggerCrossTabSync(); // Update other tabs
                }
            } catch (error) {
                // Error archiving notification
            }
        },

        async deleteNotification(notificationId) {
            if (!confirm('Are you sure you want to delete this notification?')) return;
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', notification_id: notificationId }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                if (data.success) {
                    notificationsCache = notificationsCache.filter(n => n.id != notificationId);
                    unreadCount = notificationsCache.filter(n => !n.is_read).length;
                    updateBadge(unreadCount);
                    refreshNotificationsList();
                    triggerCrossTabSync(); // Update other tabs
                }
            } catch (error) {
                // Error deleting notification
            }
        },

        async clearAll() {
            if (!confirm('Delete all notifications? This cannot be undone.')) return;
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_all' }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                if (data.success) {
                    notificationsCache = [];
                    unreadCount = 0;
                    updateBadge(0);
                    refreshNotificationsList();
                    triggerCrossTabSync(); // Update other tabs
                }
            } catch (error) {
                // Error clearing notifications
            }
        }
    };

    window.NotificationFeatures = NotificationFeatures;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();