/**
 * Global Notifications - Premium Experience with Delightful Micro-interactions
 * Aesthetic: Luxurious minimalism with playful details
 */
(function () {
    var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
    const API = BASE_URL + 'api/notifications.php';
    const POLL_INTERVAL = 60000; // 1 minute
    let pollHandle = null;
    let timeUpdateHandle = null;
    let cache = [];
    let isModalOpen = false;

    const ICONS = {
        pending_document: 'bi-file-earmark-text',
        new_document: 'bi-file-earmark-text',
        document_status: 'bi-file-earmark-text',
        deadline_approaching: 'bi-clock',
        overdue: 'bi-exclamation-triangle',
        saf_funds_ready: 'bi-cash',
        new_note: 'bi-chat-dots',
        approved_ready: 'bi-check-circle',
        upcoming_event: 'bi-calendar-event',
        event_reminder: 'bi-calendar-event',
        pending_material: 'bi-image',
        material_status: 'bi-image',
        new_user: 'bi-person-plus',
        security_alert: 'bi-shield-exclamation',
        account: 'bi-key',
        profile_reminder: 'bi-person-gear',
        system: 'bi-gear'
    };

    function qs(id) {
        return document.getElementById(id);
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    async function fetchNotifications(showToastOnError = false) {
        try {
            const response = await fetch(API, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success !== false) {
                const oldCount = cache.filter(n => !n.read).length;
                const newCount = (data.unread_count || 0);
                
                cache = data.notifications || [];
                console.log('Notifications fetched:', cache.length, 'items');
                updateBadge(newCount, oldCount !== newCount && newCount > oldCount);
                
                if (isModalOpen) {
                    refreshNotificationsList();
                }
            }
        } catch (error) {
            console.error('Notification fetch error:', error);
            if (showToastOnError && window.ToastManager) {
                window.ToastManager.error('Failed to load notifications', 'Network Error');
            }
        }
    }

    function updateBadge(count, isNew = false) {
        const badge = qs('notificationCount');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
                
                if (isNew) {
                    // Exciting entrance for new notifications
                    badge.style.animation = 'none';
                    void badge.offsetWidth;
                    badge.style.animation = 'badgeEntrance 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), badgePulse 3s ease-in-out 0.5s infinite';
                    
                    // Add a subtle shake to the bell
                    const bell = qs('notificationBell');
                    if (bell) {
                        bell.style.animation = 'bellShake 0.5s ease';
                        setTimeout(() => bell.style.animation = '', 500);
                    }
                }
            } else {
                // Smooth exit
                badge.style.animation = 'badgeExit 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards';
                setTimeout(() => {
                    badge.style.display = 'none';
                }, 400);
            }
        }
    }

    function buildListHTML() {
        if (!cache.length) {
            return `
                <div class="notification-empty-state">
                    <i class="bi bi-bell-slash text-muted" style="font-size: 3rem;"></i>
                    <h5>No notifications</h5>
                    <p class="text-muted">You're all caught up! Check back later for updates.</p>
                </div>
            `;
        }
        
        return cache.map((n, index) => {
            const icon = ICONS[n.type] || 'bi-bell';
            const iconClass = `notification-icon-${n.type.replace(/_/g, '-')}`;
            const dt = new Date(n.timestamp);
            const dateStr = isNaN(dt.getTime()) ? '' : formatRelativeTime(dt);
            const readClass = n.read ? 'notification-read' : 'notification-unread';
            const clickHandler = ''; // Removed clickability to prevent errors
            const animationDelay = (index * 0.05).toFixed(2);
            
            return `
                <div class="list-group-item notification-item ${readClass}" 
                     ${clickHandler}
                     style="animation: slideIn 0.5s ease ${animationDelay}s both"
                     data-notification-id="${n.id}"
                     aria-label="${escapeHtml(n.title || 'Notification')}">
                    <div class="d-flex w-100 justify-content-between align-items-start">
                        <h6 class="mb-1">
                            <i class="bi ${icon} ${iconClass}"></i>
                            ${escapeHtml(n.title || 'Notification')}
                        </h6>
                        <small class="text-muted">${dateStr}</small>
                    </div>
                    <p class="mb-1">${escapeHtml(n.message || '')}</p>
                </div>
            `;
        }).join('');
    }

    function formatRelativeTime(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins === 1) return '1 min ago';
        if (diffMins < 60) return `${diffMins} mins ago`;
        if (diffHours === 1) return '1 hour ago';
        if (diffHours < 24) return `${diffHours} hours ago`;
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return `${diffDays} days ago`;
        if (diffDays < 14) return '1 week ago';
        
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric',
            year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
        });
    }

    function addDynamicStyles() {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
            .notification-empty-state { text-align: center; padding: 2rem; }
        `;
        document.head.appendChild(style);
    }

    function showNotifications() {
        const modalEl = qs('notificationsModal');
        const list = qs('notificationsList');
        
        if (list) {
            // Premium loading experience
            list.innerHTML = `
                <div class="notification-loading" style="animation: fadeInScale 0.4s ease">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="notification-loading-text">Loading notifications...</div>
                </div>
            `;
            
            // Smooth content reveal
            setTimeout(() => {
                list.style.transition = 'opacity 0.3s ease';
                list.style.opacity = '0';
                
                setTimeout(() => {
                    list.innerHTML = buildListHTML();
                    list.style.opacity = '1';
                    
                    // Setup interactions
                    setupKeyboardNavigation(list);
                    setupCardHoverEffects(list);
                    setupParallaxEffect(list);
                }, 200);
            }, 300);
        }
        
        if (modalEl && window.bootstrap) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            isModalOpen = true;
            
            // Add refresh button to modal footer
            const footer = modalEl.querySelector('.modal-footer');
            if (footer && !footer.querySelector('.refresh-btn')) {
                const refreshBtn = document.createElement('button');
                refreshBtn.className = 'btn btn-outline-primary btn-sm refresh-btn';
                refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Refresh';
                refreshBtn.onclick = () => fetchNotifications(true);
                footer.insertBefore(refreshBtn, footer.firstChild);
            }
            
            modalEl.addEventListener('hidden.bs.modal', () => {
                isModalOpen = false;
                stopTimeUpdates();
            }, { once: true });
        }
    }

function refreshNotificationsList() {
    const list = qs('notificationsList');
    if (!list) return;
    
    // Smooth refresh without loading state
    list.style.transition = 'opacity 0.2s ease';
    list.style.opacity = '0.5';
    
    setTimeout(() => {
        list.innerHTML = buildListHTML();
        list.style.opacity = '1';
        
        setupKeyboardNavigation(list);
        setupCardHoverEffects(list);
        setupParallaxEffect(list);
        
        // Restart time updates to ensure they're active
        if (isModalOpen) {
            startTimeUpdates();
        }
    }, 200);
}

    function setupKeyboardNavigation(list) {
        const items = list.querySelectorAll('.notification-item');
        items.forEach((item, index) => {
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    // Add press animation
                    item.style.transform = 'scale(0.97)';
                    setTimeout(() => {
                        item.click();
                    }, 100);
                } 
                else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (items[index + 1]) {
                        items[index + 1].focus();
                        items[index + 1].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                } 
                else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (items[index - 1]) {
                        items[index - 1].focus();
                        items[index - 1].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
                else if (e.key === 'Home') {
                    e.preventDefault();
                    items[0].focus();
                    items[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                else if (e.key === 'End') {
                    e.preventDefault();
                    const lastItem = items[items.length - 1];
                    lastItem.focus();
                    lastItem.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
            });
        });
    }

    function setupCardHoverEffects(list) {
        const items = list.querySelectorAll('.notification-item');
        items.forEach(item => {
            let timeout;
            
            item.addEventListener('mouseenter', function() {
                clearTimeout(timeout);
                this.style.transition = 'all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)';
            });
            
            item.addEventListener('mouseleave', function() {
                timeout = setTimeout(() => {
                    this.style.transform = '';
                }, 100);
            });
        });
    }

    function setupParallaxEffect(list) {
        const items = list.querySelectorAll('.notification-item');
        items.forEach(item => {
            item.addEventListener('mousemove', function(e) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const centerX = rect.width / 2;
                const centerY = rect.height / 2;
                
                const deltaX = (x - centerX) / centerX;
                const deltaY = (y - centerY) / centerY;
                
                // Subtle tilt effect
                this.style.transform = `
                    translateY(-6px) 
                    translateX(2px) 
                    scale(1.02)
                    perspective(1000px)
                    rotateY(${deltaX * 3}deg) 
                    rotateX(${-deltaY * 3}deg)
                `;
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = '';
            });
        });
    }

    async function markAllAsRead() {
        const unreadIds = cache.filter(n => !n.read).map(n => n.id);
        
        if (unreadIds.length === 0) {
            if (window.ToastManager) {
                window.ToastManager.info('All notifications are already read', 'Nothing to do');
            }
            return;
        }

        // Save original state for rollback
        const originalCache = JSON.parse(JSON.stringify(cache));
        const originalBadgeCount = cache.filter(n => !n.read).length;

        // Button loading state
        const markAllBtn = document.querySelector('[onclick*="markAllAsRead"]');
        let originalText = '';
        
        if (markAllBtn) {
            originalText = markAllBtn.innerHTML;
            markAllBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Marking...';
            markAllBtn.disabled = true;
            markAllBtn.style.opacity = '0.7';
        }

        // Optimistic update with delightful animation
        const list = qs('notificationsList');
        if (list) {
            const unreadItems = list.querySelectorAll('.notification-unread');
            unreadItems.forEach((item, index) => {
                setTimeout(() => {
                    item.style.transition = 'all 0.5s ease';
                    item.classList.remove('notification-unread');
                    item.classList.add('notification-read');
                    
                    // Flash effect
                    item.style.background = 'rgba(99, 102, 241, 0.15)';
                    setTimeout(() => {
                        item.style.background = '';
                    }, 300);
                }, index * 80);
            });
        }
        
        cache.forEach(n => n.read = true);
        updateBadge(0);

        try {
            const response = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' }),
                credentials: 'same-origin'
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Force a full refresh from server to ensure consistency
                await fetchNotifications(true);  // Pass true to skip toast on error
                if (isModalOpen) {
                    refreshNotificationsList();
                }

                if (window.ToastManager) {
                    window.ToastManager.success(
                        `${unreadIds.length} notification${unreadIds.length > 1 ? 's' : ''} marked as read`, 
                        'Success'
                    );
                }
            } else {
                throw new Error('Server error');
            }
        } catch (error) {
            console.error('Error:', error);
            // Rollback optimistic updates
            cache = originalCache;
            updateBadge(originalBadgeCount);
            if (isModalOpen) {
                refreshNotificationsList();
            }
            if (window.ToastManager) {
                window.ToastManager.error('Failed to mark as read', 'Error');
            }
        } finally {
            if (markAllBtn) {
                markAllBtn.innerHTML = originalText;
                markAllBtn.disabled = false;
                markAllBtn.style.opacity = '1';
            }
        }
    }

    function startPolling() {
        if (pollHandle) return;
        pollHandle = setInterval(() => fetchNotifications(false), POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollHandle) {
            clearInterval(pollHandle);
            pollHandle = null;
        }
    }

    function init() {
        // Smooth initial load
        setTimeout(() => fetchNotifications(false), 600);
        
        startPolling();
        
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopPolling();
            } else {
                fetchNotifications(false);
                startPolling();
            }
        });

        addDynamicStyles();
        setupBellInteraction();
    }

    function addDynamicStyles() {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes cardSlideIn {
                from {
                    opacity: 0;
                    transform: translateX(-40px) translateY(20px) scale(0.95);
                    filter: blur(8px);
                }
                to {
                    opacity: 1;
                    transform: translateX(0) translateY(0) scale(1);
                    filter: blur(0);
                }
            }
            
            @keyframes fadeInScale {
                from {
                    opacity: 0;
                    transform: scale(0.9);
                }
                to {
                    opacity: 1;
                    transform: scale(1);
                }
            }
            
            @keyframes emptyEntrance {
                from {
                    opacity: 0;
                    transform: scale(0.85) translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: scale(1) translateY(0);
                }
            }
            
            @keyframes badgeExit {
                to {
                    opacity: 0;
                    transform: scale(0) rotate(-180deg);
                }
            }
            
            @keyframes bellShake {
                0%, 100% { transform: rotate(0deg); }
                10%, 30%, 50%, 70%, 90% { transform: rotate(-12deg); }
                20%, 40%, 60%, 80% { transform: rotate(12deg); }
            }
        `;
        document.head.appendChild(style);
    }

    function setupBellInteraction() {
        const bell = qs('notificationBell');
        if (bell) {
            // Fun bell shake on click
            bell.addEventListener('click', function() {
                this.style.animation = 'bellShake 0.6s cubic-bezier(0.36, 0, 0.66, -0.56)';
                setTimeout(() => {
                    this.style.animation = '';
                }, 600);
            });
            
            // Subtle hover glow
            bell.addEventListener('mouseenter', function() {
                this.style.transition = 'all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)';
            });
        }
    }

    // Public API
    window.refreshNotifications = fetchNotifications;
    window.showNotifications = function () {
        if (!cache.length) {
            fetchNotifications().then(showNotifications);
        } else {
            showNotifications();
        }
    };
    window.markAllAsRead = markAllAsRead;
    window.__stopNotificationsPolling = stopPolling;

function startTimeUpdates() {
    stopTimeUpdates(); // Clear any existing
    timeUpdateHandle = setInterval(() => {
        updateDisplayedTimes();
    }, 60000); // Update every minute
}

function stopTimeUpdates() {
    if (timeUpdateHandle) {
        clearInterval(timeUpdateHandle);
        timeUpdateHandle = null;
    }
}

function updateDisplayedTimes() {
    const timeElements = document.querySelectorAll('.notification-item');
    timeElements.forEach((el) => {
        const timeElement = el.querySelector('small.text-muted');
        if (!timeElement) return;
        
        // Get the notification ID from the data attribute
        const notificationId = el.dataset.notificationId;
        if (!notificationId) return;
        
        // Find the corresponding notification in cache
        const notification = cache.find(n => n.id == notificationId);
        if (notification && notification.timestamp) {
            const dt = new Date(notification.timestamp);
            if (!isNaN(dt.getTime())) {
                timeElement.textContent = formatRelativeTime(dt);
            }
        }
    });
}

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Expose refreshNotifications globally
    window.refreshNotifications = fetchNotifications;
})();