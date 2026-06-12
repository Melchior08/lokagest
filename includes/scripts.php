<?php
if (!isset($basePath)) {
    $basePath = '..';
}
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/app.php';
}
?>
<script>window.LOKAGEST_BASE = <?php echo json_encode(rtrim(APP_URL, '/')); ?>;</script>
<script src="<?php echo htmlspecialchars($basePath); ?>/js/auth.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
