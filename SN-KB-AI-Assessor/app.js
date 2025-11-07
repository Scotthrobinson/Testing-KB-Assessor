const API = {
    fetch: "api/fetch_kb.php",
    articles: "api/articles.php",
    assess: "api/assess.php",
    progress: "api/progress.php",
    cancel: "api/cancel.php",
    delete: "api/delete.php",
    assessment_details: "api/assessment_details.php",
    mark_current: "api/mark_current.php",
    rewrite: "api/rewrite.php"
};

// Assessment configuration
const ASSESSMENT_DELAY_MS = 250; // Delay between batches (in milliseconds)
const CONCURRENT_ASSESSMENTS = 10; // Number of articles to assess simultaneously

// Use service-now base URL provided by server via window.APP_CONFIG.service_now_base_url
const SERVICE_NOW_BASE = (window.APP_CONFIG && window.APP_CONFIG.service_now_base_url)
    ? String(window.APP_CONFIG.service_now_base_url).replace(/\/+$/, '')
    : 'https://milestonedemo3.service-now.com/';

// Load saved filters from localStorage
function loadSavedFilters() {
    try {
        const saved = localStorage.getItem('kb-assessor-filters');
        if (saved) {
            return JSON.parse(saved);
        }
    } catch (e) {
        console.error('Failed to load saved filters:', e);
    }
    return {
        search: '',
        status: '',
        verdict: ''
    };
}

// Save filters to localStorage
function saveFilters(filters) {
    try {
        localStorage.setItem('kb-assessor-filters', JSON.stringify(filters));
    } catch (e) {
        console.error('Failed to save filters:', e);
    }
}

// Load saved sort state from localStorage
function loadSavedSort() {
    try {
        const saved = localStorage.getItem('kb-assessor-sort');
        if (saved) {
            return JSON.parse(saved);
        }
    } catch (e) {
        console.error('Failed to load saved sort:', e);
    }
    return { key: 'sys_updated_on', dir: 'desc' };
}

// Save sort state to localStorage
function saveSort(sort) {
    try {
        localStorage.setItem('kb-assessor-sort', JSON.stringify(sort));
    } catch (e) {
        console.error('Failed to save sort:', e);
    }
}

const state = {
    articles: [],
    selected: new Set(),
    lastFetch: null,
    isFetching: false,
    isAssessing: false,
    sort: loadSavedSort(),
    progress: {
        total: 0,
        completed: 0
    },
    filters: loadSavedFilters(),
    activeMetricFilter: null,
    lastCheckedIndex: null // For shift-click range selection
};

const elements = {
    tableBody: document.querySelector("#articles-table tbody"),
    tableHead: document.querySelector("#articles-table thead"),
    fetchButton: document.getElementById("fetch-updates"),
    fetchAllButton: document.getElementById("fetch-all"),
    assessButton: document.getElementById("assess-selected"),
    cancelButton: document.getElementById("cancel-assessment"),
    deleteButton: document.getElementById("delete-selected"),
    selectAll: document.getElementById("select-all"),
    alerts: document.getElementById("alerts"),
    lastFetch: document.getElementById("last-fetch"),
    progressWrapper: document.getElementById("assessment-progress-wrapper"),
    progressBar: document.getElementById("assessment-progress"),
    // Metrics
    metricTotal: document.getElementById("metric-total"),
    metricNeeds: document.getElementById("metric-needs"),
    metricAssessed: document.getElementById("metric-assessed"),
    metricRunning: document.getElementById("metric-running"),
    metricErrors: document.getElementById("metric-errors"),
    metricNotAssessed: document.getElementById("metric-notassessed"),
    // Search and filters
    searchBox: document.getElementById("search-box"),
    clearSearch: document.getElementById("clear-search"),
    statusFilter: document.getElementById("status-filter"),
    verdictFilter: document.getElementById("verdict-filter"),
    toastContainer: document.getElementById("toast-container")
};

function formatTimestamp(ts) {
    if (!ts) return "—";
    const date = new Date(ts);
    if (Number.isNaN(date.getTime())) return ts;
    return date.toLocaleString();
}

function verdictBadge(verdict, articleId) {
    if (verdict === undefined || verdict === null) {
        return "—";
    }
    if (verdict) {
        // Make Current clickable too, same size/behaviour as Needs Review
        return `<span role="button" class="badge bg-success text-dark assessment-details-badge" data-article-id="${articleId}" title="View assessment details">Current</span>`;
    }

    // Needs review badge is clickable to show details
    return `<span role="button" class="badge bg-warning text-dark assessment-details-badge" data-article-id="${articleId}" title="View assessment details">Needs Review</span>`;
}

function formatStatus(status, showSpinner = false) {
    if (!status) return '<span class="text-muted">—</span>';
    
    const icons = {
        queued: '<i class="fas fa-clock"></i>',
        running: showSpinner ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="fas fa-cog fa-spin"></i>',
        done: '<i class="fas fa-check-circle"></i>',
        error: '<i class="fas fa-exclamation-circle"></i>'
    };
    
    const labels = {
        queued: 'Queued',
        running: 'Running',
        done: 'Completed',
        error: 'Error'
    };
    
    const icon = icons[status] || '';
    const label = labels[status] || status;
    const badgeClass = `status-badge status-${status}`;
    
    return `<span class="${badgeClass}">${icon} ${label}</span>`;
}

function getStatusClass(status) {
    switch (status) {
        case "running":
            return "running";
        case "done":
            return "done";
        case "error":
            return "error";
        default:
            return "";
    }
}

