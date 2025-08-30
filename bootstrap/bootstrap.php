<?php
declare(strict_types=1);

// Bootstrap installer for ai‑chatbot‑platform
//
// Upload only this file (in the bootstrap directory) to your server.
// It downloads the latest version of the project from your private GitHub
// repository, extracts it into the parent directory, and then redirects
// the user to the main installer.  If the repository is private, you
// must provide a GitHub personal access token with at least `repo` scope
// via the `GITHUB_TOKEN` environment variable to authenticate the API
// request.

$owner  = 'ksanyok';
$repo   = 'ai-chatbot-platform';
$branch = 'main';

// Compose the download URL for the specified branch. Since this
// repository is public, we can use codeload.github.com to download the ZIP
// archive without authentication. This endpoint returns a raw ZIP of the
// repository.
$downloadUrl = "https://codeload.github.com/$owner/$repo/zip/refs/heads/$branch";

// Temporary paths for the downloaded ZIP archive and extraction directory
$tempZip  = sys_get_temp_dir() . '/chatbot-install-' . uniqid() . '.zip';
$tempDir  = sys_get_temp_dir() . '/chatbot-install-' . uniqid();

// Ensure required PHP extensions are available
if (!function_exists('curl_init')) {
    exit('cURL extension is required to download installation files.');
}
if (!class_exists('ZipArchive')) {
    exit('ZipArchive extension is required to unpack installation files.');
}

try {
    // Prepare cURL headers. Always send a User-Agent; GitHub requires it.
    // No Authorization header is needed because the repository is public.
    $headers = ['User-Agent: ai-chatbot-installer'];

    // Download the ZIP archive from GitHub. Use a cURL session so we can
    // inspect the HTTP status code. Since the repository is public, we
    // expect a 200 response without authentication.
    $fp = fopen($tempZip, 'w');
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ai-chatbot-installer');
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    // If we received an HTTP error or cURL error, clean up and abort with
    // an informative message. When accessing a private repository without
    // authentication, GitHub returns 404. For such cases we hint the user
    // to define the GITHUB_TOKEN environment variable.
    if ($httpCode !== 200 || !empty($curlError)) {
        // Remove the incomplete ZIP file
        if (file_exists($tempZip)) {
            unlink($tempZip);
        }
        $message = 'Failed to download archive (HTTP ' . $httpCode . ').';
        if (!empty($curlError)) {
            $message .= ' cURL error: ' . $curlError . '.';
        }
        if ($httpCode === 404) {
            $message .= ' The repository may be private or the branch does not exist.';
            $message .= ' If this is a private repository, set the GITHUB_TOKEN environment variable with a valid GitHub personal access token.';
        }
        throw new Exception($message);
    }

    // Open and extract the ZIP archive
    $zip = new ZipArchive();
    if ($zip->open($tempZip) !== true) {
        throw new Exception('Failed to open downloaded archive.');
    }
    $zip->extractTo($tempDir);
    $zip->close();

    // Determine the root directory inside the extracted archive
    $entries = glob($tempDir . '/*', GLOB_ONLYDIR);
    if (!$entries) {
        throw new Exception('No directory found in extracted archive.');
    }
    $extractedRoot = $entries[0];

    // Determine where to copy the extracted files. If this script is
    // located inside a "bootstrap" directory, copy to its parent directory;
    // otherwise copy into the current directory. This makes it safe to
    // upload the bootstrap installer either inside a "bootstrap" folder or
    // directly in the web root.
    $targetRoot = __DIR__;
    if (basename(__DIR__) === 'bootstrap') {
        $targetRoot = dirname(__DIR__);
    }
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
            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0775, true);
            }
            copy($item->getPathname(), $targetPath);
        }
    }

    // Remove the temporary ZIP file
    unlink($tempZip);
    // Recursively delete the extracted temporary directory
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tempDir);

    // Redirect to the main installer (install.php) if running in a web context.
    // Compute the absolute path to install.php based on where files were copied.
    $installPath = $targetRoot . '/install.php';
    if (php_sapi_name() !== 'cli' && is_file($installPath)) {
        // Determine the correct relative URL for redirection. If the bootstrap
        // script sits inside a "bootstrap" subdirectory, go up one level to
        // reach install.php. Otherwise redirect to install.php in the same
        // directory.
        $redirectPath = (basename(__DIR__) === 'bootstrap') ? '../install.php' : 'install.php';
        header('Location: ' . $redirectPath);
        exit;
    }

    // Output a message for CLI or if redirect is not possible
    echo "Files downloaded successfully. Run install.php to continue installation.";

} catch (Throwable $e) {
    // Handle any errors during download or extraction gracefully
    echo 'Error during installation: ' . htmlspecialchars($e->getMessage());
}
