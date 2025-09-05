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
    $msg     = htmlspecialchars($message);
    echo '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="UTF-8">';
    echo '<title>' . $title . '</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.0/dist/tailwind.min.css">';
    echo '</head><body class="p-8">';
    echo '<div class="max-w-2xl mx-auto">';
    echo '<h1 class="text-2xl font-bold mb-4">' . $heading . '</h1>';
    echo '<div class="p-4 rounded ' . ($success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') . '">' . $msg . '</div>';
    echo '<a href="/" class="mt-4 inline-block text-emerald-600">' . $return . '</a>';
    echo '</div></body></html>';
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
        $destPath = __DIR__ . '/' . $iterator->getSubPathName();
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
        respond_translated($labels, $lang, $labels[$lang]['success'] . $composerMsg);
} else {
        respond_translated($labels, $lang, $labels[$lang]['zip_error'], false);
}