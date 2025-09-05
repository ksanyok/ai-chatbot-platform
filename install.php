<?php
// Installation script for AI chatbot platform
// This script guides the user through configuring the database and creating
// the initial admin account. It writes credentials to a local .env.php file
// so that the rest of the application can read them via putenv().  After
// installation completes, the user is redirected to the main interface.

// If the application is already installed, redirect immediately
if (file_exists(__DIR__ . '/.env.php')) {
    // Nothing to do; configuration exists.  Forward the user to the root.
    header('Location: index.php');
    exit;
}

// Initialize variables for the form
$error = '';
// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = trim($_POST['admin_pass'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');

    // Basic validation
    if ($dbName === '' || $dbUser === '' || $adminUser === '' || $adminPass === '') {
        $error = 'Пожалуйста, заполните все обязательные поля.';
    } else {
        try {
            // Attempt database connection
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Include database helper and create tables
            require_once __DIR__ . '/config/db.php';
            // Use ensureTables() with the created PDO instance
            ensureTables($pdo);
            // Create admins table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            // Insert admin user
            $stmt = $pdo->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
            $stmt->execute([
                $adminUser,
                password_hash($adminPass, PASSWORD_BCRYPT),
                $adminEmail
            ]);
            // Write credentials to .env.php so config/db.php can load them
            $envContent = "<?php\n"
                . "putenv('DB_HOST=' . " . var_export($dbHost, true) . ");\n"
                . "putenv('DB_NAME=' . " . var_export($dbName, true) . ");\n"
                . "putenv('DB_USER=' . " . var_export($dbUser, true) . ");\n"
                . "putenv('DB_PASS=' . " . var_export($dbPass, true) . ");\n";
            file_put_contents(__DIR__ . '/.env.php', $envContent);

            // AFTER installation: ensure composer dependencies are available
            function install_log($msg) { @file_put_contents(__DIR__.'/install.log', '['.date('c').'] '.$msg.PHP_EOL, FILE_APPEND); }
            function run_command($cmd, &$output=null, &$exitCode=null) {
                $output = null; $exitCode = null;
                if (function_exists('proc_open')) {
                    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
                    $proc = @proc_open($cmd, $descriptors, $pipes, __DIR__);
                    if (is_resource($proc)) {
                        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                        $exitCode = proc_close($proc);
                        $output = trim($out . "\n" . $err);
                        return true;
                    }
                }
                // fallback
                if (function_exists('shell_exec')) {
                    $out = @shell_exec($cmd . ' 2>&1');
                    $output = $out;
                    $exitCode = 0;
                    return true;
                }
                return false;
            }
            function ensure_composer_and_install() {
                $proj = __DIR__;
                $vendor = $proj . '/vendor/autoload.php';
                if (file_exists($vendor)) return [true, 'already_present'];
                $log = '';
                // 1) try system composer
                $cmd = 'composer --version';
                $out = null; $ec = null;
                if (run_command($cmd, $out, $ec)) {
                    install_log('composer check: ' . ($out ?: 'no output'));
                    // Run composer install
                    $cmd2 = 'composer install --no-dev --no-interaction --optimize-autoloader';
                    run_command($cmd2, $out, $ec);
                    install_log('composer install result: ' . ($out ?: '') . ' exit=' . intval($ec));
                    if (file_exists($vendor)) return [true, 'composer_system'];
                }
                // 2) try to download composer.phar
                $phar = $proj . '/composer.phar';
                $downloaded = false;
                // prefer curl
                if (function_exists('curl_init')) {
                    $ch = curl_init('https://getcomposer.org/download/latest-stable/composer.phar');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    $data = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($http === 200 && $data && strlen($data) > 100000) { file_put_contents($phar, $data); $downloaded = true; }
                } elseif (ini_get('allow_url_fopen')) {
                    $data = @file_get_contents('https://getcomposer.org/download/latest-stable/composer.phar');
                    if ($data && strlen($data) > 100000) { file_put_contents($phar, $data); $downloaded = true; }
                }
                if ($downloaded) {
                    @chmod($phar, 0755);
                    install_log('composer.phar downloaded');
                    $cmd3 = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($phar) . ' install --no-dev --no-interaction --optimize-autoloader';
                    run_command($cmd3, $out, $ec);
                    install_log('composer.phar install result: ' . ($out ?: '') . ' exit=' . intval($ec));
                    if (file_exists($vendor)) return [true, 'composer_phar'];
                } else {
                    install_log('composer.phar download failed or not allowed');
                }
                return [false, 'failed'];
            }

            list($okComposer, $composerMode) = ensure_composer_and_install();
            if (!$okComposer) {
                $error = "Установка завершена, но зависимости (vendor/) не установлены. Выполните в корне проекта: `composer install` или загрузите composer.phar. Подробности в install.log.";
                install_log('Composer install failed during web install');
                // don't redirect; show error to user
            } else {
                install_log('Composer OK via: ' . $composerMode);
                // Redirect to home after successful installation
                header('Location: index.php?installed=1');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Ошибка подключения или установки: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Установка • Chatbot Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','Segoe UI','Roboto','Helvetica Neue','Arial','Noto Sans','Apple Color Emoji','Segoe UI Emoji'] }
          }
        }
      };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
      .glass{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 30px rgba(0,0,0,.3)}
    </style>
