<?php
$pageTitle = 'Leads';
require_once '../includes/header.php';
requireEmployee();

$employeeId = (int) ($_SESSION['user_id'] ?? 0);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif ($_POST['action'] === 'add_lead') {
        $name = trim((string) clean_input($_POST['name'] ?? ''));
        $company = trim((string) clean_input($_POST['company'] ?? ''));
        $phone = trim((string) clean_input($_POST['phone'] ?? ''));
        $email = trim((string) clean_input($_POST['email'] ?? ''));
        $source = trim((string) clean_input($_POST['source'] ?? ''));
        $description = trim((string) clean_input($_POST['description'] ?? ''));

        if ($name === '' || $phone === '') {
            $error = 'Lead name and phone are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO leads (name, email, phone, company, description, status, assigned_to, source, created_at, updated_at)
                                       VALUES (?, ?, ?, ?, ?, 'new', ?, ?, NOW(), NOW())");
                $stmt->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone,
                    $company !== '' ? $company : null,
                    $description !== '' ? $description : null,
                    $employeeId,
                    $source !== '' ? $source : null
                ]);
                $success = 'Lead added successfully.';
            } catch (PDOException $e) {
                $error = 'Unable to add lead. Please try again.';
            }
        }
    } elseif ($_POST['action'] === 'edit_lead') {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $name = trim((string) clean_input($_POST['name'] ?? ''));
        $company = trim((string) clean_input($_POST['company'] ?? ''));
        $phone = trim((string) clean_input($_POST['phone'] ?? ''));
        $email = trim((string) clean_input($_POST['email'] ?? ''));
        $source = trim((string) clean_input($_POST['source'] ?? ''));
        $status = strtolower(trim((string) clean_input($_POST['status'] ?? 'new')));
        $description = trim((string) clean_input($_POST['description'] ?? ''));

        $allowedStatus = ['new', 'contacted', 'converted', 'lost'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'new';
        }

        if ($leadId < 1) {
            $error = 'Invalid lead.';
        } elseif ($name === '' || $phone === '') {
            $error = 'Lead name and phone are required.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE leads
                                       SET name = ?, email = ?, phone = ?, company = ?, description = ?, status = ?, source = ?, updated_at = NOW()
                                       WHERE id = ? AND assigned_to = ?
                                       LIMIT 1");
                $stmt->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone,
                    $company !== '' ? $company : null,
                    $description !== '' ? $description : null,
                    $status,
                    $source !== '' ? $source : null,
                    $leadId,
                    $employeeId
                ]);
                if ($stmt->rowCount() < 1) {
                    $error = 'Lead not found.';
                } else {
                    $success = 'Lead updated successfully.';
                }
            } catch (PDOException $e) {
                $error = 'Unable to update lead. Please try again.';
            }
        }
    } elseif ($_POST['action'] === 'update_status') {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $status = strtolower(trim((string) clean_input($_POST['status'] ?? '')));
        $allowedStatus = ['new', 'contacted', 'converted', 'lost'];
        if ($leadId > 0 && in_array($status, $allowedStatus, true)) {
            try {
                $stmt = $pdo->prepare("UPDATE leads SET status = ?, updated_at = NOW() WHERE id = ? AND assigned_to = ? LIMIT 1");
                $stmt->execute([$status, $leadId, $employeeId]);
                if ($stmt->rowCount() < 1) {
                    $error = 'Lead not found.';
                } else {
                    $success = 'Lead status updated successfully.';
                }
            } catch (PDOException $e) {
                $error = 'Unable to update status. Please try again.';
            }
        }
    }
}

$leads = [];
$stats = [
    'total' => 0,
    'new' => 0,
    'contacted' => 0,
    'converted' => 0,
    'lost' => 0
];

