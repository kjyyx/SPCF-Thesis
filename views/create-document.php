<?php
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
  header('Location: ' . BASE_URL . 'login?error=access_denied');
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
      $severity
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
  <link rel="icon" type="image/jpeg" href="<?php echo BASE_URL; ?>assets/images/sign-um-favicon.jpg">
  <title>Sign-um - Document Creator</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" />
  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/global.css"> <!-- Global shared UI styles -->
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/event-calendar.css"> <!-- Reuse shared navbar/dropdown/modal styles -->
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/create-document.css"> <!-- Updated path -->
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/toast.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/global-notifications.css"><!-- Global notifications styles -->

  <script>
    // Pass user data to JavaScript (for consistency with event-calendar.php)
    window.currentUser = <?php
    // Convert snake_case to camelCase for JavaScript
    $jsUser = $currentUser;
    $jsUser['firstName'] = $currentUser['first_name'];
    $jsUser['lastName'] = $currentUser['last_name'];
    $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
    echo json_encode($jsUser);
    ?>;
    window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;
    window.BASE_URL = "<?php echo BASE_URL; ?>";

    // REMOVE: Duplicate loadNotifications function and interval (handled by global-notifications.js)
  </script>
</head>

<body class="with-fixed-navbar">
  <?php
  // Set page title for navbar
  $pageTitle = 'Document Creator';
  include ROOT_PATH . 'includes/navbar.php';
  include ROOT_PATH . 'includes/notifications.php';
  ?>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Page Header -->
    <div class="page-header-section">
      <div class="container-fluid">
        <div class="page-header-content">
          <h1 class="page-title">
            <i class="bi bi-file-plus me-3"></i>
            Create Document for Signing
          </h1>
          <p class="page-subtitle">Generate and prepare documents for digital signature workflow</p>
        </div>
      </div>
    </div>

    <!-- Document Controls -->
    <div class="document-controls">
      <div class="container-fluid">
        <div class="controls-actions">
          <div class="action-controls">
            <!-- Document Type Selector -->
            <div class="document-type-selector">
              <div class="btn-group">
                <button id="documentTypeDropdown" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                  <i class="bi bi-folder2-open me-2"></i>Project Proposal
                </button>
                <ul class="dropdown-menu">
                  <li><a class="dropdown-item" href="#" onclick="selectDocumentType('proposal')">
                      <i class="bi bi-journal-text me-2"></i>Project Proposal
                    </a></li>
                  <li><a class="dropdown-item" href="#" onclick="selectDocumentType('saf')">
                      <i class="bi bi-piggy-bank me-2"></i>SAF Request
                    </a></li>
                  <li><a class="dropdown-item" href="#" onclick="selectDocumentType('facility')">
                      <i class="bi bi-building me-2"></i>Facility Request
                    </a></li>
                  <li><a class="dropdown-item" href="#" onclick="selectDocumentType('communication')">
                      <i class="bi bi-envelope me-2"></i>Communication Letter
                    </a></li>
                </ul>
              </div>
            </div>

            <button class="btn btn-success" onclick="submitDocument()">
              <i class="bi bi-check-circle me-2"></i>Create Document
            </button>
          </div>


        </div>
      </div>
    </div>

    <!-- Document Editor Container -->
    <div class="document-container">
      <div class="container-fluid">
        <div class="editor-wrapper">
          <!-- Editor Panel -->
          <div class="editor-panel">
            <div class="editor-content">
              <!-- === Project Proposal Form === -->
              <div id="proposal-form" class="form-section document-form">
                <h5 class="mb-3"><i class="bi bi-journal-text me-2"></i>Project Proposal</h5>

                <div class="row g-3">
                  <div class="col-md-3">
                    <label class="form-label">
                      <i class="bi bi-calendar3"></i>Date <span class="required">*</span>
                    </label>
                    <input id="prop-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">
                      <i class="bi bi-person-badge"></i>Project Organizer <span class="required">*</span>
                    </label>
                    <input id="prop-organizer" class="form-control" placeholder="Enter project organizer name">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">
                      <i class="bi bi-building"></i>Department <span class="required">*</span>
                    </label>
                    <select id="prop-department" class="form-select">
                      <option value="">Select Department</option>
                      <option value="College of Arts, Social Sciences, and Education">College of Arts, Social Sciences, and Education (CASSED)</option>
                      <option value="College of Business">College of Business (COB)</option>
                      <option value="College of Computing and Information Sciences">College of Computing and Information Sciences (CCIS)</option>
                      <option value="College of Criminology">College of Criminology (COC)</option>
                      <option value="College of Engineering">College of Engineering (COE)</option>
                      <option value="College of Hospitality and Tourism Management">College of Hospitality and Tourism Management (CHTM)</option>
                      <option value="College of Nursing">College of Nursing (CON)</option>
                      <option value="SPCF Miranda">SPCF Miranda (MIRANDA)</option>
                      <option value="Supreme Student Council">Supreme Student Council (SSC)</option>
                    </select>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-flag"></i>Project Title <span class="required">*</span>
                    </label>
                    <input id="prop-title" class="form-control" placeholder="Enter project title">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-person-check"></i>Lead Facilitator <span class="required">*</span>
                    </label>
                    <input id="prop-lead" class="form-control" placeholder="Enter lead facilitator name">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">
                      <i class="bi bi-card-text"></i>Rationale
                    </label>
                    <textarea id="prop-rationale" class="form-control" rows="4"
                      placeholder="Describe the purpose and justification for this project..."></textarea>
                    <div class="form-text">Explain why this project is needed and its importance.</div>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-list-check"></i>Objectives (one per line) <span class="required">*</span>
                    </label>
                    <textarea id="prop-objectives" class="form-control" rows="5"
                      placeholder="1. Objective one&#10;2. Objective two&#10;3. Objective three"></textarea>
                    <div class="form-text">List specific, measurable goals for this project.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-mortarboard"></i>Intended Learning Outcomes (one per line)
                    </label>
                    <textarea id="prop-ilos" class="form-control" rows="5"
                      placeholder="1. Students will be able to...&#10;2. Participants will learn to...&#10;3. Attendees will understand..."></textarea>
                    <div class="form-text">Define what participants will learn or achieve.</div>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">Source of Budget</label>
                    <input id="prop-budget-source" class="form-control" placeholder="e.g., SAF, Dept funds, Sponsor">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Venue <span class="required">*</span></label>
                    <input id="prop-venue" class="form-control" placeholder="Venue">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Mechanics</label>
                    <textarea id="prop-mechanics" class="form-control" rows="3"
                      placeholder="Mechanics / How it will run"></textarea>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Schedule (summary)</label>
                    <textarea id="prop-schedule" class="form-control" rows="3"
                      placeholder="Overall schedule summary"></textarea>
                  </div>
                </div>

                <hr class="my-4" style="border: none; height: 1px; background: #e5e7eb;">

                <div class="mt-3">
                  <label class="form-label">Program Schedule (detailed rows)</label>
                  <div id="program-rows-prop" class="mt-2">
                    <div class="program-row">
                      <div><input type="time" class="form-control start-time" value=""></div>
                      <div><input type="time" class="form-control end-time" value=""></div>
                      <div><input type="text" class="activity-input form-control" placeholder="Activity description">
                      </div>
                      <div><button class="btn btn-sm btn-danger" onclick="removeProgramRow(this)">×</button></div>
                    </div>
                  </div>
                  <div class="mt-2"><button class="btn btn-sm btn-success" onclick="addProgramRowProp()">+ Add
                      Activity</button></div>
                </div>

                <hr class="my-4" style="border: none; height: 1px; background: #e5e7eb;">

                <div class="mt-3">
                  <label class="form-label">
                    <i class="bi bi-table"></i>Budget Requirements
                  </label>
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">Add budget items and quantities to calculate total costs</small>
                    <button class="btn btn-sm btn-success" onclick="addBudgetRowProp()">
                      <i class="bi bi-plus"></i> Add Budget Item
                    </button>
                  </div>
                  <div class="table-responsive">
                    <table id="budget-table-prop" class="budget-table">
                      <thead>
                        <tr>
                          <th>Item Description</th>
                          <th>Unit Price (₱)</th>
                          <th>Size/Details</th>
                          <th>Quantity</th>
                          <th>Total (₱)</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody id="budget-body-prop">
                      </tbody>
                      <tfoot>
                        <tr>
                          <td colspan="4" style="text-align:right;font-weight:700">Grand Total:</td>
                          <td id="grand-total-prop">₱0.00</td>
                          <td></td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>
              </div> <!-- end proposal -->

              <!-- === SAF Request === -->
              <div id="saf-form" class="form-section document-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-piggy-bank me-2"></i>SAF Request</h5>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-flag"></i>Project Title <span class="required">*</span>
                    </label>
                    <input id="saf-title" class="form-control" placeholder="Project Title">
                  </div>
                  <div class="col-md-6">
                    <!-- Placeholder -->
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">
                      <i class="bi bi-check-square"></i>Category (check to enable fund row)
                    </label>
                    <div class="category-checkboxes">
                      <div class="form-check">
                        <input id="saf-ssc" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-ssc">SSC</label>
                      </div>
                      <div class="form-check">
                        <input id="saf-csc" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-csc">CSC</label>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-4">
                    <label class="form-label">
                      <i class="bi bi-calendar3"></i>Date Requested <span class="required">*</span>
                    </label>
                    <input id="saf-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">
                      <i class="bi bi-calendar3"></i>Implementation Date <span class="required">*</span>
                    </label>
                    <input id="saf-impl-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-4">
                    <!-- Placeholder for future fields -->
                  </div>
                </div>

                <div id="row-dept" class="row g-3 mt-2" style="display:none">
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-building"></i>College / Department <span class="required">*</span>
                    </label>
                    <select id="saf-dept" class="form-select">
                      <option value="">Select Department</option>
                      <option value="casse" <?php echo ($currentUser['department'] === 'College of Arts, Social Sciences, and Education') ? 'selected' : ''; ?>>College of Arts, Social Sciences, and Education</option>
                      <option value="cob" <?php echo ($currentUser['department'] === 'College of Business') ? 'selected' : ''; ?>>College of Business</option>
                      <option value="ccis" <?php echo ($currentUser['department'] === 'College of Computing and Information Sciences') ? 'selected' : ''; ?>>College of Computing and Information Sciences</option>
                      <option value="coc" <?php echo ($currentUser['department'] === 'College of Criminology') ? 'selected' : ''; ?>>College of Criminology</option>
                      <option value="coe" <?php echo ($currentUser['department'] === 'College of Engineering') ? 'selected' : ''; ?>>College of Engineering</option>
                      <option value="chtm" <?php echo ($currentUser['department'] === 'College of Hospitality and Tourism Management') ? 'selected' : ''; ?>>College of Hospitality and Tourism Management</option>
                      <option value="con" <?php echo ($currentUser['department'] === 'College of Nursing') ? 'selected' : ''; ?>>College of Nursing</option>
                      <option value="miranda" <?php echo ($currentUser['department'] === 'SPCF Miranda') ? 'selected' : ''; ?>>SPCF Miranda</option>
                      <option value="ssc">Supreme Student Council</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <!-- Placeholder -->
                  </div>
                </div>

                <div class="mt-4">
                  <label class="form-label">
                    <i class="bi bi-currency-dollar"></i>Fund Amounts
                  </label>
                  <div class="table-responsive saf-table-container">
                    <table class="table table-bordered saf-fund-table">
                      <thead>
                        <tr>
                          <th>Fund/s</th>
                          <th>Available</th>
                          <th>Requested</th>
                          <th>Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr id="row-ssc" style="display:none">
                          <td>SSC</td>
                          <td><input id="avail-ssc" class="form-control" type="number" min="0" step="1"
                              placeholder="0" readonly></td>
                          <td>
                            <div class="saf-input-wrapper">
                              <input id="req-ssc" class="form-control text-center" type="number" min="0" step="100"
                                value="0" oninput="validateSAFAmount('req-ssc')" placeholder="Enter amount">
                              <div class="saf-quick-buttons">
                                <button type="button" onclick="changeRequestedSAF('req-ssc', 100)">+100</button>
                                <button type="button" onclick="changeRequestedSAF('req-ssc', 500)">+500</button>
                                <button type="button" onclick="changeRequestedSAF('req-ssc', 1000)">+1K</button>
                                <button type="button" onclick="changeRequestedSAF('req-ssc', 'clear')" class="clear-btn">Clear</button>
                              </div>
                            </div>
                          </td>
                          <td id="bal-ssc" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-csc" style="display:none">
                          <td>CSC</td>
                          <td><input id="avail-csc" class="form-control" type="number" min="0" step="1"
                              placeholder="0" readonly></td>
                          <td>
                            <div class="saf-input-wrapper">
                              <input id="req-csc" class="form-control text-center" type="number" min="0" step="100"
                                value="0" oninput="validateSAFAmount('req-csc')" placeholder="Enter amount">
                              <div class="saf-quick-buttons">
                                <button type="button" onclick="changeRequestedSAF('req-csc', 100)">+100</button>
                                <button type="button" onclick="changeRequestedSAF('req-csc', 500)">+500</button>
                                <button type="button" onclick="changeRequestedSAF('req-csc', 1000)">+1K</button>
                                <button type="button" onclick="changeRequestedSAF('req-csc', 'clear')" class="clear-btn">Clear</button>
                              </div>
                            </div>
                          </td>
                          <td id="bal-csc" class="text-end">₱0.00</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="alert alert-info mt-3" style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 1rem;">
                  <small class="text-primary" style="display: flex; align-items: start; gap: 0.5rem;">
                    <i class="bi bi-info-circle" style="margin-top: 2px;"></i>
                    <span>Check categories to enable the inputs. Type amounts directly or use quick add buttons (+100, +500, +1K). Click Clear to reset.</span>
                  </small>
                </div>
              </div>
              
              <!-- === Facility Request === -->
              <div id="facility-form" class="form-section document-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-building me-2"></i>Facility Request</h5>

                <!-- Basic Event Information -->
                <div class="row g-3">
                  <div class="col-md-8">
                    <label class="form-label">Event Name <span class="required">*</span></label>
                    <input type="text" class="form-control" id="fac-event-name" placeholder="Event Name">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Event Date <span class="required">*</span></label>
                    <input type="date" class="form-control" id="fac-event-date">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">Department <span class="required">*</span></label>
                    <select class="form-select" id="fac-dept">
                      <option value="">Select Department</option>
                      <option value="College of Arts, Social Sciences, and Education">College of Arts, Social Sciences, and Education (CASSED)</option>
                      <option value="College of Business">College of Business (COB)</option>
                      <option value="College of Computing and Information Sciences">College of Computing and Information Sciences (CCIS)</option>
                      <option value="College of Criminology">College of Criminology (COC)</option>
                      <option value="College of Engineering">College of Engineering (COE)</option>
                      <option value="College of Hospitality and Tourism Management">College of Hospitality and Tourism Management (CHTM)</option>
                      <option value="College of Nursing">College of Nursing (CON)</option>
                      <option value="SPCF Miranda">SPCF Miranda (MIRANDA)</option>
                      <option value="Supreme Student Council">Supreme Student Council (SSC)</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Clean and Set-up Committee</label>
                    <input type="text" class="form-control" id="fac-cleanup-committee" placeholder="Committee name">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">Contact Person</label>
                    <input type="text" class="form-control" id="fac-contact-person"
                      value="<?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" id="fac-contact-number" placeholder="Contact number">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-4">
                    <label class="form-label">Expected No. of Attendees</label>
                    <input type="number" class="form-control" id="fac-attendees" min="0" placeholder="0">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Guest / Speaker</label>
                    <input type="text" class="form-control" id="fac-guest-speaker" placeholder="Guest/Speaker name">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Expected No. of Performers</label>
                    <input type="number" class="form-control" id="fac-performers" min="0" placeholder="0">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Parking Gate / Plate No.</label>
                    <input type="text" class="form-control" id="fac-parking" placeholder="Parking information">
                  </div>
                </div>

                <!-- Facilities Selection -->
                <div class="mt-4">
                  <label class="form-label"><i class="bi bi-building-check"></i> Facilities to be Used</label>
                  <div class="facilities-grid">
                    <!-- IT Building Facilities -->
                    <div class="facility-category">
                      <h6 class="category-title">IT Building</h6>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f1"
                          value="IT Bldg. Theater">
                        <label class="form-check-label" for="fac-f1">IT Bldg. Theater</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f2"
                          value="IT Bldg Theater Lobby">
                        <label class="form-check-label" for="fac-f2">IT Bldg Theater Lobby</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f3" value="Computer Lab">
                        <label class="form-check-label" for="fac-f3">Computer Lab</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f4"
                          value="IT Seminar Room">
                        <label class="form-check-label" for="fac-f4">IT Seminar Room</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f5" value="IT Case Room">
                        <label class="form-check-label" for="fac-f5">IT Case Room</label>
                      </div>
                    </div>

                    <!-- CHTM Facilities -->
                    <div class="facility-category">
                      <h6 class="category-title">CHTM</h6>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f6"
                          value="CHTM/ Luid Hall">
                        <label class="form-check-label" for="fac-f6">Luid Hall</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f7"
                          value="CHTM/ Amphitheater">
                        <label class="form-check-label" for="fac-f7">Amphitheater</label>
                      </div>
                    </div>

                    <!-- Sports Facilities -->
                    <div class="facility-category">
                      <h6 class="category-title">Sports & Recreation</h6>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f8" value="Tennis Court">
                        <label class="form-check-label" for="fac-f8">Tennis Court</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f9" value="Orchard Bar">
                        <label class="form-check-label" for="fac-f9">Orchard Bar</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f10" value="Andreas">
                        <label class="form-check-label" for="fac-f10">Andreas</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f11"
                          value="Gym 1 (Basketball Court)">
                        <label class="form-check-label" for="fac-f11">Gym 1 (Basketball Court)</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f12"
                          value="Gym 2 (Volleyball Court)">
                        <label class="form-check-label" for="fac-f12">Gym 2 (Volleyball Court)</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f13" value="Aquatic">
                        <label class="form-check-label" for="fac-f13">Aquatic Center</label>
                      </div>
                    </div>

                    <!-- COC Facilities -->
                    <div class="facility-category">
                      <h6 class="category-title">COC</h6>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f14"
                          value="COC / Function Room">
                        <label class="form-check-label" for="fac-f14">Function Room</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f15"
                          value="COC / Fitness Center">
                        <label class="form-check-label" for="fac-f15">Fitness Center</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f16"
                          value="COC / Firing Range">
                        <label class="form-check-label" for="fac-f16">Firing Range</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f17" value="Classroom">
                        <label class="form-check-label" for="fac-f17">Classroom</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f18" value="COC Lab">
                        <label class="form-check-label" for="fac-f18">COC Lab</label>
                      </div>
                    </div>

                    <!-- CON Facilities -->
                    <div class="facility-category">
                      <h6 class="category-title">CON</h6>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f20" value="Nursing Lab">
                        <label class="form-check-label" for="fac-f20">Nursing Lab</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f21"
                          value="CON / Amphitheater">
                        <label class="form-check-label" for="fac-f21">Amphitheater</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f22"
                          value="CON / Lecture Room">
                        <label class="form-check-label" for="fac-f22">Lecture Room</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f23"
                          value="CON / Chapel">
                        <label class="form-check-label" for="fac-f23">Chapel</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input facility-check" type="checkbox" id="fac-f24"
                          value="CON / RVJ Hall">
                        <label class="form-check-label" for="fac-f24">RVJ Hall</label>
                      </div>
                    </div>
                  </div>

                  <!-- Specify Fields -->
                  <div class="row g-3 mt-2">
                    <div class="col-md-4">
                      <label class="form-label">Computer Lab Specify</label>
                      <input type="text" class="form-control" id="fac-s1" placeholder="Specify which computer lab">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Other Facility Specify</label>
                      <input type="text" class="form-control" id="fac-s2" placeholder="Other facility details">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Classroom Specify</label>
                      <input type="text" class="form-control" id="fac-s3" placeholder="Specify classroom">
                    </div>
                  </div>
                </div>

                <!-- Equipment & Staffing -->
                <div class="mt-4">
                  <label class="form-label"><i class="bi bi-tools"></i> Equipment & Staffing Needs</label>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e1" value="Lectern">
                        <label class="form-check-label" for="fac-e1">Lectern</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q1" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e2"
                          value="Chorale Raiser">
                        <label class="form-check-label" for="fac-e2">Chorale Raiser</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q2" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e3" value="Elevator">
                        <label class="form-check-label" for="fac-e3">Elevator</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e4" value="Tables">
                        <label class="form-check-label" for="fac-e4">Tables</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q3" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e5" value="Chairs">
                        <label class="form-check-label" for="fac-e5">Chairs</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q4" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e6" value="Flag">
                        <label class="form-check-label" for="fac-e6">Flag</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q5" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e7"
                          value="Lights and Sounds">
                        <label class="form-check-label" for="fac-e7">Lights and Sounds</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e8" value="Microphone">
                        <label class="form-check-label" for="fac-e8">Microphone</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q6" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e9" value="Projector">
                        <label class="form-check-label" for="fac-e9">Projector</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q7" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e10" value="Technical">
                        <label class="form-check-label" for="fac-e10">Technical Staff</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q8" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                      <div class="form-check">
                        <input class="form-check-input equipment-check" type="checkbox" id="fac-e11"
                          value="Ushers / Usherettes">
                        <label class="form-check-label" for="fac-e11">Ushers / Usherettes</label>
                        <input type="number" class="form-control equipment-qty" id="fac-q9" min="0" placeholder="Qty"
                          style="width: 80px; display: inline-block; margin-left: 10px;">
                      </div>
                    </div>
                  </div>

                  <!-- Other Equipment -->
                  <div class="row g-3 mt-2">
                    <div class="col-md-6">
                      <label class="form-label">Other Equipment 1</label>
                      <input type="text" class="form-control" id="fac-o1" placeholder="Other equipment description">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Other Equipment 2</label>
                      <input type="text" class="form-control" id="fac-o2" placeholder="Other equipment description">
                    </div>
                  </div>
                </div>

                <!-- Event Timeline -->
                <div class="mt-4">
                  <label class="form-label"><i class="bi bi-clock"></i> Event Timeline</label>
                  <div class="row g-3">
                    <div class="col-md-3">
                      <label class="form-label">Pre-Event Date</label>
                      <input type="date" class="form-control" id="fac-pre-event-date">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Practice Date</label>
                      <input type="date" class="form-control" id="fac-practice-date">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Setup Date</label>
                      <input type="date" class="form-control" id="fac-setup-date">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Cleanup Date</label>
                      <input type="date" class="form-control" id="fac-cleanup-date">
                    </div>
                  </div>

                  <div class="row g-3 mt-2">
                    <div class="col-md-3">
                      <label class="form-label">Pre-Event Start Time</label>
                      <input type="time" class="form-control" id="fac-pre-event-start">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Pre-Event End Time</label>
                      <input type="time" class="form-control" id="fac-pre-event-end">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Practice Start Time</label>
                      <input type="time" class="form-control" id="fac-practice-start">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Practice End Time</label>
                      <input type="time" class="form-control" id="fac-practice-end">
                    </div>
                  </div>

                  <div class="row g-3 mt-2">
                    <div class="col-md-3">
                      <label class="form-label">Setup Start Time</label>
                      <input type="time" class="form-control" id="fac-setup-start">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Setup End Time</label>
                      <input type="time" class="form-control" id="fac-setup-end">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Cleanup Start Time</label>
                      <input type="time" class="form-control" id="fac-cleanup-start">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Cleanup End Time</label>
                      <input type="time" class="form-control" id="fac-cleanup-end">
                    </div>
                  </div>
                </div>

                <!-- Other Matters -->
                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Other Matters / Specifications</label>
                    <textarea class="form-control" id="fac-other-matters" rows="3"
                      placeholder="Any other specifications or requirements"></textarea>
                  </div>
                </div>
              </div>

              <!-- === Communication Letter === -->
              <div id="communication-form" class="form-section document-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-envelope me-2"></i>Communication Letter</h5>

                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Date <span class="required">*</span></label>
                    <input id="comm-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Department <span class="required">*</span></label>
                    <select id="comm-department-select" class="form-select">
                      <option value="">Select Department</option>
                      <option value="College of Arts, Social Sciences, and Education">College of Arts, Social Sciences, and Education (CASSED)</option>
                      <option value="College of Business">College of Business (COB)</option>
                      <option value="College of Computing and Information Sciences">College of Computing and Information Sciences (CCIS)</option>
                      <option value="College of Criminology">College of Criminology (COC)</option>
                      <option value="College of Engineering">College of Engineering (COE)</option>
                      <option value="College of Hospitality and Tourism Management">College of Hospitality and Tourism Management (CHTM)</option>
                      <option value="College of Nursing">College of Nursing (CON)</option>
                      <option value="SPCF Miranda">SPCF Miranda (MIRANDA)</option>
                      <option value="Supreme Student Council">Supreme Student Council (SSC)</option>
                    </select>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Project Title <span class="required">*</span></label>
                    <input id="comm-subject" class="form-control" placeholder="Project Title">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Letter Body <span class="required">*</span></label>
                    <textarea id="comm-body" class="form-control" rows="8"
                      placeholder="Write your letter here (closing & signature should be typed manually)"></textarea>
                    <div class="small text-muted mt-1">Note: The sender name/title block was removed from the letter
                      body to allow manual input.</div>
                  </div>
                </div>
              </div>
            </div> <!-- end editor-content -->
          </div> <!-- end editor-panel -->

          <!-- Preview Panel -->
          <div class="preview-panel">
            <div id="paper-container" class="paper-container"></div>

            <div class="page-controls" id="page-controls" style="display:none">
              <button class="btn btn-sm btn-outline-secondary" onclick="previousPage()">
                <i class="bi bi-chevron-left"></i>
              </button>
              <div id="page-indicator" class="small">Page 1 of 1</div>
              <button class="btn btn-sm btn-outline-secondary" onclick="nextPage()">
                <i class="bi bi-chevron-right"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Toast Utils -->
  <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>

  <!-- Custom JavaScript -->
  <script src="<?php echo BASE_URL; ?>assets/js/create-document.js"></script>

  <!-- Profile Settings Modal -->
  <div class="modal fade" id="profileSettingsModal" tabindex="-1" aria-labelledby="profileSettingsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="profileSettingsLabel">
            <i class="bi bi-person-gear me-2"></i>Profile Settings
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="profileSettingsForm">
          <div class="modal-body">
            <div id="profileSettingsMessages"></div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="profileFirstName" class="form-label">First Name</label>
                <input type="text" class="form-control" id="profileFirstName" required>
              </div>
              <div class="col-md-6 mb-3">
                <label for="profileLastName" class="form-label">Last Name</label>
                <input type="text" class="form-control" id="profileLastName" required>
              </div>
            </div>
            <div class="mb-3">
              <label for="profileEmail" class="form-label">Email Address</label>
              <input type="email" class="form-control" id="profileEmail" required>
            </div>
            <div class="mb-3">
              <label for="profilePhone" class="form-label">Phone Number</label>
              <input type="tel" class="form-control" id="profilePhone">
            </div>
            <div class="mb-3">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="darkModeToggle">
                <label class="form-check-label" for="darkModeToggle">
                  Enable Dark Mode
                </label>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Preferences Modal -->
  <div class="modal fade" id="preferencesModal" tabindex="-1" aria-labelledby="preferencesLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="preferencesLabel">
            <i class="bi bi-sliders me-2"></i>Preferences
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="preferencesMessages"></div>
          <div class="mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="emailNotifications">
              <label class="form-check-label" for="emailNotifications">
                Email Notifications
              </label>
            </div>
          </div>
          <div class="mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="browserNotifications">
              <label class="form-check-label" for="browserNotifications">
                Browser Notifications
              </label>
            </div>
          </div>
          <div class="mb-3">
            <label for="defaultView" class="form-label">Default View</label>
            <select class="form-select" id="defaultView">
              <option value="month">Month View</option>
              <option value="week">Week View</option>
              <option value="agenda">Agenda View</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Help & Support Modal -->
  <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="helpLabel">
            <i class="bi bi-question-circle me-2"></i>Help & Support
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="accordion" id="helpAccordion">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#gettingStarted">
                  Getting Started
                </button>
              </h2>
              <div id="gettingStarted" class="accordion-collapse collapse show" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>Welcome to the Document Creator! Here you can:</p>
                  <ul>
                    <li>Create professional documents for various purposes</li>
                    <li>Generate project proposals, SAF requests, facility requests, and communication letters</li>
                    <li>Preview documents in real-time</li>
                    <li>Print or submit documents for approval</li>
                  </ul>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#documentTypes">
                  Document Types
                </button>
              </h2>
              <div id="documentTypes" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p><strong>Project Proposal:</strong> Detailed proposals with budget, program schedule, and objectives</p>
                  <p><strong>SAF Request:</strong> Student Activities Fund requests with category-based funding</p>
                  <p><strong>Facility Request:</strong> Facility reservation requests with date and purpose</p>
                  <p><strong>Communication Letter:</strong> Official communication letters with signature blocks</p>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#creatingDocuments">
                  Creating Documents
                </button>
              </h2>
              <div id="creatingDocuments" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>To create a document:</p>
                  <ol>
                    <li>Select the document type from the dropdown</li>
                    <li>Fill in all required fields in the form</li>
                    <li>Click "Generate" to see the preview</li>
                    <li>Review the document in the preview panel</li>
                    <li>Click "Create Document" to submit for approval</li>
                  </ol>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#budgetManagement">
                  Budget Management
                </button>
              </h2>
              <div id="budgetManagement" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p>For project proposals with budgets:</p>
                  <ul>
                    <li>Add budget items with description, quantity, and price</li>
                    <li>Totals are calculated automatically</li>
                    <li>Specify the budget source</li>
                    <li>All calculations update in real-time</li>
                  </ul>
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#troubleshooting">
                  Troubleshooting
                </button>
              </h2>
              <div id="troubleshooting" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                <div class="accordion-body">
                  <p><strong>Common Issues:</strong></p>
                  <ul>
                    <li><strong>Preview not updating:</strong> Click "Generate" after making changes</li>
                    <li><strong>Form not saving:</strong> Ensure all required fields are filled</li>
                    <li><strong>Print issues:</strong> Use browser's print dialog for best results</li>
                    <li><strong>Access denied:</strong> Contact your administrator for permission issues</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Notifications Modal - OneUI Enhanced -->
  <div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="notificationsModalLabel">
            <i class="bi bi-bell-fill"></i>
            <span>Notifications</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="notificationsList">
            <!-- Notifications will be populated here -->
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAllAsRead()">
            <i class="bi bi-check-all me-2"></i>Mark All Read
          </button>
          <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-2"></i>Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Generic Confirmation Modal -->
  <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="confirmModalLabel">Confirm Action</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p id="confirmModalMessage">Are you sure you want to proceed?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="confirmModalBtn">Confirm</button>
        </div>
      </div>
    </div>
  </div>
</body>

</html>