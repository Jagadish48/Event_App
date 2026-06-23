<?php
$pageTitle = 'WhatsApp Campaigns';
require_once '../includes/header.php';
requireAdmin();

ensureWhatsAppSchema();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_campaign') {
            try {
                $name = clean_input($_POST['name']);
                $templateId = (int)($_POST['template_id'] ?? 0);
                $segment = clean_input($_POST['segment']);
                $scheduledAt = clean_input($_POST['scheduled_at']);
                $festivalName = clean_input($_POST['festival_name'] ?? '');
                
                if (!$name || !$templateId || !$segment || !$scheduledAt) {
                    throw new Exception("All required fields must be filled.");
                }

                // Create Campaign
                $stmt = $pdo->prepare("INSERT INTO wa_campaigns (name, template_id, segment, scheduled_at, status, created_by, festival_name) VALUES (?, ?, ?, ?, 'scheduled', ?, ?)");
                $stmt->execute([$name, $templateId, $segment, $scheduledAt, $_SESSION['user_id'], $festivalName]);
                $campaignId = $pdo->lastInsertId();

                // Build Recipients
                $recipients = [];
                if ($segment === 'all_clients') {
                    $stmtC = $pdo->query("SELECT name, phone FROM clients WHERE phone IS NOT NULL AND phone != ''");
                    while ($row = $stmtC->fetch()) {
                        $recipients[] = ['name' => $row['name'], 'phone' => $row['phone']];
                    }
                } elseif ($segment === 'all_employees') {
                    $stmtE = $pdo->query("SELECT u.name, e.phone FROM users u JOIN employees e ON e.user_id = u.id WHERE e.phone IS NOT NULL AND e.phone != ''");
                    while ($row = $stmtE->fetch()) {
                        $recipients[] = ['name' => $row['name'], 'phone' => $row['phone']];
                    }
                } elseif ($segment === 'custom') {
                    // Expect custom list in a textarea, one per line (Name,Phone or just Phone)
                    $lines = explode("\n", $_POST['custom_list'] ?? '');
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!$line) continue;
                        $parts = explode(',', $line, 2);
                        if (count($parts) === 2) {
                            $recipients[] = ['name' => trim($parts[0]), 'phone' => trim($parts[1])];
                        } else {
                            $recipients[] = ['name' => '', 'phone' => trim($parts[0])];
                        }
                    }
                }

                // Insert Recipients
                $stmtRec = $pdo->prepare("INSERT INTO wa_campaign_recipients (campaign_id, recipient, name) VALUES (?, ?, ?)");
                foreach ($recipients as $rec) {
                    $phone = wa_formatPhone($rec['phone'], wa_getConfig()['default_country']);
                    if ($phone !== '') {
                        $stmtRec->execute([$campaignId, $phone, $rec['name']]);
                    }
                }

                $success = "Campaign scheduled successfully with " . count($recipients) . " recipients.";
            } catch (Exception $e) {
                $error = "Error creating campaign: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'cancel_campaign') {
            try {
                $campId = (int)clean_input($_POST['campaign_id']);
                $pdo->prepare("UPDATE wa_campaigns SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'")->execute([$campId]);
                $success = "Campaign cancelled.";
            } catch (Exception $e) {
                $error = "Error cancelling campaign: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'delete_campaign') {
            try {
                $campId = (int)clean_input($_POST['campaign_id']);
                $pdo->prepare("DELETE FROM wa_campaign_recipients WHERE campaign_id = ?")->execute([$campId]);
                $pdo->prepare("DELETE FROM wa_campaigns WHERE id = ?")->execute([$campId]);
                $success = "Campaign deleted.";
            } catch (Exception $e) {
                $error = "Error deleting campaign: " . $e->getMessage();
            }
        }
    }
}

