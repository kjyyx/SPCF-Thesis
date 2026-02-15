// Event Calendar JavaScript
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
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

// Audit log system (now handled globally)

class CalendarApp {
    constructor() {
        console.log('CalendarApp constructor called');
        console.log('Current user:', currentUser);
        
        this.currentDate = currentDate;
        this.selectedDate = null;
    this.events = events;
    // Fixed palette for department colors (Bootstrap backgrounds)
    this.COLOR_CLASSES = ['bg-primary','bg-success','bg-danger','bg-warning','bg-info','bg-secondary','bg-dark'];
        
        this.COLOR_MAP_RGB = {
            'College of Arts and Social Sciences and Education': 'rgb(59, 130, 246)', // Blue
            'College of Computing and Information Sciences': 'rgb(30, 41, 59)', // Blue and Black
            'College of Hospitality and Tourism Management': 'rgb(236, 72, 153)', // Pink
            'College of Business': 'rgb(245, 158, 11)', // Yellow
            'College of Criminology': 'rgb(107, 114, 128)', // Gray
            'College of Engineering': 'rgb(239, 68, 68)', // Red
            'College of Nursing': 'rgb(34, 197, 94)', // Green
            'SPCF Miranda': 'rgb(127, 29, 29)', // Maroon
            'Supreme Student Council': 'rgb(249, 115, 22)' // Orange
        };
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
        const approvalsBtn = document.getElementById('approvalsBtn'); // Add Approvals button reference

        // Hide all role-specific sections first
        if (studentInfoCompact) studentInfoCompact.style.display = 'none';
        if (employeeInfoCompact) employeeInfoCompact.style.display = 'none';
        if (approvalsBtn) approvalsBtn.style.display = 'none'; // Hide by default

        if (currentUser.role === 'student') {
            // Show student info section for all students
            if (studentInfoCompact) studentInfoCompact.style.display = 'flex';
            // Show Approvals button only for SSC President
            if (approvalsBtn && currentUser.position === 'Supreme Student Council President') {
                approvalsBtn.style.display = 'inline-flex';
            } else if (approvalsBtn) {
                approvalsBtn.style.display = 'none';
            }
        } else if (currentUser.role === 'employee') {
            // Show employee info section for employees
            if (employeeInfoCompact) employeeInfoCompact.style.display = 'flex';
            // Only PPFO and EVP can add events
            if (addEventBtn && (currentUser.position === 'Physical Plant and Facilities Office (PPFO)' || currentUser.position === 'Executive Vice-President/Student Services (EVP)')) {
                addEventBtn.style.display = 'inline-flex';
            }
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
        
        // Event management (only for PPFO, EVP, and admins)
        if (currentUser.role === 'admin' || (currentUser.role === 'employee' && (currentUser.position === 'Physical Plant and Facilities Office (PPFO)' || currentUser.position === 'Executive Vice-President/Student Services (EVP)'))) {
            document.getElementById('addEventBtn')?.addEventListener('click', () => this.openEventModal());
            document.getElementById('saveEventBtn')?.addEventListener('click', () => this.saveEvent());
            document.getElementById('deleteBtn')?.addEventListener('click', () => this.deleteEvent());
            document.getElementById('approveBtn')?.addEventListener('click', () => this.approveEvent());
            document.getElementById('disapproveBtn')?.addEventListener('click', () => this.disapproveEvent());
        }
        
        // Search and filter controls
        document.getElementById('eventSearch')?.addEventListener('input', () => this.applyFilters());
        document.getElementById('departmentFilter')?.addEventListener('change', () => this.applyFilters());
        document.getElementById('statusFilter')?.addEventListener('change', () => this.applyFilters());
        
        // Export button
        document.getElementById('exportEventsBtn')?.addEventListener('click', () => this.exportEvents());
        
        // Keyboard navigation
        document.addEventListener('keydown', (e) => this.handleKeyboardNavigation(e));
        
        // Event form submission
        document.getElementById('eventForm')?.addEventListener('submit', (e) => this.handleEventFormSubmit(e));

        // Populate departments (units) into the eventDepartment select
        this.populateDepartments();
    }
    
    applyFilters() {
        const searchTerm = document.getElementById('eventSearch')?.value.toLowerCase() || '';
        const departmentFilter = document.getElementById('departmentFilter')?.value || '';
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        
        // Get current view and re-render with filters
        const activeView = document.querySelector('.view-btn.active')?.dataset.view || 'month';
        this.switchView(activeView);
    }
    
    getFilteredEvents() {
        const searchTerm = document.getElementById('eventSearch')?.value.toLowerCase() || '';
        const departmentFilter = document.getElementById('departmentFilter')?.value || '';
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        
        const filteredEvents = {};
        
        Object.keys(this.events).forEach(dateStr => {
            const dayEvents = this.events[dateStr].filter(event => {
                // Search filter
                const matchesSearch = !searchTerm || 
                    event.title.toLowerCase().includes(searchTerm) ||
                    event.department.toLowerCase().includes(searchTerm);
                
                // Department filter
                const matchesDepartment = !departmentFilter || event.department === departmentFilter;
                
                // Status filter
                const matchesStatus = !statusFilter || 
                    (statusFilter === 'approved' && event.isApproved) ||
                    (statusFilter === 'pending' && !event.isApproved);
                
                return matchesSearch && matchesDepartment && matchesStatus;
            });
            
            if (dayEvents.length > 0) {
                filteredEvents[dateStr] = dayEvents;
            }
        });
        
        return filteredEvents;
    }
    
    exportEvents() {
        const filteredEvents = this.getFilteredEvents();
        const events = [];
        
        Object.keys(filteredEvents).forEach(dateStr => {
            filteredEvents[dateStr].forEach(event => {
                events.push({
                    date: dateStr,
                    time: event.time,
                    title: event.title,
                    department: event.department,
                    status: event.isApproved ? 'Approved' : 'Pending'
                });
            });
        });
        
        if (events.length === 0) {
            this.showToast('No events to export', 'warning');
            return;
        }
        
        // Create CSV content
        const headers = ['Date', 'Time', 'Title', 'Department', 'Status'];
        const csvContent = [
            headers.join(','),
            ...events.map(event => [
                event.date,
                event.time,
                `"${event.title.replace(/"/g, '""')}"`,
                `"${event.department || ''}"`,
                event.status
            ].join(','))
        ].join('\n');
        
        // Download CSV
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `events_${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        this.showToast(`Exported ${events.length} events to CSV`, 'success');
    }
    
    handleKeyboardNavigation(e) {
        // Only handle keyboard navigation when not in form inputs
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }
        
        switch (e.key) {
            case 'ArrowLeft':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.previousMonth();
                }
                break;
            case 'ArrowRight':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.nextMonth();
                }
                break;
            case 'Home':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.goToToday();
                }
                break;
            case 'm':
            case 'M':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.switchView('month');
                }
                break;
            case 'w':
            case 'W':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.switchView('week');
                }
                break;
            case 'l':
            case 'L':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.switchView('list');
                }
                break;
            case 'a':
            case 'A':
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    this.switchView('agenda');
                }
                break;
        }
    }
    
    async loadEvents() {
        const loadingEl = document.getElementById('calendarLoading');
        if (loadingEl) loadingEl.style.display = 'block';
        
        try {
            const resp = await fetch(BASE_URL + 'api/events.php');
            const data = await resp.json();
            if (data && data.success) {
                // Normalize into map keyed by date
                const map = {};
                (data.events || []).forEach(ev => {
                    const date = ev.event_date;
                    if (!map[date]) map[date] = [];
                    const isApproved = ev.approved == 1;
                    const isPencilBooked = !isApproved;
                    map[date].push({
                        id: String(ev.id),
                        title: ev.title,
                        time: ev.event_time || '',
                        department: ev.department || '',
                        venue: ev.venue || '',
                        isApproved: isApproved,
                        isPencilBooked: isPencilBooked,
                        color: isApproved ? this.getDepartmentColorRGB(ev.department || '') : 'rgb(148, 163, 184)',
textColor: isApproved ? this.getTextColorForRGB(this.getDepartmentColorRGB(ev.department || '')) : 'white'
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
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    async populateDepartments() {
        const units = [
            { name: 'College of Arts and Social Sciences and Education' },
            { name: 'College of Computing and Information Sciences' },
            { name: 'College of Hospitality and Tourism Management' },
            { name: 'College of Business' },
            { name: 'College of Criminology' },
            { name: 'College of Engineering' },
            { name: 'College of Nursing' },
            { name: 'SPCF Miranda' },
            { name: 'Supreme Student Council' }
        ];

        const select = document.getElementById('eventDepartment');
        if (select) {
            select.innerHTML = '<option value="">Select Department</option>';
            units.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.name;
                opt.textContent = u.name;
                select.appendChild(opt);
            });
        }

        const filterSelect = document.getElementById('departmentFilter');
        if (filterSelect) {
            filterSelect.innerHTML = '<option value="">All Departments</option>';
            units.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.name;
                opt.textContent = u.name;
                filterSelect.appendChild(opt);
            });
        }

        this.renderDepartmentLegend(units);
    }

    // Fetch approved documents from API and merge them into this.events keyed by date
    async _mergeApprovedEvents() {
        try {
            const resp = await fetch(BASE_URL + 'api/documents.php?action=approved_events');
            const data = await resp.json();
            if (!data || !data.success) return;
            const approved = data.events || [];
            approved.forEach(ev => {
                // Normalize date to YYYY-MM-DD
                let date = ev.event_date;
                try {
                    const d = new Date(date);
                    if (!isNaN(d.getTime())) {
                        date = d.toISOString().split('T')[0];
                    }
                } catch (e) {
                    // leave as-is
                }
                if (!this.events[date]) this.events[date] = [];
                // Avoid duplicates by title
                const exists = this.events[date].some(x => x.title === ev.title && x.department === ev.department);
                if (!exists) {
                    this.events[date].push({
                        id: String(ev.id),
                        title: ev.title,
                        time: '',
                        department: ev.department || '',
                        isApproved: true,
                        isPencilBooked: false,
                        color: this.getDepartmentColorRGB(ev.department || ''),
                        textColor: this.getTextColorForRGB(this.getDepartmentColorRGB(ev.department || ''))
                    });
                }
            });
            // Keep global cache in sync
            events = this.events;
        } catch (e) {
            console.error('Error fetching approved events', e);
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
                const bg = this.getDepartmentColorRGB(u.name);
                const text = this.getTextColorForRGB(bg);
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.style.backgroundColor = bg;
                badge.style.color = text;
                badge.textContent = u.name;
                wrap.appendChild(badge);
            });
            legend.appendChild(wrap);
        };

        renderGroup('Offices', byType.office);
        renderGroup('Colleges', byType.college);
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
        // Map known unit names to bootstrap color classes
        const colorMap = {
            'College of Arts and Social Sciences and Education': 'bg-primary',
            'College of Computing and Information Sciences': 'bg-secondary',
            'College of Hospitality and Tourism Management': 'bg-success',
            'College of Business': 'bg-warning',
            'College of Criminology': 'bg-info',
            'College of Engineering': 'bg-danger',
            'College of Nursing': 'bg-dark',
            'SPCF Miranda': 'bg-danger',
            'Supreme Student Council': 'bg-info'
        };
        if (department && colorMap[department]) return colorMap[department];
        if (department) return this.COLOR_CLASSES[this.hashToColorIndex(department)];
        return 'bg-primary';
    }
    
    getDepartmentColorRGB(department) {
        return this.COLOR_MAP_RGB[department] || 'rgb(59, 130, 246)';
    }
    
    getTextColorForRGB(rgb) {
        const match = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
        if (!match) return 'black';
        const r = parseInt(match[1]);
        const g = parseInt(match[2]);
        const b = parseInt(match[3]);
        const brightness = (r * 299 + g * 587 + b * 114) / 1000;
        return brightness > 128 ? 'black' : 'white';
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

                const filteredEvents = this.getFilteredEvents();
                const dayEvents = filteredEvents[dateStr] || [];
                dayEvents.slice(0, 3).forEach(event => {
                    const eventElement = document.createElement('div');
                    eventElement.className = 'event-item';
                    eventElement.style.backgroundColor = event.color;
                    eventElement.style.color = event.textColor;
                    eventElement.dataset.eventId = event.id;
                    eventElement.dataset.date = dateStr;
                    eventElement.textContent = event.title;
                    // Tooltip shows time and department when available
                    const timeStr = event.time ? `${event.time} - ` : '';
                    const deptStr = event.department ? ` (${event.department})` : '';
                    const statusStr = event.isPencilBooked ? ' [Pencil-booked]' : ' [Approved]';
                    eventElement.title = `${timeStr}${event.title}${deptStr}${statusStr}`;
                    
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
        } else if (view === 'week') {
            this.renderWeekView();
        } else if (view === 'agenda') {
            this.renderAgendaView();
        } else if (view === 'list') {
            this.renderListView();
        }
    }
    
    renderListView() {
        const listContainer = document.getElementById('eventsList');
        if (!listContainer) return;
        
        listContainer.innerHTML = '';
        
        // Get filtered events and sort by date
        const filteredEvents = this.getFilteredEvents();
        const allEvents = [];
        Object.keys(filteredEvents).forEach(dateStr => {
            filteredEvents[dateStr].forEach(event => {
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
    
    renderWeekView() {
        const weekDaysHeader = document.getElementById('weekDaysHeader');
        const weekBody = document.getElementById('weekBody');
        
        if (!weekDaysHeader || !weekBody) return;
        
        // Get the start of the current week (Sunday)
        const startOfWeek = new Date(this.currentDate);
        startOfWeek.setDate(this.currentDate.getDate() - this.currentDate.getDay());
        
        // Generate week days header
        weekDaysHeader.innerHTML = '';
        for (let i = 0; i < 7; i++) {
            const day = new Date(startOfWeek);
            day.setDate(startOfWeek.getDate() + i);
            
            const dayHeader = document.createElement('div');
            dayHeader.className = 'week-day-header';
            
            const dayName = day.toLocaleDateString('en-US', { weekday: 'short' });
            const dayNum = day.getDate();
            const isToday = this.isToday(day);
            
            dayHeader.innerHTML = `
                <div class="day-name">${dayName}</div>
                <div class="day-number ${isToday ? 'today' : ''}">${dayNum}</div>
            `;
            
            weekDaysHeader.appendChild(dayHeader);
        }
        
        // Generate time slots (8 AM to 8 PM)
        weekBody.innerHTML = '';
        const startHour = 8;
        const endHour = 20;
        
        for (let hour = startHour; hour <= endHour; hour++) {
            const timeSlot = document.createElement('div');
            timeSlot.className = 'time-slot';
            
            const timeLabel = document.createElement('div');
            timeLabel.className = 'time-label';
            timeLabel.textContent = `${hour > 12 ? hour - 12 : hour}${hour >= 12 ? 'PM' : 'AM'}`;
            
            const timeSlots = document.createElement('div');
            timeSlots.className = 'time-slots-grid';
            
            // Create slots for each day
            for (let day = 0; day < 7; day++) {
                const daySlot = document.createElement('div');
                daySlot.className = 'week-day-column';
                daySlot.dataset.hour = hour;
                daySlot.dataset.day = day;
                
                // Check for events in this time slot
                const slotDate = new Date(startOfWeek);
                slotDate.setDate(startOfWeek.getDate() + day);
                const dateStr = slotDate.toISOString().split('T')[0];
                
                const filteredEvents = this.getFilteredEvents();
                if (filteredEvents[dateStr]) {
                    filteredEvents[dateStr].forEach(event => {
                        const eventHour = parseInt(event.time.split(':')[0]);
                        if (eventHour === hour) {
                            const eventElement = document.createElement('div');
                            eventElement.className = 'week-event-item';
                            eventElement.style.backgroundColor = event.color;
                            eventElement.style.color = event.textColor;
                            eventElement.textContent = event.title;
                            eventElement.title = `${event.title} - ${event.time}`;
                            eventElement.addEventListener('click', () => {
                                this.handleEventClick(event.id, dateStr);
                            });
                            daySlot.appendChild(eventElement);
                        }
                    });
                }
                
                timeSlots.appendChild(daySlot);
            }
            
            timeSlot.appendChild(timeLabel);
            timeSlot.appendChild(timeSlots);
            weekBody.appendChild(timeSlot);
        }
    }
    
    renderAgendaView() {
        const agendaContainer = document.getElementById('agendaContainer');
        if (!agendaContainer) return;
        
        agendaContainer.innerHTML = '';
        
        // Get filtered events and group by date
        const filteredEvents = this.getFilteredEvents();
        const eventsByDate = {};
        Object.keys(filteredEvents).forEach(dateStr => {
            filteredEvents[dateStr].forEach(event => {
                if (!eventsByDate[dateStr]) {
                    eventsByDate[dateStr] = [];
                }
                eventsByDate[dateStr].push(event);
            });
        });
        
        // Sort dates
        const sortedDates = Object.keys(eventsByDate).sort();
        
        if (sortedDates.length === 0) {
            agendaContainer.innerHTML = '<div class="text-center text-muted p-4">No upcoming events</div>';
            return;
        }
        
        sortedDates.forEach(dateStr => {
            const dateEvents = eventsByDate[dateStr];
            const dateDiv = document.createElement('div');
            dateDiv.className = 'agenda-date-group';
            
            const dateHeader = document.createElement('div');
            dateHeader.className = 'agenda-date-header';
            
            const eventDate = new Date(dateStr);
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(today.getDate() + 1);
            
            let dateLabel = eventDate.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            if (eventDate.toDateString() === today.toDateString()) {
                dateLabel = 'Today - ' + dateLabel;
                dateHeader.classList.add('today');
            } else if (eventDate.toDateString() === tomorrow.toDateString()) {
                dateLabel = 'Tomorrow - ' + dateLabel;
            }
            
            dateHeader.innerHTML = `<h5>${dateLabel}</h5><span class="event-count">${dateEvents.length} event${dateEvents.length !== 1 ? 's' : ''}</span>`;
            
            const eventsList = document.createElement('div');
            eventsList.className = 'agenda-events-list';
            
            dateEvents.sort((a, b) => a.time.localeCompare(b.time)).forEach(event => {
                const eventElement = this.createAgendaEventElement(event, dateStr);
                eventsList.appendChild(eventElement);
            });
            
            dateDiv.appendChild(dateHeader);
            dateDiv.appendChild(eventsList);
            agendaContainer.appendChild(dateDiv);
        });
    }
    
    createAgendaEventElement(event, dateStr) {
        const eventDiv = document.createElement('div');
        eventDiv.className = 'agenda-event-item';
        eventDiv.dataset.eventId = event.id;
        eventDiv.dataset.date = dateStr;
        
        const deptBg = event.color;
        const deptText = event.textColor;
        
        eventDiv.innerHTML = `
            <div class="agenda-event-time">
                <i class="bi bi-clock"></i>
                <span>${event.time}</span>
            </div>
            <div class="agenda-event-content">
                <h6 class="agenda-event-title">${event.title}</h6>
                <div class="agenda-event-meta">
                    <span class="badge" style="background-color: ${deptBg}; color: ${deptText}">${event.department || 'University'}</span>
                    ${event.isApproved ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-warning">Pending</span>'}
                </div>
            </div>
        `;
        
        eventDiv.addEventListener('click', () => {
            this.handleEventClick(event.id, dateStr);
        });
        
        return eventDiv;
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
        
        const deptBg = event.color;
        const deptText = event.textColor;
        eventDiv.innerHTML = `
            <div class="event-list-header">
                <h5 class="event-list-title">${event.title}</h5>
                <span class="event-list-date">${formattedDate} at ${event.time}</span>
            </div>
            <div class="event-list-meta">
                <span><i class="bi bi-tag"></i> Event</span>
                <span class="ms-2"><i class="bi bi-building"></i></span>
                <span class="badge" style="background-color: ${deptBg}; color: ${deptText}">${event.department || 'University'}</span>
            </div>
        `;
        
        eventDiv.addEventListener('click', () => {
            this.handleEventClick(event.id, event.date);
        });
        
        return eventDiv;
    }
    
    handleEventClick(eventId, dateStr) {
        const canEdit = currentUser.role === 'admin' || (currentUser.role === 'employee' && (currentUser.position === 'Physical Plant and Facilities Office (PPFO)' || currentUser.position === 'Executive Vice-President/Student Services (EVP)'));
        if (currentUser.role === 'student' || !canEdit) {
            this.viewEventDetails(eventId, dateStr);
        } else {
            this.editEvent(eventId, dateStr);
        }
    }
    
    openEventModal(selectedDate = null) {
        const canEdit = currentUser.role === 'admin' || (currentUser.role === 'employee' && (currentUser.position === 'Physical Plant and Facilities Office (PPFO)' || currentUser.position === 'Executive Vice-President/Student Services (EVP)'));
        if (currentUser && (currentUser.role === 'student' || !canEdit)) {
            return;
        }

        const form = document.getElementById('eventForm');
        const deleteBtn = document.getElementById('deleteBtn');
        const modal = new bootstrap.Modal(document.getElementById('eventModal'));

        form.reset();
        editingEventId = null;
        deleteBtn.style.display = 'none';
        document.getElementById('approveBtn').style.display = 'none';
        document.getElementById('disapproveBtn').style.display = 'none';
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
        document.getElementById('eventVenue').value = event.venue || '';
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

        // Show approve/disapprove buttons for authorized users (PPFO, EVP, admins)
        const canApprove = currentUser.role === 'admin' || (currentUser.role === 'employee' && (currentUser.position === 'Physical Plant and Facilities Office (PPFO)' || currentUser.position === 'Executive Vice-President/Student Services (EVP)'));
        document.getElementById('approveBtn').style.display = canApprove ? 'inline-block' : 'none';
        document.getElementById('disapproveBtn').style.display = canApprove ? 'inline-block' : 'none';

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
        document.getElementById('viewEventVenue').textContent = event.venue || 'Not specified';
        document.getElementById('viewEventDate').textContent = formattedDate;
        document.getElementById('viewEventTime').textContent = event.time;
        document.getElementById('viewEventDepartment').textContent = event.department || 'University';
        document.getElementById('viewEventStatus').textContent = event.isApproved ? 'Approved Event' : 'Pencil-booked Event';

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
        const venue = document.getElementById('eventVenue').value;
        const department = document.getElementById('eventDepartment').value;

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
            venue,
            event_date: date,
            event_time: time || null,
            department
        };

        const isUpdate = Boolean(editingEventId);
        const url = BASE_URL + 'api/events.php' + (isUpdate ? `?id=${encodeURIComponent(editingEventId)}` : '');
        const method = isUpdate ? 'PUT' : 'POST';

        fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                window.addAuditLog(isUpdate ? 'EVENT_UPDATED' : 'EVENT_CREATED', 'Event Management', `${isUpdate ? 'Updated' : 'Created'} event: ${title}`, isUpdate ? editingEventId : res.id, 'Event');
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
        fetch(BASE_URL + `api/events.php?id=${encodeURIComponent(editingEventId)}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    window.addAuditLog('EVENT_DELETED', 'Event Management', `Deleted event: ${event.title}`, editingEventId, 'Event');
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
    
    approveEvent() {
        if (!editingEventId) return;

        fetch(BASE_URL + `api/events.php?id=${encodeURIComponent(editingEventId)}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'approve' })
        })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    window.addAuditLog('EVENT_APPROVED', 'Event Management', `Approved event ID: ${editingEventId}`, editingEventId, 'Event');
                    this.loadEvents();
                    const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                    modal?.hide();
                    this.showToast('Event approved successfully', 'success');
                } else {
                    this.showToast(res.message || 'Approval failed', 'error');
                }
            })
            .catch(err => { console.error(err); this.showToast('Server error approving event', 'error'); });
    }
    
    disapproveEvent() {
        if (!editingEventId) return;

        fetch(BASE_URL + `api/events.php?id=${encodeURIComponent(editingEventId)}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'disapprove' })
        })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    window.addAuditLog('EVENT_DISAPPROVED', 'Event Management', `Disapproved event ID: ${editingEventId}`, editingEventId, 'Event');
                    this.loadEvents();
                    const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                    modal?.hide();
                    this.showToast('Event disapproved successfully', 'success');
                } else {
                    this.showToast(res.message || 'Disapproval failed', 'error');
                }
            })
            .catch(err => { console.error(err); this.showToast('Server error disapproving event', 'error'); });
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

    openPubmatApprovals() {
        // Redirect to pubmat approvals interface (assuming a new page or modal)
        window.location.href = BASE_URL + '?page=pubmat-approvals';
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
        const resp = await fetch(BASE_URL + 'api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_password', current_password: currentPassword, new_password: newPassword })
        }).then(r => r.json());

        if (resp.success) {
            window.addAuditLog('PASSWORD_CHANGED', 'Security', 'Password changed', currentUser?.id || null, 'User', 'INFO');
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
        window.addAuditLog('LOGOUT', 'Authentication', `User logged out: ${currentUser.firstName} ${currentUser.lastName}`);
    }

    localStorage.removeItem('currentUser');
    currentUser = null;
    
    window.location.href = BASE_URL + '?page=logout';
}

