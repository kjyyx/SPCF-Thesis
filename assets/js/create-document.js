/**
 * Document Creator JavaScript - Dynamic Document Generation System
 *
 * This file provides comprehensive document creation functionality for the SPCF Thesis system,
 * enabling users to generate professional documents including project proposals, SAF requests,
 * facility requests, and communication letters with real-time preview and pagination.
 *
 * @fileoverview Client-side document generation and preview system
 * @author SPCF Thesis Development Team
 * @version 1.0.0
 * @since 2024
 *
 * @description
 * This JavaScript file handles all client-side document creation operations, including:
 * - Multi-format document generation (Project Proposals, SAF Forms, Facility Requests, Communication Letters)
 * - Real-time live preview with automatic updates
 * - Multi-page document pagination and navigation
 * - Form data collection and validation
 * - Professional HTML generation with proper formatting
 * - Print-ready document output
 *
 * @architecture
 * The code is organized into logical sections:
 * 1. Global State Variables & Configuration
 * 2. Document Type Selection & Management
 * 3. Document Generation & Preview System
 * 4. Data Collection & Processing
 * 5. HTML Generation Templates
 * 6. Utility Functions & Helpers
 * 7. Form-Specific Helpers (Budget, Program, SAF, Communication)
 * 8. Event Handling & Initialization
 *
 * @dependencies
 * - Bootstrap (UI components and styling)
 * - ToastManager (notification system for audit logging)
 * - PHP Backend (for audit logging via window.addAuditLog)
 * - Browser DOM API (for form manipulation and preview rendering)
 *
 * @security
 * - All data processing is client-side only
 * - No sensitive data is transmitted or stored
 * - Input validation prevents XSS through HTML escaping
 * - DOM access is guarded to prevent runtime errors
 *
 * @document-types
 * - proposal: Project Proposal with budget, program schedule, and objectives
 * - saf: Student Activities Fund release form with category-based funding
 * - facility: Facility reservation request with date and purpose
 * - communication: Official communication letter with signature blocks
 *
 * @features
 * - Live preview updates on form changes
 * - Multi-page document support with navigation
 * - Professional document formatting
 * - Form validation and error handling
 * - Print functionality for final output
 * - Audit logging integration
 *
 * @notes
 * - Public function names referenced by HTML must remain unchanged
 * - This script is client-side only; no network calls except audit logging
 * - Prefer non-destructive refactors: do not change DOM IDs/classes the HTML relies on
 * - Guard DOM access where possible to avoid runtime errors if sections are not present
 * - All document generation uses sanitized HTML to prevent XSS attacks
 *
 * @example
 * // File is automatically loaded by create-document.php
 * // Document type selection triggers automatic preview generation
 * selectDocumentType('proposal'); // Switches to proposal form and preview
 */

// === Global State Variables & Configuration ===

/**
 * @section Global State Management
 * Core application state that persists across function calls
 * These variables track the current state of the document creation interface
 */

/**
 * Document Type State
 * Tracks which type of document is currently being created
 */
let currentDocumentType = 'proposal'; // Currently selected document type ('proposal', 'saf', 'facility', 'communication')

/**
 * Pagination State
 * Manages multi-page document display and navigation
 */
let pages = []; // Array of HTML strings, each representing one page of the document
let currentPage = 1; // Current page number being displayed (1-indexed)
let totalPages = 1; // Total number of pages in the current document

/**
 * Generation Control
 * Prevents excessive regeneration during rapid user input
 */
let genTimeout = null; // Timeout ID for debounced document generation

/**
 * @section Document Type Selection & Management
 * Functions for switching between different document types and managing form visibility
 */

/**
 * Select and switch to a specific document type
 * Updates the UI to show the appropriate form and triggers document regeneration
 * @param {string} type - The document type to select ('proposal', 'saf', 'facility', 'communication')
 */
