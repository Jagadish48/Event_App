<?php
require_once __DIR__ . '/config/database.php';
ensureEventProfitFeedbackIncentiveSchema();

$branding = getBrandingSettings();
$appName = trim((string) ($branding['app_name'] ?? 'NETWORK EVENTS'));
$appName = $appName !== '' ? $appName : 'NETWORK EVENTS';
$faviconUrl = resolveAppAssetUrl($branding['favicon'] ?? '');

$token = trim((string) ($_GET['t'] ?? ($_POST['t'] ?? '')));
$error = '';
$success = '';
$eventName = '';
$clientName = '';

if ($token !== '') {
    try {
        $stmt = $pdo->prepare("SELECT r.event_id, e.name as event_name, c.name as client_name
                               FROM event_feedback_requests r
                               JOIN events e ON e.id = r.event_id
                               LEFT JOIN clients c ON c.id = e.client_id
                               WHERE r.token = ?
                               LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            $eventName = (string) ($row['event_name'] ?? '');
            $clientName = (string) ($row['client_name'] ?? '');
        }
    } catch (PDOException $e) {
        $error = 'Unable to load feedback request.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rating = (int) ($_POST['rating'] ?? 0);
        $message = trim((string) ($_POST['message'] ?? ''));
        submitEventFeedbackByToken($token, $rating, $message);
        $success = 'Thank you. Your feedback has been submitted successfully.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($appName); ?> - Event Feedback</title>
    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <?php endif; ?>
    <link href="<?php echo SITE_URL; ?>assets/css/bootstrap.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/all.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/all.min.css'); ?>" rel="stylesheet">
</head>
<body data-app-name="<?php echo htmlspecialchars($appName); ?>" data-theme="light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="fw-bold">Client Feedback</div>
                        <span class="badge bg-primary">Event</span>
                    </div>
                    <div class="card-body">
                        <?php if ($token === ''): ?>
                            <div class="alert alert-danger mb-0">Invalid feedback link.</div>
                        <?php else: ?>
                            <?php if ($success !== ''): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($error !== ''): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <div class="text-muted small">Event</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($eventName !== '' ? $eventName : '—'); ?></div>
                                <?php if ($clientName !== ''): ?>
                                    <div class="text-muted small mt-1"><?php echo htmlspecialchars($clientName); ?></div>
                                <?php endif; ?>
                            </div>

                            <form method="POST" action="">
                                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
                                <div class="mb-3">
                                    <label class="form-label">Rating *</label>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <input type="radio" class="btn-check" name="rating" id="r<?php echo $i; ?>" value="<?php echo $i; ?>" required>
                                            <label class="btn btn-outline-secondary" for="r<?php echo $i; ?>">
                                                <?php echo $i; ?><i class="fas fa-star ms-1 text-warning"></i>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="form-text text-muted mt-2">1★ Poor • 2★ Average • 3★ Good • 4★ Very Good • 5★ Excellent</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Feedback Message</label>
                                    <textarea class="form-control" name="message" rows="4" placeholder="Share your experience..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Feedback
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-center text-muted small mt-3">Powered by <?php echo htmlspecialchars($appName); ?></div>
            </div>
        </div>
    </div>
    <script src="<?php echo SITE_URL; ?>assets/js/bootstrap.bundle.min.js?v=<?php echo filemtime(__DIR__ . '/assets/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
