/**
 * Admin Dashboard JavaScript - Complete Administration Interface
 *
 * This file provides comprehensive administrative functionality for the SPCF Thesis system,
 * including user management, materials administration, audit logging, and system monitoring.
 *
 * @fileoverview Admin dashboard client-side functionality
 * @author SPCF Thesis Development Team
 * @version 1.0.0
 * @since 2024
 *
 * @description
 * This JavaScript file handles all client-side operations for the admin dashboard, including:
 * - User account management (CRUD operations, role assignments, validation)
 * - Materials management (upload, download, bulk operations, filtering)
 * - Audit log monitoring and pagination
 * - Real-time statistics and dashboard metrics
 * - Form validation and user interaction handling
 *
 * @architecture
 * The code is organized into logical sections:
 * 1. Global Variables & Configuration
 * 2. Utility Functions (validation, notifications, helpers)
 * 3. Audit Log Management
 * 4. User Management System
 * 5. Materials Management System
 * 6. Initialization & Event Handlers
 *
 * @dependencies
 * - ToastManager (global notification system)
 * - Bootstrap (modal dialogs, UI components)
 * - PHP Backend APIs (users.php, materials.php, audit.php)
 * - Local Storage API (for temporary data persistence)
 * - QRCode library for 2FA setup
 *
 * @security
 * - All sensitive operations are handled server-side
 * - Passwords are never stored or transmitted client-side
 * - API calls include proper authentication headers
 * - Input validation prevents XSS and injection attacks
 *
 * @api
 * - USERS_API: '../api/users.php' - User management endpoints
 * - MATERIALS_API: '../api/materials.php' - Materials management endpoints
 * - AUDIT_API: '../api/audit.php' - Audit logging endpoints
 *
 * @notes
 * - Public function names referenced by HTML must remain unchanged
 * - API endpoints are centralized via apiFetch(); update base paths if folder structure changes
 * - Avoid storing secrets or sensitive data in client-side code
 * - All DOM manipulation uses modern querySelector/getElementById methods
 * - Error handling provides user-friendly feedback via ToastManager
 *
 * @example
 * // File is automatically loaded by admin-dashboard.php
 * // No manual initialization required - uses DOMContentLoaded event
 */

// QRCode library loaded via CDN in admin-dashboard.php

// === Global Variables & Configuration ===
/**
 * @section Global State Variables
 * Core application state that persists across function calls
 * These variables track the current state of the admin interface
 */
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
let currentUser = null; // Current logged-in admin user (set by PHP on page load)
let publicMaterials = []; // Materials list (loaded from API)

/**
 * @section User Management State
 * Variables for tracking user editing and role selection states
 */
let editingUserId = null; // ID of user currently being edited (null when adding new user)
let selectedUserRole = null; // Currently selected role in user modal
let editingUserOriginalRole = null; // Original role before editing (prevents unintended table changes)
let currentUserRoleFilter = null; // Current role filter
let currentUserSearch = null; // Current search query
let currentUserStatus = null; // Current status filter
let currentUserStartDate = null; // Current start date filter
let currentUserEndDate = null; // Current end date filter

/**
 * @section Materials Management State
 * Variables for tracking material selection and current focus
 */
let selectedMaterials = []; // Array of selected material IDs for bulk operations
let currentMaterialId = null; // ID of material currently displayed in detail modal
let currentMaterialsStatus = null; // Current status filter
let currentMaterialsSearch = null; // Current search query
let currentMaterialsStartDate = null; // Current start date filter
let currentMaterialsEndDate = null; // Current end date filter

/**
 * @section Pagination State
 * Variables controlling pagination for different data tables
 * Each section has its own pagination state for independent navigation
 */

// Audit Log Pagination
let currentAuditPage = 1; // Current page number for audit logs
let auditPageSize = 50; // Number of audit entries per page
let totalAuditPages = 1; // Total number of audit pages available
let auditLogs = []; // Current page of audit logs (for detail display)
let currentAuditCategory = null; // Current category filter
let currentAuditSeverity = null; // Current severity filter
let currentAuditSearch = null; // Current search query
let currentAuditStartDate = null; // Current start date filter
let currentAuditEndDate = null; // Current end date filter

// Users Table Pagination
let currentUsersPage = 1; // Current page number for users table
let usersPageSize = 50; // Number of users displayed per page
let totalUsersPages = 1; // Total number of user pages available

// Materials Table Pagination
let currentMaterialsPage = 1; // Current page number for materials table
let materialsPageSize = 50; // Number of materials displayed per page
let totalMaterialsPages = 1; // Total number of material pages available

/**
 * @section Data Storage
 * Main data containers populated from backend APIs
 */
let users = {}; // User database cache - populated from backend API calls

/**
 * @section API Endpoints
 * Centralized API endpoint constants for backend communication
 * Update these paths if the folder structure changes
 */
const USERS_API = BASE_URL + 'api/users.php'; // User management API endpoint
const MATERIALS_API = BASE_URL + 'api/materials.php'; // Materials management API endpoint
const AUDIT_API = BASE_URL + 'api/audit.php'; // Audit logging API endpoint
/**
 * @section Utility Functions
 * Common helper functions used throughout the admin dashboard
 * These functions provide reusable functionality for notifications, validation, and data initialization
 */

/**
 * Display a toast notification to the user
 * @param {string} message - The message to display
 * @param {string} type - The type of notification ('info', 'success', 'warning', 'error')
 * @param {string|null} title - Optional title for the notification
 */
function showToast(message, type = 'info', title = null) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: type,
            title: title,
            message: message,
            duration: 4000
        });
    } else {
        // Fallback to modal if ToastManager is not available
        showConfirmModal(
            title || 'Notification',
            message,
            function() {}, // No action needed
            'OK',
            'btn-primary'
        );
    }
}

/**
 * @section Validation Functions
 * Input validation utilities for forms and user data
 * These functions ensure data integrity and provide user feedback
 */

/**
 * Validate email address format
 * @param {string} email - Email address to validate
 * @returns {boolean} True if email format is valid
 */
function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/**
 * Normalize phone number by removing common punctuation and spaces
 * @param {string} input - Raw phone number input
 * @returns {string} Normalized phone number
 */
function normalizePhone(input) {
    return input.replace(/[\s\-().]/g, '');
}

/**
 * Validate Philippine phone number format
 * Accepts formats: 09XXXXXXXXX or +639XXXXXXXXX
 * @param {string} phone - Phone number to validate
 * @returns {boolean} True if phone number is valid Philippine format
 */
function isValidPHPhone(phone) {
    const normalized = normalizePhone(phone);
    // Accept Philippines mobile format: 09XXXXXXXXX or +639XXXXXXXXX
    return /^(09|\+639)\d{9}$/.test(normalized);
}

/**
 * Display a success message in the user form
 * @param {string} message - Success message to display
 */
