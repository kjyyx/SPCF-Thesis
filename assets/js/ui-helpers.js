/**
 * Global UI Helpers
 * =================
 */

function showConfirmModal(title, message, onConfirm, confirmText = 'Confirm', confirmClass = 'btn-primary') {
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    document.getElementById('confirmModalLabel').textContent = title;
    document.getElementById('confirmModalMessage').textContent = message;

    const confirmBtn = document.getElementById('confirmModalBtn');
    confirmBtn.textContent = confirmText;
    confirmBtn.className = `btn ${confirmClass}`;

    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    newConfirmBtn.addEventListener('click', () => {
        modal.hide();
        onConfirm();
    });

    modal.show();
}

const STATUS_ALIASES = {
    document: {
        pending: 'submitted',
        in_review: 'in_progress',
        under_review: 'in_progress',
        'under review': 'in_progress',
        'in review': 'in_progress',
        'in progress': 'in_progress',
        completed: 'approved',
        timeout: 'on_hold',
        expired: 'on_hold',
        deleted: 'cancelled'
    },
    step: {
        skipped: 'queued',
        signed: 'completed',
        approved: 'completed',
        in_review: 'pending',
        in_progress: 'pending',
        timeout: 'expired'
    }
};

const STATUS_MAPS = {
    document: {
        draft: { text: 'Draft', class: 'bg-secondary' },
        submitted: { text: 'Newly Submitted', class: 'bg-info text-dark' },
        in_progress: { text: 'Under Review', class: 'bg-primary' },
        on_hold: { text: 'Action Required', class: 'bg-warning text-dark' },
        approved: { text: 'Fully Approved', class: 'bg-success' },
        rejected: { text: 'Declined', class: 'bg-danger' },
        cancelled: { text: 'Cancelled', class: 'bg-dark' }
    },
    step: {
        queued: { text: 'Waiting', class: 'bg-secondary' },
        pending: { text: 'Awaiting Signature', class: 'bg-warning text-dark' },
        completed: { text: 'Signed', class: 'bg-success' },
        rejected: { text: 'Declined', class: 'bg-danger' },
        expired: { text: 'Timed Out', class: 'bg-dark' }
    }
};

function normalizeWorkflowStatus(status, type = 'document') {
    const raw = String(status || '').trim().toLowerCase();
    if (!raw) return '';
    const aliases = STATUS_ALIASES[type] || {};
    return aliases[raw] || raw;
}

function getStatusMeta(status, type = 'document') {
    const normalized = normalizeWorkflowStatus(status, type);
    const typeMap = STATUS_MAPS[type] || {};
    const fallbackText = normalized
        ? normalized.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
        : 'Unknown';
    const match = typeMap[normalized] || { text: fallbackText, class: 'bg-secondary' };
    return { normalized, text: match.text, class: match.class };
}

function getStatusText(status, type = 'document') {
    return getStatusMeta(status, type).text;
}

function getStatusBadge(status, type = 'document') {
    const match = getStatusMeta(status, type);
    return `<span class="badge rounded-pill ${match.class}">${escapeHtml(match.text)}</span>`;
}

function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}