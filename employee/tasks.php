<?php
$pageTitle = 'Tasks';
require_once '../includes/header.php';
requireEmployee();

ensureTaskWorkflowSchema();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_task_status')) {
    header('Content-Type: application/json');

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }

    $taskId = (int) ($_POST['task_id'] ?? 0);
    $status = clean_input($_POST['status'] ?? '');
    $allowedStatuses = ['pending', 'in_progress', 'completed'];

    if ($taskId < 1 || !in_array($status, $allowedStatuses, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE employee_tasks SET status = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$status, $taskId, (int) $_SESSION['user_id']]);

        if ($stmt->rowCount() < 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT DATE_FORMAT(updated_at, '%d %b %Y, %h:%i %p') as updated_at_fmt FROM employee_tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $row = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Task status updated successfully',
            'updated_at' => $row['updated_at_fmt'] ?? ''
        ]);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
        exit();
    }
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add_task_note')) {
    header('Content-Type: application/json');

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }

    $taskId = (int) ($_POST['task_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($taskId < 1 || $note === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Note is required']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM employee_tasks WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$taskId, (int) $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO task_updates (task_id, user_id, note) VALUES (?, ?, ?)");
        $stmt->execute([$taskId, (int) $_SESSION['user_id'], $note]);
        $updateId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%d %b %Y, %h:%i %p') as created_fmt FROM task_updates WHERE id = ? LIMIT 1");
        $stmt->execute([$updateId]);
        $row = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Progress note added successfully',
            'update' => [
                'id' => $updateId,
                'text' => $note,
                'created_at' => $row['created_fmt'] ?? ''
            ]
        ]);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
        exit();
    }
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'task_notes')) {
    $taskId = (int) ($_GET['task_id'] ?? 0);
    if ($taskId < 1) {
        echo "<div class='text-muted'>Invalid task.</div>";
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM employee_tasks WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$taskId, (int) $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            echo "<div class='text-muted'>Task not found.</div>";
            exit();
        }

        $stmt = $pdo->prepare("SELECT tu.note, tu.created_at, u.name
                               FROM task_updates tu
                               JOIN users u ON u.id = tu.user_id
                               WHERE tu.task_id = ?
                               ORDER BY tu.created_at DESC
                               LIMIT 20");
        $stmt->execute([$taskId]);
        $updates = $stmt->fetchAll();

        if (!$updates) {
            echo "<div class='text-muted'>No progress notes yet.</div>";
            exit();
        }

        foreach ($updates as $up) {
            $when = date('d M Y, h:i A', strtotime($up['created_at']));
            echo "<div class='p-3 rounded mb-2' style='background: rgba(255,255,255,0.03); border: 1px solid rgba(229,231,235,0.08);'>";
            echo "<div class='d-flex justify-content-between align-items-center'>";
            echo "<div class='fw-semibold'>" . htmlspecialchars($up['name']) . "</div>";
            echo "<div class='text-muted small'>" . htmlspecialchars($when) . "</div>";
            echo "</div>";
            echo "<div class='text-muted small mt-1'>" . nl2br(htmlspecialchars((string) ($up['note'] ?? ''))) . "</div>";
            echo "</div>";
        }
        exit();
    } catch (PDOException $e) {
        echo "<div class='text-muted'>Failed to load notes.</div>";
        exit();
    }
}

$tasks = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'overdue' => 0
];

$filter_q = isset($_GET['q']) ? trim((string) clean_input($_GET['q'])) : '';
$filter_client = isset($_GET['client']) ? clean_input($_GET['client']) : '';
$filter_event = isset($_GET['event']) ? clean_input($_GET['event']) : '';
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_overdue = isset($_GET['overdue']) ? clean_input($_GET['overdue']) : '';
$filter_status = $_GET['status'] ?? '';

$allowedStatuses = ['pending', 'in_progress', 'completed'];
if (!is_array($filter_status)) {
    $filter_status = $filter_status !== '' ? [$filter_status] : [];
}
$filter_status = array_values(array_filter(array_map(function($v) {
    return clean_input((string) $v);
}, $filter_status), function($v) use ($allowedStatuses) {
    return $v !== '' && in_array($v, $allowedStatuses, true);
}));

