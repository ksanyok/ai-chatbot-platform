<?php
$logFile = __DIR__ . '/../ask-gpt.log';
@file_put_contents($logFile, "[" . date('c') . "] BOOTING ask-gpt\n", FILE_APPEND);

set_error_handler(function($severity, $message, $file, $line) use ($logFile) {
    file_put_contents($logFile, "[" . date('c') . "] PHP Error: {$message} in {$file} on line {$line}\n", FILE_APPEND);
    return false;
});

// Robust autoload: prefer project-root vendor (handles public/ docroot or chroot)
$projectRootAutoload = dirname(__DIR__) . '/vendor/autoload.php';
try {
    if (file_exists($projectRootAutoload)) {
        @require_once $projectRootAutoload;
    } else {
        @require_once __DIR__ . '/../vendor/autoload.php';
    }
} catch (\Throwable $e) {
    file_put_contents($logFile, "[" . date('c') . "] Autoload error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "⚠️  Autoload error: " . $e->getMessage() . "\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';
// Load settings from DB
$apiKey = db()->query("SELECT value FROM api_keys WHERE name='openai_key'")->fetchColumn();
if (!$apiKey) {
    file_put_contents($logFile, "[".date('c')."] ERR: openai_key missing\n", FILE_APPEND);
    echo "❌ openai_key not configured in DB.\n";
    exit(1);
}
$dbModel = db()->query("SELECT value FROM api_keys WHERE name='embedding_model'")->fetchColumn();
$model = $dbModel ?: 'text-embedding-ada-002';

// Load chat model and top K from DB
$dbChat = db()->query("SELECT value FROM api_keys WHERE name='chat_model'")->fetchColumn();
$chatModel = $dbChat ?: 'gpt-4o-mini';
$dbTop = db()->query("SELECT value FROM api_keys WHERE name='top_k'")->fetchColumn();
$topResults = $dbTop ? intval($dbTop) : 3;


$botGreeting = db()->query("SELECT value FROM api_keys WHERE name='bot_greeting'")->fetchColumn();
$systemPrompt = db()->query("SELECT value FROM api_keys WHERE name='system_prompt'")->fetchColumn();
if (!$systemPrompt) {
    $systemPrompt = "You are an intelligent assistant. Respond in English by default, but if the user asks in another language, reply in that language. Answer concisely, clearly, and to the point, using only the provided context. Greet the user once at the start of the conversation and do not repeat the greeting.";
}
/**
 * Prevent the model from auto-greeting. We will inject greeting ourselves only when needed.
 * Works regardless of what is saved in DB (RU/EN). 
 */
$systemPrompt .= " IMPORTANT: Do NOT include any greeting in your reply; greetings are handled by the application.";



$openai = OpenAI::client($apiKey);

// Получаем вопрос
$question = isset($argv[1]) ? $argv[1] : null;
if (!$question) {
    echo "❌ Укажи вопрос как аргумент командной строки.\n";
    exit(1);
}
file_put_contents($logFile, "[" . date('c') . "] Вопрос: $question\n", FILE_APPEND);

// We'll populate $userId and $historyMessages after computing the query embedding
// We'll populate $userId and $historyMessages after computing the query embedding
$userId = isset($argv[2]) ? $argv[2] : null;
$shouldGreet = false;
$lastInteractionAt = null;
$historyMessages = [];

// Fallback contacts for contact-related queries
$staticContacts = "If context has no valid contacts or links, you may offer these: Email: NathanielRothschild.Org@gmail.com; Whatsapp: https://wa.me/447878425100; Website: https://linktr.ee/rothschildfamilybank";

// Only append fallback contacts when the question mentions contact-related terms
if (preg_match('/\b(contact|phone|email|номер|контакт)\b/i', $question)) {
    $systemPrompt .= " " . $staticContacts;
}

// Шаг 1: эмбеддинг вопроса
try {
    $embeddingResponse = $openai->embeddings()->create([
        'model' => $model,
        'input' => $question,
    ]);
} catch (\Throwable $e) {
    $err = "OpenAI embedding error: " . $e->getMessage();
    file_put_contents($logFile, "[" . date('c') . "] $err\n", FILE_APPEND);
    echo "⚠️  $err\n";
    exit(1);
}
$queryEmbedding = isset($embeddingResponse['data'][0]['embedding']) ? $embeddingResponse['data'][0]['embedding'] : null;
if (!$queryEmbedding) {
    file_put_contents($logFile, "[" . date('c') . "] Ошибка: embedding пустой\n", FILE_APPEND);
    exit(1);
}
file_put_contents($logFile, "[" . date('c') . "] queryEmbedding size: " . count($queryEmbedding) . "\n", FILE_APPEND);

// ===== Per-user embedding-backed history retrieval =====
if ($userId) {
    try {
        $pdo = db();
        // Determine last interaction time for greeting throttle (24h)
        $tstmt = $pdo->prepare("SELECT MAX(created_at) AS last_at FROM user_messages WHERE user_id = ?");
        $tstmt->execute([$userId]);
        $lastInteractionAt = $tstmt->fetchColumn();
        if ($lastInteractionAt) {
            $ts = strtotime($lastInteractionAt);
            if ($ts === false) {
                $shouldGreet = false;
            } else {
                $shouldGreet = (time() - $ts) >= 24*3600; // greet again only if idle >= 24h
            }
        } else {
            // no history at all → first message in conversation
            $shouldGreet = true;
        }
        // Таблица user_messages теперь создаётся централизованно в config/db.php (миграции).
        // Здесь просто работаем с ней; если её нет — ловим ошибку и продолжаем без персистентной истории.

        // Load configurable limit for retrieved messages (by similarity)
        $dbMax = $pdo->query("SELECT value FROM api_keys WHERE name='history_max_entries'")->fetchColumn();
        $maxEntries = $dbMax ? max(1, intval($dbMax)) : 200;
        $maxEntries = min($maxEntries, 2000);

        // Fetch candidate messages for this user that have embeddings
        $stmt = $pdo->prepare("SELECT role, content, embedding FROM user_messages WHERE user_id = ? AND embedding IS NOT NULL");
        $stmt->execute([$userId]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scores = [];
        foreach ($candidates as $c) {
            $emb = json_decode($c['embedding'], true);
            if (!is_array($emb) || count($emb) !== count($queryEmbedding)) continue;
            // cosine similarity
            $dot = 0.0; $na = 0.0; $nb = 0.0;
            foreach ($queryEmbedding as $i => $v) { $dot += $v * ($emb[$i] ?? 0); $na += $v*$v; $nb += ($emb[$i] ?? 0)*($emb[$i] ?? 0); }
            if ($na == 0 || $nb == 0) continue;
            $score = $dot / (sqrt($na) * sqrt($nb));
            $scores[] = ['score' => $score, 'role' => $c['role'], 'content' => $c['content']];
        }
        // sort by score desc
        usort($scores, function($a,$b){ return ($b['score'] <=> $a['score']); });
        $selected = array_slice($scores, 0, $maxEntries);
        // Build history messages in chronological-ish order by grouping assistant/user pairs as found
        foreach ($selected as $s) {
            if ($s['role'] === 'user') {
                $historyMessages[] = ['role' => 'user', 'content' => $s['content']];
            } else {
                $historyMessages[] = ['role' => 'assistant', 'content' => $s['content']];
            }
        }

        // If we are going to greet now, we won't also fetch a greeting-like assistant message from history by similarity (see instructions).

        file_put_contents($logFile, "[".date('c')."] Loaded " . count($historyMessages) . " contextual messages for user_id=" . $userId . "\n", FILE_APPEND);

    } catch (\Throwable $e) {
        file_put_contents($logFile, "[".date('c')."] user_messages retrieval error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Шаг 2: загрузка базы embedding’ов
$embeddingData = json_decode(file_get_contents(__DIR__ . '/../data/embeddings.json'), true);

// Расчёт cosine similarity
$cosineSimilarity = function(array $a, array $b) {
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
};

// Шаг 3: поиск релевантных чанков
$results = [];
foreach ($embeddingData as $item) {
    $score = $cosineSimilarity($queryEmbedding, $item['embedding']);
    $results[] = [
        'score' => $score,
        'text' => $item['text'],
        'url' => $item['url']
    ];
}
usort($results, function($a, $b) {
    if ($b['score'] == $a['score']) {
        return 0;
    }
    return ($b['score'] < $a['score']) ? -1 : 1;
});

$contextChunks = array_slice($results, 0, $topResults);

// Build allowed URLs list from retrieved chunks (whitelist for linking)
$allowedUrlSet = [];
foreach ($contextChunks as $chunk) {
    if (!empty($chunk['url'])) {
        $allowedUrlSet[$chunk['url']] = true;
    }
}
$allowedUrls = array_keys($allowedUrlSet);

file_put_contents($logFile, "[" . date('c') . "] Найдено " . count($contextChunks) . " релевантных чанков\n", FILE_APPEND);
file_put_contents($logFile, "[" . date('c') . "] contextChunks: " . count($contextChunks) . "\n", FILE_APPEND);

// Шаг 4: формируем промпт
$contextText = "";
foreach ($contextChunks as $chunk) {
    $contextText .= "- Источник: " . $chunk['url'] . "\n" . $chunk['text'] . "\n\n";
}

$userPrompt = "Context:\n" . $contextText . "\n\nQuestion: $question";

$previewPrompt = mb_substr($userPrompt, 0, 500);
file_put_contents($logFile, "[" . date('c') . "] userPrompt preview:\n" . $previewPrompt . "\n", FILE_APPEND);

// Шаг 5: отправка в GPT

$messages = [];
// Insert system prompt
$messages[] = ['role' => 'system', 'content' => $systemPrompt];

// Add strict linking policy so the model never invents URLs
if (!empty($allowedUrls)) {
    $allowedList = "- " . implode("\n- ", $allowedUrls);
    $linkPolicy = "LINKING POLICY (STRICT):\n"
        . "• When providing links, you MUST use only the URLs from the ALLOWED LINKS list below.\n"
        . "• Do NOT invent, guess, shorten, or modify URLs.\n"
        . "• If none of the allowed links match the user's request, explicitly say you do not have a link.\n"
        . "• When citing sources, output the links verbatim exactly as listed.\n"
        . "ALLOWED LINKS:\n"
        . $allowedList;
    $messages[] = ['role' => 'system', 'content' => $linkPolicy];
}

// Prepend history messages
if (!empty($historyMessages)) {
    $messages = array_merge($messages, $historyMessages);
}
// Finally add current user prompt
$messages[] = ['role' => 'user', 'content' => $userPrompt];

try {
    $response = $openai->chat()->create([
        'model' => $chatModel,
        'messages' => $messages,
    ]);
} catch (\Throwable $e) {
    $err = "OpenAI error: " . $e->getMessage();
    file_put_contents($logFile, "[" . date('c') . "] $err\n", FILE_APPEND);
    echo "⚠️  $err\n";
    exit(1);
}


$finalAnswer = isset($response['choices'][0]['message']['content']) ? $response['choices'][0]['message']['content'] : "⚠️ Ошибка: пустой ответ";

if ($userId && $shouldGreet && !empty($botGreeting)) {
    $finalAnswer = trim($botGreeting) . "\n\n" . ltrim($finalAnswer);
    file_put_contents($logFile, "[" . date('c') . "] Greeting injected (lastInteractionAt=" . ($lastInteractionAt ?: 'null') . ")\n", FILE_APPEND);
}
echo $finalAnswer;
file_put_contents($logFile, "[" . date('c') . "] Ответ:\n$finalAnswer\n", FILE_APPEND);

if ($userId) {
    try {
        // Save user's message embedding (we already have $queryEmbedding)
        $pdo = db();
        $ins = $pdo->prepare("INSERT INTO user_messages (user_id, role, content, embedding, created_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->execute([$userId, 'user', $question, json_encode($queryEmbedding, JSON_UNESCAPED_UNICODE)]);

        // Compute embedding for assistant response and save
        $assistantEmbedding = null;
        try {
            $ae = $openai->embeddings()->create(['model' => $model, 'input' => $finalAnswer]);
            $assistantEmbedding = $ae['data'][0]['embedding'] ?? null;
        } catch (\Throwable $e) {
            file_put_contents($logFile, "[".date('c')."] assistant embedding error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        if (is_array($assistantEmbedding)) {
            $ins->execute([$userId, 'assistant', $finalAnswer, json_encode($assistantEmbedding, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (\Throwable $e) {
        file_put_contents($logFile, "[".date('c')."] user_messages save error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}