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
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
  global $currentUser;
  try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $currentUser['id'],
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
          <span
            id="userDisplayName"><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
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
            <li><a class="dropdown-item" href="event-calendar.php"><i class="bi bi-calendar-event me-2"></i>Calendar</a>
            </li>
            <li><a class="dropdown-item" href="track-document.php"><i class="bi bi-file-earmark-check me-2"></i>Track
                Documents</a></li>
            <li><a class="dropdown-item" href="upload-publication.php"><i class="bi bi-cloud-upload me-2"></i>Upload
                Materials</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item text-danger" href="user-logout.php"><i
                  class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
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
                      <option value="criminology">College of Criminology</option>
                      <option value="hospitality">College of Hospitality and Tourism Management</option>
                      <option value="spc">SPCF Miranda</option>
                      <option value="ssc">Supreme Student Council</option>
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
                  <div class="col-md-4">
                    <label class="form-label">
                      <i class="bi bi-calendar3"></i>Implementation Date
                    </label>
                    <input id="saf-impl-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-4">
                    <!-- Placeholder for future fields -->
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
              <!-- === Facility Request === -->
              <div id="facility-form" class="form-section document-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-building me-2"></i>Facility Request</h5>

                <!-- Basic Event Information -->
                <div class="row g-3">
                  <div class="col-md-8">
                    <label class="form-label">Event Name</label>
                    <input type="text" class="form-control" id="fac-event-name" placeholder="Event Name">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Event Date</label>
                    <input type="date" class="form-control" id="fac-event-date">
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <select class="form-select" id="fac-dept">
                      <option value="">Select Department</option>
                      <option value="College of Engineering">College of Engineering</option>
                      <option value="College of Business Administration">College of Business Administration</option>
                      <option value="College of Education">College of Education</option>
                      <option value="College of Arts & Sciences">College of Arts & Sciences</option>
                      <option value="College of Computer Science">College of Computer Science</option>
                      <option value="College of Nursing">College of Nursing</option>
                      <option value="College of Criminology">College of Criminology</option>
                      <option value="College of Hospitality and Tourism Management">College of Hospitality and Tourism
                        Management</option>
                      <option value="Supreme Student Council">Supreme Student Council</option>
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
                    <label class="form-label">Date</label>
                    <input id="comm-date" type="date" class="form-control">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <select id="comm-department-select" class="form-select">
                      <option value="">Select Department</option>
                      <option value="engineering">College of Engineering</option>
                      <option value="business">College of Business</option>
                      <option value="education">College of Arts, Social Sciences, and Education</option>
                      <option value="arts">College of Arts, Social Sciences, and Education</option>
                      <option value="science">College of Computing and Information Sciences</option>
                      <option value="nursing">College of Nursing</option>
                      <option value="criminology">College of Criminology</option>
                      <option value="hospitality">College of Hospitality and Tourism Management</option>
                      <option value="spc">SPCF Miranda</option>
                      <option value="ssc">Supreme Student Council</option>
                    </select>
                  </div>
                </div>

                <div class="row g-3 mt-2">
                  <div class="col-12">
                    <label class="form-label">Project Title</label>
                    <input id="comm-subject" class="form-control" placeholder="Project Title">
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