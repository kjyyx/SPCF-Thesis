<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
  logoutUser();
  header('Location: ' . BASE_URL . 'login');
  exit();
}

// Restrict Accounting employees to only SAF access
if ($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) {
  header('Location: ' . BASE_URL . 'saf');
  exit();
}

// Restrict to students only
if ($currentUser['role'] !== 'student') {
  header('Location: ' . BASE_URL . 'login&error=access_denied');
  exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
  global $currentUser;
  try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $currentUser['id'],
      $currentUser['role'],
      $currentUser['first_name'] . ' ' . $currentUser['last_name'],
      $action,
      $category,
      $details,
      $targetId,
      $targetType,
      $_SERVER['REMOTE_ADDR'] ?? null,
      null, // Set user_agent to null to avoid storing PII
      $severity ?? 'INFO'
    ]);
  } catch (Exception $e) {
    error_log("Failed to add audit log: " . $e->getMessage());
  }
}

// Log page view
addAuditLog('CREATE_DOCUMENT_VIEWED', 'Document Management', 'Viewed create document page', $currentUser['id'], 'User', 'INFO');
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
  <title>Sign-um - Document Creator</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/create-document.css">

  <script>
    // Pass user data to JavaScript
    window.currentUser = <?php
    $jsUser = $currentUser;
    $jsUser['firstName'] = $currentUser['first_name'];
    $jsUser['lastName'] = $currentUser['last_name'];
    $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
    echo json_encode($jsUser);
    ?>;
    window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;
    window.BASE_URL = "<?php echo BASE_URL; ?>";
  </script>
</head>

