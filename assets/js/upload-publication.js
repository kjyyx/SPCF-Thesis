// Upload Publication Materials JavaScript
var BASE_URL = window.BASE_URL || './';

function showToast(message, type = 'info', title = null) {
    if (window.ToastManager) {
        window.ToastManager.show({ type: type, title: title, message: message, duration: 4000 });
    } else {
        console.log(`[${type.toUpperCase()}] ${message}`);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    initializeUploadSystem();
});

function initializeUploadSystem() {
    const uploadZone = document.getElementById('upload-zone');
    const fileInput = document.getElementById('file-input');
    const previewGrid = document.getElementById('preview-grid');
    const submitBtn = document.getElementById('submit-btn');
    const usageBar = document.getElementById('usage-bar');
    const usageText = document.getElementById('usage-text');
    const alertContainer = document.getElementById('alert-container');
    const emptyState = document.getElementById('empty-preview-state');

    let uploadedFiles = [];
    const MAX_TOTAL_SIZE = 50 * 1024 * 1024; // 50MB
    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    const prefs = {
        autoPreview: localStorage.getItem('uploadPub_autoPreview') !== 'false',
        showFileDescriptions: localStorage.getItem('uploadPub_showFileDescriptions') !== 'false',
        autoCompress: localStorage.getItem('uploadPub_autoCompress') !== 'false',
        maxFilesPerUpload: parseInt(localStorage.getItem('uploadPub_maxFilesPerUpload')) || 10
    };

    uploadZone.addEventListener('click', () => fileInput.click());

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        uploadZone.addEventListener(eventName, () => uploadZone.classList.add('drag-over'), false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, () => uploadZone.classList.remove('drag-over'), false);
    });

    uploadZone.addEventListener('drop', (e) => handleFiles({ target: { files: e.dataTransfer.files } }), false);
    fileInput.addEventListener('change', handleFiles, false);

    document.getElementById('clear-all-btn').addEventListener('click', () => {
        if (confirm('Are you sure you want to clear all selected files?')) {
            uploadedFiles = [];
            renderPreview();
        }
    });

    submitBtn.addEventListener('click', submitFiles);

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    async function handleFiles(e) {
        const files = [...e.target.files];
        
        if (uploadedFiles.length + files.length > prefs.maxFilesPerUpload) {
            showAlert(`You can only upload a maximum of ${prefs.maxFilesPerUpload} files at once.`, 'warning');
            return;
        }

        for (let file of files) {
            if (file.size > MAX_FILE_SIZE) {
                showAlert(`File ${file.name} exceeds the 10MB limit.`, 'danger');
                continue;
            }

            const isDuplicate = uploadedFiles.some(f => f.name === file.name && f.size === file.size);
            if (isDuplicate) {
                showAlert(`File ${file.name} is already added.`, 'warning');
                continue;
            }

            if (prefs.autoCompress && file.type.startsWith('image/') && file.size > 2 * 1024 * 1024) {
                try {
                    const compressedFile = await compressImage(file);
                    uploadedFiles.push(compressedFile);
                    showToast(`Compressed ${file.name} automatically`, 'success');
                } catch (err) {
                    uploadedFiles.push(file);
                }
            } else {
                uploadedFiles.push(file);
            }
        }

        fileInput.value = '';
        renderPreview();
    }

    function renderPreview() {
        if (uploadedFiles.length === 0) {
            previewGrid.innerHTML = '';
            if (emptyState) {
                emptyState.style.display = 'block';
                previewGrid.appendChild(emptyState);
            }
            document.getElementById('clear-all-btn').style.display = 'none';
            document.getElementById('file-count').textContent = '0';
            updateStorageUsage();
            checkFormValidity();
            return;
        }

        if (emptyState) emptyState.style.display = 'none';
        document.getElementById('clear-all-btn').style.display = 'inline-block';
        document.getElementById('file-count').textContent = uploadedFiles.length;

        previewGrid.innerHTML = '';

        uploadedFiles.forEach((file, index) => {
            const previewEl = document.createElement('div');
            previewEl.className = 'card border-0 shadow-sm rounded-4 file-preview transition-base hover-lift';
            previewEl.draggable = true;
            previewEl.dataset.index = index;

            let previewContent = '';
            if (prefs.autoPreview && file.type.startsWith('image/')) {
                previewContent = `<img src="${URL.createObjectURL(file)}" alt="${file.name}" class="w-100 h-100 object-fit-cover">`;
            } else if (file.type === 'application/pdf') {
                previewContent = `<i class="bi bi-file-earmark-pdf text-danger" style="font-size: 3rem;"></i>`;
            } else {
                previewContent = `<i class="bi bi-file-earmark-text text-primary" style="font-size: 3rem;"></i>`;
            }

            previewEl.innerHTML = `
                <div class="position-relative">
                    <button type="button" class="btn btn-danger btn-sm rounded-circle position-absolute shadow-sm d-flex align-items-center justify-content-center p-0 remove-btn" 
                            style="top: -10px; right: -10px; z-index: 10; width: 26px; height: 26px;" data-index="${index}">
                        <i class="bi bi-x" style="font-size: 1.2rem;"></i>
                    </button>
                    <div class="bg-light rounded-top-4 d-flex align-items-center justify-content-center overflow-hidden" style="height: 140px;">
                        ${previewContent}
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="fw-bold text-dark text-truncate text-sm mb-1" title="${file.name}">${file.name}</div>
                    <div class="text-xs text-muted mb-2">${formatFileSize(file.size)}</div>
                    ${prefs.showFileDescriptions ? `<input type="text" class="form-control bg-light border-0 rounded-3 p-2 px-3 text-xs desc-input" placeholder="Add description..." value="${file.description || ''}" data-index="${index}">` : ''}
                </div>
            `;

            previewGrid.appendChild(previewEl);

            previewEl.addEventListener('dragstart', handleDragStart);
            previewEl.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; return false; });
            previewEl.addEventListener('drop', handleDropSort);
            previewEl.addEventListener('dragend', function() { this.style.opacity = '1'; draggedItem = null; });
        });

        document.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                uploadedFiles.splice(parseInt(this.dataset.index), 1);
                renderPreview();
            });
        });

        document.querySelectorAll('.desc-input').forEach(input => {
            input.addEventListener('change', function() {
                uploadedFiles[parseInt(this.dataset.index)].description = this.value;
            });
        });

        updateStorageUsage();
        checkFormValidity();
    }

    let draggedItem = null;

    function handleDragStart(e) {
        draggedItem = this;
        setTimeout(() => this.style.opacity = '0.5', 0);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dataset.index);
    }

    function handleDropSort(e) {
        e.stopPropagation();
        if (draggedItem !== this) {
            const fromIndex = parseInt(e.dataTransfer.getData('text/plain'));
            const toIndex = parseInt(this.dataset.index);
            const movedFile = uploadedFiles.splice(fromIndex, 1)[0];
            uploadedFiles.splice(toIndex, 0, movedFile);
            renderPreview();
        }
        return false;
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function updateStorageUsage() {
        const totalSize = uploadedFiles.reduce((sum, file) => sum + file.size, 0);
        const percentage = Math.min(100, Math.round((totalSize / MAX_TOTAL_SIZE) * 100));
        
        usageBar.style.width = `${percentage}%`;
        document.getElementById('usage-percent').textContent = `${percentage}%`;
        usageText.textContent = `${formatFileSize(totalSize)} / 50 MB Used`;
        
        usageBar.className = 'progress-bar rounded-pill transition-base ' + 
            (percentage > 90 ? 'bg-danger' : percentage > 75 ? 'bg-warning' : 'bg-primary');
            
        if (totalSize > MAX_TOTAL_SIZE) {
            showAlert('Total file size exceeds the 50MB limit. Please remove some files.', 'danger');
            submitBtn.disabled = true;
        } else {
            checkFormValidity();
        }
    }

    function showAlert(message, type = 'info') {
        alertContainer.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        setTimeout(() => {
            const alert = alertContainer.querySelector('.alert');
            if (alert) new bootstrap.Alert(alert).close();
        }, 5000);
    }

    document.getElementById('pub-title').addEventListener('input', checkFormValidity);

    function checkFormValidity() {
        const title = document.getElementById('pub-title').value.trim();
        const totalSize = uploadedFiles.reduce((sum, file) => sum + file.size, 0);
        submitBtn.disabled = !(title && uploadedFiles.length > 0 && totalSize <= MAX_TOTAL_SIZE);
    }

    function compressImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = event => {
                const img = new Image();
                img.src = event.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    let width = img.width;
                    let height = img.height;
                    const MAX_WIDTH = 1920;
                    const MAX_HEIGHT = 1080;
                    
                    if (width > height && width > MAX_WIDTH) {
                        height *= MAX_WIDTH / width; width = MAX_WIDTH;
                    } else if (height > MAX_HEIGHT) {
                        width *= MAX_HEIGHT / height; height = MAX_HEIGHT;
                    }
                    
                    canvas.width = width; canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);
                    
                    canvas.toBlob(blob => {
                        const newFile = new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() });
                        newFile.description = file.description;
                        resolve(newFile);
                    }, 'image/jpeg', 0.8);
                };
                img.onerror = error => reject(error);
            };
            reader.onerror = error => reject(error);
        });
    }

    async function submitFiles() {
        if (submitBtn.disabled) return;

        const baseTitle = document.getElementById('pub-title').value.trim();
        const desc = document.getElementById('pub-desc').value.trim();

        if (!baseTitle) {
            showAlert('Please enter a title for your publication.', 'danger');
            return;
        }

        submitBtn.disabled = true;
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Uploading...';

        let successCount = 0;
        let errorMessages = [];

        for (let i = 0; i < uploadedFiles.length; i++) {
            const file = uploadedFiles[i];
            const fileDesc = file.description || desc;
            
            // --- SMART NAMING: Append " - Part 1, Part 2" if multiple files uploaded ---
            const finalTitle = uploadedFiles.length > 1 ? `${baseTitle} - Part ${i + 1}` : baseTitle;

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('student_id', window.currentUser.id);
            formData.append('title', finalTitle);
            formData.append('description', fileDesc);
            formData.append('file', file);

            try {
                const response = await fetch(BASE_URL + 'api/materials.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    successCount++;
                } else {
                    errorMessages.push(`File ${file.name}: ${result.message}`);
                }
            } catch (error) {
                errorMessages.push(`File ${file.name}: ${error.message}`);
            }
        }

        if (successCount === uploadedFiles.length) {
            document.getElementById('pub-title').value = '';
            document.getElementById('pub-desc').value = '';
            uploadedFiles = [];
            renderPreview();

            new bootstrap.Modal(document.getElementById('successModal')).show();
            showToast(`Successfully uploaded ${successCount} file(s)`, 'success');
        } else {
            showAlert(`Uploaded ${successCount}/${uploadedFiles.length} files. Errors: ${errorMessages.join(', ')}`, 'danger');
        }

        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

// Prefs and Profile logic preserved untouched
function openProfileSettings() {
    if (window.currentUser) {
        document.getElementById('profileFirstName').value = window.currentUser.firstName || '';
        document.getElementById('profileLastName').value = window.currentUser.lastName || '';
        document.getElementById('profileEmail').value = window.currentUser.email || '';
    }
    new bootstrap.Modal(document.getElementById('profileSettingsModal')).show();
}

function openPreferences() {
    document.getElementById('autoPreview').checked = localStorage.getItem('uploadPub_autoPreview') !== 'false';
    document.getElementById('showFileDescriptions').checked = localStorage.getItem('uploadPub_showFileDescriptions') !== 'false';
    document.getElementById('autoCompress').checked = localStorage.getItem('uploadPub_autoCompress') !== 'false';
    new bootstrap.Modal(document.getElementById('preferencesModal')).show();
}

function savePreferences() {
    localStorage.setItem('uploadPub_autoPreview', document.getElementById('autoPreview').checked);
    localStorage.setItem('uploadPub_showFileDescriptions', document.getElementById('showFileDescriptions').checked);
    localStorage.setItem('uploadPub_autoCompress', document.getElementById('autoCompress').checked);
    
    showToast('Preferences saved successfully', 'success');
    bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
    setTimeout(() => location.reload(), 500); 
}

if (window.NavbarSettings) {
    window.openProfileSettings = window.NavbarSettings.openProfileSettings;
    window.openChangePassword = window.NavbarSettings.openChangePassword;
    window.openPreferences = window.NavbarSettings.openPreferences;
    window.showHelp = window.NavbarSettings.showHelp;
    window.savePreferences = window.NavbarSettings.savePreferences;
    window.saveProfileSettings = window.NavbarSettings.saveProfileSettings;
}