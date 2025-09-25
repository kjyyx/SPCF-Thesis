// Upload Publication Materials JavaScript
// This file contains the client-side logic for the upload publication materials page

document.addEventListener('DOMContentLoaded', function () {
    // Initialize the upload functionality
    initializeUploadSystem();
});

function initializeUploadSystem() {
    // High-level: Drag-and-drop and input-based file selection with previews, ordering, usage bar, and server upload.
    // File upload related variables
    const uploadZone = document.getElementById('upload-zone');
    const fileInput = document.getElementById('file-input');
    const previewGrid = document.getElementById('preview-grid');
    const submitBtn = document.getElementById('submit-btn');
    const usageBar = document.getElementById('usage-bar');
    const usageText = document.getElementById('usage-text');
    const alertContainer = document.getElementById('alert-container');

    let uploadedFiles = [];
    const maxFileSize = 10 * 1024 * 1024; // 10MB
    const maxTotalSize = 100 * 1024 * 1024; // 100MB
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    // Drag and drop functionality
    if (uploadZone) {
        uploadZone.addEventListener('click', () => fileInput.click());
        uploadZone.addEventListener('dragover', handleDragOver);
        uploadZone.addEventListener('dragleave', handleDragLeave);
        uploadZone.addEventListener('drop', handleDrop);
    }

    // File input change
    if (fileInput) {
        fileInput.addEventListener('change', handleFileSelect);
    }

    // Submit button
    if (submitBtn) {
        submitBtn.addEventListener('click', handleSubmit);
    }

    // Clear all button
    const clearAllBtn = document.getElementById('clear-all-btn');
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', handleClearAll);
    }

    function handleClearAll() {
        if (uploadedFiles.length === 0) return;

        if (confirm('Are you sure you want to remove all files?')) {
            if (window.addAuditLog) {
                window.addAuditLog('FILES_CLEARED', 'Materials', `Cleared ${uploadedFiles.length} uploaded files`, null, 'Material', 'INFO');
            }
            uploadedFiles = [];
            if (previewGrid) previewGrid.innerHTML = '';
            hidePreviewContainer();
            updateSubmitButton();
            updateStorageUsage();
            updateFileCounts();
            showAlert('All files have been removed', 'info');
        }
    }

    function handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        const section = uploadZone && uploadZone.closest ? uploadZone.closest('.upload-section') : null;
        if (section) section.classList.add('dragover');
    }

    function handleDragLeave(e) {
        e.preventDefault();
        e.stopPropagation();
        const section = uploadZone && uploadZone.closest ? uploadZone.closest('.upload-section') : null;
        if (section) section.classList.remove('dragover');
    }

    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        const section = uploadZone && uploadZone.closest ? uploadZone.closest('.upload-section') : null;
        if (section) section.classList.remove('dragover');

        const files = Array.from(e.dataTransfer.files);
        processFiles(files);
    }

    function handleFileSelect(e) {
        const files = Array.from(e.target.files);
        processFiles(files);
    }

    function processFiles(files) {
        files.forEach(file => {
            if (validateFile(file)) {
                addFileToPreview(file);
            }
        });
        updateSubmitButton();
        updateStorageUsage();
    }

    function validateFile(file) {
        // Check file type
        if (!allowedTypes.includes(file.type)) {
            showAlert('Unsupported file type: ' + file.name, 'danger');
            return false;
        }

        // Check file name (alphanumeric, underscores, hyphens, dots only)
        const fileNameRegex = /^[a-zA-Z0-9._-]+$/;
        if (!fileNameRegex.test(file.name)) {
            showAlert('Invalid file name: ' + file.name + ' (use only letters, numbers, underscores, hyphens, dots. Examples: document.pdf, file_1.txt, my-file.jpg)', 'danger');
            return false;
        }

        // Check file size
        if (file.size > maxFileSize) {
            showAlert('File too large: ' + file.name + ' (max 10MB)', 'danger');
            return false;
        }

        // Check total size
        const currentTotal = uploadedFiles.reduce((sum, f) => sum + f.size, 0);
        if (currentTotal + file.size > maxTotalSize) {
            showAlert('Total upload limit exceeded (max 100MB)', 'danger');
            return false;
        }

        return true;
    }

    function addFileToPreview(file) {
        uploadedFiles.push(file);

        const fileElement = document.createElement('div');
        fileElement.className = 'file-preview';
        fileElement.draggable = true;

        // Create preview based on file type
        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.className = 'preview-image';
            img.src = URL.createObjectURL(file);
            img.alt = file.name;
            fileElement.appendChild(img);
        } else {
            // For non-image files, show file icon with better styling
            const iconDiv = document.createElement('div');
            iconDiv.className = 'd-flex align-items-center justify-content-center';
            iconDiv.style.height = '100px';
            iconDiv.style.background = 'linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%)';
            iconDiv.style.borderRadius = '8px 8px 0 0';

            // Get appropriate icon based on file extension
            const extension = file.name.split('.').pop().toLowerCase();
            let iconClass = 'bi-file-earmark-text';
            let iconColor = '#6366f1';

            if (['pdf'].includes(extension)) {
                iconClass = 'bi-file-earmark-pdf';
                iconColor = '#dc2626';
            } else if (['doc', 'docx'].includes(extension)) {
                iconClass = 'bi-file-earmark-word';
                iconColor = '#2563eb';
            }

            iconDiv.innerHTML = `
                <div class="text-center">
                    <i class="bi ${iconClass}" style="font-size: 2.5rem; color: ${iconColor};"></i>
                    <div class="mt-1 small text-muted fw-semibold">${extension.toUpperCase()}</div>
                </div>
            `;
            fileElement.appendChild(iconDiv);
        }

        // File info container
        const fileInfo = document.createElement('div');
        fileInfo.className = 'file-info';

        // File name
        const nameDiv = document.createElement('div');
        nameDiv.className = 'file-name';
        nameDiv.textContent = file.name;
        nameDiv.title = file.name; // Tooltip for long names
        fileInfo.appendChild(nameDiv);

        // Add description textarea
        const descDiv = document.createElement('div');
        descDiv.className = 'file-description';
        const descLabel = document.createElement('label');
        descLabel.textContent = 'Description:';
        descLabel.className = 'desc-label';
        const descTextarea = document.createElement('textarea');
        descTextarea.className = 'desc-textarea';
        descTextarea.placeholder = 'Enter description for this file';
        descTextarea.rows = 2;
        descDiv.appendChild(descLabel);
        descDiv.appendChild(descTextarea);
        fileInfo.appendChild(descDiv);

        // Store description in file object
        file.description = '';

        descTextarea.addEventListener('input', (e) => {
            file.description = e.target.value;
            console.log('Description updated for', file.name, ':', file.description);
        });

        // File size
        const sizeDiv = document.createElement('div');
        sizeDiv.className = 'file-size';
        sizeDiv.textContent = formatFileSize(file.size);
        fileInfo.appendChild(sizeDiv);

        fileElement.appendChild(fileInfo);

        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'delete-btn';
        deleteBtn.innerHTML = '<i class="bi bi-x"></i>';
        deleteBtn.title = 'Remove file';
        deleteBtn.onclick = () => removeFile(file, fileElement);
        fileElement.appendChild(deleteBtn);

        // Drag handle
        const dragHandle = document.createElement('div');
        dragHandle.className = 'drag-handle';
        dragHandle.innerHTML = '<i class="bi bi-grip-vertical"></i>';
        dragHandle.title = 'Drag to reorder';
        fileElement.appendChild(dragHandle);

        // Add drag functionality
        fileElement.addEventListener('dragstart', handleDragStart);
        fileElement.addEventListener('dragend', handleDragEnd);
        fileElement.addEventListener('dragover', handleFileDragOver);
        fileElement.addEventListener('drop', handleFileDrop);

        if (previewGrid) previewGrid.appendChild(fileElement);

        // Show preview container and update counts
        showPreviewContainer();
        updateFileCounts();
    }

    function removeFile(file, element) {
        uploadedFiles = uploadedFiles.filter(f => f !== file);
        element.remove();
        updateSubmitButton();
        updateStorageUsage();
        updateFileCounts();

        // Hide preview container if no files
        if (uploadedFiles.length === 0) {
            hidePreviewContainer();
        }
    }

    // Helper function to format file size
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    // Show preview container
    function showPreviewContainer() {
        const previewContainer = document.getElementById('preview-container');
        if (previewContainer) {
            previewContainer.style.display = 'block';
        }
    }

    // Hide preview container
    function hidePreviewContainer() {
        const previewContainer = document.getElementById('preview-container');
        if (previewContainer) {
            previewContainer.style.display = 'none';
        }
    }

    // Update file counts
    function updateFileCounts() {
        const fileCount = uploadedFiles.length;
        const fileCountElement = document.getElementById('file-count');
        const fileCountBadge = document.getElementById('file-count-badge');

        if (fileCountElement) {
            fileCountElement.textContent = fileCount;
        }

        if (fileCountBadge) {
            fileCountBadge.textContent = fileCount;
        }
    }

    function handleDragStart(e) {
        e.dataTransfer.setData('text/plain', '');
        e.target.classList.add('dragging');
    }

    function handleDragEnd(e) {
        e.target.classList.remove('dragging');
        document.querySelectorAll('.file-preview').forEach(el => el.classList.remove('drag-over'));
    }

    function handleFileDragOver(e) {
        e.preventDefault();
        e.target.closest('.file-preview').classList.add('drag-over');
    }

    function handleFileDrop(e) {
        e.preventDefault();
        const draggedElement = document.querySelector('.dragging');
        const dropTarget = e.target.closest('.file-preview');

        if (draggedElement && dropTarget && draggedElement !== dropTarget) {
            const allPreviews = previewGrid ? Array.from(previewGrid.children) : [];
            const draggedIndex = allPreviews.indexOf(draggedElement);
            const dropIndex = allPreviews.indexOf(dropTarget);

            // Reorder files array
            const [draggedFile] = uploadedFiles.splice(draggedIndex, 1);
            uploadedFiles.splice(dropIndex, 0, draggedFile);

            // Reorder DOM elements
            if (previewGrid) {
                if (draggedIndex < dropIndex) {
                    previewGrid.insertBefore(draggedElement, dropTarget.nextSibling);
                } else {
                    previewGrid.insertBefore(draggedElement, dropTarget);
                }
            }
        }

        document.querySelectorAll('.file-preview').forEach(el => el.classList.remove('drag-over'));
    }

    function updateSubmitButton() {
        if (submitBtn) {
            submitBtn.disabled = uploadedFiles.length === 0;
        }
    }

    function updateStorageUsage() {
        if (window.addAuditLog) {
            window.addAuditLog('STORAGE_USAGE_CHECKED', 'Materials', 'Checked storage usage', null, 'Material', 'INFO');
        }
        const totalSize = uploadedFiles.reduce((sum, file) => sum + file.size, 0);
        const percentage = (totalSize / maxTotalSize) * 100;
        const remainingSize = maxTotalSize - totalSize;

        if (usageBar) {
            usageBar.style.width = percentage + '%';
            usageBar.setAttribute('aria-valuenow', percentage.toFixed(1));

            // Update color based on usage
            usageBar.className = 'progress-bar';
            if (percentage > 90) {
                usageBar.classList.add('bg-danger');
            } else if (percentage > 70) {
                usageBar.classList.add('bg-warning');
            } else {
                usageBar.classList.add('bg-success');
            }
        }

        if (usageText) {
            const usedMB = (totalSize / (1024 * 1024)).toFixed(1);
            const percentageText = percentage.toFixed(1);
            usageText.textContent = `${usedMB} MB / 100 MB (${percentageText}%)`;
        }

        // Update remaining space
        const remainingSpaceElement = document.getElementById('remaining-space');
        if (remainingSpaceElement) {
            const remainingMB = (remainingSize / (1024 * 1024)).toFixed(1);
            remainingSpaceElement.textContent = `${remainingMB} MB`;
        }
    }

    async function handleSubmit() {
        if (uploadedFiles.length === 0) {
            showAlert('Please select files to upload', 'warning');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Uploading...';

        let successCount = 0;
        let errorCount = 0;

        for (const file of uploadedFiles) {
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('file', file);
            formData.append('student_id', window.currentUser?.id || 'unknown'); // Assuming user ID is available
            formData.append('title', file.name); // Use filename as title, or prompt for description
            formData.append('description',  file.description || 'Uploaded publication material'); // Placeholder
            console.log('Uploading', file.name, 'with description:', file.description || 'Uploaded publication material');

            try {
                const response = await fetch('../api/materials.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    successCount++;
                } else {
                    errorCount++;
                    showAlert(`Failed to upload ${file.name}: ${result.message}`, 'danger');
                }
            } catch (error) {
                errorCount++;
                showAlert(`Error uploading ${file.name}: ${error.message}`, 'danger');
            }
        }

        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-send me-2"></i>Submit for Approval';

        if (successCount > 0) {
            // In handleSubmit, after successful upload:
            showAlert(`Successfully uploaded ${successCount} file(s)! Files are pending approval.`, 'success');
            // Clear files after successful submission
            uploadedFiles = [];
            if (previewGrid) previewGrid.innerHTML = '';
            updateSubmitButton();
            updateStorageUsage();
            updateFileCounts();
            hidePreviewContainer();
        }
    }

    function showAlert(message, type) {
        if (window.ToastManager) {
            window.ToastManager.show({
                type: type,
                message: message,
                duration: 5000
            });
        } else {
            // Fallback
            alert(message);
        }
    }
}