/* Document Maker - JavaScript Functions */

/*******************************
 * State Variables
 *******************************/
let currentDocumentType = 'proposal';
let pages = [];
let currentPage = 1;
let totalPages = 1;
let genTimeout = null;

/*******************************
 * Document Type Selection
 *******************************/
function selectDocumentType(type) {
    currentDocumentType = type;
    const map = {
        proposal: 'Project Proposal', 
        saf: 'SAF Request', 
        facility: 'Facility Request', 
        communication: 'Communication Letter'
    };
    document.getElementById('documentTypeDropdown').textContent = map[type];
    
    // Hide all forms
    document.querySelectorAll('.document-form').forEach(el => el.style.display = 'none');
    
    // Show chosen form
    if (type === 'proposal') document.getElementById('proposal-form').style.display = 'block';
    if (type === 'saf') document.getElementById('saf-form').style.display = 'block';
    if (type === 'facility') document.getElementById('facility-form').style.display = 'block';
    if (type === 'communication') document.getElementById('communication-form').style.display = 'block';
    
    scheduleGenerate();
}

/*******************************
 * Document Generation Utility
 *******************************/
function scheduleGenerate() {
    clearTimeout(genTimeout);
    genTimeout = setTimeout(generateDocument, 220);
}

function generateDocument() {
    let html = '';
    if (currentDocumentType === 'proposal') {
        html = generateProposalHTML(collectProposalData());
    } else if (currentDocumentType === 'saf') {
        html = generateSAFHTML(collectSAFData());
    } else if (currentDocumentType === 'facility') {
        html = generateFacilityHTML(collectFacilityData());
    } else if (currentDocumentType === 'communication') {
        html = generateCommunicationHTML(collectCommunicationData());
    }
    paginateAndRender(html);
}

/*******************************
 * Pagination & Rendering
 *******************************/
function paginateAndRender(html) {
    const container = document.getElementById('paper-container');
    container.innerHTML = '';
    
    // The generators already produce one or more .paper-page blocks; render them directly
    const temp = document.createElement('div');
    temp.innerHTML = html;
    const nodes = temp.querySelectorAll('.paper-page');
    pages = [];
    
    if (nodes.length) {
        nodes.forEach(n => pages.push(n.outerHTML));
    } else {
        pages.push(`<div class="paper-page">${html}</div>`);
    }
    
    totalPages = pages.length;
    currentPage = 1;
    renderPage();
}

function renderPage() {
    const container = document.getElementById('paper-container');
    container.innerHTML = pages[currentPage - 1] || '<div class="paper-page"><em>No preview</em></div>';
    document.getElementById('page-controls').style.display = totalPages > 1 ? 'flex' : 'none';
    document.getElementById('page-indicator').textContent = `Page ${currentPage} of ${totalPages}`;
}

function nextPage() {
    if (currentPage < totalPages) {
        currentPage++;
        renderPage();
    }
}

function previousPage() {
    if (currentPage > 1) {
        currentPage--;
        renderPage();
    }
}

/*******************************
 * Data Collectors
 *******************************/
function collectProposalData() {
    const budgetRows = [];
    document.querySelectorAll('#budget-body-prop tr').forEach(tr => {
        const name = tr.querySelector('.item-name')?.value || '';
        const price = parseFloat(tr.querySelector('.item-price')?.value) || 0;
        const size = tr.querySelector('.item-size')?.value || '';
        const qty = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
        const total = price * qty;
        if (name || price || size || qty) budgetRows.push({ name, price, size, qty, total });
    });
    
    const programRows = [];
    document.querySelectorAll('#program-rows-prop .program-row').forEach(r => {
        const start = r.querySelectorAll('.time-selector')[0]?.getAttribute('data-time') || '';
        const end = r.querySelectorAll('.time-selector')[1]?.getAttribute('data-time') || '';
        const act = r.querySelector('.activity-input')?.value || '';
        if (start || end || act) programRows.push({ start, end, act });
    });
    
    return {
        date: document.getElementById('prop-date').value,
        organizer: document.getElementById('prop-organizer').value,
        department: document.getElementById('prop-department').value,
        title: document.getElementById('prop-title').value,
        lead: document.getElementById('prop-lead').value,
        rationale: document.getElementById('prop-rationale').value,
        objectives: (document.getElementById('prop-objectives').value || '').split('\n').map(s => s.trim()).filter(Boolean),
        ilos: (document.getElementById('prop-ilos').value || '').split('\n').map(s => s.trim()).filter(Boolean),
        budgetSource: document.getElementById('prop-budget-source').value,
        venue: document.getElementById('prop-venue').value,
        mechanics: document.getElementById('prop-mechanics').value,
        scheduleSummary: document.getElementById('prop-schedule').value,
        program: programRows,
        budget: budgetRows
    };
}

