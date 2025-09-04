<?php require_once __DIR__.'/auth.php'; ?>
<?php
// --- UI language: persist per authorized user and load on each request ---
// Attempt to get DB handle but avoid throwing a fatal error if connection fails
try {
    $dbh = function_exists('db') ? db() : null;
} catch (Throwable $e) {
    // DB not available; continue in session-only mode
    $dbh = null;
}
if ($dbh) {
    // Create user_prefs table if not exists — wrap in try/catch to avoid breaking the UI
    try {
        $dbh->exec("CREATE TABLE IF NOT EXISTS user_prefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            pref VARCHAR(64) NOT NULL,
            value VARCHAR(255) NOT NULL,
            UNIQUE KEY uniq_user_pref (user_id, pref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        // ignore schema creation errors here
    }
}

// Handle language switch (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ui_lang'])) {
    $lang = in_array($_POST['ui_lang'], ['en','ru'], true) ? $_POST['ui_lang'] : 'en';
    $_SESSION['ui_lang'] = $lang;
    if ($dbh && !empty($_SESSION['user_id'])) {
        try {
            $stmt = $dbh->prepare("INSERT INTO user_prefs (user_id, pref, value) VALUES (?, 'ui_lang', ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
            $stmt->execute([ (int)$_SESSION['user_id'], $lang ]);
        } catch (Throwable $e) {
            // Don't break the request on DB write failure; log for debugging
            @file_put_contents(__DIR__ . '/../error.log', '[' . date('c') . '] Language save failed: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
    // PRG pattern to avoid resubmission
    header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?') . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']!=='' ? ('?'.$_SERVER['QUERY_STRING']) : ''));
    exit;
}

// Determine current UI language
$ui_lang = 'en';
if ($dbh && !empty($_SESSION['user_id'])) {
    $stmt = $dbh->prepare("SELECT value FROM user_prefs WHERE user_id=? AND pref='ui_lang'");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $val = $stmt->fetchColumn();
    if (is_string($val) && $val !== '') { $ui_lang = $val; }
} elseif (!empty($_SESSION['ui_lang'])) {
    $ui_lang = in_array($_SESSION['ui_lang'], ['en','ru'], true) ? $_SESSION['ui_lang'] : 'en';
}

// Translations
$i18n = [
  'en' => [
    'app.title' => 'Chatbot Admin',
    'nav.dashboard' => 'Dashboard',
    'nav.training' => 'Training',
    'nav.history' => 'History',
    'nav.settings' => 'Settings',
    'nav.logout' => 'Log out',
    'lang.label' => 'Language',
    'lang.en' => 'English',
    'lang.ru' => 'Russian',
    'welcome' => 'Welcome',
    'last_login' => 'Last login',
    'telegram.bot' => 'Telegram Bot',
    'users' => 'Users',
    'status.active' => 'Active',
    'status.inactive' => 'Inactive',
    'fb.pages' => 'Facebook Pages',
    'connected.pages' => 'Connected pages',
    'training.progress' => 'Training progress',
    'progress' => 'Progress',
    'open.training' => 'Start training',
    'training.desc' => 'Upload sitemap, set exclusions and launch the process.',
    'open.history' => 'Training history',
    'history.desc' => 'See previous runs.',
    'open.settings' => 'Settings',
    'settings.desc' => 'OpenAI keys, bot tokens, etc.',
    'training.title' => 'Bot Training',
    'training.cost' => 'Current training cost',
    'history.title' => 'Dialog history',
    'history.sessions' => 'Conversations',
    'history.sessions.desc' => 'Grouped by chat sessions across channels.',
    'filter.by' => 'Filter',
    'channel.all' => 'All',
    'channel.telegram' => 'Telegram',
    'channel.facebook' => 'Facebook',
    'channel.instagram' => 'Instagram',
    'channel.whatsapp' => 'WhatsApp',
    'channel.web' => 'Website',
    'channel.unknown' => 'Unknown',
    'session.messages' => 'messages',
    'session.duration' => 'Duration',
    'view.details' => 'View',
    'loading' => 'Loading...',
    'close' => 'Close',
    'export.txt' => 'Export .txt',
    'no.sessions' => 'No sessions found',
    'search.placeholder' => 'Search history...',
    'question' => 'Question',
    'answer' => 'Answer',
    'save' => 'Save',
    'tabs.common' => 'General',
    'tabs.telegram' => 'Telegram',
    'tabs.facebook' => 'Facebook',
    'tabs.help' => 'Guide',
    'general.title' => 'General settings',
    'openai.key' => 'OpenAI API Key',
    'embedding.model' => 'Embedding model',
    'bot.greeting' => 'Bot greeting',
    'system.prompt' => 'System prompt',
    'reactions.title' => 'Reactions',
    'reaction.telegram' => 'React 👍 before replying (Telegram)',
    'reaction.facebook' => 'React ❤️ before replying (Facebook)',
    'comment.trigger' => 'Enable auto-reply to comments (Facebook)',
    'comment.message' => 'Comment reply text',
    'telegram.title' => 'Telegram',
    'telegram.token' => 'Telegram Bot Token',
    'custom.webhook' => 'Custom Webhook URL (optional)',
    'set.webhook' => 'Set Webhook',
    'delete.webhook' => 'Delete Webhook',
    'webhook.connected' => 'Webhook connected',
    'webhook.not_connected' => 'Webhook not connected',
    'http.check' => 'HTTP check',
    'telegram.test' => 'Telegram test message',
    'chat.id' => 'Chat ID',
    'send.test' => 'Send test',
    'facebook.title' => 'Facebook',
    'facebook.app_id' => 'Facebook App ID',
    'facebook.oauth.redirect' => 'Facebook OAuth Redirect URI',
    'webhook.unified' => 'Unified Webhook Callback URL (Telegram/Messenger/Instagram/WhatsApp)',
    'connect.facebook' => 'Connect Facebook',
    'facebook.connected' => 'Connected',
    'facebook.not_connected' => 'Not connected',
    'connected.pages.list' => 'Connected pages:',
    'failed.pages.list' => 'Failed to connect:',
    'messenger.test.block' => 'Messenger test message',
    'page.access.token' => 'Page Access Token',
    'recipient.psid' => 'Recipient PSID',
    'facebook.app_secret' => 'Facebook App Secret',
    'facebook.verify' => 'Facebook Webhook Verification Token',
    'whatsapp.title' => 'WhatsApp Cloud API',
    'whatsapp.access' => 'WhatsApp Access Token',
    'whatsapp.phone_id' => 'WhatsApp Phone Number ID',
    'whatsapp.business_id' => 'WhatsApp Business Account ID (optional)',
    'whatsapp.test.to' => 'Test recipient (E.164, without +)',
    'whatsapp.send.test' => 'Send test to WhatsApp',
    'help.title' => 'Meta setup (Facebook/Instagram/WhatsApp)',
    'footer.version' => 'v0.1 • Developed by BuyReadySite.com',
    'footer.updated' => 'Last update:',
    'footer.docs' => 'Docs',
    'footer.support' => 'Support',
    'login.title' => 'Sign in • Chatbot Admin',
    'login.heading' => 'Sign in',
    'login.email' => 'Email',
    'login.password' => 'Password',
    'login.submit' => 'Sign in',
    'saved' => 'Saved'
,    'training.manage' => 'Training management',
    'clear.all' => 'Clear all training data',
    'view.stats' => 'View statistics',
    'url.input' => 'URL input',
    'paste.links' => 'Paste links to pages or sitemaps, one per line',
    'preview' => 'Preview',
    'start' => 'Start training',
    'exclusions' => 'Exclusions',
    'exclusions.placeholder' => 'One pattern per line',
    'total.links' => 'Total links',
    'status.new' => 'New',
    'status.update' => 'Needs update',
    'status.ready' => 'Ready',
    'launched' => 'Training launched',
    'you.can.close' => 'You can close the page — the process will continue in the background.',
    'links' => 'links',
    'of' => 'of'
,    'filtered.links' => 'Filtered links',
    'domain.breakdown' => 'Domain breakdown',
    'exclusions.examples' => 'Examples (regex or substring). Click to add:',
    'examples.use' => 'Click to add',
    'stats.by_site' => 'Training stats by site',
    'site' => 'Site',
    'trainings' => 'Trainings',
    'pages' => 'Pages',
    'processed' => 'Processed',
    'refresh' => 'Refresh',
    'last.training' => 'Last training'
,    'summary.title' => 'Training summary',
    'total.sites' => 'Sites',
    'trained.pages' => 'Trained pages',
    'ongoing' => 'Running trainings',
    'preview.next' => 'Continue to exclusions',
    'step.urls' => 'Step 1 — URLs',
    'step.exclusions' => 'Step 2 — Exclusions',
    'stats.title' => 'Training statistics',
    'domain' => 'Domain',
    'error.pages' => 'Error pages',
    'action' => 'Action',
    'retry' => 'retry',
    'train.site' => 'train site'
,
    // --- Processing summary and modes ---
    'summary.review' => 'Summary before start',
    'process.mode' => 'Processing mode',
    'mode.smart' => 'Smart',
    'mode.smart.desc' => 'Process new and updated pages, skip those already ready',
    'mode.new' => 'Only new',
    'mode.new.desc' => 'Process only pages that were not trained before',
    'mode.all' => 'Reprocess all',
    'mode.all.desc' => 'Force retrain all pages (overwrite previous results)',
    'will.process' => 'Will process',
    'next' => 'Next',
    'back' => 'Back',
    'dns.noip' => 'failed to resolve IP (DNS unreachable)',
    'hint.openai_key' => 'Enter your OpenAI API key.',
    'hint.embedding_model' => 'Select the model for text embeddings.',
    'hint.bot_greeting' => 'A message your bot sends on first contact.',
    'hint.system_prompt' => 'High‑level instructions that define bot behavior.',
    'hint.telegram_token' => 'Bot token from @BotFather in Telegram.',
    'hint.custom_webhook' => 'If your domain is not reachable via HTTPS or uses a custom path — specify it manually. Current server IP(s): {ips}',
    'hint.telegram_test' => 'Any chat_id where the bot can write (for DMs — write to the bot first).',
    'hint.facebook_app_id' => 'Your app ID in Facebook Developer Console.',
    'hint.oauth_redirect' => 'Add this URI in Facebook App → Valid OAuth Redirect URIs.',
    'hint.webhook_unified' => 'Use this URL in Webhook settings for Messenger, Instagram and WhatsApp (Cloud API). Set the same Verification Token.',
    'hint.psid' => 'PSID is the user identifier who wrote to the page. You can get it from incoming webhook payload or support tools.',
    'hint.whatsapp_send' => 'Message is sent via Cloud API to the specified number. Make sure the number is allowed for tests or production is enabled.',
    'msg.whatsapp_sent' => 'WhatsApp test sent',
    'msg.whatsapp_token_expired' => 'WhatsApp error: token expired. Get a new Access Token in Meta console and save it.',
    'msg.whatsapp_error' => 'WhatsApp error',
    'msg.whatsapp_fill' => 'Provide Access Token, Phone Number ID and recipient number',
    'msg.telegram_sent' => 'Telegram test sent',
    'msg.telegram_error' => 'Telegram error',
    'msg.telegram_fill' => 'Provide Bot Token and chat_id',
    'msg.fb_sent' => 'Facebook test sent',
    'msg.fb_error' => 'Facebook error',
    'msg.fb_fill' => 'Provide Page Access Token and recipient PSID',
    'msg.webhook_set_ok' => 'Webhook set successfully',
    'msg.webhook_set_error' => 'Webhook setup error',
    'msg.webhook_deleted' => 'Webhook deleted',
    'msg.webhook_delete_error' => 'Webhook deletion error',
    'msg.fb_enter_appid' => 'Enter Facebook App ID and click “Save”',
    'msg.fb_connect_error_short' => 'Facebook connection error'
  ],
  'ru' => [
    'app.title' => 'Панель Чат-бота',
    'nav.dashboard' => 'Панель',
    'nav.training' => 'Обучение',
    'nav.history' => 'История',
    'nav.settings' => 'Настройки',
    'nav.logout' => 'Выйти',
    'lang.label' => 'Язык',
    'lang.en' => 'Английский',
    'lang.ru' => 'Русский',
    'welcome' => 'Добро пожаловать',
    'last_login' => 'Последний вход',
    'telegram.bot' => 'Telegram-бот',
    'users' => 'Пользователи',
    'status.active' => 'Активен',
    'status.inactive' => 'Неактивен',
    'fb.pages' => 'Страницы Facebook',
    'connected.pages' => 'Подключено страниц',
    'training.progress' => 'Прогресс обучения',
    'progress' => 'Прогресс',
    'open.training' => 'Запуск обучения',
    'training.desc' => 'Загрузите карту сайта, задайте исключения и запустите процесс.',
    'open.history' => 'История обучений',
    'history.desc' => 'Посмотреть результаты предыдущих запусков.',
    'open.settings' => 'Настройки',
    'settings.desc' => 'Ключи OpenAI, токены ботов и др.',
    'training.title' => 'Обучение чат-бота',
    'training.cost' => 'Текущие затраты на обучение',
    'history.title' => 'История диалогов',
    'history.sessions' => 'Диалоги',
    'history.sessions.desc' => 'Сгруппировано по сессиям (каналы: Telegram, Facebook и др.).',
    'filter.by' => 'Фильтр',
    'channel.all' => 'Все',
    'channel.telegram' => 'Telegram',
    'channel.facebook' => 'Facebook',
    'channel.instagram' => 'Instagram',
    'channel.whatsapp' => 'WhatsApp',
    'channel.web' => 'Сайт',
    'channel.unknown' => 'Неизвестно',
    'session.messages' => 'сообщений',
    'session.duration' => 'Длительность',
    'view.details' => 'Подробнее',
    'loading' => 'Загрузка...',
    'close' => 'Закрыть',
    'export.txt' => 'Экспорт .txt',
    'no.sessions' => 'Сессии не найдены',
    'search.placeholder' => 'Поиск по истории...',
    'question' => 'Вопрос',
    'answer' => 'Ответ',
    'save' => 'Сохранить',
    'tabs.common' => 'Общие',
    'tabs.telegram' => 'Telegram',
    'tabs.facebook' => 'Facebook',
    'tabs.help' => 'Инструкция',
    'general.title' => 'Общие настройки',
    'openai.key' => 'OpenAI API-ключ',
    'embedding.model' => 'Модель эмбеддингов',
    'bot.greeting' => 'Приветствие бота',
    'system.prompt' => 'Системный промпт',
    'reactions.title' => 'Опции реакций',
    'reaction.telegram' => 'Ставить реакцию 👍 перед ответом (Telegram)',
    'reaction.facebook' => 'Ставить реакцию ❤️ перед ответом (Facebook)',
    'comment.trigger' => 'Включить автоответ на комментарии (Facebook)',
    'comment.message' => 'Текст ответа на комментарии',
    'telegram.title' => 'Telegram',
    'telegram.token' => 'Токен бота Telegram',
    'custom.webhook' => 'Кастомный Webhook URL (опционально)',
    'set.webhook' => 'Установить Webhook',
    'delete.webhook' => 'Удалить Webhook',
    'webhook.connected' => 'Webhook подключён',
    'webhook.not_connected' => 'Webhook не подключён',
    'http.check' => 'HTTP проверка',
    'telegram.test' => 'Тест сообщения в Telegram',
    'chat.id' => 'Chat ID',
    'send.test' => 'Отправить тест',
    'facebook.title' => 'Facebook',
    'facebook.app_id' => 'Facebook App ID',
    'facebook.oauth.redirect' => 'Facebook OAuth Redirect URI',
    'webhook.unified' => 'Единый Webhook Callback URL (Telegram/Messenger/Instagram/WhatsApp)',
    'connect.facebook' => 'Подключить Facebook',
    'facebook.connected' => 'Подключено',
    'facebook.not_connected' => 'Не подключено',
    'connected.pages.list' => 'Подключённые страницы:',
    'failed.pages.list' => 'Не удалось подключить:',
    'messenger.test.block' => 'Тест сообщения в Facebook Messenger',
    'page.access.token' => 'Page Access Token',
    'recipient.psid' => 'PSID получателя',
    'facebook.app_secret' => 'Facebook App Secret',
    'facebook.verify' => 'Facebook Webhook Verification Token',
    'whatsapp.title' => 'WhatsApp Cloud API',
    'whatsapp.access' => 'WhatsApp Access Token',
    'whatsapp.phone_id' => 'WhatsApp Phone Number ID',
    'whatsapp.business_id' => 'WhatsApp Business Account ID (опционально)',
    'whatsapp.test.to' => 'Тестовый номер (E.164, без +)',
    'whatsapp.send.test' => 'Отправить тест в WhatsApp',
    'help.title' => 'Пошаговая настройка Meta (Facebook/Instagram/WhatsApp)',
    'footer.version' => 'v0.1 • Developed by BuyReadySite.com',
    'footer.updated' => 'Последнее обновление:',
    'footer.docs' => 'Документация',
    'footer.support' => 'Поддержка',
    'login.title' => 'Вход • Панель Чат-бота',
    'login.heading' => 'Вход',
    'login.email' => 'Email',
    'login.password' => 'Пароль',
    'login.submit' => 'Войти',
    'saved' => 'Сохранено'
,    'training.manage' => 'Управление обучением',
    'clear.all' => 'Очистить все данные обучения',
    'view.stats' => 'Просмотреть статистику',
    'url.input' => 'Ввод URL',
    'paste.links' => 'Вставьте ссылки на страницы или карты сайта, по одной на строке',
    'preview' => 'Предпросмотр',
    'start' => 'Начать обучение',
    'exclusions' => 'Исключения',
    'exclusions.placeholder' => 'Каждый шаблон с новой строки',
    'total.links' => 'Всего ссылок',
    'status.new' => 'Новые',
    'status.update' => 'Требуют обновления',
    'status.ready' => 'Готовые',
    'launched' => 'Обучение запущено',
    'you.can.close' => 'Страницу можно закрыть — процесс продолжится в фоне.',
    'links' => 'ссылок',
    'of' => 'из'
,    'filtered.links' => 'Отфильтровано ссылок',
    'domain.breakdown' => 'Разбивка по доменам',
    'exclusions.examples' => 'Примеры (регэксп или подстрока). Кликни, чтобы добавить:',
    'examples.use' => 'Кликни, чтобы добавить',
    'stats.by_site' => 'Статистика обучения по сайтам',
    'site' => 'Сайт',
    'trainings' => 'Обучений',
    'pages' => 'Страниц',
    'processed' => 'Обработано',
    'refresh' => 'Обновить',
    'last.training' => 'Последнее обучение'
,    'summary.title' => 'Сводка обучения',
    'total.sites' => 'Сайтов',
    'trained.pages' => 'Обученных страниц',
    'ongoing' => 'Запущено обучений',
    'preview.next' => 'Перейти к исключениям',
    'step.urls' => 'Шаг 1 — URL',
    'step.exclusions' => 'Шаг 2 — Исключения',
    'stats.title' => 'Статистика обучения',
    'domain' => 'Домен',
    'error.pages' => 'Ошибочные страницы',
    'action' => 'Действие',
    'retry' => 'повторить',
    'train.site' => 'обучить сайт'
,
    // --- Processing summary and modes ---
    'summary.review' => 'Сводка перед запуском',
    'process.mode' => 'Режим обработки',
    'mode.smart' => 'Умный',
    'mode.smart.desc' => 'Обработать новые и обновлённые, готовые пропустить',
    'mode.new' => 'Только новые',
    'mode.new.desc' => 'Обработать только страницы, которые ранее не обучались',
    'mode.all' => 'Перезапустить все',
    'mode.all.desc' => 'Принудительно переобучить все страницы (перезапись предыдущих результатов)',
    'will.process' => 'Будет обработано',
    'next' => 'Далее',
    'back' => 'Назад',
    'dns.noip' => 'не удалось получить IP (DNS не отвечает)',
    'hint.openai_key' => 'Введите ключ API от OpenAI.',
    'hint.embedding_model' => 'Выберите модель для создания эмбеддингов текста.',
    'hint.bot_greeting' => 'Сообщение, которое бот отправляет при первом контакте.',
    'hint.system_prompt' => 'Высокоуровневые инструкции, определяющие поведение бота.',
    'hint.telegram_token' => 'Токен бота от @BotFather в Telegram.',
    'hint.custom_webhook' => 'Если домен недоступен по HTTPS или используется другой путь — укажи вручную. Текущий IP(ы): {ips}',
    'hint.telegram_test' => 'Любой chat_id, где боту разрешено писать (для лички — сначала напиши боту).',
    'hint.facebook_app_id' => 'ID вашего приложения в Facebook Developer Console.',
    'hint.oauth_redirect' => 'Добавьте этот URI в настройках Facebook App → Valid OAuth Redirect URIs.',
    'hint.webhook_unified' => 'Используйте этот URL в настройках Webhook для Messenger, Instagram и WhatsApp (Cloud API). Укажите тот же Verification Token.',
    'hint.psid' => 'PSID — это ID пользователя, написавшего на страницу. Узнать можно из payload входящего вебхука или инструментов поддержки.',
    'hint.whatsapp_send' => 'Сообщение отправляется через Cloud API на указанный номер. Убедитесь, что номер разрешён для тестов или включён прод‑режим.',
    'msg.whatsapp_sent' => 'WhatsApp тест отправлен',
    'msg.whatsapp_token_expired' => 'Ошибка WhatsApp: токен истёк. Получите новый Access Token в консоли Meta и сохраните.',
    'msg.whatsapp_error' => 'Ошибка WhatsApp',
    'msg.whatsapp_fill' => 'Укажите Access Token, Phone Number ID и номер получателя',
    'msg.telegram_sent' => 'Telegram тест отправлен',
    'msg.telegram_error' => 'Ошибка Telegram',
    'msg.telegram_fill' => 'Укажи Bot Token и chat_id',
    'msg.fb_sent' => 'Facebook тест отправлен',
    'msg.fb_error' => 'Ошибка Facebook',
    'msg.fb_fill' => 'Укажи Page Access Token и PSID получателя',
    'msg.webhook_set_ok' => 'Webhook успешно установлен',
    'msg.webhook_set_error' => 'Ошибка установки Webhook',
    'msg.webhook_deleted' => 'Webhook удалён',
    'msg.webhook_delete_error' => 'Ошибка удаления Webhook',
    'msg.fb_enter_appid' => 'Укажи Facebook App ID и нажми «Сохранить»',
    'msg.fb_connect_error_short' => 'Ошибка подключения Facebook'
  ]
];

function t(string $key): string {
    global $i18n, $ui_lang;
    return $i18n[$ui_lang][$key] ?? ($i18n['en'][$key] ?? $key);
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($ui_lang) ?>">
<head>
    <style>
        body { display: flex; flex-direction: column; min-height: 100vh; }
        main { padding-top: 4rem; flex: 1; }
        footer { flex-shrink: 0; }
    </style>
    <meta charset="utf-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <title><?= htmlspecialchars(t('app.title')) ?></title>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-indigo-950 to-gray-900 text-gray-100 antialiased flex flex-col">
<header id="appHeader" class="sticky top-0 z-20 backdrop-blur-xl bg-white/5 border-b border-white/10/0">
  <!-- subtle gradient line -->
  <div class="h-[2px] bg-gradient-to-r from-emerald-400/60 via-indigo-400/60 to-fuchsia-400/60"></div>
  <div class="container mx-auto flex items-center justify-between py-3">
    <?php $current = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
    <!-- Brand -->
    <div class="flex items-center gap-3">
      <div class="relative w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 grid place-items-center shadow-lg shadow-emerald-900/30 ring-1 ring-white/10">
        <span class="font-black text-[13px] tracking-tight">BRS</span>
      </div>
      <div class="font-semibold text-lg sm:text-xl text-white/90"><?= htmlspecialchars(t('app.title')) ?></div>
    </div>

    <!-- Desktop nav -->
    <nav class="hidden md:flex items-center gap-2">
      <a href="/admin/dashboard.php" class="px-3 py-2 rounded-lg transition <?= $current==='dashboard.php'?'bg-white/10 text-indigo-300 ring-1 ring-white/10':'hover:bg-white/5' ?>">
        <i class="fas fa-tachometer-alt"></i> <span class="ml-1 hidden lg:inline"><?= htmlspecialchars(t('nav.dashboard')) ?></span>
      </a>
      <a href="/admin/training.php" class="px-3 py-2 rounded-lg transition <?= $current==='training.php'?'bg-white/10 text-indigo-300 ring-1 ring-white/10':'hover:bg-white/5' ?>">
        <i class="fas fa-brain"></i> <span class="ml-1 hidden lg:inline"><?= htmlspecialchars(t('nav.training')) ?></span>
      </a>
      <a href="/admin/history.php" class="px-3 py-2 rounded-lg transition <?= $current==='history.php'?'bg-white/10 text-indigo-300 ring-1 ring-white/10':'hover:bg-white/5' ?>">
        <i class="fas fa-history"></i> <span class="ml-1 hidden lg:inline"><?= htmlspecialchars(t('nav.history')) ?></span>
      </a>
      <a href="/admin/settings.php" class="px-3 py-2 rounded-lg transition <?= $current==='settings.php'?'bg-white/10 text-indigo-300 ring-1 ring-white/10':'hover:bg-white/5' ?>">
        <i class="fas fa-cog"></i> <span class="ml-1 hidden lg:inline"><?= htmlspecialchars(t('nav.settings')) ?></span>
      </a>
      <a href="/public/logout.php" class="px-3 py-2 rounded-lg hover:bg-white/5 transition">
        <i class="fas fa-sign-out-alt"></i> <span class="ml-1 hidden lg:inline"><?= htmlspecialchars(t('nav.logout')) ?></span>
      </a>
    </nav>

    <!-- Right: language + mobile burger -->
    <div class="flex items-center gap-2">
      <!-- Segmented language switch -->
      <form method="post" class="hidden sm:flex items-center gap-1 bg-white/5 rounded-xl p-1 ring-1 ring-white/10">
        <button type="submit" name="ui_lang" value="en" class="px-2.5 py-1 rounded-lg text-xs <?= $ui_lang==='en'?'bg-emerald-500/20 text-emerald-200 ring-1 ring-emerald-400/30':'text-gray-300 hover:bg-white/5' ?>">EN</button>
        <button type="submit" name="ui_lang" value="ru" class="px-2.5 py-1 rounded-lg text-xs <?= $ui_lang==='ru'?'bg-emerald-500/20 text-emerald-200 ring-1 ring-emerald-400/30':'text-gray-300 hover:bg-white/5' ?>">RU</button>
      </form>

      <!-- Mobile menu button -->
      <button id="btnBurger" class="md:hidden inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white/5 ring-1 ring-white/10 hover:bg-white/10" aria-expanded="false" aria-controls="mobileMenu">
        <i class="fas fa-bars"></i>
      </button>
    </div>
  </div>

  <!-- Mobile dropdown -->
  <div id="mobileMenu" class="md:hidden hidden border-t border-white/10 bg-gray-900/95">
    <div class="container mx-auto py-3 space-y-1">
      <a href="/admin/dashboard.php" class="flex items-center gap-2 px-3 py-2 rounded-lg <?= $current==='dashboard.php'?'bg-white/10 text-indigo-300':'hover:bg-white/5' ?>">
        <i class="fas fa-tachometer-alt"></i><span><?= htmlspecialchars(t('nav.dashboard')) ?></span>
      </a>
      <a href="/admin/training.php" class="flex items-center gap-2 px-3 py-2 rounded-lg <?= $current==='training.php'?'bg-white/10 text-indigo-300':'hover:bg-white/5' ?>">
        <i class="fas fa-brain"></i><span><?= htmlspecialchars(t('nav.training')) ?></span>
      </a>
      <a href="/admin/history.php" class="flex items-center gap-2 px-3 py-2 rounded-lg <?= $current==='history.php'?'bg-white/10 text-indigo-300':'hover:bg-white/5' ?>">
        <i class="fas fa-history"></i><span><?= htmlspecialchars(t('nav.history')) ?></span>
      </a>
      <a href="/admin/settings.php" class="flex items-center gap-2 px-3 py-2 rounded-lg <?= $current==='settings.php'?'bg-white/10 text-indigo-300':'hover:bg-white/5' ?>">
        <i class="fas fa-cog"></i><span><?= htmlspecialchars(t('nav.settings')) ?></span>
      </a>
      <form method="post" class="pt-2 flex items-center gap-2">
        <span class="text-xs text-gray-400"><?= htmlspecialchars(t('lang.label')) ?>:</span>
        <button type="submit" name="ui_lang" value="en" class="px-2 py-1 rounded bg-white/5 ring-1 ring-white/10 <?= $ui_lang==='en'?'text-emerald-200 ring-emerald-400/30 bg-emerald-500/10':'' ?>">EN</button>
        <button type="submit" name="ui_lang" value="ru" class="px-2 py-1 rounded bg-white/5 ring-1 ring-white/10 <?= $ui_lang==='ru'?'text-emerald-200 ring-emerald-400/30 bg-emerald-500/10':'' ?>">RU</button>
      </form>
      <a href="/public/logout.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-white/5">
        <i class="fas fa-sign-out-alt"></i><span><?= htmlspecialchars(t('nav.logout')) ?></span>
      </a>
    </div>
  </div>
</header>
<script>
  (function(){
    const header = document.getElementById('appHeader');
    const burger = document.getElementById('btnBurger');
    const menu = document.getElementById('mobileMenu');

    // shadow on scroll
    function onScroll(){
      const y = window.scrollY || document.documentElement.scrollTop;
      header.classList.toggle('shadow-[0_10px_30px_rgba(0,0,0,.35)]', y>2);
      header.classList.toggle('border-white/10', y>2);
    }
    onScroll();
    window.addEventListener('scroll', onScroll, {passive:true});

    // burger toggle
    burger && burger.addEventListener('click', function(){
      const open = menu.classList.toggle('hidden');
      burger.setAttribute('aria-expanded', (!open).toString());
      this.innerHTML = open ? '<i class="fas fa-bars"></i>' : '<i class="fas fa-times"></i>';
    });
  })();
</script>
<main class="flex-grow container mx-auto py-6 mt-16">