function showUserFormSuccess(message) {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${message}</div>`;
}

/**
 * Clear all messages from the user form
 */
function hideUserFormMessages() {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = '';
}

/**
 * @section Audit Log Management System
 * Functions for managing audit logging, monitoring system activities,
 * and displaying audit trails for administrative oversight
 */

/**
 * Add an entry to the server-side audit log
 * This function logs all administrative actions for compliance and monitoring
 * @param {string} action - The action performed (e.g., 'USER_CREATED', 'LOGIN')
 * @param {string} category - Category of the action (e.g., 'User Management', 'Authentication')
 * @param {string} details - Detailed description of what happened
 * @param {string|null} targetId - ID of the affected resource (optional)
 * @param {string|null} targetType - Type of the affected resource (optional)
 * @param {string} severity - Severity level ('INFO', 'WARNING', 'ERROR')
 */
window.addAuditLog = async function (action, category, details, targetId = null, targetType = null, severity = 'INFO') {
    try {
        const response = await fetch(BASE_URL + 'api/audit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action,
                category,
                details,
                target_id: targetId,
                target_type: targetType,
                severity
            })
        });
        const result = await response.json();
        if (!result.success) {
            console.error('Failed to log audit entry:', result.message);
        }
    } catch (e) {
        console.error('Audit log error:', e);
    }
};

/**
 * Load audit logs from server with pagination and filtering
 * Fetches audit entries based on current filters and pagination settings
 * @param {number} page - Page number to load (defaults to 1)
 */
async function loadAuditLogs(page = 1, category = null, severity = null, search = null, startDate = null, endDate = null) {
    currentAuditCategory = category;
    currentAuditSeverity = severity;
    currentAuditSearch = search;
    currentAuditStartDate = startDate;
    currentAuditEndDate = endDate;
    currentAuditPage = page;
    try {
        // Build query parameters
        const params = new URLSearchParams({
            page: currentAuditPage,
            limit: auditPageSize,
            ...(category && { category }),
            ...(severity && { severity }),
            ...(search && { search }),
            ...(startDate && { start_date: startDate }),
            ...(endDate && { end_date: endDate })
        });

        console.log('Fetching audit logs with params:', params.toString());
        const data = await apiFetch(`${AUDIT_API}?${params}`);
        console.log('Audit API response:', data);
        if (data.success) {
            const tbody = document.getElementById('auditTableBody');
            console.log('auditTableBody element:', tbody);
            tbody.innerHTML = '';

            // Render each audit log entry
            data.logs.forEach(entry => {
                const row = createAuditLogRow(entry);
                tbody.appendChild(row);
            });

            auditLogs = data.logs; // Store for details modal
            totalAuditPages = data.totalPages;
            updateAuditPagination();
            console.log('Audit logs loaded successfully, count:', data.logs.length);
        } else {
            console.error('Failed to load audit logs', data.message);
            showToast('Failed to load audit logs: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (e) {
        console.error('Error in loadAuditLogs:', e);
        handleApiError('Error loading audit logs', e, (m) => showToast(m || 'Server error while loading audit logs', 'error'));
    }
}

/**
 * Update the audit log pagination controls
 * Generates page navigation buttons based on current page and total pages
 */
function updateAuditPagination() {
    const paginationEl = document.getElementById('auditPagination');
    if (!paginationEl) return;

    paginationEl.innerHTML = '';

    // Don't show pagination for single page
    if (totalAuditPages <= 1) return;

    // Previous button
    const prevBtn = document.createElement('li');
    prevBtn.className = `page-item ${currentAuditPage === 1 ? 'disabled' : ''}`;
    prevBtn.innerHTML = `<a class="page-link" href="#" onclick="loadAuditLogs(${currentAuditPage - 1}, currentAuditCategory, currentAuditSeverity, currentAuditSearch, currentAuditStartDate, currentAuditEndDate)">Previous</a>`;
    paginationEl.appendChild(prevBtn);

    // Page number buttons (show current ± 2 pages)
    const startPage = Math.max(1, currentAuditPage - 2);
    const endPage = Math.min(totalAuditPages, currentAuditPage + 2);

    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement('li');
        pageBtn.className = `page-item ${i === currentAuditPage ? 'active' : ''}`;
        pageBtn.innerHTML = `<a class="page-link" href="#" onclick="loadAuditLogs(${i}, currentAuditCategory, currentAuditSeverity, currentAuditSearch, currentAuditStartDate, currentAuditEndDate)">${i}</a>`;
        paginationEl.appendChild(pageBtn);
    }

    // Next button
    const nextBtn = document.createElement('li');
    nextBtn.className = `page-item ${currentAuditPage === totalAuditPages ? 'disabled' : ''}`;
    nextBtn.innerHTML = `<a class="page-link" href="#" onclick="loadAuditLogs(${currentAuditPage + 1}, currentAuditCategory, currentAuditSeverity, currentAuditSearch, currentAuditStartDate, currentAuditEndDate)">Next</a>`;
    paginationEl.appendChild(nextBtn);
}

function updateUsersPagination() {
    const paginationEl = document.getElementById('usersPagination');
    if (!paginationEl) return;

    paginationEl.innerHTML = '';

    if (totalUsersPages <= 1) return;

    // Previous button
    const prevBtn = document.createElement('li');
    prevBtn.className = `page-item ${currentUsersPage === 1 ? 'disabled' : ''}`;
    prevBtn.innerHTML = `<a class="page-link" href="#" onclick="loadUsersFromAPI(currentUserRoleFilter, ${currentUsersPage - 1}, currentUserSearch, currentUserStatus, currentUserStartDate, currentUserEndDate)">Previous</a>`;
    paginationEl.appendChild(prevBtn);

    // Page numbers
    const startPage = Math.max(1, currentUsersPage - 2);
    const endPage = Math.min(totalUsersPages, currentUsersPage + 2);

    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement('li');
        pageBtn.className = `page-item ${i === currentUsersPage ? 'active' : ''}`;
        pageBtn.innerHTML = `<a class="page-link" href="#" onclick="loadUsersFromAPI(currentUserRoleFilter, ${i}, currentUserSearch, currentUserStatus, currentUserStartDate, currentUserEndDate)">${i}</a>`;
        paginationEl.appendChild(pageBtn);
    }

    // Next button
    const nextBtn = document.createElement('li');
    nextBtn.className = `page-item ${currentUsersPage === totalUsersPages ? 'disabled' : ''}`;
    nextBtn.innerHTML = `<a class="page-link" href="#" onclick="loadUsersFromAPI(currentUserRoleFilter, ${currentUsersPage + 1}, currentUserSearch, currentUserStatus, currentUserStartDate, currentUserEndDate)">Next</a>`;
    paginationEl.appendChild(nextBtn);
}

function updateMaterialsPagination() {
    const paginationEl = document.getElementById('materialsPagination');
    if (!paginationEl) return;

    paginationEl.innerHTML = '';

    if (totalMaterialsPages <= 1) return;

    // Previous button
    const prevBtn = document.createElement('li');
    prevBtn.className = `page-item ${currentMaterialsPage === 1 ? 'disabled' : ''}`;
    prevBtn.innerHTML = `<a class="page-link" href="#" onclick="loadMaterials(${currentMaterialsPage - 1}, currentMaterialsStatus, currentMaterialsSearch, currentMaterialsStartDate, currentMaterialsEndDate)">Previous</a>`;
    paginationEl.appendChild(prevBtn);

    // Page numbers
    const startPage = Math.max(1, currentMaterialsPage - 2);
    const endPage = Math.min(totalMaterialsPages, currentMaterialsPage + 2);

    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement('li');
        pageBtn.className = `page-item ${i === currentMaterialsPage ? 'active' : ''}`;
        pageBtn.innerHTML = `<a class="page-link" href="#" onclick="loadMaterials(${i}, currentMaterialsStatus, currentMaterialsSearch, currentMaterialsStartDate, currentMaterialsEndDate)">${i}</a>`;
        paginationEl.appendChild(pageBtn);
    }

    // Next button
    const nextBtn = document.createElement('li');
    nextBtn.className = `page-item ${currentMaterialsPage === totalMaterialsPages ? 'disabled' : ''}`;
    nextBtn.innerHTML = `<a class="page-link" href="#" onclick="loadMaterials(${currentMaterialsPage + 1}, currentMaterialsStatus, currentMaterialsSearch, currentMaterialsStartDate, currentMaterialsEndDate)">Next</a>`;
    paginationEl.appendChild(nextBtn);
}

/**
 * @section Initialization & Event Handlers
 * Page initialization, authentication checks, and event binding
 * Ensures proper setup of the admin dashboard on page load
 */

// ==========================================================
// Main Initialization
// ==========================================================

/**
 * Initialize the admin dashboard when the page loads
 * Performs authentication checks, loads initial data, and sets up the UI
 */
document.addEventListener('DOMContentLoaded', async function () {
    console.log('Admin Dashboard loaded');
    console.log('window.currentUser:', window.currentUser);

    // Use the user data passed from PHP backend
    if (window.currentUser) {
        currentUser = window.currentUser;
        console.log('Admin user:', currentUser);

        // Security check: Ensure user has admin role
        if (currentUser.role !== 'admin') {
            console.log('User is not an admin, redirecting...');
            window.location.href = BASE_URL + '?page=calendar';
            return;
        }

        // Update UI with current user information
        const adminUserName = document.getElementById('adminUserName');
        if (adminUserName) {
            adminUserName.textContent = `${currentUser.firstName} ${currentUser.lastName}`;
        }

        // Initialize dashboard
        await loadUsersFromAPI();
        await loadMaterials();
        await loadAuditLogs();
        updateDashboardStats();
        updateDashboardOverview();

        // Load 2FA setting
        try {
            const resp = await fetch(BASE_URL + 'api/settings.php?key=enable_2fa');
            const data = await resp.json();
            const enable2FA = document.getElementById('enable2FA');
            if (enable2FA) {
                enable2FA.checked = data.value === '1';
            }
        } catch (e) {
            console.error('Failed to load 2FA setting:', e);
        }

    } else {
        console.log('No user data, redirecting to login...');
        window.location.href = BASE_URL + '?page=login';
    }
});

// ==========================================================
// Navigation
// ==========================================================
// Navigation functions moved below (single source of truth)

/**
 * @section User Management System
 * Complete user account management functionality including CRUD operations,
 * role-based access control, form validation, and user interface management
 */

/**
 * Update header statistics based on current state
 * Displays total users, materials, and pending approvals in the dashboard header
 */
function updateUserStatistics() {
    const totalUsers = Object.keys(users).length;
    const activeUsers = Object.values(users).filter(user => user.status !== 'inactive').length;
    const pendingMaterials = publicMaterials.filter(m => m.status === 'pending').length;
    const approvedMaterials = publicMaterials.filter(m => m.status === 'approved').length;
    const totalAuditLogs = auditLogs.length;
    const today = new Date().toISOString().split('T')[0];
    const todayLogs = auditLogs.filter(log => log.timestamp && log.timestamp.startsWith(today)).length;

    // Update elements
    const totalUsersEl = document.getElementById('totalUsers');
    const activeUsersEl = document.getElementById('activeUsers');
    const totalMaterialsEl = document.getElementById('totalMaterials');
    const approvedMaterialsEl = document.getElementById('approvedMaterials');
    const pendingApprovalsEl = document.getElementById('pendingApprovals');
    const pendingMaterialsEl = document.getElementById('pendingMaterials');
    const totalAuditLogsEl = document.getElementById('totalAuditLogs');
    const todayLogsEl = document.getElementById('todayLogs');

    if (totalUsersEl) totalUsersEl.textContent = totalUsers;
    if (activeUsersEl) activeUsersEl.textContent = activeUsers;
    if (totalMaterialsEl) totalMaterialsEl.textContent = publicMaterials.length;
    if (approvedMaterialsEl) approvedMaterialsEl.textContent = approvedMaterials;
    if (pendingApprovalsEl) pendingApprovalsEl.textContent = pendingMaterials;
    if (pendingMaterialsEl) pendingMaterialsEl.textContent = pendingMaterials;
    if (totalAuditLogsEl) totalAuditLogsEl.textContent = totalAuditLogs;
    if (todayLogsEl) todayLogsEl.textContent = todayLogs;
}

/**
 * Render the users table based on current users map
 * Populates the users table with all user accounts from the users object
 */
function loadUsersTable() {
    const tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    tbody.innerHTML = '';

    Object.values(users).forEach(user => {
        const row = createUserRow(user);
        tbody.appendChild(row);
    });
}

/**
 * Build a table row element for a given user
 * Creates a formatted HTML table row with user information and action buttons
 * @param {Object} user - User object containing user data
 * @param {string} user.id - Unique user identifier
 * @param {string} user.firstName - User's first name
 * @param {string} user.lastName - User's last name
 * @param {string} user.role - User role (admin, employee, student)
 * @param {string} user.email - User's email address
 * @param {string} user.phone - User's phone number (optional)
 * @param {string} user.department - Department for students (optional)
 * @param {string} user.office - Office for employees/admins (optional)
 * @returns {HTMLElement} Table row element ready for insertion
 */
function createUserRow(user) {
    const row = document.createElement('tr');

    // Role-based styling for badges
    const roleClass = {
        'admin': 'bg-danger',
        'employee': 'bg-primary',
        'student': 'bg-success'
    };

    // Display appropriate organizational unit based on role
    let departmentOrOffice;
    if (user.role === 'student') {
        departmentOrOffice = user.department;
    } else if (user.role === 'employee') {
        departmentOrOffice = `${user.office || ''}${user.office && user.department ? ' / ' : ''}${user.department || ''}`;
    } else {
        departmentOrOffice = user.office;
    }
    const contact = `${user.email}<br><small>${user.phone || '-'}</small>`;

    // Action buttons for editing and deleting users
    const actionButtons = `
        <div class="btn-group" role="group">
            <button class="btn btn-sm btn-outline-primary" onclick="editUser('${user.id}')" title="Edit User">
                <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-outline-warning" onclick="resetUser2FA('${user.id}')" title="Reset 2FA">
                <i class="bi bi-shield-x"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser('${user.id}')" title="Delete User">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;

    row.innerHTML = `
        <td>
            <input type="checkbox" class="user-checkbox" value="${user.id}" onchange="updateBulkSelection()">
        </td>
        <td><strong>${user.id}</strong></td>
        <td>${user.firstName} ${user.lastName}</td>
        <td><span class="badge ${roleClass[user.role]}">${user.role.toUpperCase()}</span></td>
        <td>${departmentOrOffice || '-'}</td>
        <td>${contact}</td>
        <td><span class="badge ${user.status === 'active' ? 'bg-success' : 'bg-secondary'}">${user.status || 'active'}</span></td>
        <td><span class="badge ${user.twoFactorEnabled ? 'bg-success' : 'bg-warning'}">${user.twoFactorEnabled ? 'Enabled' : 'Disabled'}</span></td>
        <td>${actionButtons}</td>
    `;

    return row;
}

