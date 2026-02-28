/**
 * PDF Viewer Manager
 * ==================
 * Handles PDF.js rendering, pagination, zooming, and full-screen modal view.
 */
class PdfViewer {
    constructor(signatureManager) {
        this.signatureManager = signatureManager;

        // Inline Viewer State
        this.pdfDoc = null;
        this.currentPage = 1;
        this.totalPages = 1;
        this.scale = 1.0;
        this.canvas = null;
        this.ctx = null;

        // Full Modal Viewer State
        this.fullPdfDoc = null;
        this.fullCurrentPage = 1;
        this.fullTotalPages = 1;
        this.fullScale = 1.0;
        this.fullCanvas = null;
        this.fullCtx = null;

        // Drag/Pan State for Full Viewer
        this.isDragMode = false;
        this.isDragging = false;
        this.dragStartX = 0;
        this.dragStartY = 0;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;

        // Bind drag handlers to preserve 'this' context
        this.handleMouseDown = this.handleMouseDown.bind(this);
        this.handleMouseMove = this.handleMouseMove.bind(this);
        this.handleMouseUp = this.handleMouseUp.bind(this);
        
        // --- FIX: Bind Window Resize to fix Browser Zoom Alignment ---
        this.handleResize = this.handleResize.bind(this);
        window.addEventListener('resize', this.handleResize);
    }

    // --- FIX: Recalculates canvas & overlays when browser zooms (Ctrl + Scroll) ---
    handleResize() {
        if (this.resizeTimeout) clearTimeout(this.resizeTimeout);
        this.resizeTimeout = setTimeout(() => {
            const doc = window.documentSystem?.currentDocument || window.documentTracker?.currentDocument;
            if (doc) {
                // If inline viewer is visible, re-render to snap overlays into place
                if (this.pdfDoc && document.getElementById('pdfContent')?.offsetParent) {
                    this.renderPage(doc);
                }
                // If full screen modal is visible, re-render that instead
                if (this.fullPdfDoc && document.getElementById('fullDocumentModal')?.classList.contains('show')) {
                    this.renderFullPage(doc);
                }
            }
        }, 150); // 150ms debounce for smooth performance
    }

    // ------------------------------------------------------------------
    // Inline Document Viewer
    // ------------------------------------------------------------------

    async loadPdf(url, currentDocument) {
        try {
            const loadingEl = document.getElementById('pdfLoading');
            let canvasEl = document.getElementById('pdfCanvas');
            const container = document.getElementById('pdfContent');

            // Bulletproof: Auto-create the canvas if the HTML is missing it!
            if (!canvasEl && container) {
                canvasEl = document.createElement('canvas');
                canvasEl.id = 'pdfCanvas';
                canvasEl.style.cssText = 'display: none; margin: 0 auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
                container.appendChild(canvasEl);
            }

            // Show loader, hide canvas initially
            if (loadingEl) {
                loadingEl.style.display = 'flex';
                loadingEl.innerHTML = `
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="loading-text">Loading document...</p>
                `;
            }
            if (canvasEl) canvasEl.style.display = 'none';

            this.canvas = canvasEl;
            if (!this.canvas) {
                console.error("Critical: Could not find or create PDF canvas.");
                return;
            }
            this.ctx = this.canvas.getContext('2d');

            // Handle URL paths safely
            let pdfUrl = url;
            if (pdfUrl.startsWith('/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(1);
            } else if (pdfUrl.startsWith('../')) {
                pdfUrl = BASE_URL + pdfUrl.substring(3);
            } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(12);
            } else if (!pdfUrl.startsWith('http')) {
                pdfUrl = BASE_URL + (pdfUrl.startsWith('uploads/') ? pdfUrl : 'uploads/' + pdfUrl);
            }

            // Explicitly set the PDF.js worker
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

            // Fetch the document
            this.pdfDoc = await window.pdfjsLib.getDocument(pdfUrl).promise;
            this.totalPages = this.pdfDoc.numPages;
            this.currentPage = 1;
            this.scale = 1.0;

            // Hide loader, show canvas now that it's ready
            if (loadingEl) {
                loadingEl.classList.remove('d-flex');
                loadingEl.style.display = 'none';
            }
            if (canvasEl) canvasEl.style.display = 'block';

            await this.renderPage(currentDocument);
            this.fitToWidth(currentDocument);
            this.updateZoomIndicator();

        } catch (error) {
            console.error("PDF Load Error", error);
            const loadingEl = document.getElementById('pdfLoading');
            if (loadingEl) {
                loadingEl.innerHTML = '<p class="text-danger mt-3 fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Failed to load document preview.</p>';
            }
        }
    }

