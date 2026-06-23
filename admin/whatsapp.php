<?php
$pageTitle = 'WhatsApp Integration';
require_once '../includes/header.php';
requireAdmin();

ensureWhatsAppSchema();

$success = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'config';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_config') {
            setAppSetting('wa_enabled', isset($_POST['wa_enabled']) ? '1' : '0');
            // env values should be updated in .env manually by admin, or we can instruct them.
            // For security, we don't write to .env from here, but we can save local overrides if needed.
            $success = 'Configuration saved. (Update .env file for API credentials)';
            $activeTab = 'config';
        } elseif ($action === 'test_message') {
            $phone = trim(clean_input($_POST['test_phone']));
            if ($phone !== '') {
                $res = sendWhatsAppMessage($phone, 'lead_welcome', ['Test User', 'Test Company']);
                if ($res['success']) {
                    $success = 'Test message sent successfully! Message ID: ' . $res['message_id'];
                } else {
                    $error = 'Failed to send test message: ' . $res['error'];
                }
            } else {
                $error = 'Please enter a valid phone number.';
            }
            $activeTab = 'config';
        } elseif ($action === 'save_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            $trigger = clean_input($_POST['trigger_type']);
            $name = clean_input($_POST['template_name']);
            $cat = clean_input($_POST['category']);
            $body = clean_input($_POST['body_preview']);
            $active = isset($_POST['is_active']) ? 1 : 0;
            
            // Extract variables from body preview, or allow manual entry. Here we just rely on defaults for now or simple json array.
            $vars = json_encode(explode(',', clean_input($_POST['variables'] ?? '')));
            if ($vars === '[""]') $vars = '[]';
            
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE wa_templates SET template_name = ?, category = ?, body_preview = ?, is_active = ?, variables_json = ? WHERE id = ?");
                $stmt->execute([$name, $cat, $body, $active, $vars, $id]);
                $success = 'Template updated.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO wa_templates (trigger_type, template_name, category, body_preview, is_active, variables_json) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$trigger, $name, $cat, $body, $active, $vars]);
                $success = 'Template created.';
            }
            $activeTab = 'templates';
        } elseif ($action === 'save_automation') {
            foreach ($_POST['rules'] as $trigger => $rule) {
                $enabled = isset($rule['enabled']) ? 1 : 0;
                $start = (int)($rule['start'] ?? 9);
                $end = (int)($rule['end'] ?? 21);
                $stmt = $pdo->prepare("UPDATE wa_automation_rules SET is_enabled = ?, send_hour_start = ?, send_hour_end = ? WHERE trigger_type = ?");
                $stmt->execute([$enabled, $start, $end, $trigger]);
            }
            $success = 'Automation rules updated.';
            $activeTab = 'automation';
        } elseif ($action === 'process_queue') {
            $res = wa_processQueue(50);
            $success = "Processed queue: {$res['processed']} attempted, {$res['succeeded']} sent, {$res['failed']} failed.";
            $activeTab = 'logs';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch Data
$stats = wa_getStats('month');
$configEnabled = getAppSetting('wa_enabled', '0') === '1';

// Templates
$stmt = $pdo->query("SELECT * FROM wa_templates ORDER BY category, trigger_type");
$templates = $stmt->fetchAll();

// Logs
$stmt = $pdo->query("SELECT * FROM wa_message_log ORDER BY queued_at DESC LIMIT 100");
$logs = $stmt->fetchAll();

// Automation Rules
$stmt = $pdo->query("SELECT * FROM wa_automation_rules ORDER BY trigger_type");
$rules = $stmt->fetchAll();
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
            <a class="nav-link <?php echo $activeTab === 'config' ? 'active' : ''; ?>" href="?tab=config">
                <i class="fas fa-cog"></i> Configuration
            </a>
            <a class="nav-link <?php echo $activeTab === 'templates' ? 'active' : ''; ?>" href="?tab=templates">
                <i class="fas fa-file-code"></i> Templates
            </a>
            <a class="nav-link <?php echo $activeTab === 'automation' ? 'active' : ''; ?>" href="?tab=automation">
                <i class="fas fa-robot"></i> Automation Rules
            </a>
            <a class="nav-link <?php echo $activeTab === 'logs' ? 'active' : ''; ?>" href="?tab=logs">
                <i class="fas fa-history"></i> Message Logs
            </a>
            <a class="nav-link <?php echo $activeTab === 'campaigns' ? 'active' : ''; ?>" href="campaigns.php">
                <i class="fas fa-bullhorn"></i> Campaigns
            </a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h3 page-title">WhatsApp Integration</h1>
            <div class="page-subtitle">Manage Meta Cloud API Settings and Templates</div>
        </div>
        <div>
            <?php if (isWhatsAppEnabled()): ?>
                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> System Active</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> System Disabled</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
                <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Messages (Month)</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                <div class="stat-value"><?php echo number_format($stats['sent'] + $stats['delivered'] + $stats['read']); ?></div>
                <div class="stat-label">Successfully Sent</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-value"><?php echo number_format($stats['failed']); ?></div>
                <div class="stat-label">Failed Deliveries</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value"><?php echo number_format($stats['pending_queue']); ?></div>
                <div class="stat-label">Pending in Queue</div>
            </div>
        </div>
    </div>

    <!-- Tabs Content -->
    <div class="card">
        <div class="card-body">
            
            <?php if ($activeTab === 'config'): ?>
                <h5 class="card-title mb-4">API Configuration</h5>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i><strong>Note:</strong> API credentials (Tokens, Phone Number ID) are securely managed in the <code>.env</code> file in your project root.
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_config">
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="wa_enabled" name="wa_enabled" <?php echo $configEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="wa_enabled">Enable WhatsApp Integration (Master Switch)</label>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Settings</button>
                </form>

                <hr class="my-4">

                <h5 class="card-title mb-3">Test Connection</h5>
                <form method="POST" action="" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="test_message">
                    <div class="col-md-4">
                        <label class="form-label">Phone Number (with Country Code)</label>
                        <input type="text" class="form-control" name="test_phone" placeholder="e.g., 919876543210" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-secondary"><i class="fas fa-paper-plane me-2"></i>Send Test Message</button>
                    </div>
                </form>

            <?php elseif ($activeTab === 'templates'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="card-title mb-0">Message Templates</h5>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Trigger Type</th>
                                <th>Meta Template Name</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $tpl): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tpl['trigger_type']))); ?></div>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($tpl['template_name']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo ucfirst($tpl['category']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($tpl['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="editTemplate(<?php echo htmlspecialchars(json_encode($tpl)); ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($activeTab === 'automation'): ?>
                <h5 class="card-title mb-4">Automation Rules</h5>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_automation">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Trigger Event</th>
                                    <th>Enabled</th>
                                    <th>Allowed Time Window</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                    <tr>
                                        <td><strong><?php echo ucwords(str_replace('_', ' ', $rule['trigger_type'])); ?></strong></td>
                                        <td>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="rules[<?php echo $rule['trigger_type']; ?>][enabled]" 
                                                       <?php echo $rule['is_enabled'] ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <select class="form-select form-select-sm" style="width: 80px;" name="rules[<?php echo $rule['trigger_type']; ?>][start]">
                                                    <?php for($i=0; $i<24; $i++): ?>
                                                        <option value="<?php echo $i; ?>" <?php echo $rule['send_hour_start'] == $i ? 'selected' : ''; ?>><?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>:00</option>
                                                    <?php endfor; ?>
                                                </select>
                                                <span>to</span>
                                                <select class="form-select form-select-sm" style="width: 80px;" name="rules[<?php echo $rule['trigger_type']; ?>][end]">
                                                    <?php for($i=0; $i<24; $i++): ?>
                                                        <option value="<?php echo $i; ?>" <?php echo $rule['send_hour_end'] == $i ? 'selected' : ''; ?>><?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>:00</option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Automation Rules</button>
                </form>

            <?php elseif ($activeTab === 'logs'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="card-title mb-0">Recent Message Logs (Last 100)</h5>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="process_queue">
                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-sync me-2"></i>Process Pending Queue</button>
                    </form>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle" data-smart-table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Recipient</th>
                                <th>Template / Trigger</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo formatDate($log['queued_at'], 'd M Y H:i'); ?></td>
                                    <td><?php echo htmlspecialchars($log['recipient']); ?></td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($log['template_name'] ?: 'Unknown'); ?></strong></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($log['trigger_type'] ?: '-'); ?></div>
                                    </td>
                                    <td>
                                        <?php
                                            $badgeClass = match($log['status']) {
                                                'queued' => 'warning',
                                                'sent' => 'primary',
                                                'delivered', 'read' => 'success',
                                                'failed' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo ucfirst($log['status']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($log['error_message']): ?>
                                            <span class="text-danger small" title="<?php echo htmlspecialchars($log['error_message']); ?>">
                                                <i class="fas fa-info-circle"></i> Error
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($log['related_type']): ?>
                                            <div class="small text-muted">
                                                <?php echo ucfirst($log['related_type']); ?> #<?php echo $log['related_id']; ?>
                                            </div>
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

<!-- Edit Template Modal -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="template_id" id="modal_template_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Trigger Type</label>
                            <input type="text" class="form-control" name="trigger_type" id="modal_trigger_type" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category" id="modal_category">
                                <option value="utility">Utility</option>
                                <option value="marketing">Marketing</option>
                                <option value="service">Service</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Meta Template Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="template_name" id="modal_template_name" required
                                   placeholder="Exact name as approved in Meta Business Manager">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Expected Variables (Comma separated, ordered)</label>
                            <input type="text" class="form-control" name="variables" id="modal_variables" 
                                   placeholder="e.g., employee_name, amount, month">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Body Preview (for reference)</label>
                            <textarea class="form-control" name="body_preview" id="modal_body_preview" rows="4"></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="modal_is_active">
                                <label class="form-check-label" for="modal_is_active">Template Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editTemplate(tpl) {
    document.getElementById('modal_template_id').value = tpl.id;
    document.getElementById('modal_trigger_type').value = tpl.trigger_type;
    document.getElementById('modal_category').value = tpl.category;
    document.getElementById('modal_template_name').value = tpl.template_name;
    document.getElementById('modal_body_preview').value = tpl.body_preview;
    document.getElementById('modal_is_active').checked = tpl.is_active == 1;
    
    try {
        let vars = JSON.parse(tpl.variables_json || '[]');
        document.getElementById('modal_variables').value = vars.join(',');
    } catch(e) {
        document.getElementById('modal_variables').value = '';
    }
    
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