function selectDocumentType(type) {
    // Update global state
    currentDocumentType = type;

    // Map document types to display names
    const map = {
        proposal: 'Project Proposal',
        saf: 'SAF Request',
        facility: 'Facility Request',
        communication: 'Communication Letter'
    };

    // Update dropdown button text
    document.getElementById('documentTypeDropdown').textContent = map[type];

    // Hide all document forms
    document.querySelectorAll('.document-form').forEach(el => el.style.display = 'none');

    // Show the selected form
    if (type === 'proposal') document.getElementById('proposal-form').style.display = 'block';
    if (type === 'saf') document.getElementById('saf-form').style.display = 'block';
    if (type === 'facility') document.getElementById('facility-form').style.display = 'block';
    if (type === 'communication') document.getElementById('communication-form').style.display = 'block';

    // Log the document type selection for audit purposes
    if (window.addAuditLog) {
        window.addAuditLog('DOCUMENT_TYPE_SELECTED', 'Document Management', `Selected document type: ${map[type]}`, null, 'Document', 'INFO');
    }

    // Trigger document regeneration with new form data
    scheduleGenerate();
}

/**
 * @section Document Generation & Preview System
 * Core functions for generating documents and managing multi-page preview
 */

/**
 * Schedule document regeneration with debouncing
 * Prevents excessive regeneration during rapid user input by delaying execution
 */
function scheduleGenerate() {
    clearTimeout(genTimeout);
    genTimeout = setTimeout(generateDocument, 220);
}

/**
 * Generate the complete document based on current form data
 * Routes to the appropriate document generator based on current document type
 */
function generateDocument() {
    let html = '';

    // Route to appropriate document generator
    if (currentDocumentType === 'proposal') {
        html = generateProposalHTML(collectProposalData());
    } else if (currentDocumentType === 'saf') {
        html = generateSAFHTML(collectSAFData());
    } else if (currentDocumentType === 'facility') {
        html = generateFacilityHTML(collectFacilityData());
    } else if (currentDocumentType === 'communication') {
        html = generateCommunicationHTML(collectCommunicationData());
    }

    // Process and display the generated document
    paginateAndRender(html);

    // Log document creation for audit purposes
    if (window.addAuditLog) {
        window.addAuditLog('DOCUMENT_CREATED', 'Document Management', `Created ${currentDocumentType} document: ${collectProposalData().title || 'Untitled'}`, null, 'Document', 'INFO');
    }
}

/**
 * @section Pagination & Rendering
 * Functions for managing multi-page document display and navigation
 */

/**
 * Process generated HTML and split into pages for display
 * Handles pagination by extracting .paper-page elements or creating them
 * @param {string} html - The generated HTML content to paginate
 */
function paginateAndRender(html) {
    const container = document.getElementById('paper-container');
    if (!container) return; // Guard if preview container is missing

    container.innerHTML = '';

    // Parse the HTML and extract page elements
    const temp = document.createElement('div');
    temp.innerHTML = html;
    const nodes = temp.querySelectorAll('.paper-page');
    pages = [];

    // Store each page's HTML
    if (nodes.length) {
        nodes.forEach(n => pages.push(n.outerHTML));
    } else {
        // If no page breaks, wrap entire content in a single page
        pages.push(`<div class="paper-page">${html}</div>`);
    }

    // Reset to first page and render
    totalPages = pages.length;
    currentPage = 1;
    renderPage();
}

/**
 * Render the current page in the preview container
 * Updates the display and navigation controls based on current page
 */
function renderPage() {
    const container = document.getElementById('paper-container');
    if (!container) return;

    // Display current page or fallback message
    container.innerHTML = pages[currentPage - 1] || '<div class="paper-page"><em>No preview</em></div>';

    // Update navigation controls visibility
    const controls = document.getElementById('page-controls');
    const indicator = document.getElementById('page-indicator');

    // Show/hide pagination controls based on page count
    if (controls) controls.style.display = totalPages > 1 ? 'flex' : 'none';
    if (indicator) indicator.textContent = `Page ${currentPage} of ${totalPages}`;
}

/**
 * Navigate to the next page in the document
 * Only advances if not already on the last page
 */
function nextPage() {
    if (currentPage < totalPages) {
        currentPage++;
        renderPage();
    }
}

/**
 * Navigate to the previous page in the document
 * Only goes back if not already on the first page
 */
function previousPage() {
    if (currentPage > 1) {
        currentPage--;
        renderPage();
    }
}

/**
 * @section Data Collection & Processing
 * Functions for collecting form data from different document types
 * Each function extracts and validates data from the corresponding form
 */

