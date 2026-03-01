// Event Calendar JavaScript
var BASE_URL = window.BASE_URL || window.location.origin + "/SPCF-Thesis/";
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

class CalendarApp {
  constructor() {
    this.currentDate = currentDate;
    this.selectedDate = null;
    this.events = events;
    this.searchDebounceTimer = null;
    // Fixed palette for department colors (Bootstrap backgrounds)
    this.COLOR_CLASSES = [
      "bg-primary",
      "bg-success",
      "bg-danger",
      "bg-warning",
      "bg-info",
      "bg-secondary",
      "bg-dark",
    ];

    this.COLOR_MAP_RGB = {
      "College of Arts and Social Sciences and Education": "rgb(59, 130, 246)", // Blue
      "College of Computing and Information Sciences": "rgb(30, 41, 59)", // Slate
      "College of Hospitality and Tourism Management": "rgb(236, 72, 153)", // Pink
      "College of Business": "rgb(245, 158, 11)", // Yellow
      "College of Criminology": "rgb(107, 114, 128)", // Gray
      "College of Engineering": "rgb(239, 68, 68)", // Red
      "College of Nursing": "rgb(34, 197, 94)", // Green
      "SPCF Miranda": "rgb(127, 29, 29)", // Maroon
      "Supreme Student Council": "rgb(249, 115, 22)", // Orange
      "University Wide": "rgb(139, 92, 246)", // Purple
    };
    this.init();
    this.loadEvents();
  }

  init() {
    // Load persisted state
    const savedDate = localStorage.getItem("calendar_currentDate");
    const savedView = localStorage.getItem("calendar_currentView");

    if (savedDate) {
      currentDate = new Date(savedDate);
    }

    this.setupUIBasedOnRole();
    this.bindEvents();
    this.generateCalendar();
    this.updateEventStatistics();

    if (savedView && ["month", "week", "agenda", "list"].includes(savedView)) {
      this.switchView(savedView);
    }
  }

  setupUIBasedOnRole() {
    const studentInfoCompact = document.getElementById("studentInfoCompact");
    const employeeInfoCompact = document.getElementById("employeeInfoCompact");
    const addEventBtn = document.getElementById("addEventBtn");
    const approvalsBtn = document.getElementById("approvalsBtn");

    if (studentInfoCompact) studentInfoCompact.style.display = "none";
    if (employeeInfoCompact) employeeInfoCompact.style.display = "none";
    if (approvalsBtn) approvalsBtn.style.display = "none";

    if (currentUser.role === "student") {
      if (studentInfoCompact) studentInfoCompact.style.display = "flex";
      if (
        approvalsBtn &&
        currentUser.position === "Supreme Student Council President"
      ) {
        approvalsBtn.style.display = "inline-flex";
      }
    } else if (currentUser.role === "employee") {
      if (employeeInfoCompact) employeeInfoCompact.style.display = "flex";
      if (
        addEventBtn &&
        currentUser.position === "Physical Plant and Facilities Office (PPFO)"
      ) {
        addEventBtn.style.display = "inline-flex";
      }
    } else if (currentUser.role === "admin") {
      if (addEventBtn) addEventBtn.style.display = "inline-flex";
    }

    this.updateUserInfo();
  }

  updateUserInfo() {
    const userDisplayName = document.getElementById("userDisplayName");
    const userRoleBadge = document.getElementById("userRoleBadge");

    if (userDisplayName)
      userDisplayName.textContent = `${currentUser.firstName} ${currentUser.lastName}`;
    if (userRoleBadge) {
      userRoleBadge.textContent = currentUser.role.toUpperCase();
      userRoleBadge.className = `badge ms-2 ${this.getRoleBadgeClass(currentUser.role)}`;
    }
  }

  getRoleBadgeClass(role) {
    const classes = {
      admin: "bg-danger",
      employee: "bg-primary",
      student: "bg-success",
    };
    return classes[role] || "bg-secondary";
  }

  formatTime12Hour(timeString) {
    if (!timeString) return "";
    const [hours, minutes] = timeString.split(":");
    const hour12 = hours % 12 || 12;
    const ampm = hours < 12 ? "AM" : "PM";
    return `${hour12}:${minutes} ${ampm}`;
  }

  // --- NEW SMART NORMALIZER ---
  normalizeDepartment(dept) {
    if (!dept) return "University Wide";
    const d = String(dept).toLowerCase().trim();

    if (d === "casse" || d.includes("arts") || d.includes("social"))
      return "College of Arts and Social Sciences and Education";
    if (d === "ccis" || d.includes("computing") || d.includes("information"))
      return "College of Computing and Information Sciences";
    if (d === "chtm" || d.includes("hospitality") || d.includes("tourism"))
      return "College of Hospitality and Tourism Management";
    if (d === "cob" || d.includes("business")) return "College of Business";
    if (d === "coc" || d.includes("criminology"))
      return "College of Criminology";
    if (d === "coe" || d.includes("engineering"))
      return "College of Engineering";
    if (d === "con" || d.includes("nursing")) return "College of Nursing";
    if (d === "ssc" || d.includes("supreme")) return "Supreme Student Council";
    if (d.includes("miranda")) return "SPCF Miranda";
    if (d.includes("university") || d.includes("wide"))
      return "University Wide";

    return dept.charAt(0).toUpperCase() + dept.slice(1);
  }

