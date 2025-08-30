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

// Compose the GitHub API zipball URL for the specified branch. Using
// api.github.com allows both public and private repositories to be
// downloaded. When the repository is private, authentication headers
// will be added below.
$downloadUrl = "https://api.github.com/repos/$owner/$repo/zipball/$branch";

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
    // Prepare cURL headers. Always send a User‑Agent as GitHub API
    // requires it. Include Authorization header when a token is available.
    $token   = getenv('GITHUB_TOKEN') ?: '';
    $headers = ['User-Agent: ai-chatbot-installer'];
    if (!empty($token)) {
        $headers[] = 'Authorization: token ' . $token;
    }

    // Download the ZIP archive from GitHub. Use a cURL session so we can
    // inspect the HTTP status code. If the request fails (e.g. because the
    // repository is private and no token was provided), we throw a clear
    // exception telling the user what to do.
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

    // Copy extracted files to the parent directory of this script
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

    // Redirect to the main installer (install.php) if running in a web context
    $installPath = dirname(__DIR__) . '/install.php';
    if (php_sapi_name() !== 'cli' && is_file($installPath)) {
        header('Location: ../install.php');
        exit;
    }

    // Output a message for CLI or if redirect is not possible
    echo "Files downloaded successfully. Run install.php to continue installation.";

} catch (Throwable $e) {
    // Handle any errors during download or extraction gracefully
    echo 'Error during installation: ' . htmlspecialchars($e->getMessage());
}
