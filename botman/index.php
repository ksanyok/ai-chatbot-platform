<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../vendor/autoload.php';

use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Facebook\FacebookDriver;
require_once __DIR__ . '/../config/db.php'; // подключение к БД

// Проверка прав доступа к директории логов
$logDir = __DIR__ . '/../';
$logFile = $logDir . 'bot.log';
if (!is_writable($logDir)) {
    die("Ошибка: директория $logDir недоступна для записи. Проверьте права доступа.");
}

// Ensure history table exists
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id),
    FOREIGN KEY (user_id) REFERENCES bot_users(id) ON DELETE CASCADE
)");
// Ensure extra columns & indexes exist for history
try { $pdo->exec("ALTER TABLE history ADD COLUMN channel VARCHAR(32) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("CREATE INDEX idx_history_created ON history(created_at)"); } catch (Throwable $e) {}
try { $pdo->exec("CREATE INDEX idx_history_channel_time ON history(channel, created_at)"); } catch (Throwable $e) {}

// Load Telegram bot token from DB
$telegramToken = $pdo->query("SELECT value FROM api_keys WHERE name='telegram_bot_token'")->fetchColumn();
if (!$telegramToken) {
    file_put_contents($logFile, "[".date('c')."] ERR: telegram_bot_token missing in DB\n", FILE_APPEND);
    exit("Telegram bot token not configured.");
}

// Load Facebook tokens from DB
$facebookToken = $pdo->query("SELECT value FROM api_keys WHERE name='facebook_page_token'")->fetchColumn();
$facebookAppSecret = $pdo->query("SELECT value FROM api_keys WHERE name='facebook_app_secret'")->fetchColumn();
$facebookVerificationToken = $pdo->query("SELECT value FROM api_keys WHERE name='facebook_verification_token'")->fetchColumn();

// Load Instagram tokens from DB
$instagramToken            = $pdo->query("SELECT value FROM api_keys WHERE name='instagram_token'")->fetchColumn();
$instagramAppSecret        = $pdo->query("SELECT value FROM api_keys WHERE name='instagram_app_secret'")->fetchColumn();
$instagramVerificationToken = $pdo->query("SELECT value FROM api_keys WHERE name='instagram_verification_token'")->fetchColumn();

// Load WhatsApp tokens from DB
$whatsappToken   = $pdo->query("SELECT value FROM api_keys WHERE name='whatsapp_access_token'")->fetchColumn();
$whatsappPhoneId = $pdo->query("SELECT value FROM api_keys WHERE name='whatsapp_phone_number_id'")->fetchColumn();

// Load greeting text from DB
$greeting = $pdo->query("SELECT value FROM api_keys WHERE name='bot_greeting'")->fetchColumn();

// Load reaction_enabled setting from DB
$reactionEnabled = $pdo->query("SELECT value FROM api_keys WHERE name='reaction_enabled'")->fetchColumn();
$facebookReactionEnabled = $pdo->query("SELECT value FROM api_keys WHERE name='facebook_reaction_enabled'")->fetchColumn();

// Load comment trigger settings
$commentTriggerEnabled = $pdo->query("SELECT value FROM api_keys WHERE name='comment_trigger_enabled'")->fetchColumn();
$commentTriggerMessage = $pdo->query("SELECT value FROM api_keys WHERE name='comment_trigger_message'")->fetchColumn();

set_error_handler(function($severity, $message, $file, $line) use ($logFile) {
    file_put_contents($logFile, "[".date('c')."] PHP Error: {$message} in {$file} on line {$line}\n", FILE_APPEND);
    return false; // передать стандартному обработчику
});

// Загрузка драйверов
DriverManager::loadDriver(\BotMan\Drivers\Telegram\TelegramDriver::class);
if ($whatsappToken) {
    try {
        DriverManager::loadDriver(\BotMan\Drivers\WhatsApp\WhatsAppDriver::class);
    } catch (\Throwable $e) {
        // Либо драйвера нет, либо не установлен — логируем предупреждение, но не ломаем остальное
        file_put_contents($logFile, "[".date('c')."] WARNING: WhatsApp driver load failed: ".$e->getMessage()."\n", FILE_APPEND);
    }
}
if ($instagramToken && $instagramAppSecret && $instagramVerificationToken) {
    try {
        DriverManager::loadDriver(\BotMan\Drivers\Instagram\InstagramDriver::class);
    } catch (\Throwable $e) {
        file_put_contents($logFile, "[".date('c')."] WARNING: Instagram driver load failed: ".$e->getMessage()."\n", FILE_APPEND);
    }
}
if ($facebookToken && $facebookAppSecret && $facebookVerificationToken) {
    DriverManager::loadDriver(\BotMan\Drivers\Facebook\FacebookDriver::class);
}

// Настройки
$config = [
    'telegram' => [
        'token' => $telegramToken
    ]
];
if ($whatsappToken) {
    $config['whatsapp'] = [
        'token'           => $whatsappToken,
        // некоторые драйверы требуют phone_number_id
        'phone_number_id' => $whatsappPhoneId,
    ];
}
if ($facebookToken && $facebookAppSecret && $facebookVerificationToken) {
    $config['facebook'] = [
        'token' => $facebookToken,
        'app_secret' => $facebookAppSecret,
        'verification' => $facebookVerificationToken,
    ];
}
if ($instagramToken && $instagramAppSecret && $instagramVerificationToken) {
    $config['instagram'] = [
        'token'        => $instagramToken,
        'app_secret'   => $instagramAppSecret,
        'verification' => $instagramVerificationToken,
    ];
}

try {
    $botman = BotManFactory::create($config);

    // Обработка входящего текста и автоответ на комментарии Facebook
    $botman->hears('.*', function ($bot) use ($pdo, $telegramToken, $reactionEnabled, $facebookReactionEnabled, $facebookToken, $logFile, $commentTriggerEnabled, $commentTriggerMessage) {
        $payload = $bot->getMessage()->getPayload();
        $senderId = $bot->getMessage()->getSender();
        $driverName = $bot->getDriver()->getName();
        $channelMap = [
            'Telegram' => 'telegram',
            'Facebook' => 'facebook',
            'Instagram' => 'instagram',
            'WhatsApp' => 'whatsapp',
        ];
        $channelName = $channelMap[$driverName] ?? 'web';
        file_put_contents($logFile, "[" . date('c') . "] Driver: $driverName\n", FILE_APPEND);

        // Reload greeting from DB on each message so admin changes apply immediately
        try {
            $greeting = $pdo->query("SELECT value FROM api_keys WHERE name='bot_greeting'")->fetchColumn();
        } catch (Throwable $e) {
            $greeting = null;
        }

        if ($driverName === 'WhatsApp') {
            file_put_contents($logFile, "[".date('c')."] WhatsApp raw payload: ".json_encode($payload, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }
        if ($driverName === 'Instagram') {
            file_put_contents($logFile, "[".date('c')."] Instagram raw payload: ".json_encode($payload, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }

        // --- Facebook Comment Trigger ---
        // 1. Log entire Facebook payload before comment trigger
        file_put_contents($logFile, "[".date('c')."] Payload dump: ".json_encode($payload, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);

        // Extended logging for comment event raw value
        if (isset($payload['entry'][0]['changes'][0]['value'])) {
            file_put_contents($logFile, "[".date('c')."] Comment event raw: ".json_encode($payload['entry'][0]['changes'][0]['value'], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }

        // Detect comment event on Facebook
        if (
            $driverName === 'Facebook'
            && $commentTriggerEnabled == '1'
            && isset($payload['entry'][0]['changes'][0]['field'])
            && $payload['entry'][0]['changes'][0]['field'] === 'feed'
            && isset($payload['entry'][0]['changes'][0]['value']['comment_id'])
        ) {
            // 2. Extended comment trigger logging
            file_put_contents($logFile, "[".date('c')."] Checking comment trigger: field=".($payload['entry'][0]['changes'][0]['field'] ?? 'n/a')." comment_id=".($payload['entry'][0]['changes'][0]['value']['comment_id'] ?? 'n/a')."\n", FILE_APPEND);
            $commenterId = $payload['entry'][0]['changes'][0]['value']['from']['id'] ?? null;
            if ($commenterId) {
                // Auto-send emoji acknowledgment
                $bot->say('😊', $commenterId, FacebookDriver::class);
                file_put_contents($logFile, "[".date('c')."] Sent emoji acknowledgment to commenterId=".$commenterId."\n", FILE_APPEND);

                $bot->say($commentTriggerMessage, $commenterId, FacebookDriver::class);
                file_put_contents($logFile, "[".date('c')."] Sent private message to commenterId=".$commenterId." message=\"".$commentTriggerMessage."\"\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "[".date('c')."] Comment trigger enabled but no commenterId found\n", FILE_APPEND);
            }
            return;
        }
        // --- End Facebook Comment Trigger ---

        // Записываем пользователя в таблицу bot_users
        try {
            $extras   = $bot->getMessage()->getExtras();
            $stmt = $pdo->prepare("INSERT IGNORE INTO bot_users (id, first_name, last_name, username) VALUES (?,?,?,?)");
            $stmt->execute([
                $senderId,
                $extras['from']['first_name'] ?? null,
                $extras['from']['last_name']  ?? null,
                $extras['from']['username']   ?? null
            ]);
        } catch (\Throwable $e) {
            // тихо игнорируем, чтобы не ломать бота
        }

        $question = $bot->getMessage()->getText();
        if ($question === null || trim($question) === '') {
            return;
        }
        if ($question === '/start') {
            $startGreeting = $greeting ?: 'Привет! Я готов отвечать на вопросы 🤖';
            $bot->reply($startGreeting);
            // Записываем факт приветствия, чтобы не повторяться
            try {
                $stmt = $pdo->prepare("INSERT INTO history (user_id, question, answer, channel, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$senderId, '/start', $startGreeting, $channelName]);
            } catch (\Throwable $e) {
                file_put_contents($logFile, "[".date('c')."] history insert error: ".$e->getMessage()."\n", FILE_APPEND);
            }
            return;
        }

        // Check user history count
        $historyCount = $pdo->prepare("SELECT COUNT(*) FROM history WHERE user_id = ?");
        $historyCount->execute([$senderId]);
        $count = $historyCount->fetchColumn();

        if ($count == 0 && $greeting && trim($greeting) !== '') {
            $bot->reply($greeting);
            // продолжаем генерировать ответ на первый вопрос
            // Record that the bot greeted the user so the LLM won't repeat the greeting
            try {
                $stmt = $pdo->prepare("INSERT INTO history (user_id, question, answer, channel, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$senderId, '/greeting', $greeting, $channelName]);
            } catch (\Throwable $e) {
                file_put_contents($logFile, "[".date('c')."] history insert error (greeting): ".$e->getMessage()."\n", FILE_APPEND);
            }
        }

        if ($reactionEnabled == '1' && $driverName === 'Telegram') {
            try {
                $chatId = $payload['chat']['id'] ?? null;
                $messageId = $payload['message_id'] ?? null;
                if ($chatId && $messageId) {
                    $ch = curl_init("https://api.telegram.org/bot{$telegramToken}/setMessageReaction");
                    $postFields = [
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                        'reaction' => json_encode([['type'=>'emoji','emoji'=>'👍']])
                    ];
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $postFields,
                        CURLOPT_TIMEOUT => 5,
                    ]);
                    $resp = curl_exec($ch);
                    $curlErr = curl_error($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    file_put_contents($logFile, "[".date('c')."] Telegram reaction request responded HTTP {$httpCode} body={$resp} err={$curlErr}\n", FILE_APPEND);
                    if ($httpCode < 200 || $httpCode >= 300) {
                        file_put_contents($logFile, "[".date('c')."] WARNING: Telegram reaction failed with HTTP {$httpCode}\n", FILE_APPEND);
                    }
                }
            } catch (\Throwable $e) {
                file_put_contents($logFile, "[".date('c')."] reaction error: ".$e->getMessage()."\n", FILE_APPEND);
            }
        }

        if ($facebookReactionEnabled == '1' && $driverName === 'Facebook') {
            try {
                $fbSenderId = $payload['sender']['id'] ?? null;
                $pageId = $payload['recipient']['id'] ?? null;
                if ($fbSenderId && $pageId) {
                    $url = "https://graph.facebook.com/v23.0/" . urlencode($pageId) . "/message_reactions";
                    $postParams = [
                        'user_id' => $fbSenderId,
                        'reaction_type' => 'LOVE',
                        'access_token' => $facebookToken
                    ];
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query($postParams),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ]);
                    $resp = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlErr = curl_error($ch);
                    curl_close($ch);
                    file_put_contents($logFile, "[".date('c')."] FB reaction request to {$url} with user_id={$fbSenderId} responded HTTP {$httpCode} body={$resp} err={$curlErr}\n", FILE_APPEND);
                    if ($httpCode < 200 || $httpCode >= 300) {
                        file_put_contents($logFile, "[".date('c')."] WARNING: FB reaction failed with HTTP {$httpCode}\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents($logFile, "[".date('c')."] FB reaction skipped: missing senderId or pageId\n", FILE_APPEND);
                }
            } catch (\Throwable $e) {
                file_put_contents($logFile, "[".date('c')."] facebook reaction error: ".$e->getMessage()."\n", FILE_APPEND);
            }
        }

        file_put_contents($logFile, "[" . date('c') . "] Входящее: $question\n", FILE_APPEND);

        function detectPhpBinary(): string {
            // Список возможных версий PHP, от новой к старым
            $phpVersions = ['8.3', '8.2', '8.1', '8.0', '7.4'];
            
            // Возможные директории, где может находиться PHP
            $directories = [
                '/usr/local/php%s/bin/php',
                '/opt/alt/php%s/usr/bin/php',
                '/usr/bin/php%s',
                '/usr/local/bin/php%s',
                '/bin/php%s',
            ];
        
            // Перебираем все комбинации путей и версий
            foreach ($phpVersions as $version) {
                foreach ($directories as $template) {
                    $path = sprintf($template, str_replace('.', '', $version));
                    if (is_executable($path)) {
                        return $path;
                    }
                }
        
                // Иногда путь содержит точку: php8.2 вместо php82
                foreach ($directories as $template) {
                    $path = sprintf($template, $version);
                    if (is_executable($path)) {
                        return $path;
                    }
                }
            }
        
            // Если ничего не найдено — пробуем текущий бинарник
            if (defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
                return PHP_BINARY;
            }
        
            // В крайнем случае — просто php (должен быть в PATH)
            return 'php';
        }
        
        $phpBin = detectPhpBinary();

        
        // Вызываем ask-gpt.php и логируем вывод и код возврата
        $escaped = escapeshellarg($question);
        $scriptPath = __DIR__ . "/../scripts/ask-gpt.php";
        // Include senderId to maintain context
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . " $escaped " . escapeshellarg($senderId) . " 2>&1";
        file_put_contents($logFile, "[" . date('c') . "] exec cmd: $cmd\n", FILE_APPEND);
        exec($cmd, $outputLines, $exitCode);
        $responseText = implode("\n", $outputLines);
        if (trim($responseText) === '') {
            file_put_contents($logFile, "[" . date('c') . "] WARNING: ask-gpt produced empty output\n", FILE_APPEND);
        }
        file_put_contents($logFile, "[" . date('c') . "] ask-gpt exitCode: $exitCode; output:\n" . $responseText . "\n", FILE_APPEND);

        // Эмуляция набора сообщения
        $bot->typesAndWaits(rand(1,3));

        // Отправка ответа (или сообщение об ошибке)
        $bot->reply($responseText ? $responseText : '🤖 Ошибка при генерации ответа. Проверь логи.');

        // Save question and answer to history
        try {
            $stmt = $pdo->prepare("INSERT INTO history (user_id, question, answer, channel, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$senderId, $question, $responseText, $channelName]);
        } catch (\Throwable $e) {
            file_put_contents($logFile, "[".date('c')."] history insert error: ".$e->getMessage()."\n", FILE_APPEND);
        }
    });

    $botman->listen();
} catch (\Throwable $e) {
    file_put_contents($logFile, "[".date('c')."] Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Error in bot: " . $e->getMessage();
}