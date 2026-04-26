# Syncarent Wiki Starter

Public-facing wiki built with PHP and Bootstrap, protected behind a password gate and backed by MySQL for releases, features, help docs, and admin auth.

## Quick Start

1. From the project root, run:
   ```bash
   php -S localhost:8000
   ```
2. Open `http://localhost:8000`.
3. Enter the site password:
   - `Password123`

## How It Works

- The site entrypoint is `index.php`.
- Site settings are loaded from `.env`.
- Database setup runs automatically on page load from `database/schema.sql`.
- The entire wiki is protected by an env-driven password (`WIKI_ACCESS_PASSWORD`).
- All wiki pages live inside `content/`.
- The left sidebar navigation is built automatically from folder + file structure in `content/`.
- Each folder becomes a section in the sidebar.

## Environment

- `.env` is included for starter defaults.
- `.env.example` can be copied for new environments.
- Available values:
  - `WIKI_SITE_NAME`
  - `WIKI_SITE_TAGLINE`
  - `WIKI_LOGO_URL`
  - `WIKI_ACCESS_PASSWORD`
  - `WIKI_DB_HOST`
  - `WIKI_DB_PORT`
  - `WIKI_DB_NAME`
  - `WIKI_DB_USER`
  - `WIKI_DB_PASS`
  - `WIKI_ADMIN_DEFAULT_EMAIL`
  - `WIKI_ADMIN_DEFAULT_NAME`
  - `WIKI_ADMIN_DEFAULT_PASSWORD`
  - `WIKI_ADMIN_UPLOAD_MAX_MB`

## Apache `.htaccess` Environment Values

- `.htaccess` now includes example `SetEnv` values for:
  - `WIKI_DB_HOST`
  - `WIKI_DB_PORT`
  - `WIKI_DB_NAME`
  - `WIKI_DB_USER`
  - `WIKI_DB_PASS`
- Replace those examples with your real credentials when running under Apache.
- `.sql` files are blocked from direct web access.

## Add Pages

1. Add a new `.php` file under `content/<section>/`.
2. The filename becomes the page URL slug and default navigation label.
3. Start the file with the access guard:
   ```php
   <?php
   if (!defined('WIKI_BOOTSTRAPPED')) {
       http_response_code(403);
       exit('Forbidden');
   }
   ```
4. Add optional metadata at the top of the file:
   ```php
   <?php
   $pageMeta = [
       'title' => 'Page Title',
       'description' => 'Short description shown in header.',
   ];
   ?>
   ```

## Releases Section

- Releases are database-driven from three tables:
  - `releases` (`header`, `status`, `slug`, `html_content`)
  - `features` (`header`, `slug`, `html_content`, `asset_path`)
  - `release_features` (joins features to releases with `display_order`)
- The left sidebar shows direct links to the 10 most recent published releases.
- If there are more than 10, an `Older Releases` link appears and supports pagination.
- Each release has its own URL format: `?page=releases/<release-slug>`.
- Feature assets (GIF/video/image files) should be stored under `assets/releases/` and referenced via `features.asset_path`.

## Help Docs

- Help docs are database-driven using `help_docs` (`title`, `status`, `slug`, `html_content`).
- Published docs are listed under a `Help Docs` section in the left sidebar.
- Each help doc has its own URL format: `?page=help/<doc-slug>`.

## Admin Portal

- Admin portal URL: `/admin/index.php`.
- Auth tables:
  - `admin_users` (stores `password_hash` created via PHP `password_hash()`).
  - `admin_sessions` (tracks active admin logins).
- On first bootstrap (when no admin users exist), a default admin user is created from:
  - `WIKI_ADMIN_DEFAULT_EMAIL`
  - `WIKI_ADMIN_DEFAULT_NAME`
  - `WIKI_ADMIN_DEFAULT_PASSWORD`
- Portal features:
  - Create releases and attach features.
  - Create features with optional asset upload.
  - Create help docs.
  - Upload assets into `assets/<folder>/YYYY/MM/...` via GUI.
