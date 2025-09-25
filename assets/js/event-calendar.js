// Event Calendar JavaScript
// High-level: Renders a month view calendar with events; employees/admins can CRUD, students view-only.
// Notes for future developers:
// - Keep global functions used by HTML intact (openChangePassword, logout, showNotifications).
// - Storage is localStorage-only for now (calendarEvents); swap to API-backed data later.
// - Guard DOM access; the calendar can render even if some elements are absent.
// - Avoid changing DOM IDs/classes expected by the HTML templates.
/**
 * Calendar Application integrated with University Event Management System
 * Employees can CRUD events, Students can only view
 */

// Global variables
let currentDate = new Date();
let today = new Date();
// Events cache (keyed by YYYY-MM-DD); filled from API
let events = {};
let editingEventId = null;
let currentUser = null; // Declare currentUser globally

// Audit log system
let auditLog = JSON.parse(localStorage.getItem('auditLog')) || [];

// Function to add audit log entry
function addAuditLog(action, category, details, targetId = null, targetType = null, severity = 'INFO') {
    if (window.addAuditLog) {
        window.addAuditLog(action, category, details, targetId, targetType, severity);
    } else {
        // Fallback to API directly
        fetch('../api/audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action,
                category,
                details,
                target_id: targetId,
                target_type: targetType,
                severity
            })
        }).catch(e => console.error('Audit error:', e));
    }
}

class CalendarApp {
    constructor() {
        console.log('CalendarApp constructor called');
        console.log('Current user:', currentUser);
        
        this.currentDate = currentDate;
        this.selectedDate = null;
    this.events = events;
    // Fixed palette for department colors (Bootstrap backgrounds)
    this.COLOR_CLASSES = ['bg-primary','bg-success','bg-danger','bg-warning','bg-info','bg-secondary','bg-dark'];
        
        console.log('Initializing calendar...');
        this.init();
    this.loadEvents();
    }
    
    init() {
        console.log('CalendarApp.init() called');
        console.log('DOM elements check:');
        console.log('- currentMonth:', document.getElementById('currentMonth'));
        console.log('- calendarDays:', document.getElementById('calendarDays'));
        console.log('- userDisplayName:', document.getElementById('userDisplayName'));
        
        this.setupUIBasedOnRole();
        this.bindEvents();
        this.generateCalendar();
        this.updateEventStatistics();
        
        console.log('CalendarApp.init() completed');
    }
    
    setupUIBasedOnRole() {
        const studentInfoCompact = document.getElementById('studentInfoCompact');
        const employeeInfoCompact = document.getElementById('employeeInfoCompact');
        const addEventBtn = document.getElementById('addEventBtn');
        
        // Hide all role-specific sections first
        if (studentInfoCompact) studentInfoCompact.style.display = 'none';
        if (employeeInfoCompact) employeeInfoCompact.style.display = 'none';
        
        if (currentUser.role === 'student') {
            // Show student info section for students
            if (studentInfoCompact) studentInfoCompact.style.display = 'flex';
            if (addEventBtn) addEventBtn.style.display = 'none';
        } else if (currentUser.role === 'employee') {
            // Show employee info section for employees
            if (employeeInfoCompact) employeeInfoCompact.style.display = 'flex';
            if (addEventBtn) addEventBtn.style.display = 'inline-flex';
        } else if (currentUser.role === 'admin') {
            // Admins don't show either section but can add events
            if (addEventBtn) addEventBtn.style.display = 'inline-flex';
        }
        
        this.updateUserInfo();
    }
    
    updateUserInfo() {
        const userDisplayName = document.getElementById('userDisplayName');
        const userRoleBadge = document.getElementById('userRoleBadge');
        
        if (userDisplayName) {
            userDisplayName.textContent = `${currentUser.firstName} ${currentUser.lastName}`;
        }
        
        if (userRoleBadge) {
            userRoleBadge.textContent = currentUser.role.toUpperCase();
            userRoleBadge.className = `badge ms-2 ${this.getRoleBadgeClass(currentUser.role)}`;
        }
    }
    