/** Open the modal to create a new user. */
function openAddUserModal() {
    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    resetUserForm();
    const modalLabel = document.getElementById('userModalLabel');
    if (modalLabel) modalLabel.textContent = 'Add New User';
    editingUserId = null;
    editingUserOriginalRole = null;
    // Enable role selection for create
    document.querySelectorAll('.role-btn').forEach(btn => btn.disabled = false);
    const idInput = document.getElementById('userIdInput');
    if (idInput) idInput.disabled = false;
    // Show password section for new users
    const passwordSection = document.getElementById('passwordSection');
    const addUserNotice = document.getElementById('addUserNotice');
    const editUserNotice = document.getElementById('editUserNotice');
    if (passwordSection) passwordSection.style.display = 'block';
    if (addUserNotice) addUserNotice.style.display = 'block';
    if (editUserNotice) editUserNotice.style.display = 'none';
    modal.show();
}

/** Reset and sanitize the user form inputs. */
function resetUserForm() {
    const userForm = document.getElementById('userForm');
    if (userForm) userForm.reset();
    selectedUserRole = null;
    editingUserOriginalRole = null;

    // Reset password fields specifically (since form.reset() might not clear them properly)
    const userPassword = document.getElementById('userPassword');
    const userConfirmPassword = document.getElementById('userConfirmPassword');
    if (userPassword) userPassword.value = '';
    if (userConfirmPassword) userConfirmPassword.value = '';

    // Reset password visibility to hidden
    if (userPassword) userPassword.type = 'password';
    if (userConfirmPassword) userConfirmPassword.type = 'password';
    const passwordButtons = document.querySelectorAll('#userPassword ~ .btn, #userConfirmPassword ~ .btn');
    passwordButtons.forEach(btn => {
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'bi bi-eye';
    });

    // Reset role buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Hide role-specific fields and clear constraints
    document.querySelectorAll('.role-fields').forEach(field => {
        field.style.display = 'none';
    });
    setRoleFieldConstraints(null);

    // Hide messages
    hideUserFormMessages();

    // Show password section by default
    const passwordSection = document.getElementById('passwordSection');
    const addUserNotice = document.getElementById('addUserNotice');
    const editUserNotice = document.getElementById('editUserNotice');
    if (passwordSection) passwordSection.style.display = 'block';
    if (addUserNotice) addUserNotice.style.display = 'block';
    if (editUserNotice) editUserNotice.style.display = 'none';
}

/** Handle role button selection in the modal. */
function selectUserRole(role) {
    // Allow admin, employee and student roles
    if (role !== 'admin' && role !== 'employee' && role !== 'student') {
        showUserFormError('Invalid role selection. Please select Admin, Employee, or Student role.');
        return;
    }

    selectedUserRole = role;

    // Reset all buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Highlight selected role
    const selectedBtn = document.querySelector(`[data-role="${role}"]`);
    if (selectedBtn) {
        selectedBtn.classList.add('active');
    }

    // Show/hide role-specific fields and apply constraints
    document.querySelectorAll('.role-fields').forEach(field => {
        field.style.display = 'none';
    });

    if (role === 'admin') {
        // Admin should not show Office/Admin Position dropdowns per requirement
        const adminFields = document.getElementById('adminFields');
        if (adminFields) adminFields.style.display = 'none';
    } else if (role === 'employee') {
        const employeeFields = document.getElementById('employeeFields');
        if (employeeFields) employeeFields.style.display = 'block';
    } else if (role === 'student') {
        const studentFields = document.getElementById('studentFields');
        if (studentFields) studentFields.style.display = 'block';
    }

    setRoleFieldConstraints(role);

    // Clear any previous error messages
    hideUserFormMessages();
}

/**
 * Open the user modal pre-filled for editing an existing user
 * Loads user data into the form and configures the modal for edit mode
 * @param {string} userId - The ID of the user to edit
 */
function editUser(userId) {
    const user = users[userId];
    if (!user) return;

    editingUserId = userId;
    editingUserOriginalRole = user.role;

    // Populate form fields with existing user data
    const userIdInput = document.getElementById('userIdInput');
    const userFirstName = document.getElementById('userFirstName');
    const userLastName = document.getElementById('userLastName');
    const userEmail = document.getElementById('userEmail');
    const userPhone = document.getElementById('userPhone');
    const userPassword = document.getElementById('userPassword');
    const userConfirmPassword = document.getElementById('userConfirmPassword');

    if (userIdInput) {
        userIdInput.value = user.id;
        userIdInput.disabled = true; // prevent PK change during edit
    }
    if (userFirstName) userFirstName.value = user.firstName;
    if (userLastName) userLastName.value = user.lastName;
    if (userEmail) userEmail.value = user.email;
    if (userPhone) userPhone.value = user.phone;

    // Clear password fields for security (don't show existing passwords)
    if (userPassword) userPassword.value = '';
    if (userConfirmPassword) userConfirmPassword.value = '';

    // Select role
    selectUserRole(user.role);
    // Disable changing role during edit to avoid moving across tables
    document.querySelectorAll('.role-btn').forEach(btn => btn.disabled = true);

    // Populate role-specific fields
    if (user.role === 'admin') {
        const adminOffice = document.getElementById('adminOffice');
        const adminPosition = document.getElementById('adminPosition');
        if (adminOffice) adminOffice.value = user.office || '';
        if (adminPosition) adminPosition.value = user.position || '';
    } else if (user.role === 'employee') {
        const employeeDepartment = document.getElementById('employeeDepartment');
        const employeePosition = document.getElementById('employeePosition');
        // Handle position mapping for display
        let displayPosition = user.position;
        let displayDepartment = user.department;
        if (user.position && user.position.startsWith('Dean of ')) {
            const code = user.position.split(' ')[2];
            displayPosition = 'College Dean';
            displayDepartment = CODE_TO_DEPARTMENT[code] || user.department;
        } else if (user.position === 'College Student Council Adviser') {
            displayPosition = 'College Student Council Adviser';
            displayDepartment = user.department;
        }
        // Ensure department select is populated before setting value
        if (employeeDepartment) {
            if (employeeDepartment.options.length <= 1) {
                populateDepartmentSelect(employeeDepartment, COLLEGES);
            }
            employeeDepartment.value = displayDepartment || '';
            employeeDepartment.style.display = 'block';
            employeeDepartment.disabled = false;
            employeeDepartment.required = true;
        }
        if (employeePosition) employeePosition.value = displayPosition || '';
        // Trigger change event to populate department dropdown
        if (employeePosition) employeePosition.dispatchEvent(new Event('change'));
    } else if (user.role === 'student') {
        const studentDepartment = document.getElementById('studentDepartment');
        const studentPosition = document.getElementById('studentPosition');
        if (studentDepartment) studentDepartment.value = user.department || '';
        if (studentPosition) studentPosition.value = user.position || '';
    }

    // Update modal
    const userModalLabel = document.getElementById('userModalLabel');
    const passwordSection = document.getElementById('passwordSection');
    const addUserNotice = document.getElementById('addUserNotice');
    const editUserNotice = document.getElementById('editUserNotice');

    if (userModalLabel) userModalLabel.textContent = 'Edit User';
    // Hide password section for editing (security)
    if (passwordSection) passwordSection.style.display = 'none';
    if (addUserNotice) addUserNotice.style.display = 'none';
    if (editUserNotice) editUserNotice.style.display = 'block';

    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
}

// Ensure hidden role fields are not marked required/disabled incorrectly
/** Apply input required/disabled flags depending on the selected role. */
function setRoleFieldConstraints(role) {
    const adminOffice = document.getElementById('adminOffice');
    const adminPosition = document.getElementById('adminPosition');
    const employeeDepartment = document.getElementById('employeeDepartment');
    const employeePosition = document.getElementById('employeePosition');
    const studentDepartment = document.getElementById('studentDepartment');
    const studentPosition = document.getElementById('studentPosition');

    // Reset all to not required and disabled when role is null
    [adminOffice, adminPosition, employeeDepartment, employeePosition, studentDepartment, studentPosition]
        .forEach(el => { if (el) { el.required = false; el.disabled = true; } });

    // Admin: no office/position dropdowns per request (keep fields disabled)
    if (role === 'employee') {
        if (employeePosition) { employeePosition.disabled = false; employeePosition.required = true; }
        if (employeeDepartment) { employeeDepartment.disabled = false; employeeDepartment.required = false; employeeDepartment.style.display = 'block'; }
    } else if (role === 'student') {
        if (studentDepartment) { studentDepartment.disabled = false; studentDepartment.required = true; studentDepartment.style.display = 'block'; }
        if (studentPosition) { studentPosition.disabled = false; /* optional */ studentPosition.style.display = 'block'; }
    } else {
        // role is null or admin - ensure student/employee fields hidden
        if (studentDepartment) { studentDepartment.disabled = true; studentDepartment.required = false; studentDepartment.style.display = 'none'; }
        if (studentPosition) { studentPosition.disabled = true; studentPosition.style.display = 'none'; }
        if (employeeDepartment) { employeeDepartment.disabled = true; employeeDepartment.required = false; employeeDepartment.style.display = 'none'; }
    }
}

// Department lists used in multiple places
const DEPARTMENTS_FULL = [
    'Supreme Student Council (SSC)',
    'SPCF Miranda',
    'College of Engineering',
    'College of Nursing',
    'College of Business',
    'College of Criminology',
    'College of Computing and Information Sciences',
    'College of Arts, Social Sciences and Education',
    'College of Hospitality and Tourism Management'
];

// Colleges for dean and adviser positions
const COLLEGES = [
    'SPCF Miranda',
    'College of Engineering',
    'College of Nursing',
    'College of Business',
    'College of Criminology',
    'College of Computing and Information Sciences',
    'College of Arts, Social Sciences and Education',
    'College of Hospitality and Tourism Management'
];

// Department code mappings
const DEPARTMENT_CODES = {
    'College of Business': 'COB',
    'College of Engineering': 'COE',
    'College of Criminology': 'COC',
    'College of Arts, Social Sciences and Education': 'COA',
    'College of Nursing': 'CON',
    'College of Hospitality and Tourism Management': 'CHTM',
    'College of Computing and Information Sciences': 'COCS',
    'SPCF Miranda': 'MIRANDA',
    'Supreme Student Council (SSC)': 'SSC'
};

const CODE_TO_DEPARTMENT = Object.fromEntries(Object.entries(DEPARTMENT_CODES).map(([k, v]) => [v, k]));

// Employee roles that require department dropdown
const EMPLOYEE_ROLES_WITH_DEPT = [
    'College Dean',
    'College Student Council Adviser'
];

// Helper to populate a select with department options
function populateDepartmentSelect(selectEl, options) {
    if (!selectEl) return;
    let html = '<option value="">Select Department</option>';
    options.forEach(opt => {
        html += `<option value="${opt}">${opt}</option>`;
    });
    selectEl.innerHTML = html;
}

// Listen for employeePosition changes to toggle employeeDepartment
document.addEventListener('DOMContentLoaded', function () {
    const employeePosition = document.getElementById('employeePosition');
    const employeeDepartment = document.getElementById('employeeDepartment');
    if (employeeDepartment) {
        // default populate with colleges for employee departments
        populateDepartmentSelect(employeeDepartment, COLLEGES);
        employeeDepartment.style.display = 'none';
    }
    // Set student department list to full including SSC
    const studentDepartment = document.getElementById('studentDepartment');
    if (studentDepartment) {
        populateDepartmentSelect(studentDepartment, DEPARTMENTS_FULL);
    }

    if (employeePosition) {
        employeePosition.addEventListener('change', function (e) {
            const val = e.target.value;
            if (!employeeDepartment) return;
            if (EMPLOYEE_ROLES_WITH_DEPT.includes(val)) {
                populateDepartmentSelect(employeeDepartment, COLLEGES);
                employeeDepartment.style.display = 'block';
                employeeDepartment.disabled = false;
                employeeDepartment.required = true;
            } else {
                employeeDepartment.style.display = 'none';
                employeeDepartment.disabled = true;
                employeeDepartment.required = false;
            }
        });
    }
});

