/**
 * Signature Manager (Dynamic Multi-Box Edition)
 * ============================================
 * Allows users to dynamically add, delete, drag, and resize multiple signature 
 * boxes across any page of a document. Future-proof against template changes!
 */
class SignatureManager {
    constructor() {
        this.signatureImage = null;
        this.placedSignatures = []; // Stores array of { id, page, x_pct, y_pct, w_pct, h_pct }

        this.currentCanvas = null;
        this.currentPage = 1;
        this.isReadOnly = false;
    }

    // ------------------------------------------------------------------
    // Smooth Signature Pad & Auto-Cropper
    // ------------------------------------------------------------------

    initSignaturePad() {
        const container = document.getElementById('signaturePadContainer');
        const canvas = document.getElementById('signatureCanvas');
        if (!container || !canvas || canvas.dataset.initialized) return;

        canvas.dataset.initialized = 'true';
        const ctx = canvas.getContext('2d', { willReadFrequently: true });

        // --- FIX 1: Auto-Resizing & High-DPI Support ---
        const resizeCanvas = () => {
            const width = container.clientWidth;
            const height = 200; // STRICTLY LOCK HEIGHT TO 200px!

            if (width === 0) return; // Modal is currently hidden

            const dpr = window.devicePixelRatio || 1;

            // Only resize if dimensions actually changed (prevents accidental clearing)
            if (canvas.width !== Math.floor(width * dpr) || canvas.height !== Math.floor(height * dpr)) {
                // Save existing drawing if any
                let backup = null;
                if (this.hasCanvasContent(canvas)) {
                    backup = ctx.getImageData(0, 0, canvas.width, canvas.height);
                }

                // Force the physical size and logical size to match perfectly
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';
                canvas.width = Math.floor(width * dpr);
                canvas.height = Math.floor(height * dpr);

                ctx.scale(dpr, dpr);
                ctx.lineJoin = 'round';
                ctx.lineCap = 'round';

                if (backup) {
                    ctx.putImageData(backup, 0, 0);
                }
            }
        };

        // Watch the container for size changes (like when the modal opens)
        const ro = new ResizeObserver(() => resizeCanvas());
        ro.observe(container);

        // --- FIX 2: Fountain Pen Physics ---
        let drawing = false;
        let lastPos = null;
        let lastTime = null;
        let lastLineWidth = 2.5;

        // Pen width settings
        const minWidth = 0.8;
        const maxWidth = 3.8;
        const velocityFilterWeight = 0.7; // Smoothing factor for organic transitions

        const pos = (e) => {
            const r = canvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;

            // Returns exact logical CSS coordinates matching the pointer
            return {
                x: clientX - r.left,
                y: clientY - r.top,
                time: Date.now()
            };
        };

        const start = (e) => {
            e.preventDefault();
            drawing = true;
            lastPos = pos(e);
            lastTime = lastPos.time;
            lastLineWidth = (minWidth + maxWidth) / 2;

            ctx.beginPath();
            ctx.fillStyle = '#0f172a'; // Deep ink color
            ctx.arc(lastPos.x, lastPos.y, lastLineWidth / 2, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.moveTo(lastPos.x, lastPos.y);
        };

        const move = (e) => {
            if (!drawing) return;
            e.preventDefault();

            const currentPos = pos(e);
            const dx = currentPos.x - lastPos.x;
            const dy = currentPos.y - lastPos.y;
            const distance = Math.sqrt(dx * dx + dy * dy);
            const timeDiff = currentPos.time - lastTime;

            // Calculate velocity (pixels per ms)
            const velocity = distance / Math.max(1, timeDiff);

            // Target width: Faster = thinner, Slower = thicker
            const targetWidth = Math.max(minWidth, maxWidth - (velocity * 2));

            // Smooth out width changes for organic stroke
            const newLineWidth = (lastLineWidth * velocityFilterWeight) + (targetWidth * (1 - velocityFilterWeight));

            const midPoint = {
                x: lastPos.x + dx / 2,
                y: lastPos.y + dy / 2
            };

            ctx.lineWidth = newLineWidth;
            ctx.strokeStyle = '#0f172a';

            ctx.quadraticCurveTo(lastPos.x, lastPos.y, midPoint.x, midPoint.y);
            ctx.stroke();

            // Reset path from midpoint so next stroke segment can have a different thickness
            ctx.beginPath();
            ctx.moveTo(midPoint.x, midPoint.y);

            lastPos = currentPos;
            lastTime = currentPos.time;
            lastLineWidth = newLineWidth;
        };

        const end = (e) => {
            if (drawing) {
                ctx.lineTo(lastPos.x, lastPos.y);
                ctx.stroke();
            }
            drawing = false;
        };

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);

        // Touch events must be passive: false to prevent mobile scrolling while signing
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        window.addEventListener('touchend', end);

        // Upload handler
        const uploadInput = document.getElementById('signatureUpload');
        if (uploadInput) {
            uploadInput.onchange = (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        const img = new Image();
                        img.onload = () => {
                            // Reset transform before clearing to ensure entire canvas clears
                            ctx.save();
                            ctx.setTransform(1, 0, 0, 1, 0, 0);
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            ctx.restore();

                            const logicalW = canvas.width / (window.devicePixelRatio || 1);
                            const logicalH = canvas.height / (window.devicePixelRatio || 1);
                            const scale = Math.min(logicalW / img.width, logicalH / img.height);
                            const w = img.width * scale, h = img.height * scale;
                            ctx.drawImage(img, (logicalW - w) / 2, (logicalH - h) / 2, w, h);

                            this.saveSignatureState(canvas);
                        };
                        img.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            };
        }

        document.getElementById('sigClearBtn')?.addEventListener('click', () => {
            // Must clear the physical area
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.restore();
        });

        document.getElementById('sigSaveBtn')?.addEventListener('click', () => {
            if (!this.hasCanvasContent(canvas)) {
                if (window.ToastManager) window.ToastManager.show({ type: 'error', title: 'No Signature', message: 'Please draw or upload a signature.' });
                return;
            }
            this.saveSignatureState(canvas);
            if (typeof window.toggleSignaturePad === 'function') window.toggleSignaturePad();
            if (window.ToastManager) window.ToastManager.show({ type: 'success', title: 'Saved', message: 'Signature ready to apply.' });
        });
    }

