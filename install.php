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
            // Redirect to home after successful installation
            header('Location: index.php?installed=1');
            exit;
        } catch (Exception $e) {
            $error = 'Ошибка подключения или установки: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка AI Chatbot Platform</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.0/dist/tailwind.min.css">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white shadow-md rounded-lg p-8 w-full max-w-md">
        <h1 class="text-2xl font-bold mb-4">Установка AI Chatbot Platform</h1>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Хост базы данных</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($dbHost ?? 'localhost') ?>" class="w-full border border-gray-300 rounded p-2">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Имя базы данных</label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($dbName ?? '') ?>" class="w-full border border-gray-300 rounded p-2" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Пользователь БД</label>
                <input type="text" name="db_user" value="<?= htmlspecialchars($dbUser ?? '') ?>" class="w-full border border-gray-300 rounded p-2" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Пароль БД</label>
                <input type="password" name="db_pass" class="w-full border border-gray-300 rounded p-2">
            </div>
            <hr class="my-4">
            <h2 class="text-xl font-semibold mb-2">Создание администратора</h2>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Логин администратора</label>
                <input type="text" name="admin_user" value="<?= htmlspecialchars($adminUser ?? '') ?>" class="w-full border border-gray-300 rounded p-2" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Пароль администратора</label>
                <input type="password" name="admin_pass" class="w-full border border-gray-300 rounded p-2" required>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Email администратора (необязательно)</label>
                <input type="email" name="admin_email" value="<?= htmlspecialchars($adminEmail ?? '') ?>" class="w-full border border-gray-300 rounded p-2">
            </div>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded">Установить</button>
        </form>
    </div>
</body>
</html>