function collectSAFData() {
    const categories = {
        ssc: document.getElementById('saf-ssc').checked,
        csc: document.getElementById('saf-csc').checked,
        cca: document.getElementById('saf-cca').checked,
        ex: document.getElementById('saf-ex').checked,
        osa: document.getElementById('saf-osa').checked,
        idev: document.getElementById('saf-idev').checked,
        others: document.getElementById('saf-others').checked,
        othersText: document.getElementById('saf-others-text').value || ''
    };
    
    const funds = {
        ssc: { available: parseFloat(document.getElementById('avail-ssc')?.value) || 0, requested: parseFloat(document.getElementById('req-ssc')?.value) || 0 },
        csc: { available: parseFloat(document.getElementById('avail-csc')?.value) || 0, requested: parseFloat(document.getElementById('req-csc')?.value) || 0 },
        cca: { available: parseFloat(document.getElementById('avail-cca')?.value) || 0, requested: parseFloat(document.getElementById('req-cca')?.value) || 0 },
        ex: { available: parseFloat(document.getElementById('avail-ex')?.value) || 0, requested: parseFloat(document.getElementById('req-ex')?.value) || 0 },
        osa: { available: parseFloat(document.getElementById('avail-osa')?.value) || 0, requested: parseFloat(document.getElementById('req-osa')?.value) || 0 },
        idev: { available: parseFloat(document.getElementById('avail-idev')?.value) || 0, requested: parseFloat(document.getElementById('req-idev')?.value) || 0 },
        others: { available: parseFloat(document.getElementById('avail-others')?.value) || 0, requested: parseFloat(document.getElementById('req-others')?.value) || 0 }
    };
    
    return {
        dept: document.getElementById('saf-dept').value,
        title: document.getElementById('saf-title').value,
        dateRequested: document.getElementById('saf-date').value,
        categories,
        funds
    };
}

function collectFacilityData() {
    return {
        name: document.getElementById('fac-name').value,
        dateNeeded: document.getElementById('fac-date').value,
        facility: document.getElementById('fac-facility').value,
        notes: document.getElementById('fac-notes').value
    };
}

function collectCommunicationData() {
    function readPeople(listId) {
        const arr = [];
        document.querySelectorAll(`#${listId} .person-entry`).forEach(e => {
            const name = e.querySelector('.person-name')?.value || '';
            const title = e.querySelector('.title-input')?.value || '';
            if (name || title) arr.push({ name, title });
        });
        return arr;
    }
    
    return {
        date: document.getElementById('comm-date').value,
        forList: readPeople('for-list'),
        fromList: readPeople('from-list'),
        notedList: readPeople('noted-list'),
        approvedList: readPeople('approved-list'),
        subject: document.getElementById('comm-subject').value,
        body: document.getElementById('comm-body').value
    };
}

/*******************************
 * HTML Generators
 *******************************/