    async renderPage(currentDocument) {
        if (!this.pdfDoc || !this.canvas) return;

        const page = await this.pdfDoc.getPage(this.currentPage);
        const viewport = page.getViewport({ scale: this.scale });

        this.canvas.height = viewport.height;
        this.canvas.width = viewport.width;

        await page.render({ canvasContext: this.ctx, viewport: viewport }).promise;
        this.updatePageControls();

        // Re-render signature overlays after the page is rendered
        if (currentDocument && this.signatureManager) {
            setTimeout(() => {
                this.signatureManager.renderSignatureOverlay(currentDocument, this.canvas, this.currentPage);
            }, 50);
        }
    }

    updatePageControls() {
        const pageInput = document.getElementById('pageInput');
        const pageTotal = document.getElementById('pageTotal');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');

        if (pageTotal) pageTotal.textContent = this.totalPages;
        if (pageInput) pageInput.value = this.currentPage;
        if (prevBtn) prevBtn.disabled = this.currentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.currentPage >= this.totalPages;
    }

    updateZoomIndicator() {
        const zoomIndicator = document.getElementById('zoomIndicator');
        if (zoomIndicator) zoomIndicator.textContent = `${Math.round(this.scale * 100)}%`;
    }

    prevPage(currentDocument) {
        if (this.currentPage > 1) {
            this.currentPage--;
            this.renderPage(currentDocument);
        }
    }

    nextPage(currentDocument) {
        if (this.currentPage < this.totalPages) {
            this.currentPage++;
            this.renderPage(currentDocument);
        }
    }

    goToPage(pageNum, currentDocument) {
        pageNum = parseInt(pageNum);
        if (pageNum >= 1 && pageNum <= this.totalPages) {
            this.currentPage = pageNum;
            localStorage.setItem('notifications_currentPage', this.currentPage);
            this.renderPage(currentDocument);
        }
    }

    zoomIn(currentDocument) {
        this.scale = Math.min(this.scale + 0.25, 3.0);
        this.renderPage(currentDocument);
        this.updateZoomIndicator();
    }

    zoomOut(currentDocument) {
        this.scale = Math.max(this.scale - 0.25, 0.5);
        this.renderPage(currentDocument);
        this.updateZoomIndicator();
    }

    fitToWidth(currentDocument) {
        if (!this.pdfDoc) return;
        const container = document.getElementById('pdfContent');
        if (!container) return;

        const containerWidth = container.clientWidth - 40; // Padding
        this.pdfDoc.getPage(this.currentPage).then(page => {
            const viewport = page.getViewport({ scale: 1 });
            this.scale = containerWidth / viewport.width;
            this.renderPage(currentDocument).then(() => {
                this.updateZoomIndicator();
            });
        });
    }

    resetZoom(currentDocument) {
        this.scale = 1.0;
        this.renderPage(currentDocument);
        this.updateZoomIndicator();
    }

    // ------------------------------------------------------------------
    // Full Modal Viewer
    // ------------------------------------------------------------------

    openFullViewer(currentDocument) {
        if (!currentDocument) return;

        // Reset drag state
        this.isDragMode = false;
        this.isDragging = false;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;

        const modal = new bootstrap.Modal(document.getElementById('fullDocumentModal'));
        const pdfContent = document.getElementById('fullPdfContent');

        if (pdfContent) {
            pdfContent.innerHTML = '<div id="fullPdfContainer" style="position: relative; min-width: 100%; min-height: 100%; display: flex; align-items: flex-start; justify-content: center;"><canvas id="fullPdfCanvas" style="max-width: none; image-rendering: -webkit-optimize-contrast;"></canvas></div>';
            this.fullCanvas = document.getElementById('fullPdfCanvas');
            this.fullCtx = this.fullCanvas.getContext('2d');
            this.loadFullPdf(currentDocument.file_path, currentDocument);
        }

        const modalElement = document.getElementById('fullDocumentModal');
        modalElement.addEventListener('hidden.bs.modal', () => {
            this.removeDragListeners();
            this.isDragMode = false;
            this.isDragging = false;
        }, { once: true });

        modal.show();
    }