function sortArticles(items) {
    const { key, dir } = state.sort || {};
    const aDir = dir === 'asc' ? 1 : -1;

    const parseDate = (s) => {
        if (!s) return 0;
        // Try native parse
        let t = Date.parse(s);
        if (!Number.isFinite(t)) {
            // Try "YYYY-MM-DD HH:mm:ss" -> "YYYY-MM-DDTHH:mm:ssZ"
            const isoish = String(s).replace(' ', 'T');
            t = Date.parse(isoish) || Date.parse(isoish + 'Z');
        }
        return Number.isFinite(t) ? t : 0;
    };

    const getVal = (a) => {
        switch (key) {
            case 'kb_number': {
                const s = (a.kb_number || '').toString();
                const num = parseInt(s.replace(/\D+/g, ''), 10);
                // Prefer numeric compare; fallback to string
                return Number.isFinite(num) ? num : s.toLowerCase();
            }
            case 'short_description':
                return (a.short_description || '').toString().toLowerCase();
            case 'sys_updated_on':
                return parseDate(a.sys_updated_on);
            case 'last_assessed_at':
                return parseDate(a.last_assessed_at);
            case 'verdict_current':
                // null -> -1, false -> 0, true -> 1
                return a.verdict_current === null || a.verdict_current === undefined
                    ? -1
                    : (a.verdict_current ? 1 : 0);
            default:
                return 0;
        }
    };

    return items.sort((a, b) => {
        const va = getVal(a);
        const vb = getVal(b);
        if (va < vb) return -1 * aDir;
        if (va > vb) return 1 * aDir;
        return 0;
    });
}

function filterArticles(articles) {
    return articles.filter(article => {
        // Search filter
        if (state.filters.search) {
            const searchLower = state.filters.search.toLowerCase();
            const matchesSearch =
                (article.kb_number || '').toLowerCase().includes(searchLower) ||
                (article.short_description || '').toLowerCase().includes(searchLower);
            if (!matchesSearch) return false;
        }
        
        // Status filter
        if (state.filters.status) {
            if (state.filters.status === 'not_assessed') {
                if (article.last_status && article.last_status !== 'queued') return false;
            } else if (article.last_status !== state.filters.status) {
                return false;
            }
        }
        
        // Verdict filter
        if (state.filters.verdict) {
            if (state.filters.verdict === 'current') {
                if (article.verdict_current !== true) return false;
            } else if (state.filters.verdict === 'needs_review') {
                if (article.verdict_current !== false) return false;
            } else if (state.filters.verdict === 'not_assessed') {
                if (article.verdict_current !== null && article.verdict_current !== undefined) return false;
            }
        }
        
        return true;
    });
}

function renderArticles() {
    if (state.articles.length === 0) {
        elements.tableBody.innerHTML = `
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
                        <span>📥</span> Fetch KB Updates
                    </button>
                </td>
            </tr>
        `;
        return;
    }

    const filtered = filterArticles(state.articles);
    
    if (filtered.length === 0) {
        elements.tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    No articles match your filters. <a href="#" onclick="clearAllFilters(); return false;">Clear filters</a>
                </td>
            </tr>
        `;
        return;
    }

    const items = sortArticles(filtered);

    const rows = items.map((article) => {
        const checked = state.selected.has(article.id) ? "checked" : "";
        const isRunning = article.last_status === 'running';
        const statusText = formatStatus(article.last_status, isRunning);
        const verdict = verdictBadge(article.verdict_current, article.id);
        const rowClass = getStatusClass(article.last_status);
        const classAttr = rowClass ? ` class="${rowClass}"` : "";
        
        // Create ServiceNow article link
        //const articleUrl = `https://universityofbirmingham.service-now.com/kb_view.do?sysparm_article=${article.kb_number}`;
        const articleUrl = `${SERVICE_NOW_BASE}/kb_view.do?sysparm_article=${encodeURIComponent(kbNumber)}`;
        const kbNumberLink = `<a href="${articleUrl}" target="_blank" rel="noopener noreferrer" class="kb-number-link">${article.kb_number}</a>`;

        return `
            <tr data-article-id="${article.id}"${classAttr}>
                <td>
                    <input type="checkbox" class="article-checkbox" value="${article.id}" ${checked} ${state.isAssessing ? "disabled" : ""}>
                </td>
                <td>${kbNumberLink}</td>
                <td>${article.short_description ?? ""}</td>
                <td>${formatTimestamp(article.sys_updated_on)}</td>
                <td data-column="status">${statusText}</td>
                <td data-column="verdict">${verdict}</td>
            </tr>
        `;
    }).join("");

    elements.tableBody.innerHTML = rows;
}

function renderSelectionState() {
    elements.assessButton.disabled = !state.selected.size || state.isAssessing;

    // Enable cancel button if assessments are running (either started by this client or already running on server)
    if (elements.cancelButton) {
        const anyRunning = state.articles.some(a => (a.last_status === 'running'));
        elements.cancelButton.disabled = !anyRunning && !state.isAssessing;
    }

    // Enable delete if any selection and not currently assessing
    if (elements.deleteButton) {
        elements.deleteButton.disabled = state.selected.size === 0 || state.isAssessing;
    }

    // Check select-all based on filtered articles, not all articles
    const filtered = filterArticles(state.articles);
    const allFilteredSelected = filtered.length > 0 && filtered.every(article => state.selected.has(article.id));
    elements.selectAll.checked = allFilteredSelected;
}

function renderFetchButton() {
    const spinner = elements.fetchButton.querySelector(".spinner-border");
    if (state.isFetching) {
        spinner.classList.remove("d-none");
        elements.fetchButton.disabled = true;
    } else {
        spinner.classList.add("d-none");
        elements.fetchButton.disabled = false;
    }
}

