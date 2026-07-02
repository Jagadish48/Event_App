<script src="<?php echo SITE_URL; ?>assets/js/bootstrap.bundle.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/chart.js"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/script.js"></script>
    <?php if (isset($additional_js)): ?>
        <?php echo $additional_js; ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="mobile-bottom-nav d-flex d-lg-none">
        <?php if (isAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>admin/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i><span>Home</span>
            </a>
            <a href="<?php echo SITE_URL; ?>admin/employees.php" class="nav-item">
                <i class="fas fa-users"></i><span>Team</span>
            </a>
            <a href="<?php echo SITE_URL; ?>admin/events.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i><span>Events</span>
            </a>
            <a href="<?php echo SITE_URL; ?>admin/profile.php" class="nav-item">
                <i class="fas fa-user"></i><span>Profile</span>
            </a>
        <?php else: ?>
            <a href="<?php echo SITE_URL; ?>employee/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i><span>Home</span>
            </a>
            <a href="<?php echo SITE_URL; ?>employee/tasks.php" class="nav-item">
                <i class="fas fa-list-check"></i><span>Tasks</span>
            </a>
            <a href="<?php echo SITE_URL; ?>employee/attendance.php" class="nav-item">
                <i class="fas fa-clock"></i><span>Time</span>
            </a>
            <a href="<?php echo SITE_URL; ?>employee/profile.php" class="nav-item">
                <i class="fas fa-user"></i><span>Profile</span>
            </a>
        <?php endif; ?>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var currentUrl = window.location.href.split(/[?#]/)[0];
            var navItems = document.querySelectorAll('.mobile-bottom-nav .nav-item');
            navItems.forEach(function(item) {
                if (item.href === currentUrl) {
                    item.classList.add('active');
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