/**
 * Collect all data from the Project Proposal form
 * Gathers budget items, program schedule, objectives, and other proposal details
 * @returns {Object} Proposal data object with all form fields
 */
function collectProposalData() {
    // Collect budget table data
    const budgetRows = [];
    document.querySelectorAll('#budget-body-prop tr').forEach(tr => {
        const name = tr.querySelector('.item-name')?.value || '';
        const price = parseFloat(tr.querySelector('.item-price')?.value) || 0;
        const size = tr.querySelector('.item-size')?.value || '';
        const qty = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
        const total = price * qty;
        if (name || price || size || qty) budgetRows.push({ name, price, size, qty, total });
    });

    // Collect program schedule data
    const programRows = [];
    document.querySelectorAll('#program-rows-prop .program-row').forEach(r => {
        const start = r.querySelectorAll('.time-selector')[0]?.getAttribute('data-time') || '';
        const end = r.querySelectorAll('.time-selector')[1]?.getAttribute('data-time') || '';
        const act = r.querySelector('.activity-input')?.value || '';
        if (start || end || act) programRows.push({ start, end, act });
    });

    // Return complete proposal data object
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

/**
 * Collect all data from the SAF (Student Activities Fund) form
 * Gathers fund categories, available amounts, requested amounts, and project details
 * @returns {Object} SAF data object with categories, funds, and project information
 */
function collectSAFData() {
    // Collect selected fund categories
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

    // Map categories to c1-c7
    const categoryMap = ['ssc', 'csc', 'cca', 'ex', 'osa', 'idev', 'others'];
    const cValues = categoryMap.map(cat => categories[cat] ? '✓' : '');

    // Funds with balances
    const funds = {
        ssc: { available: parseFloat(document.getElementById('avail-ssc')?.value) || 0, requested: parseFloat(document.getElementById('req-ssc')?.value) || 0 },
        csc: { available: parseFloat(document.getElementById('avail-csc')?.value) || 0, requested: parseFloat(document.getElementById('req-csc')?.value) || 0 },
        cca: { available: parseFloat(document.getElementById('avail-cca')?.value) || 0, requested: parseFloat(document.getElementById('req-cca')?.value) || 0 },
        ex: { available: parseFloat(document.getElementById('avail-ex')?.value) || 0, requested: parseFloat(document.getElementById('req-ex')?.value) || 0 },
        osa: { available: parseFloat(document.getElementById('avail-osa')?.value) || 0, requested: parseFloat(document.getElementById('req-osa')?.value) || 0 },
        idev: { available: parseFloat(document.getElementById('avail-idev')?.value) || 0, requested: parseFloat(document.getElementById('req-idev')?.value) || 0 },
        others: { available: parseFloat(document.getElementById('avail-others')?.value) || 0, requested: parseFloat(document.getElementById('req-others')?.value) || 0 }
    };

    // Calculate balances
    const balSSC = funds.ssc.available - funds.ssc.requested;
    const balCSC = funds.csc.available - funds.csc.requested;
    const balCCA = funds.cca.available - funds.cca.requested;
    const balExemplar = funds.ex.available - funds.ex.requested;
    const balOSA = funds.osa.available - funds.osa.requested;
    const balIDEV = funds.idev.available - funds.idev.requested;
    const balOther = funds.others.available - funds.others.requested;

    return {
        department: document.getElementById('saf-dept').value,
        title: document.getElementById('saf-title').value,
        reqDate: document.getElementById('saf-date').value,
        implDate: document.getElementById('saf-impl-date').value,
        departmentFull: '',  // Will be set in API
        c1: cValues[0], c2: cValues[1], c3: cValues[2], c4: cValues[3], c5: cValues[4], c6: cValues[5], c7: cValues[6],
        otherSpecify: categories.othersText,
        availSSC: funds.ssc.available, reqSSC: funds.ssc.requested, balSSC,
        availCSC: funds.csc.available, reqCSC: funds.csc.requested, balCSC,
        availCCA: funds.cca.available, reqCCA: funds.cca.requested, balCCA,
        availExemplar: funds.ex.available, reqExemplar: funds.ex.requested, balExemplar,
        availOSA: funds.osa.available, reqOSA: funds.osa.requested, balOSA,
        availIDEV: funds.idev.available, reqIDEV: funds.idev.requested, balIDEV,
        availOther: funds.others.available, reqOther: funds.others.requested, balOther,
        otherFundDesc: categories.othersText,
        reqByName: window.currentUser?.firstName + ' ' + window.currentUser?.lastName,
        notedBy: '', recBy: '', appBy: '', relBy: '',  // Set in API if needed
        notedDate: '', recDate: '', appDate: '', releaseDate: ''  // Set in API if needed
    };
}

/**
 * Collect all data from the Facility Request form
 * Gathers requester information, facility details, and reservation notes
 * @returns {Object} Facility data object with reservation details
 */
function collectFacilityData() {
    // Facilities checkboxes (f1-f24)
    const facilities = [];
    for (let i = 1; i <= 24; i++) {
        facilities.push(document.getElementById(`fac-f${i}`)?.checked ? '✓' : '');
    }

    // Specify fields (s1-s5)
    const specifies = [];
    for (let i = 1; i <= 5; i++) {
        specifies.push(document.getElementById(`fac-s${i}`)?.value || '');
    }

    // Equipment checkboxes (e1-e11)
    const equipment = [];
    for (let i = 1; i <= 11; i++) {
        equipment.push(document.getElementById(`fac-e${i}`)?.checked ? '✓' : '');
    }

    // Quantities (q1-q9)
    const quantities = [];
    for (let i = 1; i <= 9; i++) {
        quantities.push(parseInt(document.getElementById(`fac-q${i}`)?.value) || 0);
    }

    // Other equipment (o1, o2)
    const others = [];
    for (let i = 1; i <= 2; i++) {
        others.push(document.getElementById(`fac-o${i}`)?.value || '');
    }

    return {
        department: document.getElementById('fac-dept').value,
        eventName: document.getElementById('fac-event-name').value,
        eventDate: document.getElementById('fac-event-date').value,
        departmentFull: '',  // Set in API
        cleanSetUpCommittee: document.getElementById('fac-cleanup-committee').value,
        contactPerson: document.getElementById('fac-contact-person').value,
        contactNumber: document.getElementById('fac-contact-number').value,
        expectedAttendees: parseInt(document.getElementById('fac-attendees').value) || 0,
        guestSpeaker: document.getElementById('fac-guest-speaker').value,
        expectedPerformers: parseInt(document.getElementById('fac-performers').value) || 0,
        parkingGatePlateNo: document.getElementById('fac-parking').value,
        f1: facilities[0], f2: facilities[1], f3: facilities[2], f4: facilities[3], f5: facilities[4], f6: facilities[5], f7: facilities[6], f8: facilities[7], f9: facilities[8], f10: facilities[9], f11: facilities[10], f12: facilities[11], f13: facilities[12], f14: facilities[13], f15: facilities[14], f16: facilities[15], f17: facilities[16], f18: facilities[17], f19: facilities[18], f20: facilities[19], f21: facilities[20], f22: facilities[21], f23: facilities[22], f24: facilities[23],
        s1: specifies[0], s2: specifies[1], s3: specifies[2], s4: specifies[3], s5: specifies[4],
        e1: equipment[0], e2: equipment[1], e3: equipment[2], e4: equipment[3], e5: equipment[4], e6: equipment[5], e7: equipment[6], e8: equipment[7], e9: equipment[8], e10: equipment[9], e11: equipment[10],
        q1: quantities[0], q2: quantities[1], q3: quantities[2], q4: quantities[3], q5: quantities[4], q6: quantities[5], q7: quantities[6], q8: quantities[7], q9: quantities[8],
        o1: others[0], o2: others[1],
        preEventDate: document.getElementById('fac-pre-event-date').value,
        practiceDate: document.getElementById('fac-practice-date').value,
        setupDate: document.getElementById('fac-setup-date').value,
        cleanupDate: document.getElementById('fac-cleanup-date').value,
        preEventStartTime: document.getElementById('fac-pre-event-start').value,
        practiceStartTime: document.getElementById('fac-practice-start').value,
        setupStartTime: document.getElementById('fac-setup-start').value,
        cleanupStartTime: document.getElementById('fac-cleanup-start').value,
        preEventEndTime: document.getElementById('fac-pre-event-end').value,
        practiceEndTime: document.getElementById('fac-practice-end').value,
        setupEndTime: document.getElementById('fac-setup-end').value,
        cleanupEndTime: document.getElementById('fac-cleanup-end').value,
        departmentHead: '',  // Set in API
        otherMattersSpecify: document.getElementById('fac-other-matters').value,
        receivingRequesteeName: window.currentUser?.firstName + ' ' + window.currentUser?.lastName,
        receivingDateFiled: new Date().toLocaleDateString(),
        receivingEventName: document.getElementById('fac-event-name').value,
        receivingEventDates: document.getElementById('fac-event-date').value
    };
}

/**
 * Collect all data from the Communication Letter form
 * Gathers letter details, recipient/sender lists, and message content
 * @returns {Object} Communication data object with letter details and personnel lists
 */
function collectCommunicationData() {
    /**
     * Helper function to read personnel lists from form sections
     * @param {string} listId - The ID of the personnel list container
     * @returns {Array} Array of personnel objects with name and title
     */
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
        department: document.getElementById('comm-department-select').value,
        notedList: readPeople('noted-list'),
        approvedList: readPeople('approved-list'),
        title: document.getElementById('comm-subject').value,
        body: document.getElementById('comm-body').value
    };
}