/** Ask for confirmation and stage user deletion. */
function deleteUser(userId) {
    const user = users[userId];
    if (!user) return;

    // Prevent deleting current admin (self-deletion protection)
    if (currentUser && currentUser.id === userId) {
        showToast('You cannot delete your own account while logged in.', 'warning');
        return;
    }

    // Show confirmation modal
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    window.userToDelete = userId; // Store for confirmation
    window.bulkDeleteIds = null; // Clear bulk flag
    modal.show();
}

/** Reset 2FA for a user. */
function resetUser2FA(userId) {
    const user = users[userId];
    if (!user) {
        showToast('User not found', 'error');
        return;
    }

    showConfirmModal(
        'Reset 2FA',
        `Are you sure you want to reset 2FA for ${user.firstName} ${user.lastName}? This will disable 2FA and require them to set it up again.`,
        async function() {
            // Call API
            apiFetch(USERS_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reset_2fa',
                    id: userId,
                    role: user.role
                })
            }).then(data => {
                if (data.success) {
                    showToast('2FA reset successfully', 'success');
                    // Refresh the users list
                    refreshUserList();
                } else {
                    showToast('Failed to reset 2FA: ' + (data.message || 'Unknown error'), 'error');
                }
            }).catch(e => {
                handleApiError('Error resetting 2FA', e);
            });
        },
        'Reset 2FA',
        'btn-warning'
    );
}

/** Perform user deletion via API after confirm modal. */
function confirmDeleteUser() {
    // Check if this is a bulk delete
    if (window.bulkDeleteIds && window.bulkDeleteIds.length > 0) {
        confirmBulkDeleteUsers();
        return;
    }

    // Single user delete
    const userId = window.userToDelete;
    if (!userId || !users[userId]) return;
    const u = users[userId];
    // Call API to delete
    fetch(`${USERS_API}?id=${encodeURIComponent(userId)}&role=${encodeURIComponent(u.role)}`, {
        method: 'DELETE'
    }).then(r => r.json()).then(resp => {
        if (resp.success) {
            addAuditLog('USER_DELETED', 'User Management', `Deleted user account: ${u.firstName} ${u.lastName} (${u.id})`, u.id, 'User', 'WARNING');
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
            if (modal) modal.hide();
            loadUsersFromAPI().then(() => {
                updateUserStatistics();
                editingUserId = null;
                showToast('User deleted successfully.', 'success');
            });
        } else {
            showToast(resp.message || 'Delete failed', 'error');
        }
    }).catch(err => {
        console.error('Delete error', err);
        showToast('Server error while deleting user', 'error');
    });
}

// ----------------------------------------------------------
// Form messaging helpers
// ----------------------------------------------------------
function showUserFormError(message) {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${message}</div>`;
}


/** Filter users table by role (dropdown). */
/** Filter users table by role (dropdown). */
function filterUsers() {
    const roleFilter = document.getElementById('userRoleFilter').value;
    const statusFilter = document.getElementById('userStatusFilter').value;
    const startDate = document.getElementById('userDateFrom').value;
    const endDate = document.getElementById('userDateTo').value;
    loadUsersFromAPI(roleFilter, 1, null, statusFilter, startDate, endDate);
}

/** Client-side full table text search for users. */
function searchUsers() {
    const query = document.getElementById('userSearch').value.trim();
    loadUsersFromAPI(currentUserRoleFilter, 1, query, currentUserStatus, currentUserStartDate, currentUserEndDate);
}

/** Re-fetch users and clear filters. */
/** Re-fetch users and clear filters. */
function refreshUserList() {
    currentUsersPage = 1;
    currentUserRoleFilter = null;
    currentUserSearch = null;
    currentUserStatus = null;
    currentUserStartDate = null;
    currentUserEndDate = null;
    loadUsersFromAPI().then(() => updateUserStatistics());
    document.getElementById('userRoleFilter').value = '';
    document.getElementById('userSearch').value = '';
    document.getElementById('userStatusFilter').value = '';
    document.getElementById('userDateFrom').value = '';
    document.getElementById('userDateTo').value = '';
}

/** Export users to CSV (client-side only). */
function exportUsers() {
    const userData = Object.values(users).map(user => {
        let departmentOrOffice;
        if (user.role === 'student') {
            departmentOrOffice = user.department;
        } else if (user.role === 'employee') {
            departmentOrOffice = user.department || '';
        } else {
            departmentOrOffice = user.office;
        }

        return {
            'User ID': user.id,
            'First Name': user.firstName,
            'Last Name': user.lastName,
            'Role': user.role,
            'Department/Office': departmentOrOffice,
            'Position': user.position,
            'Email': user.email,
            'Phone': user.phone
        };
    });

    const csv = convertToCSV(userData);
    downloadCSV(csv, 'university_users.csv');

    // Add audit log
    addAuditLog('USERS_EXPORTED', 'User Management', `Exported ${userData.length} users to CSV`, null, 'System', 'INFO');
}

// Handle user form submission
// ----------------------------------------------------------
// User form submit (create/update)
// ----------------------------------------------------------
document.getElementById('userForm').addEventListener('submit', function (e) {
    e.preventDefault();

    hideUserFormMessages();

    // Allow admin, employee and student roles
    if (!selectedUserRole || (selectedUserRole !== 'admin' && selectedUserRole !== 'employee' && selectedUserRole !== 'student')) {
        showUserFormError('Please select Admin, Employee, or Student role.');
        return;
    }

    const userId = document.getElementById('userIdInput').value.trim();
    const firstName = document.getElementById('userFirstName').value.trim();
    const lastName = document.getElementById('userLastName').value.trim();
    const email = document.getElementById('userEmail').value.trim();
    const phone = document.getElementById('userPhone').value.trim();
    const password = document.getElementById('userPassword').value;
    const confirmPassword = document.getElementById('userConfirmPassword').value;

    // Determine if this is an edit operation
    const isEdit = !!editingUserId;

    // Validation
    if (!userId || !firstName || !lastName || !email || !phone) {
        showUserFormError('Please fill in all required fields.');
        return;
    }

    // Email and phone validation
    if (!isValidEmail(email)) {
        showUserFormError('Please enter a valid email address (e.g., user@example.com).');
        return;
    }
    if (!isValidPHPhone(phone)) {
        showUserFormError('Please enter a valid Philippines mobile number (e.g., 09123456789 or +639123456789).');
        return;
    }

    // Password validation (only for new users)
    if (!isEdit) {
        if (password && password.length < 8) {
            showUserFormError('Password must be at least 8 characters long.');
            return;
        }
        if (password && confirmPassword && password !== confirmPassword) {
            showUserFormError('Passwords do not match.');
            return;
        }
        // If password is provided but confirmation is empty
        if (password && !confirmPassword) {
            showUserFormError('Please confirm the password.');
            return;
        }
        // If confirmation is provided but password is empty
        if (!password && confirmPassword) {
            showUserFormError('Please enter a password first.');
            return;
        }
    }

    // Role-specific validation
    let office, position, department, studentPosition;

    if (selectedUserRole === 'admin') {
        office = document.getElementById('adminOffice').value;
        position = document.getElementById('adminPosition').value;

        if (!office || !position) {
            showUserFormError('Please select both Office and Admin Position for administrators.');
            return;
        }
    } else if (selectedUserRole === 'employee') {
        department = document.getElementById('employeeDepartment').value;
        position = document.getElementById('employeePosition').value;

        if (!department || !position) {
            showUserFormError('Please select Department and Employee Position for employees.');
            return;
        }

        // Map display positions to database positions
        if (position === 'College Dean') {
            position = 'Dean of ' + DEPARTMENT_CODES[department];
        }
        // College Student Council Adviser is already the correct value
    } else if (selectedUserRole === 'student') {
        department = document.getElementById('studentDepartment').value;
        studentPosition = document.getElementById('studentPosition').value;

        if (!department) {
            showUserFormError('Please select a Department/College for students.');
            return;
        }
    }

    // Prepare payload
    const payload = {
        id: userId,
        first_name: firstName,
        last_name: lastName,
        role: selectedUserRole,
        email: email,
        phone: normalizePhone(phone) // store normalized phone
    };

    // Add password if provided (only for new users)
    if (!isEdit && password) {
        payload.default_password = password;
    }

    if (selectedUserRole === 'student') {
        payload.department = department;
        payload.position = studentPosition || 'Regular Student';
    } else {
        payload.position = position;
        if (selectedUserRole === 'admin') {
            payload.office = office;
        } else if (selectedUserRole === 'employee') {
            payload.department = department;
        }
    }

    const roleForUpdate = isEdit ? editingUserOriginalRole : selectedUserRole;
    const request = isEdit
        ? apiFetch(`${USERS_API}?id=${encodeURIComponent(userId)}&role=${encodeURIComponent(roleForUpdate)}`, {
            method: 'PUT',
            body: JSON.stringify(payload)
        })
        : apiFetch(USERS_API, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

    request.then(async resp => {
        if (resp.success) {
            if (isEdit) {
                showUserFormSuccess('User updated successfully!');
                addAuditLog('USER_UPDATED', 'User Management', `Updated user: ${firstName} ${lastName} (${userId})`, userId, 'User', 'INFO');
            } else {
                const passwordMsg = password ? 'The provided password was set.' : 'Default password "ChangeMe123!" was set.';
                showUserFormSuccess(`User created successfully! ${passwordMsg} User must change password on first login.`);
                addAuditLog('USER_CREATED', 'User Management', `Created ${selectedUserRole}: ${firstName} ${lastName} (${userId})`, userId, 'User', 'INFO');
            }
            await loadUsersFromAPI();
            updateUserStatistics();
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('userModal'));
                if (modal) modal.hide();
            }, 1000);
        } else {
            showUserFormError(resp.message || 'Operation failed');
        }
    }).catch(err => handleApiError('User save error', err, showUserFormError));

    // In the user creation success handler (around line 1024), replace QR code logic
    if (result.success) {
        // No QR code here - user will set up 2FA on login
        showToast(`User created successfully! Default password: ${defaultPassword || 'ChangeMe123!'}. User must set up 2FA on first login.`, 'success');
    }
});



// ==========================================================
// Bulk Operations
// ==========================================================

/** Toggle select all users checkbox. */
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAllUsers');
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateBulkSelection();
}

/** Update bulk selection UI. */
function updateBulkSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const bulkBar = document.getElementById('bulkOperationsBar');
    const selectedCount = document.getElementById('selectedCount');

    if (checkboxes.length > 0) {
        bulkBar.style.display = 'flex';
        selectedCount.textContent = checkboxes.length;
    } else {
        bulkBar.style.display = 'none';
    }
}

/** Clear all selections. */
function clearSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAllUsers').checked = false;
    updateBulkSelection();
}

/** Export selected users. */
function bulkExportUsers() {
    const selectedIds = Array.from(document.querySelectorAll('.user-checkbox:checked')).map(cb => cb.value);
    const selectedUsers = Object.values(users).filter(user => selectedIds.includes(user.id));

    addAuditLog('USERS_BULK_EXPORTED', 'User Management', `${selectedIds.length} users exported to CSV`, currentUser?.id, 'User', 'INFO');

    const csv = generateUserCSV(selectedUsers);
    downloadCSV(csv, 'selected_users_export.csv');
}

/** Bulk reset passwords. */
async function bulkResetPasswords() {
    const selectedIds = Array.from(document.querySelectorAll('.user-checkbox:checked')).map(cb => cb.value);

    showConfirmModal(
        'Bulk Password Reset',
        `Reset passwords for ${selectedIds.length} users? They will need to set new passwords on next login.`,
        async function() {
            try {
                const resp = await fetch(BASE_URL + 'api/users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_reset_passwords',
                        user_ids: selectedIds
                    })
                }).then(r => r.json());

                if (resp.success) {
                    showToast(`Successfully reset passwords for ${resp.reset_count} users`, 'success');
                    refreshUserList();
                } else {
                    showToast('Failed to reset passwords: ' + (resp.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                handleApiError('Error resetting passwords', e);
            }
        },
        'Reset Passwords',
        'btn-warning'
    );
}

/** Bulk change role. */
function bulkChangeRole() {
    const selectedIds = Array.from(document.querySelectorAll('.user-checkbox:checked')).map(cb => cb.value);

    if (selectedIds.length === 0) {
        showToast('No users selected.', 'warning');
        return;
    }

    // Create role selection modal
    const roleModal = document.createElement('div');
    roleModal.className = 'modal fade';
    roleModal.innerHTML = `
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Role for ${selectedIds.length} Users</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select New Role</label>
                        <select class="form-select" id="bulkRoleSelect">
                            <option value="admin">Administrator</option>
                            <option value="employee">Employee</option>
                            <option value="student">Student</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmBulkChangeRole()">Change Role</button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(roleModal);
    const modal = new bootstrap.Modal(roleModal);
    modal.show();

    // Store selected IDs for confirmation
    roleModal.dataset.selectedIds = JSON.stringify(selectedIds);

    // Clean up modal after hide
    roleModal.addEventListener('hidden.bs.modal', () => {
        document.body.removeChild(roleModal);
    });
}

