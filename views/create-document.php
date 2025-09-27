<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
  logoutUser();
  header('Location: user-login.php');
  exit();
}

// Restrict to students only
if ($currentUser['role'] !== 'student') {
    header('Location: user-login.php?error=access_denied');
    exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Log page view
addAuditLog('CREATE_DOCUMENT_VIEWED', 'Document Management', 'Viewed create document page', $currentUser['id'], 'User', 'INFO');

// Debug: Log user data (optional, for development)
error_log("DEBUG create-document.php: Current user data: " . json_encode($currentUser));
error_log("DEBUG create-document.php: Session data: " . json_encode($_SESSION));
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
  <title>Sign-um - Document Creator</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" />
  <!-- Custom CSS -->
  <link rel="stylesheet" href="../assets/css/global.css"> <!-- Global shared UI styles -->
  <link rel="stylesheet" href="../assets/css/event-calendar.css"> <!-- Reuse shared navbar/dropdown/modal styles -->
  <link rel="stylesheet" href="../assets/css/create-document.css"> <!-- Updated path -->
  <link rel="stylesheet" href="../assets/css/toast.css">

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
  </script>
</head>

<body class="with-fixed-navbar">
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg fixed-top">
    <div class="container-fluid">
      <div class="navbar-brand">
        <i class="bi bi-file-text me-2"></i>
        Sign-um | Document Creator
      </div>

      <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
        <!-- User Info -->
        <div class="user-info me-3">
          <i class="bi bi-person-circle me-2"></i>
          <span id="userDisplayName"><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
          <span class="badge ms-2 <?php
          echo ($currentUser['role'] === 'admin') ? 'bg-danger' :
              (($currentUser['role'] === 'employee') ? 'bg-primary' : 'bg-success');
          ?>" id="userRoleBadge">
            <?php echo strtoupper($currentUser['role']); ?>
          </span>
        </div>

        <!-- Back to Calendar -->
        <!-- <div class="me-3">
          <a href="calendar.html" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-2"></i>Back to Calendar
          </a>
        </div> -->

        <!-- <div class="me-3">
          <button class="btn btn-outline-secondary btn-sm" onclick="goBack()" title="Go Back">
            <i class="bi bi-arrow-left me-2"></i>Back
          </button>
        </div> -->
        <!-- Settings Dropdown -->
        <div class="dropdown me-3">
          <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-gear me-2"></i>Settings
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="event-calendar.php"><i class="bi bi-calendar-event me-2"></i>Calendar</a></li>
            <li><a class="dropdown-item" href="track-document.php"><i class="bi bi-file-earmark-check me-2"></i>Track Documents</a></li>
            <li><a class="dropdown-item" href="upload-publication.php"><i class="bi bi-cloud-upload me-2"></i>Upload Materials</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="user-logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </nav>

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

            <div class="divider"></div>

            <button class="btn btn-success" onclick="generateDocument()">
              <i class="bi bi-play-fill me-2"></i>Generate
            </button>
            <button class="btn btn-outline-primary" onclick="printDocument()">
              <i class="bi bi-printer me-2"></i>Print
            </button>
            <button id="togglePreviewBtn" class="btn btn-outline-secondary" onclick="togglePreview()">
              <i class="bi bi-eye me-2"></i>Preview
            </button>
            <button class="btn btn-success" onclick="submitDocument()">
              <i class="bi bi-check-circle me-2"></i>Create Document
            </button>
          </div>

          <div class="preview-status">
            <div class="status-indicator">
              <div class="status-dot"></div>
              <span class="status-text">Live preview updates automatically</span>
            </div>
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
                      <i class="bi bi-calendar3"></i>Date
                    </label>
                    <input id="prop-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">
                      <i class="bi bi-person-badge"></i>Project Organizer
                    </label>
                    <input id="prop-organizer" class="form-control" placeholder="Enter project organizer name">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">
                      <i class="bi bi-building"></i>Department
                    </label>
                    <select id="prop-department" class="form-select">
                      <option value="">Select Department</option>
                      <option value="engineering">College of Engineering</option>
                      <option value="business">College of Business</option>
                      <option value="education">College of Arts, Social Sciences, and Education</option>
                      <option value="arts">College of Arts, Social Sciences, and Education</option>
                      <option value="science">College of Computing and Information Sciences</option>
                      <option value="nursing">College of Nursing</option>
                      <!-- Add more as needed -->
                    </select>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-flag"></i>Project Title
                    </label>
                    <input id="prop-title" class="form-control" placeholder="Enter project title">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-person-check"></i>Lead Facilitator
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
                      <i class="bi bi-list-check"></i>Objectives (one per line)
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
                    <label class="form-label">Venue</label>
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

                <div class="mt-3">
                  <label class="form-label">Program Schedule (detailed rows)</label>
                  <div id="program-rows-prop" class="mt-2">
                    <div class="program-row">
                      <div class="time-selector" onclick="openTimeSelector(this)"><span
                          class="time-display">Start</span><i class="bi bi-clock ms-2"></i></div>
                      <div class="time-selector" onclick="openTimeSelector(this)"><span
                          class="time-display">End</span><i class="bi bi-clock ms-2"></i></div>
                      <div><input type="text" class="activity-input form-control" placeholder="Activity description">
                      </div>
                      <div><button class="btn btn-sm btn-danger" onclick="removeProgramRow(this)">×</button></div>
                    </div>
                  </div>
                  <div class="mt-2"><button class="btn btn-sm btn-success" onclick="addProgramRowProp()">+ Add
                      Activity</button></div>
                </div>

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
                      <i class="bi bi-building"></i>College / Department
                    </label>
                    <select id="saf-dept" class="form-select">
                      <option value="">Select Department</option>
                      <option value="engineering">College of Engineering</option>
                      <option value="business">College of Business Administration</option>
                      <option value="education">College of Education</option>
                      <option value="arts">College of Arts & Sciences</option>
                      <option value="science">College of Computer Science</option>
                      <option value="nursing">College of Nursing</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-flag"></i>Project Title
                    </label>
                    <input id="saf-title" class="form-control" placeholder="Project Title">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-4">
                    <label class="form-label">
                      <i class="bi bi-calendar3"></i>Date Requested
                    </label>
                    <input id="saf-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-8">
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
                      <div class="form-check">
                        <input id="saf-cca" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-cca">CCA</label>
                      </div>
                      <div class="form-check">
                        <input id="saf-ex" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-ex">Exemplar</label>
                      </div>
                      <div class="form-check">
                        <input id="saf-osa" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-osa">Office of Student Affairs</label>
                      </div>
                      <div class="form-check">
                        <input id="saf-idev" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-idev">Idev</label>
                      </div>
                      <div class="form-check">
                        <input id="saf-others" class="form-check-input saf-cat" type="checkbox">
                        <label class="form-check-label" for="saf-others">Others</label>
                      </div>
                    </div>
                    <input id="saf-others-text" class="form-control mt-3" placeholder="If Others, specify">
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
                          <td><input id="avail-ssc" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-ssc', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-ssc" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-ssc', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-ssc" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-csc" style="display:none">
                          <td>CSC</td>
                          <td><input id="avail-csc" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-csc', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-csc" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-csc', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-csc" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-cca" style="display:none">
                          <td>CCA</td>
                          <td><input id="avail-cca" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-cca', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-cca" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-cca', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-cca" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-ex" style="display:none">
                          <td>Exemplar</td>
                          <td><input id="avail-ex" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-ex', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-ex" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-ex', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-ex" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-osa" style="display:none">
                          <td>Office of Student Affairs</td>
                          <td><input id="avail-osa" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-osa', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-osa" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-osa', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-osa" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-idev" style="display:none">
                          <td>Idev</td>
                          <td><input id="avail-idev" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-idev', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-idev" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-idev', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-idev" class="text-end">₱0.00</td>
                        </tr>
                        <tr id="row-others" style="display:none">
                          <td>Others</td>
                          <td><input id="avail-others" class="form-control" type="number" min="0" step="1000"
                              placeholder="0"></td>
                          <td>
                            <div class="input-group">
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-others', -1000)">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input id="req-others" class="form-control text-center" type="number" min="0" step="1000"
                                value="0" readonly>
                              <button class="btn btn-outline-secondary" type="button"
                                onclick="changeRequestedSAF('req-others', 1000)">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </td>
                          <td id="bal-others" class="text-end">₱0.00</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="small text-muted mt-2">Check categories to enable the inputs. Amount controls change by
                  ₱1,000 per click.</div>
              </div>

              <!-- === Facility Request === -->
              <div id="facility-form" class="form-section document-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-building me-2"></i>Facility Request</h5>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Requested By (Name)</label>
                    <input id="fac-name" class="form-control" placeholder="Name">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Date Needed</label>
                    <input id="fac-date" type="date" class="form-control">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Facility</label>
                    <input id="fac-facility" class="form-control" placeholder="Facility">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Purpose / Notes</label>
                    <textarea id="fac-notes" class="form-control" rows="4" placeholder="Purpose"></textarea>
                  </div>
                </div>
              </div>

              <!-- === Communication Letter === -->
              <div id="communication-form" class="form-section document-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-envelope me-2"></i>Communication Letter</h5>

                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Date</label>
                    <input id="comm-date" type="date" class="form-control">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">For (Recipients)</label>
                    <div id="for-list">
                      <div class="person-entry">
                        <input class="form-control form-control-sm person-name" placeholder="Recipient name">
                        <input class="form-control form-control-sm title-input" placeholder="Title (e.g., President)">
                      </div>
                    </div>
                    <div class="mt-1">
                      <button class="btn btn-sm btn-outline-primary" onclick="addPerson('for-list')"><i
                          class="bi bi-plus"></i> Add</button>
                      <button class="btn btn-sm btn-outline-danger" onclick="removePerson('for-list')"><i
                          class="bi bi-dash"></i> Remove</button>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">From (Senders)</label>
                    <div id="from-list">
                      <div class="person-entry">
                        <input class="form-control form-control-sm person-name" placeholder="Sender name">
                        <input class="form-control form-control-sm title-input" placeholder="Title">
                      </div>
                    </div>
                    <div class="mt-1">
                      <button class="btn btn-sm btn-outline-primary" onclick="addPerson('from-list')"><i
                          class="bi bi-plus"></i> Add</button>
                      <button class="btn btn-sm btn-outline-danger" onclick="removePerson('from-list')"><i
                          class="bi bi-dash"></i> Remove</button>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">Noted (Optional)</label>
                    <div id="noted-list"></div>
                    <div class="mt-1">
                      <button class="btn btn-sm btn-outline-primary" onclick="addPerson('noted-list')"><i
                          class="bi bi-plus"></i> Add</button>
                      <button class="btn btn-sm btn-outline-danger" onclick="removePerson('noted-list')"><i
                          class="bi bi-dash"></i> Remove</button>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Approved (Optional)</label>
                    <div id="approved-list"></div>
                    <div class="mt-1">
                      <button class="btn btn-sm btn-outline-primary" onclick="addPerson('approved-list')"><i
                          class="bi bi-plus"></i> Add</button>
                      <button class="btn btn-sm btn-outline-danger" onclick="removePerson('approved-list')"><i
                          class="bi bi-dash"></i> Remove</button>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Subject</label>
                    <input id="comm-subject" class="form-control" placeholder="Subject">
                    <div style="height:1px;background:#e9ecef;margin-top:.5rem;margin-bottom:.9rem;width:85%"></div>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Letter Body</label>
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
  <script src="../assets/js/toast.js"></script>

  <!-- Custom JavaScript -->
  <script src="../assets/js/create-document.js"></script>

  <script>
    // Add back function
    function goBack() {
      if (document.referrer && document.referrer.includes(window.location.hostname)) {
        window.history.back();
      } else {
        window.location.href = 'event-calendar.php';  // Default to calendar if no referrer
      }
    }
  </script>
</body>

</html>