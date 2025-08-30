<?php
// Simple update script for AI Chatbot Platform
// This script attempts to fetch the latest version of this repository from GitHub
// and extract it over the current installation. It should be executed by an
// administrator after a new version is detected.

include_once __DIR__ . '/inc/version.php';

function respond($message, $success = true) {
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Обновление</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.0/dist/tailwind.min.css"></head><body class="p-8">';
    echo '<div class="max-w-2xl mx-auto">';
    echo '<h1 class="text-2xl font-bold mb-4">Обновление платформы</h1>';
    echo '<div class="p-4 rounded ' . ($success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') . '">' . htmlspecialchars($message) . '</div>';
    echo '<a href="/" class="mt-4 inline-block text-emerald-600">Вернуться на главную</a>';
    echo '</div></body></html>';
    exit;
}

// Only allow updates if curl and zip extensions are loaded
if (!function_exists('curl_init') || !class_exists('ZipArchive')) {
    respond('Необходимы PHP-расширения cURL и ZipArchive для выполнения обновления.', false);
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
    respond('Не удалось загрузить обновление с GitHub. HTTP код: ' . $httpCode, false);
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
        respond('Не удалось определить корневую директорию в архиве.', false);
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
    respond('Обновление успешно установлено.');
} else {
    respond('Ошибка распаковки ZIP-архива.', false);
}