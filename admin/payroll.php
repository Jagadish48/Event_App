<?php
$pageTitle = 'Payroll Management';
require_once '../includes/header.php';
requireAdmin();

$success = '';
$error = '';

// Get filter parameters
$filter_month = isset($_GET['month']) ? clean_input($_GET['month']) : getCurrentMonth();
$filter_employee = isset($_GET['employee']) ? clean_input($_GET['employee']) : '';

// Get employees for payroll calculation
try {
    $query = "SELECT u.id, u.name, u.email, e.designation, e.salary, e.join_date 
              FROM users u 
              LEFT JOIN employees e ON e.user_id = u.id 
              WHERE u.role = 'employee' AND e.status = 'active'";
    
    $params = [];
    
    if ($filter_employee) {
        $query .= " AND u.id = ?";
        $params[] = $filter_employee;
    }
    
    $query .= " ORDER BY u.name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = 'Error fetching employees: ' . $e->getMessage();
    $employees = [];
}

// Calculate payroll for each employee
$payrollData = [];
$totalPayroll = 0;

foreach ($employees as $employee) {
    $salary = calculateSalary($employee['id'], $filter_month);
    $salary['employee'] = $employee;
    $payrollData[] = $salary;
    $totalPayroll += $salary['total_payable'];
}

// Get all employees for filter dropdown
try {
    $stmt = $pdo->query("SELECT u.id, u.name, e.designation 
                        FROM users u 
                        LEFT JOIN employees e ON e.user_id = u.id 
                        WHERE u.role = 'employee' 
                        ORDER BY u.name");
    $allEmployees = $stmt->fetchAll();
} catch(PDOException $e) {
    $allEmployees = [];
}

// Calculate summary statistics
$summary = [
    'total_employees' => count($payrollData),
    'total_base_salary' => array_sum(array_column($payrollData, 'base_salary')),
    'total_earned_salary' => array_sum(array_column($payrollData, 'earned_salary')),
    'total_expenses' => array_sum(array_column($payrollData, 'approved_expenses')),
    'total_payable' => $totalPayroll,
    'avg_salary' => count($payrollData) > 0 ? $totalPayroll / count($payrollData) : 0
];
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
            <h1 class="h3 page-title">Payroll</h1>
            <div class="page-subtitle">Review payroll summaries and employee payouts</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" onclick="printPayroll()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <button class="btn btn-secondary" onclick="exportPayroll()">
                <i class="fas fa-download me-2"></i>Export
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="employees.php"><i class="fas fa-users me-2"></i>Employees</a>
        <a class="btn btn-secondary" href="expenses.php"><i class="fas fa-money-bill-wave me-2"></i>Expenses</a>
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

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label">Month</label>
                        <input type="month" class="form-control" name="month" value="<?php echo $filter_month; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Employee</label>
                        <select class="form-select js-searchable-select" name="employee">
                            <option value="">All Employees</option>
                            <?php foreach ($allEmployees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo $filter_employee == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                    <?php if ($emp['designation']) echo '(' . htmlspecialchars($emp['designation']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Calculate</button>
                            <a href="payroll.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $summary['total_employees']; ?></div>
                <div class="stat-label">Employees</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-value"><?php echo formatCurrency($summary['total_base_salary']); ?></div>
                <div class="stat-label">Base Salary</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-value"><?php echo formatCurrency($summary['total_earned_salary']); ?></div>
                <div class="stat-label">Earned Salary</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-value"><?php echo formatCurrency($summary['total_expenses']); ?></div>
                <div class="stat-label">Expenses</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-value"><?php echo formatCurrency($summary['total_payable']); ?></div>
                <div class="stat-label">Total Payable</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value"><?php echo formatCurrency($summary['avg_salary']); ?></div>
                <div class="stat-label">Avg Salary</div>
            </div>
        </div>
    </div>

    <!-- Payroll Details -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Payroll Details - <?php echo date('F Y', strtotime($filter_month . '-01')); ?></h5>
        </div>
        <div class="card-body">
            <?php if (empty($payrollData)): ?>
                <p class="text-muted">No payroll data found for the selected criteria.</p>
            <?php else: ?>
                <div class="table-responsive" id="payrollTable">
                    <table class="table table-hover" id="payrollDetailsTable" data-smart-table data-export-name="payroll_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Designation</th>
                                <th>Base Salary</th>
                                <th>Working Days</th>
                                <th>Present Days</th>
                                <th>Absent</th>
                                <th>Leaves</th>
                                <th>Deductions</th>
                                <th>Earned Salary</th>
                                <th>Approved Expenses</th>
                                <th>Total Payable</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payrollData as $payroll): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($payroll['employee']['name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($payroll['employee']['email']); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($payroll['employee']['designation'] ?: 'N/A'); ?></td>
                                    <td><?php echo formatCurrency($payroll['base_salary']); ?></td>
                                    <td><?php echo $payroll['working_days']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $payroll['present_days'] >= $payroll['working_days'] * 0.8 ? 'success' : 'warning'; ?>">
                                            <?php echo $payroll['present_days']; ?>
                                        </span>
                                    </td>
                                    <td><span class="badge bg-danger"><?php echo (int) ($payroll['absent_days'] ?? 0); ?></span></td>
                                    <td><span class="badge bg-primary"><?php echo (int) ($payroll['approved_leaves'] ?? 0); ?></span></td>
                                    <td class="text-danger fw-semibold"><?php echo formatCurrency((float) ($payroll['deduction_amount'] ?? 0)); ?></td>
                                    <td><?php echo formatCurrency($payroll['earned_salary']); ?></td>
                                    <td><?php echo formatCurrency($payroll['approved_expenses']); ?></td>
                                    <td>
                                        <strong class="text-primary"><?php echo formatCurrency($payroll['total_payable']); ?></strong>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $payroll['employee']['id']; ?>)">
                                            <i class="fas fa-eye"></i> Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-primary fw-bold">
                                <td colspan="3">TOTAL</td>
                                <td>-</td>
                                <td><?php echo array_sum(array_column($payrollData, 'present_days')); ?></td>
                                <td><?php echo array_sum(array_map(function($r){ return (int) ($r['absent_days'] ?? 0); }, $payrollData)); ?></td>
                                <td><?php echo array_sum(array_map(function($r){ return (int) ($r['approved_leaves'] ?? 0); }, $payrollData)); ?></td>
                                <td><?php echo formatCurrency(array_sum(array_map(function($r){ return (float) ($r['deduction_amount'] ?? 0); }, $payrollData))); ?></td>
                                <td><?php echo formatCurrency($summary['total_earned_salary']); ?></td>
                                <td><?php echo formatCurrency($summary['total_expenses']); ?></td>
                                <td><?php echo formatCurrency($summary['total_payable']); ?></td>
                                <td>-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payroll Chart -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Payroll Breakdown</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <canvas id="salaryChart"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <canvas id="expenseChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Employee Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payroll Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function viewDetails(employeeId) {
    // Load employee details via AJAX
    const month = '" . $filter_month . "';
    
    fetch('payroll_details.php?employee_id=' + employeeId + '&month=' + month)
        .then(response => response.text())
        .then(html => {
            document.getElementById('detailsContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        })
        .catch(error => {
            console.error('Error loading details:', error);
            alert('Error loading employee details');
        });
}

function printPayroll() {
    printElement('payrollTable');
}

function exportPayroll() {
    exportToCSV('payrollDetailsTable', 'payroll_export.csv');
}

// Salary Chart
const salaryCtx = document.getElementById('salaryChart').getContext('2d');
const salaryChart = new Chart(salaryCtx, {
    type: 'bar',
    data: {
        labels: ['Base Salary', 'Earned Salary', 'Expenses', 'Total Payable'],
        datasets: [{
            label: 'Amount (Rs)',
            data: [" . $summary['total_base_salary'] . ", " . $summary['total_earned_salary'] . ", " . $summary['total_expenses'] . ", " . $summary['total_payable'] . "],
            backgroundColor: ['#3498db', '#2ecc71', '#f39c12', '#9b59b6']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'Rs ' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Expense Type Chart
const expenseCtx = document.getElementById('expenseChart').getContext('2d');
const expenseChart = new Chart(expenseCtx, {
    type: 'doughnut',
    data: {
        labels: ['Earned Salary', 'Approved Expenses'],
        datasets: [{
            data: [" . $summary['total_earned_salary'] . ", " . $summary['total_expenses'] . "],
            backgroundColor: ['#2ecc71', '#f39c12']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>
";
require_once '../includes/footer.php';
?>
