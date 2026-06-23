<?php
$pageTitle = 'Expense Management';
require_once '../includes/header.php';
requireAdmin();
ensureExpenseCategorizationSchema();
ensureExpenseCategoriesSchema();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'approve') {
            try {
                $expId = (int)clean_input($_POST['expense_id']);
                // Fetch expense details before updating for notification
                $expRow = null;
                try {
                    $stmtExp = $pdo->prepare("SELECT e.*, u.name as emp_name, emp.phone as emp_phone FROM expenses e JOIN users u ON u.id = e.user_id LEFT JOIN employees emp ON emp.user_id = e.user_id WHERE e.id = ? LIMIT 1");
                    $stmtExp->execute([$expId]);
                    $expRow = $stmtExp->fetch();
                } catch (PDOException $ex) {}

                $stmt = $pdo->prepare("UPDATE expenses SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $expId]);
                $success = 'Expense approved successfully!';

                // WhatsApp notification
                if ($expRow && wa_isTriggerEnabled('expense_approved')) {
                    $empPhone = trim((string)($expRow['emp_phone'] ?? ''));
                    if ($empPhone !== '') {
                        sendWhatsAppMessage($empPhone, 'expense_approved', [
                            (string)($expRow['emp_name'] ?? 'Employee'),
                            formatCurrency((float)($expRow['amount'] ?? 0)),
                            (string)(($expRow['personal_type'] ?? '') ?: ($expRow['type'] ?? 'Expense')),
                        ], ['related_type' => 'expense', 'related_id' => $expId, 'user_id' => (int)($expRow['user_id'] ?? 0)]);
                    }
                }
            } catch(PDOException $e) {
                $error = 'Error approving expense: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'reject') {
            try {
                $expId = (int)clean_input($_POST['expense_id']);
                $rejectionReason = clean_input($_POST['rejection_reason'] ?? '');
                // Fetch expense details before updating
                $expRow = null;
                try {
                    $stmtExp = $pdo->prepare("SELECT e.*, u.name as emp_name, emp.phone as emp_phone FROM expenses e JOIN users u ON u.id = e.user_id LEFT JOIN employees emp ON emp.user_id = e.user_id WHERE e.id = ? LIMIT 1");
                    $stmtExp->execute([$expId]);
                    $expRow = $stmtExp->fetch();
                } catch (PDOException $ex) {}

                $stmt = $pdo->prepare("UPDATE expenses SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $rejectionReason, $expId]);
                $success = 'Expense rejected successfully!';

                // WhatsApp notification
                if ($expRow && wa_isTriggerEnabled('expense_rejected')) {
                    $empPhone = trim((string)($expRow['emp_phone'] ?? ''));
                    if ($empPhone !== '') {
                        sendWhatsAppMessage($empPhone, 'expense_rejected', [
                            (string)($expRow['emp_name'] ?? 'Employee'),
                            formatCurrency((float)($expRow['amount'] ?? 0)),
                            (string)(($expRow['personal_type'] ?? '') ?: ($expRow['type'] ?? 'Expense')),
                            'Please contact admin for more information.',
                        ], ['related_type' => 'expense', 'related_id' => $expId, 'user_id' => (int)($expRow['user_id'] ?? 0)]);
                    }
                }
            } catch(PDOException $e) {
                $error = 'Error rejecting expense: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'delete') {
            try {
                $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
                $stmt->execute([clean_input($_POST['expense_id'])]);
                $success = 'Expense deleted successfully!';
            } catch(PDOException $e) {
                $error = 'Error deleting expense: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'save_category_settings') {
            try {
                $scope = strtolower(trim((string) ($_POST['expense_custom_category_scope'] ?? 'user')));
                if (!in_array($scope, ['user', 'global'], true)) $scope = 'user';
                $requireApproval = isset($_POST['expense_custom_category_require_approval']) ? '1' : '0';
                setAppSetting('expense_custom_category_scope', $scope);
                setAppSetting('expense_custom_category_require_approval', $requireApproval);
                $success = 'Expense category settings updated.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'approve_category') {
            try {
                $id = (int) clean_input($_POST['category_id'] ?? 0);
                if ($id < 1) throw new RuntimeException('Invalid category.');
                $stmt = $pdo->prepare("UPDATE expense_categories SET status = 'approved' WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $success = 'Category approved.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'reject_category') {
            try {
                $id = (int) clean_input($_POST['category_id'] ?? 0);
                if ($id < 1) throw new RuntimeException('Invalid category.');
                $stmt = $pdo->prepare("UPDATE expense_categories SET status = 'rejected' WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $success = 'Category rejected.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'disable_category') {
            try {
                $id = (int) clean_input($_POST['category_id'] ?? 0);
                if ($id < 1) throw new RuntimeException('Invalid category.');
                $stmt = $pdo->prepare("SELECT usage_count FROM expense_categories WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) throw new RuntimeException('Category not found.');
                if ((int) ($row['usage_count'] ?? 0) > 0) {
                    throw new RuntimeException('Cannot delete a category that has been used.');
                }
                $stmt = $pdo->prepare("UPDATE expense_categories SET is_active = 0 WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $success = 'Category removed.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'edit_category') {
            try {
                $id = (int) clean_input($_POST['category_id'] ?? 0);
                $newName = normalizeExpenseCategoryName(clean_input($_POST['category_name'] ?? ''));
                if ($id < 1) throw new RuntimeException('Invalid category.');
                if ($newName === '') throw new RuntimeException('Category name is required.');
                $newNameLen = function_exists('mb_strlen') ? mb_strlen($newName, 'UTF-8') : strlen($newName);
                if ($newNameLen > 80) throw new RuntimeException('Category name is too long.');

                $stmt = $pdo->prepare("SELECT scope, created_by_user_id FROM expense_categories WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) throw new RuntimeException('Category not found.');

                $scope = (string) ($row['scope'] ?? 'user');
                $createdBy = (int) ($row['created_by_user_id'] ?? 0);
                $key = expenseCategoryKey($newName);

                $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE scope = ? AND created_by_user_id = ? AND name_key = ? AND id <> ? LIMIT 1");
                $stmt->execute([$scope, $createdBy, $key, $id]);
                if ($stmt->fetch()) {
                    throw new RuntimeException('A category with this name already exists.');
                }

                $stmt = $pdo->prepare("UPDATE expense_categories SET name = ?, name_key = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                $stmt->execute([$newName, $key, $id]);
                $success = 'Category updated.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filter_q = isset($_GET['q']) ? trim((string) clean_input($_GET['q'])) : '';
$filter_status = $_GET['status'] ?? '';
$filter_employee = isset($_GET['employee']) ? clean_input($_GET['employee']) : '';
$filter_client = isset($_GET['client']) ? clean_input($_GET['client']) : '';
$filter_event = isset($_GET['event']) ? clean_input($_GET['event']) : '';
$filter_month = isset($_GET['month']) ? clean_input($_GET['month']) : getCurrentMonth();
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_category = isset($_GET['category']) ? clean_input($_GET['category']) : '';
$filter_expense_type = isset($_GET['expense_type']) ? clean_input($_GET['expense_type']) : '';
$filter_amount_min = isset($_GET['amount_min']) ? clean_input($_GET['amount_min']) : '';
$filter_amount_max = isset($_GET['amount_max']) ? clean_input($_GET['amount_max']) : '';

$categorySettings = getExpenseCategorySettings();
try {
    $stmt = $pdo->query("SELECT ec.*, u.name as creator_name, u.email as creator_email
                         FROM expense_categories ec
                         LEFT JOIN users u ON u.id = ec.created_by_user_id
                         ORDER BY (ec.status = 'pending') DESC, ec.is_active DESC, ec.scope DESC, ec.usage_count DESC, ec.name ASC");
    $expenseCategories = $stmt->fetchAll();
} catch (PDOException $e) {
    $expenseCategories = [];
}

$allowedStatuses = ['pending', 'approved', 'rejected'];
if (!is_array($filter_status)) {
    $filter_status = $filter_status !== '' ? [$filter_status] : [];
}
$filter_status = array_values(array_filter(array_map(function($v) {
    return clean_input((string) $v);
}, $filter_status), function($v) use ($allowedStatuses) {
    return $v !== '' && in_array($v, $allowedStatuses, true);
}));

// Get expenses
try {
    $query = "SELECT e.*, u.name, u.email, e2.designation,
                     c.name as client_name,
                     ev.name as event_name,
                     ev.budget as event_budget,
                     ev.status as event_status,
                     ev.start_date as event_start_date,
                     ev.end_date as event_end_date
              FROM expenses e
              JOIN users u ON e.user_id = u.id
              LEFT JOIN employees e2 ON e2.user_id = u.id
              LEFT JOIN clients c ON c.id = e.client_id
              LEFT JOIN events ev ON ev.id = e.event_id
              WHERE 1=1";
    
    $params = [];
    
    if (!empty($filter_status)) {
        $placeholders = implode(',', array_fill(0, count($filter_status), '?'));
        $query .= " AND e.status IN ($placeholders)";
        foreach ($filter_status as $st) {
            $params[] = $st;
        }
    }
    
    if ($filter_from !== '' || $filter_to !== '') {
        if ($filter_from !== '') {
            $query .= " AND DATE(e.created_at) >= ?";
            $params[] = $filter_from;
        }
        if ($filter_to !== '') {
            $query .= " AND DATE(e.created_at) <= ?";
            $params[] = $filter_to;
        }
    } elseif ($filter_month) {
        $query .= " AND DATE_FORMAT(e.created_at, '%Y-%m') = ?";
        $params[] = $filter_month;
    }
    
    if ($filter_employee) {
        $query .= " AND e.user_id = ?";
        $params[] = $filter_employee;
    }

    if ($filter_client !== '') {
        $query .= " AND e.client_id = ?";
        $params[] = $filter_client;
    }

    if ($filter_event !== '') {
        $query .= " AND e.event_id = ?";
        $params[] = $filter_event;
    }

    if ($filter_category) {
        if ($filter_category === 'client' || $filter_category === 'personal') {
            $query .= " AND COALESCE(e.expense_category, 'personal') = ?";
            $params[] = $filter_category;
        }
    }

    if ($filter_expense_type !== '') {
        $query .= " AND COALESCE(e.personal_type, e.type) = ?";
        $params[] = $filter_expense_type;
    }

    if ($filter_amount_min !== '' && is_numeric($filter_amount_min)) {
        $query .= " AND e.amount >= ?";
        $params[] = (float) $filter_amount_min;
    }

    if ($filter_amount_max !== '' && is_numeric($filter_amount_max)) {
        $query .= " AND e.amount <= ?";
        $params[] = (float) $filter_amount_max;
    }

    if ($filter_q !== '') {
        $query .= " AND (u.name LIKE ? OR u.email LIKE ? OR e.type LIKE ? OR e.personal_type LIKE ? OR e.purpose LIKE ? OR e.description LIKE ? OR c.name LIKE ? OR ev.name LIKE ?)";
        $like = '%' . $filter_q . '%';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
        }
    }
    
    $query .= " ORDER BY e.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = 'Error fetching expenses: ' . $e->getMessage();
    $expenses = [];
}

// Get employees for filter dropdown
try {
    $stmt = $pdo->query("SELECT u.id, u.name, e.designation 
                        FROM users u 
                        LEFT JOIN employees e ON e.user_id = u.id 
                        WHERE u.role = 'employee' 
                        ORDER BY u.name");
    $employees = $stmt->fetchAll();
} catch(PDOException $e) {
    $employees = [];
}

// Get clients/events/types for filter dropdowns
try {
    $stmt = $pdo->query("SELECT id, name FROM clients ORDER BY name");
    $clientsForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $clientsForFilter = [];
}

try {
    $stmt = $pdo->query("SELECT id, name FROM events ORDER BY start_date DESC, name ASC");
    $eventsForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $eventsForFilter = [];
}

try {
    $stmt = $pdo->query("SELECT DISTINCT COALESCE(personal_type, type) as expense_type FROM expenses WHERE COALESCE(personal_type, type) IS NOT NULL AND COALESCE(personal_type, type) <> '' ORDER BY expense_type ASC");
    $typesForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $typesForFilter = [];
}

// Calculate analytics cards (based on current filters)
$approvedExpensesAmount = 0;
$pendingApprovalsCount = 0;
foreach ($expenses as $expense) {
    $amountVal = (float) ($expense['amount'] ?? 0);
    if (($expense['status'] ?? '') === 'approved') {
        $approvedExpensesAmount += $amountVal;
    } elseif (($expense['status'] ?? '') === 'pending') {
        $pendingApprovalsCount++;
    }
}

$budgetTotal = 0;
$budgetApprovedClientExpenses = 0;
try {
    $eventWhere = "WHERE e.status IN ('planning','active')";
    $eventParams = [];

    if ($filter_client !== '') {
        $eventWhere .= " AND e.client_id = ?";
        $eventParams[] = $filter_client;
    }
    if ($filter_event !== '') {
        $eventWhere .= " AND e.id = ?";
        $eventParams[] = $filter_event;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.budget), 0) as total_budget FROM events e $eventWhere");
    $stmt->execute($eventParams);
    $budgetTotal = (float) (($stmt->fetch()['total_budget'] ?? 0));

    $expenseWhere = "WHERE ex.status = 'approved' AND COALESCE(ex.expense_category, 'personal') = 'client' AND ex.event_id IS NOT NULL
                     AND ev.status IN ('planning','active')";
    $expenseParams = [];

    if ($filter_client !== '') {
        $expenseWhere .= " AND ev.client_id = ?";
        $expenseParams[] = $filter_client;
    }
    if ($filter_event !== '') {
        $expenseWhere .= " AND ev.id = ?";
        $expenseParams[] = $filter_event;
    }
    if ($filter_from !== '' || $filter_to !== '') {
        if ($filter_from !== '') {
            $expenseWhere .= " AND DATE(ex.created_at) >= ?";
            $expenseParams[] = $filter_from;
        }
        if ($filter_to !== '') {
            $expenseWhere .= " AND DATE(ex.created_at) <= ?";
            $expenseParams[] = $filter_to;
        }
    } elseif ($filter_month) {
        $expenseWhere .= " AND DATE_FORMAT(ex.created_at, '%Y-%m') = ?";
        $expenseParams[] = $filter_month;
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ex.amount), 0) as approved_amount
                           FROM expenses ex
                           JOIN events ev ON ev.id = ex.event_id
                           $expenseWhere");
    $stmt->execute($expenseParams);
    $budgetApprovedClientExpenses = (float) (($stmt->fetch()['approved_amount'] ?? 0));
} catch(PDOException $e) {
    $budgetTotal = 0;
    $budgetApprovedClientExpenses = 0;
}

$budgetRemaining = max(0, $budgetTotal - $budgetApprovedClientExpenses);
$budgetUtilization = $budgetTotal > 0 ? min(100, round(($budgetApprovedClientExpenses / $budgetTotal) * 100, 1)) : 0;
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Admin</div>
            <div class="sidebar-subtitle">Manage events & teams</div>
        </div>

        <div class="sidebar-quick">
            <a class="btn btn-secondary btn-sm" href="events.php?open=add">
                <i class="fas fa-plus me-2"></i>Event
            </a>
            <a class="btn btn-primary btn-sm" href="employees.php?open=add">
                <i class="fas fa-user-plus me-2"></i>Employee
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="nav flex-column">
            <div class="nav-section-title">Overview</div>
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>

            <div class="nav-section-title">Operations</div>
            <a class="nav-link" href="events.php">
                <i class="fas fa-calendar-check"></i> Events
            </a>
            <a class="nav-link" href="employees.php">
                <i class="fas fa-users"></i> Employees
            </a>
            <a class="nav-link" href="attendance.php">
                <i class="fas fa-clock"></i> Attendance
            </a>

            <div class="nav-section-title">Sales</div>
            <a class="nav-link" href="leads.php">
                <i class="fas fa-handshake"></i> Leads
            </a>
            <a class="nav-link" href="clients.php">
                <i class="fas fa-building"></i> Clients
            </a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php">
                <i class="fas fa-money-bill-wave"></i> Expenses
            </a>
            <a class="nav-link" href="project_expense_reports.php">
                <i class="fas fa-file-excel"></i> Project Reports
            </a>
            <a class="nav-link" href="payroll.php">
                <i class="fas fa-calculator"></i> Payroll
            </a>

            <div class="nav-section-title">Analytics</div>
            <a class="nav-link" href="reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a>

            <div class="nav-section-title">Communication</div>
            <a class="nav-link" href="whatsapp.php">
                <i class="fab fa-whatsapp"></i> WhatsApp
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
            <div class="page-subtitle">Review and approve employee expenses</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" onclick="exportExpenses()">
                <i class="fas fa-download me-2"></i>Export
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="reports.php"><i class="fas fa-chart-bar me-2"></i>Reports</a>
        <a class="btn btn-secondary" href="payroll.php"><i class="fas fa-calculator me-2"></i>Payroll</a>
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

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">Expense Categories</h5>
            <form method="POST" action="" class="d-flex align-items-center gap-2 flex-wrap">
                <input type="hidden" name="action" value="save_category_settings">
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0 small text-muted">New custom categories:</label>
                    <select class="form-select form-select-sm" name="expense_custom_category_scope">
                        <option value="user" <?php echo ($categorySettings['scope'] ?? 'user') === 'user' ? 'selected' : ''; ?>>Only for employee</option>
                        <option value="global" <?php echo ($categorySettings['scope'] ?? 'user') === 'global' ? 'selected' : ''; ?>>Available to all</option>
                    </select>
                </div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="reqApproval" name="expense_custom_category_require_approval" <?php echo !empty($categorySettings['require_approval']) ? 'checked' : ''; ?>>
                    <label class="form-check-label small text-muted" for="reqApproval">Require approval</label>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Save</button>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($expenseCategories)): ?>
                <p class="text-muted mb-0">No categories found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm" id="expenseCategoriesTable" data-smart-table data-export-name="expense_categories.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Scope</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Usage</th>
                                <th>Last Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseCategories as $cat): ?>
                                <?php
                                    $scope = (string) ($cat['scope'] ?? 'user');
                                    $status = (string) ($cat['status'] ?? 'approved');
                                    $isActive = (int) ($cat['is_active'] ?? 1) === 1;
                                    $statusBadge = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning');
                                ?>
                                <tr class="<?php echo $isActive ? '' : 'text-muted'; ?>">
                                    <td class="fw-semibold">
                                        <?php echo htmlspecialchars((string) ($cat['name'] ?? '')); ?>
                                        <?php if (!$isActive): ?><span class="ms-2 badge bg-secondary">Disabled</span><?php endif; ?>
                                    </td>
                                    <td><?php echo $scope === 'global' ? 'All' : 'Employee'; ?></td>
                                    <td><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo ucfirst($status); ?></span></td>
                                    <td>
                                        <?php if ((int) ($cat['created_by_user_id'] ?? 0) > 0): ?>
                                            <div><?php echo htmlspecialchars((string) ($cat['creator_name'] ?? '')); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars((string) ($cat['creator_email'] ?? '')); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) ($cat['usage_count'] ?? 0); ?></td>
                                    <td class="text-muted small"><?php echo !empty($cat['last_used_at']) ? htmlspecialchars(date('d M Y', strtotime((string) $cat['last_used_at']))) : '—'; ?></td>
                                    <td>
                                        <div class="table-action-group">
                                            <button class="btn btn-sm btn-primary" type="button"
                                                onclick="openEditCategoryModal(<?php echo (int) $cat['id']; ?>, '<?php echo htmlspecialchars(addslashes((string) ($cat['name'] ?? ''))); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($status === 'pending'): ?>
                                                <button class="btn btn-sm btn-success" type="button" onclick="approveCategory(<?php echo (int) $cat['id']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" type="button" onclick="rejectCategory(<?php echo (int) $cat['id']; ?>)">
                                                    <i class="fas fa-xmark"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" type="button" <?php echo ((int) ($cat['usage_count'] ?? 0) > 0) ? 'disabled' : ''; ?>
                                                onclick="disableCategory(<?php echo (int) $cat['id']; ?>)">
                                                <i class="fas fa-trash"></i>
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

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card info h-100">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-value"><?php echo formatCurrency($approvedExpensesAmount); ?></div>
                <div class="stat-label">Total Approved Expenses</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card primary h-100">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value"><?php echo (int) $pendingApprovalsCount; ?></div>
                <div class="stat-label">Pending Expense Approvals</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card warning h-100">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-value"><?php echo formatCurrency($budgetRemaining); ?></div>
                <div class="stat-label">Remaining Budget</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card success h-100">
                <div class="stat-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="stat-value"><?php echo htmlspecialchars((string) $budgetUtilization); ?>%</div>
                <div class="stat-label">Budget Utilization</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Filters</h5>
            <a class="btn btn-sm btn-secondary" href="expenses.php"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3">
                    <div class="col-lg-3">
                        <label class="form-label">Search</label>
                        <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($filter_q); ?>" placeholder="Employee, client, event, purpose...">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Employee</label>
                        <select class="form-select js-searchable-select" name="employee">
                            <option value="">All</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int) $emp['id']; ?>" <?php echo (string) $filter_employee === (string) $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?><?php if ($emp['designation']) echo ' (' . htmlspecialchars($emp['designation']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                            <?php foreach ($eventsForFilter as $ev): ?>
                                <option value="<?php echo (int) $ev['id']; ?>" <?php echo (string) $filter_event === (string) $ev['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ev['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status[]" multiple>
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo in_array($st, $filter_status, true) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($st)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-1">Select multiple</div>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category">
                            <option value="">All</option>
                            <option value="client" <?php echo $filter_category === 'client' ? 'selected' : ''; ?>>Client</option>
                            <option value="personal" <?php echo $filter_category === 'personal' ? 'selected' : ''; ?>>Personal</option>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Expense Type</label>
                        <select class="form-select js-searchable-select" name="expense_type">
                            <option value="">All</option>
                            <?php foreach ($typesForFilter as $t): ?>
                                <?php $tval = (string) ($t['expense_type'] ?? ''); ?>
                                <?php if ($tval === '') continue; ?>
                                <option value="<?php echo htmlspecialchars($tval); ?>" <?php echo (string) $filter_expense_type === (string) $tval ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tval); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Month</label>
                        <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($filter_month); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Amount Range</label>
                        <div class="d-flex gap-2">
                            <input type="number" class="form-control" name="amount_min" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $filter_amount_min); ?>" placeholder="Min">
                            <input type="number" class="form-control" name="amount_max" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $filter_amount_max); ?>" placeholder="Max">
                        </div>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply</button>
                        <a href="expenses.php" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Expenses Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Expenses</h5>
        </div>
        <div class="card-body">
            <?php if (empty($expenses)): ?>
                <p class="text-muted">No expenses found for the selected criteria.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="expensesTable" data-smart-table data-export-name="expenses_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Client</th>
                                <th>Event</th>
                                <th>Expense Type</th>
                                <th>Amount</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <?php
                                    $cat = strtolower(trim((string) ($expense['expense_category'] ?? 'personal')));
                                    $cat = $cat === 'client' ? 'client' : 'personal';
                                    $expenseType = (string) (($expense['personal_type'] ?? '') ?: ($expense['type'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($expense['name']); ?></strong>
                                            <?php if ($expense['designation']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($expense['designation']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(($expense['client_name'] ?? '') ?: ($cat === 'client' ? 'Client' : '-')); ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(($expense['event_name'] ?? '') ?: ($cat === 'client' ? 'Event' : '-')); ?></div>
                                        <?php if ($cat === 'client' && isset($expense['event_budget'])): ?>
                                            <div class="text-muted small">Budget <?php echo formatCurrency((float) ($expense['event_budget'] ?? 0)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($expenseType !== '' ? $expenseType : '-'); ?></div>
                                        <div class="text-muted small">
                                            <span class="badge <?php echo $cat === 'client' ? 'bg-info' : 'bg-primary'; ?>">
                                                <?php echo $cat === 'client' ? 'Client' : 'Personal'; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($expense['amount']); ?></strong>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) (($expense['purpose'] ?? '') ?: '-')); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars((string) (($expense['description'] ?? '') ?: '')); ?></div>
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
                                    </td>
                                    <td><?php echo formatDate($expense['created_at'], 'd M Y'); ?></td>
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
                                            <span class="text-muted">No proof</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($expense['status'] == 'pending'): ?>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-success" onclick="approveExpense(<?php echo $expense['id']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="rejectExpense(<?php echo $expense['id']; ?>)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteExpense(<?php echo $expense['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
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

<!-- Reject Reason Modal -->
<div class="modal fade" id="rejectReasonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="expense_id" id="reject_reason_expense_id">
                <div class="modal-body">
                    <label class="form-label">Rejection Reason *</label>
                    <textarea class="form-control" name="rejection_reason" id="rejection_reason" required rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Forms -->
<form method="POST" action="" id="approveForm" style="display: none;">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="expense_id" id="approve_expense_id">
</form>

<form method="POST" action="" id="rejectForm" style="display: none;">
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="expense_id" id="reject_expense_id">
</form>

<form method="POST" action="" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="expense_id" id="delete_expense_id">
</form>

<form method="POST" action="" id="approveCategoryForm" style="display: none;">
    <input type="hidden" name="action" value="approve_category">
    <input type="hidden" name="category_id" id="approve_category_id">
</form>

<form method="POST" action="" id="rejectCategoryForm" style="display: none;">
    <input type="hidden" name="action" value="reject_category">
    <input type="hidden" name="category_id" id="reject_category_id">
</form>

<form method="POST" action="" id="disableCategoryForm" style="display: none;">
    <input type="hidden" name="action" value="disable_category">
    <input type="hidden" name="category_id" id="disable_category_id">
</form>

<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Expense Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="modal-body">
                    <label class="form-label">Category Name *</label>
                    <input type="text" class="form-control" name="category_name" id="edit_category_name" required maxlength="80">
                    <div class="form-text text-muted">Editing does not change existing expense records, but updates the category label used for future selection.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function approveExpense(expenseId) {
    customConfirm('Are you sure you want to approve this expense?', function() {
        document.getElementById('approve_expense_id').value = expenseId;
        document.getElementById('approveForm').submit();
    });
}

function rejectExpense(expenseId) {
    document.getElementById('reject_reason_expense_id').value = expenseId;
    document.getElementById('rejection_reason').value = '';
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('rejectReasonModal'));
    modal.show();
}

function deleteExpense(expenseId) {
    customConfirm('Are you sure you want to delete this expense? This action cannot be undone.', function() {
        document.getElementById('delete_expense_id').value = expenseId;
        document.getElementById('deleteForm').submit();
    });
}

function viewImage(imageUrl) {
    document.getElementById('modalImage').src = imageUrl;
    const el = document.getElementById('imageModal');
    if (!el) return;
    bootstrap.Modal.getOrCreateInstance(el).show();
}

function exportExpenses() {
    exportToCSV('expensesTable', 'expenses_export.csv');
}

function approveCategory(categoryId) {
    customConfirm('Approve this expense category?', function() {
        document.getElementById('approve_category_id').value = categoryId;
        document.getElementById('approveCategoryForm').submit();
    });
}

function rejectCategory(categoryId) {
    customConfirm('Reject this expense category?', function() {
        document.getElementById('reject_category_id').value = categoryId;
        document.getElementById('rejectCategoryForm').submit();
    });
}

function disableCategory(categoryId) {
    customConfirm('Remove this category? This only works if it has not been used.', function() {
        document.getElementById('disable_category_id').value = categoryId;
        document.getElementById('disableCategoryForm').submit();
    });
}

function openEditCategoryModal(id, name) {
    const idEl = document.getElementById('edit_category_id');
    const nameEl = document.getElementById('edit_category_name');
    if (idEl) idEl.value = id;
    if (nameEl) nameEl.value = name || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editCategoryModal')).show();
}
</script>
";
require_once '../includes/footer.php';
?>