    getRoleBadgeClass(role) {
        const classes = {
            'admin': 'bg-danger',
            'employee': 'bg-primary', 
            'student': 'bg-success'
        };
        return classes[role] || 'bg-secondary';
    }
    
    bindEvents() {
        // Navigation controls
        document.getElementById('prevMonth')?.addEventListener('click', () => this.previousMonth());
        document.getElementById('nextMonth')?.addEventListener('click', () => this.nextMonth());
        document.getElementById('todayBtn')?.addEventListener('click', () => this.goToToday());
        
        // View controls
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const view = (e.currentTarget || e.target).dataset.view;
                this.switchView(view);
            });
        });
        
        // Event management (employees and admins only)
        if (currentUser.role === 'employee' || currentUser.role === 'admin') {
            document.getElementById('addEventBtn')?.addEventListener('click', () => this.openEventModal());
            document.getElementById('saveEventBtn')?.addEventListener('click', () => this.saveEvent());
            document.getElementById('deleteBtn')?.addEventListener('click', () => this.deleteEvent());
        }
        
        // Event form submission
        document.getElementById('eventForm')?.addEventListener('submit', (e) => this.handleEventFormSubmit(e));

        // Populate departments (units) into the eventDepartment select
        this.populateDepartments();
    }
    
    async loadEvents() {
        try {
            const resp = await fetch('../api/events.php');
            const data = await resp.json();
            if (data && data.success) {
                // Normalize into map keyed by date
                const map = {};
                (data.events || []).forEach(ev => {
                    const date = ev.event_date;
                    if (!map[date]) map[date] = [];
                    map[date].push({
                        id: String(ev.id),
                        title: ev.title,
                        time: ev.event_time || '',
                        description: ev.description || '',
                        department: ev.department || '',
                        color: this.getDepartmentColor(ev.department || '')
                    });
                });
                this.events = map;
                events = map;
                this.generateCalendar();
                this.updateEventStatistics();
            } else {
                console.error('Failed to load events', data);
            }
        } catch (e) {
            console.error('Error loading events', e);
        }
    }

    async populateDepartments() {
        try {
            const select = document.getElementById('eventDepartment');
            if (!select) {
                // Still render legend if available
                const resp = await fetch('../api/units.php');
                const data = await resp.json();
                if (data && data.success) this.renderDepartmentLegend(data.units || []);
                return;
            }
            // Keep existing placeholder option, then append from API
            const resp = await fetch('../api/units.php');
            const data = await resp.json();
            if (data && data.success) {
                // Clear all except first placeholder
                const first = select.querySelector('option');
                select.innerHTML = '';
                if (first && first.value === '') {
                    select.appendChild(first);
                } else {
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = 'Select Department';
                    select.appendChild(placeholder);
                }
                (data.units || []).forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u.name; // UI still uses name; API maps to unit_id on save
                    opt.textContent = u.name;
                    select.appendChild(opt);
                });
                // Also render legend if a container exists
                this.renderDepartmentLegend(data.units || []);
            }
        } catch (e) {
            console.error('Failed to load units', e);
        }
    }

    renderDepartmentLegend(units) {
        const legend = document.getElementById('departmentLegend');
        if (!legend) return; // optional UI element
        legend.innerHTML = '';

        // Group by type for clarity
        const byType = { office: [], college: [] };
        units.forEach(u => {
            if (!u) return;
            if (u.type === 'office') byType.office.push(u);
            else if (u.type === 'college') byType.college.push(u);
        });

        const renderGroup = (title, list) => {
            if (!list.length) return;
            const header = document.createElement('div');
            header.className = 'legend-group-title fw-semibold mt-2 mb-1';
            header.textContent = title;
            legend.appendChild(header);

            const wrap = document.createElement('div');
            wrap.className = 'd-flex flex-wrap gap-2';
            list.forEach(u => {
                const bg = this.getDepartmentColor(u.name);
                const text = this.getTextColorForBg(bg);
                const badge = document.createElement('span');
                badge.className = `badge ${bg} ${text}`;
                badge.textContent = u.name;
                wrap.appendChild(badge);
            });
            legend.appendChild(wrap);
        };

        renderGroup('Offices', byType.office);
        renderGroup('Colleges', byType.college);
    }
    
    getSampleEvents() {
        const today = new Date();
        const currentMonth = today.getMonth();
        const currentYear = today.getFullYear();
        
        const sampleEvents = {};
        
        // Sample events
        sampleEvents[`${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-15`] = [{
            id: '1',
            title: 'Engineering Symposium',
            time: '09:00',
            description: 'Annual engineering symposium featuring latest research and innovations in the field.',
            department: 'College of Engineering and Technology',
            color: 'bg-warning'
        }];

        sampleEvents[`${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-22`] = [{
            id: '2',
            title: 'Nursing Skills Competition',
            time: '14:00',
            description: 'Inter-college nursing skills competition showcasing clinical expertise.',
            department: 'College of Health Sciences and Nursing',
            color: 'bg-info'
        }];

        sampleEvents[`${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-28`] = [{
            id: '3',
            title: 'Business Plan Presentation',
            time: '10:30',
            description: 'Final presentations for the entrepreneurship course business plans.',
            department: 'College of Business and Accountancy',
            color: 'bg-success'
        }];
        
        return sampleEvents;
    }
    
    // Color utilities and mapping
    hashToColorIndex(name) {
        let h = 0;
        for (let i = 0; i < (name || '').length; i++) {
            h = (h * 31 + name.charCodeAt(i)) >>> 0;
        }
        return h % this.COLOR_CLASSES.length;
    }

    getTextColorForBg(bgClass) {
        const darkBg = new Set(['bg-primary','bg-success','bg-danger','bg-dark']);
        return darkBg.has(bgClass) ? 'text-white' : 'text-dark';
    }

    // Department color mapping function
    getDepartmentColor(department) {
        // Map known unit names (aligned with schema.sql seed data) to bootstrap color classes
        const colorMap = {
            // Offices
            'Administration Office': 'bg-secondary',
            'Academic Affairs': 'bg-info',
            'Student Affairs': 'bg-success',
            'Finance Office': 'bg-warning',
            'HR Department': 'bg-primary',
            'IT Department': 'bg-dark',
            'Library': 'bg-secondary',
            'Registrar': 'bg-info',
            // Colleges (exact names from schema.sql)
            'College of Engineering': 'bg-warning',
            'College of Nursing': 'bg-info',
            'College of Business': 'bg-success',
            'College of Criminology': 'bg-dark',
            'College of Computing and Information Sciences': 'bg-danger',
            'College of Art and Social Sciences and Education': 'bg-primary',
            'College of Hospitality and Tourism Management': 'bg-secondary',
            // Backward-compat keys from older placeholder data
            'College of Arts, Sciences and Education': 'bg-primary',
            'College of Business and Accountancy': 'bg-success',
            'College of Computer Science and Information Systems': 'bg-danger',
            'College of Engineering and Technology': 'bg-warning',
            'College of Health Sciences and Nursing': 'bg-info',
            'College of Law and Criminology': 'bg-dark',
            'College of Tourism, Hospitality Management and Transportation': 'bg-secondary'
        };
        if (department && colorMap[department]) return colorMap[department];
        if (department) return this.COLOR_CLASSES[this.hashToColorIndex(department)];
        return 'bg-primary';
    }
    
    generateCalendar() {
        console.log('generateCalendar called');
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();

        // Update month display
        const monthNames = ["January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"];
        const monthEl = document.getElementById('currentMonth');
        if (monthEl) monthEl.textContent = `${monthNames[month]} ${year}`;
        console.log('Month display updated to:', `${monthNames[month]} ${year}`);

        // Get first day of month and number of days
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        const calendarDays = document.getElementById('calendarDays');
        if (!calendarDays) {
            console.error('calendarDays element not found!');
            return;
        }
        
        console.log('Generating calendar for', daysInMonth, 'days, first day:', firstDay);
        calendarDays.innerHTML = '';

        let dayCount = 0;
        const isStudent = currentUser && currentUser.role === 'student';

        // Generate 6 weeks of calendar
        for (let week = 0; week < 6; week++) {
            for (let day = 0; day < 7; day++) {
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day';

                let dayNumber;
                let isCurrentMonth = true;
                let cellDate;

                if (week === 0 && day < firstDay) {
                    // Previous month days
                    dayNumber = daysInPrevMonth - firstDay + day + 1;
                    isCurrentMonth = false;
                    cellDate = new Date(year, month - 1, dayNumber);
                } else if (dayCount >= daysInMonth) {
                    // Next month days
                    dayNumber = dayCount - daysInMonth + 1;
                    isCurrentMonth = false;
                    cellDate = new Date(year, month + 1, dayNumber);
                    dayCount++;
                } else {
                    // Current month days
                    dayNumber = dayCount + 1;
                    cellDate = new Date(year, month, dayNumber);
                    dayCount++;
                }

                const dateStr = cellDate.toISOString().split('T')[0];
                dayElement.dataset.date = dateStr;

                // Add classes
                if (!isCurrentMonth) {
                    dayElement.classList.add('other-month');
                }

                if (this.isToday(cellDate)) {
                    dayElement.classList.add('today');
                }

                // Day number
                const dayNumberEl = document.createElement('div');
                dayNumberEl.className = 'day-number';
                dayNumberEl.textContent = dayNumber;
                dayElement.appendChild(dayNumberEl);

                // Events for this day
                const eventsContainer = document.createElement('div');
                eventsContainer.className = 'day-events';

                const dayEvents = this.events[dateStr] || [];
                dayEvents.slice(0, 3).forEach(event => {
                    const eventElement = document.createElement('div');
                    const eventColor = event.department ? this.getDepartmentColor(event.department) : (event.color || 'bg-primary');
                    eventElement.className = `event-item ${eventColor}`;
                    eventElement.dataset.eventId = event.id;
                    eventElement.dataset.date = dateStr;
                    eventElement.textContent = event.title;
                    // Tooltip shows time and department when available
                    const timeStr = event.time ? `${event.time} - ` : '';
                    const deptStr = event.department ? ` (${event.department})` : '';
                    eventElement.title = `${timeStr}${event.title}${deptStr}`;
                    
                    // Add click handler
                    eventElement.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.handleEventClick(event.id, dateStr);
                    });
                    
                    eventsContainer.appendChild(eventElement);
                });

                if (dayEvents.length > 3) {
                    const moreElement = document.createElement('div');
                    moreElement.className = 'event-item bg-secondary';
                    moreElement.textContent = `+${dayEvents.length - 3} more`;
                    eventsContainer.appendChild(moreElement);
                }

                dayElement.appendChild(eventsContainer);

                // Add day click handler for employees/admins
                if (!isStudent) {
                    dayElement.addEventListener('click', () => {
                        this.selectedDate = cellDate;
                        this.openEventModal(dateStr);
                    });
                    dayElement.style.cursor = 'pointer';
                }

                calendarDays.appendChild(dayElement);
            }
        }

        console.log('Calendar generation completed');
        this.updateEventStatistics();
    }
    
    updateEventStatistics() {
        const totalEvents = Object.values(this.events).reduce((total, dayEvents) => total + dayEvents.length, 0);
        const currentMonth = this.currentDate.getMonth();
        const currentYear = this.currentDate.getFullYear();

        let thisMonthEvents = 0;
        let upcomingEvents = 0;
        let todayEvents = 0;
        
        const todayStr = today.toISOString().split('T')[0];

        Object.keys(this.events).forEach(dateStr => {
            const eventDate = new Date(dateStr);
            if (eventDate.getMonth() === currentMonth && eventDate.getFullYear() === currentYear) {
                thisMonthEvents += this.events[dateStr].length;
            }
            if (eventDate >= today) {
                upcomingEvents += this.events[dateStr].length;
            }
            if (dateStr === todayStr) {
                todayEvents += this.events[dateStr].length;
            }
        });

    const totalEl = document.getElementById('totalEvents');
    const upcomingEl = document.getElementById('upcomingEvents');
    const todayEl = document.getElementById('todayEvents');
    if (totalEl) totalEl.textContent = thisMonthEvents;
    if (upcomingEl) upcomingEl.textContent = upcomingEvents;
    if (todayEl) todayEl.textContent = todayEvents;
        
        // Update notification badge
        const notificationBadge = document.getElementById('notificationCount');
        if (notificationBadge) {
            notificationBadge.textContent = upcomingEvents;
            notificationBadge.style.display = upcomingEvents > 0 ? 'flex' : 'none';
        }
    }
    
    previousMonth() {
        this.currentDate.setMonth(this.currentDate.getMonth() - 1);
        this.generateCalendar();
    }

    nextMonth() {
        this.currentDate.setMonth(this.currentDate.getMonth() + 1);
        this.generateCalendar();
    }

    goToToday() {
        this.currentDate = new Date();
        this.generateCalendar();
    }
    
    switchView(view) {
        // Update active button
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });
        
        // Show/hide view containers
        document.querySelectorAll('.calendar-view').forEach(container => {
            container.classList.toggle('active', container.id === `${view}View`);
        });
        
        if (view === 'month') {
            this.generateCalendar();
        } else if (view === 'list') {
            this.renderListView();
        }
    }
    
    renderListView() {
        const listContainer = document.getElementById('eventsList');
        if (!listContainer) return;
        
        listContainer.innerHTML = '';
        
        // Get all events and sort by date
        const allEvents = [];
        Object.keys(this.events).forEach(dateStr => {
            this.events[dateStr].forEach(event => {
                allEvents.push({...event, date: dateStr});
            });
        });
        
        allEvents.sort((a, b) => new Date(a.date) - new Date(b.date));
        
        allEvents.forEach(event => {
            const eventElement = this.createListEventElement(event);
            listContainer.appendChild(eventElement);
        });
        
        if (allEvents.length === 0) {
            listContainer.innerHTML = '<div class="text-center text-muted p-4">No events found</div>';
        }
    }
    
    createListEventElement(event) {
        const eventDiv = document.createElement('div');
        eventDiv.className = 'event-list-item';
        eventDiv.dataset.eventId = event.id;
        eventDiv.dataset.date = event.date;
        
        const eventDate = new Date(event.date);
        const formattedDate = eventDate.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        
        const deptBg = this.getDepartmentColor(event.department || '');
        const deptText = this.getTextColorForBg(deptBg);
        eventDiv.innerHTML = `
            <div class="event-list-header">
                <h5 class="event-list-title">${event.title}</h5>
                <span class="event-list-date">${formattedDate} at ${event.time}</span>
            </div>
            <p class="event-list-description">${event.description}</p>
            <div class="event-list-meta">
                <span><i class="bi bi-tag"></i> Event</span>
                <span class="ms-2"><i class="bi bi-building"></i></span>
                <span class="badge ${deptBg} ${deptText}">${event.department || 'University'}</span>
            </div>
        `;
        
        eventDiv.addEventListener('click', () => {
            this.handleEventClick(event.id, event.date);
        });
        
        return eventDiv;
    }
    
    handleEventClick(eventId, dateStr) {
        if (currentUser.role === 'student') {
            this.viewEventDetails(eventId, dateStr);
        } else {
            this.editEvent(eventId, dateStr);
        }
    }
    
    openEventModal(selectedDate = null) {
        if (currentUser && currentUser.role === 'student') {
            return;
        }

        const form = document.getElementById('eventForm');
        const deleteBtn = document.getElementById('deleteBtn');
        const modal = new bootstrap.Modal(document.getElementById('eventModal'));

        form.reset();
        editingEventId = null;
        deleteBtn.style.display = 'none';
        document.getElementById('eventModalLabel').textContent = 'Add New Event';

        if (selectedDate) {
            document.getElementById('eventDate').value = selectedDate;
        } else {
            document.getElementById('eventDate').value = new Date().toISOString().split('T')[0];
        }

        modal.show();
    }
    
    editEvent(eventId, dateStr) {
        if (currentUser && currentUser.role === 'student') {
            this.viewEventDetails(eventId, dateStr);
            return;
        }

        const event = this.events[dateStr]?.find(e => e.id === eventId);
        if (!event) return;

        editingEventId = eventId;
        document.getElementById('eventModalLabel').textContent = 'Edit Event';
        document.getElementById('eventTitle').value = event.title;
        document.getElementById('eventDate').value = dateStr;
        document.getElementById('eventTime').value = event.time || '';
        document.getElementById('eventDescription').value = event.description || '';
        // Try to set the department; if option list hasn't loaded yet, populate then set
        const deptSelect = document.getElementById('eventDepartment');
        if (deptSelect) {
            const setValue = () => {
                const val = event.department || '';
                if (val && Array.from(deptSelect.options).some(o => o.value === val)) {
                    deptSelect.value = val;
                } else {
                    // Leave placeholder selected if unknown; user must choose before saving
                    deptSelect.value = '';
                }
            };
            if (deptSelect.options.length <= 1) {
                // Likely not yet populated; populate then set
                this.populateDepartments().finally(setValue);
            } else {
                setValue();
            }
        }
        document.getElementById('deleteBtn').style.display = 'inline-block';

        const modal = new bootstrap.Modal(document.getElementById('eventModal'));
        modal.show();
    }
    
    viewEventDetails(eventId, dateStr) {
        const event = this.events[dateStr]?.find(e => e.id === eventId);
        if (!event) return;

        const eventDate = new Date(dateStr);
        const formattedDate = eventDate.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        document.getElementById('viewEventTitle').textContent = event.title;
        document.getElementById('viewEventDescription').textContent = event.description;
        document.getElementById('viewEventDate').textContent = formattedDate;
        document.getElementById('viewEventTime').textContent = event.time;
        document.getElementById('viewEventDepartment').textContent = event.department || 'University';

        const modal = new bootstrap.Modal(document.getElementById('viewEventModal'));
        modal.show();
    }
    
    handleEventFormSubmit(e) {
        e.preventDefault();
        this.saveEvent();
    }
    
    saveEvent() {
        if (currentUser && currentUser.role === 'student') {
            return;
        }

        const title = document.getElementById('eventTitle').value;
        const date = document.getElementById('eventDate').value;
        const time = document.getElementById('eventTime').value;
        const description = document.getElementById('eventDescription').value;
        const department = document.getElementById('eventDepartment').value;
        const color = this.getDepartmentColor(department);

        // Validate required fields
        if (!title || !date) {
            this.showToast('Please provide a title and date for the event.', 'warning');
            return;
        }
        if (!department) {
            this.showToast('Please select a department.', 'warning');
            return;
        }

        // Persist via API
        const payload = {
            title,
            description,
            event_date: date,
            event_time: time || null,
            department
        };

        const isUpdate = Boolean(editingEventId);
        const url = isUpdate ? `../api/events.php?id=${encodeURIComponent(editingEventId)}` : '../api/events.php';
        const method = isUpdate ? 'PUT' : 'POST';

        fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                addAuditLog(isUpdate ? 'EVENT_UPDATED' : 'EVENT_CREATED', 'Event Management', `${isUpdate ? 'Updated' : 'Created'} event: ${title}`, isUpdate ? editingEventId : res.id, 'Event');
                // Refresh list
                this.loadEvents();
                const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                modal?.hide();
                this.showToast(isUpdate ? 'Event updated successfully' : 'Event created successfully', 'success');
            } else {
                this.showToast(res.message || 'Failed to save event', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            this.showToast('Server error saving event', 'error');
        });
    }
    
    deleteEvent() {
        if (!editingEventId) return;

        const dateStr = document.getElementById('eventDate').value;
        const event = this.events[dateStr]?.find(e => e.id === editingEventId);
        
        if (!event) return;

        // Persist via API
        fetch(`../api/events.php?id=${encodeURIComponent(editingEventId)}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    addAuditLog('EVENT_DELETED', 'Event Management', `Deleted event: ${event.title}`, editingEventId, 'Event');
                    this.loadEvents();
                    const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                    modal?.hide();
                    this.showToast('Event deleted successfully', 'success');
                } else {
                    this.showToast(res.message || 'Delete failed', 'error');
                }
            })
            .catch(err => { console.error(err); this.showToast('Server error deleting event', 'error'); });
    }
    
    isToday(date) {
        return date.toDateString() === today.toDateString();
    }
    
    showToast(message, type = 'info') {
        if (window.ToastManager) {
            window.ToastManager.show({
                type: type,
                message: message,
                duration: 3000
            });
        } else {
            // Fallback
            alert(message);
        }
    }
}

function openChangePassword() {
    const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    modal.show();
}

// Global password visibility toggle for inline onclick usage in the page
function togglePasswordVisibility(fieldId) {
    try {
        const field = document.getElementById(fieldId);
        const icon = document.getElementById(fieldId + 'Icon');
        if (!field) return;

        if (field.type === 'password') {
            field.type = 'text';
            if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
        } else {
            field.type = 'password';
            if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
        }
    } catch (_) {
        // no-op: keep UI resilient even if elements are missing
    }
}

// Handle change password form
document.getElementById('changePasswordForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const currentPassword = document.getElementById('currentPassword')?.value || '';
    const newPassword = document.getElementById('newPassword')?.value || '';
    const confirmPassword = document.getElementById('confirmPassword')?.value || '';
    const messagesDiv = document.getElementById('changePasswordMessages');

    const show = (html) => { if (messagesDiv) messagesDiv.innerHTML = html; };
    const ok = (msg) => `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) => `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    if (!currentPassword || !newPassword || !confirmPassword) { show(err('All fields are required.')); return; }
    if (newPassword !== confirmPassword) { show(err('New passwords do not match.')); return; }
    const policy = /^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/;
    if (!policy.test(newPassword)) { show(err('Password must be 8+ chars with upper, lower, number, special.')); return; }

    try {
        const resp = await fetch('../api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_password', current_password: currentPassword, new_password: newPassword })
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('PASSWORD_CHANGED', 'Security', 'Password changed', currentUser?.id || null, 'User', 'INFO');
            // Clear must_change_password flag on the client if present
            if (window.currentUser) {
                window.currentUser.must_change_password = 0;
            }
            show(ok('Password changed successfully!'));
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
                if (modal) modal.hide();
                if (messagesDiv) messagesDiv.innerHTML = '';
                document.getElementById('changePasswordForm')?.reset();
            }, 1500);
        } else {
            show(err(resp.message || 'Failed to change password.'));
        }
    } catch (e) {
        show(err('Server error changing password.'));
    }
});

// Utility functions from original script.js
function logout() {
    if (currentUser) {
        addAuditLog('LOGOUT', 'Authentication', `User logged out: ${currentUser.firstName} ${currentUser.lastName}`);
    }

    localStorage.removeItem('currentUser');
    currentUser = null;
    
    window.location.href = 'user-logout.php';
}

function showNotifications() {
    const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
    
    // Simple notifications for now
    const notificationsList = document.getElementById('notificationsList');
    if (notificationsList) {
        notificationsList.innerHTML = `
            <div class="list-group">
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">Upcoming Events</h6>
                        <small>Just now</small>
                    </div>
                    <p class="mb-1">You have upcoming events to attend.</p>
                </div>
            </div>
        `;
    }
    
    modal.show();
}

// Initialize the calendar app when the page loads
document.addEventListener('DOMContentLoaded', function () {
    console.log('DOM loaded, checking for user data...');
    console.log('window.currentUser:', window.currentUser);
    
    // Use the user data passed from PHP
    if (window.currentUser) {
        currentUser = window.currentUser;
        console.log('User data found:', currentUser);
        console.log('Initializing CalendarApp...');
        new CalendarApp();
    } else {
        console.log('No user data found, redirecting to login...');
        // If no user data, redirect to login
        window.location.href = 'user-login.php';
    }
});
