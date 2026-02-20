<!-- Notifications Modal - Enhanced -->
<div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notificationsModalLabel">
                    <i class="bi bi-bell-fill"></i>
                    <span>Notifications</span>
                </h5>
                <div class="d-flex gap-2">
                    <!-- <button type="button" class="btn btn-sm btn-outline-secondary" onclick="showPreferencesModal()" title="Notification Settings">
                        <i class="bi bi-gear"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="NotificationFeatures.getStats()" title="Statistics">
                        <i class="bi bi-bar-chart"></i>
                    </button> -->
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <!-- Filter buttons -->
                <!-- <div class="notification-filters mb-3">
                    <button class="filter-btn active" data-notification-filter="document">
                        <i class="bi bi-file-text me-1"></i>Documents
                        <span class="filter-badge" style="display: none;">0</span>
                    </button>
                    <button class="filter-btn active" data-notification-filter="event">
                        <i class="bi bi-calendar me-1"></i>Events
                        <span class="filter-badge" style="display: none;">0</span>
                    </button>
                    <button class="filter-btn active" data-notification-filter="system">
                        <i class="bi bi-gear me-1"></i>System
                        <span class="filter-badge" style="display: none;">0</span>
                    </button>
                </div> -->

                <!-- Notifications list -->
                <div id="notificationsList">
                    <!-- Notifications will be populated here -->
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <!-- <div>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="NotificationFeatures.clearAll()" title="Clear all notifications">
                        <i class="bi bi-trash me-2"></i>Clear All
                    </button>
                </div> -->
                <div>
                    <!-- <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="markAllAsRead()">
                        <i class="bi bi-check-all me-2"></i>Mark All Read
                    </button> -->
                    <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>