<?php
$pageTitle = 'Expense Management';
require_once '../includes/header.php';
requireEmployee();
ensureExpenseCategorizationSchema();
ensureClientWorkflowSchema();
ensureExpenseCategoriesSchema();

$success = '';
$error = '';

$personalTypeOptions = getPersonalExpenseTypeOptionsForUser((int) $_SESSION['user_id']);
$personalTypeOptions[] = 'Other';
$personalTypeSuggestionRows = listExpenseCategoriesForUser((int) $_SESSION['user_id']);
$personalTypeSuggestions = [];
foreach ($personalTypeSuggestionRows as $r) {
    $n = (string) ($r['name'] ?? '');
    if ($n !== '' && expenseCategoryKey($n) !== 'other') {
        $personalTypeSuggestions[] = $n;
    }
}
foreach (['Travel', 'Food', 'Supplies', 'Marketing', 'Communication', 'Fuel', 'Internet'] as $f) {
    if (!in_array($f, $personalTypeSuggestions, true)) $personalTypeSuggestions[] = $f;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, company FROM clients WHERE assigned_to = ? ORDER BY name ASC");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $assignedClients = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignedClients = [];
}

try {
    $stmt = $pdo->prepare("SELECT e.id, e.name, e.client_id, c.name as client_name
        FROM event_team et
        JOIN events e ON e.id = et.event_id
        LEFT JOIN clients c ON c.id = e.client_id
        WHERE et.user_id = ?
        ORDER BY e.start_date DESC, e.id DESC");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $assignedEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignedEvents = [];
}

// Filters (for table + grouping views)
$filter_client_id = (int) ($_GET['client_id'] ?? 0);
$filter_event_id = (int) ($_GET['event_id'] ?? 0);
$filter_category = strtolower(trim((string) ($_GET['category'] ?? '')));
$filter_status = strtolower(trim((string) ($_GET['status'] ?? '')));
$filter_type = trim((string) ($_GET['expense_type'] ?? ''));
$filter_from = trim((string) ($_GET['from'] ?? ''));
$filter_to = trim((string) ($_GET['to'] ?? ''));

if (!in_array($filter_category, ['client', 'personal'], true)) $filter_category = '';
if (!in_array($filter_status, ['pending', 'approved', 'rejected'], true)) $filter_status = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            try {
                // Validate date - prevent backdated entries beyond 2 days
                $expenseDate = clean_input($_POST['expense_date']);
                $today = new DateTime();
                $expDate = new DateTime($expenseDate);
                $interval = $today->diff($expDate);
                
                if ($interval->days > 2 && $expDate < $today) {
                    $error = 'Cannot add expenses older than 2 days';
                } else {
                    $expenseCategory = strtolower(trim((string) ($_POST['expense_category'] ?? 'personal')));
                    if (!in_array($expenseCategory, ['client', 'personal'], true)) {
                        $expenseCategory = 'personal';
                    }

                    $amount = clean_input($_POST['amount'] ?? '');
                    $description = clean_input($_POST['description'] ?? '');
                    $type = '';
                    $personalType = null;
                    $clientId = null;
                    $eventId = null;
                    $purpose = null;
                    $reimbursable = 0;

                    if ($expenseCategory === 'client') {
                        $type = 'Client Expense';
                        $clientId = (int) ($_POST['client_id'] ?? 0);
                        $eventId = (int) ($_POST['event_id'] ?? 0);
                        $purpose = clean_input($_POST['purpose'] ?? '');
                        $reimbursable = 1;

                        if ($clientId < 1 || $eventId < 1 || $purpose === '') {
                            $error = 'Please select a client, event, and enter the expense purpose.';
                        } else {
                            $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND assigned_to = ? LIMIT 1");
                            $stmt->execute([$clientId, (int) $_SESSION['user_id']]);
                            if (!$stmt->fetch()) {
                                $error = 'Invalid client selection.';
                            } else {
                                $stmt = $pdo->prepare("SELECT e.id
                                    FROM event_team et
                                    JOIN events e ON e.id = et.event_id
                                    WHERE et.user_id = ? AND e.id = ? AND e.client_id = ?
                                    LIMIT 1");
                                $stmt->execute([(int) $_SESSION['user_id'], $eventId, $clientId]);
                                if (!$stmt->fetch()) {
                                    $error = 'Invalid event selection for the chosen client.';
                                }
                            }
                        }
                    } else {
                        $personalType = normalizeExpenseCategoryName(clean_input($_POST['personal_type'] ?? ''));
                        if ($personalType === '') {
                            $error = 'Please select a personal expense type.';
                        } else {
                            if (expenseCategoryKey($personalType) === 'other') {
                                $customName = normalizeExpenseCategoryName(clean_input($_POST['custom_personal_type'] ?? ''));
                                if ($customName === '') {
                                    $error = 'Please enter the expense name for "Other".';
                                } else {
                                    $catRow = upsertExpenseCategory($customName, (int) $_SESSION['user_id']);
                                    $personalType = (string) ($catRow['name'] ?? $customName);
                                    $type = $personalType;
                                }
                            } else {
                                $allowed = getPersonalExpenseTypeOptionsForUser((int) $_SESSION['user_id']);
                                $allowedKeys = array_map(function($v) { return expenseCategoryKey($v); }, $allowed);
                                if (!in_array(expenseCategoryKey($personalType), $allowedKeys, true)) {
                                    $error = 'Invalid personal expense type.';
                                } else {
                                    $type = $personalType;
                                }
                            }
                        }
                    }

                    $imagePath = null;
                    if (!$error && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        try {
                            $imagePath = uploadFile($_FILES['image'], 'expenses');
                        } catch (Exception $e) {
                            $error = 'Image upload failed: ' . $e->getMessage();
                        }
                    }

                    if (!$error) {
                        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, type, personal_type, expense_category, client_id, event_id, purpose, reimbursable, amount, description, image, created_at, status)
                                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                        $stmt->execute([
                            $_SESSION['user_id'],
                            $type,
                            $personalType,
                            $expenseCategory,
                            $clientId ?: null,
                            $eventId ?: null,
                            $purpose,
                            $reimbursable,
                            $amount,
                            $description,
                            $imagePath,
                            $expenseDate . ' ' . date('H:i:s')
                        ]);

                        if ($expenseCategory === 'personal' && !empty($personalType) && expenseCategoryKey($personalType) !== 'other') {
                            try {
                                incrementExpenseCategoryUsageForUser((int) $_SESSION['user_id'], $personalType);
                            } catch (Exception $e) {}
                        }

                        $expenseId = (int) $pdo->lastInsertId();
                        if ($expenseCategory === 'client' && $eventId && $expenseId) {
                            try {
                                $stmt = $pdo->prepare("INSERT IGNORE INTO event_expenses (event_id, expense_id) VALUES (?, ?)");
                                $stmt->execute([$eventId, $expenseId]);
                            } catch (PDOException $e) {}
                        }

                        $success = 'Expense submitted successfully!';
                    }
                }
            } catch(PDOException $e) {
                $error = 'Error adding expense: ' . $e->getMessage();
            }
        }
    }
}

