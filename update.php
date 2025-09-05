<?php
// Simple update script for AI Chatbot Platform
// This script attempts to fetch the latest version of this repository from GitHub
// and extract it over the current installation. It should be executed by an
// administrator after a new version is detected.

include_once __DIR__ . '/inc/version.php';

// Start session so we can access the user's preferred UI language (ui_lang)
// and maintain authentication state. Without starting the session here the
// update script would always fall back to the default language and require
// re‑authentication on some hosts.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine current UI language from session, defaulting to Russian.  The
// installer and admin interface persist the selected language in
// $_SESSION['ui_lang'], so reuse it here.  Supported values are 'en' and 'ru'.
$lang = isset($_SESSION['ui_lang']) && in_array($_SESSION['ui_lang'], ['en','ru'], true)
    ? $_SESSION['ui_lang']
    : 'ru';

// Simple translation dictionary for the updater.  We define labels for the two
// supported languages.  If new languages are added in the future, extend
// this array accordingly.
$labels = [
    'ru' => [
        'title' => 'Обновление платформы',
        'heading' => 'Обновление платформы',
        'return' => 'Вернуться на главную',
        'need_ext' => 'Необходимы PHP‑расширения cURL и ZipArchive для выполнения обновления.',
        'download_fail' => 'Не удалось загрузить обновление с GitHub. HTTP код: ',
        'extract_root_fail' => 'Не удалось определить корневую директорию в архиве.',
        'zip_error' => 'Ошибка распаковки ZIP‑архива.',
        'success' => 'Обновление успешно установлено.',
    ],
    'en' => [
        'title' => 'Platform update',
        'heading' => 'Platform update',
        'return' => 'Return to home',
        'need_ext' => 'PHP extensions cURL and ZipArchive are required to perform the update.',
        'download_fail' => 'Failed to download update from GitHub. HTTP status: ',
        'extract_root_fail' => 'Could not determine root directory in archive.',
        'zip_error' => 'Error extracting ZIP archive.',
        'success' => 'Update successfully installed.',
    ],
];

// Helper function to send an HTML response and terminate execution.  It uses
// translated labels defined above and respects the selected UI language.
function respond_translated(array $labels, string $lang, string $message, bool $success = true): void {
    $title   = htmlspecialchars($labels[$lang]['title']);
    $heading = htmlspecialchars($labels[$lang]['heading']);
    $return  = htmlspecialchars($labels[$lang]['return']);
    $msg     = $message; // allow some HTML/newlines in message; we'll escape when placing into text nodes

    // Try to determine local app version and last update timestamp
    $localVer = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
    $lastUpdateHuman = '—';
    $lastFile = __DIR__ . '/data/last_update.txt';
    if (file_exists($lastFile)) {
        $iso = trim(@file_get_contents($lastFile));
        if ($iso) {
            try {
                $dt = new DateTime($iso);
                if ($lang === 'ru') {
                    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
                    $lastUpdateHuman = $dt->format('j') . ' ' . $months[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
                } else {
                    $lastUpdateHuman = $dt->format('F j, Y');
                }
            } catch (Exception $e) { /* ignore */ }
        }
    }

    echo '<!DOCTYPE html><html lang="' . htmlspecialchars($lang) . '"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $title . '</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.0/dist/tailwind.min.css">';
    echo '</head><body class="bg-slate-900 text-slate-100">';
    echo '<div class="min-h-screen flex items-center justify-center p-6">';
    echo '<div class="max-w-2xl w-full">';
    echo '<div class="rounded-2xl bg-gradient-to-br from-slate-800/60 to-slate-900/70 border border-white/6 p-6 shadow-xl">';
    echo '<div class="flex items-center gap-4">';
    echo '<div class="w-12 h-12 rounded-xl bg-emerald-500/20 grid place-items-center text-emerald-300 font-bold">BRS</div>';
    echo '<div>';
    echo '<h1 class="text-xl font-semibold">' . $heading . '</h1>';
    echo '<p class="text-sm text-slate-300">' . htmlspecialchars($title) . '</p>';
    echo '</div></div>';

    echo '<div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">';
    echo '<div class="col-span-2">';
    echo '<div class="p-4 rounded bg-slate-800/60 border border-white/4">';
    echo '<p class="text-sm text-slate-300 mb-2">' . htmlspecialchars(strip_tags($msg)) . '</p>';
    echo '</div></div>';
    echo '<div class="p-4 rounded bg-slate-800/40 border border-white/4">';
    echo '<div class="text-xs text-slate-400">' . htmlspecialchars($labels[$lang]['title']) . '</div>';
    echo '<div class="mt-2 text-sm">v' . htmlspecialchars($localVer) . '</div>';
    echo '<div class="mt-3 text-xs text-slate-400">' . htmlspecialchars($labels[$lang]['return']) . '</div>';
    echo '<div class="mt-2 text-sm">' . htmlspecialchars($lastUpdateHuman) . '</div>';
    echo '</div></div>';

    echo '<div class="mt-6 flex items-center justify-between">';
    echo '<a href="/" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-500/20 text-emerald-200 border border-emerald-400/10 hover:bg-emerald-500/30">' . $return . '</a>';
    echo '<a href="/update.log" class="text-xs text-slate-400 hover:underline">View update.log</a>';
    echo '</div>';

    echo '</div></div></div></body></html>';
    exit;
}

// Legacy responder retained for backward compatibility.  Redirect all uses
// to the new respond_translated() helper defined above.
function respond($message, $success = true) {
    global $labels, $lang;
    respond_translated($labels, $lang, $message, $success);
}

// Only allow updates if curl and zip extensions are loaded
if (!function_exists('curl_init') || !class_exists('ZipArchive')) {
    respond_translated($labels, $lang, $labels[$lang]['need_ext'], false);
}

// URL to download the latest zip archive from GitHub main branch
$repoOwner = 'ksanyok';
$repoName = 'ai-chatbot-platform';
$zipUrl = "https://github.com/{$repoOwner}/{$repoName}/archive/refs/heads/main.zip";
$tmpDir = sys_get_temp_dir();
$tmpZip = $tmpDir . '/ai_chatbot_update_' . uniqid() . '.zip';

// Download the zip file using cURL
$ch = curl_init($zipUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'AI Chatbot Platform Updater');
$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== 200 || !$data) {
        respond_translated($labels, $lang, $labels[$lang]['download_fail'] . $httpCode, false);
    }