/** Confirm bulk role change. */
async function confirmBulkChangeRole() {
    const roleModal = document.querySelector('.modal.show');
    const selectedIds = JSON.parse(roleModal.dataset.selectedIds);
    const newRole = document.getElementById('bulkRoleSelect').value;

    try {
        const resp = await fetch(BASE_URL + 'api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'bulk_change_role',
                user_ids: selectedIds,
                new_role: newRole
            })
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('USERS_BULK_ROLE_CHANGED', 'User Management', `${selectedIds.length} users changed to ${newRole}`, currentUser?.id, 'User', 'INFO');
            showToast(`${selectedIds.length} users updated successfully.`, 'success');
            bootstrap.Modal.getInstance(roleModal).hide();
            clearSelection();
            loadUsers(); // Refresh the table
        } else {
            showToast(resp.message || 'Failed to change roles.', 'error');
        }
    } catch (error) {
        showToast('Server error changing roles.', 'error');
    }
}

/** Bulk delete users. */
function bulkDeleteUsers() {
    const selectedIds = Array.from(document.querySelectorAll('.user-checkbox:checked')).map(cb => cb.value);

    if (selectedIds.length === 0) {
        showToast('No users selected.', 'warning');
        return;
    }

    // Check if trying to delete self
    if (currentUser && selectedIds.includes(currentUser.id)) {
        showToast('You cannot delete your own account while logged in.', 'warning');
        return;
    }

    // Show delete confirmation modal with bulk message
    window.bulkDeleteIds = selectedIds;
    window.userToDelete = null; // Clear single user flag

    // Update modal for bulk delete
    document.querySelector('#deleteConfirmModal .modal-title').innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Confirm Bulk Delete';
    document.querySelector('#deleteConfirmModal .modal-body h5').textContent = `Delete ${selectedIds.length} users?`;
    document.querySelector('#deleteConfirmModal .modal-body p').textContent = 'This action cannot be undone.';
    document.querySelector('#deleteConfirmModal .btn-danger').innerHTML = '<i class="bi bi-trash me-2"></i>Delete Users';

    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    modal.show();
}

/** Perform bulk user deletion. */
async function confirmBulkDeleteUsers() {
    const selectedIds = window.bulkDeleteIds || [];

    if (selectedIds.length === 0) return;

    try {
        const resp = await fetch(BASE_URL + 'api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'bulk_delete_users',
                user_ids: selectedIds
            })
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('USERS_BULK_DELETED', 'User Management', `${selectedIds.length} users deleted`, currentUser?.id, 'User', 'CRITICAL');
            showToast(`${selectedIds.length} users deleted successfully.`, 'success');
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
            if (modal) modal.hide();
            clearSelection();
            loadUsersFromAPI().then(() => {
                updateUserStatistics();
            });
        } else {
            showToast(resp.message || 'Failed to delete users.', 'error');
        }
    } catch (error) {
        showToast('Server error deleting users.', 'error');
    }
}

// Update the delete confirmation to handle bulk
document.querySelector('#deleteConfirmModal .btn-danger').setAttribute('onclick', 'confirmBulkDeleteUsers()');

/** Generate CSV from user data. */
function generateUserCSV(userList) {
    const headers = ['User ID', 'First Name', 'Last Name', 'Role', 'Email', 'Phone', 'Department', 'Office', 'Status'];
    const rows = userList.map(user => [
        user.id,
        user.firstName,
        user.lastName,
        user.role,
        user.email,
        user.phone || '',
        user.department || '',
        user.office || '',
        user.status || 'active'
    ]);

    const csvContent = [headers, ...rows]
        .map(row => row.map(field => `"${field}"`).join(','))
        .join('\n');

    return csvContent;
}

/** Download CSV file. */
function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}


// ==========================================================
// Materials: State, UI, and Actions
// ==========================================================

/**
 * @section Materials Management System
 * Public materials administration including upload tracking, approval workflow,
 * download management, bulk operations, and file organization
 */

/**
 * Render the materials table with current public materials
 * Displays all public materials in a paginated table format
 */
function loadMaterialsTable() {
    const tbody = document.getElementById('materialsTableBody');
    tbody.innerHTML = '';

    publicMaterials.forEach(material => {
        const row = createMaterialRow(material);
        tbody.appendChild(row);
    });

    // Update bulk actions
    updateSelectedMaterials();
}

/** Build a <tr> for a material row. */
function createMaterialRow(material) {
    const row = document.createElement('tr');

    const statusClass = {
        'pending': 'bg-warning text-dark',
        'approved': 'bg-success text-white',
        'rejected': 'bg-danger text-white'
    };

    const submissionDate = new Date(material.uploaded_at).toLocaleDateString();

    row.innerHTML = `
        <td>
            <input type="checkbox" class="material-checkbox" value="${material.id}" onchange="updateSelectedMaterials()">
        </td>
        <td><strong>${material.id}</strong></td>
        <td>${material.title}</td>
        <td>${material.submitted_by || 'Unknown'}</td>
        <td>${material.department || 'N/A'}</td>
        <td><span class="badge ${statusClass[material.status]}">${material.status.toUpperCase()}</span></td>
        <td>${submissionDate}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm btn-outline-info" onclick="viewMaterialDetails('${material.id}')" title="View Details">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteMaterial('${material.id}')" title="Delete Material">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>
    `;

    return row;
}

/** Open the material detail modal for a given ID. */
function viewMaterialDetails(materialId) {
    const material = publicMaterials.find(m => m.id == materialId);
    if (!material) return;

    currentMaterialId = materialId;

    // Populate modal with material details
    document.getElementById('materialDetailId').textContent = material.id;
    document.getElementById('materialDetailFileName').textContent = material.title;
    document.getElementById('materialDetailDescription').textContent = material.description || 'No description provided';
    document.getElementById('materialDetailStatus').innerHTML = `<span class="badge ${getStatusClass(material.status)}">${material.status.toUpperCase()}</span>`;
    document.getElementById('materialDetailSize').textContent = material.file_size_kb ? `${material.file_size_kb} KB` : 'N/A';
    document.getElementById('materialDetailDownloads').textContent = material.downloads || 0;

    // Hide approval/rejection info initially
    const approvalInfo = document.getElementById('materialApprovalInfo');
    const rejectionInfo = document.getElementById('materialRejectionInfo');
    approvalInfo.style.display = 'none';
    rejectionInfo.style.display = 'none';

    if (material.status === 'approved' && material.approved_by_name) {
        document.getElementById('materialApprovedBy').textContent = material.approved_by_name;
        document.getElementById('materialApprovalDate').textContent = material.approved_at ? new Date(material.approved_at).toLocaleString() : 'N/A';
        approvalInfo.style.display = 'block';
    } else if (material.status === 'rejected' && material.rejected_by) {
        document.getElementById('materialRejectedBy').textContent = material.rejected_by;
        document.getElementById('materialRejectionDate').textContent = material.rejected_at ? new Date(material.rejected_at).toLocaleString() : 'N/A';
        document.getElementById('materialRejectionReason').textContent = material.rejection_reason || 'No reason provided';
        rejectionInfo.style.display = 'block';
    }

    const modal = new bootstrap.Modal(document.getElementById('materialDetailModal'));
    modal.show();
}

/** Download the material file. */
function downloadMaterial() {
    if (!currentMaterialId) return;
    window.open(BASE_URL + `api/materials.php?download=1&id=${currentMaterialId}`, '_blank');
}

function getStatusClass(status) {
    const statusClasses = {
        'pending': 'bg-warning text-dark',
        'approved': 'bg-success text-white',
        'rejected': 'bg-danger text-white'
    };
    return statusClasses[status] || 'bg-secondary text-white';
}

/** Ask confirmation and stage material deletion. */
function deleteMaterial(materialId) {
    currentMaterialId = materialId;
    const material = publicMaterials.find(m => m.id === materialId);
    if (!material) return;

    showConfirmModal(
        'Delete Material',
        `Are you sure you want to delete "${material.fileName}"?\n\nThis action cannot be undone.`,
        function() {
            deleteSingleMaterial();
        },
        'Delete',
        'btn-danger'
    );
}

function deleteSingleMaterial() {
    if (!currentMaterialId) return;

    apiFetch(`${MATERIALS_API}?id=${encodeURIComponent(currentMaterialId)}`, {
        method: 'DELETE'
    }).then(resp => {
        if (resp.success) {
            addAuditLog('MATERIAL_DELETED', 'Public Materials', `Deleted material ID: ${currentMaterialId}`, currentMaterialId, 'Material', 'INFO');
            const modal = bootstrap.Modal.getInstance(document.getElementById('materialDetailModal'));
            if (modal) modal.hide();
            loadMaterials();
            updateUserStatistics();
            currentMaterialId = null;
        } else {
            showToast(resp.message || 'Delete failed', 'error');
        }
    }).catch(err => handleApiError('Delete error', err, (m) => showToast(m || 'Server error while deleting material', 'error')));
}

