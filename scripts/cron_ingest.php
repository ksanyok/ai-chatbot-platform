<?php
require_once __DIR__ . '/ingest.php';
ob_start();

// Запуск всех текущих тренировок
$pdo = db();
$ids = $pdo->query("SELECT id FROM trainings WHERE status='running'")->fetchAll(PDO::FETCH_COLUMN);

logMsg("Cron script: found " . count($ids) . " running trainings");
foreach ($ids as $tid) {
    logMsg("Cron script: running training #$tid");
    runTraining($tid);
}

echo "Cron script executed: " . count($ids) . " trainings processed.";