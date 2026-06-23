<?php
require_once __DIR__ . '/../config/database.php';
requireAdmin();

if (!isset($_GET['employee_id']) || !isset($_GET['month'])) {
    echo '<div class="alert alert-danger">Invalid request</div>';
    exit;
}

$employeeId = clean_input($_GET['employee_id']);
$month = clean_input($_GET['month']);

try {
    // Get employee details
    $stmt = $pdo->prepare("SELECT u.*, e.designation, e.salary, e.join_date 
                          FROM users u 
                          LEFT JOIN employees e ON e.user_id = u.id 
                          WHERE u.id = ? AND u.role = 'employee'");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        echo '<div class="alert alert-danger">Employee not found</div>';
        exit;
    }
    
    // Get attendance records for the month
    $stmt = $pdo->prepare("SELECT * FROM attendance 
                          WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
                          ORDER BY date");
    $stmt->execute([$employeeId, $month]);
    $attendanceRecords = $stmt->fetchAll();
    
    // Get approved expenses for the month
    $stmt = $pdo->prepare("SELECT * FROM expenses 
                          WHERE user_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ? 
                          AND status = 'approved' 
                          ORDER BY created_at");
    $stmt->execute([$employeeId, $month]);
    $expenses = $stmt->fetchAll();
    
    // Calculate salary
    $salary = calculateSalary($employeeId, $month);
    $dailyAttendance = is_array($salary['daily'] ?? null) ? $salary['daily'] : [];
    
} catch(PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . $e->getMessage() . '</div>';
    exit;
}
?>

<div class="row">
    <div class="col-md-6">
        <h6>Employee Information</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Name:</strong></td>
                <td><?php echo htmlspecialchars($employee['name']); ?></td>
            </tr>
            <tr>
                <td><strong>Email:</strong></td>
                <td><?php echo htmlspecialchars($employee['email']); ?></td>
            </tr>
            <tr>
                <td><strong>Designation:</strong></td>
                <td><?php echo htmlspecialchars($employee['designation'] ?: 'N/A'); ?></td>
            </tr>
            <tr>
                <td><strong>Join Date:</strong></td>
                <td><?php echo formatDate($employee['join_date']); ?></td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6>Salary Calculation</h6>
        <table class="table table-sm">
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
                <td><?php echo (int) ($salary['approved_leaves'] ?? 0); ?> (Remaining <?php echo (int) ($salary['remaining_leaves'] ?? 0); ?>)</td>
            </tr>
            <tr>
                <td><strong>Weekly Offs:</strong></td>
                <td><?php echo (int) ($salary['weekly_offs'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong>Deductions:</strong></td>
                <td class="text-danger"><?php echo formatCurrency((float) ($salary['deduction_amount'] ?? 0)); ?></td>
            </tr>
            <tr>
                <td><strong>Earned Salary:</strong></td>
                <td><?php echo formatCurrency($salary['earned_salary']); ?></td>
            </tr>
            <tr>
                <td><strong>Approved Expenses:</strong></td>
                <td><?php echo formatCurrency($salary['approved_expenses']); ?></td>
            </tr>
            <tr class="table-primary">
                <td><strong>Total Payable:</strong></td>
                <td><strong><?php echo formatCurrency($salary['total_payable']); ?></strong></td>
            </tr>
        </table>
    </div>
</div>

<div class="mt-4">
    <h6>Attendance Records</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Hours</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dailyAttendance)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No attendance records found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dailyAttendance as $record): ?>
                        <tr>
                            <td><?php echo formatDate($record['date']); ?></td>
                            <td><?php echo !empty($record['check_in']) ? substr((string) $record['check_in'], 0, 5) : '-'; ?></td>
                            <td><?php echo !empty($record['check_out']) ? substr((string) $record['check_out'], 0, 5) : '-'; ?></td>
                            <td>
                                <?php echo !empty($record['hours']) ? round((float) $record['hours'], 2) . 'h' : '-'; ?>
                            </td>
                            <td>
                                <?php echo renderAttendanceStatusBadgeFromKey($record['status_key'] ?? 'absent'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    <h6>Approved Expenses</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">No approved expenses found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td><?php echo formatDate($expense['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($expense['type']); ?></td>
                            <td><?php echo htmlspecialchars($expense['description'] ?: '-'); ?></td>
                            <td><?php echo formatCurrency($expense['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