function generateProposalHTML(d) {
    const header = `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;"><div class="logo-placeholder">LOGO</div><div style="text-align:center"><div style="font-weight:800">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-size:0.95rem">Project Proposal</div></div><div class="logo-placeholder">LOGO</div></div>`;
    
    const objectivesHtml = (d.objectives && d.objectives.length) ? 
        `<ol>${d.objectives.map(o => `<li>${escapeHtml(o)}</li>`).join('')}</ol>` : 
        '<div class="text-muted">No objectives provided</div>';
    
    const ilosHtml = (d.ilos && d.ilos.length) ? 
        `<ol>${d.ilos.map(o => `<li>${escapeHtml(o)}</li>`).join('')}</ol>` : 
        '<div class="text-muted">No ILOs provided</div>';
    
    const budgetHtml = (d.budget && d.budget.length) ? 
        `<table style="width:100%;border-collapse:collapse;margin-top:8px"><thead><tr style="background:#f8f9fa"><th style="border:1px solid #ddd;padding:6px">Item</th><th style="border:1px solid #ddd;padding:6px">Qty</th><th style="border:1px solid #ddd;padding:6px">Unit Price</th><th style="border:1px solid #ddd;padding:6px">Total</th></tr></thead><tbody>${d.budget.map(b => `<tr><td style="border:1px solid #ddd;padding:6px">${escapeHtml(b.name)}</td><td style="border:1px solid #ddd;padding:6px;text-align:right">${b.qty}</td><td style="border:1px solid #ddd;padding:6px;text-align:right">₱${b.price.toFixed(2)}</td><td style="border:1px solid #ddd;padding:6px;text-align:right">₱${b.total.toFixed(2)}</td></tr>`).join('')}</tbody></table>` : 
        '<div class="text-muted">No budget items</div>';
    
    const programHtml = (d.program && d.program.length) ? 
        `<table style="width:100%;border-collapse:collapse;margin-top:8px"><thead><tr style="background:#f8f9fa"><th style="border:1px solid #ddd;padding:6px">Start</th><th style="border:1px solid #ddd;padding:6px">End</th><th style="border:1px solid #ddd;padding:6px">Activity</th></tr></thead><tbody>${d.program.map(p => `<tr><td style="border:1px solid #ddd;padding:6px">${escapeHtml(p.start)}</td><td style="border:1px solid #ddd;padding:6px">${escapeHtml(p.end)}</td><td style="border:1px solid #ddd;padding:6px">${escapeHtml(p.act)}</td></tr>`).join('')}</tbody></table>` : 
        '<div class="text-muted">No program schedule</div>';

    return `<div class="paper-page">${header}<div><strong>Title:</strong> ${escapeHtml(d.title || '[Project Title]')}</div><div style="margin-top:6px"><strong>Date:</strong> ${formatDate(d.date)}</div><div style="margin-top:6px"><strong>Organizer:</strong> ${escapeHtml(d.organizer || '')}</div><div style="margin-top:6px"><strong>Lead Facilitator:</strong> ${escapeHtml(d.lead || '')}</div><div style="margin-top:6px"><strong>Department:</strong> ${escapeHtml(d.department || '')}</div><div style="margin-top:12px"><strong>Rationale:</strong><div style="margin-top:6px">${(d.rationale || '').replace(/\n/g, '<br>') || '<em>None provided</em>'}</div></div><div style="margin-top:12px"><strong>Objectives:</strong>${objectivesHtml}</div><div style="margin-top:12px"><strong>Intended Learning Outcomes:</strong>${ilosHtml}</div><div style="margin-top:12px"><strong>Source of Budget:</strong> ${escapeHtml(d.budgetSource || '')}</div><div style="margin-top:12px"><strong>Venue:</strong> ${escapeHtml(d.venue || '')}</div><div style="margin-top:12px"><strong>Mechanics:</strong><div style="margin-top:6px">${(d.mechanics || '').replace(/\n/g, '<br>') || '<em>None provided</em>'}</div></div><div style="margin-top:12px"><strong>Schedule (summary):</strong><div style="margin-top:6px">${(d.scheduleSummary || '').replace(/\n/g, '<br>') || '<em>None provided</em>'}</div></div><div style="margin-top:12px"><strong>Program Schedule:</strong>${programHtml}</div><div style="margin-top:12px"><strong>Budget Requirements:</strong>${budgetHtml}</div></div>`;
}

