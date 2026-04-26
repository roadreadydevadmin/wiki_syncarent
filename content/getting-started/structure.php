<?php
if (!defined('WIKI_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageMeta = [
    'title' => 'Project Structure',
    'description' => 'Starter file layout for your Bootstrap + PHP wiki.',
];
?>
<section>
    <h2>Current Structure</h2>
    <pre><code>/
├── .env
├── .env.example
├── .htaccess
├── assets/
│   ├── css/site.css
│   ├── img/logo.svg
│   └── releases/
├── database/
│   ├── .htaccess
│   └── schema.sql
├── content/
│   ├── home.php
│   ├── .htaccess
│   ├── getting-started/
│   │   ├── overview.php
│   │   └── structure.php
│   └── releases/
│       └── index.php
├── includes/
│   ├── database.php
│   ├── env.php
│   ├── wiki.php
│   └── .htaccess
└── index.php</code></pre>

    <h2>Routing Model</h2>
    <p>
        The URL query `?page=section/page-name` maps directly to files under `content/`.
    </p>
    <p>
        Releases are now database-driven from `database/schema.sql` and rendered at
        `?page=releases/index`.
    </p>
</section>
