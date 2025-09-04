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
    // Prefer embedding ingest UI in an iframe to avoid the included script terminating the parent page with exits.
    $candidates = [
        __DIR__ . '/../scripts/ingest.php',
        __DIR__ . '/../ingest.php',
    ];
    $iframeSrc = null;
    foreach ($candidates as $fs) {
        if (!file_exists($fs)) continue;
        // Try to map filesystem path to URL path using DOCUMENT_ROOT
        $realFs = realpath($fs);
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
            if ($docRoot && strpos($realFs, $docRoot) === 0) {
                $iframeSrc = '/' . ltrim(str_replace('\\', '/', substr($realFs, strlen($docRoot))), '/');
                break;
            }
        }
        // Fallback guesses relative to web root
        if (strpos($fs, '/scripts/ingest.php') !== false) {
            $iframeSrc = '/scripts/ingest.php';
            break;
        }
        if (strpos($fs, '/ingest.php') !== false) {
            $iframeSrc = '/ingest.php';
            break;
        }
    }

    // Additional fallback: build a URL relative to the current script location.
    // This helps when the app is served from a subdirectory and DOCUMENT_ROOT
    // is not set or doesn't match the repository layout (common in some hosts).
    if (!$iframeSrc) {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
        $baseDir = rtrim(dirname($scriptName), '/\\');
        if ($baseDir === '.' || $baseDir === '/') { $baseDir = ''; }
        $candidate = $baseDir . '/scripts/ingest.php';
        // Assign candidate URL (do not perform network request here).
        $iframeSrc = $candidate;
    }

    if ($iframeSrc) {
        echo '<div style="width:100%; height:820px; border:1px solid rgba(255,255,255,0.06); border-radius:8px; overflow:hidden">';
        echo '<iframe src="' . htmlspecialchars($iframeSrc) . '" style="width:100%; height:100%; border:0;" title="Ingest UI"></iframe>';
        echo '</div>';
    } else {
        echo '<div class="text-red-400">Ingest script not found or not web-accessible. Please ensure /scripts/ingest.php is available.</div>';
    }
    ?>
</div>
<?php require_once __DIR__.'/../inc/footer.php'; ?>