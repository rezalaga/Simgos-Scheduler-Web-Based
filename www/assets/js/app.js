function copyResponseBody() {
    const el = document.getElementById('log-response-body');
    if (!el) return;
    navigator.clipboard.writeText(el.textContent).then(() => toast('Copied!', 'success')).catch(() => {});
}

// ─── Helpers ────────────────────────────────────────────────
function api(action, data, method) {
    const opts = { method: method || 'GET' };
    if (data) {
        opts.method = 'POST';
        opts.body = data instanceof FormData ? data : new URLSearchParams(data);
    }
    const url = '?' + new URLSearchParams({ action }).toString();
    return fetch(url, opts).then(r => r.text()).then(t => {
        try { return JSON.parse(t); }
        catch (e) {
            console.error('JSON parse error for', action, '\nresponse text:', t.substring(0, 500));
            throw e;
        }
    });
}

function toast(msg, type) {
    const color = type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'success';
    const icon = type === 'error' ? 'exclamation-triangle' : type === 'warning' ? 'exclamation-circle' : 'check-circle';
    const container = document.querySelector('.toast-container');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${color} border-0 show`;
    el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-${icon} me-2"></i>${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(el);
    setTimeout(() => { el.remove(); }, 5000);
}

function initTooltips() {
    document.querySelectorAll('[title]').forEach(el => {
        try { new bootstrap.Tooltip(el, { delay: { show: 100, hide: 0 } }); } catch (_) {}
    });
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function toDate(d) {
    if (!d) return null;
    if (d.includes('T')) return new Date(d);
    return new Date(d.replace(' ', 'T') + 'Z');
}

function formatDate(d) {
    if (!d || typeof d !== 'string') return d || '-';
    try {
        const dt = toDate(d);
        if (!dt || isNaN(dt.getTime())) return d;
        return dt.toLocaleString();
    } catch (_) { return d; }
}

function statusBadge(status) {
    return status === 'success'
        ? '<span class="badge bg-success">Success</span>'
        : '<span class="badge bg-danger">Error</span>';
}

// ─── Dashboard ──────────────────────────────────────────────
let dashInterval;
let tickInterval;
let activeTasks = [];
let taskExecuting = {};

function refreshDashboard() {
    api('get-stats').then(d => {
        document.getElementById('stat-servers').textContent = d.total_servers;
        document.getElementById('stat-active').textContent = d.active_tasks;
        document.getElementById('stat-total-logs').textContent = d.total_logs;
        document.getElementById('stat-failed').textContent = d.failed_logs;

        const cont = document.getElementById('recent-logs-container');
        if (!d.recent_logs.length) {
            cont.innerHTML = '<div class="text-center text-muted py-3">No executions yet.</div>';
        } else {
            let html = '<table class="table table-hover mb-0"><thead><tr><th>Time</th><th>Task</th><th>Server</th><th>Status</th><th>Code</th><th>Duration</th></tr></thead><tbody>';
            d.recent_logs.forEach(l => {
                html += `<tr class="cursor-pointer" onclick="viewLog(${l.id})">
                    <td><small class="text-muted">${formatDate(l.executed_at)}</small></td>
                    <td>${esc(l.task_title)}</td>
                    <td>${esc(l.server_name)}</td>
                    <td>${statusBadge(l.status)}</td>
                    <td>${l.http_code || '-'}</td>
                    <td>${l.response_time_ms || '-'}ms</td>
                </tr>`;
            });
            html += '</tbody></table>';
            cont.innerHTML = html;
            initTooltips();
        }
    }).catch(e => toast('Failed to load dashboard: ' + e.message, 'error'));

    api('list-tasks').then(tasks => {
        activeTasks = tasks.filter(t => t.is_active == 1);
        renderActiveTasks();
        if (!tickInterval) {
            tickInterval = setInterval(tick, 1000);
        }
    }).catch(e => toast('Failed to load tasks: ' + e.message, 'error'));
}

function effectiveNext(nextExec, interval) {
    const now = Date.now();
    const intMs = interval * 1000;
    while (nextExec <= now - intMs) nextExec += intMs;
    return nextExec;
}

function renderActiveTasks() {
    const cont = document.getElementById('active-tasks-container');
    if (!activeTasks.length) {
        cont.innerHTML = '<div class="text-center text-muted py-4">No active tasks. <a href="?page=tasks">Create one</a>.</div>';
        return;
    }
    const now = Date.now();
    const r = 29, circ = 2 * Math.PI * r;
    let html = '<div class="active-tasks-grid">';

    activeTasks.forEach(t => {
        const nextExec = effectiveNext(toDate(t.next_execution_at)?.getTime() ?? now, t.execute_interval_sec);
        const lastExec = toDate(t.last_executed_at)?.getTime() ?? null;
        const remaining = Math.max(0, Math.floor((nextExec - now) / 1000));
        const total = lastExec ? Math.max(1, Math.round((nextExec - lastExec) / 1000)) : t.execute_interval_sec;
        const pct = Math.min(100, Math.max(0, ((total - remaining) / total) * 100));

        const isExecuting = taskExecuting[t.id];
        let fillClass = 'success';
        if (isExecuting) fillClass = 'info';
        else if (remaining <= 5) fillClass = 'danger';
        else if (remaining <= 10) fillClass = 'warning';

        const offset = isExecuting ? 0 : (pct / 100) * circ;

        html += `<div class="task-donut-card" data-task-id="${t.id}">
            <div class="donut">
                <svg viewBox="0 0 72 72" width="72" height="72">
                    <g transform="rotate(-90 36 36)">
                        <circle class="track" cx="36" cy="36" r="${r}"/>
                        <circle class="fill ${fillClass}" cx="36" cy="36" r="${r}"
                            stroke-dasharray="${circ}" stroke-dashoffset="${offset}"/>
                    </g>
                    <text x="36" y="31" class="donut-text">${isExecuting ? 'RUN' : remaining + 's'}</text>
                    <text x="36" y="45" class="donut-label">${isExecuting ? '' : (remaining > 0 ? 'left' : 'NOW')}</text>
                </svg>
            </div>
            <div class="task-donut-info">
                <div class="text-truncate"><strong>${esc(t.title)}</strong></div>
                <div class="text-truncate"><small class="text-muted">${esc(t.server_name)}</small> <code class="small">${esc(t.path)}</code></div>
                <div class="small text-muted">Next: ${formatDate(t.next_execution_at)}</div>
            </div>
        </div>`;
    });

    html += '</div>';
    cont.innerHTML = html;
    initTooltips();
}

function tick() {
    let changed = false;
    const now = Date.now();

    activeTasks.forEach(t => {
        if (taskExecuting[t.id]) return;
        const nextExec = effectiveNext(toDate(t.next_execution_at)?.getTime() ?? now, t.execute_interval_sec);
        if (nextExec <= now && now - nextExec < 1500) {
            changed = true;
            executeTask(t);
        }
    });

    if (!changed) renderActiveTasks();
}

function executeTask(task) {
    taskExecuting[task.id] = true;
    renderActiveTasks();

    api('execute-task', { id: task.id }).then(r => {
        if (r.ok) {
            task.next_execution_at = r.next_execution_at;
            task.http_code = r.http_code;
            task.response_time_ms = r.response_time_ms;
            task.last_status = r.status;
            const label = r.status === 'success' ? 'Success' : 'Error';
            toast(`"${task.title}" ${label} (${r.http_code}) ${r.response_time_ms}ms`, r.status);
        }
        delete taskExecuting[task.id];
        refreshDashboard();
    }).catch(e => {
        delete taskExecuting[task.id];
        toast(`Failed to execute "${task.title}": ${e.message}`, 'error');
        refreshDashboard();
    });
}

// ─── Servers ────────────────────────────────────────────────
function loadServers() {
    api('list-servers').then(servers => {
        const cont = document.getElementById('servers-table-container');
        if (!servers.length) {
            cont.innerHTML = '<div class="text-center text-muted py-3">No servers yet. Add one to get started.</div>';
            return;
        }
        let html = '<table class="table table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Name</th><th>Base URL</th><th>Created</th><th class="text-end">Actions</th></tr></thead><tbody>';
        servers.forEach((s, i) => {
            html += `<tr>
                <td class="text-muted">${i + 1}</td>
                <td><strong>${esc(s.name)}</strong></td>
                <td><code>${esc(s.base_url)}</code></td>
                <td><small class="text-muted">${formatDate(s.created_at)}</small></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openServerModal(${s.id})" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-info me-1" onclick="copyServer(${s.id})" title="Copy"><i class="bi bi-copy"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete('server', ${s.id}, '${esc(s.name)}')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
        initTooltips();
    }).catch(e => toast('Failed to load servers: ' + e.message, 'error'));
}