$clientsForFilter = [];
$eventsForFilter = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT c.id, c.name
                           FROM employee_tasks t
                           JOIN clients c ON c.id = t.client_id
                           WHERE t.user_id = ? AND t.client_id IS NOT NULL
                           ORDER BY c.name");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $clientsForFilter = $stmt->fetchAll();
} catch (PDOException $e) {
    $clientsForFilter = [];
}

try {
    $stmt = $pdo->prepare("SELECT DISTINCT e.id, e.name
                           FROM employee_tasks t
                           JOIN events e ON e.id = t.event_id
                           WHERE t.user_id = ? AND t.event_id IS NOT NULL
                           ORDER BY e.name");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $eventsForFilter = $stmt->fetchAll();
} catch (PDOException $e) {
    $eventsForFilter = [];
}

try {
    $query = "SELECT t.*, c.name as client_name, e.name as event_name
              FROM employee_tasks t
              LEFT JOIN clients c ON c.id = t.client_id
              LEFT JOIN events e ON e.id = t.event_id
              WHERE t.user_id = ?";
    $params = [(int) $_SESSION['user_id']];

    if ($filter_q !== '') {
        $query .= " AND (t.title LIKE ? OR t.description LIKE ?)";
        $like = '%' . $filter_q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($filter_client !== '') {
        $query .= " AND t.client_id = ?";
        $params[] = $filter_client;
    }

    if ($filter_event !== '') {
        $query .= " AND t.event_id = ?";
        $params[] = $filter_event;
    }

    if (!empty($filter_status)) {
        $placeholders = implode(',', array_fill(0, count($filter_status), '?'));
        $query .= " AND t.status IN ($placeholders)";
        foreach ($filter_status as $st) {
            $params[] = $st;
        }
    }

    if ($filter_from !== '') {
        $query .= " AND t.due_at IS NOT NULL AND DATE(t.due_at) >= ?";
        $params[] = $filter_from;
    }

    if ($filter_to !== '') {
        $query .= " AND t.due_at IS NOT NULL AND DATE(t.due_at) <= ?";
        $params[] = $filter_to;
    }

    if ($filter_overdue === '1') {
        $query .= " AND t.status <> 'completed' AND t.due_at IS NOT NULL AND t.due_at < NOW()";
    }

    $query .= " ORDER BY FIELD(t.status,'pending','in_progress','completed'), COALESCE(t.due_at, t.updated_at) ASC, t.id DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    foreach ($tasks as $t) {
        $stats['total']++;
        $s = (string) ($t['status'] ?? 'pending');
        if (isset($stats[$s])) {
            $stats[$s]++;
        }
        if ($s !== 'completed' && !empty($t['due_at']) && strtotime($t['due_at']) < time()) {
            $stats['overdue']++;
        }
    }
} catch (PDOException $e) {
    $error = 'Error fetching tasks';
    $tasks = [];
}
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Employee</div>
            <div class="sidebar-subtitle">Your work & schedule</div>
        </div>

        <div class="sidebar-quick">
            <a class="btn btn-secondary btn-sm" href="attendance.php">
                <i class="fas fa-clock me-2"></i>Attendance
            </a>
            <a class="btn btn-primary btn-sm" href="expenses.php?open=add">
                <i class="fas fa-plus me-2"></i>Expense
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="nav flex-column">
            <div class="nav-section-title">Overview</div>
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="nav-link" href="tasks.php">
                <i class="fas fa-list-check"></i> Tasks
            </a>

            <div class="nav-section-title">Work</div>
            <a class="nav-link" href="clients.php">
                <i class="fas fa-building"></i> Clients
            </a>
            <a class="nav-link" href="leads.php">
                <i class="fas fa-handshake"></i> Leads
            </a>
            <a class="nav-link" href="attendance.php">
                <i class="fas fa-clock"></i> Attendance
            </a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php">
                <i class="fas fa-money-bill-wave"></i> Expenses
            </a>
            <a class="nav-link" href="project_expense_report.php">
                <i class="fas fa-file-excel"></i> Project Expense Report
            </a>
            <a class="nav-link" href="payroll.php">
                <i class="fas fa-calculator"></i> Payroll
            </a>
            <a class="nav-link" href="salary.php">
                <i class="fas fa-wallet"></i> Salary
            </a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link" href="profile.php">
                <i class="fas fa-gear"></i> Settings
            </a>
        </nav>
    </div>
</div>

<div class="main-content" data-csrf-token="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Tasks</h1>
            <div class="page-subtitle">Update statuses, track deadlines, and add progress notes</div>
        </div>
        <div class="page-actions">
            <span class="badge bg-warning"><?php echo (int) ($stats['pending'] ?? 0); ?> pending</span>
            <span class="badge bg-primary"><?php echo (int) ($stats['in_progress'] ?? 0); ?> in progress</span>
            <span class="badge bg-success"><?php echo (int) ($stats['completed'] ?? 0); ?> completed</span>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a>
        <a class="btn btn-secondary" href="clients.php"><i class="fas fa-building me-2"></i>Clients</a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card primary h-100">
                <div class="stat-icon"><i class="fas fa-list-check"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning h-100">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['pending'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info h-100">
                <div class="stat-icon"><i class="fas fa-spinner"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['in_progress'] ?? 0); ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card danger h-100">
                <div class="stat-icon"><i class="fas fa-triangle-exclamation"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['overdue'] ?? 0); ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Filters</h5>
            <a class="btn btn-sm btn-secondary" href="tasks.php"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label">Search</label>
                        <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($filter_q); ?>" placeholder="Task title or description">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Client</label>
                        <select class="form-select js-searchable-select" name="client">
                            <option value="">All</option>
                            <?php foreach ($clientsForFilter as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $filter_client === (string) $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Event</label>
                        <select class="form-select js-searchable-select" name="event">
                            <option value="">All</option>
                            <?php foreach ($eventsForFilter as $e): ?>
                                <option value="<?php echo (int) $e['id']; ?>" <?php echo (string) $filter_event === (string) $e['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($e['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Task Status</label>
                        <select class="form-select" name="status[]" multiple>
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo in_array($st, $filter_status, true) ? 'selected' : ''; ?>>
                                    <?php echo $st === 'in_progress' ? 'In Progress' : ucfirst($st); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-1">Select multiple</div>
                    </div>

                    <div class="col-lg-3">
                        <label class="form-label">Due From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Due To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Overdue</label>
                        <select class="form-select" name="overdue">
                            <option value="">All</option>
                            <option value="1" <?php echo $filter_overdue === '1' ? 'selected' : ''; ?>>Only overdue</option>
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply</button>
                        <a class="btn btn-secondary" href="tasks.php">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Assigned Tasks</h5>
        </div>
        <div class="card-body">
            <?php if (empty($tasks)): ?>
                <p class="text-muted mb-0">No tasks assigned yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="tasksTable" data-smart-table data-export-name="my_tasks_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <?php
                                    $status = strtolower(trim((string) ($task['status'] ?? 'pending')));
                                    $badgeClass = $status === 'completed' ? 'bg-success' : ($status === 'in_progress' ? 'bg-primary' : 'bg-warning');
                                    $due = $task['due_at'] ? date('d M Y', strtotime($task['due_at'])) : '-';
                                    $isOverdue = $status !== 'completed' && $task['due_at'] && strtotime($task['due_at']) < time();
                                    $updatedAt = $task['updated_at'] ? date('d M Y, h:i A', strtotime($task['updated_at'])) : '';
                                ?>
                                <tr class="js-task-row">
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars($task['description']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($task['client_name']) || !empty($task['event_name'])): ?>
                                            <div class="text-muted small mt-1">
                                                <?php if (!empty($task['client_name'])): ?>
                                                    <span class="badge bg-info me-1"><?php echo htmlspecialchars($task['client_name']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($task['event_name'])): ?>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($task['event_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small <?php echo $isOverdue ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo htmlspecialchars($due); ?>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge bg-danger ms-1">Overdue</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="badge js-task-badge <?php echo $badgeClass; ?>">
                                                <?php echo $status === 'in_progress' ? 'In Progress' : ucfirst($status); ?>
                                            </span>
                                            <select class="form-select form-select-sm js-task-status" data-task-id="<?php echo (int) $task['id']; ?>" data-current-status="<?php echo htmlspecialchars($status); ?>">
                                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            </select>
                                        </div>
                                    </td>
                                    <td class="small text-muted js-task-updated">
                                        <?php echo $updatedAt ? ('Updated ' . htmlspecialchars($updatedAt)) : '-'; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-success" onclick="openTaskNoteModal(<?php echo (int) $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                                <i class="fas fa-comment-dots"></i>
                                            </button>
                                            <button class="btn btn-sm btn-info" onclick="viewTaskNotes(<?php echo (int) $task['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="taskNoteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskNoteTitle">Task Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="taskNoteTaskId" value="">
                <div class="mb-3">
                    <label class="form-label">Add Progress Note</label>
                    <textarea class="form-control" id="taskNoteText" rows="3" placeholder="What did you complete? Any blockers? Next steps..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="button" onclick="submitTaskNote()">Save Note</button>
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
                <div class="mt-3" id="taskNoteList">
                    <div class="text-muted">Notes will appear here after you add them.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="taskNotesViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskNotesViewTitle">Progress Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskNotesViewBody">
                <div class="text-muted">Loading...</div>
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function openTaskNoteModal(taskId, title) {
    document.getElementById('taskNoteTaskId').value = taskId;
    document.getElementById('taskNoteTitle').textContent = 'Task Notes • ' + title;
    document.getElementById('taskNoteText').value = '';
    document.getElementById('taskNoteList').innerHTML = '<div class=\"text-muted\">Add a note to start tracking progress.</div>';
    new bootstrap.Modal(document.getElementById('taskNoteModal')).show();
}

async function submitTaskNote() {
    const taskId = document.getElementById('taskNoteTaskId').value;
    const note = document.getElementById('taskNoteText').value.trim();
    if (!note) {
        showAlert('danger', 'Please enter a note.');
        return;
    }

    const csrfToken = document.querySelector('.main-content')?.dataset?.csrfToken || '';
    if (!csrfToken) {
        showAlert('danger', 'Security token missing. Please refresh the page.');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_task_note');
        formData.append('task_id', taskId);
        formData.append('note', note);
        formData.append('csrf_token', csrfToken);

        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        if (!result || !result.success) {
            throw new Error(result && result.message ? result.message : 'Failed to add note');
        }

        const list = document.getElementById('taskNoteList');
        const item = document.createElement('div');
        item.className = 'p-3 rounded mb-2';
        item.style.background = 'rgba(255,255,255,0.03)';
        item.style.border = '1px solid rgba(229,231,235,0.08)';
        item.innerHTML = '<div class=\"fw-semibold\">' + (result.update.created_at || '') + '</div><div class=\"text-muted small mt-1\"></div>';
        item.querySelector('.text-muted').textContent = result.update.text || '';
        if (list && list.querySelector('.text-muted')) {
            list.innerHTML = '';
        }
        if (list) list.prepend(item);
        document.getElementById('taskNoteText').value = '';
        showAlert('success', result.message || 'Note added successfully');
    } catch (err) {
        showAlert('danger', err && err.message ? err.message : 'Failed to add note');
    }
}

function viewTaskNotes(taskId) {
    const url = window.location.pathname + '?action=task_notes&task_id=' + encodeURIComponent(taskId);
    document.getElementById('taskNotesViewBody').innerHTML = '<div class=\"text-muted\">Loading...</div>';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            document.getElementById('taskNotesViewBody').innerHTML = html;
            if (typeof initializeActionButtonsTargets === 'function') initializeActionButtonsTargets();
            new bootstrap.Modal(document.getElementById('taskNotesViewModal')).show();
        })
        .catch(function() {
            document.getElementById('taskNotesViewBody').innerHTML = '<div class=\"text-muted\">Failed to load notes.</div>';
            new bootstrap.Modal(document.getElementById('taskNotesViewModal')).show();
        });
}
</script>
";
require_once '../includes/footer.php';
?>