// Profile Settings Functions
function openProfileSettings() {
    // Populate form with current user data
    if (currentUser) {
        document.getElementById('profileFirstName').value = currentUser.first_name || '';
        document.getElementById('profileLastName').value = currentUser.last_name || '';
        document.getElementById('profileEmail').value = currentUser.email || '';
        document.getElementById('profilePhone').value = currentUser.phone || '';
        if (currentUser.role === 'student') {
            document.getElementById('profilePosition').value = 'Student';
        }
        
        // Load theme preference from localStorage
        const darkMode = localStorage.getItem('darkMode') === 'true';
        document.getElementById('darkModeToggle').checked = darkMode;
    }
    
    const modal = new bootstrap.Modal(document.getElementById('profileSettingsModal'));
    modal.show();
}

function openPreferences() {
    // Load preferences from localStorage
    const emailNotifications = localStorage.getItem('emailNotifications') !== 'false'; // default true
    const browserNotifications = localStorage.getItem('browserNotifications') !== 'false'; // default true
    const defaultView = localStorage.getItem('defaultView') || 'month';
    const timezone = localStorage.getItem('timezone') || 'Asia/Manila';
    
    document.getElementById('emailNotifications').checked = emailNotifications;
    document.getElementById('browserNotifications').checked = browserNotifications;
    document.getElementById('defaultView').value = defaultView;
    document.getElementById('timezone').value = timezone;
    
    const modal = new bootstrap.Modal(document.getElementById('preferencesModal'));
    modal.show();
}

