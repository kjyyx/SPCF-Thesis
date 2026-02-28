/**
 * Document Comments Manager
 * =========================
 */
class CommentsManager {
    constructor(apiBase) {
        this.apiBase = apiBase;
        this.threadComments = [];
        this.replyTarget = null;
        this.currentDocumentId = null;
    }

    async init(docId) {
        this.currentDocumentId = docId;
        const input = document.getElementById('threadCommentInput');
        if (input) input.value = '';
        this.clearReplyTarget();

        if (!docId) {
            this.threadComments = [];
            this.render();
            return;
        }
        await this.load();
    }

    async load() {
        try {
            const res = await fetch(`${this.apiBase}?action=get_comments&id=${this.currentDocumentId}`);
            const data = await res.json();
            this.threadComments = data.success && Array.isArray(data.comments) ? data.comments : [];
            this.render();
        } catch (e) {
            this.threadComments = [];
            this.render();
        }
    }

    render() {
        const list = document.getElementById('threadCommentsList');
        if (!list) return;

        if (!this.threadComments.length) {
            list.innerHTML = '<div class="thread-empty-state">No comments yet. Start the discussion.</div>';
            return;
        }

        const grouped = this.threadComments.reduce((acc, comment) => {
            const key = comment.parent_id === null ? 'root' : String(comment.parent_id);
            if (!acc[key]) acc[key] = [];
            acc[key].push(comment);
            return acc;
        }, {});

        const renderNodes = (parentKey, depth = 0) => {
            const children = grouped[parentKey] || [];
            return children.map(c => {
                const timeText = c.created_at ? new Date(c.created_at).toLocaleString() : '';
                return `
                    <div class="thread-comment-item depth-${Math.min(depth, 4)}" data-comment-id="${c.id}">
                        <div class="thread-comment-header">
                            <span class="thread-comment-author">${escapeHtml(c.author_name)}</span>
                            <span class="thread-comment-meta">${escapeHtml(c.author_role)} ${c.author_position ? `• ${escapeHtml(c.author_position)}` : ''}</span>
                        </div>
                        <div class="thread-comment-body">${escapeHtml(c.comment)}</div>
                        <div class="thread-comment-actions">
                            <span class="thread-comment-time">${escapeHtml(timeText)}</span>
                            <button type="button" class="reply-comment-btn" data-id="${c.id}" data-author="${escapeHtml(c.author_name)}">Reply</button>
                        </div>
                        ${renderNodes(String(c.id), depth + 1)}
                    </div>
                `;
            }).join('');
        };

        list.innerHTML = renderNodes('root');

        list.querySelectorAll('.reply-comment-btn').forEach(btn => {
            btn.addEventListener('click', () => this.setReplyTarget(btn.dataset.id, btn.dataset.author));
        });
    }

    setReplyTarget(commentId, authorName) {
        this.replyTarget = { id: Number(commentId), authorName: authorName || 'Unknown' };
        const banner = document.getElementById('commentReplyBanner');
        const nameLabel = document.getElementById('replyAuthorName');
        if (nameLabel) nameLabel.textContent = this.replyTarget.authorName;
        if (banner) banner.style.display = 'flex';
        const input = document.getElementById('threadCommentInput');
        if (input) input.focus();
    }

    clearReplyTarget() {
        this.replyTarget = null;
        const banner = document.getElementById('commentReplyBanner');
        if (banner) banner.style.display = 'none';
    }

    async postComment() {
        if (!this.currentDocumentId) return;
        const input = document.getElementById('threadCommentInput');
        const comment = input?.value.trim();
        if (!comment) return; // Add a toast here if you like

        try {
            const res = await fetch(this.apiBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_comment',
                    document_id: this.currentDocumentId,
                    comment,
                    parent_id: this.replyTarget?.id || null
                })
            });
            const data = await res.json();
            if (data.success) {
                input.value = '';
                this.clearReplyTarget();
                await this.load();
            }
        } catch (e) {
            console.error("Failed to post comment", e);
        }
    }
}