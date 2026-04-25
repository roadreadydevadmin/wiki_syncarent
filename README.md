# Syncarent Wiki Starter

Public-facing wiki starter built with PHP and Bootstrap.

## Quick Start

1. From the project root, run:
   ```bash
   php -S localhost:8000
   ```
2. Open `http://localhost:8000`.

## How It Works

- The site entrypoint is `index.php`.
- All wiki pages live inside `content/`.
- The left sidebar navigation is built automatically from folder + file structure in `content/`.
- Each folder becomes a section in the sidebar.

## Add Pages

1. Add a new `.php` file under `content/<section>/`.
2. The filename becomes the page URL slug and default navigation label.
3. Add optional metadata at the top of the file:
   ```php
   <?php
   $pageMeta = [
       'title' => 'Page Title',
       'description' => 'Short description shown in header.',
   ];
   ?>
   ```

## Releases Section

- Release pages belong in `content/releases/`.
- Create one new file per release, for example:
  - `content/releases/v1-0-1-bug-fixes.php`
  - `content/releases/v1-1-0-new-features.php`
