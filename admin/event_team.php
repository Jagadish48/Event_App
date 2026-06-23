<?php
require_once __DIR__ . '/../config/database.php';
requireAdmin();

if (!isset($_GET['event_id'])) {
    echo '<div class="alert alert-danger">Invalid request</div>';
    exit;
}

$eventId = clean_input($_GET['event_id']);

try {
    // Get event details
    $stmt = $pdo->prepare("SELECT e.*, c.name as client_name, u.name as created_by_name 
                          FROM events e 
                          LEFT JOIN clients c ON e.client_id = c.id 
                          JOIN users u ON e.created_by = u.id 
                          WHERE e.id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        echo '<div class="alert alert-danger">Event not found</div>';
        exit;
    }
    
    // Get event team
    $stmt = $pdo->prepare("SELECT et.*, u.name, u.email, e.phone, e.designation 
                          FROM event_team et 
                          JOIN users u ON et.user_id = u.id 
                          LEFT JOIN employees e ON e.user_id = u.id 
                          WHERE et.event_id = ? 
                          ORDER BY et.assigned_at");
    $stmt->execute([$eventId]);
    $teamMembers = $stmt->fetchAll();
    
    // Get event expenses
    $stmt = $pdo->prepare("SELECT ex.*, ee.expense_id 
                          FROM event_expenses ee 
                          JOIN expenses ex ON ee.expense_id = ex.id 
                          WHERE ee.event_id = ? 
                          ORDER BY ex.created_at DESC");
    $stmt->execute([$eventId]);
    $expenses = $stmt->fetchAll();
    
    // Calculate totals
    $totalExpenses = array_sum(array_column($expenses, 'amount'));
    $profitLoss = $event['budget'] - $totalExpenses;
    
} catch(PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . $e->getMessage() . '</div>';
    exit;
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h6>Event Information</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Name:</strong></td>
                <td><?php echo htmlspecialchars($event['name']); ?></td>
            </tr>
            <tr>
                <td><strong>Client:</strong></td>
                <td><?php echo htmlspecialchars($event['client_name'] ?: 'No Client'); ?></td>
            </tr>
            <tr>
                <td><strong>Dates:</strong></td>
                <td><?php echo formatDate($event['start_date']); ?> - <?php echo formatDate($event['end_date']); ?></td>
            </tr>
            <tr>
                <td><strong>Venue:</strong></td>
                <td><?php echo htmlspecialchars($event['venue'] ?: 'TBD'); ?></td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6>Financial Summary</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Budget:</strong></td>
                <td><?php echo formatCurrency($event['budget']); ?></td>
            </tr>
            <tr>
                <td><strong>Expenses:</strong></td>
                <td><?php echo formatCurrency($totalExpenses); ?></td>
            </tr>
            <tr>
                <td><strong>Profit/Loss:</strong></td>
                <td class="<?php echo $profitLoss >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <strong><?php echo formatCurrency($profitLoss); ?></strong>
                </td>
            </tr>
            <tr>
                <td><strong>Status:</strong></td>
                <td>
                    <span class="badge bg-<?php 
                        echo $event['status'] == 'completed' ? 'success' : 
                             ($event['status'] == 'active' ? 'primary' : 
                             ($event['status'] == 'cancelled' ? 'danger' : 'warning')); 
                    ?>">
                        <?php echo ucfirst($event['status']); ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>
</div>

<h6>Team Members</h6>
<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Name</th>
                <th>Designation</th>
                <th>Role</th>
                <th>Contact</th>
                <th>Assigned</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($teamMembers)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">No team members assigned</td>
                </tr>
            <?php else: ?>
                <?php foreach ($teamMembers as $member): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($member['designation'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($member['role'] ?: 'Team Member'); ?></td>
                        <td>
                            <?php echo htmlspecialchars($member['email']); ?>
                            <br>
                            <?php if (!empty($member['phone'])): ?>
                                <?php echo htmlspecialchars($member['phone']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDate($member['assigned_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<h6 class="mt-4">Event Expenses</h6>
<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($expenses)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">No expenses recorded</td>
                </tr>
            <?php else: ?>
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?php echo formatDate($expense['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($expense['type']); ?></td>
                        <td><?php echo htmlspecialchars($expense['description'] ?: '-'); ?></td>
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
            <?php endif; ?>
        </tbody>
        <?php if (!empty($expenses)): ?>
            <tfoot>
                <tr class="table-primary fw-bold">
                    <td colspan="3">TOTAL</td>
                    <td><?php echo formatCurrency($totalExpenses); ?></td>
                    <td>-</td>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>
