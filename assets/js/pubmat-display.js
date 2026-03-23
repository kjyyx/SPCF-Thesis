// filepath: assets/js/pubmat-display.js
var BASE_URL = window.BASE_URL || './';
let pubmats = [];
let pubmatIdsJSON = '[]'; // Store as a JSON string for easy comparison and to detect changes
let slideshowIndex = 0;
let slideshowActive = false;
let slideshowTimeout = null;
let progressInterval = null;
let progressValue = 0;
let infoHideTimeout = null;
let sortableInstance = null; // To hold the Sortable instance

document.addEventListener('DOMContentLoaded', () => {
    const slideshowBtn = document.getElementById('slideshow-btn');
    if (slideshowBtn) {
        slideshowBtn.addEventListener('click', startSlideshowFromBeginning);
    }
    const slideshowDeleteBtn = document.getElementById('slideshow-delete-btn');
    if (slideshowDeleteBtn) {
        slideshowDeleteBtn.addEventListener('click', deleteCurrentPubmat);
    }

    loadPubmats();
    // Periodically check for new pubmats
    setInterval(() => loadPubmats(true), 5000);
});

async function loadPubmats(isPolling = false) {
    try {
        const response = await fetch(BASE_URL + 'api/materials.php?for_display=1&t=' + Date.now());

        if (!response.ok) {
            if (response.status === 403) {
                if (!isPolling) {
                    showError('Access denied. PPFO only.');
                    window.location.href = BASE_URL + 'login';
                }
            } else if (!isPolling) {
                showError(`Error loading pubmats: ${response.statusText}`);
            }
            return;
        }

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (!isPolling) showError('Invalid server response.');
            return;
        }

        const data = await response.json();
        if (!data.success) {
            if (!isPolling) showError(data.message || 'Failed to load pubmats.');
            return;
        }

        const newPubmats = (data.materials || []).filter(mat => mat.status === 'approved');
        const newPubmatIdsJSON = JSON.stringify(newPubmats.map(p => p.id));

        if (newPubmatIdsJSON !== pubmatIdsJSON) {
            pubmatIdsJSON = newPubmatIdsJSON;
            const currentIdOnScreen = slideshowActive ? pubmats[slideshowIndex]?.id : null;
            pubmats = newPubmats;

            if (!slideshowActive) {
                renderGallery();
            } else {
                if (pubmats.length === 0) {
                    closeSlideshow();
                    return;
                }
                
                let newIndex = currentIdOnScreen ? pubmats.findIndex(p => p.id === currentIdOnScreen) : -1;
                
                if (newIndex !== -1) {
                    slideshowIndex = newIndex;
                } else if (slideshowIndex >= pubmats.length) {
                    slideshowIndex = 0;
                }

                updateSlideshow();

                const counter = document.getElementById('slideshow-counter');
                if (counter) {
                    counter.textContent = `${slideshowIndex + 1} / ${pubmats.length}`;
                }
            }
        }
    } catch (error) {
        if (!isPolling) {
            showError('Error loading pubmats: ' + error.message);
        } else {
            console.error('Error polling for pubmats:', error);
        }
    }
}

function renderGallery() {
    const gallery = document.getElementById('gallery');
    const emptyState = document.getElementById('emptyState');
    const slideshowBtn = document.getElementById('slideshow-btn');

    if (!gallery) return;

    if (!pubmats.length) {
        gallery.innerHTML = '';
        if (emptyState) emptyState.classList.remove('d-none');
        if (slideshowBtn) slideshowBtn.disabled = true;
        return;
    }

    if (emptyState) emptyState.classList.add('d-none');
    if (slideshowBtn) slideshowBtn.disabled = false;

    gallery.innerHTML = pubmats.map((mat, index) => `
        <div class="gallery-item-wrap" data-id="${mat.id}">
            <button type="button" class="btn btn-danger btn-sm gallery-delete-btn" onclick="deletePubmat('${encodeURIComponent(mat.id)}'); event.stopPropagation();">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
            <div class="gallery-item" onclick="startSlideshow(${index})">
                ${mat.file_type && mat.file_type.startsWith('image/') 
                    ? `<img src="${BASE_URL}api/materials.php?action=serve_image&id=${encodeURIComponent(mat.id)}" alt="${escapeHtml(mat.title)}">`
                    : `<div class="d-flex align-items-center justify-content-center h-100 bg-light text-muted"><i class="bi bi-file-earmark-pdf-fill fs-1"></i></div>`}
                <p class="mt-2 text-center">${escapeHtml(mat.title)}</p>
            </div>
        </div>
    `).join('');

    if (sortableInstance) {
        sortableInstance.destroy();
    }

    sortableInstance = Sortable.create(gallery, {
        animation: 150,
        handle: '.gallery-item-wrap',
        onEnd: function (evt) {
            const orderedIds = Array.from(gallery.children).map(item => item.dataset.id);
            updateSortOrder(orderedIds);
        }
    });
}

async function updateSortOrder(orderedIds) {
    try {
        const response = await fetch(BASE_URL + 'api/materials.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'update_order',
                order: orderedIds,
            }),
        });

        const data = await response.json();

        if (response.ok && data.success) {
            if (window.ToastManager) {
                window.ToastManager.show({ type: 'success', title: 'Success', message: 'Display order has been saved.' });
            }
            pubmats.sort((a, b) => orderedIds.indexOf(a.id) - orderedIds.indexOf(b.id));
            pubmatIdsJSON = JSON.stringify(orderedIds);
        } else {
            showError(data.message || 'Failed to save the new order.');
            renderGallery();
        }
    } catch (error) {
        showError('Error saving order: ' + error.message);
        renderGallery();
    }
}