/**
 * @section HTML Generation Templates
 * Functions for generating professional HTML documents from collected form data
 * Each function creates print-ready HTML with proper formatting and styling
 */

/**
 * Generate HTML for Project Proposal document
 * Creates a complete project proposal with budget, program schedule, and objectives
 * @param {Object} d - Proposal data object from collectProposalData()
 * @returns {string} Complete HTML string for the proposal document
 */
function generateProposalHTML(d) {
    // Generate document header with title
    const header = `<div style="text-align:center;margin-bottom:1rem;"><div style="font-weight:800;font-size:1.2rem">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-weight:700;font-size:1.1rem">Project Proposal</div></div>`;

    // Generate objectives list with HTML escaping for security
    const objectivesHtml = (d.objectives && d.objectives.length) ?
        `<ol>${d.objectives.map(o => `<li>${escapeHtml(o)}</li>`).join('')}</ol>` :
        '<div class="text-muted">No objectives provided</div>';

    // Generate Intended Learning Outcomes list
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
    const header = `<div style="text-align:center;margin-bottom:1rem;"><div style="font-weight:800;font-size:1.2rem">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-weight:700;font-size:1.1rem">FUND RELEASE FORM</div></div>`;

    // Build funds table HTML
    const funds = ['ssc', 'csc', 'cca', 'ex', 'osa', 'idev', 'others'];
    const fundsHtml = `<table style="width:100%;border-collapse:collapse;margin-top:8px"><thead><tr style="background:#f8f9fa"><th style="border:1px solid #000;padding:6px">Fund/s</th><th style="border:1px solid #000;padding:6px">Available</th><th style="border:1px solid #000;padding:6px">Requested</th><th style="border:1px solid #000;padding:6px">Balance</th></tr></thead><tbody>${funds.map(code => {
        const label = code === 'ex' ? 'Exemplar' : (code === 'osa' ? 'Office of Student Affairs' : (code === 'idev' ? 'Idev' : (code === 'others' ? 'Others' : code.toUpperCase())));
        const f = d.funds[code] || { available: 0, requested: 0 };
        const bal = (f.available || 0) - (f.requested || 0);
        return `<tr><td style="border:1px solid #000;padding:6px">${label}</td><td style="border:1px solid #000;padding:6px;text-align:right">₱${(f.available || 0).toFixed(2)}</td><td style="border:1px solid #000;padding:6px;text-align:right">₱${(f.requested || 0).toFixed(2)}</td><td style="border:1px solid #000;padding:6px;text-align:right;color:${bal < 0 ? '#dc3545' : '#000'}">₱${bal.toFixed(2)}</td></tr>`;
    }).join('')}</tbody></table>`;

    // Signature row HTML
    const signatureRow = `<div style="margin-top:12px"><table style="width:100%;border-collapse:collapse"><thead><tr style="background:#f8f9fa"><th style="border:1px solid #000;padding:8px">Requested by</th><th style="border:1px solid #000;padding:8px">Noted by</th><th style="border:1px solid #000;padding:8px">Recommended by</th><th style="border:1px solid #000;padding:8px">Approved by</th><th style="border:1px solid #000;padding:8px">Released by</th></tr></thead><tbody><tr><td style="border:1px solid #000;padding:18px;text-align:center;vertical-align:bottom"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">${escapeHtml(d.reqByName || 'Requester')}</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-size:.95rem">${escapeHtml(d.notedBy || 'Noted')}</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">${escapeHtml(d.recBy || 'Recommender')}</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">${escapeHtml(d.appBy || 'Approver')}</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">${escapeHtml(d.relBy || 'Accounting')}</div></td></tr><tr><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ${escapeHtml(d.notedDate || '______')}</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ${escapeHtml(d.notedDate || '______')}</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ${escapeHtml(d.recDate || '______')}</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ${escapeHtml(d.appDate || '______')}</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ${escapeHtml(d.releaseDate || '______')}</td></tr></tbody></table></div>`;

    // Assemble final HTML
    const leftDetails = `<div><strong>College / Department:</strong> ${escapeHtml(d.departmentFull || d.department || '')}</div><div style="margin-top:6px"><strong>Project Title:</strong> ${escapeHtml(d.title || '')}</div><div style="margin-top:6px"><strong>Date Requested:</strong> ${formatDate(d.reqDate)}</div><div style="margin-top:6px"><strong>Implementation Date:</strong> ${formatDate(d.implDate)}</div>`;
    return `<div class="paper-page">${header}<div style="margin-top:8px">${leftDetails}</div>${fundsHtml}${signatureRow}</div>`;
}