    async loadFullPdf(url, currentDocument) {
        try {
            let pdfUrl = url;
            if (pdfUrl.startsWith('/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(1);
            } else if (pdfUrl.startsWith('../')) {
                pdfUrl = BASE_URL + pdfUrl.substring(3);
            } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
                pdfUrl = BASE_URL + pdfUrl.substring(12);
            } else if (!pdfUrl.startsWith('http')) {
                pdfUrl = BASE_URL + (pdfUrl.startsWith('uploads/') ? pdfUrl : 'uploads/' + pdfUrl);
            }

            this.fullPdfDoc = await window.pdfjsLib.getDocument(pdfUrl).promise;
            this.fullTotalPages = this.fullPdfDoc.numPages;
            this.fullCurrentPage = 1;
            this.fullScale = 1.0;
            this.canvasOffsetX = 0;
            this.canvasOffsetY = 0;

            await this.renderFullPage(currentDocument);
            this.updateFullControls();
        } catch (error) {
            console.error("Full PDF Load Error", error);
            if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'Error', message: 'Failed to load document for full view.' });
        }
    }

    async renderFullPage(currentDocument) {
        if (!this.fullPdfDoc || !this.fullCanvas) return;

        try {
            const page = await this.fullPdfDoc.getPage(this.fullCurrentPage);
            const viewport = page.getViewport({ scale: this.fullScale });

            this.fullCanvas.height = viewport.height;
            this.fullCanvas.width = viewport.width;

            await page.render({ canvasContext: this.fullCtx, viewport: viewport }).promise;

            const container = document.getElementById('fullPdfContainer');
            if (container && currentDocument && this.signatureManager) {
                container.querySelectorAll('.completed-signature-container').forEach(el => el.remove());

                // Override signature manager's page temporarily for full viewer
                const originalPage = this.signatureManager.currentPage;
                this.signatureManager.currentPage = this.fullCurrentPage;
                this.signatureManager.renderCompletedSignatures(currentDocument, container, this.fullCanvas);
                this.signatureManager.currentPage = originalPage; // Restore
            }

            this.updateCanvasPosition(currentDocument);
        } catch (error) {
            console.error("Error rendering full page", error);
        }
    }

    updateFullControls() {
        const pageInput = document.getElementById('fullPageInput');
        const pageTotal = document.getElementById('fullPageTotal');
        const zoomIndicator = document.getElementById('fullZoomIndicator');
        const prevBtn = document.getElementById('fullPrevPageBtn');
        const nextBtn = document.getElementById('fullNextPageBtn');

        if (pageInput) pageInput.value = this.fullCurrentPage;
        if (pageTotal) pageTotal.textContent = this.fullTotalPages;
        if (zoomIndicator) zoomIndicator.textContent = Math.round(this.fullScale * 100) + '%';
        if (prevBtn) prevBtn.disabled = this.fullCurrentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.fullCurrentPage >= this.fullTotalPages;
    }

    async fullPrevPage(currentDocument) {
        if (this.fullCurrentPage > 1) {
            this.fullCurrentPage--;
            await this.renderFullPage(currentDocument);
            this.updateFullControls();
        }
    }

    async fullNextPage(currentDocument) {
        if (this.fullCurrentPage < this.fullTotalPages) {
            this.fullCurrentPage++;
            await this.renderFullPage(currentDocument);
            this.updateFullControls();
        }
    }

    async fullGoToPage(pageNum, currentDocument) {
        const page = parseInt(pageNum);
        if (page >= 1 && page <= this.fullTotalPages) {
            this.fullCurrentPage = page;
            await this.renderFullPage(currentDocument);
            this.updateFullControls();
        }
    }

    async fullZoomIn(currentDocument) {
        this.fullScale = Math.min(this.fullScale + 0.25, 3.0);
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage(currentDocument);
        this.updateFullControls();
    }

    async fullZoomOut(currentDocument) {
        this.fullScale = Math.max(this.fullScale - 0.25, 0.5);
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage(currentDocument);
        this.updateFullControls();
    }

    async fullFitToWidth(currentDocument) {
        if (!this.fullPdfDoc) return;
        this.fullScale = 1.0;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage(currentDocument);
        this.updateFullControls();
    }

    async fullResetZoom(currentDocument) {
        this.fullScale = 1.0;
        this.canvasOffsetX = 0;
        this.canvasOffsetY = 0;
        await this.renderFullPage(currentDocument);
        this.updateFullControls();
    }

    // ------------------------------------------------------------------
    // Full Viewer Drag and Pan Logic
    // ------------------------------------------------------------------

    toggleDragMode() {
        this.isDragMode = !this.isDragMode;
        const content = document.getElementById('fullPdfContent');
        const btn = document.getElementById('dragToggleBtn');

        if (!content || !btn) return;

        if (this.isDragMode) {
            content.style.cursor = 'grab';
            content.classList.add('dragging');
            btn.innerHTML = '<i class="bi bi-hand-index-fill"></i>';
            btn.title = 'Exit Drag Mode';
            this.setupDragListeners();
        } else {
            content.style.cursor = 'default';
            content.classList.remove('dragging');
            btn.innerHTML = '<i class="bi bi-hand-index"></i>';
            btn.title = 'Toggle Drag Mode';
            this.removeDragListeners();
        }
    }

    setupDragListeners() {
        const content = document.getElementById('fullPdfContent');
        if (!content) return;

        content.addEventListener('mousedown', this.handleMouseDown);
        content.addEventListener('mousemove', this.handleMouseMove);
        content.addEventListener('mouseup', this.handleMouseUp);
        content.addEventListener('mouseleave', this.handleMouseUp);

        // Prevent text selection during drag
        this._selectStartHandler = (e) => { if (this.isDragMode) e.preventDefault(); };
        content.addEventListener('selectstart', this._selectStartHandler);
    }

    removeDragListeners() {
        const content = document.getElementById('fullPdfContent');
        if (!content) return;

        content.removeEventListener('mousedown', this.handleMouseDown);
        content.removeEventListener('mousemove', this.handleMouseMove);
        content.removeEventListener('mouseup', this.handleMouseUp);
        content.removeEventListener('mouseleave', this.handleMouseUp);
        if (this._selectStartHandler) {
            content.removeEventListener('selectstart', this._selectStartHandler);
        }
    }

    handleMouseDown(e) {
        if (!this.isDragMode || e.button !== 0) return;

        this.isDragging = true;
        this.dragStartX = e.clientX - this.canvasOffsetX;
        this.dragStartY = e.clientY - this.canvasOffsetY;

        const content = document.getElementById('fullPdfContent');
        content.style.cursor = 'grabbing';
        e.preventDefault();
    }

    handleMouseMove(e) {
        if (!this.isDragging || !this.isDragMode) return;

        this.canvasOffsetX = e.clientX - this.dragStartX;
        this.canvasOffsetY = e.clientY - this.dragStartY;

        // Re-position the canvas dynamically while dragging
        this.updateCanvasPosition(window.documentSystem?.currentDocument);
        e.preventDefault();
    }

    handleMouseUp(e) {
        if (!this.isDragMode) return;

        this.isDragging = false;
        const content = document.getElementById('fullPdfContent');
        if (content) content.style.cursor = this.isDragMode ? 'grab' : 'default';
    }

    updateCanvasPosition(currentDocument) {
        if (!this.fullCanvas) return;

        const container = document.getElementById('fullPdfContainer');
        if (!container) return;

        container.style.transform = `translate(${this.canvasOffsetX}px, ${this.canvasOffsetY}px)`;

        if (currentDocument && this.signatureManager) {
            container.querySelectorAll('.completed-signature-container').forEach(el => el.remove());
            const originalPage = this.signatureManager.currentPage;
            this.signatureManager.currentPage = this.fullCurrentPage;
            this.signatureManager.renderCompletedSignatures(currentDocument, container, this.fullCanvas);
            this.signatureManager.currentPage = originalPage;
        }
    }
}