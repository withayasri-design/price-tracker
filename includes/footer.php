<?php
/**
 * Common Footer Include
 *
 * Include this at the bottom of all pages for consistent scripts and footer.
 *
 * Usage:
 *   <?php include __DIR__ . '/../includes/footer.php'; ?>
 */

$basePath = $basePath ?? '';
$currentYear = date('Y');
$version = trim(file_get_contents(__DIR__ . '/../VERSION') ?: '1.0.0');
?>

<!-- Footer -->
<footer class="footer mt-auto py-3 bg-light border-top">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start">
                <span class="text-muted">
                    &copy; <?= $currentYear ?> Price Tracker
                    <small class="ms-2">v<?= $version ?></small>
                </span>
            </div>
            <div class="col-md-6 text-center text-md-end">
                <a href="<?= $basePath ?>/pages/export.php" class="text-muted text-decoration-none me-3">
                    <i class="bi bi-download"></i> Export
                </a>
                <a href="<?= $basePath ?>/api/docs.php" class="text-muted text-decoration-none me-3">
                    <i class="bi bi-book"></i> API
                </a>
                <a href="https://github.com/withayasri-design/price-tracker"
                   class="text-muted text-decoration-none" target="_blank">
                    <i class="bi bi-github"></i> GitHub
                </a>
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom JS -->
<script src="<?= $basePath ?>/assets/js/app.js"></script>

<!-- Service Worker Registration (PWA) -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('<?= $basePath ?>/sw.js')
            .then(function(registration) {
                console.log('SW registered:', registration.scope);
            })
            .catch(function(error) {
                console.log('SW registration failed:', error);
            });
    });
}
</script>
