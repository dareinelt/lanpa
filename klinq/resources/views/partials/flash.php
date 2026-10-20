<?php
$flashes = $_SESSION['_flash'] ?? [];
unset($_SESSION['_flash']);
foreach ($flashes as $flash):
    $type = in_array($flash['type'] ?? '', ['success', 'error', 'info', 'warning'], true) ? $flash['type'] : 'info';
?>
    <div class="alert alert-<?= e($type) ?>" role="alert"><?= e($flash['message'] ?? '') ?></div>
<?php endforeach; ?>