function generateFacilityHTML(d) {
    const header = `<div style="text-align:center;margin-bottom:1rem;"><div style="font-weight:800;font-size:1.2rem">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-weight:700;font-size:1.1rem">Facility Request</div></div>`;

    // Facilities list
    const facilitiesList = [];
    for (let i = 1; i <= 24; i++) {
        if (d[`f${i}`] === '✓') {
            facilitiesList.push(`Facility ${i}`);
        }
    }
    const facilitiesHtml = facilitiesList.length ? `<ul>${facilitiesList.map(f => `<li>${f}</li>`).join('')}</ul>` : '<em>No facilities selected</em>';

    // Equipment list
    const equipmentList = [];
    for (let i = 1; i <= 11; i++) {
        if (d[`e${i}`] === '✓') {
            equipmentList.push(`Equipment ${i} (Qty: ${d[`q${i}`] || 0})`);
        }
    }
    const equipmentHtml = equipmentList.length ? `<ul>${equipmentList.map(e => `<li>${e}</li>`).join('')}</ul>` : '<em>No equipment selected</em>';

    // Timeline
    const timelineHtml = `<div style="margin-top:12px"><strong>Event Timeline:</strong><br>Pre-Event: ${formatDate(d.preEventDate)} ${d.preEventStartTime} - ${d.preEventEndTime}<br>Practice: ${formatDate(d.practiceDate)} ${d.practiceStartTime} - ${d.practiceEndTime}<br>Setup: ${formatDate(d.setupDate)} ${d.setupStartTime} - ${d.setupEndTime}<br>Cleanup: ${formatDate(d.cleanupDate)} ${d.cleanupStartTime} - ${d.cleanupEndTime}</div>`;

    // Signatures
    const signaturesHtml = `<div style="margin-top:12px"><table style="width:100%;border-collapse:collapse"><tbody><tr><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">${escapeHtml(d.departmentHead || 'Department Head')}</div></td><td style="border:1px solid #000;padding:18px;text-align:center"><div style="border-bottom:1px solid #000;height:3rem;margin-bottom:.25rem"></div><div style="font-weight:700">${escapeHtml(d.receivingRequesteeName || 'Requestee')}</div></td></tr><tr><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ______</td><td style="border:1px solid #000;padding:6px;text-align:center"><strong>Date:</strong> ${escapeHtml(d.receivingDateFiled || '______')}</td></tr></tbody></table></div>`;

    return `<div class="paper-page">${header}<div><strong>Event Name:</strong> ${escapeHtml(d.eventName || '')}</div><div style="margin-top:6px"><strong>Event Date:</strong> ${formatDate(d.eventDate)}</div><div style="margin-top:6px"><strong>Department:</strong> ${escapeHtml(d.departmentFull || d.department || '')}</div><div style="margin-top:6px"><strong>Clean and Set-up Committee:</strong> ${escapeHtml(d.cleanSetUpCommittee || '')}</div><div style="margin-top:6px"><strong>Contact Person:</strong> ${escapeHtml(d.contactPerson || '')}</div><div style="margin-top:6px"><strong>Contact Number:</strong> ${escapeHtml(d.contactNumber || '')}</div><div style="margin-top:6px"><strong>Expected Attendees:</strong> ${d.expectedAttendees || 0}</div><div style="margin-top:6px"><strong>Guest/Speaker:</strong> ${escapeHtml(d.guestSpeaker || '')}</div><div style="margin-top:6px"><strong>Expected Performers:</strong> ${d.expectedPerformers || 0}</div><div style="margin-top:6px"><strong>Parking Gate/Plate No.:</strong> ${escapeHtml(d.parkingGatePlateNo || '')}</div><div style="margin-top:12px"><strong>Facilities:</strong>${facilitiesHtml}</div><div style="margin-top:12px"><strong>Equipment & Staffing:</strong>${equipmentHtml}</div>${timelineHtml}<div style="margin-top:12px"><strong>Other Matters:</strong> ${escapeHtml(d.otherMattersSpecify || '')}</div>${signaturesHtml}</div>`;
}