  bindEvents() {
    document
      .getElementById("prevMonth")
      ?.addEventListener("click", () => this.previousMonth());
    document
      .getElementById("nextMonth")
      ?.addEventListener("click", () => this.nextMonth());
    document
      .getElementById("todayBtn")
      ?.addEventListener("click", () => this.goToToday());

    document.querySelectorAll(".view-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        const button = e.currentTarget;
        if (!button) return;
        const view = button.dataset.view;
        this.switchView(view);
      });
    });

    if (
      currentUser.role === "admin" ||
      (currentUser.role === "employee" &&
        (currentUser.position ===
          "Physical Plant and Facilities Office (PPFO)" ||
          currentUser.position ===
            "Executive Vice-President/Student Services (EVP)"))
    ) {
      document
        .getElementById("addEventBtn")
        ?.addEventListener("click", () => this.openEventModal());
      document
        .getElementById("saveEventBtn")
        ?.addEventListener("click", () => this.saveEvent());
      document
        .getElementById("deleteBtn")
        ?.addEventListener("click", () => this.deleteEvent());
      document
        .getElementById("approveBtn")
        ?.addEventListener("click", () => this.approveEvent());
      document
        .getElementById("disapproveBtn")
        ?.addEventListener("click", () => this.disapproveEvent());
    }

    document.getElementById("eventSearch")?.addEventListener("input", () => {
      if (this.searchDebounceTimer) clearTimeout(this.searchDebounceTimer);
      this.searchDebounceTimer = setTimeout(() => this.applyFilters(), 180);
    });

    document
      .getElementById("departmentFilter")
      ?.addEventListener("change", () => this.applyFilters());
    document
      .getElementById("statusFilter")
      ?.addEventListener("change", () => this.applyFilters());
    document
      .getElementById("exportEventsBtn")
      ?.addEventListener("click", () => this.exportEvents());
    document.addEventListener("keydown", (e) =>
      this.handleKeyboardNavigation(e),
    );
    document
      .getElementById("eventForm")
      ?.addEventListener("submit", (e) => this.handleEventFormSubmit(e));

    this.populateDepartments();
  }

  applyFilters() {
    const activeView =
      document.querySelector(".view-btn.active")?.dataset.view || "month";
    this.switchView(activeView);
  }

  getFilteredEvents() {
    const searchTerm =
      document.getElementById("eventSearch")?.value.toLowerCase() || "";
    const departmentFilter =
      document.getElementById("departmentFilter")?.value || "";
    const statusFilter = document.getElementById("statusFilter")?.value || "";

    const filteredEvents = {};

    Object.keys(this.events).forEach((dateStr) => {
      const dayEvents = this.events[dateStr].filter((event) => {
        const matchesSearch =
          !searchTerm ||
          event.title.toLowerCase().includes(searchTerm) ||
          event.department.toLowerCase().includes(searchTerm);
        const matchesDepartment =
          !departmentFilter || event.department === departmentFilter;
        const matchesStatus =
          !statusFilter ||
          (statusFilter === "approved" && event.isApproved) ||
          (statusFilter === "pending" && !event.isApproved);
        return matchesSearch && matchesDepartment && matchesStatus;
      });

      if (dayEvents.length > 0) filteredEvents[dateStr] = dayEvents;
    });
    return filteredEvents;
  }

  exportEvents() {
    const filteredEvents = this.getFilteredEvents();
    const eventsToExport = [];

    Object.keys(filteredEvents).forEach((dateStr) => {
      filteredEvents[dateStr].forEach((event) => {
        eventsToExport.push({
          date: dateStr,
          time: event.time,
          title: event.title,
          department: event.department,
          status: event.isApproved ? "Approved" : "Pending",
        });
      });
    });

    if (eventsToExport.length === 0) {
      this.showToast("No events to export", "warning");
      return;
    }

    const headers = ["Date", "Time", "Title", "Department", "Status"];
    const csvContent = [
      headers.join(","),
      ...eventsToExport.map((event) =>
        [
          event.date,
          event.time,
          `"${event.title.replace(/"/g, '""')}"`,
          `"${event.department || ""}"`,
          event.status,
        ].join(","),
      ),
    ].join("\n");

    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `events_${new Date().toISOString().split("T")[0]}.csv`;
    link.style.visibility = "hidden";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    this.showToast(
      `Exported ${eventsToExport.length} events to CSV`,
      "success",
    );
  }

  handleKeyboardNavigation(e) {
    if (
      e.target.tagName === "INPUT" ||
      e.target.tagName === "TEXTAREA" ||
      e.target.tagName === "SELECT"
    )
      return;

    switch (e.key) {
      case "ArrowLeft":
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          this.previousMonth();
        }
        break;
      case "ArrowRight":
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          this.nextMonth();
        }
        break;
      case "Home":
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          this.goToToday();
        }
        break;
      case "m":
      case "M":
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          this.switchView("month");
        }
        break;
      case "w":
      case "W":
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          this.switchView("week");
        }
        break;
      case "l":
      case "L":
      case "a":
      case "A":
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          this.switchView("agenda");
        }
        break;
    }
  }

  async loadEvents() {
    this.setCalendarLoading(true);

    try {
      const resp = await fetch(BASE_URL + "api/events.php");
      const data = await resp.json();
      if (data && data.success) {
        const map = {};
        (data.events || []).forEach((ev) => {
          const dateStr = ev.event_date;
          if (!map[dateStr]) map[dateStr] = [];

          const isApproved = ev.approved == 1;
          const isPencilBooked = !isApproved;

          const normalizedDept = this.normalizeDepartment(ev.department);

          // FIX: Save raw 24-hour time for the input field, and 12-hour time for the display
          const rawTime = ev.event_time ? ev.event_time.substring(0, 5) : "";

          map[dateStr].push({
            id: String(ev.id),
            title: ev.title,
            time: this.formatTime12Hour(ev.event_time) || "",
            rawTime: rawTime,
            department: normalizedDept,
            venue: ev.venue || "",
            isApproved: isApproved,
            isPencilBooked: isPencilBooked,
            color: isApproved
              ? this.getDepartmentColorRGB(normalizedDept)
              : "rgb(148, 163, 184)",
            textColor: isApproved
              ? this.getTextColorForRGB(
                  this.getDepartmentColorRGB(normalizedDept),
                )
              : "white",
          });
        });
        this.events = map;
        events = map;
        this.generateCalendar();
        this.updateEventStatistics();
      }
    } catch (e) {
      console.error(e);
    } finally {
      this.setCalendarLoading(false);
    }
  }

  setCalendarLoading(isLoading) {
    const loadingEl = document.getElementById("calendarLoading");
    const container =
      document.querySelector(".calendar-container") ||
      document.querySelector(".card-lg");

    if (loadingEl) {
      loadingEl.classList.toggle("d-none", !isLoading);
      loadingEl.style.display = isLoading ? "block" : "none";
    }

    if (container) container.classList.toggle("is-loading", isLoading);
  }

  async populateDepartments() {
    // --- ADDED .TYPE SO THE LEGEND ORGANIZES PERFECTLY ---
    const units = [
      {
        name: "College of Arts and Social Sciences and Education",
        type: "college",
      },
      {
        name: "College of Computing and Information Sciences",
        type: "college",
      },
      {
        name: "College of Hospitality and Tourism Management",
        type: "college",
      },
      { name: "College of Business", type: "college" },
      { name: "College of Criminology", type: "college" },
      { name: "College of Engineering", type: "college" },
      { name: "College of Nursing", type: "college" },
      { name: "SPCF Miranda", type: "college" },
      { name: "Supreme Student Council", type: "office" },
      { name: "University Wide", type: "office" },
    ];

    const select = document.getElementById("eventDepartment");
    if (select) {
      select.innerHTML = '<option value="">Select Department</option>';
      units.forEach((u) => {
        const opt = document.createElement("option");
        opt.value = u.name;
        opt.textContent = u.name;
        select.appendChild(opt);
      });
    }

    const filterSelect = document.getElementById("departmentFilter");
    if (filterSelect) {
      filterSelect.innerHTML = '<option value="">All Departments</option>';
      units.forEach((u) => {
        const opt = document.createElement("option");
        opt.value = u.name;
        opt.textContent = u.name;
        filterSelect.appendChild(opt);
      });
    }

    this.renderDepartmentLegend(units);
  }

  renderDepartmentLegend(units) {
    const legend = document.getElementById("departmentLegend");
    if (!legend) return;
    legend.innerHTML = "";

    const byType = { office: [], college: [] };
    units.forEach((u) => {
      if (u.type === "office") byType.office.push(u);
      else if (u.type === "college") byType.college.push(u);
    });

    const renderGroup = (title, list) => {
      if (!list.length) return;
      const header = document.createElement("div");
      header.className = "legend-group-title fw-semibold mt-2 mb-1";
      header.textContent = title;
      legend.appendChild(header);

      const wrap = document.createElement("div");
      wrap.className = "d-flex flex-wrap gap-2";
      list.forEach((u) => {
        const bg = this.getDepartmentColorRGB(u.name);
        const text = this.getTextColorForRGB(bg);
        const badge = document.createElement("span");
        badge.className = "badge";
        badge.style.backgroundColor = bg;
        badge.style.color = text;
        badge.textContent = u.name;
        wrap.appendChild(badge);
      });
      legend.appendChild(wrap);
    };

    renderGroup("Colleges", byType.college);
    renderGroup("Offices", byType.office);
  }

  hashToColorIndex(name) {
    let h = 0;
    for (let i = 0; i < (name || "").length; i++)
      h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return Math.abs(h) % this.COLOR_CLASSES.length;
  }

  getDepartmentColorRGB(department) {
    const norm = this.normalizeDepartment(department);
    return this.COLOR_MAP_RGB[norm] || "rgb(59, 130, 246)"; // Defaults to blue
  }

  getTextColorForRGB(rgb) {
    const match = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
    if (!match) return "black";
    const r = parseInt(match[1]),
      g = parseInt(match[2]),
      b = parseInt(match[3]);
    const brightness = (r * 299 + g * 587 + b * 114) / 1000;
    return brightness > 128 ? "black" : "white";
  }

  generateCalendar() {
    const year = this.currentDate.getFullYear();
    const month = this.currentDate.getMonth();
    const monthNames = [
      "January",
      "February",
      "March",
      "April",
      "May",
      "June",
      "July",
      "August",
      "September",
      "October",
      "November",
      "December",
    ];

    const monthEl = document.getElementById("currentMonth");
    if (monthEl) monthEl.textContent = `${monthNames[month]} ${year}`;

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    const calendarDays = document.getElementById("calendarDays");

    if (!calendarDays) return;
    calendarDays.innerHTML = "";

    let dayCount = 0;
    const isStudent = currentUser && currentUser.role === "student";

    for (let week = 0; week < 6; week++) {
      for (let day = 0; day < 7; day++) {
        const dayElement = document.createElement("div");
        dayElement.className = "calendar-day";

        let dayNumber,
          isCurrentMonth = true,
          cellDate;

        if (week === 0 && day < firstDay) {
          dayNumber = daysInPrevMonth - firstDay + day + 1;
          isCurrentMonth = false;
          cellDate = new Date(year, month - 1, dayNumber);
        } else if (dayCount >= daysInMonth) {
          dayNumber = dayCount - daysInMonth + 1;
          isCurrentMonth = false;
          cellDate = new Date(year, month + 1, dayNumber);
          dayCount++;
        } else {
          dayNumber = dayCount + 1;
          cellDate = new Date(year, month, dayNumber);
          dayCount++;
        }

        const yearStr = cellDate.getFullYear();
        const monthStr = String(cellDate.getMonth() + 1).padStart(2, "0");
        const dayStr = String(dayNumber).padStart(2, "0");
        const dateStr = `${yearStr}-${monthStr}-${dayStr}`;

        dayElement.dataset.date = dateStr;

        if (!isCurrentMonth) dayElement.classList.add("other-month");
        if (this.isToday(cellDate)) dayElement.classList.add("today");

        const dayNumberEl = document.createElement("div");
        dayNumberEl.className = "day-number";
        dayNumberEl.textContent = dayNumber;
        dayElement.appendChild(dayNumberEl);

        const eventsContainer = document.createElement("div");
        eventsContainer.className = "day-events";

        const filteredEvents = this.getFilteredEvents();
        const dayEvents = filteredEvents[dateStr] || [];

        dayEvents.forEach((event) => {
          const eventElement = document.createElement("div");
          eventElement.className = "event-item";
          eventElement.style.backgroundColor = event.color;
          eventElement.style.color = event.textColor;
          eventElement.dataset.eventId = event.id;
          eventElement.dataset.date = dateStr;
          eventElement.textContent = event.title;

          const timeStr = event.time ? `${event.time} - ` : "";
          const deptStr = event.department ? ` (${event.department})` : "";
          const statusStr = event.isPencilBooked
            ? " [Pencil-booked]"
            : " [Approved]";
          eventElement.title = `${timeStr}${event.title}${deptStr}${statusStr}`;

          eventElement.addEventListener("click", (e) => {
            e.stopPropagation();
            this.handleEventClick(event.id, dateStr);
          });

          eventsContainer.appendChild(eventElement);
        });

        dayElement.appendChild(eventsContainer);

        if (!isStudent) {
          dayElement.addEventListener("click", () => {
            this.selectedDate = cellDate;
            this.openEventModal(dateStr);
          });
          dayElement.style.cursor = "pointer";
        }

        calendarDays.appendChild(dayElement);
      }
    }
    this.updateEventStatistics();
  }

  updateEventStatistics() {
    const currentMonth = this.currentDate.getMonth();
    const currentYear = this.currentDate.getFullYear();
    let thisMonthEvents = 0,
      upcomingEvents = 0,
      todayEvents = 0;
    const todayStr = today.toISOString().split("T")[0];

    Object.keys(this.events).forEach((dateStr) => {
      const [year, month, day] = dateStr.split("-").map((num) => parseInt(num));
      const eventDate = new Date(year, month - 1, day);

      if (month - 1 === currentMonth && year === currentYear)
        thisMonthEvents += this.events[dateStr].length;
      if (eventDate >= today) upcomingEvents += this.events[dateStr].length;
      if (dateStr === todayStr) todayEvents += this.events[dateStr].length;
    });

    if (document.getElementById("totalEvents"))
      document.getElementById("totalEvents").textContent = thisMonthEvents;
    if (document.getElementById("upcomingEvents"))
      document.getElementById("upcomingEvents").textContent = upcomingEvents;
    if (document.getElementById("todayEvents"))
      document.getElementById("todayEvents").textContent = todayEvents;

    localStorage.setItem(
      "calendar_currentDate",
      this.currentDate.toISOString(),
    );
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
    localStorage.setItem("calendar_currentView", view);

    document
      .querySelectorAll(".view-btn")
      .forEach((btn) =>
        btn.classList.toggle("active", btn.dataset.view === view),
      );

    const calendarContainer =
      document.querySelector(".calendar-container") ||
      document.querySelector(".card-lg");
    if (calendarContainer) calendarContainer.setAttribute("data-view", view);

    document.querySelectorAll(".calendar-view").forEach((container) => {
      const isActive = container.id === `${view}View`;
      container.classList.toggle("active", isActive);
      container.classList.toggle("d-none", !isActive);
    });

    if (view === "month") this.generateCalendar();
    else if (view === "agenda") this.renderAgendaView();
  }

  renderAgendaView() {
    const agendaContainer = document.getElementById("agendaContainer");
    if (!agendaContainer) return;
    agendaContainer.innerHTML = "";

    const filteredEvents = this.getFilteredEvents();
    const eventsByDate = {};
    Object.keys(filteredEvents).forEach((dateStr) => {
      filteredEvents[dateStr].forEach((event) => {
        if (!eventsByDate[dateStr]) eventsByDate[dateStr] = [];
        eventsByDate[dateStr].push(event);
      });
    });

    const sortedDates = Object.keys(eventsByDate).sort();

    if (sortedDates.length === 0) {
      agendaContainer.innerHTML =
        '<div class="text-center text-muted p-4">No upcoming events</div>';
      return;
    }

    sortedDates.forEach((dateStr) => {
      const dateEvents = eventsByDate[dateStr];
      const dateDiv = document.createElement("div");
      dateDiv.className = "agenda-date-group";

      const dateHeader = document.createElement("div");
      dateHeader.className = "agenda-date-header";

      const [year, month, day] = dateStr.split("-").map((num) => parseInt(num));
      const eventDate = new Date(year, month - 1, day);
      const tomorrow = new Date(today);
      tomorrow.setDate(today.getDate() + 1);

      let dateLabel = eventDate.toLocaleDateString("en-US", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
      });

      if (eventDate.toDateString() === today.toDateString()) {
        dateLabel = "Today - " + dateLabel;
        dateHeader.classList.add("today");
      } else if (eventDate.toDateString() === tomorrow.toDateString()) {
        dateLabel = "Tomorrow - " + dateLabel;
      }

      dateHeader.innerHTML = `<h5>${dateLabel}</h5><span class="event-count">${dateEvents.length} event${dateEvents.length !== 1 ? "s" : ""}</span>`;

      const eventsList = document.createElement("div");
      eventsList.className = "agenda-events-list";

      dateEvents
        .sort((a, b) => a.time.localeCompare(b.time))
        .forEach((event) => {
          const eventElement = this.createAgendaEventElement(event, dateStr);
          eventsList.appendChild(eventElement);
        });

      dateDiv.appendChild(dateHeader);
      dateDiv.appendChild(eventsList);
      agendaContainer.appendChild(dateDiv);
    });
  }

  createAgendaEventElement(event, dateStr) {
    const eventDiv = document.createElement("div");
    eventDiv.className = "agenda-event-item";
    eventDiv.dataset.eventId = event.id;
    eventDiv.dataset.date = dateStr;

    eventDiv.innerHTML = `
            <div class="agenda-event-time">
                <i class="bi bi-clock"></i><span>${event.time}</span>
            </div>
            <div class="agenda-event-content">
                <h6 class="agenda-event-title">${event.title}</h6>
                <div class="agenda-event-meta">
                    <span class="badge" style="background-color: ${event.color}; color: ${event.textColor}">${event.department || "University Wide"}</span>
                    ${event.isApproved ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-warning text-dark">Pending</span>'}
                </div>
            </div>
        `;

    eventDiv.addEventListener("click", () =>
      this.handleEventClick(event.id, dateStr),
    );
    return eventDiv;
  }

  handleEventClick(eventId, dateStr) {
    const canEdit =
      currentUser.role === "admin" ||
      (currentUser.role === "employee" &&
        (currentUser.position ===
          "Physical Plant and Facilities Office (PPFO)" ||
          currentUser.position ===
            "Executive Vice-President/Student Services (EVP)"));
    if (currentUser.role === "student" || !canEdit)
      this.viewEventDetails(eventId, dateStr);
    else this.editEvent(eventId, dateStr);
  }

  openEventModal(selectedDate = null) {
    const canEdit =
      currentUser.role === "admin" ||
      (currentUser.role === "employee" &&
        (currentUser.position ===
          "Physical Plant and Facilities Office (PPFO)" ||
          currentUser.position ===
            "Executive Vice-President/Student Services (EVP)"));
    if (currentUser && (currentUser.role === "student" || !canEdit)) return;

    document.getElementById("eventForm").reset();
    editingEventId = null;
    document.getElementById("deleteBtn").style.display = "none";
    document.getElementById("approveBtn").style.display = "none";
    document.getElementById("disapproveBtn").style.display = "none";
    document.getElementById("eventModalLabel").textContent = "Add New Event";
    document.getElementById("eventDate").value =
      selectedDate || new Date().toISOString().split("T")[0];

    new bootstrap.Modal(document.getElementById("eventModal")).show();
  }

  editEvent(eventId, dateStr) {
    if (currentUser && currentUser.role === "student")
      return this.viewEventDetails(eventId, dateStr);

    const event = this.events[dateStr]?.find((e) => e.id === eventId);
    if (!event) return;

    editingEventId = eventId;
    document.getElementById("eventModalLabel").textContent = "Edit Event";
    document.getElementById("eventTitle").value = event.title;
    document.getElementById("eventDate").value = dateStr;

    // FIX: Feeds the 24-hour format into the HTML input!
    document.getElementById("eventTime").value = event.rawTime || "";

    document.getElementById("eventVenue").value = event.venue || "";

    const deptSelect = document.getElementById("eventDepartment");
    if (deptSelect) {
      const val = event.department || "";
      if (Array.from(deptSelect.options).some((o) => o.value === val))
        deptSelect.value = val;
      else deptSelect.value = "";
    }

    document.getElementById("deleteBtn").style.display = "inline-block";
    const canApprove =
      currentUser.role === "admin" ||
      (currentUser.role === "employee" &&
        (currentUser.position ===
          "Physical Plant and Facilities Office (PPFO)" ||
          currentUser.position ===
            "Executive Vice-President/Student Services (EVP)"));
    document.getElementById("approveBtn").style.display = canApprove
      ? "inline-block"
      : "none";
    document.getElementById("disapproveBtn").style.display = canApprove
      ? "inline-block"
      : "none";

    new bootstrap.Modal(document.getElementById("eventModal")).show();
  }

  viewEventDetails(eventId, dateStr) {
    const event = this.events[dateStr]?.find((e) => e.id === eventId);
    if (!event) return;

    const [year, month, day] = dateStr.split("-").map((num) => parseInt(num));
    const formattedDate = new Date(year, month - 1, day).toLocaleDateString(
      "en-US",
      { weekday: "long", year: "numeric", month: "long", day: "numeric" },
    );

    let formattedTime = event.time;
    if (
      event.time &&
      !event.time.includes("AM") &&
      !event.time.includes("PM")
    ) {
      const [hours, minutes] = event.time.split(":");
      formattedTime = `${hours % 12 || 12}:${minutes} ${hours < 12 ? "AM" : "PM"}`;
    }

    document.getElementById("viewEventTitle").textContent = event.title;
    document.getElementById("viewEventVenue").textContent =
      event.venue || "Not specified";
    document.getElementById("viewEventDate").textContent = formattedDate;
    document.getElementById("viewEventTime").textContent = formattedTime;
    document.getElementById("viewEventDepartment").textContent =
      event.department || "University Wide";
    document.getElementById("viewEventStatus").textContent = event.isApproved
      ? "Approved Event"
      : "Pencil-booked Event";

    new bootstrap.Modal(document.getElementById("viewEventModal")).show();
  }

  handleEventFormSubmit(e) {
    e.preventDefault();
    this.saveEvent();
  }

  saveEvent() {
    if (currentUser && currentUser.role === "student") return;

    const title = document.getElementById("eventTitle").value;
    const date = document.getElementById("eventDate").value;
    const time = document.getElementById("eventTime").value;
    const venue = document.getElementById("eventVenue").value;
    const department = document.getElementById("eventDepartment").value;

    if (!title || !date)
      return this.showToast(
        "Please provide a title and date for the event.",
        "warning",
      );
    if (!department)
      return this.showToast("Please select a department.", "warning");

    const payload = {
      title,
      venue,
      event_date: date,
      event_time: time || null,
      department,
    };
    const isUpdate = Boolean(editingEventId);
    const url =
      BASE_URL +
      "api/events.php" +
      (isUpdate ? `?id=${encodeURIComponent(editingEventId)}` : "");

    fetch(url, {
      method: isUpdate ? "PUT" : "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .then((res) => {
        if (res.success) {
          this.loadEvents();
          bootstrap.Modal.getInstance(
            document.getElementById("eventModal"),
          )?.hide();
          this.showToast(
            isUpdate
              ? "Event updated successfully"
              : "Event created successfully",
            "success",
          );
        } else this.showToast(res.message || "Failed to save event", "error");
      })
      .catch(() => this.showToast("Server error saving event", "error"));
  }

  deleteEvent() {
    if (!editingEventId) return;
    fetch(
      BASE_URL + `api/events.php?id=${encodeURIComponent(editingEventId)}`,
      { method: "DELETE" },
    )
      .then((r) => r.json())
      .then((res) => {
        if (res.success) {
          this.loadEvents();
          bootstrap.Modal.getInstance(
            document.getElementById("eventModal"),
          )?.hide();
          this.showToast("Event deleted successfully", "success");
        } else this.showToast(res.message || "Delete failed", "error");
      })
      .catch(() => this.showToast("Server error deleting event", "error"));
  }

  approveEvent() {
    if (!editingEventId) return;
    fetch(
      BASE_URL + `api/events.php?id=${encodeURIComponent(editingEventId)}`,
      {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "approve" }),
      },
    )
      .then((r) => r.json())
      .then((res) => {
        if (res.success) {
          this.loadEvents();
          bootstrap.Modal.getInstance(
            document.getElementById("eventModal"),
          )?.hide();
          this.showToast("Event approved successfully", "success");
        } else this.showToast(res.message || "Approval failed", "error");
      })
      .catch(() => this.showToast("Server error approving event", "error"));
  }

  disapproveEvent() {
    if (!editingEventId) return;
    fetch(
      BASE_URL + `api/events.php?id=${encodeURIComponent(editingEventId)}`,
      {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "disapprove" }),
      },
    )
      .then((r) => r.json())
      .then((res) => {
        if (res.success) {
          this.loadEvents();
          bootstrap.Modal.getInstance(
            document.getElementById("eventModal"),
          )?.hide();
          this.showToast("Event disapproved successfully", "success");
        } else this.showToast(res.message || "Disapproval failed", "error");
      })
      .catch(() => this.showToast("Server error disapproving event", "error"));
  }

  isToday(date) {
    return date.toDateString() === today.toDateString();
  }

  showToast(message, type = "info") {
    if (window.ToastManager)
      window.ToastManager.show({
        type: type,
        message: message,
        duration: 3000,
      });
    else alert(message);
  }
}

