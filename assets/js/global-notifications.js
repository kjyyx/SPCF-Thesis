/**
 * Real-time Notification System
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
    let typeFilters = {
        document: true,
        event: true,
        system: true
    };

    // Notification icons based on type
    const ICONS = {
        document: {
            pending_document: 'bi-file-earmark-text',
            doc_status_approved: 'bi-check-circle-fill text-success',
            doc_status_rejected: 'bi-x-circle-fill text-danger',
            doc_status_in_review: 'bi-clock-history text-warning',
            new_note: 'bi-chat-dots text-info',
            default: 'bi-file-earmark'
        },
        event: {
            event: 'bi-calendar-event',
            event_reminder: 'bi-calendar-check-fill',
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
        
        // Filter buttons
        // document.querySelectorAll('[data-notification-filter]').forEach(btn => {
        //     btn.addEventListener('click', function() {
        //         const filter = this.dataset.notificationFilter;
        //         typeFilters[filter] = !typeFilters[filter];
        //         this.classList.toggle('active', typeFilters[filter]);
        //         refreshNotificationsList();
        //     });
        // });
    }

async function fetchNotifications(showLoading = false) {
    try {
        const url = new URL(API, window.location.origin);
        url.searchParams.append('t', Date.now()); // Cache bust
        
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
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Store previous state for comparison
            const previousUnreadCount = unreadCount;
            
            // Update cache with new data
            notificationsCache = data.notifications || [];
            unreadCount = data.unread_count || 0;
            lastFetchTime = data.timestamp || new Date().toISOString();
            
            console.log('Notifications fetched:', {
                unread: unreadCount,
                previous: previousUnreadCount,
                total: data.total_count,
                count: notificationsCache.length
            });
            
            // Update badge - ALWAYS update with current count
            updateBadge(unreadCount);
            
            // Check for new notifications only if count increased
            if (unreadCount > previousUnreadCount) {
                const newCount = unreadCount - previousUnreadCount;
                handleNewNotifications(newCount);
            }
            
            // Update modal if open
            if (isModalOpen) {
                refreshNotificationsList();
            }
            
            // Trigger custom event
            window.dispatchEvent(new CustomEvent('notificationsUpdated', {
                detail: { 
                    count: unreadCount, 
                    notifications: notificationsCache,
                    timestamp: lastFetchTime
                }
            }));
        }
    } catch (error) {
        console.error('Notification fetch error:', error);
        if (showLoading && window.ToastManager) {
            window.ToastManager.error('Failed to load notifications', 'Error');
        }
    }
}

    function handleNewNotifications(newCount) {
        // Show browser notification if permitted
        if (Notification.permission === 'granted' && document.visibilityState === 'visible') {
            new Notification('New Notification' + (newCount > 1 ? 's' : ''), {
                body: `You have ${newCount} new notification${newCount > 1 ? 's' : ''}`,
                icon: BASE_URL + 'assets/images/notification-icon.png',
                badge: BASE_URL + 'assets/images/notification-badge.png',
                silent: false
            });
        }
        
        // Animate bell
        const bell = qs('notificationBell');
        if (bell) {
            bell.classList.add('bell-ring');
            setTimeout(() => bell.classList.remove('bell-ring'), 1000);
        }
        
        // Show toast notification
        if (window.ToastManager) {
            window.ToastManager.info(
                `You have ${newCount} new notification${newCount > 1 ? 's' : ''}`,
                'New Notification',
                { 
                    duration: 5000,
                    position: 'top-right'
                }
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

    // function updateFilterCounts(typeCounts) {
    //     document.querySelectorAll('[data-notification-filter]').forEach(btn => {
    //         const filter = btn.dataset.notificationFilter;
    //         const count = typeCounts[filter] || 0;
    //         const badge = btn.querySelector('.filter-badge');
    //         
    //         if (badge) {
    //             if (count > 0) {
    //                 badge.textContent = count;
    //                 badge.style.display = 'inline';
    //             } else {
    //                 badge.style.display = 'none';
    //             }
    //         }
    //     });
    // }

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
                // Update local cache
                const notification = notificationsCache.find(n => n.id == notificationId);
                if (notification) {
                    notification.is_read = 1;
                }
                
                // Recalculate unread count
                unreadCount = notificationsCache.filter(n => !n.is_read).length;
                updateBadge(unreadCount);
                
                // Update UI
                const item = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (item) {
                    item.classList.remove('notification-unread');
                    item.classList.add('notification-read');
                    
                    // Remove mark read button
                    const markReadBtn = item.querySelector('.mark-read-btn');
                    if (markReadBtn) markReadBtn.remove();
                }
                
                // Show success toast
                if (window.ToastManager) {
                    window.ToastManager.success('Notification marked as read', 'Success');
                }
            }
        } catch (error) {
            console.error('Error marking as read:', error);
            if (window.ToastManager) {
                window.ToastManager.error('Failed to mark as read', 'Error');
            }
        }
    }

    async function markAllAsRead() {
        const unreadIds = notificationsCache.filter(n => !n.is_read).map(n => n.id);
        
        if (unreadIds.length === 0) {
            if (window.ToastManager) {
                window.ToastManager.info('No unread notifications', 'Info');
            }
            return;
        }

        try {
            const response = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' }),
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Update local cache
                notificationsCache.forEach(n => n.is_read = 1);
                unreadCount = 0;
                updateBadge(0);
                
                // Update UI
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.classList.remove('notification-unread');
                    item.classList.add('notification-read');
                    
                    const markReadBtn = item.querySelector('.mark-read-btn');
                    if (markReadBtn) markReadBtn.remove();
                });
                
                if (window.ToastManager) {
                    window.ToastManager.success('All notifications marked as read', 'Success');
                }
            }
        } catch (error) {
            console.error('Error marking all as read:', error);
            if (window.ToastManager) {
                window.ToastManager.error('Failed to mark all as read', 'Error');
            }
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
                    <!-- <div class="mt-3">
                        <button class="btn btn-sm btn-outline-primary" onclick="NotificationFeatures.exportNotifications()">
                            <i class="bi bi-download me-2"></i>Export History
                        </button>
                    </div> -->
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
                actionLink = `${BASE_URL}view-document.php?id=${n.related_document_id}`;
            } else if (n.related_event_id) {
                actionLink = `${BASE_URL}view-event.php?id=${n.related_event_id}`;
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
                        <!-- <div>
                            ${!n.is_read ? `
                                <button class="btn btn-sm btn-link mark-read-btn p-0 me-2" 
                                        onclick="event.stopPropagation(); window.markNotificationRead(${n.id})">
                                    <i class="bi bi-check-circle me-1"></i>Mark read
                                </button>
                            ` : ''}
                            ${actionLink !== '#' ? `
                                <a href="${actionLink}" class="btn btn-sm btn-link p-0" 
                                   onclick="event.stopPropagation()">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>View
                                </a>
                            ` : ''}
                        </div> -->
                    </div>
                </div>
            `;
        }).join('');
    }

    function refreshNotificationsList() {
        const list = qs('notificationsList');
        if (!list) return;
        
        // Store scroll position
        const scrollPos = list.scrollTop;
        
        list.innerHTML = buildNotificationsHTML();
        
        // Restore scroll position
        list.scrollTop = scrollPos;
        
        // Add click handlers
        setupNotificationClickHandlers();
    }

    function setupNotificationClickHandlers() {
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                // Don't trigger if clicking on a button or link
                if (e.target.closest('button') || e.target.closest('a')) return;
                
                const id = this.dataset.notificationId;
                if (id && this.classList.contains('notification-unread')) {
                    markAsRead(id);
                }
                
                // Navigate to related content if available
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
        
        // Create modal if using Bootstrap
        if (window.bootstrap) {
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            
            modalEl.addEventListener('hidden.bs.modal', () => {
                isModalOpen = false;
                startPolling(); // Switch back to normal polling
            }, { once: true });
        }
        
        // Refresh list
        refreshNotificationsList();
        
        // Switch to fast polling
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

    // New features object
    const NotificationFeatures = {
        // Archive notification
        async archiveNotification(notificationId) {
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'archive', 
                        notification_id: notificationId 
                    }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Remove from cache and UI
                    notificationsCache = notificationsCache.filter(n => n.id != notificationId);
                    unreadCount = notificationsCache.filter(n => !n.is_read).length;
                    updateBadge(unreadCount);
                    refreshNotificationsList();
                    
                    if (window.ToastManager) {
                        window.ToastManager.success('Notification archived', 'Success');
                    }
                }
            } catch (error) {
                console.error('Error archiving notification:', error);
            }
        },

        // Delete notification
        async deleteNotification(notificationId) {
            if (!confirm('Are you sure you want to delete this notification?')) return;
            
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'delete', 
                        notification_id: notificationId 
                    }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    notificationsCache = notificationsCache.filter(n => n.id != notificationId);
                    unreadCount = notificationsCache.filter(n => !n.is_read).length;
                    updateBadge(unreadCount);
                    refreshNotificationsList();
                    
                    if (window.ToastManager) {
                        window.ToastManager.success('Notification deleted', 'Success');
                    }
                }
            } catch (error) {
                console.error('Error deleting notification:', error);
            }
        },

        // Clear all notifications
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
                    
                    if (window.ToastManager) {
                        window.ToastManager.success('All notifications cleared', 'Success');
                    }
                }
            } catch (error) {
                console.error('Error clearing notifications:', error);
            }
        },

        // Get notification statistics
        // async getStats() {
        //     try {
        //         const response = await fetch(API + '?stats=true', {
        //             method: 'GET',
        //             credentials: 'same-origin'
        //         });
        //         
        //         const data = await response.json();
        //         
        //         if (data.success) {
        //             this.showStatsModal(data.stats);
        //         }
        //     } catch (error) {
        //         console.error('Error getting stats:', error);
        //     }
        // },

        // Show statistics modal
        // showStatsModal(stats) {
        //     // Create and show a modal with notification statistics
        //     const modalHtml = `
        //         <div class="modal fade" id="statsModal" tabindex="-1">
        //             <div class="modal-dialog">
        //                 <div class="modal-content">
        //                     <div class="modal-header">
        //                         <h5 class="modal-title">Notification Statistics</h5>
        //                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        //                     </div>
        //                     <div class="modal-body">
        //                         <div class="stats-grid">
        //                             <div class="stat-item">
        //                                 <div class="stat-value">${stats.total}</div>
        //                                 <div class="stat-label">Total Notifications</div>
        //                             </div>
        //                             <div class="stat-item">
        //                                 <div class="stat-value">${stats.unread}</div>
        //                                 <div class="stat-label">Unread</div>
        //                             </div>
        //                             <div class="stat-item">
        //                                 <div class="stat-value">${stats.byType.document || 0}</div>
        //                                 <div class="stat-label">Documents</div>
        //                             </div>
        //                             <div class="stat-item">
        //                                 <div class="stat-value">${stats.byType.event || 0}</div>
        //                                 <div class="stat-label">Events</div>
        //                             </div>
        //                             <div class="stat-item">
        //                                 <div class="stat-value">${stats.byType.system || 0}</div>
        //                                 <div class="stat-label">System</div>
        //                             </div>
        //                             <div class="stat-item">
        //                                 <div class="stat-value">${stats.last7Days}</div>
        //                                 <div class="stat-label">Last 7 Days</div>
        //                             </div>
        //                         </div>
        //                     </div>
        //                 </div>
        //             </div>
        //         </div>
        //     `;
        //     
        //     document.body.insertAdjacentHTML('beforeend', modalHtml);
        //     const modal = new bootstrap.Modal(document.getElementById('statsModal'));
        //     modal.show();
        //     
        //     document.getElementById('statsModal').addEventListener('hidden.bs.modal', function() {
        //         this.remove();
        //     });
        // },

        // Export notifications
        async exportNotifications() {
            try {
                const response = await fetch(API + '?export=true', {
                    method: 'GET',
                    credentials: 'same-origin'
                });
                
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `notifications-${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                if (window.ToastManager) {
                    window.ToastManager.success('Notifications exported', 'Success');
                }
            } catch (error) {
                console.error('Error exporting notifications:', error);
            }
        }
    };

    // Add to window object
    window.NotificationFeatures = NotificationFeatures;

    // Add preferences modal
    // function showPreferencesModal() {
    //     const modalHtml = `
    //         <div class="modal fade" id="preferencesModal" tabindex="-1">
    //             <div class="modal-dialog">
    //                 <div class="modal-content">
    //                 <div class="modal-header">
    //                     <h5 class="modal-title">
    //                         <i class="bi bi-gear me-2"></i>Notification Preferences
    //                     </h5>
    //                     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    //                 </div>
    //                 <div class="modal-body">
    //                     <form id="notificationPrefsForm">
    //                         <div class="mb-3">
    //                             <label class="form-label fw-bold">Notification Channels</label>
    //                             <div class="form-check mb-2">
    //                                 <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
    //                                 <label class="form-check-label" for="emailNotifications">
    //                                     <i class="bi bi-envelope me-2"></i>Email Notifications
    //                                 </label>
    //                             </div>
    //                             <div class="form-check mb-2">
    //                                 <input class="form-check-input" type="checkbox" id="browserNotifications" checked>
    //                                 <label class="form-check-label" for="browserNotifications">
    //                                     <i class="bi bi-browser-chrome me-2"></i>Browser Notifications
    //                                 </label>
    //                             </div>
    //                         </div>
    //                         
    //                         <div class="mb-3">
    //                             <label class="form-label fw-bold">Notification Types</label>
    //                             <div class="form-check mb-2">
    //                                 <input class="form-check-input" type="checkbox" id="documentUpdates" checked>
    //                                 <label class="form-check-label" for="documentUpdates">
    //                                     <i class="bi bi-file-text me-2"></i>Document Updates
    //                                 </label>
    //                             </div>
    //                             <div class="form-check mb-2">
    //                                 <input class="form-check-input" type="checkbox" id="eventReminders" checked>
    //                                 <label class="form-check-label" for="eventReminders">
    //                                     <i class="bi bi-calendar me-2"></i>Event Reminders
    //                                 </label>
    //                             </div>
    //                             <div class="form-check mb-2">
    //                                 <input class="form-check-input" type="checkbox" id="systemAlerts" checked>
    //                                 <label class="form-check-label" for="systemAlerts">
    //                                     <i class="bi bi-shield me-2"></i>System Alerts
    //                                 </label>
    //                             </div>
    //                         </div>
    //                         
    //                         <div class="mb-3">
    //                             <label class="form-label fw-bold">Quiet Hours</label>
    //                             <div class="row">
    //                                 <div class="col-6">
    //                                     <label class="form-label small">Start Time</label>
    //                                     <input type="time" class="form-control" id="quietStart" value="22:00">
    //                                 </div>
    //                                 <div class="col-6">
    //                                     <label class="form-label small">End Time</label>
    //                                     <input type="time" class="form-control" id="quietEnd" value="07:00">
    //                                 </div>
    //                             </div>
    //                             <small class="text-muted">No notifications during quiet hours</small>
    //                         </div>
    //                     </form>
    //                 </div>
    //                 <div class="modal-footer">
    //                     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    //                     <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
    //                 </div>
    //             </div>
    //         </div>
    //     </div>
    // `;
    //     
    //     document.body.insertAdjacentHTML('beforeend', modalHtml);
    //     
    //     // Load current preferences
    //     fetch(API, {
    //         method: 'POST',
    //         headers: { 'Content-Type': 'application/json' },
    //         body: JSON.stringify({ action: 'get_preferences' }),
    //         credentials: 'same-origin'
    //     })
    //     .then(res => res.json())
    //     .then(data => {
    //         if (data.success && data.preferences) {
    //             document.getElementById('emailNotifications').checked = data.preferences.email_notifications;
    //             document.getElementById('browserNotifications').checked = data.preferences.browser_notifications;
    //             document.getElementById('documentUpdates').checked = data.preferences.document_updates;
    //             document.getElementById('eventReminders').checked = data.preferences.event_reminders;
    //             document.getElementById('systemAlerts').checked = data.preferences.system_alerts;
    //             document.getElementById('quietStart').value = data.preferences.quiet_hours_start;
    //             document.getElementById('quietEnd').value = data.preferences.quiet_hours_end;
    //         }
    //     });
    //     
    //     const modal = new bootstrap.Modal(document.getElementById('preferencesModal'));
    //     modal.show();
    //     
    //     document.getElementById('preferencesModal').addEventListener('hidden.bs.modal', function() {
    //         this.remove();
    //     });
    // }

    // Save preferences function
    // window.savePreferences = async function() {
    //     const preferences = {
    //         email_notifications: document.getElementById('emailNotifications').checked,
    //         browser_notifications: document.getElementById('browserNotifications').checked,
    //         document_updates: document.getElementById('documentUpdates').checked,
    //         event_reminders: document.getElementById('eventReminders').checked,
    //         system_alerts: document.getElementById('systemAlerts').checked,
    //         quiet_hours_start: document.getElementById('quietStart').value,
    //         quiet_hours_end: document.getElementById('quietEnd').value
    //     };
    //     
    //     try {
    //         const response = await fetch(API, {
    //             method: 'POST',
    //             headers: { 'Content-Type': 'application/json' },
    //             body: JSON.stringify({ 
    //                 action: 'save_preferences', 
    //                 preferences: preferences 
    //             }),
    //             credentials: 'same-origin'
    //         });
    //         
    //         const data = await response.json();
    //         
    //         if (data.success) {
    //             bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
    //             if (window.ToastManager) {
    //                 window.ToastManager.success('Preferences saved', 'Success');
    //             }
    //         }
    //     } catch (error) {
    //         console.error('Error saving preferences:', error);
    //     }
    // };

    // Add to window object
    // window.showPreferencesModal = showPreferencesModal;

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();