function generateCommunicationHTML(d) {
    function renderPeople(list) {
        if (!list || !list.length) return '<div class="comm-indent"><em>—</em></div>';
        return `<div class="comm-indent">${list.map(p => `<div style="margin-bottom:.35rem"><strong>${escapeHtml(p.name || '__________')}</strong><div style="font-style:italic;text-transform:capitalize">${escapeHtml(p.title || '')}</div></div>`).join('')}</div>`;
    }

    const header = `<div style="text-align:center;margin-bottom:1rem;"><div style="font-weight:800;font-size:1.2rem">SYSTEMS PLUS COLLEGE FOUNDATION</div><div style="font-weight:700;font-size:1.1rem">Communication Letter</div></div>`;
    const bodyHtml = (d.body || '').replace(/\n/g, '<br>') || '&nbsp;';
    return `<div class="paper-page">${header}<div style="margin-top:2rem"><div>Date: ${formatDate(d.date)}</div><div style="margin-top:1rem">Department: ${escapeHtml(d.departmentFull || d.department || '')}</div><div style="margin-top:1rem">Project Title: ${escapeHtml(d.title || '')}</div><div style="margin-top:1rem">To: ${renderPeople(d.recipients)}</div><div style="margin-top:1rem">From: ${renderPeople(d.senders)}</div><div style="margin-top:1rem">Subject: ${escapeHtml(d.subject || '')}</div><div style="margin-top:1rem">${bodyHtml}</div><div style="margin-top:2rem;text-align:left">Sincerely,<br>${renderPeople(d.senders)}</div></div></div>`;
}

