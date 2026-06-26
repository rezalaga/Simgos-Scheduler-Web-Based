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
            $pdo->prepare("INSERT INTO logs (task_id, server_id, status, title, server_name, base_url, path, method, http_code, response_body, response_time_ms, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $taskId, $task['server_id'], $status, $task['title'], $task['server_name'], $task['base_url'], $task['path'], $method, $http_code, $response ?: '', $response_time_ms, $err_msg,
            ]);
            $logId = $pdo->lastInsertId();

            $prettyResponse = null;
            if ($response) {
                $decoded = json_decode($response, true);
                $prettyResponse = $decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response;
            }

            echo json_encode([
                'ok' => true, 'log_id' => (int)$logId, 'status' => $status,
                'http_code' => $http_code, 'response_time_ms' => $response_time_ms,
                'error_message' => $err_msg, 'next_execution_at' => $newNextFmt,
                'response_body_pretty' => $prettyResponse,
            ]);
            exit;
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
        'servers' => 'Server',
        'tasks' => 'Tugas',
        'logs' => 'Log',
        'guide' => 'Panduan',
        'settings' => 'Pengaturan',
        default => 'Dasbor',
    };
}
?>
<!DOCTYPE html>
<html lang="id">
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
        <button class="hamburger" onclick="toggleSidebar()" title="Alihkan sidebar">☰</button>
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
            <div class="nav-label">Navigasi</div>
            <ul class="nav flex-column mb-auto">
                <li class="nav-item">
                    <a href="?page=dashboard" class="nav-link <?=$page==='dashboard'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-speedometer2"></i><span>Dasbor</span>
                    </a>
                </li>
                <li>
                    <a href="?page=servers" class="nav-link <?=$page==='servers'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-server"></i><span>Server</span>
                    </a>
                </li>
                <li>
                    <a href="?page=tasks" class="nav-link <?=$page==='tasks'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-list-task"></i><span>Tugas</span>
                    </a>
                </li>
                <li>
                    <a href="?page=logs" class="nav-link <?=$page==='logs'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-journal-text"></i><span>Log</span>
                    </a>
                </li>
                <li>
                    <a href="?page=guide" class="nav-link <?=$page==='guide'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-book"></i><span>Panduan</span>
                    </a>
                </li>
                <li>
                    <a href="?page=settings" class="nav-link <?=$page==='settings'?'active':''?>" onclick="closeSidebarMobile()">
                        <i class="bi bi-gear"></i><span>Pengaturan</span>
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
            <h4 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Dasbor</h4>
            <button class="btn btn-outline-secondary btn-sm" onclick="refreshDashboard()"><i class="bi bi-arrow-clockwise"></i> Segarkan</button>
        </div>
        <div class="row g-3 mb-4" id="stats-cards">
            <div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body"><h6>Server</h6><h2 id="stat-servers">0</h2></div></div></div>
            <div class="col-md-3"><div class="card bg-success text-white"><div class="card-body"><h6>Tugas Aktif</h6><h2 id="stat-active">0</h2></div></div></div>
            <div class="col-md-3"><div class="card bg-info text-white"><div class="card-body"><h6>Total Eksekusi</h6><h2 id="stat-total-logs">0</h2></div></div></div>
            <div class="col-md-3"><div class="card bg-warning text-dark"><div class="card-body"><h6>Gagal</h6><h2 id="stat-failed">0</h2></div></div></div>
        </div>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-play-circle me-1"></i>Tugas Aktif</span>
                <small class="text-muted">Eksekusi otomatis — biarkan halaman ini terbuka</small>
            </div>
            <div class="card-body p-0" id="active-tasks-container">
                <div class="text-center text-muted py-3">Memuat...</div>
            </div>
        </div>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i>Eksekusi Terbaru</span>
                <small class="text-muted" id="auto-refresh-indicator">Muat ulang: 10 detik</small>
            </div>
            <div class="card-body p-0" id="recent-logs-container">
                <div class="text-center text-muted py-3">Memuat...</div>
            </div>
        </div>

        <?php elseif ($page === 'servers'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-server me-2"></i>Server</h4>
            <button class="btn btn-primary" onclick="openServerModal()"><i class="bi bi-plus-lg"></i> Tambah Server</button>
        </div>
        <div class="card">
            <div class="card-body p-0" id="servers-table-container">
                <div class="text-center text-muted py-3">Memuat...</div>
            </div>
        </div>

        <?php elseif ($page === 'tasks'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <h4 class="mb-0"><i class="bi bi-list-task me-2"></i>Tugas</h4>
            <div class="d-flex gap-1">
                <button class="btn btn-outline-success btn-sm" onclick="startSelectedTasks()"><i class="bi bi-play-circle"></i> Mulai Dipilih</button>
                <button class="btn btn-outline-danger btn-sm" onclick="stopSelectedTasks()"><i class="bi bi-stop-circle"></i> Hentikan Dipilih</button>
                <div class="border-start mx-1"></div>
                <button class="btn btn-outline-success btn-sm" onclick="startAllTasks()"><i class="bi bi-play-circle"></i> Mulai Semua</button>
                <button class="btn btn-outline-danger btn-sm" onclick="stopAllTasks()"><i class="bi bi-stop-circle"></i> Hentikan Semua</button>
                <div class="border-start mx-1"></div>
                <button class="btn btn-primary btn-sm" onclick="openTaskModal()"><i class="bi bi-plus-lg"></i> Tambah</button>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0" id="tasks-table-container">
                <div class="text-center text-muted py-3">Memuat...</div>
            </div>
        </div>

        <?php elseif ($page === 'logs'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>Log Eksekusi</h4>
            <div>
                <button class="btn btn-outline-danger btn-sm me-2" onclick="clearLogs()"><i class="bi bi-trash"></i> Hapus Semua</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshLogs()"><i class="bi bi-arrow-clockwise"></i> Segarkan</button>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-auto">
                <select class="form-select form-select-sm" id="log-status-filter" onchange="refreshLogs()">
                    <option value="">Semua Status</option>
                    <option value="success">Sukses</option>
                    <option value="error">Gagal</option>
                </select>
            </div>
            <div class="col-auto" id="log-task-filter-container">
                <select class="form-select form-select-sm" id="log-task-filter" onchange="refreshLogs()">
                    <option value="">Semua Tugas</option>
                </select>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0" id="logs-table-container">
                <div class="text-center text-muted py-3">Memuat...</div>
            </div>
        </div>
        <nav class="mt-3" id="logs-pagination"></nav>
        <?php elseif ($page === 'guide'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-book me-2"></i>Panduan Penggunaan</h4>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">1. Tambahkan Server</h5>
                <p class="card-text">Masuk ke menu <strong>Server</strong>, klik <span class="badge bg-primary"><i class="bi bi-plus-lg"></i> Tambah Server</span>. Isi nama server dan URL endpoint API yang akan dijadwalkan.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">2. Buat Tugas</h5>
                <p class="card-text">Masuk ke menu <strong>Tugas</strong>, klik <span class="badge bg-primary"><i class="bi bi-plus-lg"></i> Tambah</span>. Pilih server yang sudah dibuat, isi judul tugas, path API, interval eksekusi (dalam detik), dan batas waktu.</p>
                <p class="text-muted small mb-0">Catatan: Semua tugas menggunakan metode GET. Header dan body tidak diperlukan.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">3. Jalankan Tugas</h5>
                <p class="card-text">Tugas akan berjalan otomatis melalui halaman <strong>Dasbor</strong>. Cukup biarkan halaman Dasbor terbuka — setiap tugas memiliki indikator donut yang menunjukkan waktu tersisa hingga eksekusi berikutnya.</p>
                <p class="card-text">Tugas hanya akan berjalan jika halaman Dasbor aktif. Semakin banyak tugas aktif, semakin sering halaman melakukan permintaan ke API target.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">4. Pantau Log</h5>
                <p class="card-text">Masuk ke menu <strong>Log</strong> untuk melihat riwayat eksekusi. Filter berdasarkan status atau tugas tertentu. Klik tombol <span class="badge bg-info text-dark"><i class="bi bi-eye"></i> Detail</span> untuk melihat respons API.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">5. Pengaturan Zona Waktu</h5>
                <p class="card-text">Masuk ke menu <strong>Pengaturan</strong> untuk mengubah zona waktu. Pengaturan ini memengaruhi tampilan jam di pojok kanan atas dan jadwal eksekusi tugas.</p>
                <p class="text-muted small mb-0">Catatan: Semua data waktu disimpan dalam UTC di database. Zona waktu hanya mengubah tampilan, bukan data.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Catatan Penting</h5>
                <ul class="mb-0">
                    <li>Halaman Dasbor harus tetap terbuka agar tugas berjalan otomatis.</li>
                    <li>Setelah perubahan kode JavaScript, lakukan <kbd>Cmd</kbd> + <kbd>Shift</kbd> + <kbd>R</kbd> (muat ulang paksa).</li>
                    <li>Gunakan tombol Jalankan Sekarang (<i class="bi bi-play-fill"></i>) di halaman Tugas untuk menjalankan tugas secara manual.</li>
                    <li>Tugas yang dinonaktifkan (Nonaktif) tidak akan dijalankan oleh Dasbor.</li>
                </ul>
            </div>
        </div>
        <?php elseif ($page === 'settings'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Pengaturan</h4>
        </div>
        <div class="card">
            <div class="card-body" id="settings-container">
                <div class="text-center text-muted py-3">Memuat...</div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modals -->
<div class="modal fade" id="serverModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form id="serverForm" onsubmit="return saveServer(event)">
<div class="modal-header"><h5 class="modal-title" id="serverModalTitle">Tambah Server</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="id" id="server-id">
<div class="mb-3"><label class="form-label">Nama Server</label><input type="text" class="form-control" name="name" id="server-name" required></div>
<div class="mb-3"><label class="form-label">URL Dasar</label><input type="url" class="form-control" name="base_url" id="server-base-url" placeholder="https://api.example.com" required></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
<button type="submit" class="btn btn-primary">Simpan</button>
</div>
</form></div></div>
</div>

<div class="modal fade" id="taskModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form id="taskForm" onsubmit="return saveTask(event)">
<div class="modal-header"><h5 class="modal-title" id="taskModalTitle">Tambah Tugas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="id" id="task-id">
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label">Server</label><select class="form-select" name="server_id" id="task-server" required></select></div>
<div class="col-md-6 mb-3"><label class="form-label">Judul</label><input type="text" class="form-control" name="title" id="task-title" required></div>
</div>
<div class="mb-3"><label class="form-label">Path API</label><input type="text" class="form-control" name="path" id="task-path" placeholder="/api/endpoint" required></div>
<div class="row">
<div class="col-md-4 mb-3"><label class="form-label">Interval (detik) <small class="text-muted">sukses</small></label><input type="number" class="form-control" name="execute_interval" id="task-interval" value="60" min="1" required></div>
<div class="col-md-4 mb-3"><label class="form-label">Interval (detik) <small class="text-muted">gagal</small></label><input type="number" class="form-control" name="error_interval" id="task-error-interval" value="30" min="1" required></div>
<div class="col-md-4 mb-3"><label class="form-label">Batas Waktu (ms)</label><input type="number" class="form-control" name="timeout" id="task-timeout" value="5000" min="100" required></div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
<button type="submit" class="btn btn-primary">Simpan</button>
</div>
</form></div></div>
</div>

<div class="modal fade" id="logModal" tabindex="-1">
<div class="modal-dialog modal-xl"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Detail Log</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="log-detail-body">
<div class="text-center text-muted py-3">Memuat...</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button></div>
</div></div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog modal-sm"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Konfirmasi Hapus</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="deleteModalBody">Yakin ingin menghapus?</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
<button type="button" class="btn btn-danger" id="deleteConfirmBtn">Hapus</button>
</div>
</div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