function generateSAFHTML(d) {
    const header = `<div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1rem;"><div class="logo-placeholder"></div><div style="text-align:center"><div style="font-weight:800">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-weight:700">FUND RELEASE FORM</div></div><div class="logo-placeholder"></div></div>`;
    
    // Build funds table HTML
    const funds = ['ssc', 'csc', 'cca', 'ex', 'osa', 'idev', 'others'];
    const fundsHtml = `<table style="width:100%;border-collapse:collapse;margin-top:8px"><thead><tr style="background:#f8f9fa"><th style="border:1px solid #000;padding:6px">Fund/s</th><th style="border:1px solid #000;padding:6px">Available</th><th style="border:1px solid #000;padding:6px">Requested</th><th style="border:1px solid #000;padding:6px">Balance</th></tr></thead><tbody>${funds.map(code => {
        const label = code === 'ex' ? 'Exemplar' : (code === 'osa' ? 'Office of Student Affairs' : (code === 'idev' ? 'Idev' : (code === 'others' ? 'Others' : code.toUpperCase())));
        const f = d.funds[code] || { available: 0, requested: 0 };
        const bal = (f.available || 0) - (f.requested || 0);
        return `<tr><td style="border:1px solid #000;padding:6px">${label}</td><td style="border:1px solid #000;padding:6px;text-align:right">₱${(f.available || 0).toFixed(2)}</td><td style="border:1px solid #000;padding:6px;text-align:right">₱${(f.requested || 0).toFixed(2)}</td><td style="border:1px solid #000;padding:6px;text-align:right;color:${bal < 0 ? '#dc3545' : '#000'}">₱${bal.toFixed(2)}</td></tr>`;
    }).join('')}</tbody></table>`;

    // Signature row HTML
    const signatureRow = `<div style="margin-top:12px"><table style="width:100%;border-collapse:collapse"><thead><tr style="background:#f8f9fa"><th style="border:1px solid #000;padding:8px">Requested by</th><th style="border:1px solid #000;padding:8px">Noted by</th><th style="border:1px solid #000;padding:8px">Recommended by</th><th style="border:1px solid #000;padding:8px">Approved by</th><th style="border:1px solid #000;padding:8px">Released by</th></tr></thead><tbody><tr><td style="border:1px solid #000;padding:18px;text-align:center;vertical-align:bottom"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">Requester</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-size:.95rem">Noted</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">Recommender</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">Approver</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">Accounting</div></td></tr><tr><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ______</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ______</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ______</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ______</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ______</td></tr></tbody></table></div>`;

    // Assemble final HTML
    const leftDetails = `<div><strong>College / Department:</strong> ${escapeHtml(d.dept || '')}</div><div style="margin-top:6px"><strong>Project Title:</strong> ${escapeHtml(d.title || '')}</div><div style="margin-top:6px"><strong>Date Requested:</strong> ${formatDate(d.dateRequested)}</div>`;
    return `<div class="paper-page">${header}<div style="margin-top:8px">${leftDetails}</div>${fundsHtml}${signatureRow}<hr style="border-top:2px dashed #666;margin:16px 0;">${header}<div style="margin-top:8px">${leftDetails}</div>${fundsHtml}${signatureRow}</div>`;
}

function generateFacilityHTML(d) {
    const header = `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;"><div class="logo-placeholder"></div><div style="text-align:center"><div style="font-weight:800">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-size:0.95rem">Facility Request</div></div><div class="logo-placeholder"></div></div>`;
    return `<div class="paper-page">${header}<div><strong>Requested By:</strong> ${escapeHtml(d.name || '')}</div><div style="margin-top:6px"><strong>Date Needed:</strong> ${formatDate(d.dateNeeded)}</div><div style="margin-top:6px"><strong>Facility:</strong> ${escapeHtml(d.facility || '')}</div><div style="margin-top:12px"><strong>Purpose / Notes:</strong><div style="border:1px solid #eee;padding:8px;min-height:120px">${(d.notes || '').replace(/\n/g, '<br>') || '&nbsp;'}</div></div></div>`;
}

