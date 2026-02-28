/**
 * Signature Manager
 * =================
 * Handles the smooth signature pad, strictly sized target overlays, 
 * perfectly centered redaction stamps, and PDF-Lib embedding.
 */
class SignatureManager {
    constructor() {
        this.signatureImage = null;
        this.currentSignatureMap = null;

        // Context tracking (updated when rendering overlays)
        this.currentCanvas = null;
        this.currentPage = 1;

        // SAF Dual Signing properties
        this.isSafDualSigning = false;
        this.linkedTargets = null;
        this.linkedSignatureMaps = null;
    }

    // ------------------------------------------------------------------
    // Smooth Signature Pad & Auto-Cropper
    // ------------------------------------------------------------------

    initSignaturePad() {
        const container = document.getElementById('signaturePadContainer');
        const canvas = document.getElementById('signatureCanvas');
        if (!container || !canvas || canvas.dataset.initialized) return;

        canvas.dataset.initialized = 'true';
        canvas.width = container.clientWidth ? Math.min(container.clientWidth - 16, 560) : 560;
        canvas.height = 200;

        const ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#111827';
        ctx.lineWidth = 2.5; // Slightly thicker for a natural ink look
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';

        let drawing = false;
        let lastPos = null;

        const pos = (e) => {
            const r = canvas.getBoundingClientRect();
            return {
                x: (e.touches ? e.touches[0].clientX : e.clientX) - r.left,
                y: (e.touches ? e.touches[0].clientY : e.clientY) - r.top
            };
        };

        // --- FIX: Buttery Smooth Quadratic Bezier Pen Algorithm ---
        const start = (e) => { 
            drawing = true; 
            lastPos = pos(e); 
            ctx.beginPath();
            ctx.arc(lastPos.x, lastPos.y, ctx.lineWidth / 2, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.moveTo(lastPos.x, lastPos.y);
            e.preventDefault(); 
        };
        
        const move = (e) => { 
            if (!drawing) return; 
            const currentPos = pos(e); 
            
            // Calculate midpoint for smooth curving
            const midPoint = {
                x: lastPos.x + (currentPos.x - lastPos.x) / 2,
                y: lastPos.y + (currentPos.y - lastPos.y) / 2
            };

            ctx.quadraticCurveTo(lastPos.x, lastPos.y, midPoint.x, midPoint.y);
            ctx.stroke();
            lastPos = currentPos;
            e.preventDefault(); 
        };
        
        const end = () => { drawing = false; };

        canvas.onmousedown = start; canvas.onmousemove = move; window.addEventListener('mouseup', end);
        canvas.ontouchstart = start; canvas.ontouchmove = move; window.addEventListener('touchend', end);

        // Handle Image Upload
        const uploadInput = document.getElementById('signatureUpload');
        if (uploadInput) {
            uploadInput.onchange = (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        const img = new Image();
                        img.onload = () => {
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
                            const w = img.width * scale, h = img.height * scale;
                            ctx.drawImage(img, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
                            this.saveSignatureState(canvas);
                        };
                        img.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            };
        }

        // Button Actions
        document.getElementById('sigClearBtn')?.addEventListener('click', () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
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

    // --- FIX: Auto-crops whitespace so the signature PERFECTLY fills the 174x85 box! ---
    cropSignatureCanvas(canvas) {
        const ctx = canvas.getContext('2d');
        const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = imgData.data;
        let minX = canvas.width, minY = canvas.height, maxX = 0, maxY = 0;
        let hasContent = false;

        for (let y = 0; y < canvas.height; y++) {
            for (let x = 0; x < canvas.width; x++) {
                const alpha = data[(y * canvas.width + x) * 4 + 3];
                if (alpha > 0) {
                    if (x < minX) minX = x;
                    if (y < minY) minY = y;
                    if (x > maxX) maxX = x;
                    if (y > maxY) maxY = y;
                    hasContent = true;
                }
            }
        }

        if (!hasContent) return canvas;

        const padding = 8; // Small safety padding
        minX = Math.max(0, minX - padding);
        minY = Math.max(0, minY - padding);
        maxX = Math.min(canvas.width, maxX + padding);
        maxY = Math.min(canvas.height, maxY + padding);

        const width = maxX - minX;
        const height = maxY - minY;

        const croppedCanvas = document.createElement('canvas');
        croppedCanvas.width = width;
        croppedCanvas.height = height;
        croppedCanvas.getContext('2d').putImageData(ctx.getImageData(minX, minY, width, height), 0, 0);
        return croppedCanvas;
    }

    saveSignatureState(canvas) {
        // Crop it tightly before saving so it maximizes the space when applied
        const cropped = this.cropSignatureCanvas(canvas);
        this.signatureImage = cropped.toDataURL('image/png');
        
        this.updateSignatureOverlayImage();
        document.getElementById('signaturePlaceholder')?.classList.add('d-none');
        document.getElementById('signedStatus')?.classList.remove('d-none');

        if (window.documentSystem?.updateApprovalButtonsVisibility && window.documentSystem.currentDocument) {
            window.documentSystem.updateApprovalButtonsVisibility(window.documentSystem.currentDocument);
        }
    }

    // ------------------------------------------------------------------
    // PDF-Lib Generation
    // ------------------------------------------------------------------

    async applySignatureToPdf(doc) {
        if (!this.signatureImage) return null;

        let pdfUrl = doc.file_path;
        if (pdfUrl.startsWith('/')) {
            pdfUrl = BASE_URL + pdfUrl.substring(1);
        } else if (pdfUrl.startsWith('../')) {
            pdfUrl = BASE_URL + pdfUrl.substring(3);
        } else if (pdfUrl.startsWith('SPCF-Thesis/')) {
            pdfUrl = BASE_URL + pdfUrl.substring(12);
        } else if (!pdfUrl.startsWith('http')) {
            pdfUrl = BASE_URL + (pdfUrl.startsWith('uploads/') ? pdfUrl : 'uploads/' + pdfUrl);
        }

        const response = await fetch(pdfUrl, { credentials: 'same-origin' });
        if (!response.ok) {
            console.error(`Failed to fetch PDF from: ${pdfUrl}`);
            throw new Error(`Failed to fetch PDF`);
        }

        const arrayBuffer = await response.arrayBuffer();
        const pdfDoc = await window.PDFLib.PDFDocument.load(arrayBuffer);
        const embeddedSig = await pdfDoc.embedPng(this.signatureImage);

        if (this.isSafDualSigning && this.linkedSignatureMaps) {
            const acct = this.linkedSignatureMaps.accounting;
            const acctPage = pdfDoc.getPage((acct.page || 1) - 1);
            acctPage.drawImage(embeddedSig, {
                x: acct.x_pct * acctPage.getWidth(),
                y: acctPage.getHeight() - (acct.y_pct * acctPage.getHeight()) - (acct.h_pct * acctPage.getHeight()),
                width: acct.w_pct * acctPage.getWidth(),
                height: acct.h_pct * acctPage.getHeight()
            });

            const issuer = this.linkedSignatureMaps.issuer;
            const issuerPage = pdfDoc.getPage((issuer.page || 1) - 1);
            issuerPage.drawImage(embeddedSig, {
                x: issuer.x_pct * issuerPage.getWidth(),
                y: issuerPage.getHeight() - (issuer.y_pct * issuerPage.getHeight()) - (issuer.h_pct * issuerPage.getHeight()),
                width: issuer.w_pct * issuerPage.getWidth(),
                height: issuer.h_pct * issuerPage.getHeight()
            });
        } else {
            const map = this.currentSignatureMap;
            const page = pdfDoc.getPage((map.page || this.currentPage) - 1);
            page.drawImage(embeddedSig, {
                x: map.x_pct * page.getWidth(),
                y: page.getHeight() - (map.y_pct * page.getHeight()) - (map.h_pct * page.getHeight()),
                width: map.w_pct * page.getWidth(),
                height: map.h_pct * page.getHeight()
            });
        }

        const modifiedPdfBytes = await pdfDoc.save();
        return new Blob([modifiedPdfBytes], { type: 'application/pdf' });
    }

    // ------------------------------------------------------------------
    // Overlays & UI Positioning
    // ------------------------------------------------------------------

    renderSignatureOverlay(doc, canvas, currentPage) {
        this.currentCanvas = canvas;
        this.currentPage = currentPage;

        const content = document.getElementById('pdfContent');
        if (!content || !canvas) return;

        content.style.position = 'relative';
        content.querySelectorAll('.signature-target, .completed-signature-container, .linked-connection').forEach(el => el.remove());

        // Redactions will still render perfectly!
        this.renderCompletedSignatures(doc, content, canvas);

        const pendingStep = doc.workflow?.find(step => step.status === 'pending');
        const user = window.currentUser;
        const isAssigned = pendingStep && (pendingStep.assignee_id == user.id || pendingStep.assigned_to == user.id);

        // --- FIX: Only render the draggable targets if NOT in Read-Only mode! ---
        if (isAssigned && !this.isReadOnly) {
            if (doc.doc_type === 'saf') {
                this.isSafDualSigning = true;
                this.createLinkedSafSignatureTargets(content, doc);
            } else {
                this.isSafDualSigning = false;
                this.createSingleSignatureTarget(content, doc);
            }
        }
    }

    // --- FIX: Perfectly Centered Redaction stamps bound exactly to the target coordinates ---
    renderCompletedSignatures(doc, container, canvas) {
        if (!doc.workflow || !canvas) return;

        const completedSignatures = doc.workflow.filter(step => step.status === 'completed' && step.signed_at);

        completedSignatures.forEach((step) => {
            let mapData = step.signature_map || doc.signature_map;
            if (typeof mapData === 'string') {
                try { mapData = JSON.parse(mapData); } catch (e) { return; }
            }
            if (!mapData) return;

            let mapsToRender = (doc.doc_type === 'saf' && mapData.accounting && mapData.issuer)
                ? [mapData.accounting, mapData.issuer]
                : [mapData];

            mapsToRender.forEach((map) => {
                if ((map.page || 1) !== this.currentPage) return;

                let rect = this.computeSignaturePixelRectForContainer(map, canvas, container);
                if (!rect) return;

                const box = document.createElement('div');
                box.className = 'completed-signature-container';
                // By forcing the width and height to EXACTLY match rect.width/height, it will scale perfectly and allow exact centering
                box.style.cssText = `position: absolute; left: ${rect.left}px; top: ${rect.top}px; z-index: 15; width: ${rect.width}px; height: ${rect.height}px; display: flex; align-items: center; justify-content: center;`;

                const timestamp = new Date(step.signed_at).toLocaleString([], { year: '2-digit', month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });
                const flexDir = doc.doc_type === 'saf' ? 'column' : 'row';

                box.innerHTML = `
                    <div class="signature-redaction" style="
                        width: 100%;
                        height: 100%;
                        display: flex;
                        flex-direction: ${flexDir};
                        justify-content: center;
                        align-items: center;
                        gap: 4px;
                        border-radius: 8px;
                        background: rgba(30, 30, 35, 0.85);
                        border: 1px solid rgba(255, 255, 255, 0.15);
                        color: rgba(255, 255, 255, 0.95);
                        padding: 6px 10px;
                        backdrop-filter: blur(12px) saturate(180%);
                        -webkit-backdrop-filter: blur(12px) saturate(180%);
                        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
                        box-sizing: border-box;
                        overflow: hidden;
                    ">
                        <div style="
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            text-align: center;
                            gap: 1px;
                            min-width: 0;
                            overflow: hidden;
                        ">
                            <div style="font-weight: 700; font-size: 10px; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                ${step.assignee_name || 'Unknown'}
                            </div>
                            <div style="font-size: 8px; color: rgba(255, 255, 255, 0.6); width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                ${step.assignee_position || ''}
                            </div>
                            <div style="font-size: 8px; font-weight: 600; color: rgba(255, 255, 255, 0.45); margin-top: 2px;">
                                ${timestamp}
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(box);
            });
        });
    }

    createSingleSignatureTarget(content, doc) {
        let map = doc.signature_map;
        try { map = (typeof map === 'string') ? JSON.parse(map) : map; } catch (e) { map = null; }

        // --- FIX: Set specific aspect ratio so when scaled, it translates to roughly 174x85 ---
        if (!map || !map.w_pct) {
            const cw = this.currentCanvas ? this.currentCanvas.width : 800;
            const ch = this.currentCanvas ? this.currentCanvas.height : 1100;
            map = { 
                x_pct: 0.1, 
                y_pct: 0.1, 
                w_pct: 170 / cw,  // Mathematically locked 174 pixels
                h_pct: 70 / ch,   // Mathematically locked 85 pixels
                page: 1 
            };
        }

        const box = document.createElement('div');
        box.className = 'signature-target draggable';
        box.title = 'Drag to position your signature';
        box.style.cssText = 'position: absolute; display: flex; align-items: center; justify-content: center; cursor: grab; z-index: 20; background: rgba(59, 130, 246, 0.15); border: 2px dashed #3b82f6; border-radius: 4px;';

        content.appendChild(box);

        const rect = this.computeSignaturePixelRect(map);
        if (rect) {
            box.style.left = rect.left + 'px';
            box.style.top = rect.top + 'px';
            box.style.width = rect.width + 'px';
            box.style.height = rect.height + 'px';
        }

        this.makeDraggable(box, content);
        
        // --- FIX: Resizable handles have been completely stripped off ---
        this.updateSignatureMap(box, content);

        if (this.signatureImage) this.updateSignatureOverlayImage();
    }

    // ------------------------------------------------------------------
    // SAF Dual Linked Targets
    // ------------------------------------------------------------------

    createLinkedSafSignatureTargets(content, doc) {
        this.linkedTargets = null;
        let initialMap = null;

        try { initialMap = typeof doc.signature_map === 'string' ? JSON.parse(doc.signature_map) : doc.signature_map; } catch (e) { }

        if (!initialMap || !initialMap.w_pct) {
            const cw = this.currentCanvas ? this.currentCanvas.width : 800;
            const ch = this.currentCanvas ? this.currentCanvas.height : 1100;
            initialMap = { 
                x_pct: 0.65, 
                y_pct: 0.25, 
                w_pct: 130 / cw, 
                h_pct: 65 / ch, 
                page: 1 
            };
        }

        const verticalOffset = initialMap.h_pct * 1.5;

        const primaryTarget = this.createSafBox(content, 'accounting', initialMap, 'rgba(59, 130, 246, 0.15)', '#3b82f6');

        const issuerMap = { ...initialMap, y_pct: initialMap.y_pct + verticalOffset };
        const secondaryTarget = this.createSafBox(content, 'issuer', issuerMap, 'rgba(16, 185, 129, 0.15)', '#10b981');

        this.linkedTargets = { primary: primaryTarget, secondary: secondaryTarget };

        this.linkSafTargets(primaryTarget, secondaryTarget, content);
        this.updateLinkedSignatureMaps(content);

        if (this.signatureImage) this.updateLinkedSignatureOverlay();
    }

    createSafBox(content, role, map, bgColor, borderColor) {
        const box = document.createElement('div');
        box.className = `signature-target draggable saf-${role}`;

        const rect = this.computeSignaturePixelRect(map);
        box.style.cssText = `position: absolute; left: ${rect?.left || 0}px; top: ${rect?.top || 0}px; width: ${rect?.width || 120}px; height: ${rect?.height || 60}px; z-index: ${role === 'accounting' ? 25 : 24}; background-color: ${bgColor}; border: 2px dashed ${borderColor}; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: move; font-weight: 600; font-size: 12px; color: ${borderColor};`;
        box.textContent = 'Signature';

        const badge = document.createElement('div');
        badge.style.cssText = `position: absolute; top: -8px; right: -8px; width: 16px; height: 16px; background-color: ${borderColor}; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 9px; font-weight: bold; z-index: 30;`;
        badge.textContent = role === 'accounting' ? '1' : '2';

        box.appendChild(badge);
        content.appendChild(box);
        return box;
    }

    linkSafTargets(primary, secondary, content) {
        let isDragging = false;
        let startX, startY, initPX, initPY, initSX, initSY;

        const startDrag = (e) => {
            isDragging = true;
            startX = e.clientX; startY = e.clientY;
            initPX = primary.offsetLeft; initPY = primary.offsetTop;
            initSX = secondary.offsetLeft; initSY = secondary.offsetTop;
            primary.style.cursor = 'grabbing'; secondary.style.cursor = 'grabbing';
            e.preventDefault();
        };

        primary.addEventListener('mousedown', startDrag);
        secondary.addEventListener('mousedown', startDrag);

        document.addEventListener('mousemove', (e) => {
            if (!isDragging || !this.currentCanvas) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            const maxX = this.currentCanvas.width - primary.offsetWidth;
            const pMaxY = this.currentCanvas.height - primary.offsetHeight;
            const sMaxY = this.currentCanvas.height - secondary.offsetHeight;

            primary.style.left = Math.max(0, Math.min(initPX + dx, maxX)) + 'px';
            primary.style.top = Math.max(0, Math.min(initPY + dy, pMaxY)) + 'px';
            secondary.style.left = Math.max(0, Math.min(initSX + dx, maxX)) + 'px';
            secondary.style.top = Math.max(0, Math.min(initSY + dy, sMaxY)) + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                primary.style.cursor = 'move'; secondary.style.cursor = 'move';
                this.updateLinkedSignatureMaps(content);
            }
        });
    }

    // ------------------------------------------------------------------
    // Math & Image Helpers
    // ------------------------------------------------------------------

    makeDraggable(element, container) {
        let isDragging = false, startX, startY, initialX, initialY;

        element.addEventListener('mousedown', (e) => {
            isDragging = true;
            startX = e.clientX; startY = e.clientY;
            initialX = element.offsetLeft; initialY = element.offsetTop;
            element.style.cursor = 'grabbing';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging || !this.currentCanvas) return;
            const dx = e.clientX - startX, dy = e.clientY - startY;
            const maxX = this.currentCanvas.width - element.offsetWidth;
            const maxY = this.currentCanvas.height - element.offsetHeight;

            element.style.left = Math.max(0, Math.min(initialX + dx, maxX)) + 'px';
            element.style.top = Math.max(0, Math.min(initialY + dy, maxY)) + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                element.style.cursor = 'grab';
                this.updateSignatureMap(element, container);
            }
        });
    }

    updateSignatureMap(element, container) {
        if (!this.currentCanvas) return;
        const width = this.currentCanvas.width, height = this.currentCanvas.height;

        this.currentSignatureMap = {
            x_pct: Math.max(0, Math.min(1, element.offsetLeft / width)),
            y_pct: Math.max(0, Math.min(1, element.offsetTop / height)),
            w_pct: Math.max(0, Math.min(1, element.offsetWidth / width)),
            h_pct: Math.max(0, Math.min(1, element.offsetHeight / height)),
            page: this.currentPage
        };
    }

    updateLinkedSignatureMaps(content) {
        if (!this.linkedTargets || !this.currentCanvas) return;
        const w = this.currentCanvas.width, h = this.currentCanvas.height;
        const p = this.linkedTargets.primary, s = this.linkedTargets.secondary;

        this.linkedSignatureMaps = {
            accounting: { x_pct: p.offsetLeft / w, y_pct: p.offsetTop / h, w_pct: p.offsetWidth / w, h_pct: p.offsetHeight / h, page: this.currentPage },
            issuer: { x_pct: s.offsetLeft / w, y_pct: s.offsetTop / h, w_pct: s.offsetWidth / w, h_pct: s.offsetHeight / h, page: this.currentPage }
        };
        this.currentSignatureMap = this.linkedSignatureMaps.accounting;
    }

    computeSignaturePixelRect(map) {
        if (!this.currentCanvas) return null;
        const cw = this.currentCanvas.width, ch = this.currentCanvas.height;
        return {
            left: map.x_pct * cw, top: map.y_pct * ch,
            width: map.w_pct * cw, height: map.h_pct * ch
        };
    }

    computeSignaturePixelRectForContainer(map, canvas, container) {
        if (!canvas) return null;
        const canvasRect = canvas.getBoundingClientRect();
        const containerRect = container.getBoundingClientRect();

        const left = (canvasRect.left - containerRect.left) + (map.x_pct * canvasRect.width);
        const top = (canvasRect.top - containerRect.top) + (map.y_pct * canvasRect.height);

        return { left, top, width: map.w_pct * canvasRect.width, height: map.h_pct * canvasRect.height };
    }

    updateSignatureOverlayImage() {
        const box = document.getElementById('pdfContent')?.querySelector('.signature-target:not(.saf-accounting):not(.saf-issuer)');
        if (!box || !this.signatureImage) return;
        box.innerHTML = `<img src="${this.signatureImage}" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
    }

    updateLinkedSignatureOverlay() {
        if (!this.signatureImage || !this.linkedTargets) return;
        const imgHtml = `<img src="${this.signatureImage}" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
        this.linkedTargets.primary.innerHTML = imgHtml;
        this.linkedTargets.secondary.innerHTML = imgHtml;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    isUserAssignedToPendingStep(doc) {
        const pendingStep = doc.workflow?.find(step => step.status === 'pending');
        if (!pendingStep) return false;
        const user = window.currentUser;

        // Check if the current user matches the assignee of the pending step
        return pendingStep.assignee_id == user.id || pendingStep.assigned_to == user.id;
    }
}