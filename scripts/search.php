<?php
// Robust autoload: prefer project-root vendor (handles public/ docroot or chroot)
$projectRootAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($projectRootAutoload)) {
    @require_once $projectRootAutoload;
} else {
    @require_once __DIR__ . '/../vendor/autoload.php';
}

use OpenAI;

// Загрузка .env
require_once __DIR__ . '/../config/db.php';
// Load settings from DB
$logFile = __DIR__ . '/../search.log';
$apiKey = db()->query("SELECT value FROM api_keys WHERE name='openai_key'")->fetchColumn();
if (!$apiKey) {
    file_put_contents($logFile, "[".date('c')."] ERR: openai_key missing\n", FILE_APPEND);
    exit(1);
}
$dbModel = db()->query("SELECT value FROM api_keys WHERE name='embedding_model'")->fetchColumn();
$model = $dbModel ?: 'text-embedding-ada-002';

file_put_contents($logFile, "[".date('c')."] START SEARCH\n", FILE_APPEND);

$openai = OpenAI::client($apiKey);

// Получение запроса из аргумента
$query = $argv[1] ?? null;
if (!$query) {
    echo "❌ Укажи запрос как аргумент командной строки.\n";
    exit(1);
}
file_put_contents($logFile, "[".date('c')."] Query: $query\n", FILE_APPEND);

// Генерация эмбеддинга запроса
$response = $openai->embeddings()->create([
    'model' => $model,
    'input' => $query,
]);
$queryEmbedding = $response['data'][0]['embedding'] ?? null;

if (!$queryEmbedding) {
    file_put_contents($logFile, "[".date('c')."] ERR: embedding for query is null\n", FILE_APPEND);
    exit(1);
}

// Загрузка базы
$embeddingData = json_decode(file_get_contents(__DIR__ . '/../data/embeddings.json'), true);

// Расчёт cosine similarity
function cosineSimilarity(array $a, array $b): float {
    $dotProduct = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    foreach ($a as $i => $val) {
        $dotProduct += $val * $b[$i];
        $normA += $val * $val;
        $normB += $b[$i] * $b[$i];
    }
    if ($normA == 0 || $normB == 0) return 0.0;
    return $dotProduct / (sqrt($normA) * sqrt($normB));
}

// Сравниваем
$results = [];
foreach ($embeddingData as $item) {
    $score = cosineSimilarity($queryEmbedding, $item['embedding']);
    $results[] = [
        'score' => $score,
        'text' => $item['text'],
        'url' => $item['url']
    ];
}
usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

file_put_contents($logFile, "[".date('c')."] Top matches:\n", FILE_APPEND);

// Выводим топ 3 результатов
$top = array_slice($results, 0, 3);
foreach ($top as $i => $res) {
    echo "\n🔹 Match #" . ($i+1) . " (score: " . round($res['score'], 3) . ")\n";
    echo "URL: " . $res['url'] . "\n";
    echo "Text: " . mb_substr($res['text'], 0, 300) . "...\n";

    file_put_contents($logFile, "[".date('c')."] #" . ($i+1) . " - " . round($res['score'], 3) . " - " . $res['url'] . "\n", FILE_APPEND);
}

file_put_contents($logFile, "[".date('c')."] FINISH\n", FILE_APPEND);