// ... Remaining Global Account logic perfectly preserved below ...
function openChangePassword() {
  new bootstrap.Modal(document.getElementById("changePasswordModal")).show();
}
function togglePasswordVisibility(fieldId) {
  const field = document.getElementById(fieldId);
  const icon = document.getElementById(fieldId + "Icon");
  if (!field) return;
  if (field.type === "password") {
    field.type = "text";
    if (icon) {
      icon.classList.remove("bi-eye");
      icon.classList.add("bi-eye-slash");
    }
  } else {
    field.type = "password";
    if (icon) {
      icon.classList.remove("bi-eye-slash");
      icon.classList.add("bi-eye");
    }
  }
}

document
  .getElementById("changePasswordForm")
  .addEventListener("submit", async function (e) {
    e.preventDefault();
    const currentPassword =
      document.getElementById("currentPassword")?.value || "";
    const newPassword = document.getElementById("newPassword")?.value || "";
    const confirmPassword =
      document.getElementById("confirmPassword")?.value || "";
    const messagesDiv = document.getElementById("changePasswordMessages");

    const show = (html) => {
      if (messagesDiv) messagesDiv.innerHTML = html;
    };
    const ok = (msg) =>
      `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) =>
      `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    if (!currentPassword || !newPassword || !confirmPassword) {
      show(err("All fields are required."));
      return;
    }
    if (newPassword !== confirmPassword) {
      show(err("New passwords do not match."));
      return;
    }
    const policy =
      /^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/;
    if (!policy.test(newPassword)) {
      show(
        err("Password must be 8+ chars with upper, lower, number, special."),
      );
      return;
    }

    try {
      const resp = await fetch(BASE_URL + "api/auth.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "change_password",
          current_password: currentPassword,
          new_password: newPassword,
        }),
      }).then((r) => r.json());

      if (resp.success) {
        show(ok("Password changed successfully!"));
        setTimeout(() => {
          bootstrap.Modal.getInstance(
            document.getElementById("changePasswordModal"),
          )?.hide();
          if (messagesDiv) messagesDiv.innerHTML = "";
          document.getElementById("changePasswordForm")?.reset();
        }, 1500);
      } else show(err(resp.message || "Failed to change password."));
    } catch (e) {
      show(err("Server error changing password."));
    }
  });