function openServerModal(id) {
    const modal = new bootstrap.Modal(document.getElementById('serverModal'));
    document.getElementById('serverForm').reset();
    document.getElementById('server-id').value = '';
    document.getElementById('serverModalTitle').textContent = 'Add Server';
    if (id) {
        document.getElementById('serverModalTitle').textContent = 'Edit Server';
        api('get-server', { id }).then(s => {
            document.getElementById('server-id').value = s.id;
            document.getElementById('server-name').value = s.name;
            document.getElementById('server-base-url').value = s.base_url;
        });
    }
    modal.show();
}

function copyServer(id) {
    api('get-server', { id }).then(s => {
        document.getElementById('serverForm').reset();
        document.getElementById('server-id').value = '';
        document.getElementById('serverModalTitle').textContent = 'Copy Server';
        document.getElementById('server-name').value = s.name + ' (copy)';
        document.getElementById('server-base-url').value = s.base_url;
        new bootstrap.Modal(document.getElementById('serverModal')).show();
    }).catch(e => toast('Failed to copy server: ' + e.message, 'error'));
}

function saveServer(e) {
    e.preventDefault();
    const data = new FormData(e.target);
    api('save-server', data).then(d => {
        if (d.ok) {
            bootstrap.Modal.getInstance(document.getElementById('serverModal')).hide();
            toast('Server saved', 'success');
            loadServers();
        }
    }).catch(e => toast('Failed to save server: ' + e.message, 'error'));
    return false;
}

