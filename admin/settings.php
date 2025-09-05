<?php
require_once __DIR__.'/../inc/auth.php';
require_login();
require_once __DIR__.'/../inc/header.php';

// Единый Webhook для всех платформ (Telegram/Messenger/Instagram/WhatsApp)
function buildWebhookUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'https://' . $host . '/botman/index.php';
}

function resolveHostIp(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return '';
    $ips = @gethostbynamel($host);
    if (!$ips) return t('dns.noip');
    return implode(', ', $ips);
}

function testWebhookReachability(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [
        'http_code' => $http,
        'error' => $err,
        'final_url' => $finalUrl,
    ];
}
function telegramRequest(string $token, string $method, array $params = []): array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $json = json_decode($raw, true);
    return [$json, $curlErr, $raw];
}

// === Facebook helpers ===
function buildFbRedirectUri(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/botman/settings.php', '?');
    return 'https://' . $host . $path . '?fb_oauth=1';
}

function buildFbWebhookUrl(): string {
    // используем единый endpoint без query-параметров
    return buildWebhookUrl();
}

function fb_http_post_form(string $url, array $params): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp, $err];
}

// Отправка простого текстового сообщения в Facebook Messenger (для тестов)
function fb_send_text(string $pageAccessToken, string $psid, string $text): array {
    $url = 'https://graph.facebook.com/v23.0/me/messages?access_token=' . urlencode($pageAccessToken);
    $payload = json_encode([
        'messaging_type' => 'RESPONSE',
        'recipient' => ['id' => $psid],
        'message' => ['text' => $text],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp, $err];
}

// Обработка POST
$dbh = db();

// Устанавливаем значения по умолчанию
$defaults = [
    'bot_greeting' => 'Привет! Я готов отвечать на вопросы 🤖',
    'system_prompt' => 'Ты интеллектуальный помощник. Отвечай на том языке, на котором задаёт вопрос пользователь. Отвечай кратко, понятно и по делу, используя знания только из приведённого контекста. В начале диалога один раз поприветствуй пользователя, далее не повторяй приветствие.',
    'reaction_enabled' => '0',
    'facebook_reaction_enabled' => '0',
    'facebook_app_id' => '',
    'comment_trigger_enabled' => '0',
    'comment_trigger_message' => 'Hey there! I’m so happy you’re here, thanks so much for your interest in joining the rothschild community 😊 Click below to know more about the family ✨ https://linktr.ee/rothschildfamilybank',
    // Ensure editable OpenAI rows exist by default (compat: openai_key and openai_api_key)
    'openai_key' => '',
    'openai_api_key' => '',
];
foreach ($defaults as $key => $val) {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM api_keys WHERE name = ?");
    $stmt->execute([$key]);
    if ((int)$stmt->fetchColumn() === 0) {
        $ins = $dbh->prepare("INSERT INTO api_keys (name, value) VALUES (?, ?)");
        $ins->execute([$key, $val]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ([
        'openai_key', 'telegram_bot_token', 'embedding_model', 'bot_greeting', 'system_prompt', 'reaction_enabled',
        'facebook_reaction_enabled', 'facebook_app_id', 'facebook_app_secret', 'facebook_verification_token',
        'comment_trigger_enabled', 'comment_trigger_message',
        'whatsapp_access_token', 'whatsapp_phone_number_id', 'whatsapp_business_account_id'
    ] as $key) {
        // Only process keys that were posted (preserve missing inputs) and trim whitespace
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $value = trim((string)($_POST[$key] ?? ''));
        // Save the posted value (including empty string when intentionally cleared)
        $stmt = $dbh->prepare("INSERT INTO api_keys (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $stmt->execute([$key, $value]);

        // Compatibility: mirror openai_key into openai_api_key so older code finds it (but don't erase existing non-empty alternative)
        if ($key === 'openai_key') {
            $cur2 = $dbh->prepare("SELECT value FROM api_keys WHERE name = ?");
            $cur2->execute(['openai_api_key']);
            $existing2 = $cur2->fetchColumn();
            if (!($existing2 !== false && $existing2 !== null && $existing2 !== '' && $value === '')) {
                $stmt2 = $dbh->prepare("INSERT INTO api_keys (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
                $stmt2->execute(['openai_api_key', $value]);
            }
        }
    }
    echo '<div class="mb-4 text-green-400">'.htmlspecialchars(t('saved')).' ✅</div>';
    if (isset($_POST['send_whatsapp_test'])) {
        $waToken   = trim($_POST['whatsapp_access_token'] ?? ($rows['whatsapp_access_token'] ?? ''));
        $waPhoneId = trim($_POST['whatsapp_phone_number_id'] ?? ($rows['whatsapp_phone_number_id'] ?? ''));
        $to        = trim($_POST['whatsapp_test_to'] ?? '');
        if ($waToken && $waPhoneId && $to) {
            [$code,$resp,$err] = whatsapp_send_text($waPhoneId, $waToken, $to, 'Тестовое сообщение ✅ от вашего бота');
            if ($code >= 200 && $code < 300) {
                echo '<div class="mb-4 text-green-400">'.htmlspecialchars(t('msg.whatsapp_sent')).'</div>';
            } else {
                $json = json_decode($resp, true);
                if (isset($json['error']['code']) && $json['error']['code'] === 190 && isset($json['error']['error_subcode']) && $json['error']['error_subcode'] === 463) {
                    echo '<div class="mb-4 text-red-400">'.htmlspecialchars(t('msg.whatsapp_token_expired')).'</div>';
                } else {
                    echo '<div class="mb-4 text-red-400">'.htmlspecialchars(t('msg.whatsapp_error')).': HTTP ' . (int)$code . ' ' . htmlspecialchars($resp) . '</div>';
                }
            }
        } else {
            echo '<div class="mb-4 text-yellow-400">'.htmlspecialchars(t('msg.whatsapp_fill')).'</div>';
        }
    }
    // --- Telegram тестовое сообщение ---
    if (isset($_POST['send_telegram_test'])) {
        $token = trim($_POST['telegram_bot_token'] ?? '');
        $chat  = trim($_POST['telegram_test_chat_id'] ?? '');
        if ($token && $chat) {
            [$resp, $err, $raw] = telegramRequest($token, 'sendMessage', ['chat_id' => $chat, 'text' => 'Тестовое сообщение ✅ от вашего бота']);
            if (!empty($resp['ok'])) {
                echo '<div class="mb-4 text-green-400">'.htmlspecialchars(t('msg.telegram_sent')).'</div>';
            } else {
                $msg = htmlspecialchars($resp['description'] ?? $err ?? $raw ?? 'unknown');
                echo '<div class="mb-4 text-red-400">'.htmlspecialchars(t('msg.telegram_error')).': ' . $msg . '</div>';
            }
        } else {
            echo '<div class="mb-4 text-yellow-400">'.htmlspecialchars(t('msg.telegram_fill')).'</div>';
        }
    }
    // --- Facebook Messenger тестовое сообщение ---
    if (isset($_POST['send_facebook_test'])) {
        $pageToken = trim($_POST['facebook_page_token'] ?? ($rows['facebook_page_token'] ?? ''));
        $psid      = trim($_POST['facebook_test_psid'] ?? '');
        if ($pageToken && $psid) {
            [$code,$resp,$err] = fb_send_text($pageToken, $psid, 'Тестовое сообщение ✅ от вашего бота');
            if ($code >= 200 && $code < 300) {
                echo '<div class="mb-4 text-green-400">'.htmlspecialchars(t('msg.fb_sent')).'</div>';
            } else {
                echo '<div class="mb-4 text-red-400">'.htmlspecialchars(t('msg.fb_error')).': HTTP ' . (int)$code . ' ' . htmlspecialchars($err) . ' ' . htmlspecialchars((string)$resp) . '</div>';
            }
        } else {
            echo '<div class="mb-4 text-yellow-400">'.htmlspecialchars(t('msg.fb_fill')).'</div>';
        }
    }
    if (isset($_POST['set_webhook']) && !empty($_POST['telegram_bot_token'])) {
        $token = trim($_POST['telegram_bot_token']);
        $webhookUrl = !empty($_POST['custom_webhook_url']) ? trim($_POST['custom_webhook_url']) : buildWebhookUrl();
        $hostIp = resolveHostIp($webhookUrl);
        $reach = testWebhookReachability($webhookUrl);
        [$resp, $curlErr] = telegramRequest($token, 'setWebhook', ['url' => $webhookUrl, 'drop_pending_updates' => true]);
        if (!empty($resp['ok'])) {
            echo '<div class="mb-4 text-green-400">'.htmlspecialchars(t('msg.webhook_set_ok')).'</div>';
        } else {
            $errText = htmlspecialchars($resp['description'] ?? $curlErr ?? 'Неизвестная ошибка');
            echo '<div class="mb-4 text-red-400">'.htmlspecialchars(t('msg.webhook_set_error')).': '.$errText.'<br><span class="text-sm">URL: '.htmlspecialchars($webhookUrl).'<br>DNS IP(ы): '.htmlspecialchars($hostIp).'<br>HTTP проверка: код='.$reach['http_code'].' '.htmlspecialchars($reach['error']).'</span></div>';
        }
    }
    if (isset($_POST['delete_webhook']) && !empty($_POST['telegram_bot_token'])) {
        $token = trim($_POST['telegram_bot_token']);
        [$resp, $curlErr] = telegramRequest($token, 'deleteWebhook');
        if (!empty($resp['ok'])) {
            echo '<div class="mb-4 text-green-400">'.htmlspecialchars(t('msg.webhook_deleted')).'</div>';
        } else {
            $errText = htmlspecialchars($resp['description'] ?? $curlErr ?? 'Неизвестная ошибка');
            echo '<div class="mb-4 text-red-400">'.htmlspecialchars(t('msg.webhook_delete_error')).'</div>';
        }
    }
}

// === Handle Facebook OAuth callback ===
if (isset($_GET['fb_oauth']) && $_GET['fb_oauth'] === '1' && isset($_GET['code'])) {
    $rowsTmp = $dbh->query("SELECT name,value FROM api_keys")->fetchAll(PDO::FETCH_KEY_PAIR);
    $appId     = trim($rowsTmp['facebook_app_id'] ?? '');
    $appSecret = trim($rowsTmp['facebook_app_secret'] ?? '');
    $redirect  = buildFbRedirectUri();

    if ($appId && $appSecret) {
        $code = $_GET['code'];
        $tokenUrl = 'https://graph.facebook.com/v23.0/oauth/access_token?client_id=' . urlencode($appId)
            . '&redirect_uri=' . urlencode($redirect)
            . '&client_secret=' . urlencode($appSecret)
            . '&code=' . urlencode($code);
        $resp = @file_get_contents($tokenUrl);
        $user = json_decode((string)$resp, true);
        if (!isset($user['access_token'])) {
            echo '<script>window.opener && window.opener.postMessage({platform:"facebook",status:"error",reason:"oauth"}, "*"); window.close();</script>Error fetching user token';
            exit;
        }
        $userToken = $user['access_token'];

        $pagesUrl = 'https://graph.facebook.com/v23.0/me/accounts?fields=id,name,access_token,link,tasks&access_token=' . urlencode($userToken);
        $pagesResp = @file_get_contents($pagesUrl);
        $pagesData = json_decode((string)$pagesResp, true);
        $pages = $pagesData['data'] ?? [];

        $ins = $dbh->prepare("INSERT INTO api_keys (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $ins->execute(['facebook_pages_json', json_encode($pages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

        $tokensMap = [];
        $connectedPages = [];
        $failedPages = [];

        foreach ($pages as $p) {
            $pid = $p['id'] ?? null;
            $pname = $p['name'] ?? '';
            $ptoken = $p['access_token'] ?? null;
            $plink = !empty($p['link']) ? $p['link'] : ($pid ? ('https://facebook.com/' . $pid) : '');
            if (!$pid || !$ptoken) {
                if ($pid) {
                    $failedPages[] = ['id' => $pid, 'name' => $pname, 'reason' => 'no_page_access_token'];
                }
                continue;
            }

            $tokensMap[$pid] = $ptoken;

            $subUrl = 'https://graph.facebook.com/v23.0/' . rawurlencode($pid) . '/subscribed_apps';
            [$code, $respBody, $respErr] = fb_http_post_form($subUrl, [
                'subscribed_fields' => 'messages,messaging_postbacks,message_reactions,message_reads',
                'access_token' => $ptoken,
            ]);
            if ($code >= 200 && $code < 300) {
                $connectedPages[] = ['id' => $pid, 'name' => $pname, 'link' => $plink];
            } else {
                @error_log('[FB OAuth] subscribe failed page_id=' . $pid . ' code=' . $code . ' err=' . $respErr . ' resp=' . $respBody);
                $failedPages[] = [
                    'id' => $pid,
                    'name' => $pname,
                    'reason' => 'subscribe_failed',
                    'http_code' => $code,
                    'details' => substr((string)$respBody, 0, 180)
                ];
            }
        }

        $ins->execute(['facebook_page_tokens', json_encode($tokensMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $ins->execute(['facebook_connected_pages_json', json_encode($connectedPages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $ins->execute(['facebook_failed_pages_json', json_encode($failedPages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

        if (!empty($connectedPages)) {
            $firstId = $connectedPages[0]['id'];
            $ins->execute(['facebook_page_id', $firstId]);
            $ins->execute(['facebook_page_token', $tokensMap[$firstId] ?? '']);
        }

        @file_put_contents(__DIR__ . '/../botman/facebook_pages.json', json_encode($pages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        @file_put_contents(__DIR__ . '/../botman/facebook_page_tokens.json', json_encode($tokensMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

        $pageIdsJson = json_encode(array_column($connectedPages, 'id'));
        echo '<script>window.opener && window.opener.postMessage({platform:"facebook",status:"connected",page_ids:' . $pageIdsJson . '}, "*"); window.close();</script>Успешно подключены страницы. Можно закрыть окно.';
        exit;
    } else {
        echo '<script>window.opener && window.opener.postMessage({platform:"facebook",status:"error",reason:"config"}, "*"); window.close();</script>Missing App ID or Secret';
        exit;
    }
}

$rows = $dbh->query("SELECT name,value FROM api_keys")->fetchAll(PDO::FETCH_KEY_PAIR);
// Эфемерный Facebook Verification Token: генерируем на лету, если в БД нет
$ephemeralFbVerify = $rows['facebook_verification_token'] ?? '';
if ($ephemeralFbVerify === '' || $ephemeralFbVerify === null) {
    $ephemeralFbVerify = bin2hex(random_bytes(16));
}
function whatsapp_send_text(string $phoneNumberId, string $accessToken, string $to, string $text): array {
    $url = 'https://graph.facebook.com/v23.0/' . rawurlencode($phoneNumberId) . '/messages';
    $ch = curl_init($url);
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => $text]
    ], JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp, $err];
}
$fbRedirectUri = buildFbRedirectUri();
$fbWebhookUrl  = buildFbWebhookUrl();
$fbConnectedPages = [];
if (!empty($rows['facebook_connected_pages_json'])) {
    $tmp = json_decode($rows['facebook_connected_pages_json'], true);
    if (is_array($tmp)) { $fbConnectedPages = $tmp; }
}
$fbConnectedCount = is_array($fbConnectedPages) ? count($fbConnectedPages) : 0;
$fbFailedPages = [];
if (!empty($rows['facebook_failed_pages_json'])) {
    $tmp2 = json_decode($rows['facebook_failed_pages_json'], true);
    if (is_array($tmp2)) { $fbFailedPages = $tmp2; }
}

$webhookUrl = buildWebhookUrl();
// Получение статуса webhook для Telegram
$webhookStatus = null;
$lastError = null;
$data = [];
$manualWebhookUrl = '';
if (!empty($rows['telegram_bot_token'])) {
    $token = $rows['telegram_bot_token'];
    $webhookUrl = buildWebhookUrl();
    $manualWebhookUrl = '';
    if (!empty($_POST['custom_webhook_url'])) {
        $manualWebhookUrl = trim($_POST['custom_webhook_url']);
    }
    [$data, $curlErr] = telegramRequest($token, 'getWebhookInfo', []);
    if (!empty($data['ok']) && !empty($data['result']['url'])) {
        if ($data['result']['url'] === $webhookUrl) {
            $webhookStatus = 'ok';
        } else {
            $webhookStatus = 'mismatch';
        }
        $lastError = $data['result']['last_error_message'] ?? null;
    } else {
        $webhookStatus = 'error';
        $lastError = $data['description'] ?? $curlErr ?? null;
    }
}
$resolvedIps = resolveHostIp($webhookUrl);
$reachInfo = testWebhookReachability($webhookUrl);
?>
<div class="rounded-2xl border border-white/10 bg-gradient-to-br from-indigo-900/50 via-indigo-800/30 to-fuchsia-900/30 p-6 mb-6">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-indigo-200 flex items-center gap-2"><i class="fas fa-cog"></i> <?= htmlspecialchars(t('nav.settings')) ?></h1>
      <p class="text-sm text-gray-300/80 mt-1"><?= htmlspecialchars(t('settings.desc')) ?></p>
    </div>
    <div class="hidden md:flex items-center justify-center w-14 h-14 rounded-xl bg-white/5 border border-white/10">
      <i class="fas fa-sliders-h text-2xl text-indigo-300"></i>
    </div>
  </div>
</div>

<div class="lg:grid lg:grid-cols-12 gap-6 items-start">
  <!-- Sidebar -->
  <aside class="lg:col-span-3 mb-6 lg:mb-0">
    <nav class="sticky top-4 space-y-2">
      <button type="button" data-sec="common" class="sec-btn w-full flex items-center gap-2 px-4 py-2 rounded-lg ring-1 ring-inset ring-white/10 text-gray-300 hover:bg-white/5 transition">
        <i class="fas fa-sliders-h"></i> <?= htmlspecialchars(t('tabs.common')) ?>
      </button>
      <button type="button" data-sec="telegram" class="sec-btn w-full flex items-center gap-2 px-4 py-2 rounded-lg ring-1 ring-inset ring-white/10 text-gray-300 hover:bg-white/5 transition">
        <i class="fab fa-telegram-plane"></i> <?= htmlspecialchars(t('tabs.telegram')) ?>
      </button>
      <button type="button" data-sec="facebook" class="sec-btn w-full flex items-center gap-2 px-4 py-2 rounded-lg ring-1 ring-inset ring-white/10 text-gray-300 hover:bg-white/5 transition">
        <i class="fab fa-facebook-messenger"></i> <?= htmlspecialchars(t('tabs.facebook')) ?> / WhatsApp
      </button>
      <button type="button" data-sec="help" class="sec-btn w-full flex items-center gap-2 px-4 py-2 rounded-lg ring-1 ring-inset ring-white/10 text-gray-300 hover:bg-white/5 transition">
        <i class="fas fa-book-open"></i> <?= htmlspecialchars(t('tabs.help')) ?>
      </button>
    </nav>
  </aside>

  <!-- Main -->
  <main id="settings-main" class="lg:col-span-9 pt-0" style="padding-top:0">
    <form method="post" class="space-y-6">

      <!-- Common -->
      <section id="sec-common" class="sec-card rounded-xl bg-white/5 border border-white/10 p-6">
        <h3 class="text-xl font-semibold mb-4 text-indigo-300 flex items-center gap-2"><i class="fas fa-sliders-h"></i> <?= htmlspecialchars(t('general.title')) ?></h3>
        <div class="grid md:grid-cols-2 gap-6">
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('embedding.model')) ?></label>
            <select name="embedding_model" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition">
              <?php
              $models = [
                'text-embedding-3-small' => 'text-embedding-3-small — $0.02/1M',
                'text-embedding-3-large' => 'text-embedding-3-large — $0.13/1M',
                'text-embedding-ada-002' => 'text-embedding-ada-002 — $0.10/1M',
              ];
              $current = $rows['embedding_model'] ?? 'text-embedding-ada-002';
              foreach ($models as $key => $label) {
                $sel = $current === $key ? 'selected' : '';
                echo "<option value=\"{$key}\" {$sel}>{$label}</option>";
              }
              ?>
            </select>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.embedding_model')) ?></p>
          </div>
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('openai.key')) ?></label>
            <input name="openai_key" type="text" value="<?=htmlspecialchars($rows['openai_key']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.openai_key')) ?></p>
          </div>
          <div class="md:col-span-2">
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('bot.greeting')) ?></label>
            <textarea name="bot_greeting" rows="3" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition"><?=htmlspecialchars($rows['bot_greeting'] ?? '')?></textarea>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.bot_greeting')) ?></p>
          </div>
          <div class="md:col-span-2">
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('system.prompt')) ?></label>
            <textarea name="system_prompt" rows="5" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition"><?=htmlspecialchars($rows['system_prompt'] ?? '')?></textarea>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.system_prompt')) ?></p>
          </div>
        </div>

        <div class="mt-6 grid sm:grid-cols-2 gap-6">
          <div class="rounded-lg bg-white/5 border border-white/10 p-4">
            <h4 class="font-semibold mb-3 text-indigo-200"><?= htmlspecialchars(t('reactions.title')) ?></h4>
            <label class="flex items-center gap-2 mb-2">
              <input type="checkbox" name="reaction_enabled" value="1" <?=(!empty($rows['reaction_enabled']) && $rows['reaction_enabled']=='1')?'checked':''?> class="h-5 w-5 text-indigo-600 rounded" />
              <span class="text-gray-200"><?= htmlspecialchars(t('reaction.telegram')) ?></span>
            </label>
            <label class="flex items-center gap-2 mb-2">
              <input type="checkbox" name="facebook_reaction_enabled" value="1" <?=(!empty($rows['facebook_reaction_enabled']) && $rows['facebook_reaction_enabled']=='1')?'checked':''?> class="h-5 w-5 text-indigo-600 rounded" />
              <span class="text-gray-200"><?= htmlspecialchars(t('reaction.facebook')) ?></span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" name="comment_trigger_enabled" value="1" <?=(!empty($rows['comment_trigger_enabled']) && $rows['comment_trigger_enabled']=='1')?'checked':''?> class="h-5 w-5 text-indigo-600 rounded" />
              <span class="text-gray-200"><?= htmlspecialchars(t('comment.trigger')) ?></span>
            </label>
          </div>
          <div class="rounded-lg bg-white/5 border border-white/10 p-4">
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('comment.message')) ?></label>
            <textarea name="comment_trigger_message" rows="4" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition"><?=htmlspecialchars($rows['comment_trigger_message'] ?? '')?></textarea>
            <p class="text-xs text-gray-400 mt-1">Facebook comments auto-reply.</p>
          </div>
        </div>
      </section>

      <!-- Telegram -->
      <section id="sec-telegram" class="sec-card rounded-xl bg-white/5 border border-white/10 p-6 hidden" hidden>
        <h3 class="text-xl font-semibold mb-4 text-indigo-300 flex items-center gap-2"><i class="fab fa-telegram-plane"></i> <?= htmlspecialchars(t('telegram.title')) ?></h3>
        <div class="space-y-4">
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('telegram.token')) ?></label>
            <input name="telegram_bot_token" type="text" value="<?=htmlspecialchars($rows['telegram_bot_token']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.telegram_token')) ?></p>
          </div>
          <div>
            <label class="block text-sm font-semibold"><?= htmlspecialchars(t('custom.webhook')) ?></label>
            <div class="flex gap-2">
              <input id="tg_webhook" name="custom_webhook_url" type="text" value="<?=htmlspecialchars($manualWebhookUrl ?: $webhookUrl)?>" class="flex-1 px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" placeholder="https://example.com/botman/index.php" />
              <button type="button" class="px-3 rounded-lg ring-1 ring-inset ring-white/10 hover:bg-white/5" data-copy="#tg_webhook"><i class="far fa-copy"></i></button>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?= str_replace('{ips}', htmlspecialchars($resolvedIps), t('hint.custom_webhook')) ?></p>
          </div>
          <div class="flex flex-wrap items-center gap-3 mt-3">
            <button name="set_webhook" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg transition"><i class="fas fa-plug mr-2"></i><?= htmlspecialchars(t('set.webhook')) ?></button>
            <?php if ($webhookStatus === 'ok'): ?>
              <span class="text-emerald-400 font-semibold"><i class="fas fa-check-circle"></i> <?= htmlspecialchars(t('webhook.connected')) ?></span>
              <button name="delete_webhook" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition"><i class="fas fa-unlink mr-2"></i><?= htmlspecialchars(t('delete.webhook')) ?></button>
            <?php elseif ($webhookStatus === 'mismatch'): ?>
              <span class="text-yellow-400 font-semibold"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars(t('webhook.connected')) ?> (<?=htmlspecialchars($data['result']['url'] ?? '')?>)</span>
            <?php else: ?>
              <span class="text-red-400 font-semibold"><i class="fas fa-times-circle"></i> <?= htmlspecialchars(t('webhook.not_connected')) ?></span>
              <div class="text-xs text-gray-400 w-full">
                <?= htmlspecialchars(t('http.check')) ?>: код <?=$reachInfo['http_code']?> <?=htmlspecialchars($reachInfo['error'])?>
                <br>IP(ы) сервера: <?=htmlspecialchars($resolvedIps)?>
              </div>
            <?php endif; ?>
            <?php if (!empty($lastError)): ?>
              <div class="text-sm text-red-400 w-full">Ошибка: <?=htmlspecialchars($lastError)?></div>
            <?php endif; ?>
          </div>

          <div class="mt-6 rounded-xl bg-white/5 border border-white/10 p-4">
            <div class="font-semibold mb-2 flex items-center gap-2"><i class="far fa-paper-plane"></i> <?= htmlspecialchars(t('telegram.test')) ?></div>
            <label class="block text-sm font-semibold"><?= htmlspecialchars(t('chat.id')) ?></label>
            <div class="flex gap-2">
              <input name="telegram_test_chat_id" type="text" value="" class="flex-1 px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" placeholder="Например, свой user_id или ID чата" />
              <button name="send_telegram_test" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition"><?= htmlspecialchars(t('send.test')) ?></button>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.telegram_test')) ?></p>
          </div>
        </div>
      </section>

      <!-- Facebook / WhatsApp -->
      <section id="sec-facebook" class="sec-card rounded-xl bg-white/5 border border-white/10 p-6 hidden" hidden>
        <h3 class="text-xl font-semibold mb-4 text-indigo-300 flex items-center gap-2"><i class="fab fa-facebook-messenger"></i> <?= htmlspecialchars(t('facebook.title')) ?> / WhatsApp</h3>
        <div class="grid md:grid-cols-2 gap-6">
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('facebook.app_id')) ?></label>
            <input name="facebook_app_id" type="text" value="<?=htmlspecialchars($rows['facebook_app_id']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.facebook_app_id')) ?></p>
          </div>
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('facebook.app_secret')) ?></label>
            <input name="facebook_app_secret" type="text" value="<?=htmlspecialchars($rows['facebook_app_secret']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
            <p class="text-xs text-gray-400 mt-1">App Secret</p>
          </div>
          <div class="md:col-span-2">
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('facebook.oauth.redirect')) ?></label>
            <div class="flex gap-2">
              <input id="fb_redirect_uri" type="text" readonly value="<?=htmlspecialchars($fbRedirectUri)?>" class="flex-1 px-3 py-2 rounded-lg bg-gray-900/40 text-gray-300 ring-1 ring-inset ring-white/10" />
              <button type="button" class="px-3 rounded-lg ring-1 ring-inset ring-white/10 hover:bg-white/5" data-copy="#fb_redirect_uri"><i class="far fa-copy"></i></button>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.oauth_redirect')) ?></p>
          </div>
          <div class="md:col-span-2">
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('webhook.unified')) ?></label>
            <div class="flex gap-2">
              <input id="fb_webhook_url" type="text" readonly value="<?=htmlspecialchars($fbWebhookUrl)?>" class="flex-1 px-3 py-2 rounded-lg bg-gray-900/40 text-gray-300 ring-1 ring-inset ring-white/10" />
              <button type="button" class="px-3 rounded-lg ring-1 ring-inset ring-white/10 hover:bg-white/5" data-copy="#fb_webhook_url"><i class="far fa-copy"></i></button>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.webhook_unified')) ?></p>
          </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
          <button type="button" id="fb_connect_btn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition"><i class="fab fa-facebook mr-2"></i><?= htmlspecialchars(t('connect.facebook')) ?></button>
          <span id="fb_status" class="text-sm <?= $fbConnectedCount ? 'text-green-400' : 'text-gray-400' ?>"><?= $fbConnectedCount ? (htmlspecialchars(t('facebook.connected')).': ' . (int)$fbConnectedCount) : htmlspecialchars(t('facebook.not_connected')) ?></span>
        </div>

        <?php if ($fbConnectedCount): ?>
        <div class="text-sm mt-4 rounded-xl bg-white/5 border border-white/10 p-4">
          <div class="font-semibold text-indigo-300 mb-2"><?= htmlspecialchars(t('connected.pages.list')) ?></div>
          <ul class="list-disc list-inside space-y-1">
            <?php foreach ($fbConnectedPages as $pg): ?>
              <li>
                <a class="text-indigo-400 hover:underline" href="<?= htmlspecialchars($pg['link'] ?? ('https://facebook.com/' . ($pg['id'] ?? '')), ENT_QUOTES) ?>" target="_blank" rel="noopener">
                  <?= htmlspecialchars($pg['name'] ?? ('ID ' . ($pg['id'] ?? '')), ENT_QUOTES) ?>
                </a>
                <span class="text-gray-500">(ID: <?= htmlspecialchars($pg['id'] ?? '') ?>)</span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($fbFailedPages)): ?>
        <div class="text-sm mt-4 rounded-xl bg-white/5 border border-white/10 p-4 text-red-400">
          <div class="font-semibold mb-2"><?= htmlspecialchars(t('failed.pages.list')) ?></div>
          <ul class="list-disc list-inside space-y-1">
            <?php foreach ($fbFailedPages as $pg): ?>
              <li>
                <?= htmlspecialchars($pg['name'] ?? ('ID ' . ($pg['id'] ?? '')), ENT_QUOTES) ?>
                <span class="text-gray-500">(ID: <?= htmlspecialchars($pg['id'] ?? '') ?>)</span> — <?= htmlspecialchars($pg['reason'] ?? 'unknown') ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <!-- Messenger quick test -->
        <div class="mt-6 rounded-xl bg-white/5 border border-white/10 p-4">
          <div class="font-semibold mb-2 flex items-center gap-2"><i class="far fa-paper-plane"></i> <?= htmlspecialchars(t('messenger.test.block')) ?></div>
          <label class="block text-sm font-semibold"><?= htmlspecialchars(t('page.access.token')) ?></label>
          <input name="facebook_page_token" type="text" value="<?=htmlspecialchars($rows['facebook_page_token']??'')?>" class="w-full px-3 py-2 text-sm rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" placeholder="Будет заполнено после OAuth подключения страницы" />
          <label class="block text-sm font-semibold mt-2"><?= htmlspecialchars(t('recipient.psid')) ?></label>
          <div class="flex gap-2">
            <input name="facebook_test_psid" type="text" value="" class="flex-1 px-3 py-2 text-sm rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" placeholder="PSID" />
            <button name="send_facebook_test" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition"><?= htmlspecialchars(t('send.test')) ?></button>
          </div>
          <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.psid')) ?></p>
        </div>

        <div class="border-t border-white/10 my-6"></div>
        <h4 class="text-lg font-semibold text-indigo-300 mb-2"><i class="fab fa-whatsapp mr-2"></i><?= htmlspecialchars(t('whatsapp.title')) ?></h4>
        <div class="grid md:grid-cols-3 gap-4">
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('whatsapp.access')) ?></label>
            <input name="whatsapp_access_token" type="text" value="<?=htmlspecialchars($rows['whatsapp_access_token']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
          </div>
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('whatsapp.phone_id')) ?></label>
            <input name="whatsapp_phone_number_id" type="text" value="<?=htmlspecialchars($rows['whatsapp_phone_number_id']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
          </div>
          <div>
            <label class="block mb-1 font-semibold"><?= htmlspecialchars(t('whatsapp.business_id')) ?></label>
            <input name="whatsapp_business_account_id" type="text" value="<?=htmlspecialchars($rows['whatsapp_business_account_id']??'')?>" class="w-full px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" />
          </div>
        </div>
        <div class="mt-3">
          <label class="block text-sm font-semibold"><?= htmlspecialchars(t('whatsapp.test.to')) ?></label>
          <div class="flex gap-2">
            <input name="whatsapp_test_to" type="text" value="" class="flex-1 px-3 py-2 text-sm rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-900 outline-none transition" placeholder="380XXXXXXXXX" />
            <button name="send_whatsapp_test" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg transition"><?= htmlspecialchars(t('whatsapp.send.test')) ?></button>
          </div>
          <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('hint.whatsapp_send')) ?></p>
        </div>
      </section>

      <!-- Help -->
      <section id="sec-help" class="sec-card rounded-xl bg-white/5 border border-white/10 p-6 hidden" hidden>
        <h3 class="text-xl font-semibold text-indigo-300 mb-4"><i class="fas fa-book-open mr-2"></i><?= htmlspecialchars(t('tabs.help')) ?></h3>
        <div class="space-y-6 text-sm text-gray-200">
          <div>
            <h4 class="text-lg font-semibold text-indigo-200">A) Meta App</h4>
            <ol class="list-decimal list-inside space-y-1">
              <li>developers.facebook.com → Create App (scenario: Other).</li>
              <li>App name, contact email → Create.</li>
            </ol>
          </div>
          <div>
            <h4 class="text-lg font-semibold text-indigo-200">B) Webhooks</h4>
            <ol class="list-decimal list-inside space-y-1">
              <li>Messenger: events messages, messaging_postbacks, message_reactions, message_reads.</li>
              <li>Callback URL: <span class="text-indigo-300"><?= htmlspecialchars($fbWebhookUrl) ?></span></li>
              <li>Verify Token: значение с этой страницы.</li>
            </ol>
          </div>
          <div>
            <h4 class="text-lg font-semibold text-indigo-200">C) OAuth</h4>
            <ol class="list-decimal list-inside space-y-1">
              <li>Добавь Redirect URI: <span class="text-indigo-300"><?= htmlspecialchars($fbRedirectUri) ?></span></li>
              <li>Нажми «Подключить Facebook», выбери страницы.</li>
            </ol>
          </div>
          <div>
            <h4 class="text-lg font-semibold text-indigo-200">D) WhatsApp Cloud API</h4>
            <ol class="list-decimal list-inside space-y-1">
              <li>Phone Number ID, Access Token → сюда.</li>
              <li>Отправь тест на свой номер.</li>
            </ol>
          </div>
        </div>
      </section>

      <!-- Sticky Save Bar -->
      <div class="sticky bottom-4 z-10">
        <div class="bg-gradient-to-r from-indigo-500 to-fuchsia-600 p-0.5 rounded-xl shadow-xl">
          <div class="bg-gray-900/90 backdrop-blur rounded-[10px] px-4 py-3 flex items-center justify-between">
            <span class="text-sm text-gray-300"><i class="fas fa-cog mr-2"></i><?= htmlspecialchars(t('nav.settings')) ?></span>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg transition shadow">
              <i class="fas fa-save mr-2"></i><?= htmlspecialchars(t('save')) ?>
            </button>
          </div>
        </div>
      </div>

    </form>
  </main>