function logout() {
  localStorage.removeItem("currentUser");
  currentUser = null;
  window.location.href = BASE_URL + "logout";
}

function openProfileSettings() {
  if (currentUser) {
    document.getElementById("profileFirstName").value =
      currentUser.first_name || "";
    document.getElementById("profileLastName").value =
      currentUser.last_name || "";
    document.getElementById("profileEmail").value = currentUser.email || "";
    document.getElementById("profilePhone").value = currentUser.phone || "";
    if (currentUser.role === "student")
      document.getElementById("profilePosition").value = "Student";
    document.getElementById("darkModeToggle").checked =
      localStorage.getItem("darkMode") === "true";
  }
  new bootstrap.Modal(document.getElementById("profileSettingsModal")).show();
}

function openPreferences() {
  document.getElementById("emailNotifications").checked =
    localStorage.getItem("emailNotifications") !== "false";
  document.getElementById("browserNotifications").checked =
    localStorage.getItem("browserNotifications") !== "false";
  document.getElementById("defaultView").value =
    localStorage.getItem("defaultView") || "month";
  document.getElementById("timezone").value =
    localStorage.getItem("timezone") || "Asia/Manila";
  new bootstrap.Modal(document.getElementById("preferencesModal")).show();
}

function showHelp() {
  new bootstrap.Modal(document.getElementById("helpModal")).show();
}