function showHelp() {
    const modal = new bootstrap.Modal(document.getElementById('helpModal'));
    modal.show();
}

function savePreferences() {
    const emailNotifications = document.getElementById('emailNotifications').checked;
    const browserNotifications = document.getElementById('browserNotifications').checked;
    const defaultView = document.getElementById('defaultView').value;
    const timezone = document.getElementById('timezone').value;
    
    // Save to localStorage
    localStorage.setItem('emailNotifications', emailNotifications);
    localStorage.setItem('browserNotifications', browserNotifications);
    localStorage.setItem('defaultView', defaultView);
    localStorage.setItem('timezone', timezone);
    
    // Show success message
    const messagesDiv = document.getElementById('preferencesMessages');
    if (messagesDiv) {
        messagesDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Preferences saved successfully!</div>';
        setTimeout(() => {
            messagesDiv.innerHTML = '';
            bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
        }, 2000);
    }
    
    // Apply theme if changed
    applyTheme();
}

// Handle profile settings form
document.getElementById('profileSettingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const firstName = document.getElementById('profileFirstName').value;
    const lastName = document.getElementById('profileLastName').value;
    const email = document.getElementById('profileEmail').value;
    const phone = document.getElementById('profilePhone').value;
    const darkMode = document.getElementById('darkModeToggle').checked;
    const messagesDiv = document.getElementById('profileSettingsMessages');

    const show = (html) => { if (messagesDiv) messagesDiv.innerHTML = html; };
    const ok = (msg) => `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) => `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    if (!firstName || !lastName || !email) { 
        show(err('First name, last name, and email are required.')); 
        return; 
    }

    try {
        const resp = await fetch(BASE_URL + 'api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'update_profile', 
                first_name: firstName, 
                last_name: lastName, 
                email: email, 
                phone: phone 
            })
        }).then(r => r.json());

        if (resp.success) {
            // Update local user data
            currentUser.first_name = firstName;
            currentUser.last_name = lastName;
            currentUser.email = email;
            currentUser.phone = phone;
            
            // Update display name in navbar
            const displayNameEl = document.getElementById('userDisplayName');
            if (displayNameEl) {
                displayNameEl.textContent = `${firstName} ${lastName}`;
            }
            
            // Save theme preference
            localStorage.setItem('darkMode', darkMode);
            applyTheme();
            
            window.addAuditLog('PROFILE_UPDATED', 'User Management', 'Profile information updated', currentUser.id, 'User', 'INFO');
            show(ok('Profile updated successfully!'));
            
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('profileSettingsModal')).hide();
                show('');
            }, 2000);
        } else {
            show(err(resp.message || 'Failed to update profile.'));
        }
    } catch (error) {
        console.error('Profile update error:', error);
        show(err('An error occurred while updating your profile.'));
    }
});

// Theme application function
function applyTheme() {
    const darkMode = localStorage.getItem('darkMode') === 'true';
    document.body.classList.toggle('dark-theme', darkMode);
    
    // Update toggle state if modal is open
    const toggle = document.getElementById('darkModeToggle');
    if (toggle) toggle.checked = darkMode;
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
        
        // Apply saved theme
        applyTheme();
    } else {
        console.log('No user data found, redirecting to login...');
        // If no user data, redirect to login
        window.location.href = BASE_URL + '?page=login';
    }
});
