<?php
declare(strict_types=1);

// Load configuration to get app name
$deps = require __DIR__ . '/bootstrap.php';
$appName = $deps['config']['app']['app_name'] ?? 'KB Assessor';

// Try to obtain a Settings instance from dependencies first, fall back to constructing safely.
$settings = $deps['settings'] ?? null;

if (!($settings instanceof App\Settings)) {
    // Only attempt to construct if the class exists; pass a known DB dependency if available.
    if (class_exists(\App\Settings::class)) {
        $dbParam = $deps['db'] ?? null;
        try {
            $settings = new \App\Settings($dbParam);
        } catch (\Throwable $e) {
            // Failed to construct Settings — keep null and continue with defaults.
            $settings = null;
        }
    }
}

// Normalize ServiceNow base URL (no trailing slash)
$defaultServiceNow = 'https://milestonedemo3.service-now.com';
if ($settings && method_exists($settings, 'get')) {
    $serviceNowBase = rtrim((string)$settings->get('servicenow.base_url', $defaultServiceNow), '/');
} else {
    $serviceNowBase = rtrim($defaultServiceNow, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link id="theme-css"
        href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4 shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center">
            <i class="fas fa-book-open me-2"></i>
            <span class="fw-bold"><?= htmlspecialchars($appName) ?></span>
        </span>
        <div class="ms-auto d-flex align-items-center gap-3">
            <div class="d-none d-md-flex align-items-center text-light">
                <i class="fas fa-clock me-2"></i>
                <span class="small" id="last-fetch">Last fetch: never</span>
            </div>
            <a href="settings.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-cog me-1"></i>
                Settings
            </a>
        </div>
    </div>
</nav>
<main class="container-fluid mb-5">
    <!-- Metrics panels moved above controls -->
    <div class="row g-3 mb-4" id="metrics-row">
      <div class="col-6 col-md-4 col-lg-2">
        <div class="card metric-card metric-total h-100" role="button" tabindex="0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="metric-icon">
                <i class="fas fa-file-alt"></i>
              </div>
              <div class="metric-value" id="metric-total">0</div>
            </div>
            <div class="metric-label">Total Articles</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="card metric-card metric-needs h-100" role="button" tabindex="0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="metric-icon">
                <i class="fas fa-exclamation-triangle"></i>
              </div>
              <div class="metric-value" id="metric-needs">0</div>
            </div>
            <div class="metric-label">Needs Review</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="card metric-card metric-assessed h-100" role="button" tabindex="0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="metric-icon">
                <i class="fas fa-check-circle"></i>
              </div>
              <div class="metric-value" id="metric-assessed">0</div>
            </div>
            <div class="metric-label">Assessed</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="card metric-card metric-running h-100" role="button" tabindex="0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="metric-icon">
                <i class="fas fa-spinner fa-pulse"></i>
              </div>
              <div class="metric-value" id="metric-running">0</div>
            </div>
            <div class="metric-label">Running</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="card metric-card metric-errors h-100" role="button" tabindex="0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="metric-icon">
                <i class="fas fa-times-circle"></i>
              </div>
              <div class="metric-value" id="metric-errors">0</div>
            </div>
            <div class="metric-label">Errors</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="card metric-card metric-notassessed h-100" role="button" tabindex="0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="metric-icon">
                <i class="fas fa-clock"></i>
              </div>
              <div class="metric-value" id="metric-notassessed">0</div>
            </div>
            <div class="metric-label">Not Assessed</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Centered controls row -->
    <div id="controls-row" class="row g-4 align-items-center mb-4">
        <div class="col-12 text-center">
            <button id="fetch-updates" class="btn btn-primary me-2">
                <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                <i class="fas fa-sync-alt me-1"></i>
                Fetch KB Updates
            </button>
            <button id="fetch-all" class="btn btn-outline-primary me-2">
                <i class="fas fa-download me-1"></i>
                Fetch All
            </button>
            <button id="assess-selected" class="btn btn-success me-2" disabled>
                <i class="fas fa-check-double me-1"></i>
                Assess Selected
            </button>
            <button id="cancel-assessment" class="btn btn-outline-secondary me-2" disabled>
                <i class="fas fa-ban me-1"></i>
                Cancel
            </button>
            <button id="delete-selected" class="btn btn-danger" disabled>
                <i class="fas fa-trash-alt me-1"></i>
                Delete Selected
            </button>
        </div>
    </div>

    <!-- Search and Filter Controls -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" class="form-control" id="search-box" placeholder="Search by KB number or description..." aria-label="Search articles">
                <button class="btn btn-outline-secondary" type="button" id="clear-search" title="Clear search">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="status-filter" aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="done">Completed</option>
                <option value="running">Running</option>
                <option value="error">Error</option>
                <option value="queued">Queued</option>
                <option value="not_assessed">Not Assessed</option>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="verdict-filter" aria-label="Filter by verdict">
                <option value="">All Verdicts</option>
                <option value="current">Current</option>
                <option value="needs_review">Needs Review</option>
                <option value="not_assessed">Not Assessed</option>
            </select>
        </div>
    </div>

    <div class="progress mb-3 d-none" id="assessment-progress-wrapper" aria-hidden="true">
        <div
            id="assessment-progress"
            class="progress-bar progress-bar-striped progress-bar-animated"
            role="progressbar"
            style="width: 0%;"
            aria-valuenow="0"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            0%
        </div>
    </div>

    <div class="table-responsive mb-3">
        <table class="table table-hover align-middle" id="articles-table">
            <thead class="table-light">
            <tr>
                <th scope="col">
                    <input type="checkbox" id="select-all" aria-label="Select all articles">
                </th>
                <th scope="col" class="sortable" data-sort="kb_number">
                    Number <span class="sort-indicator" aria-label="Sort indicator"></span>
                </th>
                <th scope="col" class="sortable" data-sort="short_description">
                    Short Description <span class="sort-indicator" aria-label="Sort indicator"></span>
                </th>
                <th scope="col" class="sortable" data-sort="sys_updated_on">
                    Updated <span class="sort-indicator" aria-label="Sort indicator"></span>
                </th>
                <th scope="col" class="sortable" data-sort="last_assessed_at">
                    Latest Assessment <span class="sort-indicator" aria-label="Sort indicator"></span>
                </th>
                <th scope="col" class="text-center sortable" data-sort="verdict_current">
                    Verdict <span class="sort-indicator" aria-label="Sort indicator"></span>
                </th>
            </tr>
            </thead>
            <tbody>
            <tr id="empty-state">
                <td colspan="6" class="text-center py-5">
                    <div class="text-muted mb-3">
                        <svg width="64" height="64" fill="currentColor" class="mb-3" viewBox="0 0 16 16">
                            <path d="M5 10.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/>
                            <path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2z"/>
                            <path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1z"/>
                        </svg>
                    </div>
                    <h5 class="mb-2">No Articles Yet</h5>
                    <p class="text-muted mb-3">Get started by fetching knowledge base articles from ServiceNow</p>
                    <button class="btn btn-primary" onclick="document.getElementById('fetch-updates').click()">
                        <i class="fas fa-download me-2"></i>Fetch KB Updates
                    </button>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <!-- Toast Container for Notifications -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <div id="alerts"></div>
</main>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-confirm">
      <div class="modal-header">
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <div class="modal-icon" id="confirm-icon">
          <i class="fas fa-question-circle"></i>
        </div>
        <h5 class="modal-title mb-3" id="confirmModalLabel">Confirm Action</h5>
        <p id="confirm-message">Are you sure you want to proceed?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirm-action-btn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Assessment details modal -->
<div class="modal fade" id="assessmentModal" tabindex="-1" aria-labelledby="assessmentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="assessmentModalLabel">Assessment details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="assessment-loading" class="text-center my-3 d-none">
          <div class="spinner-border" role="status" aria-hidden="true"></div>
          <div class="mt-2">Loading assessment...</div>
        </div>

        <div id="assessment-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="assessment-content" class="d-none">
          <h6>Verdict</h6>
          <div id="assessment-verdict" class="mb-3"></div>

          <h6>Recommendations</h6>
          <div id="assessment-recommendations-container" class="mb-3">
            <div id="recommendation-controls" class="mb-2 d-none">
              <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="select-all-recommendations">
                <i class="fas fa-check-square me-1"></i>Select All
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="unselect-all-recommendations">
                <i class="fas fa-square me-1"></i>Unselect All
              </button>
            </div>
            <ul id="assessment-recommendations" class="list-unstyled"></ul>
          </div>

          <hr>

        </div>
      </div>
      <div class="modal-footer">
        <button id="apply-recommendations" type="button" class="btn btn-primary d-none">
          <i class="fas fa-magic me-1"></i>Apply Selected Changes
        </button>
        <button id="mark-current" type="button" class="btn btn-success">Mark as Current</button>
        <button id="assessment-close" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<script>
window.APP_CONFIG = window.APP_CONFIG || {};
window.APP_CONFIG.service_now_base_url = <?php echo json_encode($serviceNowBase, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    crossorigin="anonymous"
></script>
<script src="app.js" type="module"></script>
</body>
</html>