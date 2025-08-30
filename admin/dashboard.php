<?php
require_once __DIR__.'/../inc/auth.php';
require_login();
require_once __DIR__.'/../inc/header.php';

// Информация о Telegram-боте
$keys = db()->query("SELECT name, value FROM api_keys")->fetchAll(PDO::FETCH_KEY_PAIR);
$botUsername = '';
if (!empty($keys['telegram_bot_token'])) {
    $token = $keys['telegram_bot_token'];
    $me = @file_get_contents("https://api.telegram.org/bot{$token}/getMe");
    $meData = json_decode($me, true);
    if (!empty($meData['ok']) && !empty($meData['result']['username'])) {
        $botUsername = $meData['result']['username'];
    }
}
$userCount = 0;
try {
    $userCount = db()->query("SELECT COUNT(*) FROM bot_users")->fetchColumn();
} catch (\PDOException $e) {
    // table does not exist or error, leave userCount = 0
}

// Webhook статус
$webhookStatus = null;
if ($userCount !== null && !empty($keys['telegram_bot_token'])) {
    $token = $keys['telegram_bot_token'];
    $webhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/botman/index.php';
    $info = @file_get_contents("https://api.telegram.org/bot{$token}/getWebhookInfo");
    $data = json_decode($info, true);
    $webhookStatus = (!empty($data['ok']) && !empty($data['result']['url']) && $data['result']['url'] === $webhookUrl)
        ? 'ok' : 'error';
}

// Индикатор фона обучения
$stmt = db()->query("SELECT SUM(processed_pages) done, SUM(total_pages) total
                     FROM trainings WHERE status='running'");
$progress = $stmt->fetch();

// Facebook connected pages
$fbConnectedPages = [];
if (!empty($keys['facebook_connected_pages_json'])) {
    $tmp = json_decode($keys['facebook_connected_pages_json'], true);
    if (is_array($tmp)) { $fbConnectedPages = $tmp; }
}
$fbConnectedCount = count($fbConnectedPages);
?>
<?php $perc = ($progress && $progress['total']>0) ? round($progress['done']*100/$progress['total'],1) : 0; ?>

<!-- Hero -->
<section class="relative overflow-hidden rounded-2xl mb-8 p-6 md:p-8 bg-gradient-to-br from-indigo-900/40 via-indigo-800/20 to-purple-900/30 border border-white/10 backdrop-blur">
  <div class="absolute -top-20 -left-24 h-80 w-80 rounded-full bg-indigo-600/20 blur-3xl"></div>
  <div class="absolute -bottom-24 -right-24 h-[22rem] w-[22rem] rounded-full bg-purple-600/20 blur-3xl"></div>
  <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
      <h2 class="text-2xl md:text-3xl font-bold text-indigo-200">
        <?= htmlspecialchars(t('welcome')) ?>, <?= htmlspecialchars($_SESSION['user_email']) ?>
      </h2>
      <div class="text-sm text-gray-300 mt-1">
        <?= htmlspecialchars(t('last_login')) ?>: <?= $_SESSION['last_login'] ?? '—' ?>
      </div>
    </div>
    <a href="settings.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold shadow">
      <i class="fas fa-cog"></i> <?= htmlspecialchars(t('open.settings')) ?>
    </a>
  </div>
</section>

<!-- Key stats -->
<section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
  <div class="p-5 rounded-xl bg-gray-800/70 border border-white/10 shadow">
    <div class="flex items-center justify-between">
      <div class="text-sm text-gray-400">Telegram</div>
      <i class="fab fa-telegram-plane text-indigo-300"></i>
    </div>
    <div class="mt-2 text-3xl font-bold text-indigo-200"><?= intval($userCount) ?></div>
    <div class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('users')) ?></div>
    <?php if ($botUsername): ?>
      <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="mt-3 inline-flex items-center gap-2 text-indigo-400 hover:text-indigo-300">
        @<?= htmlspecialchars($botUsername) ?> <i class="fas fa-external-link-alt text-xs"></i>
      </a>
    <?php endif; ?>
  </div>

  <div class="p-5 rounded-xl bg-gray-800/70 border border-white/10 shadow">
    <div class="flex items-center justify-between">
      <div class="text-sm text-gray-400">Facebook</div>
      <i class="fab fa-facebook-square text-indigo-300"></i>
    </div>
    <div class="mt-2 text-3xl font-bold text-indigo-200"><?= intval($fbConnectedCount) ?></div>
    <div class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('connected.pages')) ?></div>
  </div>

  <div class="p-5 rounded-xl bg-gray-800/70 border border-white/10 shadow">
    <div class="flex items-center justify-between">
      <div class="text-sm text-gray-400"><?= htmlspecialchars(t('training.progress')) ?></div>
      <i class="fas fa-spinner text-indigo-300"></i>
    </div>
    <div class="mt-2 text-3xl font-bold text-indigo-200"><?= $perc ?>%</div>
    <div class="mt-3 w-full h-3 rounded-full bg-gray-700">
      <div class="h-3 rounded-full bg-indigo-500" style="width: <?= $perc ?>%"></div>
    </div>
    <div class="text-xs text-gray-400 mt-1"><?php if ($progress && $progress['total']>0){ echo (int)$progress['done'].' / '.(int)$progress['total']; } else { echo '—'; } ?></div>
  </div>
</section>