</div>

<script>
(function(){
  // Sidebar nav → sections
  const btns = document.querySelectorAll('.sec-btn');
  const secs = document.querySelectorAll('.sec-card');
  function activate(id){
    secs.forEach(s=>{ s.classList.add('hidden'); s.setAttribute('hidden',''); });
    const curSec = document.getElementById('sec-'+id);
    if (curSec){ curSec.classList.remove('hidden'); curSec.removeAttribute('hidden'); }
    btns.forEach(b=>{
      b.classList.remove('bg-indigo-600/20','text-indigo-200','ring-indigo-400/30');
      b.classList.add('text-gray-300','ring-white/10');
    });
    const cur = document.querySelector('.sec-btn[data-sec="'+id+'"]');
    if (cur){ cur.classList.add('bg-indigo-600/20','text-indigo-200','ring-indigo-400/30'); cur.classList.remove('text-gray-300'); }
  }
  btns.forEach(b=>b.addEventListener('click',()=>activate(b.getAttribute('data-sec'))));
  activate('common');

  // Copy helpers
  document.querySelectorAll('[data-copy]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const tgt = document.querySelector(btn.getAttribute('data-copy'));
      if (!tgt) return;
      navigator.clipboard.writeText(tgt.value||'').then(()=>{
        btn.classList.add('ring-indigo-400');
        setTimeout(()=>btn.classList.remove('ring-indigo-400'), 600);
      });
    });
  });
})();
</script>