function startSlideshowFromBeginning() {
    if (!pubmats.length) {
        showError('No approved pubmats available for slideshow.');
        return;
    }
    startSlideshow(0);
}

function startSlideshow(index) {
    if (sortableInstance) {
        sortableInstance.option('disabled', true);
    }
    slideshowIndex = index;
    slideshowActive = true;
    document.body.style.overflow = 'hidden';
    updateSlideshow();
    const slideshowView = document.getElementById('slideshow-view');
    if (slideshowView) {
        slideshowView.classList.add('show');
        slideshowView.classList.add('controls-hidden');
    }
    const info = document.querySelector('#slideshow-view .slideshow-info');
    if (info) info.classList.add('is-hidden');
    scheduleNextSlide();
}

function scheduleNextSlide() {
    if (!slideshowActive) return;

    if (slideshowTimeout) clearTimeout(slideshowTimeout);
    if (progressInterval) clearInterval(progressInterval);

    const progressEl = document.getElementById('slideshow-progress');
    progressValue = 0;
    if (progressEl) progressEl.style.width = '0%';

    progressInterval = setInterval(() => {
        if (!slideshowActive) return;
        progressValue += 1;
        if (progressEl) progressEl.style.width = `${Math.min(progressValue, 100)}%`;
    }, 150);

    slideshowTimeout = setTimeout(() => {
        if (slideshowActive) {
            if (pubmats.length > 0) {
                slideshowIndex = (slideshowIndex + 1) % pubmats.length;
                updateSlideshow();
                scheduleNextSlide();
            } else {
                closeSlideshow();
            }
        }
    }, 15000);
}

function updateSlideshow() {
    if (pubmats.length === 0) {
        closeSlideshow();
        return;
    }
    if (slideshowIndex >= pubmats.length) {
        slideshowIndex = 0;
    }
    
    const mat = pubmats[slideshowIndex];
    if (!mat) {
        console.error('Slideshow error: material at index ' + slideshowIndex + ' is undefined.');
        closeSlideshow();
        return;
    }
    
    const content = document.getElementById('slideshow-content');
    const title = document.getElementById('slideshow-title');
    const counter = document.getElementById('slideshow-counter');
    const background = document.getElementById('slideshow-bg');

    title.textContent = escapeHtml(mat.title);
    counter.textContent = `${slideshowIndex + 1} / ${pubmats.length}`;

    if (mat.file_type && mat.file_type.startsWith('image/')) {
        const imageUrl = `${BASE_URL}api/materials.php?action=serve_image&id=${encodeURIComponent(mat.id)}`;
        if (background) background.style.backgroundImage = `url('${imageUrl}')`;
        content.innerHTML = `<img src="${imageUrl}" class="slideshow-img" alt="${escapeHtml(mat.title)}">`;
    } else {
        if (background) background.style.backgroundImage = 'none';
        content.innerHTML = `
            <div class="text-center">
                <i class="bi bi-file-earmark-pdf-fill fs-1 text-muted"></i>
                <p class="mt-3">${escapeHtml(mat.title)}</p>
                <button class="btn btn-primary" onclick="downloadPubmat('${mat.id}')">Download</button>
            </div>
        `;
    }
}

function closeSlideshow() {
    if (sortableInstance) {
        sortableInstance.option('disabled', false);
    }
    slideshowActive = false;
    if (slideshowTimeout) clearTimeout(slideshowTimeout);
    if (progressInterval) clearInterval(progressInterval);
    slideshowTimeout = null;
    progressInterval = null;
    if (infoHideTimeout) clearTimeout(infoHideTimeout);
    infoHideTimeout = null;
    const progressEl = document.getElementById('slideshow-progress');
    if (progressEl) progressEl.style.width = '0%';
    const slideshowView = document.getElementById('slideshow-view');
    if (slideshowView) {
        slideshowView.classList.remove('show');
        slideshowView.classList.remove('controls-hidden');
    }
    const info = document.querySelector('#slideshow-view .slideshow-info');
    if (info) info.classList.remove('is-hidden');
    document.body.style.overflow = '';
    renderGallery();
}

function downloadPubmat(id) {
    window.open(BASE_URL + `api/materials.php?download=1&id=${id}`, '_blank');
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function showError(message) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: 'error',
            title: 'Error',
            message,
            duration: 4000
        });
    } else {
        console.error(message);
    }
}

async function deleteCurrentPubmat() {
    if (!pubmats.length || !pubmats[slideshowIndex]) return;
    await deletePubmat(pubmats[slideshowIndex].id);
}

async function deletePubmat(id) {
    const decodedId = decodeURIComponent(String(id || ''));
    const ok = window.confirm('Delete this pubmat?');
    if (!ok) return;

    try {
        const response = await fetch(`${BASE_URL}api/materials.php?id=${encodeURIComponent(decodedId)}`, {
            method: 'DELETE'
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            showError(data.message || 'Failed to delete pubmat.');
            return;
        }
        
        loadPubmats();
    } catch (error) {
        showError('Error deleting pubmat: ' + error.message);
    }
}

document.addEventListener('keydown', (e) => {
    if (slideshowActive && e.key === 'Escape') {
        closeSlideshow();
    }
});