file_put_contents($tmpZip, $data);

// Extract the zip archive
$zip = new ZipArchive();
if ($zip->open($tmpZip) === true) {
    // The zip contains a top-level directory like ai-chatbot-platform-main
    $extractedDir = $tmpDir . '/ai_chatbot_extract_' . uniqid();
    mkdir($extractedDir);
    $zip->extractTo($extractedDir);
    $zip->close();
    // Determine extracted root dir
    $entries = scandir($extractedDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $rootPath = $extractedDir . '/' . $entry;
        if (is_dir($rootPath)) {
            $sourceDir = $rootPath;
            break;
        }
    }
        if (!isset($sourceDir)) {
            respond_translated($labels, $lang, $labels[$lang]['extract_root_fail'], false);
        }
    // Copy files to current directory except vendor and .env.php to preserve environment
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        // Build destination path by computing relative path from the extracted source dir
        $sourcePath = $item->getPathname();
        $relPath = ltrim(substr($sourcePath, strlen($sourceDir)), DIRECTORY_SEPARATOR);
        if ($relPath === false) { $relPath = $item->getFilename(); }
        $destPath = __DIR__ . '/' . $relPath;
        // Skip vendor directory and environment file to avoid overwriting dependencies and config
        if (strpos($destPath, '/vendor/') !== false || basename($destPath) === '.env.php') {
            continue;
        }
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
        } else {
            // Copy file
            copy($item->getRealPath(), $destPath);
        }
    }

    // Attempt to ensure composer dependencies are present after update
    function update_log($msg) { @file_put_contents(__DIR__.'/update.log', '['.date('c').'] '.$msg.PHP_EOL, FILE_APPEND); }
    function run_command_for_update($cmd, &$output=null, &$exitCode=null) {
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
        if (function_exists('shell_exec')) {
            $out = @shell_exec($cmd . ' 2>&1');
            $output = $out;
            $exitCode = 0;
            return true;
        }
        return false;
    }
    function ensure_composer_and_install_update() {
        $proj = __DIR__;
        $vendor = $proj . '/vendor/autoload.php';
        if (file_exists($vendor)) return [true, 'already_present'];
        $out = null; $ec = null;
        // Try system composer
        if (run_command_for_update('composer --version', $out, $ec)) {
            update_log('composer check: ' . ($out ?: 'no output'));
            run_command_for_update('composer install --no-dev --no-interaction --optimize-autoloader', $out, $ec);
            update_log('composer install result: ' . ($out ?: '') . ' exit=' . intval($ec));
            if (file_exists($vendor)) return [true, 'composer_system'];
        }
        // Try download composer.phar
        $phar = $proj . '/composer.phar';
        $downloaded = false;
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
            update_log('composer.phar downloaded');
            $cmd3 = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($phar) . ' install --no-dev --no-interaction --optimize-autoloader';
            run_command_for_update($cmd3, $out, $ec);
            update_log('composer.phar install result: ' . ($out ?: '') . ' exit=' . intval($ec));
            if (file_exists($vendor)) return [true, 'composer_phar'];
        } else {
            update_log('composer.phar download failed or not allowed');
        }
        return [false, 'failed'];
    }

    list($okComposer, $composerMode) = ensure_composer_and_install_update();
    if ($okComposer) {
        update_log('Composer OK via: ' . $composerMode);
        $composerMsg = "\nComposer: dependencies installed via {$composerMode}.";
    } else {
        update_log('Composer install failed during update');
        $composerMsg = "\nComposer: dependencies not installed. Run `composer install` in project root or provide composer.phar. See update.log for details.";
    }

    // Cleanup temp files
    unlink($tmpZip);
    // Remove extracted data
    function rrmdir($dir) {
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) rrmdir($path); else unlink($path);
        }
        rmdir($dir);
    }
        rrmdir($extractedDir);

        // Record last update timestamp for footer display
        try {
            $dataDir = __DIR__ . '/data';
            if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
            $lastFile = $dataDir . '/last_update.txt';
            @file_put_contents($lastFile, date('c'));
            @file_put_contents(__DIR__.'/update.log', '['.date('c').'] last_update.txt updated'.PHP_EOL, FILE_APPEND);
        } catch (Exception $e) { /* non-fatal */ }

        respond_translated($labels, $lang, $labels[$lang]['success'] . $composerMsg);
} else {
        respond_translated($labels, $lang, $labels[$lang]['zip_error'], false);
}