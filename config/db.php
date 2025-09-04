<?php
declare(strict_types=1);

/*
 * Database connection and schema management for the chatbot platform.
 *
 * This module exposes a single function `db()` that returns a PDO instance
 * connected to the MySQL database defined via environment variables or
 * sensible defaults. It also provides an `ensureTables()` helper that
 * creates all required tables and adds missing columns for backwards
 * compatibility. If the connection fails in a web context, the user is
 * redirected to the installation wizard.
 */

$db = null;

/**
 * Get a PDO database connection.
 *
 * Uses environment variables for credentials with sensible defaults. If
 * a `.env.php` file exists in the project root, it will be loaded to
 * populate environment variables via putenv().
 *
 * When the connection fails while handling a web request, the user is
 * redirected to `install.php` so they can configure the database.
 *
 * @return PDO
 */
function db(): PDO
{
    global $db;
    if ($db) {
        return $db;
    }

    // Load environment variables from .env if present (preferred) or legacy .env.php
    $rootDir = __DIR__ . '/..';
    $envFile = __DIR__ . '/../.env.php';
    if (file_exists($rootDir . '/.env')) {
        // Try using vlucas/phpdotenv if available
        if (class_exists('\Dotenv\Dotenv')) {
            try {
                \Dotenv\Dotenv::createImmutable($rootDir)->safeLoad();
            } catch (Throwable $e) {
                // ignore and continue — environment may already be set
            }
        } else {
            // Fallback: parse simple KEY=VALUE lines
            $lines = @file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) continue;
                    if (strpos($line, '=') === false) continue;
                    [$k, $v] = array_map('trim', explode('=', $line, 2));
                    if ($k !== '') putenv("$k=$v");
                }
            }
        }
    } elseif (file_exists($envFile)) {
        require_once $envFile;
    }

    // Database credentials from environment variables or defaults
    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: 'chatbot';
    $user = getenv('DB_USER') ?: 'dbuser';
    $pass = getenv('DB_PASS') ?: 'dbpassword';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$name;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
        $db = new PDO($dsn, $user, $pass, $options);
    } catch (Throwable $e) {
        // If explicit env flag requests redirect to install, do so. Otherwise throw to let caller handle it.
        $redirect = getenv('REDIRECT_TO_INSTALL') ?: getenv('DB_REDIRECT_TO_INSTALL') ?: false;
        if (!$redirect) {
            // In web context, rethrow so the front controller can show a sensible error instead of an installer.
            throw $e;
        }
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
 * if they do not exist and adds columns if missing. Also attempts to
 * upgrade older schemas to match current expectations.
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
    //
    // The `api_keys` table stores tokens or API credentials for external services on a per‑user basis.
    // Each record has a `name` identifying the type of key (e.g. 'telegram_bot_token'), a `value` storing
    // the actual token, timestamps for creation and update, and a foreign key linking back to the
    // corresponding row in `bot_users`.  A unique index on `(user_id, name)` prevents duplicate
    // entries for the same user and key name.
        $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        -- user_id is optional. When null, the key applies globally rather than to a specific bot_user.
        user_id INT DEFAULT NULL,
        name VARCHAR(255) NOT NULL,
        value VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES bot_users(id),
        UNIQUE KEY uniq_api_user_name (user_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate legacy api_keys schema
    // Older versions of this application created an `api_keys` table with an `api_key` column and
    // without `name`/`value` distinction.  The following statements attempt to bring the table up to
    // date by adding the `name` column, renaming `api_key` to `value`, adding the `updated_at` column
    // and the unique index.  Each statement is wrapped in a try/catch so that repeated executions
    // (e.g. subsequent calls or when columns already exist) do not throw an error.
    try {
        // Add name column if it doesn't exist; default to 'telegram_bot_token' for legacy rows
        $db->exec("ALTER TABLE api_keys ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT 'telegram_bot_token'");
    } catch (Throwable $e) {
        // Column may already exist; ignore
    }
    try {
        // Rename api_key to value if the old column exists
        $db->exec("ALTER TABLE api_keys CHANGE api_key value VARCHAR(255) NOT NULL");
    } catch (Throwable $e) {
        // Column may already be renamed or not exist; ignore
    }
    try {
        // Add updated_at column for modification timestamps
        $db->exec("ALTER TABLE api_keys ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (Throwable $e) {
        // Column may already exist; ignore
    }
    try {
        // Add unique constraint on (user_id, name) if it doesn't exist
        $db->exec("ALTER TABLE api_keys ADD CONSTRAINT uniq_api_user_name UNIQUE (user_id, name)");
    } catch (Throwable $e) {
        // Constraint may already exist; ignore
    }

    // Ensure user_id column is nullable for backwards compatibility.
    // Prior versions defined user_id as NOT NULL, which prevents inserting global keys.
    try {
        $db->exec("ALTER TABLE api_keys MODIFY user_id INT DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already be nullable or modification failed; ignore
    }

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
        content MEDIUMTEXT NOT NULL,
        title VARCHAR(255) DEFAULT NULL,
        h1 VARCHAR(255) DEFAULT NULL,
        description VARCHAR(512) DEFAULT NULL,
        keywords VARCHAR(512) DEFAULT NULL,
        embed_tokens INT DEFAULT NULL,
        embed_cost DECIMAL(12,4) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        last_modified DATETIME DEFAULT NULL,
        last_trained_at DATETIME DEFAULT NULL,
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
        total_pages INT DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        finished_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (site_id) REFERENCES sites(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate legacy trainings schema
    // Older versions lacked `processed_pages`, `total_pages`, `total_cost`, `status` and `finished_at` columns. Attempt to add them if missing.
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN processed_pages INT DEFAULT 0");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN total_pages INT DEFAULT 0");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN total_cost DECIMAL(12,4) DEFAULT 0");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN finished_at DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already exist
    }

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
    // Make user_id nullable to avoid FK failures on misaligned/legacy user ids.
    $db->exec("CREATE TABLE IF NOT EXISTS user_prefs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        pref VARCHAR(255) NOT NULL,
        value VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_pref (user_id, pref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure the foreign key references bot_users with ON DELETE SET NULL to make user_id optional
    try {
        // Drop existing FK if it exists (constraint names may differ across installs)
        $db->exec("ALTER TABLE user_prefs DROP FOREIGN KEY user_prefs_ibfk_1");
    } catch (Throwable $e) {
        // ignore if FK name doesn't exist
    }
    try {
        // Add a safe FK that sets user_id to NULL when the referenced user is removed
        $db->exec("ALTER TABLE user_prefs ADD CONSTRAINT user_prefs_ibfk_1 FOREIGN KEY (user_id) REFERENCES bot_users(id) ON DELETE SET NULL ON UPDATE CASCADE");
    } catch (Throwable $e) {
        // ignore errors (may already exist or bot_users missing during initial install)
    }

    // Attempt to rename pref_key/pref_value columns from older schema
    try {
        $db->exec("ALTER TABLE user_prefs CHANGE pref_key pref VARCHAR(255) NOT NULL, CHANGE pref_value value VARCHAR(255) NOT NULL");
    } catch (Throwable $e) {
        // If columns do not exist or are already renamed, ignore
    }

    // In case older schema used NOT NULL for user_id, make it nullable for compatibility
    try {
        $db->exec("ALTER TABLE user_prefs MODIFY user_id INT DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore
    }

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
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new'");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN last_modified DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN last_trained_at DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already exist
    }
    
    // Add missing columns to trainings table
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN total_cost DECIMAL(12,4) DEFAULT 0");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN processed_pages INT DEFAULT 0");
    } catch (Throwable $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN finished_at DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
        // Column may already exist
    }
}