function renderMetrics() {
    if (!elements.metricTotal) return;

    const items = state.articles || [];
    const total = items.length;

    let needs = 0;
    let assessed = 0;
    let running = 0;
    let errors = 0;
    let notAssessed = 0;

    for (const a of items) {
        const status = a.last_status || null;
        const verdict = a.verdict_current;

        if (status === 'done') {
            assessed++;
            if (verdict === false) needs++;
        } else if (status === 'running') {
            running++;
        } else if (status === 'error') {
            errors++;
        } else if (status === 'queued') {
            // treat queued as not assessed yet
            notAssessed++;
        } else {
            // no assessments yet
            notAssessed++;
        }
    }

    elements.metricTotal.textContent = String(total);
    elements.metricNeeds.textContent = String(needs);
    elements.metricAssessed.textContent = String(assessed);
    elements.metricRunning.textContent = String(running);
    elements.metricErrors.textContent = String(errors);
    elements.metricNotAssessed.textContent = String(notAssessed);
}

function renderProgress() {
    if (!state.isAssessing || state.progress.total === 0) {
        elements.progressWrapper.classList.add("d-none");
        elements.progressWrapper.setAttribute("aria-hidden", "true");
        elements.progressBar.style.width = "0%";
        elements.progressBar.textContent = "0%";
        elements.progressBar.setAttribute("aria-valuenow", "0");
        return;
    }

    const percent = Math.floor((state.progress.completed / state.progress.total) * 100);
    elements.progressWrapper.classList.remove("d-none");
    elements.progressWrapper.setAttribute("aria-hidden", "false");
    elements.progressBar.style.width = `${percent}%`;
    elements.progressBar.setAttribute("aria-valuenow", String(percent));
    elements.progressBar.textContent = `${percent}%`;
}

function renderLastFetch() {
    const text = state.lastFetch
        ? `Last fetch: ${formatTimestamp(state.lastFetch)}`
        : "Last fetch: never";
    elements.lastFetch.textContent = text;
}
function updateSortIndicators() {
    const headers = elements.tableHead.querySelectorAll('th.sortable');
    headers.forEach((th) => {
        const key = th.getAttribute('data-sort');
        const indicator = th.querySelector('.sort-indicator');
        if (!indicator) return;
        
        th.classList.remove('active');
        if (key === state.sort.key) {
            th.classList.add('active');
            indicator.innerHTML = state.sort.dir === 'asc'
                ? '<i class="fas fa-sort-up"></i>'
                : '<i class="fas fa-sort-down"></i>';
        } else {
            indicator.innerHTML = '<i class="fas fa-sort"></i>';
        }
    });
}


function showAlert(message, type = "danger") {
    const wrapper = document.createElement("div");
    wrapper.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    elements.alerts.appendChild(wrapper.firstElementChild);
}

