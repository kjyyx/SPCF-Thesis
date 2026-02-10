<!-- Notifications Modal - OneUI Enhanced -->
<div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notificationsModalLabel">
                    <i class="bi bi-bell-fill"></i>
                    <span>Notifications</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="notificationsList">
                    <!-- Notifications will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAllAsRead()">
                    <i class="bi bi-check-all me-2"></i>Mark All Read
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo BASE_URL; ?>assets/js/global-notifications.js"></script>