// Get all expenses (for dashboard totals + timeline + top client)
try {
    $stmt = $pdo->prepare("SELECT ex.*, c.name as client_name, ev.name as event_name, ev.budget as event_budget
        FROM expenses ex
        LEFT JOIN clients c ON c.id = ex.client_id
        LEFT JOIN events ev ON ev.id = ex.event_id
        WHERE ex.user_id = ?
        ORDER BY ex.created_at DESC, ex.id DESC");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $allExpenses = $stmt->fetchAll();
    
    // Calculate statistics
    $totals = [
        'client_amount' => 0,
        'personal_amount' => 0,
        'approved_count' => 0
    ];

    $clientTotals = [];
    
    foreach ($allExpenses as $expense) {
        $cat = strtolower(trim((string) ($expense['expense_category'] ?? 'personal')));
        $cat = $cat === 'client' ? 'client' : 'personal';
        $amountVal = (float) ($expense['amount'] ?? 0);

        if ($cat === 'client') {
            $totals['client_amount'] += $amountVal;
            $cid = (int) ($expense['client_id'] ?? 0);
            if (!isset($clientTotals[$cid])) $clientTotals[$cid] = 0;
            $clientTotals[$cid] += $amountVal;
        } else {
            $totals['personal_amount'] += $amountVal;
        }

        if (($expense['status'] ?? '') === 'approved') {
            $totals['approved_count']++;
        }
    }

    $topClientId = 0;
    $topClientAmount = 0;
    foreach ($clientTotals as $cid => $sum) {
        if ($sum > $topClientAmount) {
            $topClientAmount = $sum;
            $topClientId = (int) $cid;
        }
    }

    $topClientName = '';
    if ($topClientId > 0) {
        foreach ($assignedClients as $c) {
            if ((int) ($c['id'] ?? 0) === $topClientId) {
                $topClientName = (string) ($c['name'] ?? '');
                break;
            }
        }
        if ($topClientName === '') {
            foreach ($allExpenses as $ex) {
                if ((int) ($ex['client_id'] ?? 0) === $topClientId) {
                    $topClientName = (string) ($ex['client_name'] ?? '');
                    break;
                }
            }
        }
    }
    
} catch(PDOException $e) {
    $error = 'Error fetching expenses: ' . $e->getMessage();
    $allExpenses = [];
}

// Get filtered expenses (for the table + grouped view)
try {
    $query = "SELECT ex.*, c.name as client_name, ev.name as event_name, ev.budget as event_budget
        FROM expenses ex
        LEFT JOIN clients c ON c.id = ex.client_id
        LEFT JOIN events ev ON ev.id = ex.event_id
        WHERE ex.user_id = ?";
    $params = [(int) $_SESSION['user_id']];

    if ($filter_category !== '') {
        $query .= " AND COALESCE(ex.expense_category, 'personal') = ?";
        $params[] = $filter_category;
    }

    if ($filter_status !== '') {
        $query .= " AND ex.status = ?";
        $params[] = $filter_status;
    }

    if ($filter_client_id > 0) {
        $query .= " AND ex.client_id = ?";
        $params[] = $filter_client_id;
    }

    if ($filter_event_id > 0) {
        $query .= " AND ex.event_id = ?";
        $params[] = $filter_event_id;
    }

    if ($filter_type !== '') {
        if ($filter_type === 'Client Expense') {
            $query .= " AND COALESCE(ex.expense_category, 'personal') = 'client'";
        } elseif (in_array($filter_type, $personalTypeOptions, true)) {
            $query .= " AND COALESCE(ex.expense_category, 'personal') = 'personal' AND COALESCE(ex.personal_type, ex.type) = ?";
            $params[] = $filter_type;
        }
    }

    if ($filter_from !== '') {
        $query .= " AND DATE(ex.created_at) >= ?";
        $params[] = $filter_from;
    }

    if ($filter_to !== '') {
        $query .= " AND DATE(ex.created_at) <= ?";
        $params[] = $filter_to;
    }

    $query .= " ORDER BY ex.created_at DESC, ex.id DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (PDOException $e) {
    $expenses = [];
}

// Build grouped client view (from filtered expenses)
$groupedClientExpenses = [];
$personalExpenses = [];

foreach ($expenses as $ex) {
    $cat = strtolower(trim((string) ($ex['expense_category'] ?? 'personal')));
    $cat = $cat === 'client' ? 'client' : 'personal';
    if ($cat === 'client') {
        $cid = (int) ($ex['client_id'] ?? 0);
        if (!isset($groupedClientExpenses[$cid])) {
            $groupedClientExpenses[$cid] = [
                'client_id' => $cid,
                'client_name' => (string) ($ex['client_name'] ?? 'Client'),
                'total' => 0,
                'items' => []
            ];
        }
        $groupedClientExpenses[$cid]['total'] += (float) ($ex['amount'] ?? 0);
        $groupedClientExpenses[$cid]['items'][] = $ex;
    } else {
        $personalExpenses[] = $ex;
    }
}

// Project-wise tracking (events assigned to employee)
try {
    $stmt = $pdo->prepare("SELECT e.id, e.name, e.budget, e.status, c.name as client_name,
        (SELECT COALESCE(SUM(ex.amount), 0) FROM event_expenses ee
            JOIN expenses ex ON ex.id = ee.expense_id
            WHERE ee.event_id = e.id AND ex.status = 'approved' AND COALESCE(ex.expense_category, 'personal') = 'client') as total_approved_expenses,
        (SELECT COALESCE(SUM(ex.amount), 0) FROM event_expenses ee
            JOIN expenses ex ON ex.id = ee.expense_id
            WHERE ee.event_id = e.id AND ex.status = 'approved' AND COALESCE(ex.expense_category, 'personal') = 'client' AND ex.user_id = ?) as your_approved_expenses
        FROM event_team et
        JOIN events e ON e.id = et.event_id
        LEFT JOIN clients c ON c.id = e.client_id
        WHERE et.user_id = ?
        ORDER BY e.start_date DESC, e.id DESC");
    $stmt->execute([(int) $_SESSION['user_id'], (int) $_SESSION['user_id']]);
    $eventTracking = $stmt->fetchAll();
} catch (PDOException $e) {
    $eventTracking = [];
}

// Timeline (from all expenses)
$timeline = [];
foreach ($allExpenses as $ex) {
    $cat = strtolower(trim((string) ($ex['expense_category'] ?? 'personal')));
    $cat = $cat === 'client' ? 'client' : 'personal';
    $label = $cat === 'client' ? ((string) ($ex['purpose'] ?? 'Client expense')) : ((string) (($ex['personal_type'] ?? '') ?: ($ex['type'] ?? 'Personal expense')));
    $amountFmt = formatCurrency((float) ($ex['amount'] ?? 0));

    $timeline[] = [
        'ts' => (string) ($ex['created_at'] ?? ''),
        'badge' => 'bg-info',
        'title' => $amountFmt . ' added',
        'meta' => $label
    ];

    if (!empty($ex['approved_at']) && ($ex['status'] ?? '') === 'approved') {
        $timeline[] = [
            'ts' => (string) $ex['approved_at'],
            'badge' => 'bg-success',
            'title' => $amountFmt . ' approved',
            'meta' => $label
        ];
    }

    if (!empty($ex['approved_at']) && ($ex['status'] ?? '') === 'rejected') {
        $timeline[] = [
            'ts' => (string) $ex['approved_at'],
            'badge' => 'bg-danger',
            'title' => $amountFmt . ' rejected',
            'meta' => $label
        ];
    }
}

usort($timeline, function($a, $b) {
    return strtotime((string) ($b['ts'] ?? '')) <=> strtotime((string) ($a['ts'] ?? ''));
});
$timeline = array_slice($timeline, 0, 10);
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

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Expenses</h1>
            <div class="page-subtitle">Submit client and personal expenses with clear categorization</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                <i class="fas fa-receipt me-2"></i>Add Expense
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card info h-100">
                <div class="stat-icon"><i class="fas fa-building"></i></div>
                <div class="stat-value"><?php echo formatCurrency($totals['client_amount'] ?? 0); ?></div>
                <div class="stat-label">Total Client Expenses</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card primary h-100">
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-value"><?php echo formatCurrency($totals['personal_amount'] ?? 0); ?></div>
                <div class="stat-label">Total Personal Expenses</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card success h-100">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-value"><?php echo (int) ($totals['approved_count'] ?? 0); ?></div>
                <div class="stat-label">Approved Expenses</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card info h-100">
                <div class="stat-icon"><i class="fas fa-crown"></i></div>
                <div class="stat-value"><?php echo formatCurrency((float) ($topClientAmount ?? 0)); ?></div>
                <div class="stat-label">Top Spending Client</div>
                <div class="text-muted small mt-1"><?php echo htmlspecialchars($topClientName ?: '—'); ?></div>
            </div>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="fas fa-receipt me-2"></i>Add Expense
        </button>
        <button class="btn btn-secondary" type="button" onclick="openAddExpenseProof()">
            <i class="fas fa-upload me-2"></i>Upload Bill
        </button>
        <button class="btn btn-secondary" type="button" onclick="exportCurrentExpenses()">
            <i class="fas fa-download me-2"></i>Export Current View
        </button>
        <a class="btn btn-secondary" href="project_expense_report.php?open=upload">
            <i class="fas fa-file-excel me-2"></i>Submit Final Expense Sheet
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
            <a class="btn btn-sm btn-secondary" href="expenses.php"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id">
                            <option value="">All</option>
                            <?php foreach ($assignedClients as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo $filter_client_id == (int) $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Event</label>
                        <select class="form-select" name="event_id">
                            <option value="">All</option>
                            <?php foreach ($assignedEvents as $ev): ?>
                                <option value="<?php echo (int) $ev['id']; ?>" <?php echo $filter_event_id == (int) $ev['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ev['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category">
                            <option value="">All</option>
                            <option value="client" <?php echo $filter_category === 'client' ? 'selected' : ''; ?>>Client</option>
                            <option value="personal" <?php echo $filter_category === 'personal' ? 'selected' : ''; ?>>Personal</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Expense Type</label>
                        <select class="form-select" name="expense_type">
                            <option value="">All</option>
                            <option value="Client Expense" <?php echo $filter_type === 'Client Expense' ? 'selected' : ''; ?>>Client Expense</option>
                            <?php foreach ($personalTypeOptions as $t): ?>
                                <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $filter_type === $t ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-filter me-2"></i>Apply Filters</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-building me-2"></i>Client-Wise Expenses</h5>
            <span class="text-muted small"><?php echo count($groupedClientExpenses); ?> client(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($groupedClientExpenses)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-building"></i></div>
                    <div class="empty-title">No client expenses found</div>
                    <div class="empty-subtitle">Try adjusting filters or add a client expense linked to an event.</div>
                    <div class="empty-actions">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                            <i class="fas fa-receipt me-2"></i>Add Expense
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="accordion" id="clientExpenseAccordion">
                    <?php $idx = 0; foreach ($groupedClientExpenses as $cid => $group): $idx++; ?>
                        <?php
                            $collapseId = 'clientExpenseCollapse' . $idx;
                            $headingId = 'clientExpenseHeading' . $idx;
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="<?php echo $headingId; ?>">
                                <button class="accordion-button <?php echo $idx === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="<?php echo $idx === 1 ? 'true' : 'false'; ?>" aria-controls="<?php echo $collapseId; ?>">
                                    <div class="d-flex justify-content-between align-items-center w-100 gap-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($group['client_name']); ?></div>
                                        <div class="text-muted small"><?php echo formatCurrency((float) ($group['total'] ?? 0)); ?></div>
                                    </div>
                                </button>
                            </h2>
                            <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse <?php echo $idx === 1 ? 'show' : ''; ?>" aria-labelledby="<?php echo $headingId; ?>" data-bs-parent="#clientExpenseAccordion">
                                <div class="accordion-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Purpose</th>
                                                    <th>Event</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (($group['items'] ?? []) as $ex): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string) (($ex['purpose'] ?? '') ?: 'Client expense')); ?></td>
                                                        <td class="text-muted"><?php echo htmlspecialchars((string) (($ex['event_name'] ?? '') ?: '—')); ?></td>
                                                        <td><strong><?php echo formatCurrency((float) ($ex['amount'] ?? 0)); ?></strong></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo ($ex['status'] ?? '') === 'approved' ? 'success' : (($ex['status'] ?? '') === 'rejected' ? 'danger' : 'warning'); ?>">
                                                                <?php echo htmlspecialchars(ucfirst((string) ($ex['status'] ?? 'pending'))); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars(formatDate((string) ($ex['created_at'] ?? ''))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Project-Wise Tracking</h5>
            <span class="text-muted small">Budget usage</span>
        </div>
        <div class="card-body">
            <?php if (empty($eventTracking)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="empty-title">No assigned events</div>
                    <div class="empty-subtitle">When you’re assigned to an event, budget tracking will appear here.</div>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($eventTracking as $ev): ?>
                        <?php
                            $budget = (float) ($ev['budget'] ?? 0);
                            $used = (float) ($ev['total_approved_expenses'] ?? 0);
                            $remaining = $budget - $used;
                            $percent = $budget > 0 ? max(0, min(100, round(($used / $budget) * 100))) : 0;
                        ?>
                        <div class="col-xl-6">
                            <div class="panel-lite rounded p-3 h-100">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) ($ev['name'] ?? 'Event')); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars((string) ($ev['client_name'] ?? '')); ?></div>
                                    </div>
                                    <span class="badge bg-info"><?php echo htmlspecialchars(ucfirst((string) ($ev['status'] ?? 'planning'))); ?></span>
                                </div>

                                <div class="mt-3">
                                    <div class="d-flex justify-content-between text-muted small mb-1">
                                        <span>Budget usage</span>
                                        <span><?php echo formatCurrency($used); ?> / <?php echo formatCurrency($budget); ?></span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (int) $percent; ?>%"></div>
                                    </div>
                                    <div class="text-muted small mt-2">Remaining: <?php echo formatCurrency($remaining); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-clock-rotate-left me-2"></i>Expense Timeline</h5>
            <span class="text-muted small">Latest activity</span>
        </div>
        <div class="card-body">
            <?php if (empty($timeline)): ?>
                <p class="text-muted mb-0">No activity yet.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($timeline as $t): ?>
                        <div class="panel-lite rounded p-3 d-flex justify-content-between align-items-start gap-3">
                            <div class="d-flex gap-2 align-items-start">
                                <span class="badge <?php echo htmlspecialchars((string) ($t['badge'] ?? 'bg-info')); ?>"><?php echo htmlspecialchars((string) ($t['title'] ?? '')); ?></span>
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string) ($t['meta'] ?? '')); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) ($t['ts'] ?? 'now')))); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Expenses Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-table me-2"></i>Expense History</h5>
            <span class="text-muted small"><?php echo count($expenses); ?> item(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($expenses)): ?>
                <p class="text-muted">No expenses found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="expenseDetailedTable">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Event</th>
                                <th>Purpose</th>
                                <th>Expense Type</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Bill</th>
                                <th>Approval</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <?php
                                    $cat = strtolower(trim((string) ($expense['expense_category'] ?? 'personal')));
                                    $cat = $cat === 'client' ? 'client' : 'personal';
                                    $expenseTypeLabel = $cat === 'client'
                                        ? 'Client Expense'
                                        : (string) (($expense['personal_type'] ?? '') ?: ($expense['type'] ?? 'Personal'));
                                ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <?php echo htmlspecialchars($cat === 'client' ? (($expense['client_name'] ?? '') ?: 'Client') : '—'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($cat === 'client' ? (($expense['event_name'] ?? '') ?: '—') : '—'); ?></td>
                                    <td><?php echo htmlspecialchars($cat === 'client' ? ((string) (($expense['purpose'] ?? '') ?: '—')) : '—'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $cat === 'client' ? 'bg-info' : 'bg-primary'; ?>">
                                            <?php echo htmlspecialchars($expenseTypeLabel); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($expense['amount']); ?></strong>
                                    </td>
                                    <td class="text-muted small"><?php echo formatDate($expense['created_at']); ?></td>
                                    <td>
                                        <?php if ($expense['image']): ?>
                                            <?php if (pathinfo($expense['image'], PATHINFO_EXTENSION) == 'pdf'): ?>
                                                <a href="<?php echo SITE_URL . 'uploads/' . $expense['image']; ?>" target="_blank" class="btn btn-sm btn-info">
                                                    <i class="fas fa-file-pdf"></i> View
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-info" onclick="viewImage('<?php echo SITE_URL . 'uploads/' . $expense['image']; ?>')">
                                                    <i class="fas fa-image"></i> View
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No bill</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $expense['status'] == 'approved' ? 'success' : 
                                                 ($expense['status'] == 'rejected' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($expense['status']); ?>
                                        </span>
                                        <?php if ($expense['approved_at']): ?>
                                            <br><small class="text-muted"><?php echo formatDate($expense['approved_at'], 'd M Y'); ?></small>
                                        <?php endif; ?>
                                        <?php if ($expense['status'] == 'rejected' && !empty($expense['rejection_reason'])): ?>
                                            <br><small class="text-danger"><?php echo htmlspecialchars($expense['rejection_reason']); ?></small>
                                        <?php endif; ?>
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

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Add Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Expense Category *</label>
                        <div class="btn-group w-100" role="group" aria-label="Expense category">
                            <input type="radio" class="btn-check" name="expense_category" id="expCatClient" value="client">
                            <label class="btn btn-outline-secondary" for="expCatClient"><i class="fas fa-building me-2"></i>Client Expense</label>
                            <input type="radio" class="btn-check" name="expense_category" id="expCatPersonal" value="personal" checked>
                            <label class="btn btn-outline-secondary" for="expCatPersonal"><i class="fas fa-wallet me-2"></i>Personal Expense</label>
                        </div>
                        <div class="text-muted small mt-2">Client expenses can be linked to an event for reporting and budget tracking.</div>
                    </div>

                    <div id="clientFields" class="d-none">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Client *</label>
                                <select class="form-select" name="client_id" id="clientSelect">
                                    <option value="">Select Client</option>
                                    <?php foreach ($assignedClients as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['name']); ?><?php echo $c['company'] ? (' • ' . htmlspecialchars($c['company'])) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Event *</label>
                                <select class="form-select" name="event_id" id="eventSelect">
                                    <option value="">Select Event</option>
                                    <?php foreach ($assignedEvents as $ev): ?>
                                        <option value="<?php echo (int) $ev['id']; ?>" data-client-id="<?php echo (int) ($ev['client_id'] ?? 0); ?>">
                                            <?php echo htmlspecialchars($ev['name']); ?><?php echo $ev['client_name'] ? (' • ' . htmlspecialchars($ev['client_name'])) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Expense Purpose *</label>
                                <input type="text" class="form-control" name="purpose" placeholder="e.g., Venue advance, vendor payment, client meeting logistics">
                            </div>
                        </div>
                        <div class="my-3 sidebar-divider"></div>
                    </div>

                    <div id="personalFields">
                        <div class="mb-3">
                            <label class="form-label">Personal Expense Type *</label>
                            <input type="text" class="form-control form-control-sm mb-2" id="personalTypeSearch" placeholder="Search expense type...">
                            <select class="form-select" name="personal_type" id="personalTypeSelect">
                                <option value="">Select Type</option>
                                <?php foreach ($personalTypeOptions as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="personalOtherWrap" class="expense-other-wrap d-none mt-2">
                                <label class="form-label">Enter Expense Name *</label>
                                <input type="text" class="form-control" name="custom_personal_type" id="customPersonalType" placeholder="Enter Expense Name" list="expenseTypeSuggestions" autocomplete="off">
                                <div class="form-text text-muted">Example: Parking Fee</div>
                            </div>
                            <datalist id="expenseTypeSuggestions">
                                <?php foreach ($personalTypeSuggestions as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Amount *</label>
                                <input type="number" class="form-control" name="amount" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date *</label>
                                <input type="date" class="form-control" name="expense_date" value="<?php echo getCurrentDate(); ?>" max="<?php echo getCurrentDate(); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-upload me-2"></i>Bill / Proof Upload</label>
                        <div class="upload-area">
                            <input type="file" class="form-control" name="image" accept="image/*,.pdf" id="image">
                            <div class="text-muted small mt-2">Upload image or PDF as proof (optional)</div>
                            <div id="image_preview" class="mt-2"></div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Note: You cannot add expenses older than 2 days from today.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Expense Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="img-fluid" alt="Expense Proof">
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function viewImage(imageUrl) {
    document.getElementById('modalImage').src = imageUrl;
    const el = document.getElementById('imageModal');
    if (!el) return;
    bootstrap.Modal.getOrCreateInstance(el).show();
}

function setExpenseCategory(category) {
    const clientFields = document.getElementById('clientFields');
    const personalFields = document.getElementById('personalFields');
    const personalSelect = document.getElementById('personalTypeSelect');
    const otherWrap = document.getElementById('personalOtherWrap');
    const customInput = document.getElementById('customPersonalType');
    const clientSelect = document.getElementById('clientSelect');
    const eventSelect = document.getElementById('eventSelect');

    if (category === 'client') {
        clientFields?.classList.remove('d-none');
        personalFields?.classList.add('d-none');
        if (personalSelect) personalSelect.required = false;
        if (otherWrap) {
            otherWrap.classList.add('d-none');
            otherWrap.classList.remove('is-visible');
        }
        if (customInput) {
            customInput.required = false;
            customInput.value = '';
        }
        if (clientSelect) clientSelect.required = true;
        if (eventSelect) eventSelect.required = true;
    } else {
        clientFields?.classList.add('d-none');
        personalFields?.classList.remove('d-none');
        if (personalSelect) personalSelect.required = true;
        if (clientSelect) clientSelect.required = false;
        if (eventSelect) eventSelect.required = false;
    }
}

document.getElementById('expCatClient')?.addEventListener('change', function() { setExpenseCategory('client'); });
document.getElementById('expCatPersonal')?.addEventListener('change', function() { setExpenseCategory('personal'); });
setExpenseCategory(document.getElementById('expCatClient')?.checked ? 'client' : 'personal');

function filterEventsByClient(clientId) {
    const select = document.getElementById('eventSelect');
    if (!select) return;
    const wanted = parseInt(clientId || '0', 10) || 0;
    Array.from(select.options).forEach(function(opt) {
        if (!opt.value) return;
        const cid = parseInt(opt.dataset.clientId || '0', 10) || 0;
        opt.hidden = wanted > 0 && cid > 0 && cid !== wanted;
    });
    if (select.selectedOptions.length && select.selectedOptions[0].hidden) {
        select.value = '';
    }
}

document.getElementById('clientSelect')?.addEventListener('change', function(e) {
    filterEventsByClient(e.target.value);
});

function syncPersonalOtherField() {
    const personalSelect = document.getElementById('personalTypeSelect');
    const otherWrap = document.getElementById('personalOtherWrap');
    const customInput = document.getElementById('customPersonalType');
    if (!personalSelect || !otherWrap || !customInput) return;

    const isOther = (String(personalSelect.value || '').toLowerCase() === 'other');
    if (isOther) {
        otherWrap.classList.remove('d-none');
        requestAnimationFrame(function() {
            otherWrap.classList.add('is-visible');
        });
        customInput.required = true;
        if (!customInput.value) {
            setTimeout(function() { customInput.focus(); }, 0);
        }
    } else {
        otherWrap.classList.remove('is-visible');
        setTimeout(function() {
            otherWrap.classList.add('d-none');
        }, 180);
        customInput.required = false;
        customInput.value = '';
    }
}

document.getElementById('personalTypeSelect')?.addEventListener('change', syncPersonalOtherField);
syncPersonalOtherField();

document.getElementById('personalTypeSearch')?.addEventListener('input', function(e) {
    const q = String(e.target.value || '').trim().toLowerCase();
    const sel = document.getElementById('personalTypeSelect');
    if (!sel) return;
    Array.from(sel.options).forEach(function(opt) {
        if (!opt.value) return;
        const t = String(opt.text || '').toLowerCase();
        opt.hidden = q !== '' && !t.includes(q);
    });
    const selected = sel.selectedOptions && sel.selectedOptions[0] ? sel.selectedOptions[0] : null;
    if (selected && selected.hidden) {
        selected.hidden = false;
    }
});

function openAddExpenseProof() {
    const modalEl = document.getElementById('addExpenseModal');
    if (!modalEl) return;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    modalEl.addEventListener('shown.bs.modal', function() {
        const input = document.getElementById('image');
        if (!input) return;
        input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        input.focus();
    }, { once: true });
}

function exportCurrentExpenses() {
    const table = document.getElementById('expenseDetailedTable');
    if (!table) return;
    exportToCSV('expenseDetailedTable', 'employee_expenses_export.csv');
}


</script>
";
require_once '../includes/footer.php';
?>
