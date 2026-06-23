<?php
$pageTitle = 'Salary';
require_once '../includes/header.php';
requireEmployee();

$employee_id = get_employee_id();
$salary_records = [];
$current_month_salary = null;
$employee_info = null;
$info = '';

function salaryTableMeta(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'salary'");
        $exists = (int) $stmt->fetchColumn() > 0;
        if (!$exists) return ['exists' => false, 'cols' => []];

        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'salary'");
        $cols = array_map('strtolower', array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        return ['exists' => true, 'cols' => $cols];
    } catch (PDOException $e) {
        error_log('[salary.php] salaryTableMeta failed: ' . $e->getMessage());
        return ['exists' => false, 'cols' => []];
    }
}

try {
    $stmt = $pdo->prepare("SELECT e.id as employee_id, e.designation, e.salary as base_salary, u.name, u.email
                           FROM employees e
                           JOIN users u ON u.id = e.user_id
                           WHERE e.user_id = ? LIMIT 1");
    $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $employee_info = $stmt->fetch();
    if ($employee_info && isset($employee_info['employee_id'])) {
        $employee_id = (int) $employee_info['employee_id'];
        $_SESSION['employee_id'] = $employee_id;
    }
} catch (PDOException $e) {
    error_log('[salary.php] employee_info query failed: ' . $e->getMessage());
    $employee_info = null;
}

if (empty($employee_id)) {
    $error = 'Your employee profile is not linked properly. Please contact admin support.';
} else {
    $current_month = get_current_month();
    $meta = salaryTableMeta($pdo);

    $salaryTableOk = false;
    $employeeKeyCol = 'employee_id';
    $monthCol = 'month';
    if (!empty($meta['exists'])) {
        $cols = $meta['cols'] ?? [];
        if (in_array('employee_id', $cols, true) && in_array('month', $cols, true)) {
            $salaryTableOk = true;
            $employeeKeyCol = 'employee_id';
            $monthCol = 'month';
        } elseif (in_array('user_id', $cols, true) && in_array('month', $cols, true)) {
            $salaryTableOk = true;
            $employeeKeyCol = 'user_id';
            $monthCol = 'month';
        }
    }

    if ($salaryTableOk) {
        try {
            $keyVal = $employeeKeyCol === 'user_id' ? (int) ($_SESSION['user_id'] ?? 0) : (int) $employee_id;
            $stmt = $pdo->prepare("SELECT * FROM salary WHERE {$employeeKeyCol} = ? AND {$monthCol} = ? LIMIT 1");
            $stmt->execute([$keyVal, $current_month]);
            $current_month_salary = $stmt->fetch();

            $stmt = $pdo->prepare("SELECT * FROM salary WHERE {$employeeKeyCol} = ? ORDER BY {$monthCol} DESC LIMIT 6");
            $stmt->execute([$keyVal]);
            $salary_records = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[salary.php] salary table query failed: ' . $e->getMessage());
            $error = 'Payroll is not generated yet for your account.';
        }
    } else {
        $info = 'Payroll is not generated yet. Showing calculated salary summary based on attendance and approved expenses.';
        try {
            $months = [];
            for ($i = 0; $i < 6; $i++) {
                $months[] = date('Y-m', strtotime("-{$i} month", strtotime($current_month . '-01')));
            }

            foreach ($months as $m) {
                $calc = calculateSalary((int) ($_SESSION['user_id'] ?? 0), $m);
                $row = [
                    'month' => $m,
                    'base_salary' => (float) ($calc['base_salary'] ?? 0),
                    'allowance' => (float) ($calc['approved_expenses'] ?? 0),
                    'deduction' => (float) ($calc['deduction_amount'] ?? 0),
                    'total_salary' => (float) ($calc['total_payable'] ?? 0),
                    'present_days' => (int) ($calc['present_days'] ?? 0),
                    'payment_status' => 'pending',
                    'payment_date' => null
                ];
                if ($m === $current_month) {
                    $current_month_salary = $row;
                }
                $salary_records[] = $row;
            }
        } catch (Exception $e) {
            error_log('[salary.php] fallback calculateSalary failed: ' . $e->getMessage());
            $error = 'Unable to load salary summary right now. Please try again later.';
        }
    }
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

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Salary</h1>
            <div class="page-subtitle">Review your salary summary and recent months</div>
        </div>
        <div class="page-actions">
            <span class="text-muted small d-none d-md-inline"><?php echo date('d M Y, h:i A'); ?></span>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="payroll.php"><i class="fas fa-calculator me-2"></i>Payroll</a>
        <a class="btn btn-secondary" href="expenses.php"><i class="fas fa-money-bill-wave me-2"></i>Expenses</a>
    </div>

    <?php if (!empty($info)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($info); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>
                
    <div class="row mb-4">
        <div class="col-lg-4 mb-3">
            <div class="stat-card primary h-100">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                        <div class="stat-value">
                            <?php
                            $currentAmount = 0;
                            if (!empty($current_month_salary)) {
                                $currentAmount = $current_month_salary['total_salary'];
                            } elseif (!empty($employee_info)) {
                                $currentAmount = $employee_info['base_salary'];
                            }
                            echo format_currency($currentAmount);
                            ?>
                        </div>
                        <div class="stat-label">This Month</div>
                        <small class="text-muted"><?php echo htmlspecialchars(get_current_month()); ?></small>
                    </div>
                    <span class="stat-pill positive"><i class="fas fa-arrow-up"></i> 0%</span>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="stat-card success h-100">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-value"><?php echo (int) ($current_month_salary['present_days'] ?? 0); ?></div>
                        <div class="stat-label">Present Days</div>
                        <small class="text-muted">Current month</small>
                    </div>
                    <span class="stat-pill positive"><i class="fas fa-arrow-up"></i> 0%</span>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="stat-card info h-100">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="stat-icon"><i class="fas fa-id-badge"></i></div>
                        <div class="stat-value"><?php echo htmlspecialchars($employee_info['designation'] ?? 'Not Assigned'); ?></div>
                        <div class="stat-label">Designation</div>
                        <small class="text-muted"><?php echo htmlspecialchars($employee_info['email'] ?? 'Not Available'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
                
    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Employee Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td><strong>Name:</strong></td>
                            <td><?php echo htmlspecialchars($employee_info['name'] ?? 'Not Available'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td><?php echo htmlspecialchars($employee_info['email'] ?? 'Not Available'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Designation:</strong></td>
                            <td><?php echo htmlspecialchars($employee_info['designation'] ?? 'Not Assigned'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Base Salary:</strong></td>
                            <td><?php echo format_currency($employee_info['base_salary'] ?? 0); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Salary Breakdown</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($current_month_salary)): ?>
                        <p class="text-muted mb-0">No salary record found for the current month.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="p-3 border rounded-3 h-100">
                                    <div class="text-muted small">Base Salary</div>
                                    <div class="fw-bold"><?php echo format_currency($current_month_salary['base_salary']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded-3 h-100">
                                    <div class="text-muted small">Total Salary</div>
                                    <div class="fw-bold"><?php echo format_currency($current_month_salary['total_salary']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded-3 h-100">
                                    <div class="text-muted small">Allowances</div>
                                    <div class="fw-bold"><?php echo format_currency($current_month_salary['allowance']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded-3 h-100">
                                    <div class="text-muted small">Deductions</div>
                                    <div class="fw-bold"><?php echo format_currency($current_month_salary['deduction']); ?></div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center p-3 border rounded-3">
                                    <div>
                                        <div class="text-muted small">Payment Status</div>
                                        <div class="fw-bold"><?php echo ucfirst($current_month_salary['payment_status']); ?></div>
                                    </div>
                                    <span class="badge bg-<?php echo $current_month_salary['payment_status'] === 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($current_month_salary['payment_status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
                
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Salary History</h5>
        </div>
        <div class="card-body">
            <?php if (empty($salary_records)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted mb-0">No salary records found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Base Salary</th>
                                <th>Allowances</th>
                                <th>Deductions</th>
                                <th>Total Salary</th>
                                <th>Status</th>
                                <th>Payment Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($salary_records as $record): ?>
                                <tr>
                                    <td><?php echo date('F Y', strtotime($record['month'] . '-01')); ?></td>
                                    <td><?php echo format_currency($record['base_salary']); ?></td>
                                    <td><?php echo format_currency($record['allowance']); ?></td>
                                    <td><?php echo format_currency($record['deduction']); ?></td>
                                    <td class="fw-bold"><?php echo format_currency($record['total_salary']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $record['payment_status'] === 'paid' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($record['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $record['payment_date'] ? format_date($record['payment_date']) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
                
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Monthly Summary</h5>
        </div>
        <div class="card-body">
            <?php
            $year_summary = [
                'total_earned' => 0,
                'months_count' => 0,
                'total_allowance' => 0,
                'total_deduction' => 0
            ];
            try {
                if (!empty($salaryTableOk)) {
                    $keyVal = ($employeeKeyCol ?? 'employee_id') === 'user_id' ? (int) ($_SESSION['user_id'] ?? 0) : (int) $employee_id;
                    $stmt = $pdo->prepare(
                        "SELECT 
                            SUM(total_salary) as total_earned,
                            SUM(allowance) as total_allowance,
                            SUM(deduction) as total_deduction,
                            COUNT(*) as months_count
                         FROM salary 
                         WHERE {$employeeKeyCol} = ? AND SUBSTRING({$monthCol}, 1, 4) = ?"
                    );
                    $stmt->execute([$keyVal, date('Y')]);
                    $year_summary = $stmt->fetch() ?: $year_summary;
                } else {
                    $sumEarned = 0.0;
                    $sumAllowance = 0.0;
                    $sumDeduction = 0.0;
                    $count = 0;

                    $year = (int) date('Y');
                    $start = strtotime($year . '-01-01');
                    $end = strtotime($year . '-12-01');
                    for ($ts = $start; $ts <= $end; $ts = strtotime('+1 month', $ts)) {
                        $m = date('Y-m', $ts);
                        $calc = calculateSalary((int) ($_SESSION['user_id'] ?? 0), $m);
                        $sumEarned += (float) ($calc['total_payable'] ?? 0);
                        $sumAllowance += (float) ($calc['approved_expenses'] ?? 0);
                        $sumDeduction += (float) ($calc['deduction_amount'] ?? 0);
                        $count++;
                    }

                    $year_summary = [
                        'total_earned' => $sumEarned,
                        'months_count' => $count,
                        'total_allowance' => $sumAllowance,
                        'total_deduction' => $sumDeduction
                    ];
                }
            } catch (Exception $e) {
                error_log('[salary.php] year summary failed: ' . $e->getMessage());
            }
            ?>
            <div class="row g-3 text-center">
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 h-100">
                        <small class="text-muted">Total Earned (<?php echo date('Y'); ?>)</small>
                        <div class="fw-bold text-success"><?php echo format_currency($year_summary['total_earned'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 h-100">
                        <small class="text-muted">Average Monthly</small>
                        <div class="fw-bold">
                            <?php
                            $avg = 0;
                            $months = (int) ($year_summary['months_count'] ?? 0);
                            if ($months > 0) {
                                $avg = ($year_summary['total_earned'] ?? 0) / $months;
                            }
                            echo format_currency($avg);
                            ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 h-100">
                        <small class="text-muted">Total Allowances</small>
                        <div class="fw-bold"><?php echo format_currency($year_summary['total_allowance'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 h-100">
                        <small class="text-muted">Total Deductions</small>
                        <div class="fw-bold text-danger"><?php echo format_currency($year_summary['total_deduction'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
