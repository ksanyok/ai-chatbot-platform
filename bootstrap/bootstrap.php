<?php
declare(strict_types=1);

// Bootstrap installer for ai-chatbot-platform
// Upload only this file (in the bootstrap directory) to your server.
// It downloads the latest version of the project from GitHub, extracts
// it into the parent directory, and then redirects to the main installer.

$owner  = 'ksanyok';
$repo   = 'ai-chatbot-platform';
$branch = 'main';

$downloadUrl = "https://codeload.github.com/$owner/$repo/zip/refs/heads/$branch";
$tempZip = sys_get_temp_dir() . '/chatbot-install-' . uniqid() . '.zip';
$tempDir = sys_get_temp_dir() . '/chatbot-install-' . uniqid();

if (!function_exists('curl_init')) {
    exit('cURL extension is required to download installation files.');
}
if (!class_exists('ZipArchive')) {
    exit('ZipArchive extension is required to unpack installation files.');
}

try {
    // Download ZIP
    $fp = fopen($tempZip, 'w');
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    // Extract ZIP
    $zip = new ZipArchive();
    if ($zip->open($tempZip) !== true) {
        throw new Exception('Failed to open downloaded archive.');
    }
    $zip->extractTo($tempDir);
    $zip->close();

    // Determine extracted directory (e.g., ai-chatbot-platform-main)
    $entries = glob($tempDir . '/*', GLOB_ONLYDIR);
    if (!$entries) {
        throw new Exception('No directory found in extracted archive.');
    }
    $extractedRoot = $entries[0];

    // Copy files from extracted root to parent directory of this script
    $targetRoot = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0775, true);
            }
        } else {
            // Ensure destination directory exists
            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0775, true);
            }
            copy($item->getPathname(), $targetPath);
        }
    }

    // Clean up temp files
    unlink($tempZip);
    // Recursively delete extracted directory
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tempDir);

    // Redirect to main installer
    $installPath = dirname(__DIR__) . '/install.php';
    if (php_sapi_name() !== 'cli' && is_file($installPath)) {
        header('Location: ../install.php');
        exit;
    }

    echo "Files downloaded successfully. Run install.php to continue installation.";
} catch (Throwable $e) {
    echo 'Error during installation: ' . htmlspecialchars($e->getMessage());
}