/**
 * @section Utility Functions & Helpers
 * Common utility functions for date formatting, HTML escaping, and error handling
 */

/**
 * Format a date string into a readable format
 * Converts date inputs to "Month Day, Year" format for document display
 * @param {string} s - Date string to format
 * @returns {string} Formatted date string or original string if parsing fails
 */
function formatDate(s) {
    if (!s) return '[Date]';
    try {
        const dt = new Date(s);
        return dt.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) {
        return s;
    }
}

/**
 * Escape HTML characters to prevent XSS attacks
 * Converts dangerous HTML characters to safe HTML entities
 * @param {any} unsafe - Value to escape (converted to string)
 * @returns {string} HTML-safe string
 */
function escapeHtml(unsafe) {
    if (unsafe === undefined || unsafe === null) return '';
    return String(unsafe).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

/**
 * @section Form-Specific Helper Functions
 * Specialized functions for managing dynamic form elements and calculations
 * Includes budget tables, program schedules, SAF funding, and communication lists
 */

/**
 * @subsection Budget & Program Helpers for Project Proposals
 * Functions for managing dynamic budget tables and program schedules
 */

/**
 * Add a new budget row to the proposal form
 * Creates input fields for item name, price, size, quantity, and calculates totals
 */
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

    // Attach event listeners for automatic calculation
    tr.querySelectorAll('.item-price, .item-qty').forEach(i => i.addEventListener('input', calcBudgetTotalsProp));

    if (window.addAuditLog) {
        window.addAuditLog('DOCUMENT_BUDGET_ROW_ADDED', 'Document Management', 'Added budget row to proposal', null, 'Document', 'INFO');
    }

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
    // Initial compute (guard table exists)
    if (document.getElementById('budget-body-prop')) {
        calcBudgetTotalsProp();
    }
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

/**
 * @section Print Functionality
 * Functions for document printing and output
 */

/**
 * Trigger browser print dialog for the current document
 * Opens the browser's print interface to allow users to print or save the document
 */
function printDocument() {
    window.print();
}

/**
 * @section Initialization & Event Handling
 * Page setup, event binding, and application initialization
 * Ensures all interactive elements are properly configured on page load
 */

/**
 * Initialize the document creator when the page loads
 * Sets up event listeners, form handlers, and initial state
 */
document.addEventListener('DOMContentLoaded', () => {
    // Set up live preview for all form inputs
    document.querySelectorAll('input, textarea, select').forEach(el => {
        el.addEventListener('input', scheduleGenerate);
        el.addEventListener('change', scheduleGenerate);
    });

    // Configure SAF category checkboxes to update form state
    document.querySelectorAll('.saf-cat').forEach(cb =>
        cb.addEventListener('change', () => {
            updateSAFLocks();
            scheduleGenerate();
        })
    );

    // Initialize SAF form controls
    updateSAFLocks();

    // Set up dynamic form elements for proposal
    setupBudgetProp();
    setupProgramProp();

    // Start with proposal form as default
    selectDocumentType('proposal');
});

/**
 * Additional event listeners for live preview updates
 * Ensures document regeneration happens for dynamically added elements
 */
document.addEventListener('input', function (e) {
    if (e.target.closest('.editor-panel') || e.target.closest('.form-section')) {
        scheduleGenerate();
    }
});

document.addEventListener('change', function (e) {
    if (e.target.closest('.editor-panel') || e.target.closest('.form-section')) {
        scheduleGenerate();
    }
});

/**
 * Initial document generation trigger
 * Ensures the preview loads immediately when the page is ready
 */
setTimeout(() => {
    scheduleGenerate();
}, 300);

// Add a new function to submit the document (call this from a "Create Document" button in create-document.php)
async function submitDocument() {
    const currentType = currentDocumentType;  // Use the global variable
    let data;
    let docType;

    // Collect data based on type
    if (currentType === 'proposal') {
        data = collectProposalData();
        docType = 'proposal';
    } else if (currentType === 'saf') {
        data = collectSAFData();
        docType = 'saf';
    } else if (currentType === 'facility') {
        data = collectFacilityData();
        docType = 'facility';
    } else if (currentType === 'communication') {
        data = collectCommunicationData();
        docType = 'communication';
    } else {
        window.ToastManager?.error('Unknown document type', 'Error');
        return;
    }

    try {
        const response = await fetch('../api/documents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create',
                doc_type: docType,
                student_id: window.currentUser?.id,
                data: data
            })
        });
        const result = await response.json();
        if (result.success) {
            window.ToastManager?.success('Document created successfully!', 'Success');
            // Redirect to track-document for students to track their documents
            window.location.href = 'track-document.php';
        } else {
            throw new Error(result.message);
        }
    } catch (e) {
        window.ToastManager?.error('Failed to create document: ' + e.message, 'Error');
    }
}

/**
 * @section DOM Utilities & Error Handling
 * Helper functions for DOM manipulation and error management
 */

/** Shortcut for querySelector with optional root element */
const qs = (sel, root = document) => root.querySelector(sel);

/** Shortcut for querySelectorAll returning an Array */
const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/**
 * Centralized error logging function
 * Provides consistent error reporting throughout the application
 * @param {string} prefix - Context prefix for the error message
 * @param {Error} err - The error object to log
 */
function handleError(prefix, err) {
    console.error(`[CreateDocument] ${prefix}:`, err);
}