<!-- Integrations & statuses -->
<section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
  <?php $expectedWebhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/botman/index.php'; ?>

  <div class="bg-gray-800/70 p-5 rounded-xl border border-white/10 shadow">
    <h3 class="text-lg font-semibold text-indigo-300 mb-2"><i class="fab fa-telegram-plane mr-2"></i><?= htmlspecialchars(t('telegram.bot')) ?></h3>
    <p class="text-sm mb-2">
      <?php if ($webhookStatus === 'ok'): ?>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-green-500/10 text-green-300 border border-green-500/20"><i class="fas fa-check-circle"></i> <?= htmlspecialchars(t('webhook.connected')) ?></span>
      <?php else: ?>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-red-500/10 text-red-300 border border-red-500/20"><i class="fas fa-times-circle"></i> <?= htmlspecialchars(t('webhook.not_connected')) ?></span>
      <?php endif; ?>
    </p>
    <?php if ($botUsername): ?>
      <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="inline-flex items-center gap-2 text-indigo-400 hover:text-indigo-300">
        @<?= htmlspecialchars($botUsername) ?> <i class="fas fa-external-link-alt text-xs"></i>
      </a>
    <?php endif; ?>
    <p class="text-xs text-gray-400 mt-3"><strong><?= htmlspecialchars(t('webhook.url')) ?>:</strong> <span class="font-mono break-all"><?= htmlspecialchars($expectedWebhookUrl) ?></span></p>
  </div>

  <?php if ($fbConnectedCount): ?>
  <div class="bg-gray-800/70 p-5 rounded-xl border border-white/10 shadow">
    <h3 class="text-lg font-semibold text-indigo-300 mb-2"><i class="fab fa-facebook-square mr-2"></i><?= htmlspecialchars(t('fb.pages')) ?></h3>
    <ul class="list-disc list-inside text-sm text-gray-300 space-y-1 max-h-44 overflow-auto pr-2">
      <?php foreach ($fbConnectedPages as $pg): ?>
        <li>
          <a class="text-indigo-400 hover:underline" href="<?= htmlspecialchars($pg['link'] ?? ('https://facebook.com/' . ($pg['id'] ?? '')), ENT_QUOTES) ?>" target="_blank" rel="noopener">
            <?= htmlspecialchars($pg['name'] ?? ('ID ' . ($pg['id'] ?? '')), ENT_QUOTES) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="bg-gray-800/70 p-5 rounded-xl border border-white/10 shadow">
    <h3 class="text-lg font-semibold text-indigo-300 mb-2"><i class="fas fa-heartbeat mr-2"></i><?= htmlspecialchars(t('system.health')) ?></h3>
    <?php
      $hasOpenAI = !empty($keys['openai_api_key']) || !empty($keys['openai_key']);
      $hasTg = !empty($keys['telegram_bot_token']);
      $hasFbApp = !empty($keys['facebook_app_id']);
      function badge($ok){
        return $ok
          ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-green-500/10 text-green-300 border border-green-500/20"><i class="fas fa-check-circle"></i> '.htmlspecialchars(t('present')).'</span>'
          : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-red-500/10 text-red-300 border border-red-500/20"><i class="fas fa-exclamation-triangle"></i> '.htmlspecialchars(t('missing')).'</span>';
      }
    ?>
    <ul class="text-sm text-gray-300 space-y-2">
      <li class="flex items-center justify-between"><span><?= htmlspecialchars(t('openai.key')) ?></span> <?= badge($hasOpenAI) ?></li>
      <li class="flex items-center justify-between"><span><?= htmlspecialchars(t('telegram.token')) ?></span> <?= badge($hasTg) ?></li>
      <li class="flex items-center justify-between"><span><?= htmlspecialchars(t('facebook.app_id')) ?></span> <?= badge($hasFbApp) ?></li>
      <li class="flex items-center justify-between"><span><?= htmlspecialchars(t('webhook.connected')) ?></span> <?= badge($webhookStatus === 'ok') ?></li>
    </ul>
  </div>
</section>

<!-- Quick actions -->
<section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <a href="training.php" class="group p-6 rounded-xl border border-white/10 bg-gradient-to-br from-indigo-900/30 to-indigo-800/10 hover:from-indigo-800/40 hover:to-indigo-700/20 transition shadow">
    <h3 class="font-semibold text-lg mb-1 text-indigo-300 flex items-center gap-2"><i class="fas fa-play-circle"></i> <?= htmlspecialchars(t('open.training')) ?></h3>
    <p class="text-sm text-gray-300"><?= htmlspecialchars(t('training.desc')) ?></p>
  </a>
  <a href="history.php" class="group p-6 rounded-xl border border-white/10 bg-gradient-to-br from-indigo-900/30 to-indigo-800/10 hover:from-indigo-800/40 hover:to-indigo-700/20 transition shadow">
    <h3 class="font-semibold text-lg mb-1 text-indigo-300 flex items-center gap-2"><i class="fas fa-history"></i> <?= htmlspecialchars(t('open.history')) ?></h3>
    <p class="text-sm text-gray-300"><?= htmlspecialchars(t('history.desc')) ?></p>
  </a>
  <a href="settings.php" class="group p-6 rounded-xl border border-white/10 bg-gradient-to-br from-indigo-900/30 to-indigo-800/10 hover:from-indigo-800/40 hover:to-indigo-700/20 transition shadow">
    <h3 class="font-semibold text-lg mb-1 text-indigo-300 flex items-center gap-2"><i class="fas fa-cog"></i> <?= htmlspecialchars(t('open.settings')) ?></h3>
    <p class="text-sm text-gray-300"><?= htmlspecialchars(t('settings.desc')) ?></p>
  </a>
</section>

<?php require_once __DIR__.'/../inc/footer.php'; ?>