/** Maintain bulk selection state and toggle button states. */
function updateSelectedMaterials() {
    const checkboxes = document.querySelectorAll('.material-checkbox:checked');
    selectedMaterials = Array.from(checkboxes).map(cb => cb.value);

    // Update bulk delete button
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    bulkDeleteBtn.disabled = selectedMaterials.length === 0;
    bulkDeleteBtn.textContent = selectedMaterials.length > 0
        ? `Delete Selected (${selectedMaterials.length})`
        : 'Delete Selected';

    // Update select all checkbox
    const selectAllCheckbox = document.getElementById('selectAllMaterials');
    const allCheckboxes = document.querySelectorAll('.material-checkbox');
    selectAllCheckbox.checked = selectedMaterials.length === allCheckboxes.length && allCheckboxes.length > 0;
    selectAllCheckbox.indeterminate = selectedMaterials.length > 0 && selectedMaterials.length < allCheckboxes.length;
}

function toggleSelectAllMaterials() {
    const selectAllCheckbox = document.getElementById('selectAllMaterials');
    const checkboxes = document.querySelectorAll('.material-checkbox');

    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });

    updateSelectedMaterials();
}

function bulkDeleteMaterials() {
    if (selectedMaterials.length === 0) {
        showToast('Please select materials to delete.', 'warning');
        return;
    }

    showConfirmModal(
        'Bulk Delete Materials',
        `Are you sure you want to delete ${selectedMaterials.length} selected materials?\n\nThis action cannot be undone.`,
        function() {
            confirmBulkDelete();
        },
        'Delete All',
        'btn-danger'
    );
}

function confirmBulkDelete() {
    if (selectedMaterials.length === 0) return;

    const deletedMaterials = [];
    selectedMaterials.forEach(materialId => {
        const material = publicMaterials.find(m => m.id === materialId);
        if (material) {
            deletedMaterials.push(material.fileName);
        }
    });

    // Remove selected materials
    publicMaterials = publicMaterials.filter(m => !selectedMaterials.includes(m.id));
    localStorage.setItem('publicMaterials', JSON.stringify(publicMaterials));

    // Add audit log
    addAuditLog('MATERIALS_BULK_DELETED', 'Public Materials', `Bulk deleted ${selectedMaterials.length} materials: ${deletedMaterials.join(', ')}`, null, 'PublicMaterial', 'INFO');

    // Refresh and clear selection
    loadMaterialsTable();
    updateUserStatistics();
    selectedMaterials = [];
    document.getElementById('selectAllMaterials').checked = false;

    showToast(`Successfully deleted ${deletedMaterials.length} materials.`, 'success');
}

/** Client-side filter materials by status. */
/** Client-side filter materials by status. */
function filterMaterials() {
    const status = document.getElementById('materialStatusFilter').value;
    const startDate = document.getElementById('materialDateFrom').value;
    const endDate = document.getElementById('materialDateTo').value;
    loadMaterials(1, status, null, startDate, endDate);
}

/** Client-side search across materials table. */
function searchMaterials() {
    const query = document.getElementById('materialSearch').value.trim();
    const startDate = document.getElementById('materialDateFrom').value;
    const endDate = document.getElementById('materialDateTo').value;
    loadMaterials(1, null, query, startDate, endDate);
}

/** Reset filters and re-render materials. */
/** Reset filters and re-render materials. */
function refreshMaterialsList() {
    currentMaterialsPage = 1;
    currentMaterialsStatus = null;
    currentMaterialsSearch = null;
    currentMaterialsStartDate = null;
    currentMaterialsEndDate = null;
    loadMaterials(currentMaterialsPage);
    updateUserStatistics();
    document.getElementById('materialStatusFilter').value = '';
    document.getElementById('materialSearch').value = '';
    document.getElementById('materialDateFrom').value = '';
    document.getElementById('materialDateTo').value = '';
    selectedMaterials = [];
}

/** Export materials to CSV. */
function exportMaterials() {
    const materialData = publicMaterials.map(material => ({
        'Material ID': material.id,
        'File Name': material.title,
        'Submitted By': material.submitted_by,
        'Department': material.department,
        'Status': material.status,
        'Submission Date': new Date(material.uploaded_at).toLocaleString(),
        'File Size (KB)': material.file_size_kb,
        'Downloads': material.downloads,
        'Description': material.description || ''
    }));

    const csv = convertToCSV(materialData);
    downloadCSV(csv, 'materials.csv');

    addAuditLog('MATERIALS_EXPORTED', 'Materials', `Exported ${materialData.length} materials to CSV`, null, 'System', 'INFO');
}


// ==========================================================
// Audit Log: UI helpers
// ==========================================================
// Note: Audit details modal is triggered by the eye icon in the audit table
function loadAuditLogTable() {
    loadAuditLogs(currentAuditPage);
}

/** Build a <tr> for an audit log entry. */
function createAuditLogRow(entry) {
    console.log('Creating audit log row for entry:', entry);
    const row = document.createElement('tr');

    const severityClass = {
        'INFO': 'bg-info text-white',
        'WARNING': 'bg-warning text-dark',
        'ERROR': 'bg-danger text-white',
        'CRITICAL': 'bg-dark text-white'
    };

    const timestamp = new Date(entry.timestamp).toLocaleString();

    row.innerHTML = `
        <td>${timestamp}</td>
        <td>${entry.user_name || 'Unknown'}</td>
        <td>${entry.user_id || 'N/A'}</td>
        <td>${entry.user_role || 'system'}</td>
        <td>${entry.action}</td>
        <td>${entry.category}</td>
        <td><span class="badge ${severityClass[entry.severity]}">${entry.severity}</span></td>
        <td>${entry.ip_address || 'N/A'}</td>
        <td>
            <button class="btn btn-sm btn-outline-info" onclick="viewAuditDetails('${entry.id}')" title="View Details">
                <i class="bi bi-eye"></i>
            </button>
        </td>
    `;

    return row;
}

/** Open details modal for an audit log entry. */
function viewAuditDetails(auditId) {
    const entry = auditLogs.find(e => e.id == auditId);
    if (!entry) return;

    // Populate modal with audit details
    document.getElementById('auditDetailTimestamp').textContent = new Date(entry.timestamp).toLocaleString();
    document.getElementById('auditDetailUser').textContent = `${entry.user_name} (${entry.user_id}) - ${entry.user_role}`;
    document.getElementById('auditDetailAction').textContent = `${entry.action} - ${entry.category}`;
    document.getElementById('auditDetailSystem').textContent = `IP: ${entry.ip_address || 'N/A'} | Severity: ${entry.severity}`;

    const modal = new bootstrap.Modal(document.getElementById('auditDetailModal'));
    modal.show();
}

/** Client-side filter audit table by category and severity (now filters loaded data). */
/** Client-side filter audit table by category and severity (now filters loaded data). */
function filterAuditLog() {
    const category = document.getElementById('auditCategoryFilter').value;
    const severity = document.getElementById('auditSeverityFilter').value;
    const startDate = document.getElementById('auditDateFrom').value;
    const endDate = document.getElementById('auditDateTo').value;
    loadAuditLogs(1, category, severity, null, startDate, endDate);
}

/** Client-side search across audit table. */
function searchAuditLog() {
    const search = document.getElementById('auditSearch').value.trim();
    const startDate = document.getElementById('auditDateFrom').value;
    const endDate = document.getElementById('auditDateTo').value;
    loadAuditLogs(1, null, null, search, startDate, endDate);
}

function refreshAuditLog() {
    currentAuditPage = 1;
    currentAuditCategory = null;
    currentAuditSeverity = null;
    currentAuditSearch = null;
    currentAuditStartDate = null;
    currentAuditEndDate = null;
    document.getElementById('auditCategoryFilter').value = '';
    document.getElementById('auditSeverityFilter').value = '';
    document.getElementById('auditSearch').value = '';
    document.getElementById('auditDateFrom').value = '';
    document.getElementById('auditDateTo').value = '';
    loadAuditLogs(currentAuditPage);
}

/** Export audit log to CSV (from current page). */
function exportAuditLog() {
    const auditData = auditLogs.map(entry => ({
        'Audit ID': entry.id,
        'Timestamp': new Date(entry.timestamp).toLocaleString(),
        'User ID': entry.user_id,
        'User Name': entry.user_name,
        'Action': entry.action,
        'Category': entry.category,
        'Details': entry.details,
        'Target ID': entry.target_id || '',
        'Target Type': entry.target_type || '',
        'IP Address': entry.ip_address,
        'Severity': entry.severity
    }));

    const csv = convertToCSV(auditData);
    downloadCSV(csv, 'audit_log.csv');

    // Add audit log
    addAuditLog('AUDIT_EXPORTED', 'System', `Exported ${auditData.length} audit entries to CSV`, null, 'System', 'INFO');
}

function clearAuditLog() {
    showConfirmModal(
        'Clear Audit Log',
        'Are you sure you want to clear all audit log entries?\n\nThis action cannot be undone and will remove all audit history.',
        function() {
            apiFetch(AUDIT_API, { method: 'DELETE' }).then(resp => {
                if (resp.success) {
                    loadAuditLogs(1);
                    addAuditLog('AUDIT_LOG_CLEARED', 'System', 'Cleared audit log', null, 'System', 'WARNING');
                    showToast('Audit log cleared.', 'success');
                } else {
                    showToast('Failed to clear audit logs', 'error');
                }
            }).catch(e => showToast('Server error clearing audit logs', 'error'));
        },
        'Clear All',
        'btn-danger'
    );
}

// ==========================================================
// Settings and Profile
// ==========================================================
function openProfileSettings() {
    addAuditLog('PROFILE_SETTINGS_VIEWED', 'User Management', 'Viewed profile settings', currentUser?.id, 'User', 'INFO');

    // Populate form with current user data
    if (currentUser) {
        document.getElementById('profileFirstName').value = currentUser.firstName || '';
        document.getElementById('profileLastName').value = currentUser.lastName || '';
        document.getElementById('profileEmail').value = currentUser.email || '';
        // Note: Phone and office would need to be fetched from API if not in currentUser
        document.getElementById('profilePhone').value = currentUser.phone || '';
        document.getElementById('profileOffice').value = currentUser.office || '';
    }

    const modal = new bootstrap.Modal(document.getElementById('profileSettingsModal'));
    modal.show();
}

function openSystemSettings() {
    addAuditLog('SYSTEM_SETTINGS_VIEWED', 'System', 'Viewed system settings', currentUser?.id, 'User', 'INFO');

    // Load current system settings (this would typically come from an API)
    loadSystemSettings();

    const modal = new bootstrap.Modal(document.getElementById('systemSettingsModal'));
    modal.show();
}