    hasCanvasContent(canvas) {
        const pixelData = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        for (let i = 3; i < pixelData.length; i += 4) { if (pixelData[i] > 0) return true; }
        return false;
    }

    cropSignatureCanvas(canvas) {
        const ctx = canvas.getContext('2d');
        const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = imgData.data;
        let minX = canvas.width, minY = canvas.height, maxX = 0, maxY = 0;
        let hasContent = false;

        for (let y = 0; y < canvas.height; y++) {
            for (let x = 0; x < canvas.width; x++) {
                if (data[(y * canvas.width + x) * 4 + 3] > 0) {
                    if (x < minX) minX = x; if (y < minY) minY = y;
                    if (x > maxX) maxX = x; if (y > maxY) maxY = y;
                    hasContent = true;
                }
            }
        }
        if (!hasContent) return canvas;

        const pad = 8;
        minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
        maxX = Math.min(canvas.width, maxX + pad); maxY = Math.min(canvas.height, maxY + pad);

        const cropped = document.createElement('canvas');
        cropped.width = maxX - minX; cropped.height = maxY - minY;
        cropped.getContext('2d').putImageData(ctx.getImageData(minX, minY, cropped.width, cropped.height), 0, 0);
        return cropped;
    }

    saveSignatureState(canvas) {
        const cropped = this.cropSignatureCanvas(canvas);
        this.signatureImage = cropped.toDataURL('image/png');

        document.getElementById('signaturePlaceholder')?.classList.add('d-none');
        document.getElementById('signedStatus')?.classList.remove('d-none');

        this.updateDynamicSignaturesWithImage();
        this.triggerValidationUpdate();
    }