// ─── Tasks ──────────────────────────────────────────────────
function loadTasks() {
    api('list-tasks').then(tasks => {
        const cont = document.getElementById('tasks-table-container');
        if (!tasks.length) {
            cont.innerHTML = '<div class="text-center text-muted py-3">No tasks yet. Add one to start scheduling.</div>';
            return;
        }
        let html = '<table class="table table-hover mb-0"><thead><tr><th style="width:40px">#</th><th style="width:40px"><input type="checkbox" onchange="toggleAllTaskCheckboxes(this)"></th><th>Title</th><th>Server</th><th>Path</th><th>Status</th><th>Interval</th><th>Next Run</th><th class="text-end">Actions</th></tr></thead><tbody>';
        tasks.forEach((t, i) => {
            const active = t.is_active == 1;
            html += `<tr class="${active ? '' : 'opacity-50'}">
                <td class="text-muted">${i + 1}</td>
                <td><input type="checkbox" class="task-checkbox" value="${t.id}"></td>
                <td><strong>${esc(t.title)}</strong></td>
                <td>${esc(t.server_name)}</td>
                <td><code>${esc(t.path)}</code></td>
                <td>${active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
                <td>${t.execute_interval_sec}s</td>
                <td><small class="text-muted">${formatDate(t.next_execution_at)}</small></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-success me-1" onclick="runTask(${t.id}, '${esc(t.title)}')" title="Execute Now"><i class="bi bi-play-fill"></i></button>
                    <button class="btn btn-sm btn-outline-${active ? 'warning' : 'success'} me-1" onclick="toggleTask(${t.id})" title="${active ? 'Deactivate' : 'Activate'}"><i class="bi bi-${active ? 'pause' : 'play'}"></i></button>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openTaskModal(${t.id})" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-info me-1" onclick="copyTask(${t.id})" title="Copy"><i class="bi bi-copy"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete('task', ${t.id}, '${esc(t.title)}')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
        initTooltips();
    }).catch(e => toast('Failed to load tasks: ' + e.message, 'error'));
}

function openTaskModal(id) {
    const modal = new bootstrap.Modal(document.getElementById('taskModal'));
    document.getElementById('taskForm').reset();
    document.getElementById('task-id').value = '';
    document.getElementById('taskModalTitle').textContent = 'Add Task';

    api('list-servers').then(servers => {
        const sel = document.getElementById('task-server');
        sel.innerHTML = '<option value="">Select Server</option>';
        servers.forEach(s => {
            sel.innerHTML += `<option value="${s.id}">${esc(s.name)}</option>`;
        });
        if (id) {
            document.getElementById('taskModalTitle').textContent = 'Edit Task';
            api('get-task', { id }).then(t => {
                document.getElementById('task-id').value = t.id;
                document.getElementById('task-server').value = t.server_id;
                document.getElementById('task-title').value = t.title;
                document.getElementById('task-path').value = t.path;
                document.getElementById('task-interval').value = t.execute_interval_sec;
                document.getElementById('task-error-interval').value = t.error_interval_sec;
                document.getElementById('task-timeout').value = t.response_timeout_ms;

            });
        }
    });
    modal.show();
}

function copyTask(id) {
    api('get-task', { id }).then(t => {
        api('list-servers').then(servers => {
            const sel = document.getElementById('task-server');
            sel.innerHTML = '<option value="">Select Server</option>';
            servers.forEach(s => {
                sel.innerHTML += `<option value="${s.id}" ${s.id == t.server_id ? 'selected' : ''}>${esc(s.name)}</option>`;
            });
            document.getElementById('taskForm').reset();
            document.getElementById('task-id').value = '';
            document.getElementById('taskModalTitle').textContent = 'Copy Task';
            document.getElementById('task-title').value = t.title + ' (copy)';
            document.getElementById('task-path').value = t.path;
            document.getElementById('task-interval').value = t.execute_interval_sec;
            document.getElementById('task-error-interval').value = t.error_interval_sec;
            document.getElementById('task-timeout').value = t.response_timeout_ms;
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        });
    }).catch(e => toast('Failed to copy task: ' + e.message, 'error'));
}

function saveTask(e) {
    e.preventDefault();
    const data = new FormData(e.target);
    api('save-task', data).then(d => {
        if (d.ok) {
            bootstrap.Modal.getInstance(document.getElementById('taskModal')).hide();
            toast('Task saved', 'success');
            loadTasks();
        }
    }).catch(e => toast('Failed to save task: ' + e.message, 'error'));
    return false;
}

function runTask(id, title) {
    api('execute-task', { id }).then(r => {
        if (r.ok) {
            const label = r.status === 'success' ? 'Success' : 'Error';
            toast(`"${title}" ${label} (${r.http_code}) ${r.response_time_ms}ms`, r.status);
        }
        loadTasks();
    }).catch(e => toast(`Failed to execute "${title}": ${e.message}`, 'error'));
}

function getSelectedTaskIds() {
    return Array.from(document.querySelectorAll('.task-checkbox:checked')).map(cb => cb.value).join(',');
}

function toggleAllTaskCheckboxes(el) {
    document.querySelectorAll('.task-checkbox').forEach(cb => cb.checked = el.checked);
}

function bulkTaskAction(action, label) {
    const ids = getSelectedTaskIds();
    if (!ids) { toast('No tasks selected', 'warning'); return; }
    api(action, { ids }).then(d => {
        if (d.ok) { toast(label + ' ' + ids.split(',').length + ' tasks', 'success'); loadTasks(); }
    }).catch(e => toast('Failed: ' + e.message, 'error'));
}

function startSelectedTasks() { bulkTaskAction('start-selected-tasks', 'Started'); }
function stopSelectedTasks() { bulkTaskAction('stop-selected-tasks', 'Stopped'); }

function startAllTasks() {
    api('start-all-tasks').then(d => {
        if (d.ok) { toast('All tasks started', 'success'); loadTasks(); }
    }).catch(e => toast('Failed: ' + e.message, 'error'));
}

function stopAllTasks() {
    if (!confirm('Set all tasks to inactive?')) return;
    api('stop-all-tasks').then(d => {
        if (d.ok) { toast('All tasks stopped', 'success'); loadTasks(); }
    }).catch(e => toast('Failed: ' + e.message, 'error'));
}

function toggleTask(id) {
    api('toggle-task', { id }).then(d => {
        if (d.ok) {
            toast('Task toggled', 'success');
            loadTasks();
        }
    }).catch(e => toast('Failed to toggle task: ' + e.message, 'error'));
}

// ─── Logs ───────────────────────────────────────────────────
function loadLogs(page) {
    const p = page || 1;
    const status = document.getElementById('log-status-filter')?.value || '';
    const taskFilter = document.getElementById('log-task-filter')?.value || '';
    api('list-logs', { page: p, status, task_id: taskFilter }).then(d => {
        const cont = document.getElementById('logs-table-container');
        if (!d.logs.length) {
            cont.innerHTML = '<div class="text-center text-muted py-3">No logs yet.</div>';
            return;
        }
        let html = '<table class="table table-hover mb-0"><thead><tr><th>Time</th><th>Task</th><th>URL</th><th>Status</th><th>Code</th><th>Duration</th><th class="text-end">Actions</th></tr></thead><tbody>';
        d.logs.forEach(l => {
            const url = l.base_url + l.path;
            html += `<tr>
                <td><small class="text-muted">${formatDate(l.executed_at)}</small></td>
                <td>${esc(l.task_title || l.title)}</td>
                <td><small class="text-break">${esc(url)}</small></td>
                <td>${statusBadge(l.status)}</td>
                <td>${l.http_code || '-'}</td>
                <td>${l.response_time_ms || '-'}ms</td>
                <td class="text-end"><button class="btn btn-sm btn-outline-info" onclick="viewLog(${l.id})"><i class="bi bi-eye"></i> Detail</button></td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
        initTooltips();

        if (d.totalPages > 1) {
            let pag = '<ul class="pagination pagination-sm justify-content-center mb-0">';
            for (let i = 1; i <= d.totalPages; i++) {
                pag += `<li class="page-item ${i === d.page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadLogs(${i});return false">${i}</a></li>`;
            }
            pag += '</ul>';
            document.getElementById('logs-pagination').innerHTML = pag;
        } else {
            document.getElementById('logs-pagination').innerHTML = '';
        }
    }).catch(e => toast('Failed to load logs: ' + e.message, 'error'));
}

function refreshLogs() {
    loadLogs(1);
}

function viewLog(id) {
    const modal = new bootstrap.Modal(document.getElementById('logModal'));
    document.getElementById('log-detail-body').innerHTML = '<div class="text-center text-muted py-3">Loading...</div>';
    modal.show();
    api('get-log', { id }).then(l => {
        if (!l) {
            document.getElementById('log-detail-body').innerHTML = '<div class="text-center text-muted py-3">Log not found</div>';
            return;
        }
        const body = l.response_body_pretty
            ? `<pre class="mb-0" id="log-response-body">${esc(l.response_body_pretty)}</pre>`
            : '<div class="text-muted">No response body</div>';
        let respSection;
        if (l.response_body) {
            respSection = `<div class="d-flex align-items-center gap-2 mt-3 mb-2">
                <h6 class="text-muted mb-0">Response Body (Pretty Print)</h6>
                <button class="btn btn-sm btn-outline-secondary py-0" onclick="copyResponseBody()" title="Copy response body"><i class="bi bi-clipboard"></i></button>
            </div>${body}
                <details class="mt-2"><summary class="text-muted small cursor-pointer">Show raw</summary>
                <pre class="mt-2">${esc(l.response_body)}</pre></details>`;
        } else {
            respSection = '<div class="text-muted mt-3">No response body</div>';
        }

        document.getElementById('log-detail-body').innerHTML = `
            <div class="row mb-3">
                <div class="col-md-4"><small class="text-muted d-block">Task</small><strong>${esc(l.task_title || l.title)}</strong></div>
                <div class="col-md-4"><small class="text-muted d-block">Server</small><strong>${esc(l.server_name)}</strong></div>
                <div class="col-md-4"><small class="text-muted d-block">Status</small>${statusBadge(l.status)}</div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4"><small class="text-muted d-block">URL</small><code>${esc(l.base_url)}${esc(l.path)}</code></div>
                <div class="col-md-4"><small class="text-muted d-block">HTTP Code</small><strong>${l.http_code || '-'}</strong></div>
                <div class="col-md-4"><small class="text-muted d-block">Response Time</small><strong>${l.response_time_ms || '-'} ms</strong></div>
            </div>
            ${l.error_message ? `<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i>${esc(l.error_message)}</div>` : ''}
            ${respSection}
            <small class="text-muted d-block mt-3">Executed at: ${formatDate(l.executed_at)}</small>
        `;
    }).catch(e => {
        const info = { name: e.name, message: e.message, stack: e.stack };
        document.getElementById('log-detail-body').innerHTML = `<div class="alert alert-danger">Failed to load log: ${esc(info.name)}: ${esc(info.message)}</div>`;
        console.error('viewLog error:', info, 'id:', id);
    });
}

function clearLogs() {
    if (!confirm('Delete all execution logs? This cannot be undone.')) return;
    api('clear-logs').then(d => {
        if (d.ok) {
            toast('All logs cleared', 'success');
            refreshLogs();
        }
    }).catch(e => toast('Failed to clear logs: ' + e.message, 'error'));
}

function loadLogTaskFilter() {
    api('list-tasks').then(tasks => {
        const sel = document.getElementById('log-task-filter');
        if (!sel) return;
        tasks.forEach(t => {
            sel.innerHTML += `<option value="${t.id}">${esc(t.title)}</option>`;
        });
    });
}

// ─── Delete Confirmation ────────────────────────────────────
let deleteTarget = null;

function confirmDelete(type, id, name) {
    deleteTarget = { type, id };
    document.getElementById('deleteModalBody').innerHTML = `Delete <strong>${esc(name)}</strong>? This will also delete related logs.`;
    document.getElementById('deleteConfirmBtn').onclick = function() {
        api(`delete-${deleteTarget.type}`, { id: deleteTarget.id }).then(d => {
            if (d.ok) {
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                const label = deleteTarget.type === 'server' ? 'Server deleted' : 'Task deleted';
                toast(label, 'success');
                if (deleteTarget.type === 'server') loadServers();
                else loadTasks();
            }
        }).catch(e => toast('Failed to delete: ' + e.message, 'error'));
    };
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// ─── Init ───────────────────────────────────────────────────
function closeSidebarMobile() {
    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.remove('mobile-open');
    }
}

function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const m = document.getElementById('main-content');
    const isMobile = window.innerWidth <= 768;
    if (isMobile) {
        s.classList.toggle('mobile-open');
    } else {
        s.classList.toggle('collapsed');
        m.classList.toggle('expanded');
    }
}

const TIMEZONES = [
    'Asia/Jakarta', 'Asia/Pontianak', 'Asia/Makassar', 'Asia/Jayapura',
    'Asia/Singapore', 'Asia/Kuala_Lumpur', 'Asia/Manila', 'Asia/Bangkok',
    'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Seoul',
    'Australia/Sydney', 'Pacific/Auckland', 'America/New_York',
    'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'UTC',
];

function loadSettings() {
    api('get-settings').then(s => {
        const tz = s.timezone || 'Asia/Jakarta';
        document.getElementById('sidebar-tz-iana').textContent = tz;
        const cont = document.getElementById('settings-container');
        if (!cont) return;
        cont.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Timezone</label>
                    <select class="form-select" id="setting-timezone">
                        ${TIMEZONES.map(t => `<option value="${t}"${t===tz?' selected':''}>${t.replace('/',' / ')}</option>`).join('')}
                    </select>
                    <div class="form-text">Server timezone for scheduling and clock display.</div>
                    <button class="btn btn-primary mt-3" onclick="saveSettings()"><i class="bi bi-check-lg"></i> Save</button>
                </div>
            </div>`;
    }).catch(e => {
        const cont = document.getElementById('settings-container');
        if (cont) cont.innerHTML = `<div class="alert alert-danger">Failed to load settings: ${e.message}</div>`;
    });
}

function saveSettings() {
    const tz = document.getElementById('setting-timezone').value;
    api('save-settings', { timezone: tz }, 'POST').then(r => {
        if (r.ok) {
            document.getElementById('sidebar-tz-iana').textContent = tz;
            toast('Timezone saved to ' + tz, 'success');
        }
    }).catch(e => toast('Save failed: ' + e.message, 'error'));
}

function updateClock() {
    const tzEl = document.getElementById('sidebar-tz-iana');
    if (!tzEl || !tzEl.textContent) return;
    const tz = tzEl.textContent;
    const now = new Date();
    const fmt = (opt) => new Intl.DateTimeFormat('id', { timeZone: tz, ...opt }).format(now);
    const elTime = document.getElementById('topbar-time');
    const elDate = document.getElementById('topbar-date');
    if (elTime) elTime.textContent = fmt({ hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    if (elDate) elDate.textContent = fmt({ day: '2-digit', month: 'short', year: 'numeric' });
}

function downloadImportTemplate() {
    window.location.href = '?action=download-import-template';
}

function importFromFile(type) {
    document.getElementById('import-type').value = type;
    document.getElementById('import-file').value = '';
    document.getElementById('import-result').classList.add('d-none');
    document.getElementById('import-result').innerHTML = '';
    if (type === 'server') {
        document.getElementById('importModalTitle').textContent = 'Import Servers';
        document.getElementById('importModalDesc').textContent = 'Select a .txt file to import servers. Format: Server Name | Base URL';
    } else {
        document.getElementById('importModalTitle').textContent = 'Import Tasks';
        document.getElementById('importModalDesc').textContent = 'Select a .txt file to import tasks. Servers must already exist. Format: Server Name | Title | Path | Interval | Error Interval | Timeout';
    }
    new bootstrap.Modal(document.getElementById('importModal')).show();
}

function submitImport() {
    const type = document.getElementById('import-type').value;
    const fileInput = document.getElementById('import-file');
    const result = document.getElementById('import-result');
    result.classList.add('d-none');

    if (!fileInput.files.length) {
        result.className = 'alert alert-danger py-2';
        result.textContent = 'Please select a file first.';
        result.classList.remove('d-none');
        return;
    }

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    const action = type === 'server' ? 'import-servers' : 'import-tasks';

    result.className = 'alert alert-info py-2';
    result.textContent = 'Processing...';
    result.classList.remove('d-none');

    api(action, formData).then(r => {
        if (r.ok) {
            result.className = 'alert alert-success py-2';
            result.textContent = `Successfully imported ${r.count} ${type === 'server' ? 'server(s)' : 'task(s)'}.`;
            if (type === 'server') loadServers(); else loadTasks();
        }
    }).catch(e => {
        result.className = 'alert alert-danger py-2';
        result.textContent = 'Failed: ' + e.message;
    });
}

function toggleTheme() {
    const theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
}

function initTheme() {
    const saved = localStorage.getItem('theme');
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (!localStorage.getItem('theme')) {
            document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
        }
    });
    const toastCont = document.createElement('div');
    toastCont.className = 'toast-container';
    document.body.appendChild(toastCont);
    initTooltips();
    updateClock();
    setInterval(updateClock, 1000);

    const page = new URLSearchParams(location.search).get('page') || 'dashboard';

    if (page === 'dashboard') {
        refreshDashboard();
        dashInterval = setInterval(refreshDashboard, 10000);
    } else if (page === 'servers') {
        loadServers();
    } else if (page === 'tasks') {
        loadTasks();
    } else if (page === 'logs') {
        loadLogTaskFilter();
        refreshLogs();
    } else if (page === 'settings') {
        loadSettings();
    }
});