</head>
<body class="min-h-screen text-gray-100 bg-gradient-to-br from-slate-900 via-slate-950 to-slate-900 font-sans">
  <div class="min-h-screen grid md:grid-cols-2">
    <!-- Left promo side -->
    <section class="hidden md:flex relative items-center justify-center p-12 overflow-hidden">
      <div class="absolute -top-20 -left-20 h-96 w-96 rounded-full bg-indigo-600/30 blur-3xl"></div>
      <div class="absolute -bottom-24 -right-24 h-[28rem] w-[28rem] rounded-full bg-purple-600/20 blur-3xl"></div>
      <div class="relative z-10 max-w-md">
        <h1 class="text-3xl font-bold mb-4">Chatbot Admin</h1>
        <p class="text-slate-300 text-lg leading-relaxed">AI‑панель для обучения бота, истории диалогов и интеграций с Telegram, Facebook, Instagram и WhatsApp.</p>
      </div>
    </section>
    <!-- Right installation side -->
    <section class="flex items-center justify-center p-6 md:p-12">
      <form method="post" class="glass rounded-2xl p-8 w-full max-w-md">
        <h2 class="text-2xl font-bold mb-2">Установка</h2>
        <p class="text-sm text-slate-300 mb-6">Введите параметры базы данных и создайте учётную запись администратора.</p>
        <?php if ($error): ?>
          <div class="mb-4 text-red-400 text-sm flex items-start space-x-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.485 0l6.518 11.595c.75 1.336-.213 3.006-1.742 3.006H3.48c-1.53 0-2.492-1.67-1.743-3.006L8.257 3.1zM11 14a1 1 0 11-2 0 1 1 0 012 0zM10 8a1 1 0 00-1 1v3a1 1 0 102 0V9a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>
        <!-- Database fields -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-1">Хост базы данных</label>
          <input type="text" name="db_host" value="<?= htmlspecialchars($dbHost ?? 'localhost') ?>" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-1">Имя базы данных</label>
          <input type="text" name="db_name" value="<?= htmlspecialchars($dbName ?? '') ?>" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-1">Пользователь БД</label>
          <input type="text" name="db_user" value="<?= htmlspecialchars($dbUser ?? '') ?>" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-1">Пароль БД</label>
          <input type="password" name="db_pass" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <hr class="my-4 border-slate-700">
        <h3 class="text-xl font-semibold mb-2">Администратор</h3>
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-1">Имя пользователя</label>
          <input type="text" name="admin_user" value="<?= htmlspecialchars($adminUser ?? '') ?>" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-1">Пароль</label>
          <input type="password" name="admin_pass" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium text-slate-200 mb-1">Email (необязательно)</label>
          <input type="email" name="admin_email" value="<?= htmlspecialchars($adminEmail ?? '') ?>" class="w-full bg-slate-800/70 border border-white/10 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded">Установить</button>
      </form>
    </section>
  </div>
</body>
</html>