function generateCommunicationHTML(d) {
    function renderPeople(list) {
        if (!list || !list.length) return '<div class="comm-indent"><em>—</em></div>';
        return `<div class="comm-indent">${list.map(p => `<div style="margin-bottom:.35rem"><strong>${escapeHtml(p.name || '__________')}</strong><div style="font-style:italic;text-transform:capitalize">${escapeHtml(p.title || '')}</div></div>`).join('')}</div>`;
    }
    
    const header = `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;"><div class="logo-placeholder"></div><div style="text-align:center"><div style="font-weight:800">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-size:0.95rem">Communication Letter</div></div><div class="logo-placeholder"></div></div>`;
    const bodyHtml = (d.body || '').replace(/\n/g, '<br>') || '&nbsp;';
    return `<div class="paper-page">${header}<div><div><strong>Date:</strong> ${formatDate(d.date)}</div><div style="display:flex;gap:2rem;margin-top:12px"><div style="flex:1"><strong>For:</strong>${renderPeople(d.forList)}</div><div style="flex:1"><strong>From:</strong>${renderPeople(d.fromList)}</div></div><div style="display:flex;gap:2rem;margin-top:12px"><div style="flex:1"><strong>Noted:</strong>${renderPeople(d.notedList)}</div><div style="flex:1"><strong>Approved:</strong>${renderPeople(d.approvedList)}</div></div><div style="margin-top:12px"><strong>Subject:</strong> ${escapeHtml(d.subject || '')}<div style="height:1px;background:#e9ecef;margin-top:6px;margin-bottom:8px;width:85%"></div></div><div style="margin-top:12px">${bodyHtml}</div></div></div>`;
}

/*******************************
 * Helper Functions
 *******************************/
function formatDate(s) {
    if (!s) return '[Date]';
    try {
        const dt = new Date(s);
        return dt.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) {
        return s;
    }
}

