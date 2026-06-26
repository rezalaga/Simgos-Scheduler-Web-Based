<?php
require_once __DIR__ . '/config.php';

function fmtDate($dt) {
    if (!$dt) return null;
    return str_replace(' ', 'T', $dt) . 'Z';
}

function fmtDates(&$rows, $keys) {
    if (!$rows) return;
    $single = isset($rows['id']);
    if ($single) $rows = [$rows];
    foreach ($rows as &$r) {
        foreach ($keys as $k) {
            if (isset($r[$k])) $r[$k] = fmtDate($r[$k]);
        }
    }
    if ($single) $rows = $rows[0];
}

// ─── API HANDLERS ───────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_GET['action'];

        if ($action === 'get-stats') {
            $totalTasks = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
            $activeTasks = $pdo->query("SELECT COUNT(*) FROM tasks WHERE is_active = 1")->fetchColumn();
            $totalLogs = $pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
            $successLogs = $pdo->query("SELECT COUNT(*) FROM logs WHERE status = 'success'")->fetchColumn();
            $failedLogs = $pdo->query("SELECT COUNT(*) FROM logs WHERE status = 'error'")->fetchColumn();
            $totalServers = $pdo->query("SELECT COUNT(*) FROM servers")->fetchColumn();

            $recentLogs = $pdo->query("
                SELECT l.*, t.title AS task_title, s.name AS server_name
                FROM logs l
                LEFT JOIN tasks t ON l.task_id = t.id
                LEFT JOIN servers s ON l.server_id = s.id
                ORDER BY l.executed_at DESC LIMIT 10
            ")->fetchAll();
            fmtDates($recentLogs, ['executed_at']);

            echo json_encode([
                'total_tasks' => (int)$totalTasks,
                'active_tasks' => (int)$activeTasks,
                'total_logs' => (int)$totalLogs,
                'success_logs' => (int)$successLogs,
                'failed_logs' => (int)$failedLogs,
                'total_servers' => (int)$totalServers,
                'recent_logs' => $recentLogs,
            ]);
        }

        elseif ($action === 'list-servers') {
            $servers = $pdo->query("SELECT * FROM servers ORDER BY created_at ASC")->fetchAll();
            fmtDates($servers, ['created_at']);
            echo json_encode($servers);
        }

        elseif ($action === 'get-server') {
            $stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$_REQUEST['id'] ?? $_GET['id']]);
            $server = $stmt->fetch();
            fmtDates($server, ['created_at']);
            echo json_encode($server ?: null);
        }

        elseif ($action === 'save-server') {
            $id = $_POST['id'] ?? null;
            $name = $_POST['name'];
            $base_url = rtrim($_POST['base_url'], '/');

            if ($id) {
                $stmt = $pdo->prepare("UPDATE servers SET name = ?, base_url = ? WHERE id = ?");
                $stmt->execute([$name, $base_url, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO servers (name, base_url) VALUES (?, ?)");
                $stmt->execute([$name, $base_url]);
                $id = $pdo->lastInsertId();
            }
            echo json_encode(['ok' => true, 'id' => $id]);
        }

        elseif ($action === 'delete-server') {
            $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'list-tasks') {
            $tasks = $pdo->query("
                SELECT t.*, s.name AS server_name, s.base_url
                FROM tasks t
                JOIN servers s ON t.server_id = s.id
                ORDER BY t.created_at ASC
            ")->fetchAll();
            fmtDates($tasks, ['created_at', 'last_executed_at', 'next_execution_at']);
            echo json_encode($tasks);
        }

        elseif ($action === 'get-task') {
            $stmt = $pdo->prepare("SELECT t.*, s.name AS server_name, s.base_url FROM tasks t JOIN servers s ON t.server_id = s.id WHERE t.id = ?");
            $stmt->execute([$_REQUEST['id'] ?? $_GET['id']]);
            $task = $stmt->fetch();
            fmtDates($task, ['created_at', 'last_executed_at', 'next_execution_at']);
            echo json_encode($task ?: null);
        }

        elseif ($action === 'save-task') {
            $id = $_POST['id'] ?? null;
            $server_id = $_POST['server_id'];
            $title = $_POST['title'];
            $path = '/' . ltrim($_POST['path'], '/');
            $method = 'GET';
            $headers = $_POST['headers'] ?? null;
            $body = $_POST['body'] ?? null;
            $execute_interval = (int)($_POST['execute_interval'] ?? 60);
            $error_interval = (int)($_POST['error_interval'] ?? 30);
            $timeout = (int)($_POST['timeout'] ?? 5000);

            if ($headers && !json_decode($headers)) {
                throw new Exception('Headers must be valid JSON');
            }

            $next_exec = gmdate('Y-m-d H:i:s', time() + $execute_interval);

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE tasks SET server_id=?, title=?, path=?, method=?, headers=?, body=?,
                    execute_interval_sec=?, error_interval_sec=?, response_timeout_ms=?
                    WHERE id=?
                ");
                $stmt->execute([$server_id, $title, $path, $method, $headers, $body, $execute_interval, $error_interval, $timeout, $id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO tasks (server_id, title, path, method, headers, body, execute_interval_sec, error_interval_sec, response_timeout_ms, next_execution_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$server_id, $title, $path, $method, $headers, $body, $execute_interval, $error_interval, $timeout, $next_exec]);
                $id = $pdo->lastInsertId();
            }
            echo json_encode(['ok' => true, 'id' => $id]);
        }

        elseif ($action === 'delete-task') {
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'toggle-task') {
            $stmt = $pdo->prepare("UPDATE tasks SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'list-logs') {
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            $statusFilter = $_REQUEST['status'] ?? '';
            $taskFilter = $_REQUEST['task_id'] ?? '';

            $where = [];
            $params = [];
            if ($statusFilter) {
                $where[] = "l.status = ?";
                $params[] = $statusFilter;
            }
            if ($taskFilter) {
                $where[] = "l.task_id = ?";
                $params[] = $taskFilter;
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $total = $pdo->prepare("SELECT COUNT(*) FROM logs l {$whereClause}");
            $total->execute($params);
            $totalCount = $total->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT l.*, t.title AS task_title
                FROM logs l
                LEFT JOIN tasks t ON l.task_id = t.id
                {$whereClause}
                ORDER BY l.executed_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            fmtDates($logs, ['executed_at']);

            echo json_encode([
                'logs' => $logs,
                'total' => (int)$totalCount,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => max(1, ceil($totalCount / $perPage)),
            ]);
        }

        elseif ($action === 'get-log') {
            $stmt = $pdo->prepare("
                SELECT l.*, t.title AS task_title, s.name AS server_name
                FROM logs l
                LEFT JOIN tasks t ON l.task_id = t.id
                LEFT JOIN servers s ON l.server_id = s.id
                WHERE l.id = ?
            ");
            $stmt->execute([$_REQUEST['id'] ?? $_GET['id']]);
            $log = $stmt->fetch();
            if ($log && $log['response_body']) {
                $body = $log['response_body'];
                $decoded = json_decode($body, true);
                if ($decoded !== null) {
                    $log['response_body_pretty'] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $cleaned = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
                    $log['response_body_pretty'] = htmlspecialchars($cleaned, ENT_QUOTES, 'UTF-8');
                }
            }
            fmtDates($log, ['executed_at']);
            $json = json_encode($log ?: null);
            echo $json !== false ? $json : json_encode(['error' => 'Failed to encode log data']);
        }

        elseif ($action === 'clear-logs') {
            $pdo->exec("DELETE FROM logs");
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'stop-all-tasks') {
            $pdo->exec("UPDATE tasks SET is_active = 0");
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'start-all-tasks') {
            $pdo->exec("UPDATE tasks SET is_active = 1");
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'start-selected-tasks') {
            $ids = array_filter(explode(',', $_POST['ids'] ?? ''), 'is_numeric');
            if (!empty($ids)) {
                $pdo->prepare("UPDATE tasks SET is_active = 1 WHERE id IN (" . implode(',', $ids) . ")")->execute();
            }
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'stop-selected-tasks') {
            $ids = array_filter(explode(',', $_POST['ids'] ?? ''), 'is_numeric');
            if (!empty($ids)) {
                $pdo->prepare("UPDATE tasks SET is_active = 0 WHERE id IN (" . implode(',', $ids) . ")")->execute();
            }
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'get-settings') {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $settings = [];
            foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
            echo json_encode($settings);
        }

        elseif ($action === 'save-settings') {
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($data as $key => $value) {
                if (in_array($key, ['timezone'])) $stmt->execute([$key, $value]);
            }
            echo json_encode(['ok' => true]);
        }

        elseif ($action === 'execute-task') {
            $taskId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if (!$taskId) throw new Exception('Task ID required');

            $stmt = $pdo->prepare("SELECT t.*, s.name AS server_name, s.base_url FROM tasks t JOIN servers s ON t.server_id = s.id WHERE t.id = ? AND t.is_active = 1");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            if (!$task) throw new Exception('Task not found or inactive');

            $url = rtrim($task['base_url'], '/') . '/' . ltrim($task['path'], '/');
            $start_time = microtime(true);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int)$task['response_timeout_ms'],
                CURLOPT_CONNECTTIMEOUT_MS => (int)$task['response_timeout_ms'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            ]);
            $curl_headers = ['Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
            $method = 'GET';
            if (!empty($task['headers'])) {
                $headers = json_decode($task['headers'], true) ?: [];
                foreach ($headers as $k => $v) $curl_headers[] = "$k: $v";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $response_time_ms = (int)((microtime(true) - $start_time) * 1000);
            $error = curl_error($ch);
            curl_close($ch);

            $elapsed = $task['execute_interval_sec'];
            $status = 'success';
            $err_msg = null;
            if ($error || $http_code >= 400) {
                $status = 'error';
                $err_msg = $error ?: "HTTP {$http_code}";
                $elapsed = $task['error_interval_sec'];
            }

            $newNext = gmdate('Y-m-d H:i:s', time() + $elapsed);
            $newNextFmt = fmtDate($newNext);
            $pdo->prepare("UPDATE tasks SET last_executed_at = NOW(), next_execution_at = ? WHERE id = ?")->execute([$newNext, $taskId]);

            $logId = null;
            $prettyResponse = null;
            $decoded = json_decode($response, true);
            if ($decoded !== null && count($decoded) > 0) {
                $pdo->prepare("INSERT INTO logs (task_id, server_id, status, title, server_name, base_url, path, method, http_code, response_body, response_time_ms, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                    $taskId, $task['server_id'], $status, $task['title'], $task['server_name'], $task['base_url'], $task['path'], $method, $http_code, $response, $response_time_ms, $err_msg,
                ]);
                $logId = (int)$pdo->lastInsertId();
                $prettyResponse = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            echo json_encode([
                'ok' => true, 'log_id' => $logId, 'status' => $status,
                'http_code' => $http_code, 'response_time_ms' => $response_time_ms,
                'error_message' => $err_msg, 'next_execution_at' => $newNextFmt,
                'response_body_pretty' => $prettyResponse,
            ]);
            exit;
        }

        elseif ($action === 'download-import-template') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="import-template.txt"');
            echo "# ============================================================\n";
            echo "# IMPORT TEMPLATE (Servers & Tasks)\n";
            echo "# ============================================================\n";
            echo "# Instructions:\n";
            echo "# 1. Remove # at the start of a line to activate the data\n";
            echo "# 2. Separate columns with | (pipe)\n";
            echo "# 3. Server must exist before Tasks that reference it\n";
            echo "#\n";
            echo "# ------------------------------------------------------------\n";
            echo "# [SERVERS]\n";
            echo "# Format: Server Name | Base URL\n";
            echo "# ------------------------------------------------------------\n";
            echo "RS Random|http://localhost:8080/api\n";
            echo "\n";
            echo "# ------------------------------------------------------------\n";
            echo "# [TASKS]\n";
            echo "# Format: Server Name | Task Title | API Path | Success Interval (sec) | Error Interval (sec) | Timeout (ms)\n";
            echo "# ------------------------------------------------------------\n";
            echo "RS Random|Organization|/organization/send|60|30|5000\n";
            echo "RS Random|Patient|/patient/getIhs|60|30|5000\n";
            echo "RS Random|Check Health|/health|30|15|3000\n";
            exit;
        }

        elseif ($action === 'import-servers') {
            if (empty($_FILES['file'])) throw new Exception('File not found');
            $content = file_get_contents($_FILES['file']['tmp_name']);
            $lines = explode("\n", $content);
            $count = 0;
            $stmt = $pdo->prepare("INSERT INTO servers (name, base_url) VALUES (?, ?)");
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line || $line[0] === '#' || str_starts_with($line, '[')) continue;
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) >= 2 && $parts[0] && $parts[1]) {
                    $stmt->execute([$parts[0], rtrim($parts[1], '/')]);
                    $count++;
                }
            }
            echo json_encode(['ok' => true, 'count' => $count]);
        }

        elseif ($action === 'import-tasks') {
            if (empty($_FILES['file'])) throw new Exception('File not found');
            $content = file_get_contents($_FILES['file']['tmp_name']);
            $lines = explode("\n", $content);
            $count = 0;
            $servers = $pdo->query("SELECT name, id FROM servers")->fetchAll(PDO::FETCH_KEY_PAIR);
            $stmt = $pdo->prepare("INSERT INTO tasks (server_id, title, path, method, execute_interval_sec, error_interval_sec, response_timeout_ms, next_execution_at) VALUES (?, ?, ?, 'GET', ?, ?, ?, ?)");
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line || $line[0] === '#' || str_starts_with($line, '[')) continue;
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) >= 3) {
                    $serverName = $parts[0];
                    $title = $parts[1];
                    $path = '/' . ltrim($parts[2], '/');
                    $interval = (int)($parts[3] ?? 60);
                    $errorInterval = (int)($parts[4] ?? 30);
                    $timeout = (int)($parts[5] ?? 5000);
                    $serverId = $servers[$serverName] ?? null;
                    if ($serverId) {
                        $next = gmdate('Y-m-d H:i:s', time() + $interval);
                        $stmt->execute([$serverId, $title, $path, $interval, $errorInterval, $timeout, $next]);
                        $count++;
                    }
                }
            }
            echo json_encode(['ok' => true, 'count' => $count]);
        }

        else {
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── HTML PAGE ──────────────────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';
function page_title($p) {
    return match($p) {
        'servers' => 'Servers',
        'tasks' => 'Tasks',
        'logs' => 'Logs',
        'guide' => 'Guide',
        'settings' => 'Settings',
        default => 'Dashboard',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=page_title($page)?> - Simgos Scheduler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Topbar -->
<nav class="topbar">
    <div class="d-flex align-items-center">
        <button class="hamburger" onclick="toggleSidebar()" title="Toggle sidebar">☰</button>
        <button class="theme-btn me-2" onclick="toggleTheme()" title="Toggle theme">
            <span class="light-icon"><i class="bi bi-moon-stars"></i></span>
            <span class="dark-icon"><i class="bi bi-sun"></i></span>
        </button>
        <a href="?page=dashboard" class="topbar-brand text-decoration-none">
            <img src="assets/images/simgos-logo.png" alt="SIMGOS" width="30" height="30" style="border-radius:4px;">
            Simgos Scheduler
        </a>
    </div>
    <div class="topbar-clock">
        <span class="time" id="topbar-time">-</span>
        <span class="date" id="topbar-date">-</span>
        <span class="tz" id="topbar-tz">WIB</span>
    </div>
</nav>
<span id="sidebar-tz-iana" style="display:none;"><?=date_default_timezone_get()?></span>

<div class="app-layout">
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-inner">
            <div class="nav-label">Navigation</div>
            <ul class="nav flex-column mb-auto">
                <li class="nav-item">
                    <a href="?page=dashboard" class="nav-link <?=$page==='dashboard'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="?page=servers" class="nav-link <?=$page==='servers'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-server"></i><span>Servers</span>
                    </a>
                </li>
                <li>
                    <a href="?page=tasks" class="nav-link <?=$page==='tasks'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-list-task"></i><span>Tasks</span>
                    </a>
                </li>
                <li>
                    <a href="?page=logs" class="nav-link <?=$page==='logs'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-journal-text"></i><span>Logs</span>
                    </a>
                </li>
                <li>
                    <a href="?page=guide" class="nav-link <?=$page==='guide'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-book"></i><span>Guide</span>
                    </a>
                </li>
                <li>
                    <a href="?page=settings" class="nav-link <?=$page==='settings'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-gear"></i><span>Settings</span>
                    </a>
                </li>
            </ul>
            <div class="sidebar-footer">Develop by Tim IT RSU Martha Friska Multatuli</div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content" id="main-content">
        <?php if ($page === 'dashboard'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>
            <button class="btn btn-outline-secondary btn-sm" onclick="refreshDashboard()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
        <div class="row g-3 mb-4" id="stats-cards">
            <div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body"><h6>Servers</h6><h2 id="stat-servers">0</h2></div></div></div>
            <div class="col-md-3"><div class="card bg-success text-white"><div class="card-body"><h6>Active Tasks</h6><h2 id="stat-active">0</h2></div></div></div>
            <div class="col-md-3"><div class="card bg-info text-white"><div class="card-body"><h6>Total Executions</h6><h2 id="stat-total-logs">0</h2></div></div></div>
            <div class="col-md-3"><div class="card bg-warning text-dark"><div class="card-body"><h6>Failed</h6><h2 id="stat-failed">0</h2></div></div></div>
        </div>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-play-circle me-1"></i>Active Tasks</span>
                <small class="text-muted">Executes automatically — keep this page open</small>
            </div>
            <div class="card-body p-0" id="active-tasks-container">
                <div class="text-center text-muted py-3">Loading...</div>
            </div>
        </div>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i>Recent Executions</span>
                <small class="text-muted" id="auto-refresh-indicator">Auto-refresh: 10s</small>
            </div>
            <div class="card-body p-0" id="recent-logs-container">
                <div class="text-center text-muted py-3">Loading...</div>
            </div>
        </div>

        <?php elseif ($page === 'servers'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-server me-2"></i>Servers</h4>
            <div class="d-flex gap-1">
                <button class="btn btn-outline-secondary btn-sm" onclick="importFromFile('server')"><i class="bi bi-upload"></i> Import</button>
                <button class="btn btn-primary" onclick="openServerModal()"><i class="bi bi-plus-lg"></i> Add Server</button>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0" id="servers-table-container">
                <div class="text-center text-muted py-3">Loading...</div>
            </div>
        </div>

        <?php elseif ($page === 'tasks'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <h4 class="mb-0"><i class="bi bi-list-task me-2"></i>Tasks</h4>
            <div class="d-flex gap-1">
                <button class="btn btn-outline-success btn-sm" onclick="startSelectedTasks()"><i class="bi bi-play-circle"></i> Start Selected</button>
                <button class="btn btn-outline-danger btn-sm" onclick="stopSelectedTasks()"><i class="bi bi-stop-circle"></i> Stop Selected</button>
                <div class="border-start mx-1"></div>
                <button class="btn btn-outline-success btn-sm" onclick="startAllTasks()"><i class="bi bi-play-circle"></i> Start All</button>
                <button class="btn btn-outline-danger btn-sm" onclick="stopAllTasks()"><i class="bi bi-stop-circle"></i> Stop All</button>
                <div class="border-start mx-1"></div>
                <button class="btn btn-outline-secondary btn-sm" onclick="importFromFile('task')"><i class="bi bi-upload"></i> Import</button>
                <button class="btn btn-primary btn-sm" onclick="openTaskModal()"><i class="bi bi-plus-lg"></i> Add</button>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0" id="tasks-table-container">
                <div class="text-center text-muted py-3">Loading...</div>
            </div>
        </div>

        <?php elseif ($page === 'logs'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>Execution Logs</h4>
            <div>
                <button class="btn btn-outline-danger btn-sm me-2" onclick="clearLogs()"><i class="bi bi-trash"></i> Clear All</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshLogs()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-auto">
                <select class="form-select form-select-sm" id="log-status-filter" onchange="refreshLogs()">
                    <option value="">All Status</option>
                    <option value="success">Success</option>
                    <option value="error">Error</option>
                </select>
            </div>
            <div class="col-auto" id="log-task-filter-container">
                <select class="form-select form-select-sm" id="log-task-filter" onchange="refreshLogs()">
                    <option value="">All Tasks</option>
                </select>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0" id="logs-table-container">
                <div class="text-center text-muted py-3">Loading...</div>
            </div>
        </div>
        <nav class="mt-3" id="logs-pagination"></nav>
        <?php elseif ($page === 'guide'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-book me-2"></i>Usage Guide</h4>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">1. Add a Server</h5>
                <p class="card-text">Go to <strong>Servers</strong>, click <span class="badge bg-primary"><i class="bi bi-plus-lg"></i> Add Server</span>. Enter the server name and API base URL. You can also <span class="badge border border-secondary text-secondary"><i class="bi bi-upload"></i> Import</span> multiple servers from a .txt file using the provided template.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">2. Create a Task</h5>
                <p class="card-text">Go to <strong>Tasks</strong>, click <span class="badge bg-primary"><i class="bi bi-plus-lg"></i> Add</span>. Select a server, enter the task title, API path, execution interval (seconds), and timeout. You can also <span class="badge border border-secondary text-secondary"><i class="bi bi-upload"></i> Import</span> multiple tasks from a .txt file.</p>
                <p class="text-muted small mb-0">Note: All tasks use GET method. Headers and body are not required.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">3. Import from File</h5>
                <p class="card-text">Click <span class="badge border border-secondary text-secondary"><i class="bi bi-upload"></i> Import</span> on the Servers or Tasks page. A modal will open where you can download the template file. Edit the template with your data, then upload it. The template uses pipe (<code>|</code>) separated values. Lines starting with <code>#</code> are ignored as comments.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">4. Run Tasks</h5>
                <p class="card-text">Tasks run automatically from the <strong>Dashboard</strong>. Keep the Dashboard page open — each task has a donut indicator showing the time remaining until the next execution.</p>
                <p class="card-text">Tasks only execute while the Dashboard page is active. The more active tasks, the more requests are sent to the target API.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">5. Monitor Logs</h5>
                <p class="card-text">Go to <strong>Logs</strong> to view the execution history. Filter by status or a specific task. Click <span class="badge bg-info text-dark"><i class="bi bi-eye"></i> Detail</span> to see the API response with pretty-print and a copy button.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">6. Theme & Display</h5>
                <p class="card-text">Click the <i class="bi bi-moon-stars"></i> / <i class="bi bi-sun"></i> icon next to the hamburger menu to toggle between dark and light mode. The theme automatically follows your system preference and your choice is saved.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">7. Timezone Settings</h5>
                <p class="card-text">Go to <strong>Settings</strong> to change the timezone. This affects the clock display at the top right and the task execution schedule.</p>
                <p class="text-muted small mb-0">Note: All time data is stored in UTC in the database. The timezone only affects the display, not the data.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Important Notes</h5>
                <ul class="mb-0">
                    <li>The Dashboard page must remain open for tasks to run automatically.</li>
                    <li>After JavaScript changes, do <kbd>Cmd</kbd> + <kbd>Shift</kbd> + <kbd>R</kbd> (hard refresh).</li>
                    <li>Use the Execute Now (<i class="bi bi-play-fill"></i>) button on the Tasks page to run a task manually.</li>
                    <li>Inactive tasks will not be executed by the Dashboard.</li>
                    <li>When importing, servers must already exist in the system before tasks that reference them.</li>
                </ul>
            </div>
        </div>
        <?php elseif ($page === 'settings'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Settings</h4>
        </div>
        <div class="card">
            <div class="card-body" id="settings-container">
                <div class="text-center text-muted py-3">Loading...</div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modals -->
<div class="modal fade" id="serverModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form id="serverForm" onsubmit="return saveServer(event)">
<div class="modal-header"><h5 class="modal-title" id="serverModalTitle">Add Server</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="id" id="server-id">
<div class="mb-3"><label class="form-label">Server Name</label><input type="text" class="form-control" name="name" id="server-name" required></div>
<div class="mb-3"><label class="form-label">Base URL</label><input type="url" class="form-control" name="base_url" id="server-base-url" placeholder="https://api.example.com" required></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Save</button>
</div>
</form></div></div>
</div>

<div class="modal fade" id="taskModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form id="taskForm" onsubmit="return saveTask(event)">
<div class="modal-header"><h5 class="modal-title" id="taskModalTitle">Add Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="id" id="task-id">
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label">Server</label><select class="form-select" name="server_id" id="task-server" required></select></div>
<div class="col-md-6 mb-3"><label class="form-label">Title</label><input type="text" class="form-control" name="title" id="task-title" required></div>
</div>
<div class="mb-3"><label class="form-label">API Path</label><input type="text" class="form-control" name="path" id="task-path" placeholder="/api/endpoint" required></div>
<div class="row">
<div class="col-md-4 mb-3"><label class="form-label">Interval (sec) <small class="text-muted">success</small></label><input type="number" class="form-control" name="execute_interval" id="task-interval" value="60" min="1" required></div>
<div class="col-md-4 mb-3"><label class="form-label">Interval (sec) <small class="text-muted">error</small></label><input type="number" class="form-control" name="error_interval" id="task-error-interval" value="30" min="1" required></div>
<div class="col-md-4 mb-3"><label class="form-label">Timeout (ms)</label><input type="number" class="form-control" name="timeout" id="task-timeout" value="5000" min="100" required></div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Save</button>
</div>
</form></div></div>
</div>

<div class="modal fade" id="logModal" tabindex="-1">
<div class="modal-dialog modal-xl"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Log Detail</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="log-detail-body">
<div class="text-center text-muted py-3">Loading...</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div></div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog modal-sm"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Confirm Delete</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="deleteModalBody">Are you sure?</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="button" class="btn btn-danger" id="deleteConfirmBtn">Delete</button>
</div>
</div></div>
</div>

<div class="modal fade" id="importModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="importModalTitle">Import</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <p class="text-muted small mb-3" id="importModalDesc">Select a .txt file to import. Use the provided template.</p>
    <input type="hidden" id="import-type">
    <div class="mb-3">
        <label class="form-label">Choose File</label>
        <input type="file" class="form-control" id="import-file" accept=".txt">
    </div>
    <div id="import-result" class="d-none"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" onclick="downloadImportTemplate()"><i class="bi bi-download"></i> Download Template</button>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" class="btn btn-primary" onclick="submitImport()"><i class="bi bi-upload"></i> Import</button>
</div>
</div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
