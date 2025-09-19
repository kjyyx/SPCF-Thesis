/**
 * Calendar Application integrated with University Event Management System
 * Employees can CRUD events, Students can only view
 */

// Global variables
let currentDate = new Date();
let today = new Date();
let events = JSON.parse(localStorage.getItem('calendarEvents')) || {};
let editingEventId = null;
let currentUser = null; // Declare currentUser globally

// Audit log system
let auditLog = JSON.parse(localStorage.getItem('auditLog')) || [];

// Function to add audit log entry
function addAuditLog(action, category, details, targetId = null, targetType = null, severity = 'INFO') {
    const entry = {
        id: 'AUDIT' + Date.now(),
        timestamp: new Date().toISOString(),
        userId: currentUser ? currentUser.id : 'SYSTEM',
        userName: currentUser ? `${currentUser.firstName} ${currentUser.lastName}` : 'System',
        action: action,
        category: category,
        details: details,
        targetId: targetId,
        targetType: targetType,
        ipAddress: '192.168.1.' + Math.floor(Math.random() * 255),
        userAgent: navigator.userAgent,
        severity: severity
    };

    auditLog.unshift(entry);
    
    if (auditLog.length > 1000) {
        auditLog = auditLog.slice(0, 1000);
    }

    localStorage.setItem('auditLog', JSON.stringify(auditLog));
}

class CalendarApp {
    constructor() {
        console.log('CalendarApp constructor called');
        console.log('Current user:', currentUser);
        
        this.currentDate = currentDate;
        this.selectedDate = null;
        this.events = events;
        
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
                const view = e.target.dataset.view;
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
    }
    
    loadEvents() {
        // Events are already loaded from localStorage in the global variable
        // Initialize with sample data if empty
        if (Object.keys(this.events).length === 0) {
            this.events = this.getSampleEvents();
            localStorage.setItem('calendarEvents', JSON.stringify(this.events));
        }
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
    
    // Department color mapping function
    getDepartmentColor(department) {
        const colorMap = {
            'College of Arts, Sciences and Education': 'bg-primary',
            'College of Business and Accountancy': 'bg-success',
            'College of Computer Science and Information Systems': 'bg-danger',
            'College of Engineering and Technology': 'bg-warning',
            'College of Health Sciences and Nursing': 'bg-info',
            'College of Law and Criminology': 'bg-dark',
            'College of Tourism, Hospitality Management and Transportation': 'bg-secondary'
        };
        return colorMap[department] || 'bg-primary';
    }
    
    generateCalendar() {
        console.log('generateCalendar called');
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();

        // Update month display
        const monthNames = ["January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"];
        document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;
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
                    eventElement.title = `${event.time} - ${event.title}`;
                    
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

        document.getElementById('totalEvents').textContent = thisMonthEvents;
        document.getElementById('upcomingEvents').textContent = upcomingEvents;
        document.getElementById('todayEvents').textContent = todayEvents;
        
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
        
        eventDiv.innerHTML = `
            <div class="event-list-header">
                <h5 class="event-list-title">${event.title}</h5>
                <span class="event-list-date">${formattedDate} at ${event.time}</span>
            </div>
            <p class="event-list-description">${event.description}</p>
            <div class="event-list-meta">
                <span><i class="bi bi-tag"></i> Event</span>
                <span><i class="bi bi-person"></i> University</span>
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
        document.getElementById('eventDepartment').value = event.department || 'College of Arts, Sciences and Education';
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

        if (!this.events[date]) {
            this.events[date] = [];
        }

        if (editingEventId) {
            // Edit existing event
            const eventIndex = this.events[date].findIndex(e => e.id === editingEventId);
            if (eventIndex !== -1) {
                this.events[date][eventIndex] = {
                    id: editingEventId,
                    title,
                    time,
                    description,
                    department,
                    color
                };
                
                // Add audit log
                addAuditLog('EVENT_UPDATED', 'Event Management', `Updated event: ${title}`, editingEventId, 'Event');
            }
        } else {
            // Create new event
            const newEvent = {
                id: Date.now().toString(),
                title,
                time,
                description,
                department,
                color
            };
            this.events[date].push(newEvent);
            
            // Add audit log
            addAuditLog('EVENT_CREATED', 'Event Management', `Created new event: ${title}`, newEvent.id, 'Event');
        }

        localStorage.setItem('calendarEvents', JSON.stringify(this.events));
        this.generateCalendar();

        const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
        modal.hide();
        
        // Show success toast
        this.showToast(editingEventId ? 'Event updated successfully' : 'Event created successfully', 'success');
    }
    
    deleteEvent() {
        if (!editingEventId) return;

        const dateStr = document.getElementById('eventDate').value;
        const event = this.events[dateStr]?.find(e => e.id === editingEventId);
        
        if (!event) return;

        this.events[dateStr] = this.events[dateStr].filter(e => e.id !== editingEventId);

        if (this.events[dateStr].length === 0) {
            delete this.events[dateStr];
        }

        localStorage.setItem('calendarEvents', JSON.stringify(this.events));
        this.generateCalendar();

        const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
        modal.hide();
        
        // Add audit log
        addAuditLog('EVENT_DELETED', 'Event Management', `Deleted event: ${event.title}`, editingEventId, 'Event');
        
        // Show success toast
        this.showToast('Event deleted successfully', 'success');
    }
    
    isToday(date) {
        return date.toDateString() === today.toDateString();
    }
    
    showToast(message, type = 'info') {
        // Simple alert for now - can be enhanced with proper toast implementation
        alert(message);
    }
}

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