function savePreferences() {
  localStorage.setItem(
    "emailNotifications",
    document.getElementById("emailNotifications").checked,
  );
  localStorage.setItem(
    "browserNotifications",
    document.getElementById("browserNotifications").checked,
  );
  localStorage.setItem(
    "defaultView",
    document.getElementById("defaultView").value,
  );
  localStorage.setItem("timezone", document.getElementById("timezone").value);
  const msgs = document.getElementById("preferencesMessages");
  if (msgs) {
    msgs.innerHTML =
      '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Preferences saved successfully!</div>';
    setTimeout(() => {
      msgs.innerHTML = "";
      bootstrap.Modal.getInstance(
        document.getElementById("preferencesModal"),
      ).hide();
    }, 2000);
  }
  applyTheme();
}

if (window.NavbarSettings) {
  window.openProfileSettings = window.NavbarSettings.openProfileSettings;
  window.openChangePassword = window.NavbarSettings.openChangePassword;
  window.openPreferences = window.NavbarSettings.openPreferences;
  window.showHelp = window.NavbarSettings.showHelp;
  window.savePreferences = window.NavbarSettings.savePreferences;
  window.saveProfileSettings = window.NavbarSettings.saveProfileSettings;
}

document
  .getElementById("profileSettingsForm")
  .addEventListener("submit", async function (e) {
    e.preventDefault();
    const firstName = document.getElementById("profileFirstName").value;
    const lastName = document.getElementById("profileLastName").value;
    const email = document.getElementById("profileEmail").value;
    const phone = document.getElementById("profilePhone").value;
    const msgs = document.getElementById("profileSettingsMessages");

    if (!firstName || !lastName || !email) {
      msgs.innerHTML =
        '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>First name, last name, and email are required.</div>';
      return;
    }

    try {
      const resp = await fetch(BASE_URL + "api/users.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "update_profile",
          first_name: firstName,
          last_name: lastName,
          email: email,
          phone: phone,
        }),
      }).then((r) => r.json());

      if (resp.success) {
        currentUser.first_name = firstName;
        currentUser.last_name = lastName;
        currentUser.email = email;
        currentUser.phone = phone;
        if (document.getElementById("userDisplayName"))
          document.getElementById("userDisplayName").textContent =
            `${firstName} ${lastName}`;
        localStorage.setItem(
          "darkMode",
          document.getElementById("darkModeToggle").checked,
        );
        applyTheme();
        msgs.innerHTML =
          '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Profile updated successfully!</div>';
        setTimeout(() => {
          bootstrap.Modal.getInstance(
            document.getElementById("profileSettingsModal"),
          ).hide();
          msgs.innerHTML = "";
        }, 2000);
      } else
        msgs.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${resp.message || "Failed to update profile."}</div>`;
    } catch (error) {
      msgs.innerHTML =
        '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>An error occurred while updating your profile.</div>';
    }
  });

function applyTheme() {
  const darkMode = localStorage.getItem("darkMode") === "true";
  document.body.classList.toggle("dark-theme", darkMode);
  if (document.getElementById("darkModeToggle"))
    document.getElementById("darkModeToggle").checked = darkMode;
}

document.addEventListener("DOMContentLoaded", function () {
  if (window.currentUser) {
    currentUser = window.currentUser;
    new CalendarApp();
    applyTheme();
  } else window.location.href = BASE_URL + "login";
});

window.openPubmatDisplay = function () {
  window.location.href = window.BASE_URL + "pubmat-display";
};
window.openPubmatApprovals = function () {
  window.location.href = window.BASE_URL + "pubmat-approvals";
};
