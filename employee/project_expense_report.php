<?php
$pageTitle = 'Project Expense Report';
require_once '../includes/header.php';
requireEmployee();
ensureProjectExpenseReportsSchema();
ensureClientWorkflowSchema();

$success = '';
$error = '';

try {
    $stmt = $pdo->prepare("SELECT id, name, company FROM clients WHERE assigned_to = ? ORDER BY name ASC");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $assignedClients = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignedClients = [];
}

try {
    $stmt = $pdo->prepare("SELECT e.id, e.name, e.client_id, c.name as client_name
        FROM event_team et
        JOIN events e ON e.id = et.event_id
        LEFT JOIN clients c ON c.id = e.client_id
        WHERE et.user_id = ?
        ORDER BY e.start_date DESC, e.id DESC");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $assignedEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignedEvents = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'submit_report')) {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $totalAmount = (string) ($_POST['total_amount'] ?? '0');
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($eventId < 1) {
        $error = 'Please select an event.';
    } elseif (!isset($_FILES['report_file']) || ($_FILES['report_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Please upload an Excel file (.xls or .xlsx).';
    } elseif (!is_numeric($totalAmount) || (float) $totalAmount < 0) {
        $error = 'Please enter a valid total amount.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT e.id, e.client_id
                FROM event_team et
                JOIN events e ON e.id = et.event_id
                WHERE et.user_id = ? AND e.id = ?
                LIMIT 1");
            $stmt->execute([(int) $_SESSION['user_id'], $eventId]);
            $eventRow = $stmt->fetch();
            if (!$eventRow) {
                $error = 'Invalid event selection.';
            } else {
                $eventClientId = (int) ($eventRow['client_id'] ?? 0);
                if ($clientId < 1) {
                    $clientId = $eventClientId;
                }
                if ($clientId !== $eventClientId && $eventClientId > 0) {
                    $error = 'Selected client does not match the selected event.';
                }
            }

            if (!$error) {
                $stmt = $pdo->prepare("SELECT id, status
                                       FROM project_expense_reports
                                       WHERE user_id = ? AND event_id = ?
                                       ORDER BY id DESC
                                       LIMIT 1");
                $stmt->execute([(int) $_SESSION['user_id'], $eventId]);
                $existing = $stmt->fetch();

                if ($existing && (($existing['status'] ?? '') === 'approved')) {
                    $error = 'This event report is already approved and cannot be resubmitted.';
                } else {
                    $filePath = uploadExcelFile($_FILES['report_file'], 'project_expense_reports');

                    if ($existing) {
                        $stmt = $pdo->prepare("UPDATE project_expense_reports
                                               SET client_id = ?,
                                                   total_amount = ?,
                                                   summary = ?,
                                                   remarks = ?,
                                                   file_path = ?,
                                                   status = 'pending',
                                                   reviewed_by = NULL,
                                                   reviewed_at = NULL,
                                                   admin_comment = NULL,
                                                   submitted_at = NOW()
                                               WHERE id = ? AND user_id = ? AND event_id = ?
                                               LIMIT 1");
                        $stmt->execute([
                            $clientId > 0 ? $clientId : null,
                            (float) $totalAmount,
                            $summary !== '' ? $summary : null,
                            $remarks !== '' ? $remarks : null,
                            $filePath,
                            (int) ($existing['id'] ?? 0),
                            (int) $_SESSION['user_id'],
                            $eventId
                        ]);
                        $success = 'Project expense report updated successfully.';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO project_expense_reports (user_id, client_id, event_id, total_amount, summary, remarks, file_path)
                                               VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            (int) $_SESSION['user_id'],
                            $clientId > 0 ? $clientId : null,
                            $eventId,
                            (float) $totalAmount,
                            $summary !== '' ? $summary : null,
                            $remarks !== '' ? $remarks : null,
                            $filePath
                        ]);
                        $success = 'Project expense report submitted successfully.';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Failed to submit report. Please try again.';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT pr.*, c.name as client_name, ev.name as event_name
        FROM project_expense_reports pr
        JOIN (
            SELECT user_id, event_id, MAX(id) as id
            FROM project_expense_reports
            WHERE user_id = ?
            GROUP BY user_id, event_id
        ) latest ON latest.id = pr.id
        LEFT JOIN clients c ON c.id = pr.client_id
        LEFT JOIN events ev ON ev.id = pr.event_id
        WHERE pr.user_id = ?
        ORDER BY pr.submitted_at DESC, pr.id DESC");
    $stmt->execute([(int) $_SESSION['user_id'], (int) $_SESSION['user_id']]);
    $reports = $stmt->fetchAll();
} catch (PDOException $e) {
    $reports = [];
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
            <a class="btn btn-primary btn-sm" href="project_expense_report.php?open=upload">
                <i class="fas fa-file-excel me-2"></i>Report
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="nav flex-column">
            <div class="nav-section-title">Overview</div>
            <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="tasks.php"><i class="fas fa-list-check"></i> Tasks</a>

            <div class="nav-section-title">Work</div>
            <a class="nav-link" href="clients.php"><i class="fas fa-building"></i> Clients</a>
            <a class="nav-link" href="leads.php"><i class="fas fa-handshake"></i> Leads</a>
            <a class="nav-link" href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php"><i class="fas fa-money-bill-wave"></i> Expenses</a>
            <a class="nav-link" href="project_expense_report.php"><i class="fas fa-file-excel"></i> Project Expense Report</a>
            <a class="nav-link" href="payroll.php"><i class="fas fa-calculator"></i> Payroll</a>
            <a class="nav-link" href="salary.php"><i class="fas fa-wallet"></i> Salary</a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link" href="profile.php"><i class="fas fa-gear"></i> Settings</a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Project Expense Report</h1>
            <div class="page-subtitle">Upload a final Excel expense sheet for an event when the project is completed</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadReportModal">
                <i class="fas fa-upload me-2"></i>Upload Excel
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-file-excel me-2"></i>Your Submissions</h5>
            <span class="text-muted small"><?php echo count($reports); ?> report(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($reports)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <div class="empty-title">No reports submitted</div>
                    <div class="empty-subtitle">Upload your final Excel sheet (.xls/.xlsx) along with a summary and remarks for admin review.</div>
                    <div class="empty-actions">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadReportModal">
                            <i class="fas fa-upload me-2"></i>Upload Excel
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>File</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $r): ?>
                                <?php
                                    $st = (string) ($r['status'] ?? 'pending');
                                    $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning');
                                    $fileUrl = SITE_URL . 'uploads/' . ltrim((string) ($r['file_path'] ?? ''), '/');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(($r['event_name'] ?? '') ?: 'Event'); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars(($r['client_name'] ?? '') ?: ''); ?></div>
                                    </td>
                                    <td><strong><?php echo formatCurrency((float) ($r['total_amount'] ?? 0)); ?></strong></td>
                                    <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) ($r['submitted_at'] ?? 'now')))); ?></td>
                                    <td>
                                        <?php if (!empty($r['file_path'])): ?>
                                            <a class="btn btn-sm btn-info" href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
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

<div class="modal fade" id="uploadReportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i>Upload Project Expense Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_report">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client (optional)</label>
                            <select class="form-select" name="client_id" id="prClientSelect">
                                <option value="">Auto from event</option>
                                <?php foreach ($assignedClients as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['name']); ?><?php echo $c['company'] ? (' • ' . htmlspecialchars($c['company'])) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Event *</label>
                            <select class="form-select" name="event_id" id="prEventSelect" required>
                                <option value="">Select Event</option>
                                <?php foreach ($assignedEvents as $ev): ?>
                                    <option value="<?php echo (int) $ev['id']; ?>" data-client-id="<?php echo (int) ($ev['client_id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($ev['name']); ?><?php echo $ev['client_name'] ? (' • ' . htmlspecialchars($ev['client_name'])) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Total Project Spending *</label>
                            <input type="number" class="form-control" name="total_amount" step="0.01" min="0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Project Summary</label>
                            <textarea class="form-control" name="summary" rows="3" placeholder="Short summary of the project expenses and highlights"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Final Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3" placeholder="Any final notes for the admin (optional)"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><i class="fas fa-upload me-2"></i>Excel File (.xls/.xlsx) *</label>
                            <div class="upload-area" id="excelDropArea">
                                <input type="file" class="form-control" name="report_file" id="report_file" accept=".xls,.xlsx" required>
                                <div class="text-muted small mt-2">Drag & drop your Excel file here or click to select.</div>
                                <div id="report_file_preview" class="mt-2"></div>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-shield me-2"></i>Only Excel files (.xls, .xlsx) are accepted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit to Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function filterProjectEventsByClient(clientId) {
    const select = document.getElementById('prEventSelect');
    if (!select) return;
    const wanted = parseInt(clientId || '0', 10) || 0;
    Array.from(select.options).forEach(function(opt) {
        if (!opt.value) return;
        const cid = parseInt(opt.dataset.clientId || '0', 10) || 0;
        opt.hidden = wanted > 0 && cid > 0 && cid !== wanted;
    });
    if (select.selectedOptions.length && select.selectedOptions[0].hidden) {
        select.value = '';
    }
}

document.getElementById('prClientSelect')?.addEventListener('change', function(e) {
    filterProjectEventsByClient(e.target.value);
});

const dropArea = document.getElementById('excelDropArea');
const fileInput = document.getElementById('report_file');

if (dropArea && fileInput) {
    ['dragenter', 'dragover'].forEach(function(evt) {
        dropArea.addEventListener(evt, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropArea.classList.add('drag-over');
        });
    });

    ['dragleave', 'drop'].forEach(function(evt) {
        dropArea.addEventListener(evt, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropArea.classList.remove('drag-over');
        });
    });

    dropArea.addEventListener('drop', function(e) {
        const dt = e.dataTransfer;
        if (!dt || !dt.files || !dt.files.length) return;
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

document.querySelectorAll('#uploadReportModal form').forEach(function(form) {
    form.addEventListener('submit', function() {
        const btn = form.querySelector('button[type=\"submit\"]');
        if (!btn) return;
        btn.disabled = true;
        btn.innerHTML = '<span class=\"spinner-border spinner-border-sm me-2\" role=\"status\" aria-hidden=\"true\"></span>Uploading...';
    });
});


</script>
";
require_once '../includes/footer.php';
?>