function showToast(message, type = "info", duration = 3000) {
    const toastId = `toast-${Date.now()}`;
    const icons = {
        success: '<i class="fas fa-check-circle"></i>',
        error: '<i class="fas fa-exclamation-circle"></i>',
        warning: '<i class="fas fa-exclamation-triangle"></i>',
        info: '<i class="fas fa-info-circle"></i>'
    };
    
    const bgClass = {
        success: 'bg-success',
        error: 'bg-danger',
        warning: 'bg-warning',
        info: 'bg-info'
    };
    
    const icon = icons[type] || icons.info;
    const bg = bgClass[type] || bgClass.info;
    
    const toastHTML = `
        <div id="${toastId}" class="toast align-items-center text-white ${bg} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <span class="me-2">${icon}</span>${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    const wrapper = document.createElement('div');
    wrapper.innerHTML = toastHTML;
    const toastElement = wrapper.firstElementChild;
    elements.toastContainer.appendChild(toastElement);
    
    const bsLib = window.bootstrap || globalThis.bootstrap;
    if (bsLib && bsLib.Toast) {
        const toast = new bsLib.Toast(toastElement, { delay: duration });
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
}
function clearAllFilters() {
    state.filters.search = '';
    state.filters.status = '';
    state.filters.verdict = '';
    state.activeMetricFilter = null;
    
    if (elements.searchBox) elements.searchBox.value = '';
    if (elements.statusFilter) elements.statusFilter.value = '';
    if (elements.verdictFilter) elements.verdictFilter.value = '';
    
    // Remove filtered class from all metric cards
    document.querySelectorAll('#metrics-row .card').forEach(card => {
        card.classList.remove('filtered');
    });
    
    saveFilters(state.filters);
    renderArticles();
}

function handleMetricClick(metricType) {
    // Toggle filter
    if (state.activeMetricFilter === metricType) {
        clearAllFilters();
        return;
    }
    
    state.activeMetricFilter = metricType;
    
    // Clear other filters
    state.filters.search = '';
    if (elements.searchBox) elements.searchBox.value = '';
    
    // Remove filtered class from all cards
    document.querySelectorAll('.metric-card').forEach(card => {
        card.classList.remove('filtered');
    });
    
    // Apply filter based on metric type
    switch(metricType) {
        case 'needs':
            state.filters.status = 'done';
            state.filters.verdict = 'needs_review';
            if (elements.statusFilter) elements.statusFilter.value = 'done';
            if (elements.verdictFilter) elements.verdictFilter.value = 'needs_review';
            document.querySelector('.metric-needs')?.classList.add('filtered');
            break;
        case 'assessed':
            state.filters.status = 'done';
            state.filters.verdict = '';
            if (elements.statusFilter) elements.statusFilter.value = 'done';
            if (elements.verdictFilter) elements.verdictFilter.value = '';
            document.querySelector('.metric-assessed')?.classList.add('filtered');
            break;
        case 'running':
            state.filters.status = 'running';
            state.filters.verdict = '';
            if (elements.statusFilter) elements.statusFilter.value = 'running';
            if (elements.verdictFilter) elements.verdictFilter.value = '';
            document.querySelector('.metric-running')?.classList.add('filtered');
            break;
        case 'errors':
            state.filters.status = 'error';
            state.filters.verdict = '';
            if (elements.statusFilter) elements.statusFilter.value = 'error';
            if (elements.verdictFilter) elements.verdictFilter.value = '';
            document.querySelector('.metric-errors')?.classList.add('filtered');
            break;
        case 'notassessed':
            state.filters.status = 'not_assessed';
            state.filters.verdict = '';
            if (elements.statusFilter) elements.statusFilter.value = 'not_assessed';
            if (elements.verdictFilter) elements.verdictFilter.value = '';
            document.querySelector('.metric-notassessed')?.classList.add('filtered');
            break;
    }
    
    saveFilters(state.filters);
    renderArticles();
}

function setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Ignore if typing in input/textarea
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            // Allow Escape to clear search
            if (e.key === 'Escape' && e.target === elements.searchBox) {
                elements.searchBox.value = '';
                state.filters.search = '';
                renderArticles();
                elements.searchBox.blur();
            }
            return;
        }
        
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const ctrlKey = isMac ? e.metaKey : e.ctrlKey;
        
        // Ctrl/Cmd + F: Focus search
        if (ctrlKey && e.key === 'f') {
            e.preventDefault();
            elements.searchBox?.focus();
        }
        
        // Ctrl/Cmd + A: Select all
        if (ctrlKey && e.key === 'a') {
            e.preventDefault();
            elements.selectAll.checked = true;
            elements.selectAll.dispatchEvent(new Event('change'));
        }
        
        // Ctrl/Cmd + D: Deselect all
        if (ctrlKey && e.key === 'd') {
            e.preventDefault();
            elements.selectAll.checked = false;
            elements.selectAll.dispatchEvent(new Event('change'));
        }
        
        // Enter: Assess selected
        if (e.key === 'Enter' && state.selected.size > 0 && !state.isAssessing) {
            e.preventDefault();
            handleAssessSelected();
        }
        
        // Delete: Delete selected
        if (e.key === 'Delete' && state.selected.size > 0 && !state.isAssessing) {
            e.preventDefault();
            deleteSelectedArticles();
        }
        
        // ?: Show keyboard shortcuts
        if (e.key === '?' && !e.shiftKey) {
            showKeyboardShortcuts();
        }
    });
}

function showKeyboardShortcuts() {
    const shortcuts = [
        'Ctrl/⌘ + F: Focus search',
        'Ctrl/⌘ + A: Select all',
        'Ctrl/⌘ + D: Deselect all',
        'Enter: Assess selected',
        'Delete: Delete selected',
        '?: Show this help'
    ];
    
    showToast(shortcuts.join('<br>'), 'info', 5000);
}
function showConfirmModal(options) {
    return new Promise((resolve) => {
        const modalEl = document.getElementById('confirmModal');
        const bsLib = window.bootstrap || globalThis.bootstrap;
        
        if (!bsLib || !bsLib.Modal) {
            // Fallback to browser confirm if Bootstrap not available
            resolve(confirm(options.message || 'Are you sure?'));
            return;
        }
        
        const modal = new bsLib.Modal(modalEl);
        const titleEl = document.getElementById('confirmModalLabel');
        const messageEl = document.getElementById('confirm-message');
        const iconEl = document.getElementById('confirm-icon');
        const actionBtn = document.getElementById('confirm-action-btn');
        const modalContent = modalEl.querySelector('.modal-content');
        
        // Set content
        titleEl.textContent = options.title || 'Confirm Action';
        messageEl.textContent = options.message || 'Are you sure you want to proceed?';
        actionBtn.textContent = options.confirmText || 'Confirm';
        
        // Set icon and style
        const iconClass = options.icon || 'fa-question-circle';
        const modalType = options.type || 'warning'; // danger, warning, info
        
        iconEl.innerHTML = `<i class="fas ${iconClass}"></i>`;
        
        // Remove previous modal type classes
        modalContent.classList.remove('modal-danger', 'modal-warning', 'modal-info');
        modalContent.classList.add(`modal-${modalType}`);
        
        // Set button style
        actionBtn.className = `btn btn-${options.confirmButtonClass || 'primary'}`;
        
        // Handle confirmation
        const handleConfirm = () => {
            modal.hide();
            resolve(true);
            cleanup();
        };
        
        const handleCancel = () => {
            modal.hide();
            resolve(false);
            cleanup();
        };
        
        const cleanup = () => {
            actionBtn.removeEventListener('click', handleConfirm);
            modalEl.removeEventListener('hidden.bs.modal', handleCancel);
        };
        
        actionBtn.addEventListener('click', handleConfirm);
        modalEl.addEventListener('hidden.bs.modal', handleCancel, { once: true });
        
        modal.show();
    });
}



async function fetchJSON(url, options) {
    const response = await fetch(url, {
        headers: {
            "Content-Type": "application/json"
        },
        ...options
    });

    if (!response.ok) {
        const text = await response.text();
        throw new Error(`Request failed (${response.status}): ${text}`);
    }

    return response.json();
}

async function loadArticles() {
    try {
        const data = await fetchJSON(API.articles);
        state.articles = data.items ?? [];
        if (data.last_fetch_at) {
            state.lastFetch = data.last_fetch_at;
            renderLastFetch();
        }
        const validIds = new Set(state.articles.map((article) => article.id));
        for (const id of Array.from(state.selected)) {
            if (!validIds.has(id)) {
                state.selected.delete(id);
            }
        }
        renderArticles();
        renderSelectionState();
        renderMetrics();

        // If there are running assessments on server, keep cancel button enabled
        if (elements.cancelButton) {
            const anyRunning = state.articles.some(a => (a.last_status === 'running'));
            elements.cancelButton.disabled = !anyRunning && !state.isAssessing;
        }
    } catch (error) {
        console.error(error);
        showAlert("Failed to load articles. Check console for details.");
    }
}

async function fetchUpdates(full = false) {
    try {
        state.isFetching = true;
        renderFetchButton();
        const result = await fetchJSON(API.fetch, {
            method: "POST",
            body: JSON.stringify(full ? { full: true } : {})
        });
        state.lastFetch = result.last_fetch_at ?? new Date().toISOString();
        await loadArticles();
        renderLastFetch();
        showAlert(`Fetched ${result.fetched ?? 0} articles.`, "success");
    } catch (error) {
        console.error(error);
        showAlert("Failed to fetch updates. Check console for details.");
    } finally {
        state.isFetching = false;
        renderFetchButton();
    }
}

function updateArticleLocal(articleId, changes) {
    const article = state.articles.find((item) => item.id === articleId);
    if (!article) return;
    Object.assign(article, changes);
}

function updateRowStatus(articleId, status, verdictCurrent) {
    const row = elements.tableBody.querySelector(`tr[data-article-id="${articleId}"]`);
    if (!row) return;

    row.classList.remove("running", "done", "error");
    const statusClass = getStatusClass(status);
    if (statusClass) {
        row.classList.add(statusClass);
    }

    const statusCell = row.querySelector('td[data-column="status"]');
    if (statusCell) {
        const isRunning = status === 'running';
        statusCell.innerHTML = formatStatus(status, isRunning);
    }

    if (verdictCurrent !== undefined) {
        const verdictCell = row.querySelector('td[data-column="verdict"]');
        if (verdictCell) {
            verdictCell.innerHTML = verdictBadge(verdictCurrent, articleId);
        }
    }
}

async function assessArticle(articleId) {
    updateArticleLocal(articleId, { last_status: "running" });
    updateRowStatus(articleId, "running");
    renderMetrics();

    try {
        const result = await fetchJSON(API.assess, {
            method: "POST",
            body: JSON.stringify({ article_id: articleId })
        });

        if (result.status === "error") {
            updateArticleLocal(articleId, { last_status: "error" });
            updateRowStatus(articleId, "error");
            showToast(`Assessment failed for article ${articleId}: ${result.error ?? "Unknown error"}`, "error");
        } else {
            updateArticleLocal(articleId, {
                last_status: result.status ?? "done",
                verdict_current: result.verdict_current ?? null
            });
            updateRowStatus(articleId, result.status ?? "done", result.verdict_current ?? null);
        }
    } catch (error) {
        console.error(error);
        updateArticleLocal(articleId, { last_status: "error" });
        updateRowStatus(articleId, "error");
        showToast(`Assessment request failed for article ${articleId}`, "error");
    } finally {
        state.progress.completed = Math.min(state.progress.completed + 1, state.progress.total);
        renderProgress();
        renderMetrics();
    }
}

async function processBatch(queue) {
    if (queue.length === 0) {
        state.isAssessing = false;
        // disable cancel button when finished
        if (elements.cancelButton) elements.cancelButton.disabled = true;
        state.selected.clear();
        renderSelectionState();
        renderProgress();
        await loadArticles();
        showToast(`Completed assessment of ${state.progress.total} article(s)`, "success");
        return;
    }

    // Take up to CONCURRENT_ASSESSMENTS articles from the queue
    const batch = queue.splice(0, CONCURRENT_ASSESSMENTS);
    
    // Process all articles in the batch concurrently
    await Promise.all(batch.map(articleId => assessArticle(articleId)));
    
    // Small delay before next batch
    if (queue.length > 0) {
        await new Promise(resolve => setTimeout(resolve, ASSESSMENT_DELAY_MS));
        await processBatch(queue);
    } else {
        // All done
        state.isAssessing = false;
        if (elements.cancelButton) elements.cancelButton.disabled = true;
        state.selected.clear();
        renderSelectionState();
        renderProgress();
        await loadArticles();
        showToast(`Completed assessment of ${state.progress.total} article(s)`, "success");
    }
}

async function handleAssessSelected() {
    if (!state.selected.size || state.isAssessing) return;

    state.isAssessing = true;
    // enable cancel button when starting
    if (elements.cancelButton) elements.cancelButton.disabled = false;
    state.progress.total = state.selected.size;
    state.progress.completed = 0;
    renderSelectionState();
    renderProgress();

    const queue = Array.from(state.selected);
    showToast(`Starting assessment of ${queue.length} article(s) (${CONCURRENT_ASSESSMENTS} at a time)`, "info");
    await processBatch(queue);
}

function wireEvents() {
    elements.fetchButton.addEventListener("click", () => {
        if (state.isFetching) return;
        fetchUpdates(false);
    });

    if (elements.fetchAllButton) {
        elements.fetchAllButton.addEventListener("click", () => {
            if (state.isFetching) return;
            fetchUpdates(true);
        });
    }

    elements.assessButton.addEventListener("click", () => {
        handleAssessSelected();
    });

    elements.selectAll.addEventListener("change", (event) => {
        const checked = event.target.checked;
        state.selected.clear();
        if (checked) {
            // Only select filtered/visible articles
            const filtered = filterArticles(state.articles);
            filtered.forEach((article) => state.selected.add(article.id));
        }
        renderArticles();
        renderSelectionState();
    });

    // Header sorting - moved outside select-all handler to prevent duplicate listeners
    if (elements.tableHead) {
        elements.tableHead.addEventListener('click', (e) => {
            const th = e.target.closest('th.sortable');
            if (!th) return;
            const key = th.getAttribute('data-sort');
            if (!key) return;
            if (state.sort.key === key) {
                state.sort.dir = state.sort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sort.key = key;
                state.sort.dir = 'asc';
            }
            saveSort(state.sort);
            renderArticles();
            updateSortIndicators();
        });
    }

    if (elements.cancelButton) {
        elements.cancelButton.addEventListener("click", async () => {
            const confirmed = await showConfirmModal({
                title: 'Cancel Assessments',
                message: 'Cancel selected assessments? This will stop queued/running assessments.',
                icon: 'fa-ban',
                type: 'warning',
                confirmText: 'Yes, Cancel',
                confirmButtonClass: 'warning'
            });
            if (!confirmed) return;
            await cancelAssessments();
        });
    }

    if (elements.deleteButton) {
        elements.deleteButton.addEventListener("click", async () => {
            await deleteSelectedArticles();
        });
    }

    elements.tableBody.addEventListener("change", (event) => {
        if (event.target.classList.contains("article-checkbox")) {
            const checkbox = event.target;
            const id = Number.parseInt(checkbox.value, 10);
            const row = checkbox.closest('tr');
            const currentIndex = Array.from(elements.tableBody.querySelectorAll('tr')).indexOf(row);
            
            // Handle shift-click for range selection
            if (event.shiftKey && state.lastCheckedIndex !== null) {
                const start = Math.min(state.lastCheckedIndex, currentIndex);
                const end = Math.max(state.lastCheckedIndex, currentIndex);
                const rows = Array.from(elements.tableBody.querySelectorAll('tr'));
                const shouldCheck = checkbox.checked;
                
                for (let i = start; i <= end; i++) {
                    const rowCheckbox = rows[i]?.querySelector('.article-checkbox');
                    if (rowCheckbox && !rowCheckbox.disabled) {
                        const rowId = Number.parseInt(rowCheckbox.value, 10);
                        if (shouldCheck) {
                            state.selected.add(rowId);
                            rowCheckbox.checked = true;
                        } else {
                            state.selected.delete(rowId);
                            rowCheckbox.checked = false;
                        }
                    }
                }
            } else {
                // Normal click
                if (checkbox.checked) {
                    state.selected.add(id);
                } else {
                    state.selected.delete(id);
                }
            }
            
            state.lastCheckedIndex = currentIndex;
            renderSelectionState();
        }
    });

    // Delegate click for assessment details badges/buttons
    elements.tableBody.addEventListener("click", async (event) => {
        const btn = event.target.closest('.assessment-details-btn, .assessment-details-badge');
        if (!btn) return;
        const articleId = Number.parseInt(btn.getAttribute('data-article-id'), 10);
        if (Number.isNaN(articleId) || !articleId) return;
        await showAssessmentDetails(articleId);
    });
    
    // Search box
    if (elements.searchBox) {
        elements.searchBox.addEventListener('input', (e) => {
            state.filters.search = e.target.value;
            saveFilters(state.filters);
            renderArticles();
        });
    }
    
    // Clear search button
    if (elements.clearSearch) {
        elements.clearSearch.addEventListener('click', () => {
            if (elements.searchBox) {
                elements.searchBox.value = '';
                state.filters.search = '';
                saveFilters(state.filters);
                renderArticles();
            }
        });
    }
    
    // Status filter
    if (elements.statusFilter) {
        elements.statusFilter.addEventListener('change', (e) => {
            state.filters.status = e.target.value;
            state.activeMetricFilter = null;
            document.querySelectorAll('.metric-card').forEach(card => {
                card.classList.remove('filtered');
            });
            saveFilters(state.filters);
            renderArticles();
        });
    }
    
    // Verdict filter
    if (elements.verdictFilter) {
        elements.verdictFilter.addEventListener('change', (e) => {
            state.filters.verdict = e.target.value;
            state.activeMetricFilter = null;
            document.querySelectorAll('.metric-card').forEach(card => {
                card.classList.remove('filtered');
            });
            saveFilters(state.filters);
            renderArticles();
        });
    }
    
    // Metric cards click to filter
    document.querySelectorAll('.metric-card').forEach((card) => {
        card.addEventListener('click', () => {
            if (card.classList.contains('metric-needs')) {
                handleMetricClick('needs');
            } else if (card.classList.contains('metric-assessed')) {
                handleMetricClick('assessed');
            } else if (card.classList.contains('metric-running')) {
                handleMetricClick('running');
            } else if (card.classList.contains('metric-errors')) {
                handleMetricClick('errors');
            } else if (card.classList.contains('metric-notassessed')) {
                handleMetricClick('notassessed');
            }
        });
    });
    
    // Setup keyboard shortcuts
    setupKeyboardShortcuts();
}

function showAssessmentDetails(articleId) {
    const modalEl = document.getElementById('assessmentModal');
    const bsLib = window.bootstrap || globalThis.bootstrap;
    if (!bsLib || !bsLib.Modal) {
        console.error("Bootstrap Modal not available");
        showAlert("UI runtime missing (Bootstrap). Please reload the page.");
        return;
    }
    const bsModal = new bsLib.Modal(modalEl);
    const loading = document.getElementById('assessment-loading');
    const errorEl = document.getElementById('assessment-error');
    const contentEl = document.getElementById('assessment-content');
    const verdictEl = document.getElementById('assessment-verdict');
    const recEl = document.getElementById('assessment-recommendations');
    const markBtn = document.getElementById('mark-current');
    const applyBtn = document.getElementById('apply-recommendations');

    // reset
    loading.classList.remove('d-none');
    errorEl.classList.add('d-none');
    contentEl.classList.add('d-none');
    verdictEl.textContent = '';
    recEl.innerHTML = '';
    
    // Remove any existing copy button and article link
    const existingCopyBtn = recEl.parentElement.querySelector('.copy-btn');
    if (existingCopyBtn) {
        existingCopyBtn.remove();
    }
    const existingArticleLink = contentEl.querySelector('.btn-outline-primary');
    if (existingArticleLink) {
        existingArticleLink.remove();
    }
    
    // Hide recommendation controls
    const controlsEl = document.getElementById('recommendation-controls');
    if (controlsEl) {
        controlsEl.classList.add('d-none');
    }
    
    if (applyBtn) {
        applyBtn.classList.add('d-none');
        applyBtn.onclick = null;
    }

    bsModal.show();

    try {
        const data = await fetchJSON(`${API.assessment_details}?article_id=${articleId}`);
        if (data.error) {
            throw new Error(data.error);
        }
        
        // Get article details for the link
        const article = state.articles.find(a => a.id === articleId);
        const kbNumber = article?.kb_number || data.kb_number || 'Unknown';
        
        // Add link to open article in ServiceNow
        const articleUrl = `${SERVICE_NOW_BASE}/kb_view.do?sysparm_article=${encodeURIComponent(kbNumber)}`;
        const articleLink = `<a href="${articleUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary mb-2">
            <i class="fas fa-external-link-alt me-1"></i>Open Article in ServiceNow
        </a>`;
        
        // Insert link before verdict
        verdictEl.parentElement.insertAdjacentHTML('afterbegin', articleLink);

        const verdict = data.verdict_current;
        verdictEl.innerHTML = verdict
            ? '<span class="badge bg-success text-dark">Current</span>'
            : '<span class="badge bg-warning text-dark">Needs Review</span>';

        // Always show recommendations; add a default message when current and empty
        const recs = Array.isArray(data.recommendations) ? data.recommendations : [];
        if (verdict === true && recs.length === 0) {
            recs.push('Article appears current; no changes recommended.');
        }
        
        // Add copy button for recommendations
        if (recs.length > 0 && verdict === false) {
            const copyBtn = document.createElement('button');
            copyBtn.className = 'btn btn-sm btn-outline-secondary copy-btn mb-2';
            copyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy Recommendations';
            copyBtn.onclick = () => {
                const text = recs.join('\n');
                navigator.clipboard.writeText(text).then(() => {
                    copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
                    copyBtn.classList.add('copied');
                    setTimeout(() => {
                        copyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy Recommendations';
                        copyBtn.classList.remove('copied');
                    }, 2000);
                    showToast('Recommendations copied to clipboard', 'success', 2000);
                }).catch(err => {
                    console.error('Copy failed:', err);
                    showToast('Failed to copy to clipboard', 'error', 2000);
                });
            };
            recEl.parentElement.insertBefore(copyBtn, recEl);
        }
        
        // Render recommendations with checkboxes for "needs review" articles
        if (verdict === false && recs.length > 0) {
            // Show the select/unselect all controls
            const controlsEl = document.getElementById('recommendation-controls');
            if (controlsEl) {
                controlsEl.classList.remove('d-none');
                
                // Wire select all button
                const selectAllBtn = document.getElementById('select-all-recommendations');
                if (selectAllBtn) {
                    selectAllBtn.onclick = () => {
                        recEl.querySelectorAll('.recommendation-checkbox').forEach(cb => {
                            cb.checked = true;
                        });
                    };
                }
                
                // Wire unselect all button
                const unselectAllBtn = document.getElementById('unselect-all-recommendations');
                if (unselectAllBtn) {
                    unselectAllBtn.onclick = () => {
                        recEl.querySelectorAll('.recommendation-checkbox').forEach(cb => {
                            cb.checked = false;
                        });
                    };
                }
            }
            
            recs.forEach((r, index) => {
                const li = document.createElement('li');
                li.className = 'mb-2 d-flex align-items-start';
                
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'form-check-input me-2 recommendation-checkbox flex-shrink-0';
                checkbox.id = `rec-${index}`;
                checkbox.value = r;
                checkbox.checked = true; // Default to checked
                checkbox.style.marginTop = '0.25rem';
                
                const label = document.createElement('label');
                label.className = 'form-check-label flex-grow-1';
                label.htmlFor = `rec-${index}`;
                label.textContent = r;
                
                li.appendChild(checkbox);
                li.appendChild(label);
                recEl.appendChild(li);
            });
            
            // Show and wire the "Apply Selected Changes" button
            if (applyBtn) {
                applyBtn.classList.remove('d-none');
                applyBtn.disabled = false;
                applyBtn.onclick = async () => {
                    const checkboxes = recEl.querySelectorAll('.recommendation-checkbox:checked');
                    const selectedRecs = Array.from(checkboxes).map(cb => cb.value);
                    
                    if (selectedRecs.length === 0) {
                        showToast('Please select at least one recommendation', 'warning', 3000);
                        return;
                    }
                    
                    try {
                        applyBtn.disabled = true;
                        applyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Applying...';
                        
                        const result = await fetchJSON(API.rewrite, {
                            method: 'POST',
                            body: JSON.stringify({
                                article_id: articleId,
                                selected_recommendations: selectedRecs
                            })
                        });
                        
                        if (result.error) {
                            throw new Error(result.error);
                        }
                        
                        // Show success message with changes made
                        let successMsg = 'Article rewritten successfully!';
                        if (result.changes_made && result.changes_made.length > 0) {
                            successMsg += '\n\nChanges made:\n' + result.changes_made.map(c => '• ' + c).join('\n');
                        }
                        
                        showToast('Rewrite completed successfully', 'success', 5000);
                        
                        // Close the assessment modal first
                        bsModal.hide();
                        
                        // Show the rewrite result modal after a short delay
                        setTimeout(() => {
                            showRewriteResult(result, articleId);
                        }, 300);
                    } catch (err) {
                        console.error('Rewrite failed:', err);
                        showToast('Failed to rewrite article: ' + err.message, 'error', 5000);
                    } finally {
                        applyBtn.disabled = false;
                        applyBtn.innerHTML = '<i class="fas fa-magic me-1"></i>Apply Selected Changes';
                    }
                };
            }
        } else {
            // For "current" articles, just show the recommendations as text
            recs.forEach((r) => {
                const li = document.createElement('li');
                li.textContent = r;
                recEl.appendChild(li);
            });
        }

        // Wire "Mark as Current" button - single implementation
        if (markBtn) {
            if (verdict === true) {
                markBtn.classList.add('d-none');
                markBtn.onclick = null;
            } else {
                markBtn.classList.remove('d-none');
                markBtn.disabled = false;
                markBtn.onclick = async () => {
                    try {
                        markBtn.disabled = true;
                        await fetchJSON(API.mark_current, {
                            method: 'POST',
                            body: JSON.stringify({ article_id: articleId })
                        });
                        // Update row + metrics + modal
                        updateArticleLocal(articleId, { last_status: 'done', verdict_current: true });
                        updateRowStatus(articleId, 'done', true);
                        if (typeof renderMetrics === 'function') {
                            renderMetrics();
                        }
                        verdictEl.innerHTML = '<span class="badge bg-success text-dark">Current</span>';
                        recEl.innerHTML = '<li>Article appears current; no changes recommended.</li>';
                        markBtn.classList.add('d-none');
                        if (applyBtn) applyBtn.classList.add('d-none');
                    } catch (err) {
                        console.error('Mark current failed:', err);
                        showAlert('Failed to mark as current.');
                    } finally {
                        markBtn.disabled = false;
                    }
                };
            }
        }

        contentEl.classList.remove('d-none');
    } catch (err) {
        console.error('Failed to load assessment details:', err);
        errorEl.textContent = err.message ?? 'Failed to load details';
        errorEl.classList.remove('d-none');
    } finally {
        loading.classList.add('d-none');
    }
}

function showRewriteResult(result, articleId) {
    const modalHTML = `
        <div class="modal fade" id="rewriteResultModal" tabindex="-1" aria-labelledby="rewriteResultModalLabel" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="rewriteResultModalLabel">Rewritten Content</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-success">
                            <h6>Changes Made:</h6>
                            <ul class="mb-0">
                                ${result.changes_made.map(c => `<li>${c}</li>`).join('')}
                            </ul>
                        </div>
                        <h6>Rewritten Content:</h6>
                        <div class="border p-3 bg-light" style="max-height: 500px; overflow-y: auto;">
                            ${result.rewritten_content}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-success" id="mark-current-rewrite">
                            <i class="fas fa-check-circle me-1"></i>Mark as Current
                        </button>
                        <button type="button" class="btn btn-primary" id="copy-rewritten-content">
                            <i class="fas fa-copy me-1"></i>Copy Content
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('rewriteResultModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    const modalEl = document.getElementById('rewriteResultModal');
    const bsLib = window.bootstrap || globalThis.bootstrap;
    const modal = new bsLib.Modal(modalEl);
    
    // Wire copy button
    const copyBtn = document.getElementById('copy-rewritten-content');
    copyBtn.onclick = () => {
        navigator.clipboard.writeText(result.rewritten_content).then(() => {
            copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
            setTimeout(() => {
                copyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy Content';
            }, 2000);
            showToast('Content copied to clipboard', 'success', 2000);
        }).catch(err => {
            console.error('Copy failed:', err);
            showToast('Failed to copy to clipboard', 'error', 2000);
        });
    };
    
    // Wire mark as current button
    const markCurrentBtn = document.getElementById('mark-current-rewrite');
    if (markCurrentBtn && articleId) {
        markCurrentBtn.onclick = async () => {
            try {
                markCurrentBtn.disabled = true;
                markCurrentBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Updating...';
                
                await fetchJSON(API.mark_current, {
                    method: 'POST',
                    body: JSON.stringify({ article_id: articleId })
                });
                
                // Update local state
                updateArticleLocal(articleId, { last_status: 'done', verdict_current: true });
                updateRowStatus(articleId, 'done', true);
                if (typeof renderMetrics === 'function') {
                    renderMetrics();
                }
                
                showToast('Article marked as current', 'success', 3000);
                
                // Close the modal
                modal.hide();
            } catch (err) {
                console.error('Mark current failed:', err);
                showToast('Failed to mark as current: ' + err.message, 'error', 5000);
                markCurrentBtn.disabled = false;
                markCurrentBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>Mark as Current';
            }
        };
    }
    
    // Clean up modal when hidden
    modalEl.addEventListener('hidden.bs.modal', () => {
        modalEl.remove();
    });
    
    modal.show();
}

async function bootstrap() {
    // Restore saved filters to UI
    if (elements.searchBox) elements.searchBox.value = state.filters.search || '';
    if (elements.statusFilter) elements.statusFilter.value = state.filters.status || '';
    if (elements.verdictFilter) elements.verdictFilter.value = state.filters.verdict || '';
    
    renderFetchButton();
    renderProgress();
    renderLastFetch();
    renderSelectionState();
    updateSortIndicators(); // Initialize sort indicators
    wireEvents();
    await loadArticles();
}

bootstrap().catch((error) => {
    console.error("Bootstrap error:", error);
    showAlert("Unexpected error during initialization.");
});