function escapeHtml(unsafe) {
    if (unsafe === undefined || unsafe === null) return '';
    return String(unsafe).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

/*******************************
 * Budget & Program Helpers for Proposal
 *******************************/
function addBudgetRowProp() {
    const tbody = document.getElementById('budget-body-prop');
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input class="form-control item-name" placeholder="Item"></td>
        <td><input class="form-control item-price" type="number" step="0.01" placeholder="0.00"></td>
        <td><input class="form-control item-size" placeholder="Size/Description"></td>
        <td><input class="form-control item-qty" type="number" min="1" value="1"></td>
        <td class="item-total">₱0.00</td>
        <td><button class="btn btn-sm btn-danger" onclick="removeBudgetRowProp(this)">×</button></td>`;
    tbody.appendChild(tr);
    
    // Attach listeners
    tr.querySelectorAll('.item-price, .item-qty').forEach(i => i.addEventListener('input', calcBudgetTotalsProp));
    scheduleGenerate();
}

function removeBudgetRowProp(btn) {
    btn.closest('tr').remove();
    calcBudgetTotalsProp();
    scheduleGenerate();
}

function calcBudgetTotalsProp() {
    let grand = 0;
    document.querySelectorAll('#budget-body-prop tr').forEach(tr => {
        const price = parseFloat(tr.querySelector('.item-price')?.value) || 0;
        const qty = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
        const total = price * qty;
        tr.querySelector('.item-total').textContent = `₱${total.toFixed(2)}`;
        grand += total;
    });
    document.getElementById('grand-total-prop').textContent = `₱${grand.toFixed(2)}`;
}

function setupBudgetProp() {
    document.querySelectorAll('#budget-body-prop .item-price, #budget-body-prop .item-qty')
        .forEach(i => i.addEventListener('input', calcBudgetTotalsProp));
    calcBudgetTotalsProp();
}

function addProgramRowProp() {
    const container = document.getElementById('program-rows-prop');
    const div = document.createElement('div');
    div.className = 'program-row';
    div.innerHTML = `<div class="time-selector" onclick="openTimeSelector(this)"><span class="time-display">Start</span><i class="bi bi-clock ms-2"></i></div>
        <div class="time-selector" onclick="openTimeSelector(this)"><span class="time-display">End</span><i class="bi bi-clock ms-2"></i></div>
        <div><input type="text" class="activity-input form-control" placeholder="Activity description"></div>
        <div><button class="btn btn-sm btn-danger" onclick="removeProgramRow(this)">×</button></div>`;
    container.appendChild(div);
    scheduleGenerate();
}

function removeProgramRow(btn) {
    btn.closest('.program-row').remove();
    scheduleGenerate();
}

function setupProgramProp() {
    // Ensure any pre-existing controls are wired
    document.querySelectorAll('#program-rows-prop .time-selector')
        .forEach(el => el.addEventListener('click', () => openTimeSelector(el)));
}

function openTimeSelector(el) {
    // Simple prompt time selector
    const t = prompt('Enter time (e.g., 9:00 AM)', el.getAttribute('data-time') || '');
    if (t !== null) {
        el.setAttribute('data-time', t);
        el.querySelector('.time-display').textContent = t;
        scheduleGenerate();
    }
}

/*******************************
 * SAF Amount Controls & Locking
 *******************************/
function changeRequestedSAF(id, delta) {
    const el = document.getElementById(id);
    if (!el || el.disabled) return;
    let val = parseFloat(el.value) || 0;
    val += delta;
    if (val < 0) val = 0;
    el.value = val;
    updateSAFBalances();
    scheduleGenerate();
}

function updateSAFBalances() {
    const codes = ['ssc', 'csc', 'cca', 'ex', 'osa', 'idev', 'others'];
    codes.forEach(code => {
        const avail = parseFloat(document.getElementById(`avail-${code}`)?.value) || 0;
        const req = parseFloat(document.getElementById(`req-${code}`)?.value) || 0;
        const bal = avail - req;
        const el = document.getElementById(`bal-${code}`);
        if (el) {
            el.textContent = `₱${bal.toFixed(2)}`;
            el.style.color = bal < 0 ? '#dc3545' : '#000';
        }
    });
}

function updateSAFLocks() {
    const codes = ['ssc', 'csc', 'cca', 'ex', 'osa', 'idev', 'others'];
    codes.forEach(code => {
        const cbId = code === 'ex' ? 'saf-ex' : (code === 'others' ? 'saf-others' : `saf-${code}`);
        const cb = document.getElementById(cbId);
        const avail = document.getElementById(`avail-${code}`);
        const req = document.getElementById(`req-${code}`);
        const row = document.getElementById(`row-${code}`);
        
        if (!cb || !avail || !req || !row) return;
        
        if (cb.checked) {
            row.style.display = '';
            avail.disabled = false;
            req.disabled = false;
            avail.style.background = '';
            req.style.background = '';
        } else {
            row.style.display = 'none';
            avail.disabled = true;
            req.disabled = true;
            avail.value = '';
            req.value = '';
            if (document.getElementById(`bal-${code}`)) {
                document.getElementById(`bal-${code}`).textContent = '₱0.00';
            }
            avail.style.background = '#f8f9fa';
            req.style.background = '#f8f9fa';
        }
    });
    updateSAFBalances();
}

/*******************************
 * Communication Helpers
 *******************************/
function addPerson(listId) {
    const list = document.getElementById(listId);
    if (!list) return;
    const div = document.createElement('div');
    div.className = 'person-entry';
    div.innerHTML = `<input class="form-control form-control-sm person-name" placeholder="${listId.split('-')[0].toUpperCase()} name"><input class="form-control form-control-sm title-input" placeholder="Title">`;
    list.appendChild(div);
    scheduleGenerate();
}

function removePerson(listId) {
    const list = document.getElementById(listId);
    if (!list) return;
    const items = list.querySelectorAll('.person-entry');
    if (items.length) {
        items[items.length - 1].remove();
        scheduleGenerate();
    }
}

/*******************************
 * Print Function
 *******************************/
function printDocument() {
    window.print();
}

/*******************************
 * Event Listeners & Initialization
 *******************************/
document.addEventListener('DOMContentLoaded', () => {
    // Hooks for live preview
    document.querySelectorAll('input, textarea, select').forEach(el => {
        el.addEventListener('input', scheduleGenerate);
        el.addEventListener('change', scheduleGenerate);
    });

    // SAF checkboxes wiring
    document.querySelectorAll('.saf-cat').forEach(cb => 
        cb.addEventListener('change', () => {
            updateSAFLocks();
            scheduleGenerate();
        })
    );
    
    // Initialize SAF form
    updateSAFLocks();

    // Budget & program handlers for proposal
    setupBudgetProp();
    setupProgramProp();

    // Initial display
    selectDocumentType('proposal');
});

// Wire general input/change to live preview
document.addEventListener('input', function(e) {
    if (e.target.closest('.editor-panel') || e.target.closest('.form-section')) {
        scheduleGenerate();
    }
});

document.addEventListener('change', function(e) {
    if (e.target.closest('.editor-panel') || e.target.closest('.form-section')) {
        scheduleGenerate();
    }
});

// Kickstart initial generation
setTimeout(() => {
    scheduleGenerate();
}, 300);