// Fetch Campaigns
$stmt = $pdo->query("
    SELECT c.*, t.template_name, t.trigger_type, u.name as creator_name,
           (SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = c.id) as total_recipients,
           (SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = c.id AND status = 'sent') as sent_recipients,
           (SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = c.id AND status = 'failed') as failed_recipients
    FROM wa_campaigns c
    LEFT JOIN wa_templates t ON t.id = c.template_id
    LEFT JOIN users u ON u.id = c.created_by
    ORDER BY c.created_at DESC
");
$campaigns = $stmt->fetchAll();

// Fetch Templates for dropdown (Marketing only ideally, but allow all for flexibility)
$stmtT = $pdo->query("SELECT id, template_name, trigger_type, category FROM wa_templates WHERE is_active = 1 ORDER BY category, template_name");
$templates = $stmtT->fetchAll();

$festivals = wa_getIndianFestivals();
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Communication</div>
            <div class="sidebar-subtitle">WhatsApp & Notifications</div>
        </div>

        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            
            <div class="nav-section-title">WhatsApp</div>
            <a class="nav-link" href="whatsapp.php?tab=config">
                <i class="fas fa-cog"></i> Configuration
            </a>
            <a class="nav-link" href="whatsapp.php?tab=templates">
                <i class="fas fa-file-code"></i> Templates
            </a>
            <a class="nav-link" href="whatsapp.php?tab=automation">
                <i class="fas fa-robot"></i> Automation Rules
            </a>
            <a class="nav-link" href="whatsapp.php?tab=logs">
                <i class="fas fa-history"></i> Message Logs
            </a>
            <a class="nav-link active" href="campaigns.php">
                <i class="fas fa-bullhorn"></i> Campaigns
            </a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h3 page-title">WhatsApp Campaigns</h1>
            <div class="page-subtitle">Create and monitor broadcast campaigns</div>
        </div>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCampaignModal">
                <i class="fas fa-plus me-2"></i>Create Campaign
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($campaigns)): ?>
                <div class="text-center text-muted my-5">
                    <i class="fas fa-bullhorn fa-3x mb-3"></i>
                    <h5>No Campaigns Yet</h5>
                    <p>Create your first campaign to broadcast messages to clients or employees.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Campaign Name</th>
                                <th>Template</th>
                                <th>Segment</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $camp): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($camp['name']); ?></strong>
                                        <?php if ($camp['festival_name']): ?>
                                            <div class="small text-muted"><i class="fas fa-gift me-1"></i><?php echo htmlspecialchars($camp['festival_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($camp['template_name'] ?: 'Unknown'); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($camp['trigger_type']); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                            echo match($camp['segment']) {
                                                'all_clients' => '<span class="badge bg-info">All Clients</span>',
                                                'all_employees' => '<span class="badge bg-primary">All Employees</span>',
                                                'custom' => '<span class="badge bg-secondary">Custom List</span>',
                                                default => $camp['segment']
                                            };
                                        ?>
                                    </td>
                                    <td><?php echo formatDate($camp['scheduled_at'], 'd M Y H:i'); ?></td>
                                    <td>
                                        <?php
                                            $bClass = match($camp['status']) {
                                                'draft' => 'secondary',
                                                'scheduled' => 'warning text-dark',
                                                'running' => 'primary',
                                                'completed' => 'success',
                                                'cancelled' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>
                                        <span class="badge bg-<?php echo $bClass; ?>"><?php echo ucfirst($camp['status']); ?></span>
                                    </td>
                                    <td style="min-width: 150px;">
                                        <?php 
                                            $total = (int)$camp['total_recipients'];
                                            $sent = (int)$camp['sent_recipients'];
                                            $failed = (int)$camp['failed_recipients'];
                                            $pct = $total > 0 ? round((($sent + $failed) / $total) * 100) : 0;
                                        ?>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span><?php echo $sent; ?>/<?php echo $total; ?></span>
                                            <span><?php echo $pct; ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 5px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $total > 0 ? ($sent/$total)*100 : 0; ?>%"></div>
                                            <div class="progress-bar bg-danger" style="width: <?php echo $total > 0 ? ($failed/$total)*100 : 0; ?>%"></div>
                                        </div>
                                        <?php if ($failed > 0): ?>
                                            <div class="small text-danger mt-1"><?php echo $failed; ?> failed</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($camp['status'] === 'scheduled'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning" title="Cancel Campaign" onclick="confirmCancel(<?php echo $camp['id']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete Campaign" onclick="confirmDelete(<?php echo $camp['id']; ?>)">
                                            <i class="fas fa-trash-alt"></i>
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

<!-- Create Campaign Modal -->
<div class="modal fade" id="createCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_campaign">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Campaign Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g., Diwali 2026 Greetings">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Message Template <span class="text-danger">*</span></label>
                            <select class="form-select" name="template_id" required>
                                <option value="">Select Template...</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?php echo $t['id']; ?>">
                                        <?php echo htmlspecialchars($t['template_name']); ?> (<?php echo ucfirst($t['category']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Festival (Optional)</label>
                            <select class="form-select" name="festival_name">
                                <option value="">Not a festival campaign</option>
                                <?php foreach ($festivals as $f): ?>
                                    <option value="<?php echo htmlspecialchars($f['name']); ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Used for {festival_name} variables in templates.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Target Segment <span class="text-danger">*</span></label>
                            <select class="form-select" name="segment" id="segmentSelect" required onchange="toggleCustomList()">
                                <option value="all_clients">All Clients</option>
                                <option value="all_employees">All Employees</option>
                                <option value="custom">Custom List</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Schedule Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="scheduled_at" required min="<?php echo date('Y-m-d\TH:i'); ?>" value="<?php echo date('Y-m-d\TH:i', strtotime('+1 hour')); ?>">
                        </div>
                        
                        <div class="col-md-12" id="customListWrapper" style="display:none;">
                            <label class="form-label">Custom List (One per line: Name,Phone or just Phone)</label>
                            <textarea class="form-control" name="custom_list" rows="5" placeholder="John Doe, 919876543210&#10;919876543211"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Schedule Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Campaign Modal -->
<div class="modal fade" id="cancelCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="action" value="cancel_campaign">
                <input type="hidden" name="campaign_id" id="cancel_campaign_id">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center pb-4">
                    <div class="mb-3 text-danger">
                        <i class="fas fa-exclamation-triangle fa-3x"></i>
                    </div>
                    <h5 class="modal-title mb-2">Cancel Campaign</h5>
                    <p class="text-muted">Are you sure you want to cancel this scheduled campaign?</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger px-4">Yes, Cancel it</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Campaign Modal -->
<div class="modal fade" id="deleteCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_campaign">
                <input type="hidden" name="campaign_id" id="delete_campaign_id">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center pb-4">
                    <div class="mb-3 text-danger">
                        <i class="fas fa-trash-alt fa-3x"></i>
                    </div>
                    <h5 class="modal-title mb-2">Delete Campaign</h5>
                    <p class="text-muted">Are you sure you want to completely delete this campaign? This cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger px-4">Yes, Delete it</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleCustomList() {
    const segment = document.getElementById('segmentSelect').value;
    document.getElementById('customListWrapper').style.display = segment === 'custom' ? 'block' : 'none';
}

function confirmCancel(id) {
    document.getElementById('cancel_campaign_id').value = id;
    new bootstrap.Modal(document.getElementById('cancelCampaignModal')).show();
}

function confirmDelete(id) {
    document.getElementById('delete_campaign_id').value = id;
    new bootstrap.Modal(document.getElementById('deleteCampaignModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