<body class="has-navbar">
  <?php
  $pageTitle = 'Document Creator';
  $currentPage = 'create-document';
  include ROOT_PATH . 'includes/navbar.php';
  include ROOT_PATH . 'includes/notifications.php';
  ?>

  <div class="container pt-4 pb-5">

    <div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h1 class="page-title">
          <i class="bi bi-file-earmark-plus text-primary me-2"></i> Create Document
        </h1>
        <p class="page-subtitle">Generate and prepare documents for digital signature workflow</p>
      </div>
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-ghost rounded-pill" onclick="history.back()" title="Go Back">
          <i class="bi bi-arrow-left"></i> Back
        </button>
        <div class="dropdown">
          <button id="documentTypeDropdown" class="btn btn-outline-primary rounded-pill dropdown-toggle"
            data-bs-toggle="dropdown">
            <i class="bi bi-folder2-open me-2"></i>Project Proposal
          </button>
          <ul class="dropdown-menu shadow-xl border-0">
            <li><a class="dropdown-item" href="#" onclick="selectDocumentType('proposal')"><i
                  class="bi bi-journal-text text-primary"></i>Project Proposal</a></li>
            <li><a class="dropdown-item" href="#" onclick="selectDocumentType('saf')"><i
                  class="bi bi-piggy-bank text-success"></i>SAF Request</a></li>
            <li><a class="dropdown-item" href="#" onclick="selectDocumentType('facility')"><i
                  class="bi bi-building text-warning"></i>Facility Request</a></li>
            <li><a class="dropdown-item" href="#" onclick="selectDocumentType('communication')"><i
                  class="bi bi-envelope text-info"></i>Communication Letter</a></li>
          </ul>
        </div>
        <button class="btn btn-primary rounded-pill shadow-primary" onclick="submitDocument()">
          <i class="bi bi-send-check me-2"></i> Submit Document
        </button>
      </div>
    </div>

    <div class="row g-4 h-full align-items-stretch">

      <div class="col-lg-6 d-flex flex-column">
        <div class="card card-lg flex-1 shadow-sm h-100">
          <div class="card-body overflow-y-auto" style="max-height: calc(100vh - 240px); padding-right: 12px;">

            <div id="proposal-form" class="document-form">
              <h4 class="mb-4 text-dark fw-bold"><i class="bi bi-journal-text text-primary me-2"></i> Project Proposal
              </h4>

              <div class="row g-3 mb-4">
                <div class="col-md-4 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-calendar3 me-1"></i> Date <span
                      class="text-danger">*</span></label>
                  <input id="prop-date" type="date" class="form-control sm">
                </div>
                <div class="col-md-8 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-person-badge me-1"></i>
                    Organizer <span class="text-danger">*</span></label>
                  <input id="prop-organizer" class="form-control sm" placeholder="Enter project organizer name">
                </div>
                <div class="col-md-12 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-building me-1"></i> Department
                    <span class="text-danger">*</span></label>
                  <input type="text" class="form-control sm bg-surface-sunken"
                    value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>" readonly>
                  <input type="hidden" id="prop-department"
                    value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>">
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-12 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-flag me-1"></i> Project Title
                    <span class="text-danger">*</span></label>
                  <input id="prop-title" class="form-control sm" placeholder="Enter project title">
                </div>
                <div class="col-md-12 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-person-check me-1"></i> Lead
                    Facilitator <span class="text-danger">*</span></label>
                  <input id="prop-lead" class="form-control sm" placeholder="Enter lead facilitator name">
                </div>
                <div class="col-md-12 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-people me-1"></i>
                    Support</label>
                  <input id="prop-support" class="form-control sm" placeholder="Enter support details">
                </div>
              </div>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase"><i class="bi bi-card-text me-1"></i>
                  Rationale</label>
                <textarea id="prop-rationale" class="form-control" rows="3"
                  placeholder="Describe the purpose and justification for this project..."></textarea>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-list-check me-1"></i>
                    Objectives <span class="text-danger">*</span></label>
                  <textarea id="prop-objectives" class="form-control" rows="4"
                    placeholder="1. Objective one&#10;2. Objective two"></textarea>
                </div>
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-mortarboard me-1"></i>
                    ILOs</label>
                  <textarea id="prop-ilos" class="form-control" rows="4"
                    placeholder="1. Participants will learn..."></textarea>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-wallet2 me-1"></i> Budget
                    Source</label>
                  <input id="prop-budget-source" class="form-control sm" placeholder="e.g., SAF, Dept funds">
                </div>
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-geo-alt me-1"></i> Venue <span
                      class="text-danger">*</span></label>
                  <input id="prop-venue" class="form-control sm" placeholder="Event Venue">
                </div>
              </div>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase"><i class="bi bi-gear me-1"></i> Mechanics</label>
                <textarea id="prop-mechanics" class="form-control" rows="3"
                  placeholder="Mechanics / How it will run"></textarea>
              </div>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase"><i class="bi bi-clock-history me-1"></i> Schedule
                  Summary <span class="text-danger">*</span></label>
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <small class="text-muted">Add event dates and time ranges (each will create a calendar event when
                    approved)</small>
                  <button class="btn btn-outline-primary btn-sm rounded-pill" onclick="addScheduleSummary()">
                    <i class="bi bi-plus-lg"></i> Add Date & Time
                  </button>
                </div>
                <div id="schedule-summary-rows">
                  <div class="schedule-summary-row mb-2">
                    <div class="row g-3 align-items-center">
                      <div class="col-md-4"><input type="date" class="form-control sm schedule-date" placeholder="Date"
                          required></div>
                      <div class="col-md-3"><input type="time" class="form-control sm schedule-time"
                          placeholder="Start Time" required></div>
                      <div class="col-md-3"><input type="time" class="form-control sm schedule-end-time"
                          placeholder="End Time"></div>
                      <div class="col-md-2"><button class="btn btn-danger btn-icon sm"
                          onclick="removeScheduleRow(this)"><i class="bi bi-x-lg"></i></button></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="divider"></div>

              <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <div>
                    <label class="form-label text-muted text-xs uppercase m-0"><i class="bi bi-card-checklist me-1"></i>
                      Program Schedule</label>
                    <small class="text-muted d-block">Documentation only - does not affect calendar events</small>
                  </div>
                  <button class="btn btn-outline-primary btn-sm rounded-pill" onclick="addProgramRowProp()">
                    <i class="bi bi-plus-lg"></i> Add Activity
                  </button>
                </div>
                <div id="program-rows-prop">
                  <div class="program-row mb-2">
                    <div><input type="time" class="form-control sm start-time" value=""></div>
                    <div><input type="time" class="form-control sm end-time" value=""></div>
                    <div><input type="text" class="activity-input form-control sm" placeholder="Activity description">
                    </div>
                    <div><button class="btn btn-danger btn-icon sm" onclick="removeProgramRow(this)"><i
                          class="bi bi-x-lg"></i></button></div>
                  </div>
                </div>
              </div>

              <div class="divider"></div>

              <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <label class="form-label text-muted text-xs uppercase m-0"><i class="bi bi-table me-1"></i> Budget
                    Requirements</label>
                  <button class="btn btn-outline-success btn-sm rounded-pill" onclick="addBudgetRowProp()">
                    <i class="bi bi-plus-lg"></i> Add Item
                  </button>
                </div>
                <div class="table-wrapper border">
                  <table id="budget-table-prop" class="table mb-0">
                    <thead>
                      <tr>
                        <th>Item Description</th>
                        <th style="width: 100px;">Price (₱)</th>
                        <th>Details</th>
                        <th style="width: 80px;">Qty</th>
                        <th>Total</th>
                        <th style="width: 50px;"></th>
                      </tr>
                    </thead>
                    <tbody id="budget-body-prop"></tbody>
                    <tfoot>
                      <tr class="bg-surface-sunken">
                        <td colspan="4" class="text-end fw-bold text-dark">Grand Total:</td>
                        <td id="grand-total-prop" class="fw-bold text-success">₱0.00</td>
                        <td></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>

            <div id="saf-form" class="document-form" style="display:none">
              <h4 class="mb-4 text-dark fw-bold"><i class="bi bi-piggy-bank text-success me-2"></i> SAF Request</h4>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase"><i class="bi bi-flag me-1"></i> Project Title
                  <span class="text-danger">*</span></label>
                <input id="saf-title" class="form-control sm" placeholder="Project Title">
              </div>

              <div class="form-group mb-4 bg-surface-sunken p-3 rounded-2xl border">
                <label class="form-label text-muted text-xs uppercase mb-3"><i class="bi bi-check2-square me-1"></i>
                  Fund Category</label>
                <div class="d-flex gap-4">
                  <div class="form-check">
                    <input id="saf-ssc" class="form-check-input saf-cat" type="checkbox" <?php echo ($currentUser['position'] !== 'Supreme Student Council President') ? 'disabled' : ''; ?>>
                    <label class="form-check-label fw-bold" for="saf-ssc">SSC Fund</label>
                  </div>
                  <div class="form-check">
                    <input id="saf-csc" class="form-check-input saf-cat" type="checkbox" <?php echo ($currentUser['position'] !== 'College Student Council President') ? 'disabled' : ''; ?>>
                    <label class="form-check-label fw-bold" for="saf-csc">CSC Fund</label>
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-calendar3 me-1"></i>
                    Request
                    Date <span class="text-danger">*</span></label>
                  <input id="saf-date" type="date" class="form-control sm">
                </div>
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase"><i class="bi bi-calendar-event me-1"></i>
                    Implement Date <span class="text-danger">*</span></label>
                  <input id="saf-impl-date" type="date" class="form-control sm">
                </div>
              </div>

              <div id="row-dept" class="form-group mb-4" style="display:none">
                <label class="form-label text-muted text-xs uppercase"><i class="bi bi-building me-1"></i>
                  Department
                  <span class="text-danger">*</span></label>
                <input type="text" class="form-control sm bg-surface-sunken"
                  value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>" readonly>
                <input type="hidden" id="saf-dept"
                  value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>">
              </div>

              <div class="mb-3">
                <label class="form-label text-muted text-xs uppercase mb-3"><i class="bi bi-cash-stack me-1"></i>
                  Fund
                  Amounts</label>
                <div class="table-wrapper border">
                  <table class="table mb-0 saf-fund-table">
                    <thead>
                      <tr>
                        <th>Fund</th>
                        <th>Available</th>
                        <th>Requested</th>
                        <th class="text-end">Balance</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr id="row-ssc" style="display:none">
                        <td class="align-middle fw-bold">SSC</td>
                        <td class="align-middle"><input id="avail-ssc"
                            class="form-control sm text-center border-0 bg-transparent px-0" type="text"
                            placeholder="₱0" readonly></td>
                        <td>
                          <div class="d-flex flex-column gap-2">
                            <input id="req-ssc" class="form-control sm text-center fw-bold text-primary" type="number"
                              min="0" step="100" value="0" oninput="validateSAFAmount('req-ssc')">
                            <div class="d-flex gap-1">
                              <button type="button"
                                class="btn btn-outline-primary btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-ssc', 100)">+100</button>
                              <button type="button"
                                class="btn btn-outline-primary btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-ssc', 500)">+500</button>
                              <button type="button"
                                class="btn btn-outline-primary btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-ssc', 1000)">+1K</button>
                              <button type="button" class="btn btn-ghost btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-ssc', 'clear')">Clear</button>
                            </div>
                          </div>
                        </td>
                        <td id="bal-ssc" class="align-middle text-end fw-bold">₱0.00</td>
                      </tr>
                      <tr id="row-csc" style="display:none">
                        <td class="align-middle fw-bold">CSC</td>
                        <td class="align-middle"><input id="avail-csc"
                            class="form-control sm text-center border-0 bg-transparent px-0" type="text"
                            placeholder="₱0" readonly></td>
                        <td>
                          <div class="d-flex flex-column gap-2">
                            <input id="req-csc" class="form-control sm text-center fw-bold text-primary" type="number"
                              min="0" step="100" value="0" oninput="validateSAFAmount('req-csc')">
                            <div class="d-flex gap-1">
                              <button type="button"
                                class="btn btn-outline-primary btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-csc', 100)">+100</button>
                              <button type="button"
                                class="btn btn-outline-primary btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-csc', 500)">+500</button>
                              <button type="button"
                                class="btn btn-outline-primary btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-csc', 1000)">+1K</button>
                              <button type="button" class="btn btn-ghost btn-sm px-2 py-1 flex-1 text-2xs rounded-pill"
                                onclick="changeRequestedSAF('req-csc', 'clear')">Clear</button>
                            </div>
                          </div>
                        </td>
                        <td id="bal-csc" class="align-middle text-end fw-bold">₱0.00</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div id="facility-form" class="document-form" style="display:none">
              <h4 class="mb-4 text-dark fw-bold"><i class="bi bi-building text-warning me-2"></i> Facility Request
              </h4>

              <div class="row g-3 mb-4">
                <div class="col-md-8 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Event Name <span
                      class="text-danger">*</span></label>
                  <input type="text" class="form-control sm" id="fac-event-name" placeholder="Name of event">
                </div>
                <div class="col-md-4 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Event Date <span
                      class="text-danger">*</span></label>
                  <input type="date" class="form-control sm" id="fac-event-date">
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Department <span
                      class="text-danger">*</span></label>
                  <input type="text" class="form-control sm bg-surface-sunken"
                    value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>" readonly>
                  <input type="hidden" id="fac-dept"
                    value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>">
                </div>
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Clean/Set-up Committee</label>
                  <input type="text" class="form-control sm" id="fac-cleanup-committee" placeholder="Committee name">
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Contact Person</label>
                  <input type="text" class="form-control sm" id="fac-contact-person"
                    value="<?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?>">
                </div>
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Contact Number</label>
                  <input type="text" class="form-control sm" id="fac-contact-number" placeholder="Phone #">
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-4 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Est. Attendees</label>
                  <input type="number" class="form-control sm" id="fac-attendees" min="0" placeholder="0">
                </div>
                <div class="col-md-4 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Guest / Speaker</label>
                  <input type="text" class="form-control sm" id="fac-guest-speaker" placeholder="Name">
                </div>
                <div class="col-md-4 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Est. Performers</label>
                  <input type="number" class="form-control sm" id="fac-performers" min="0" placeholder="0">
                </div>
              </div>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase">Parking Gate / Plate No.</label>
                <input type="text" class="form-control sm" id="fac-parking" placeholder="Parking details">
              </div>

              <div class="divider"></div>

              <div class="mb-4">
                <label class="form-label text-muted text-xs uppercase mb-3"><i class="bi bi-building-check me-1"></i>
                  Target Facilities</label>
                <div class="facilities-grid">
                  <div class="facility-category">
                    <h6 class="category-title">IT Building</h6>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f1"
                        value="IT Bldg. Theater"><label class="form-check-label" for="fac-f1">IT Bldg.
                        Theater</label>
                    </div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f2"
                        value="IT Bldg Theater Lobby"><label class="form-check-label" for="fac-f2">IT
                        Bldg Theater
                        Lobby</label></div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f3"
                        value="Computer Lab"><label class="form-check-label" for="fac-f3">Computer
                        Lab</label></div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f4"
                        value="IT Seminar Room"><label class="form-check-label" for="fac-f4">IT Seminar
                        Room</label>
                    </div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f5"
                        value="IT Case Room"><label class="form-check-label" for="fac-f5">IT Case
                        Room</label></div>
                  </div>
                  <div class="facility-category">
                    <h6 class="category-title">CHTM</h6>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f6"
                        value="CHTM/ Luid Hall"><label class="form-check-label" for="fac-f6">Luid
                        Hall</label></div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f7"
                        value="CHTM/ Amphitheater"><label class="form-check-label" for="fac-f7">Amphitheater</label>
                    </div>
                  </div>
                  <div class="facility-category">
                    <h6 class="category-title">Sports & Recreation</h6>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f8"
                        value="Tennis Court"><label class="form-check-label" for="fac-f8">Tennis
                        Court</label></div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f11"
                        value="Gym 1 (Basketball Court)"><label class="form-check-label" for="fac-f11">Gym 1</label>
                    </div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f12"
                        value="Gym 2 (Volleyball Court)"><label class="form-check-label" for="fac-f12">Gym 2</label>
                    </div>
                    <div class="form-check"><input class="form-check-input facility-check" type="checkbox" id="fac-f13"
                        value="Aquatic"><label class="form-check-label" for="fac-f13">Aquatic
                        Center</label></div>
                  </div>
                </div>
                <div class="row g-3 mt-3">
                  <div class="col-md-4"><input type="text" class="form-control sm" id="fac-s1"
                      placeholder="Specify Computer Lab"></div>
                  <div class="col-md-4"><input type="text" class="form-control sm" id="fac-s2"
                      placeholder="Specify Other Facility"></div>
                  <div class="col-md-4"><input type="text" class="form-control sm" id="fac-s3"
                      placeholder="Specify Classroom"></div>
                </div>
              </div>

              <div class="divider"></div>

              <div class="mb-4">
                <label class="form-label text-muted text-xs uppercase mb-3"><i class="bi bi-tools me-1"></i>
                  Equipment
                  &
                  Staffing</label>
                <div class="row g-4">
                  <div class="col-md-6 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-3">
                      <div class="form-check mb-0 flex-1"><input class="form-check-input equipment-check"
                          type="checkbox" id="fac-e1" value="Lectern"><label class="form-check-label"
                          for="fac-e1">Lectern</label></div>
                      <input type="number" class="form-control sm w-auto flex-shrink-0" id="fac-q1" min="0"
                        placeholder="Qty" style="max-width: 70px;">
                    </div>
                    <div class="d-flex align-items-center gap-3">
                      <div class="form-check mb-0 flex-1"><input class="form-check-input equipment-check"
                          type="checkbox" id="fac-e4" value="Tables"><label class="form-check-label"
                          for="fac-e4">Tables</label></div>
                      <input type="number" class="form-control sm w-auto flex-shrink-0" id="fac-q3" min="0"
                        placeholder="Qty" style="max-width: 70px;">
                    </div>
                    <div class="d-flex align-items-center gap-3">
                      <div class="form-check mb-0 flex-1"><input class="form-check-input equipment-check"
                          type="checkbox" id="fac-e5" value="Chairs"><label class="form-check-label"
                          for="fac-e5">Chairs</label></div>
                      <input type="number" class="form-control sm w-auto flex-shrink-0" id="fac-q4" min="0"
                        placeholder="Qty" style="max-width: 70px;">
                    </div>
                  </div>
                  <div class="col-md-6 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-3">
                      <div class="form-check mb-0 flex-1"><input class="form-check-input equipment-check"
                          type="checkbox" id="fac-e8" value="Microphone"><label class="form-check-label"
                          for="fac-e8">Microphone</label></div>
                      <input type="number" class="form-control sm w-auto flex-shrink-0" id="fac-q6" min="0"
                        placeholder="Qty" style="max-width: 70px;">
                    </div>
                    <div class="d-flex align-items-center gap-3">
                      <div class="form-check mb-0 flex-1"><input class="form-check-input equipment-check"
                          type="checkbox" id="fac-e9" value="Projector"><label class="form-check-label"
                          for="fac-e9">Projector</label></div>
                      <input type="number" class="form-control sm w-auto flex-shrink-0" id="fac-q7" min="0"
                        placeholder="Qty" style="max-width: 70px;">
                    </div>
                    <div class="form-check mb-0 mt-1"><input class="form-check-input equipment-check" type="checkbox"
                        id="fac-e7" value="Lights and Sounds"><label class="form-check-label" for="fac-e7">Lights and
                        Sounds</label></div>
                  </div>
                </div>
                <div class="row g-3 mt-2">
                  <div class="col-md-6"><input type="text" class="form-control sm" id="fac-o1"
                      placeholder="Other Equipment 1"></div>
                  <div class="col-md-6"><input type="text" class="form-control sm" id="fac-o2"
                      placeholder="Other Equipment 2"></div>
                </div>
              </div>

              <div class="divider"></div>

              <div class="mb-4">
                <label class="form-label text-muted text-xs uppercase mb-3"><i class="bi bi-clock me-1"></i>
                  Timeline
                  Schedule</label>

                <div class="bg-surface-sunken p-3 rounded-2xl border mb-3">
                  <div class="row g-3 align-items-center">
                    <div class="col-md-3 fw-semibold text-sm">Pre-Event</div>
                    <div class="col-md-3"><input type="date" class="form-control sm" id="fac-pre-event-date"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-pre-event-start"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-pre-event-end"></div>
                  </div>
                </div>
                <div class="bg-surface-sunken p-3 rounded-2xl border mb-3">
                  <div class="row g-3 align-items-center">
                    <div class="col-md-3 fw-semibold text-sm">Practice</div>
                    <div class="col-md-3"><input type="date" class="form-control sm" id="fac-practice-date"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-practice-start"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-practice-end"></div>
                  </div>
                </div>
                <div class="bg-surface-sunken p-3 rounded-2xl border mb-3">
                  <div class="row g-3 align-items-center">
                    <div class="col-md-3 fw-semibold text-sm">Setup</div>
                    <div class="col-md-3"><input type="date" class="form-control sm" id="fac-setup-date"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-setup-start"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-setup-end"></div>
                  </div>
                </div>
                <div class="bg-surface-sunken p-3 rounded-2xl border mb-3">
                  <div class="row g-3 align-items-center">
                    <div class="col-md-3 fw-semibold text-sm">Cleanup</div>
                    <div class="col-md-3"><input type="date" class="form-control sm" id="fac-cleanup-date"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-cleanup-start"></div>
                    <div class="col-md-3"><input type="time" class="form-control sm" id="fac-cleanup-end"></div>
                  </div>
                </div>
              </div>

              <div class="form-group mb-0">
                <label class="form-label text-muted text-xs uppercase">Other Matters / Notes</label>
                <textarea class="form-control" id="fac-other-matters" rows="3"
                  placeholder="Any other specifications..."></textarea>
              </div>
            </div>

            <div id="communication-form" class="document-form" style="display:none">
              <h4 class="mb-4 text-dark fw-bold"><i class="bi bi-envelope text-info me-2"></i> Communication Letter
              </h4>

              <div class="row g-3 mb-4">
                <div class="col-md-4 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Date <span class="text-danger">*</span></label>
                  <input id="comm-date" type="date" class="form-control sm">
                </div>
                <div class="col-md-8 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Department <span
                      class="text-danger">*</span></label>
                  <input type="text" class="form-control sm bg-surface-sunken"
                    value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>" readonly>
                  <input type="hidden" id="comm-department-select"
                    value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>">
                </div>
              </div>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase">For (Recipient)</label>
                <input id="comm-for" class="form-control sm" placeholder="e.g., All Concerned Instructors...">
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Noted By <span
                      class="text-danger">*</span></label>
                  <div id="comm-noted-list" class="bg-surface-sunken border rounded-xl p-3"
                    style="max-height: 200px; overflow-y: auto;">
                  </div>
                </div>
                <div class="col-md-6 form-group mb-0">
                  <label class="form-label text-muted text-xs uppercase">Approved By <span
                      class="text-danger">*</span></label>
                  <div id="comm-approved-list" class="bg-surface-sunken border rounded-xl p-3"
                    style="max-height: 200px; overflow-y: auto;">
                  </div>
                </div>
              </div>

              <div class="form-group mb-4">
                <label class="form-label text-muted text-xs uppercase">Subject <span
                    class="text-danger">*</span></label>
                <input id="comm-subject" class="form-control sm" placeholder="Letter Subject">
              </div>

              <div class="form-group mb-0">
                <label class="form-label text-muted text-xs uppercase">Letter Body <span
                    class="text-danger">*</span></label>
                <textarea id="comm-body" class="form-control" rows="8"
                  placeholder="Type your formal letter here..."></textarea>
                <p class="text-2xs text-muted mt-2"><i class="bi bi-info-circle text-primary"></i> Sender name block
                  will automatically be attached to the bottom.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6 d-flex flex-column">
        <div class="card card-lg flex-1 shadow-inner bg-surface-sunken border-0 h-100 p-4">
          <div class="d-flex flex-column h-100 w-100 align-items-center">

            <div id="paper-container"
              class="paper-container bg-white shadow-md border rounded-sm w-100 flex-1 overflow-y-auto"
              style="max-height: calc(100vh - 300px);">
            </div>

            <div
              class="page-controls mt-4 d-flex justify-content-center gap-3 align-items-center bg-white border rounded-pill px-4 py-2 shadow-sm"
              id="page-controls" style="display:none !important;">
              <button class="btn btn-ghost btn-icon sm rounded-full" onclick="previousPage()"><i
                  class="bi bi-chevron-left"></i></button>
              <span id="page-indicator" class="text-xs fw-bold text-muted uppercase tracking-wider">Page 1 of
                1</span>
              <button class="btn btn-ghost btn-icon sm rounded-full" onclick="nextPage()"><i
                  class="bi bi-chevron-right"></i></button>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="profileSettingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Profile Settings</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="profileSettingsForm">
          <div class="modal-body d-flex flex-col gap-3">
            <div id="profileSettingsMessages"></div>
            <div class="row g-3">
              <div class="col-md-6 form-group mb-0">
                <label for="profileFirstName" class="form-label">First Name</label>
                <input type="text" class="form-control" id="profileFirstName" required <?php if ($currentUser['role'] !== 'admin')
                  echo 'readonly'; ?>>
              </div>
              <div class="col-md-6 form-group mb-0">
                <label for="profileLastName" class="form-label">Last Name</label>
                <input type="text" class="form-control" id="profileLastName" required <?php if ($currentUser['role'] !== 'admin')
                  echo 'readonly'; ?>>
              </div>
            </div>
            <div class="form-group mb-0">
              <label for="profileEmail" class="form-label">Email Address</label>
              <input type="email" class="form-control" id="profileEmail"
                pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" required>
              <div class="invalid-feedback">Please enter a valid email address.</div>
            </div>
            <div class="form-group mb-0">
              <label for="profilePhone" class="form-label">Phone Number</label>
              <input type="tel" class="form-control" id="profilePhone" pattern="^(09|\+639)\d{9}$">
              <div class="invalid-feedback">Please enter a valid Philippine phone number (e.g., 09123456789 or
                +639123456789).</div>
            </div>
            <div class="divider"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="preferencesModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-sliders me-2"></i>Preferences</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body d-flex flex-col gap-4">
          <div id="preferencesMessages"></div>
          <div>
            <label class="form-label text-muted mb-2">Notification Settings</label>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
              <label class="form-check-label fw-medium ms-1" for="emailNotifications">Email notifications</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="browserNotifications" checked>
              <label class="form-check-label fw-medium ms-1" for="browserNotifications">Browser
                notifications</label>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="defaultView" class="form-label">Default Calendar View</label>
            <select class="form-select" id="defaultView">
              <option value="month">Month View</option>
              <option value="week">Week View</option>
              <option value="agenda">Agenda View</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="helpModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i>Help & Support</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
          <div class="p-4 bg-surface-sunken border-bottom">
            <h6 class="fw-bold mb-2">Creating Documents</h6>
            <p class="text-sm text-muted mb-0">Select your document type from the dropdown, fill out the required
              fields
              in the left editor panel, and view the live rendering on the right.</p>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="confirmModalLabel">Confirm Action</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="confirmModalMessage" class="m-0 text-sm">Are you sure you want to proceed?</p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="confirmModalBtn">Confirm</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/ui-helpers.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/comments-manager.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/signature-manager.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/pdf-viewer.js"></script>
  <script src="<?php echo BASE_URL; ?>assets/js/create-document.js"></script>

  <script>
    // Fetch employees for Communication Dropdowns 
    fetch(BASE_URL + 'api/employees.php')
      .then(response => response.ok ? response.json() : Promise.reject(response.status))
      .then(data => {
        if (!data.success || !data.employees) return;
        const notedList = document.getElementById('comm-noted-list');
        const approvedList = document.getElementById('comm-approved-list');
        notedList.innerHTML = ''; approvedList.innerHTML = '';

        data.employees.forEach(emp => {
          const personData = {
            name: emp.first_name + ' ' + emp.last_name,
            title: emp.position + (emp.department ? ', ' + emp.department : '')
          };
          const value = JSON.stringify(personData).replace(/'/g, "&apos;");
          const lbl = `${emp.first_name} ${emp.last_name} (${emp.position}${emp.department ? ' - ' + emp.department : ''})`;

          notedList.innerHTML += `<div class="form-check mb-2"><input class="form-check-input" type="checkbox" value='${value}' id="noted-${emp.id}"><label class="form-check-label text-sm fw-medium" for="noted-${emp.id}">${lbl}</label></div>`;
          approvedList.innerHTML += `<div class="form-check mb-2"><input class="form-check-input" type="checkbox" value='${value}' id="approved-${emp.id}"><label class="form-check-label text-sm fw-medium" for="approved-${emp.id}">${lbl}</label></div>`;
        });
      }).catch(err => console.error(err));
  </script>
</body>

</html>