<?php
require_once __DIR__.'/../inc/auth.php';
require_login();
require_once __DIR__.'/../inc/header.php';

// Подсчёт стоимости обучения
$stmt = db()->query("SELECT SUM(total_cost) AS cost FROM trainings WHERE status='running'");
$running = $stmt->fetch();
$cost = isset($running['cost']) ? $running['cost'] : 0;
?>
<div class="container mx-auto py-6 max-w-5xl">
    <h2 class="text-2xl font-bold mb-4 text-indigo-400"><?= htmlspecialchars(t('training.title')) ?></h2>
    <p class="mb-4 text-gray-400"><?= htmlspecialchars(t('training.cost')) ?>: $<?=number_format($cost, 2)?></p>
    <!-- Встраиваем форму из scripts/ingest.php -->
    <?php
    // Embedded ingest UI with endpoint awareness
    if (!defined('EMBEDDED_INGEST')) define('EMBEDDED_INGEST', true);
    $endpoint = '';
    if (file_exists(__DIR__.'/../scripts/ingest.php')) {
        $endpoint = dirname($_SERVER['SCRIPT_NAME']).'/../scripts/ingest.php';
        if (!defined('INGEST_ENDPOINT')) define('INGEST_ENDPOINT', $endpoint);
        include __DIR__.'/../scripts/ingest.php';
    } elseif (file_exists(__DIR__.'/../ingest.php')) {
        $endpoint = dirname($_SERVER['SCRIPT_NAME']).'/../ingest.php';
        if (!defined('INGEST_ENDPOINT')) define('INGEST_ENDPOINT', $endpoint);
        include __DIR__.'/../ingest.php';
    } else {
        echo '<div class="text-red-400">Ingest script not found.</div>';
    }
    ?>
</div>
<?php require_once __DIR__.'/../inc/footer.php'; ?>