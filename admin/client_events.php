<?php
require_once __DIR__ . '/../config/database.php';
requireAdmin();

if (!isset($_GET['client_id'])) {
    echo '<div class="alert alert-danger">Invalid request</div>';
    exit;
}

$clientId = clean_input($_GET['client_id']);

try {
    // Get client details
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    
    if (!$client) {
        echo '<div class="alert alert-danger">Client not found</div>';
        exit;
    }
    
    // Get client events
    $stmt = $pdo->prepare("SELECT e.*, u.name as created_by_name 
                          FROM events e 
                          JOIN users u ON e.created_by = u.id 
                          WHERE e.client_id = ? 
                          ORDER BY e.start_date DESC");
    $stmt->execute([$clientId]);
    $events = $stmt->fetchAll();
    
    // Calculate event statistics
    $totalEvents = count($events);
    $totalBudget = array_sum(array_column($events, 'budget'));
    $completedEvents = 0;
    $activeEvents = 0;
    
    foreach ($events as $event) {
        if ($event['status'] == 'completed') $completedEvents++;
        elseif ($event['status'] == 'active') $activeEvents++;
    }
    
} catch(PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . $e->getMessage() . '</div>';
    exit;
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h6>Client Information</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Name:</strong></td>
                <td><?php echo htmlspecialchars($client['name']); ?></td>
            </tr>
            <tr>
                <td><strong>Company:</strong></td>
                <td><?php echo htmlspecialchars($client['company'] ?: 'N/A'); ?></td>
            </tr>
            <tr>
                <td><strong>Contact:</strong></td>
                <td>
                    <?php if ($client['email']): ?>
                        <?php echo htmlspecialchars($client['email']); ?><br>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($client['phone']); ?>
                </td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6>Event Statistics</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Total Events:</strong></td>
                <td><?php echo $totalEvents; ?></td>
            </tr>
            <tr>
                <td><strong>Total Budget:</strong></td>
                <td><?php echo formatCurrency($totalBudget); ?></td>
            </tr>
            <tr>
                <td><strong>Completed:</strong></td>
                <td><?php echo $completedEvents; ?></td>
            </tr>
            <tr>
                <td><strong>Active:</strong></td>
                <td><?php echo $activeEvents; ?></td>
            </tr>
        </table>
    </div>
</div>

<h6>Event History</h6>
<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Event Name</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Budget</th>
                <th>Status</th>
                <th>Created By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($events)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">No events found for this client</td>
                </tr>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($event['name']); ?></strong>
                            <?php if ($event['description']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($event['description'], 0, 50)) . '...'; ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDate($event['start_date']); ?></td>
                        <td><?php echo formatDate($event['end_date']); ?></td>
                        <td><?php echo formatCurrency($event['budget']); ?></td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $event['status'] == 'completed' ? 'success' : 
                                     ($event['status'] == 'active' ? 'primary' : 
                                     ($event['status'] == 'cancelled' ? 'danger' : 'warning')); 
                            ?>">
                                <?php echo ucfirst($event['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($event['created_by_name']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