try {
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE assigned_to = ? ORDER BY created_at DESC");
    $stmt->execute([$employeeId]);
    $leads = $stmt->fetchAll();

    foreach ($leads as $lead) {
        $stats['total']++;
        $status = (string) ($lead['status'] ?? '');
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }
} catch (PDOException $e) {
    error_log('Lead Fetch Error (employee/leads.php): ' . $e->getMessage());
    $error = $error ?: 'Unable to load leads. Please try again.';
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
            <h1 class="h3 page-title">Leads</h1>
            <div class="page-subtitle">Capture new leads and track follow-ups</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                <i class="fas fa-plus me-2"></i>Add Lead
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="clients.php"><i class="fas fa-building me-2"></i>Client Workflow</a>
        <a class="btn btn-secondary" href="tasks.php"><i class="fas fa-list-check me-2"></i>My Tasks</a>
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

    <div class="row mb-4">
        <div class="col-md-2">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                <div class="stat-value"><?php echo $stats['new']; ?></div>
                <div class="stat-label">New</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-phone"></i></div>
                <div class="stat-value"><?php echo $stats['contacted']; ?></div>
                <div class="stat-label">Contacted</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-check"></i></div>
                <div class="stat-value"><?php echo $stats['converted']; ?></div>
                <div class="stat-label">Converted</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-xmark"></i></div>
                <div class="stat-value"><?php echo $stats['lost']; ?></div>
                <div class="stat-label">Lost</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h5 class="mb-0">Your Leads</h5>
                <div class="position-relative" style="min-width: min(360px, 100%);">
                    <i class="fas fa-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input type="search" class="form-control ps-5" id="leadSearchInput" placeholder="Search leads..." autocomplete="off">
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($leads)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted mb-0">No leads available.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm" id="leadsTable">
                        <thead>
                            <tr>
                                <th>Lead</th>
                                <th>Company</th>
                                <th>Phone</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $lead): ?>
                                <?php
                                    $search = strtolower(trim(
                                        (string) ($lead['name'] ?? '') . ' ' .
                                        (string) ($lead['company'] ?? '') . ' ' .
                                        (string) ($lead['phone'] ?? '') . ' ' .
                                        (string) ($lead['email'] ?? '') . ' ' .
                                        (string) ($lead['source'] ?? '')
                                    ));
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($lead['name'] ?? ''); ?></div>
                                        <?php if (!empty($lead['email'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($lead['email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(($lead['company'] ?? '') ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($lead['phone'] ?? '-'); ?></td>
                                    <td class="small text-muted"><?php echo htmlspecialchars(($lead['source'] ?? '') ?: '-'); ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="lead_id" value="<?php echo (int) $lead['id']; ?>">
                                            <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                                                <option value="new" <?php echo ($lead['status'] ?? '') === 'new' ? 'selected' : ''; ?>>New</option>
                                                <option value="contacted" <?php echo ($lead['status'] ?? '') === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                                                <option value="converted" <?php echo ($lead['status'] ?? '') === 'converted' ? 'selected' : ''; ?>>Converted</option>
                                                <option value="lost" <?php echo ($lead['status'] ?? '') === 'lost' ? 'selected' : ''; ?>>Lost</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?php echo !empty($lead['created_at']) ? formatDate($lead['created_at']) : '-'; ?></td>
                                    <td>
                                        <div class="table-action-group">
                                            <button class="btn btn-sm btn-primary"
                                                type="button"
                                                onclick="openEditLead(this)"
                                                data-lead-id="<?php echo (int) $lead['id']; ?>"
                                                data-lead-name="<?php echo htmlspecialchars((string) ($lead['name'] ?? '')); ?>"
                                                data-lead-company="<?php echo htmlspecialchars((string) ($lead['company'] ?? '')); ?>"
                                                data-lead-phone="<?php echo htmlspecialchars((string) ($lead['phone'] ?? '')); ?>"
                                                data-lead-email="<?php echo htmlspecialchars((string) ($lead['email'] ?? '')); ?>"
                                                data-lead-source="<?php echo htmlspecialchars((string) ($lead['source'] ?? '')); ?>"
                                                data-lead-status="<?php echo htmlspecialchars((string) ($lead['status'] ?? 'new')); ?>"
                                                data-lead-description="<?php echo htmlspecialchars((string) ($lead['description'] ?? '')); ?>">
                                                <i class="fas fa-edit"></i>
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
</div>

<div class="modal fade" id="addLeadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_lead">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Company</label>
                                <input type="text" class="form-control" name="company">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="tel" class="form-control" name="phone" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Source</label>
                                <select class="form-select" name="source">
                                    <option value="">Select Source</option>
                                    <option value="Website">Website</option>
                                    <option value="Referral">Referral</option>
                                    <option value="Cold Call">Cold Call</option>
                                    <option value="Email">Email</option>
                                    <option value="Social Media">Social Media</option>
                                    <option value="Advertisement">Advertisement</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Lead
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editLeadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_lead">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                    <input type="hidden" name="lead_id" id="edit_lead_id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company</label>
                            <input type="text" class="form-control" name="company" id="edit_company">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" id="edit_phone" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Source</label>
                            <select class="form-select" name="source" id="edit_source">
                                <option value="">Select Source</option>
                                <option value="Website">Website</option>
                                <option value="Referral">Referral</option>
                                <option value="Cold Call">Cold Call</option>
                                <option value="Email">Email</option>
                                <option value="Social Media">Social Media</option>
                                <option value="Advertisement">Advertisement</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="new">New</option>
                                <option value="contacted">Contacted</option>
                                <option value="converted">Converted</option>
                                <option value="lost">Lost</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function openEditLead(btn) {
    try {
        document.getElementById('edit_lead_id').value = btn.dataset.leadId || '';
        document.getElementById('edit_name').value = btn.dataset.leadName || '';
        document.getElementById('edit_company').value = btn.dataset.leadCompany || '';
        document.getElementById('edit_phone').value = btn.dataset.leadPhone || '';
        document.getElementById('edit_email').value = btn.dataset.leadEmail || '';
        document.getElementById('edit_source').value = btn.dataset.leadSource || '';
        document.getElementById('edit_status').value = btn.dataset.leadStatus || 'new';
        document.getElementById('edit_description').value = btn.dataset.leadDescription || '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editLeadModal')).show();
    } catch (error) {
        console.error('Lead Fetch Error:', error);
        showAlert('danger', 'Unable to load lead details. Please try again.');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var search = document.getElementById('leadSearchInput');
    var table = document.getElementById('leadsTable');
    if (!search || !table) return;

    search.addEventListener('input', function() {
        var q = (search.value || '').toLowerCase().trim();
        Array.from(table.querySelectorAll('tbody tr')).forEach(function(tr) {
            var text = (tr.textContent || '').toLowerCase();
            tr.style.display = q === '' || text.indexOf(q) !== -1 ? '' : 'none';
        });
    });
});
</script>
";
require_once '../includes/footer.php';
?>
