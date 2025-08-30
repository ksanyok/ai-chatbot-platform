<?php
declare(strict_types=1);

$db = null;

/**
 * Get a PDO database connection.
 * Uses environment variables for credentials with sensible defaults.
 *
 * @return PDO
 */
function db(): PDO
{
    global $db;
    if ($db) {
        return $db;
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

    $db = new PDO($dsn, $user, $pass, $options);

    // Ensure tables and schema
    ensureTables($db);

    return $db;
}

/**
 * Ensure required tables exist and columns are up to date.
 *
 * @param PDO $db
 */
function ensureTables(PDO $db): void
{
    // Create bot_users table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS bot_users (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            user_id VARCHAR(255) NOT NULL,\n            provider ENUM('telegram','facebook','whatsapp','instagram') NOT NULL,\n            api_key_id INT DEFAULT NULL,\n            language VARCHAR(10) DEFAULT 'ru',\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Create api_keys table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS api_keys (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            service ENUM('telegram','facebook','whatsapp','instagram') NOT NULL,\n            api_key VARCHAR(255) NOT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Create sites table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS sites (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            url VARCHAR(255) NOT NULL,\n            name VARCHAR(255) DEFAULT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Create pages table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS pages (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            site_id INT NOT NULL,\n            url VARCHAR(255) NOT NULL,\n            title VARCHAR(255) DEFAULT NULL,\n            text LONGTEXT,\n            embed_cost DECIMAL(10,4) DEFAULT NULL,\n            embed_tokens INT DEFAULT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Create trainings table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS trainings (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            site_id INT NOT NULL,\n            status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',\n            processed_pages INT DEFAULT 0,\n            total_cost DECIMAL(10,4) DEFAULT 0.0,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Create history table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS history (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            user_id VARCHAR(255) NOT NULL,\n            role ENUM('user','assistant') NOT NULL,\n            content LONGTEXT,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Create user_prefs table
    $db->exec("\n        CREATE TABLE IF NOT EXISTS user_prefs (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            user_id VARCHAR(255) NOT NULL,\n            name VARCHAR(255) NOT NULL,\n            value LONGTEXT,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY user_pref_unique (user_id, name)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    // Add missing columns if necessary
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN embed_cost DECIMAL(10,4) DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore if column exists
    }
    try {
        $db->exec("ALTER TABLE pages ADD COLUMN embed_tokens INT DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore if column exists
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN processed_pages INT DEFAULT 0");
    } catch (Throwable $e) {
        // ignore if column exists
    }
    try {
        $db->exec("ALTER TABLE trainings ADD COLUMN total_cost DECIMAL(10,4) DEFAULT 0.0");
    } catch (Throwable $e) {
        // ignore if column exists
    }
}
