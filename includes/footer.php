<script src="<?php echo SITE_URL; ?>assets/js/bootstrap.bundle.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/chart.js"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/script.js"></script>
    <?php if (isset($additional_js)): ?>
        <?php echo $additional_js; ?>
    <?php endif; ?>
</body>
</html>