    updateDynamicSignaturesWithImage() {
        document.querySelectorAll('.dynamic-signature-box').forEach(box => {
            const id = box.dataset.id;
            if (this.signatureImage) {
                // Ensure the image naturally centers itself within the bounds of the box using width/height 100% and object-fit
                let contentHTML = `<img src="${this.signatureImage}" style="width: 100%; height: 100%; object-fit: contain; pointer-events: none;">`;

                // We must preserve the delete button and the resize handle if we update the HTML!
                const deleteBtnHTML = `<button class="delete-sig-btn" onclick="documentSystem.signatureManager.removeSignatureBox('${id}')" style="position: absolute; top: -10px; right: -10px; width: 22px; height: 22px; border-radius: 50%; background: #ef4444; color: white; border: 2px solid white; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; z-index: 30; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"><i class="bi bi-x"></i></button>`;

                // The resize handle is empty div, we will rebuild it in renderDynamicSignatures, 
                // but since we update HTML here, we can just replace the image part safely if we wrap it.
                // A better approach is to only target the inner image wrapper:
                const imgContainer = box.querySelector('.sig-img-container');
                if (imgContainer) {
                    imgContainer.innerHTML = contentHTML;
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Core Rendering & Positioning
    // ------------------------------------------------------------------

    renderSignatureOverlay(doc, canvas, currentPage) {
        this.currentCanvas = canvas;
        this.currentPage = currentPage;

        const content = document.getElementById('pdfContent');
        if (!content || !canvas) return;

        let wrapper = document.getElementById('sigOverlayWrapper');
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.id = 'sigOverlayWrapper';
            wrapper.style.position = 'absolute';
            content.appendChild(wrapper);
        }

        // Align exactly to the canvas boundaries
        wrapper.style.left = canvas.offsetLeft + 'px';
        wrapper.style.top = canvas.offsetTop + 'px';
        wrapper.style.width = canvas.offsetWidth + 'px';
        wrapper.style.height = canvas.offsetHeight + 'px';

        wrapper.innerHTML = '';
        content.querySelectorAll('.add-sig-floating-btn').forEach(el => el.remove());

        this.renderCompletedSignatures(doc, wrapper);

        if (this.isReadOnly) return;

        if (this.isUserAssignedToPendingStep(doc)) {
            if (this.placedSignatures.length === 0) {
                this.addSignatureBox(false);
            }

            this.renderDynamicSignatures(wrapper);

            const btnContainer = document.createElement('div');
            btnContainer.className = 'add-sig-floating-btn';
            btnContainer.style.cssText = 'position: absolute; top: 15px; left: 50%; transform: translateX(-50%); z-index: 100;';
            btnContainer.innerHTML = `<button class="btn btn-primary shadow-lg rounded-pill fw-bold border-2 border-white" type="button"><i class="bi bi-plus-circle me-2"></i>Add Signature Box</button>`;
            btnContainer.querySelector('button').onclick = () => this.addSignatureBox(true);
            content.appendChild(btnContainer);
        }

        this.triggerValidationUpdate();
    }

    addSignatureBox(shouldRender = true) {
        const cw = this.currentCanvas ? this.currentCanvas.width : 800;
        const ch = this.currentCanvas ? this.currentCanvas.height : 1100;

        this.placedSignatures.push({
            id: Date.now().toString() + Math.floor(Math.random() * 1000),
            page: this.currentPage,
            x_pct: 0.1,
            y_pct: 0.1,
            w_pct: 170 / cw,
            h_pct: 70 / ch
        });

        if (shouldRender) {
            const wrapper = document.getElementById('sigOverlayWrapper');
            if (wrapper) this.renderDynamicSignatures(wrapper);
            this.triggerValidationUpdate();
        }
    }

    removeSignatureBox(id) {
        this.placedSignatures = this.placedSignatures.filter(s => s.id !== id);
        const wrapper = document.getElementById('sigOverlayWrapper');
        if (wrapper) this.renderDynamicSignatures(wrapper);
        this.triggerValidationUpdate();
    }

    renderDynamicSignatures(wrapper) {
        wrapper.querySelectorAll('.dynamic-signature-box').forEach(el => el.remove());

        const pageSigs = this.placedSignatures.filter(s => s.page === this.currentPage);

        pageSigs.forEach(sig => {
            const box = document.createElement('div');
            box.className = 'signature-target dynamic-signature-box';
            box.dataset.id = sig.id;
            box.title = 'Drag to reposition, pull right edge to resize width';

            box.style.cssText = `
                position: absolute; 
                left: ${sig.x_pct * 100}%; 
                top: ${sig.y_pct * 100}%; 
                width: ${sig.w_pct * 100}%; 
                height: ${sig.h_pct * 100}%; 
                z-index: 20; 
                background: rgba(59, 130, 246, 0.15); 
                border: 2px dashed #3b82f6; 
                border-radius: 4px; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                cursor: grab;
            `;

            // Inner container for the image so it's safely isolated from handles
            const imgContainer = document.createElement('div');
            imgContainer.className = 'sig-img-container';
            imgContainer.style.cssText = 'width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; pointer-events: none;';

            if (this.signatureImage) {
                imgContainer.innerHTML = `<img src="${this.signatureImage}" style="width: 100%; height: 100%; object-fit: contain; pointer-events: none;">`;
            } else {
                imgContainer.innerHTML = `<span style="pointer-events:none; font-size:12px; color:#3b82f6; font-weight:bold; text-align:center;">Empty Box<br><small style="font-size:8px">Draw signature in sidebar</small></span>`;
            }
            box.appendChild(imgContainer);

            // Delete Button
            const delBtn = document.createElement('button');
            delBtn.innerHTML = '<i class="bi bi-x"></i>';
            delBtn.className = 'delete-sig-btn';
            delBtn.style.cssText = 'position: absolute; top: -10px; right: -10px; width: 22px; height: 22px; border-radius: 50%; background: #ef4444; color: white; border: 2px solid white; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; z-index: 30; box-shadow: 0 2px 4px rgba(0,0,0,0.2);';
            delBtn.onclick = (e) => {
                e.stopPropagation();
                this.removeSignatureBox(sig.id);
            };
            box.appendChild(delBtn);

            // Resize Width Handle
            const resizeHandle = document.createElement('div');
            resizeHandle.className = 'resize-width-handle';
            resizeHandle.style.cssText = 'position: absolute; top: 0; right: -5px; width: 10px; height: 100%; cursor: ew-resize; z-index: 25; display: flex; align-items: center; justify-content: center;';
            // Add a little visual indicator for the drag handle
            resizeHandle.innerHTML = '<div style="width: 4px; height: 20px; background: #3b82f6; border-radius: 2px;"></div>';
            box.appendChild(resizeHandle);

            wrapper.appendChild(box);

            // Apply Drag and Resize Interactions
            this.makeDraggable(box, wrapper, sig.id);
            this.makeResizableWidth(box, resizeHandle, wrapper, sig.id);
        });
    }

    makeDraggable(element, wrapper, id) {
        let isDragging = false, startX, startY, initialX, initialY;

        element.addEventListener('mousedown', (e) => {
            // Don't drag if clicking delete button or the resize handle
            if (e.target.closest('.delete-sig-btn') || e.target.closest('.resize-width-handle')) return;

            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialX = element.offsetLeft;
            initialY = element.offsetTop;
            element.style.cursor = 'grabbing';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            const maxX = wrapper.offsetWidth - element.offsetWidth;
            const maxY = wrapper.offsetHeight - element.offsetHeight;

            let newX = Math.max(0, Math.min(initialX + dx, maxX));
            let newY = Math.max(0, Math.min(initialY + dy, maxY));

            const sig = this.placedSignatures.find(s => s.id === id);
            if (sig) {
                sig.x_pct = newX / wrapper.offsetWidth;
                sig.y_pct = newY / wrapper.offsetHeight;

                element.style.left = (sig.x_pct * 100) + '%';
                element.style.top = (sig.y_pct * 100) + '%';
            }
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                element.style.cursor = 'grab';
            }
        });
    }

    makeResizableWidth(element, handle, wrapper, id) {
        let isResizing = false, startX, initialWidth;

        handle.addEventListener('mousedown', (e) => {
            e.stopPropagation(); // Prevents the drag logic from firing
            isResizing = true;
            startX = e.clientX;
            initialWidth = element.offsetWidth;
            document.body.style.cursor = 'ew-resize';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            const dx = e.clientX - startX;

            // Allow a minimum width of 50px
            const newWidth = Math.max(50, initialWidth + dx);

            // Prevent resizing out of bounds on the right side
            const maxWidth = wrapper.offsetWidth - element.offsetLeft;
            const finalWidth = Math.min(newWidth, maxWidth);

            const sig = this.placedSignatures.find(s => s.id === id);
            if (sig) {
                sig.w_pct = finalWidth / wrapper.offsetWidth;
                element.style.width = (sig.w_pct * 100) + '%';
            }
        });

        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                document.body.style.cursor = '';
            }
        });
    }

