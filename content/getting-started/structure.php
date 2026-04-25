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
│   └── img/logo.svg
├── content/
│   ├── home.php
│   ├── .htaccess
│   ├── getting-started/
│   │   ├── overview.php
│   │   └── structure.php
│   └── releases/
│       ├── index.php
│       └── v1-0-0-initial-public-starter.php
├── includes/
│   ├── env.php
│   ├── wiki.php
│   └── .htaccess
└── index.php</code></pre>

    <h2>Routing Model</h2>
    <p>
        The URL query `?page=section/page-name` maps directly to files under `content/`.
    </p>
    <p>
        Example: `?page=releases/v1-0-0-initial-public-starter` maps to
        `content/releases/v1-0-0-initial-public-starter.php`.
    </p>
</section>