<script>
// Facebook connect (kept from previous logic)
(function(){
  const btn = document.getElementById('fb_connect_btn');
  if (!btn) return;
  btn.addEventListener('click', function(){
    const appIdInput = document.querySelector('input[name="facebook_app_id"]');
    const appId = appIdInput ? appIdInput.value.trim() : '';
    if (!appId) { alert(<?= json_encode(t('msg.fb_enter_appid')) ?>); return; }
    const url = new URL('https://www.facebook.com/v23.0/dialog/oauth');
    url.searchParams.set('client_id', appId);
    url.searchParams.set('redirect_uri', '<?=htmlspecialchars($fbRedirectUri, ENT_QUOTES)?>');
    url.searchParams.set('scope', ['pages_show_list','pages_manage_metadata','pages_messaging'].join(','));
    url.searchParams.set('response_type', 'code');
    url.searchParams.set('state', 'facebook');
    window.open(url.toString(), 'FBConnect', 'width=600,height=700');
  });
  window.addEventListener('message', function(e){
    if (!e.data || e.data.platform !== 'facebook') return;
    const status = document.getElementById('fb_status');
    if (e.data.status === 'connected') {
      if (status) {
        var cnt = (e.data.page_ids && e.data.page_ids.length) ? e.data.page_ids.length : (e.data.page_id ? 1 : 0);
        status.textContent = <?= json_encode(t('facebook.connected')) ?> + '! ' + <?= json_encode(t('connected.pages')) ?> + ': ' + cnt;
        status.classList.remove('text-gray-400');
        status.classList.add('text-green-400');
      }
    } else {
      if (status) {
        status.textContent = <?= json_encode(t('msg.fb_connect_error_short')) ?>;
        status.classList.remove('text-green-400');
        status.classList.add('text-red-400');
      }
    }
  });
})();
</script>
<?php require_once __DIR__.'/../inc/footer.php'; ?>