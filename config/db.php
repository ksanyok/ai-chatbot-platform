<?php
declare(strict_types=1);

$db = null;

/**
 * Get a PDO database connection.
 * Uses environment variables for credentials with sensible defaults.
 * If a .env.php file exists in the project root, it will be loaded to
 * populate environment variables via putenv().
 *
 * If the connection fails in a web context, the user is redirected
 * to the installation wizard.
 *
 * @return PDO
 */
function db(): PDO
{
    global $db;
    if ($db) {
        return $db;
    }

    // Load environment variables from .env.php if present
    $envFile = __DIR__ . '/../.env.php';
    if (file_exists($envFile)) {
        require_once $envFile;
    }

    // Database credentials from environment variables or defaults
    $host    = getenv('DB_HOST') ?: 'localhost';
    $name    = getenv('DB_NAME') ?: 'chatbot';
    $user    = getenv('DB_USER') ?: 'dbuser';
    $pass    = getenv('DB_PASS') ?: 'dbpassword';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$name;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
        $db = new PDO($dsn, $user, $pass, $options);
    } catch (Throwable $e) {
        // Redirect to install wizard if DB connection fails in a web context
        if (php_sapi_name() !== 'cli') {
            header('Location: /install.php');
            exit;
        }
        throw $e;
    }

    // Ensure tables and schema exist or are updated
    ensureTables($db);

    return $db;
}

/**
 * Ensure all required database tables and schema exist. Creates tables
 * if they do not exist and adds columns if missing.
 *
 * @param PDO $db
 */
function ensureTables(PDO $db): void
{
    // Create bot_users table
    $db->exec("CREATE TABLE IF NOT EXISTS bot_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id VARCHAR(191) NOT NULL UNIQUE,
        chat_id VARCHAR(191) DEFAULT NULL,
        name VARCHAR(191) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create api_keys table
    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        api_key VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES bot_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create sites table
    $db->exec("CREATE TABLE IF NOT EXISTS sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(255) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create pages table
    $db->exec("CREATE TABLE IF NOT EXISTS pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        url VARCHAR(255) NOT NULL,
        content MEDIUMTEXT DEFAULT NULL,
        title VARCHAR(255) DEFAULT NULL,
        h1 VARCHAR(255) DEFAULT NULL,
        description VARCHAR(512) DEFAULT NULL,
        keywords VARCHAR(512) DEFAULT NULL,
        embed_tokens INT DEFAULT NULL,
        embed_cost DECIMAL(12,4) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (site_id) REFERENCES sites(id),
        UNIQUE KEY unique_page (site_id, url)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create trainings table
    $db->exec("CREATE TABLE IF NOT EXISTS trainings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        prompt LONGTEXT NOT NULL,
        cost DECIMAL(12,4) DEFAULT 0,
        total_cost DECIMAL(12,4) DEFAULT 0,
        processed_pages INT DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (site_id) REFERENCES sites(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create history table
    $db->exec("CREATE TABLE IF NOT EXISTS history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        request VARCHAR(1024) NOT NULL,
        answer LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_tokens INT DEFAULT NULL,
        total_tokens INT DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES bot_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create user_prefs table
    $db->exec("CREATE TABLE IF NOT EXISTS user_prefs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pref_key VARCHAR(255) NOT NULL,
        pref_value TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES bot_users(id),
        UNIQUE KEY unique_pref (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add missing columns to pages table
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN embed_tokens INT DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN embed_cost DECIMAL(12,4) DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already exist
    }

    // Add missing columns to trainings table
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN processed_pages INT DEFAULT 0");
    } catch (Throwable $e) {}
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN total_cost DECIMAL(12,4) DEFAULT 0");
    } catch (Throwable $e) {}
}