async function loadSystemSettings() {
    try {
        // This would load settings from the server
        // For now, we'll use default values that are already set in the HTML
        console.log('Loading system settings...');
    } catch (error) {
        console.error('Failed to load system settings:', error);
    }
}

function openChangePassword() {
    const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    modal.show();
}

// Handle change password form
document.getElementById('changePasswordForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const currentPassword = document.getElementById('currentPassword')?.value || '';
    const newPassword = document.getElementById('newPassword')?.value || '';
    const confirmPassword = document.getElementById('confirmPassword')?.value || '';
    const messagesDiv = document.getElementById('changePasswordMessages');

    const show = (html) => { if (messagesDiv) messagesDiv.innerHTML = html; };
    const ok = (msg) => `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) => `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    if (!currentPassword || !newPassword || !confirmPassword) { show(err('All fields are required.')); return; }
    if (newPassword !== confirmPassword) { show(err('New passwords do not match.')); return; }
    const policy = /^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/;
    if (!policy.test(newPassword)) { show(err('Password must be 8+ chars with upper, lower, number, special.')); return; }

    try {
        const resp = await fetch(BASE_URL + 'api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_password', current_password: currentPassword, new_password: newPassword })
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('PASSWORD_CHANGED', 'Security', 'Password changed', currentUser?.id || null, 'User', 'INFO');
            show(ok('Password changed successfully!'));
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
                if (modal) modal.hide();
                if (messagesDiv) messagesDiv.innerHTML = '';
                document.getElementById('changePasswordForm')?.reset();
            }, 1500);
        } else {
            show(err(resp.message || 'Failed to change password.'));
        }
    } catch (e) {
        show(err('Server error changing password.'));
    }
});

// Handle profile settings form
document.getElementById('profileSettingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const firstName = document.getElementById('profileFirstName').value.trim();
    const lastName = document.getElementById('profileLastName').value.trim();
    const email = document.getElementById('profileEmail').value.trim();
    const phone = document.getElementById('profilePhone').value.trim();
    const office = document.getElementById('profileOffice').value;
    const messagesDiv = document.getElementById('profileSettingsMessages');

    const show = (html) => { if (messagesDiv) messagesDiv.innerHTML = html; };
    const ok = (msg) => `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) => `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    if (!firstName || !lastName || !email) {
        show(err('First name, last name, and email are required.'));
        return;
    }

    if (!isValidEmail(email)) {
        show(err('Please enter a valid email address.'));
        return;
    }

    try {
        const resp = await fetch(BASE_URL + 'api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_profile',
                user_id: currentUser?.id,
                first_name: firstName,
                last_name: lastName,
                email: email,
                phone: phone,
                office: office
            })
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('PROFILE_UPDATED', 'User Management', 'Profile updated', currentUser?.id, 'User', 'INFO');
            show(ok('Profile updated successfully!'));

            // Update current user data
            if (currentUser) {
                currentUser.firstName = firstName;
                currentUser.lastName = lastName;
                currentUser.email = email;
                document.getElementById('adminUserName').textContent = `${firstName} ${lastName}`;
            }

            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('profileSettingsModal'));
                if (modal) modal.hide();
                if (messagesDiv) messagesDiv.innerHTML = '';
            }, 1500);
        } else {
            show(err(resp.message || 'Failed to update profile.'));
        }
    } catch (e) {
        show(err('Server error updating profile.'));
    }
});

// Handle system settings forms
document.getElementById('generalSettingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    await saveSystemSettings('general');
});

document.getElementById('securitySettingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    await saveSystemSettings('security');
});

document.getElementById('notificationSettingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    await saveSystemSettings('notifications');
});

document.getElementById('backupSettingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    await saveSystemSettings('backup');
});

async function saveSystemSettings(category) {
    const messagesDiv = document.getElementById('systemSettingsMessages');
    const show = (html) => { if (messagesDiv) messagesDiv.innerHTML = html; };
    const ok = (msg) => `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) => `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    try {
        let settingsData = {};

        switch (category) {
            case 'general':
                settingsData = {
                    system_name: document.getElementById('systemName').value,
                    default_language: document.getElementById('defaultLanguage').value,
                    timezone: document.getElementById('timezone').value,
                    session_timeout: document.getElementById('sessionTimeout').value,
                    max_file_size: document.getElementById('maxFileSize').value,
                    maintenance_mode: document.getElementById('maintenanceMode').checked
                };
                break;
            case 'security':
                settingsData = {
                    password_min_length: document.getElementById('passwordMinLength').value,
                    require_uppercase: document.getElementById('requireUppercase').checked,
                    require_lowercase: document.getElementById('requireLowercase').checked,
                    require_numbers: document.getElementById('requireNumbers').checked,
                    require_special_chars: document.getElementById('requireSpecialChars').checked,
                    max_login_attempts: document.getElementById('maxLoginAttempts').value,
                    lockout_duration: document.getElementById('lockoutDuration').value,
                    enable_2fa: document.getElementById('enable2FA').checked,
                    audit_log_retention: document.getElementById('auditLogRetention').value
                };
                break;
            case 'notifications':
                settingsData = {
                    email_user_registration: document.getElementById('emailUserRegistration').checked,
                    email_document_approval: document.getElementById('emailDocumentApproval').checked,
                    email_system_alerts: document.getElementById('emailSystemAlerts').checked,
                    email_security_events: document.getElementById('emailSecurityEvents').checked,
                    in_app_user_activity: document.getElementById('inAppUserActivity').checked,
                    in_app_document_updates: document.getElementById('inAppDocumentUpdates').checked,
                    in_app_system_maintenance: document.getElementById('inAppSystemMaintenance').checked,
                    admin_email: document.getElementById('adminEmail').value
                };
                break;
            case 'backup':
                settingsData = {
                    backup_frequency: document.getElementById('backupFrequency').value,
                    backup_retention: document.getElementById('backupRetention').value,
                    backup_location: document.getElementById('backupLocation').value,
                    backup_database: document.getElementById('backupDatabase').checked,
                    backup_files: document.getElementById('backupFiles').checked,
                    backup_config: document.getElementById('backupConfig').checked
                };
                break;
        }

        const resp = await fetch(BASE_URL + 'api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(settingsData)
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('SYSTEM_SETTINGS_UPDATED', 'System', `Updated ${category} settings`, currentUser?.id, 'System', 'INFO');
            show(ok(`${category.charAt(0).toUpperCase() + category.slice(1)} settings saved successfully!`));
            setTimeout(() => {
                if (messagesDiv) messagesDiv.innerHTML = '';
            }, 3000);
        } else {
            show(err(resp.message || `Failed to save ${category} settings.`));
        }
    } catch (e) {
        show(err(`Server error saving ${category} settings.`));
    }
}

function runManualBackup() {
    addAuditLog('MANUAL_BACKUP_INITIATED', 'System', 'Manual backup initiated', currentUser?.id, 'System', 'INFO');
    showToast('Manual backup initiated. This may take a few minutes.', 'info');
    // This would trigger a backup process
}

function downloadLatestBackup() {
    addAuditLog('BACKUP_DOWNLOADED', 'System', 'Latest backup downloaded', currentUser?.id, 'System', 'INFO');
    // This would download the latest backup file
    window.open(BASE_URL + 'api/backup.php?action=download_latest', '_blank');
}
// ==========================================================
// Utility helpers (DOM, CSV, Fetch)
// ==========================================================
/** Shortcut for querySelector. */
const qs = (sel, root = document) => root.querySelector(sel);
/** Shortcut for querySelectorAll. */
const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function convertToCSV(data) {
    if (data.length === 0) return '';

    const headers = Object.keys(data[0]);
    const csvContent = [
        headers.join(','),
        ...data.map(row => headers.map(header => `"${(row[header] || '').toString().replace(/"/g, '""')}"`).join(','))
    ].join('\n');

    return csvContent;
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');

    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}
/**
 * API fetch wrapper to unify headers, error handling, and JSON parsing.
 * Returns parsed JSON or throws on network/parse errors.
 */
async function apiFetch(url, options = {}) {
    const defaultHeaders = { 'Content-Type': 'application/json' };
    const merged = { headers: defaultHeaders, ...options };
    try {
        const resp = await fetch(url, merged);
        const data = await resp.json().catch(() => ({}));
        return data;
    } catch (e) {
        throw e;
    }
}

/** Generic API error handler to reduce console + alert boilerplate. */
function handleApiError(prefix, err, notifyFn = (msg) => showToast(msg, 'error')) {
    console.error(prefix, err);
    notifyFn('Server error, please try again');
}

// ==========================================================
// Generic Confirmation Modal
// ==========================================================

/**
 * Show a generic confirmation modal
 * @param {string} title - Modal title
 * @param {string} message - Confirmation message
 * @param {Function} onConfirm - Callback function when confirmed
 * @param {string} confirmText - Text for confirm button (default: 'Confirm')
 * @param {string} confirmClass - Bootstrap class for confirm button (default: 'btn-primary')
 */
function showConfirmModal(title, message, onConfirm, confirmText = 'Confirm', confirmClass = 'btn-primary') {
    const modal = new bootstrap.Modal(document.getElementById('genericConfirmModal'));
    document.getElementById('genericConfirmTitle').textContent = title;
    document.getElementById('genericConfirmMessage').textContent = message;
    const confirmBtn = document.getElementById('genericConfirmBtn');
    confirmBtn.textContent = confirmText;
    confirmBtn.className = `btn ${confirmClass}`;
    
    // Remove previous event listeners
    confirmBtn.replaceWith(confirmBtn.cloneNode(true));
    const newConfirmBtn = document.getElementById('genericConfirmBtn');
    
    // Add new event listener
    newConfirmBtn.addEventListener('click', function() {
        modal.hide();
        onConfirm();
    });
    
    modal.show();
}

// ==========================================================
// API and Loading Functions
// ==========================================================
// Replace older duplicated init/load functions with API-backed ones

async function loadUsersFromAPI(role = null, page = 1, search = null, status = null, startDate = null, endDate = null) {
    currentUserRoleFilter = role;
    currentUserSearch = search;
    currentUserStatus = status;
    currentUserStartDate = startDate;
    currentUserEndDate = endDate;
    currentUsersPage = page;
    const params = new URLSearchParams({
        page: currentUsersPage,
        limit: usersPageSize,
        ...(role && { role }),
        ...(search && { search }),
        ...(status && { status }),
        ...(startDate && { start_date: startDate }),
        ...(endDate && { end_date: endDate })
    });
    const url = `${USERS_API}?${params}`;
    try {
        const data = await apiFetch(url);
        if (data.success) {
            users = data.users || {};
            totalUsersPages = data.totalPages || 1;
            loadUsersTable();
            updateUsersPagination();
            return true;
        } else {
            console.error('Failed to load users', data.message);
            showToast('Failed to load users: ' + (data.message || 'Unknown error'), 'error');
            return false;
        }
    } catch (e) {
        handleApiError('Error loading users', e, (m) => showToast(m || 'Server error while loading users', 'error'));
        return false;
    }
}

