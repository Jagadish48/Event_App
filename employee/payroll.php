<?php
$pageTitle = 'Payroll';
require_once '../includes/header.php';
requireEmployee();

// Get filter parameters
$filter_month = isset($_GET['month']) ? clean_input($_GET['month']) : getCurrentMonth();

// Get payroll data
try {
    // Get employee details
    $stmt = $pdo->prepare("SELECT u.*, e.designation, e.salary, e.join_date 
                          FROM users u 
                          LEFT JOIN employees e ON e.user_id = u.id 
                          WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch();
    
    // Calculate salary for the selected month
    $salary = calculateSalary($_SESSION['user_id'], $filter_month);
    $monthlyAttendance = is_array($salary['daily'] ?? null) ? $salary['daily'] : [];
    
    // Get monthly expenses
    $stmt = $pdo->prepare("SELECT type, amount, description, status, created_at 
                          FROM expenses 
                          WHERE user_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ? 
                          ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id'], $filter_month]);
    $monthlyExpenses = $stmt->fetchAll();
    
    // Attendance details are derived from policy summary to include Absent/Leave/Weekly Off days
    
    // Get payroll history for last 6 months
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
                          SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as expenses 
                          FROM expenses 
                          WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) 
                          GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
                          ORDER BY month DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $payrollHistory = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = 'Error fetching payroll data: ' . $e->getMessage();
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
            <h1 class="h3 page-title">Payroll</h1>
            <div class="page-subtitle">Review salary, attendance, and monthly expenses</div>
        </div>
        <div class="page-actions">
            <form method="GET" action="" class="d-flex align-items-end gap-2 flex-wrap js-auto-submit">
                <div>
                    <label class="form-label mb-1">Month</label>
                    <input type="month" class="form-control" name="month" value="<?php echo $filter_month; ?>">
                </div>
                <button type="submit" class="btn btn-primary">View</button>
            </form>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Salary Summary Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Salary Summary - <?php echo date('F Y', strtotime($filter_month . '-01')); ?></h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($salary['base_salary']); ?></div>
                        <div class="stat-label">Base Salary</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($salary['earned_salary']); ?></div>
                        <div class="stat-label">Earned Salary</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($salary['approved_expenses']); ?></div>
                        <div class="stat-label">Approved Expenses</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($salary['total_payable']); ?></div>
                        <div class="stat-label">Total Payable</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Salary Breakdown -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Salary Breakdown</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Base Salary:</strong></td>
                            <td><?php echo formatCurrency($salary['base_salary']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Working Days:</strong></td>
                            <td><?php echo $salary['working_days']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Present Days:</strong></td>
                            <td><?php echo $salary['present_days']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Absent Days:</strong></td>
                            <td><?php echo (int) ($salary['absent_days'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Approved Leaves:</strong></td>
                            <td><?php echo (int) ($salary['approved_leaves'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Remaining Leaves:</strong></td>
                            <td><?php echo (int) ($salary['remaining_leaves'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Weekly Offs:</strong></td>
                            <td><?php echo (int) ($salary['weekly_offs'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Attendance Rate:</strong></td>
                            <td>
                                <?php 
                                $rate = $salary['working_days'] > 0 ? round(($salary['present_days'] / $salary['working_days']) * 100, 1) : 0;
                                echo $rate . '%';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Daily Rate:</strong></td>
                            <td><?php echo formatCurrency($salary['base_salary'] / $salary['working_days']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Salary Deductions:</strong></td>
                            <td class="text-danger"><?php echo formatCurrency((float) ($salary['deduction_amount'] ?? 0)); ?></td>
                        </tr>
                        <tr class="table-primary">
                            <td><strong>Earned Salary:</strong></td>
                            <td><strong><?php echo formatCurrency($salary['earned_salary']); ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Approved Expenses:</strong></td>
                            <td><?php echo formatCurrency($salary['approved_expenses']); ?></td>
                        </tr>
                        <tr class="table-success">
                            <td><strong>Total Payable:</strong></td>
                            <td><strong><?php echo formatCurrency($salary['total_payable']); ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Expenses -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Monthly Expenses</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($monthlyExpenses)): ?>
                        <p class="text-muted">No expenses found for this month.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm" id="monthlyExpensesTable" data-smart-table data-export-name="my_monthly_expenses.csv" data-page-size="10">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthlyExpenses as $expense): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($expense['type']); ?></td>
                                            <td><?php echo formatCurrency($expense['amount']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $expense['status'] == 'approved' ? 'success' : 
                                                         ($expense['status'] == 'rejected' ? 'danger' : 'warning'); 
                                                ?>">
                                                    <?php echo ucfirst($expense['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-primary fw-bold">
                                        <td>Total Approved:</td>
                                        <td><?php echo formatCurrency($salary['approved_expenses']); ?></td>
                                        <td>-</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Details -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Attendance Details</h5>
        </div>
        <div class="card-body">
            <?php if (empty($monthlyAttendance)): ?>
                <p class="text-muted">No attendance data found for this month.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm" id="attendanceDetailsTable" data-smart-table data-export-name="my_attendance_details.csv" data-page-size="10">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Hours Worked</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyAttendance as $attendance): ?>
                                <tr>
                                    <td><?php echo formatDate($attendance['date']); ?></td>
                                    <td><?php echo !empty($attendance['check_in']) ? substr((string) $attendance['check_in'], 0, 5) : '-'; ?></td>
                                    <td><?php echo !empty($attendance['check_out']) ? substr((string) $attendance['check_out'], 0, 5) : '-'; ?></td>
                                    <td><?php echo !empty($attendance['hours']) ? round((float) $attendance['hours'], 2) . 'h' : '-'; ?></td>
                                    <td><?php echo renderAttendanceStatusBadgeFromKey($attendance['status_key'] ?? 'absent'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payroll History -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Payroll History (Last 6 Months)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($payrollHistory)): ?>
                <p class="text-muted">No payroll history found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm" id="payrollHistoryTable" data-smart-table data-export-name="my_payroll_history.csv" data-page-size="10">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Approved Expenses</th>
                                <th>Salary Component</th>
                                <th>Total Payable</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payrollHistory as $history): ?>
                                <tr>
                                    <td><?php echo date('F Y', strtotime($history['month'] . '-01')); ?></td>
                                    <td><?php echo formatCurrency($history['expenses']); ?></td>
                                    <td>
                                        <?php 
                                        // Calculate salary for historical month
                                        $histSalary = calculateSalary($_SESSION['user_id'], $history['month']);
                                        echo formatCurrency($histSalary['earned_salary']);
                                        ?>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php 
                                            echo formatCurrency($histSalary['total_payable']);
                                            ?>
                                        </strong>
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

<?php
$additional_js = "
<script>
// Print payroll
function printPayroll() {
    window.print();
}

// Export payroll
function exportPayroll() {
    const data = {
        month: '" . $filter_month . "',
        base_salary: " . $salary['base_salary'] . ",
        earned_salary: " . $salary['earned_salary'] . ",
        approved_expenses: " . $salary['approved_expenses'] . ",
        total_payable: " . $salary['total_payable'] . ",
        working_days: " . $salary['working_days'] . ",
        present_days: " . $salary['present_days'] . ",
        absent_days: " . (int) ($salary['absent_days'] ?? 0) . ",
        approved_leaves: " . (int) ($salary['approved_leaves'] ?? 0) . ",
        remaining_leaves: " . (int) ($salary['remaining_leaves'] ?? 0) . ",
        weekly_offs: " . (int) ($salary['weekly_offs'] ?? 0) . ",
        deduction_amount: " . (float) ($salary['deduction_amount'] ?? 0) . "
    };
    
    const csv = 'Month,Base Salary,Earned Salary,Approved Expenses,Total Payable,Working Days,Present Days,Absent Days,Approved Leaves,Remaining Leaves,Weekly Offs,Deductions\\n' +
                '" . date('F Y', strtotime($filter_month . '-01')) . ",' + 
                data.base_salary + ',' + data.earned_salary + ',' + data.approved_expenses + ',' + 
                data.total_payable + ',' + data.working_days + ',' + data.present_days + ',' +
                data.absent_days + ',' + data.approved_leaves + ',' + data.remaining_leaves + ',' +
                data.weekly_offs + ',' + data.deduction_amount;
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'payroll_" . $filter_month . ".csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>
";
require_once '../includes/footer.php';
?>
