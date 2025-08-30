# ai-chatbot-platform

This platform trains and integrates AI chatbots across multiple channels (Telegram, Facebook Messenger, WhatsApp, Instagram) and keeps itself up to date.

## Installation

1. **Upload the application files** to your web host. You can clone this repository or download a ZIP and extract it into your web directory.
2. **Create a MySQL database** and note the host, database name, username and password.
3. Access the application in your web browser. On first run, if no database connection is configured, you'll be redirected to the installation wizard (`install.php`).
4. **Complete the installation wizard** by entering your database credentials and creating an admin account. The installer writes a `.env.php` file with your credentials and creates all required tables automatically.
5. After installation, log in with your admin account to start managing sites, pages, API keys and training sessions.

## Updating

The application includes an automatic update mechanism:

- The footer displays the current version and checks the latest version available in this repository. If a newer version is found, you'll see an "Update available" link.
- Clicking the update link runs `update.php`, which downloads the latest version from GitHub and installs it over your existing files (excluding your `.env.php` and the `vendor` directory). This script requires PHP extensions such as cURL and ZipArchive and appropriate file permissions.

You can also run `update.php` manually to upgrade when necessary.

## Configuration

Most configuration is handled through environment variables. After installation, your `.env.php` file sets the following variables:

```php
<?php
putenv('DB_HOST=localhost');
putenv('DB_NAME=chatbot');
putenv('DB_USER=dbuser');
putenv('DB_PASS=dbpassword');
```

If you need to change your database credentials later, edit `.env.php` accordingly.

## Features

- **Multi‑site training**: add websites to crawl, extract content from pages and train your chatbot on them.
- **Multi‑channel integration**: connect to Telegram, Facebook Messenger, WhatsApp and Instagram via API keys.
- **History tracking** of conversations and user preferences.
- **Admin interface** to manage sites, pages, trainings and user accounts.
- **Auto‑installer and auto‑update system** for easy deployment and maintenance.
