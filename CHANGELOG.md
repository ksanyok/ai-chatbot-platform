# Changelog

All notable changes to this project will be documented in this file.

## [0.3.0] – 2025-08-30

### Added
- Added `install.php` wizard for initial setup: collects database credentials and creates an admin user.
- Added `.env.php` support with environment variables for database connection.
- Added automatic database migration: `ensureTables()` now creates missing tables and columns.
- Added `update.php` script for in-place updates from the repository.
- Added `bootstrap/bootstrap.php` standalone installer that downloads the latest version from the repository.
- Added version checking in `inc/footer.php` using `inc/version.php` and remote version detection via GitHub API.
- Added `inc/version.php` to define `APP_VERSION`.

### Changed
- Refactored `config/db.php` to load credentials from `.env.php` and to redirect to the installer when connection fails.
- Updated footer display to show dynamic version and provide update link when a newer version is available.
- Updated `README.md` with installation, configuration and update instructions.

### Removed
- Removed hard‑coded database credentials from code.

## [0.2.0] – 2025-08-29

### Added
- Initial release with core chatbot training functionality, integration with Telegram, Facebook Messenger, WhatsApp and Instagram.
- Basic admin panel with dashboard, history and settings pages.