// Load materials into the table
async function loadMaterials(page = 1, status = null, search = null, startDate = null, endDate = null) {
    currentMaterialsStatus = status;
    currentMaterialsSearch = search;
    currentMaterialsStartDate = startDate;
    currentMaterialsEndDate = endDate;
    currentMaterialsPage = page;
    try {
        const params = new URLSearchParams({
            page: currentMaterialsPage,
            limit: materialsPageSize,
            ...(status && { status }),
            ...(search && { search }),
            ...(startDate && { start_date: startDate }),
            ...(endDate && { end_date: endDate })
        });
        const data = await apiFetch(`${MATERIALS_API}?${params}`);
        if (data.success) {
            publicMaterials = data.materials || [];
            totalMaterialsPages = data.totalPages || 1;
            loadMaterialsTable();
            updateMaterialsPagination();
        } else {
            console.error('Failed to load materials', data.message);
            showToast('Failed to load materials: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (e) {
        handleApiError('Error loading materials', e, (m) => showToast(m || 'Server error while loading materials', 'error'));
    }
}

// Update dashboard statistics
function updateDashboardStats() {
    const totalUsers = Object.keys(users).length;
    const totalMaterials = publicMaterials.length;
    const pendingApprovals = publicMaterials.filter(m => m.status === 'pending').length;

    document.getElementById('totalUsers').textContent = totalUsers;
    document.getElementById('totalMaterials').textContent = totalMaterials;
    document.getElementById('pendingApprovals').textContent = pendingApprovals;
}

// ==========================================================
// Dashboard Overview Functions
// ==========================================================

let userRoleChart = null;
let materialStatusChart = null;
let auditActivityChart = null;

function refreshDashboard() {
    addAuditLog('DASHBOARD_REFRESHED', 'System', 'Dashboard refreshed', currentUser?.id, 'System', 'INFO');
    loadUsers();
    loadMaterials();
    loadAuditLogs();
    updateDashboardOverview();
}

function updateDashboardOverview() {
    // Update metrics
    const totalUsers = Object.keys(users).length;
    const activeUsers = Object.values(users).filter(user => user.status !== 'inactive').length;
    const totalMaterials = publicMaterials.length;
    const approvedMaterials = publicMaterials.filter(m => m.status === 'approved').length;
    const pendingItems = publicMaterials.filter(m => m.status === 'pending').length;
    const securityEvents = auditLogs.filter(log => log.category === 'Security').length;
    const todayEvents = auditLogs.filter(log => {
        const today = new Date().toISOString().split('T')[0];
        return log.timestamp && log.timestamp.startsWith(today);
    }).length;

    document.getElementById('dashboardTotalUsers').textContent = totalUsers;
    document.getElementById('dashboardActiveUsers').textContent = `${activeUsers} active`;
    document.getElementById('dashboardTotalMaterials').textContent = totalMaterials;
    document.getElementById('dashboardApprovedMaterials').textContent = `${approvedMaterials} approved`;
    document.getElementById('dashboardPendingItems').textContent = pendingItems;
    document.getElementById('dashboardPendingMaterials').textContent = `${pendingItems} materials`;
    document.getElementById('dashboardSecurityEvents').textContent = securityEvents;
    document.getElementById('dashboardTodayEvents').textContent = `${todayEvents} today`;

    // Update charts
    updateUserRoleChart();
    updateMaterialStatusChart();
    updateAuditActivityChart();
    updateRecentActivity();
}

function updateUserRoleChart() {
    const ctx = document.getElementById('userRoleChart');
    if (!ctx) return;

    const userRoles = Object.values(users).reduce((acc, user) => {
        acc[user.role] = (acc[user.role] || 0) + 1;
        return acc;
    }, {});

    if (userRoleChart) userRoleChart.destroy();

    userRoleChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Admin', 'Employee', 'Student'],
            datasets: [{
                data: [userRoles['admin'] || 0, userRoles['employee'] || 0, userRoles['student'] || 0],
                backgroundColor: ['#ef4444', '#3b82f6', '#10b981'],
                borderWidth: 3,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        padding: 12,
                        font: {
                            size: 11,
                            weight: '600'
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    cornerRadius: 8,
                    titleFont: {
                        size: 13,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    }
                }
            },
            cutout: '65%'
        }
    });
}

function updateMaterialStatusChart() {
    const ctx = document.getElementById('materialStatusChart');
    if (!ctx) return;

    const materialStatuses = publicMaterials.reduce((acc, material) => {
        acc[material.status] = (acc[material.status] || 0) + 1;
        return acc;
    }, {});

    if (materialStatusChart) materialStatusChart.destroy();

    materialStatusChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Pending', 'Rejected'],
            datasets: [{
                data: [
                    materialStatuses['approved'] || 0,
                    materialStatuses['pending'] || 0,
                    materialStatuses['rejected'] || 0
                ],
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                borderWidth: 3,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        padding: 12,
                        font: {
                            size: 11,
                            weight: '600'
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    cornerRadius: 8,
                    titleFont: {
                        size: 13,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    }
                }
            },
            cutout: '65%'
        }
    });
}

function updateAuditActivityChart() {
    const ctx = document.getElementById('auditActivityChart');
    if (!ctx) return;

    // Get last 7 days
    const days = [];
    const counts = [];
    for (let i = 6; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        const dateStr = date.toISOString().split('T')[0];
        days.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        counts.push(auditLogs.filter(log => log.timestamp && log.timestamp.startsWith(dateStr)).length);
    }

    if (auditActivityChart) auditActivityChart.destroy();

    auditActivityChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: days,
            datasets: [{
                label: 'Activity',
                data: counts,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    cornerRadius: 8,
                    titleFont: {
                        size: 13,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                x: {
                    ticks: {
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        display: false,
                        drawBorder: false
                    }
                }
            }
        }
    });
}

function updateRecentActivity() {
    const activityList = document.getElementById('recentActivityList');
    if (!activityList) return;

    // Get recent audit logs (last 8 for compact view)
    const recentLogs = auditLogs.slice(0, 8);

    if (recentLogs.length === 0) {
        activityList.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="bi bi-inbox" style="font-size: 2rem; opacity: 0.3;"></i>
                <p class="mt-2 mb-0">No recent activity</p>
            </div>
        `;
        return;
    }

    activityList.innerHTML = recentLogs.map(log => {
        const iconClass = getActivityIconClass(log.category);
        const timeAgo = getTimeAgo(log.timestamp);
        const actionText = log.action.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, l => l.toUpperCase());

        return `
            <div class="activity-item">
                <div class="activity-icon ${iconClass}">
                    <i class="bi ${getActivityIcon(log.category)}"></i>
                </div>
                <div class="activity-content flex-grow-1">
                    <h6 class="mb-1">${actionText}</h6>
                    <p class="mb-0 text-muted" style="font-size: 0.85rem;">${log.details}</p>
                </div>
                <div class="activity-time">
                    <small class="text-muted">${timeAgo}</small>
                </div>
            </div>
        `;
    }).join('');
}

function getActivityIconClass(category) {
    const classes = {
        'Authentication': 'info',
        'User Management': 'success',
        'Security': 'danger',
        'System': 'warning'
    };
    return classes[category] || 'info';
}

function getActivityIcon(category) {
    const icons = {
        'Authentication': 'bi-shield-check',
        'User Management': 'bi-people',
        'Security': 'bi-shield-exclamation',
        'System': 'bi-gear'
    };
    return icons[category] || 'bi-info-circle';
}

function getTimeAgo(timestamp) {
    if (!timestamp) return 'Unknown';

    const now = new Date();
    const logTime = new Date(timestamp);
    const diffMs = now - logTime;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

function exportDashboardReport() {
    addAuditLog('DASHBOARD_REPORT_EXPORTED', 'System', 'Dashboard report exported', currentUser?.id, 'System', 'INFO');

    const reportData = {
        generated: new Date().toISOString(),
        metrics: {
            totalUsers: Object.keys(users).length,
            activeUsers: Object.values(users).filter(user => user.status !== 'inactive').length,
            totalMaterials: publicMaterials.length,
            approvedMaterials: publicMaterials.filter(m => m.status === 'approved').length,
            pendingMaterials: publicMaterials.filter(m => m.status === 'pending').length,
            totalAuditLogs: auditLogs.length
        },
        userRoles: Object.values(users).reduce((acc, user) => {
            acc[user.role] = (acc[user.role] || 0) + 1;
            return acc;
        }, {}),
        materialStatuses: publicMaterials.reduce((acc, material) => {
            acc[material.status] = (acc[material.status] || 0) + 1;
            return acc;
        }, {})
    };

    const csv = convertToCSV([reportData]);
    downloadCSV(csv, `dashboard_report_${new Date().toISOString().split('T')[0]}.csv`);
}

// ==========================================================
// Helper Functions (colors, navigation)
// ==========================================================
// Helper functions for colors
function getRoleColor(role) {
    const colors = { admin: 'danger', employee: 'primary', student: 'success' };
    return colors[role] || 'secondary';
}

function getStatusColor(status) {
    const colors = { approved: 'success', pending: 'warning', rejected: 'danger' };
    return colors[status] || 'secondary';
}

function getSeverityColor(severity) {
    const colors = { INFO: 'info', WARNING: 'warning', ERROR: 'danger' };
    return colors[severity] || 'secondary';
}

// === Basic Utility Functions ===
// Basic utility functions (defined earlier in the file where applicable)

// Remove duplicate stubbed user functions (now handled above)

// Navigation functions (required by UI)
function goToCalendar() {
    window.location.href = BASE_URL + '?page=calendar';
}

function logout() {
    try {
        if (currentUser) {
            window.addAuditLog('LOGOUT', 'Authentication', `Admin ${currentUser.firstName} ${currentUser.lastName} logged out`);
        }
    } catch (e) {
        console.error('Logout audit error:', e);
    }
    window.location.href = BASE_URL + '?page=logout';
}

// End of file

// ==================================
// OneUI Animation Enhancements
// ==================================

// Add smooth page transitions
document.addEventListener('DOMContentLoaded', function() {
    // Fade in elements on load
    document.body.style.opacity = '0';
    setTimeout(() => {
        document.body.style.transition = 'opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
        document.body.style.opacity = '1';
    }, 100);
    
    // Animate cards on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    // Observe cards and sections
    const animatedElements = document.querySelectorAll('.metric-card, .chart-card, .content-header, .recent-activity');
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
        observer.observe(el);
    });
    
    // Add ripple effect to buttons
    document.querySelectorAll('.btn').forEach(button => {
        button.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.background = 'rgba(255, 255, 255, 0.6)';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple-animation 0.6s ease-out';
            ripple.style.pointerEvents = 'none';
            
            this.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
        });
    });
    
    // Smooth tab transitions
    document.querySelectorAll('[data-bs-toggle="pill"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(e) {
            const targetPane = document.querySelector(this.getAttribute('data-bs-target'));
            if (targetPane) {
                targetPane.style.animation = 'fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
            }
        });
    });
    
    // Add hover effects to table rows
    document.querySelectorAll('.data-table tbody tr').forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.01)';
        });
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
});

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(50px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .btn {
        position: relative;
        overflow: hidden;
    }
`;
document.head.appendChild(style);

// End OneUI Enhancements