    // ------------------------------------------------------------------
    // PDF-Lib Permanent Embedding
    // ------------------------------------------------------------------

    async applySignatureToPdf(doc) {
        if (!this.signatureImage || this.placedSignatures.length === 0) return null;

        let pdfUrl = doc.file_path;
        if (pdfUrl.startsWith('/')) pdfUrl = BASE_URL + pdfUrl.substring(1);
        else if (pdfUrl.startsWith('../')) pdfUrl = BASE_URL + pdfUrl.substring(3);
        else if (!pdfUrl.startsWith('http')) pdfUrl = BASE_URL + (pdfUrl.startsWith('uploads/') ? pdfUrl : 'uploads/' + pdfUrl);

        const response = await fetch(pdfUrl, { credentials: 'same-origin' });
        const arrayBuffer = await response.arrayBuffer();
        const pdfDoc = await window.PDFLib.PDFDocument.load(arrayBuffer);
        const embeddedSig = await pdfDoc.embedPng(this.signatureImage);

        // Loop through all boxes added by the user and stamp them permanently
        for (const sig of this.placedSignatures) {
            const page = pdfDoc.getPage((sig.page || 1) - 1);
            page.drawImage(embeddedSig, {
                x: sig.x_pct * page.getWidth(),
                y: page.getHeight() - (sig.y_pct * page.getHeight()) - (sig.h_pct * page.getHeight()),
                width: sig.w_pct * page.getWidth(),
                height: sig.h_pct * page.getHeight()
            });
        }

        const modifiedPdfBytes = await pdfDoc.save();
        return new Blob([modifiedPdfBytes], { type: 'application/pdf' });
    }

    // ------------------------------------------------------------------
    // Redaction Rendering (Supports backward compatibility!)
    // ------------------------------------------------------------------

    renderCompletedSignatures(doc, wrapper) {
        if (!doc.workflow) return;
        const completedSignatures = doc.workflow.filter(step => step.status === 'completed' && step.signed_at);

        completedSignatures.forEach((step) => {
            let mapData = step.signature_map || doc.signature_map;
            if (typeof mapData === 'string') {
                try { mapData = JSON.parse(mapData); } catch (e) { return; }
            }
            if (!mapData) return;

            let mapsToRender = [];
            if (Array.isArray(mapData)) {
                mapsToRender = mapData;
            } else if (mapData.accounting && mapData.issuer) {
                mapsToRender = [mapData.accounting, mapData.issuer];
            } else {
                mapsToRender = [mapData];
            }

            mapsToRender.forEach((map) => {
                if ((map.page || 1) !== this.currentPage) return;

                const box = document.createElement('div');
                box.className = 'completed-signature-container';
                box.style.cssText = `
                    position: absolute; 
                    left: ${map.x_pct * 100}%; 
                    top: ${map.y_pct * 100}%; 
                    width: ${map.w_pct * 100}%; 
                    height: ${map.h_pct * 100}%; 
                    z-index: 15; 
                    display: flex; 
                    align-items: center; 
                    justify-content: center;
                `;

                const timestamp = new Date(step.signed_at).toLocaleString([], { year: '2-digit', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

                box.innerHTML = `
                    <div class="signature-redaction" style="width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 4px; border-radius: 8px; background: rgba(30, 30, 35, 0.85); border: 1px solid rgba(255, 255, 255, 0.15); color: rgba(255, 255, 255, 0.95); padding: 6px 10px; backdrop-filter: blur(12px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25); overflow: hidden;">
                        <div style="font-weight: 700; font-size: 10px; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align:center;">${step.assignee_name || 'Unknown'}</div>
                        <div style="font-size: 8px; color: rgba(255, 255, 255, 0.6); width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align:center;">${step.assignee_position || ''}</div>
                        <div style="font-size: 8px; font-weight: 600; color: rgba(255, 255, 255, 0.45); margin-top: 2px;">${timestamp}</div>
                    </div>`;
                wrapper.appendChild(box);
            });
        });
    }

    // ------------------------------------------------------------------
    // Validation Helpers
    // ------------------------------------------------------------------

    isReadyToSign() {
        return this.signatureImage !== null && this.placedSignatures.length > 0;
    }

    triggerValidationUpdate() {
        if (window.documentSystem?.updateApprovalButtonsVisibility && window.documentSystem.currentDocument) {
            window.documentSystem.updateApprovalButtonsVisibility(window.documentSystem.currentDocument);
        }
        if (window.documentTracker?.updateApprovalButtonsVisibility && window.documentTracker.currentDocument) {
            window.documentTracker.updateApprovalButtonsVisibility(window.documentTracker.currentDocument);
        }
    }

    isUserAssignedToPendingStep(doc) {
        const pendingStep = doc.workflow?.find(step => step.status === 'pending');
        if (!pendingStep) return false;
        return pendingStep.assignee_id == window.currentUser.id || pendingStep.assigned_to == window.currentUser.id;
    }
}