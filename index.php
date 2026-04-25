<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/wiki.php';

$siteName = 'Syncarent Wiki';
$siteTagline = 'Public documentation and release notes';
$sections = wiki_discover_pages(__DIR__ . '/content');
$defaultSlug = wiki_default_slug($sections) ?? '';

$rawPage = isset($_GET['page']) ? (string) $_GET['page'] : '';
$requestedSlug = $rawPage === '' ? $defaultSlug : wiki_sanitize_slug($rawPage);
$pageNotFound = $rawPage !== '' && $requestedSlug === '';

$currentPage = $requestedSlug !== '' ? wiki_find_page($sections, $requestedSlug) : null;

if ($currentPage === null && $defaultSlug !== '') {
    $currentPage = wiki_find_page($sections, $defaultSlug);

    if ($rawPage !== '' && $requestedSlug !== $defaultSlug) {
        $pageNotFound = true;
    }
}

$pageTitle = 'Welcome';
$pageDescription = 'Browse documentation, guides, and release notes from the sidebar.';
$pageContent = '<p class="mb-0">No content has been created yet.</p>';

if ($currentPage !== null) {
    $rendered = wiki_render_page($currentPage['path']);
    $meta = $rendered['meta'];
    $pageTitle = isset($meta['title']) && is_string($meta['title']) ? $meta['title'] : $currentPage['title'];
    $pageDescription = isset($meta['description']) && is_string($meta['description']) ? $meta['description'] : $pageDescription;
    $pageContent = $rendered['content'];
}

if ($pageNotFound) {
    http_response_code(404);
}

$htmlTitle = $pageNotFound ? 'Page Not Found | ' . $siteName : $pageTitle . ' | ' . $siteName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($htmlTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Source+Serif+4:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/css/site.css" rel="stylesheet">
</head>
<body>
<div class="wiki-shell d-md-flex">
    <aside class="wiki-sidebar p-4">
        <a class="wiki-brand mb-4 text-decoration-none" href="index.php">
            <img src="assets/img/logo.svg" alt="Syncarent logo" class="wiki-logo">
            <span>
                <strong class="wiki-brand-name d-block"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></strong>
                <small class="wiki-brand-tagline"><?= htmlspecialchars($siteTagline, ENT_QUOTES, 'UTF-8') ?></small>
            </span>
        </a>

        <?php if (count($sections) === 0): ?>
            <p class="mb-0 text-light-emphasis">Add `.php` files to `content/` to populate navigation.</p>
        <?php else: ?>
            <?php foreach ($sections as $section): ?>
                <section class="wiki-nav-section">
                    <h2 class="wiki-section-title"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <ul class="nav flex-column gap-1">
                        <?php foreach ($section['pages'] as $page): ?>
                            <?php $isActive = $currentPage !== null && $currentPage['slug'] === $page['slug']; ?>
                            <li class="nav-item">
                                <a class="wiki-nav-link <?= $isActive ? 'active' : '' ?>" href="?page=<?= urlencode($page['slug']) ?>">
                                    <?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>

    <main class="wiki-main p-4 p-lg-5">
        <header class="wiki-page-header mb-4">
            <?php if ($pageNotFound): ?>
                <div class="alert alert-warning mb-3" role="alert">
                    Requested page was not found. Showing the default page instead.
                </div>
            <?php endif; ?>

            <h1 class="wiki-title mb-2"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="wiki-description mb-0"><?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?></p>
        </header>

        <article class="wiki-article">
            <?= $pageContent ?>
        </article>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
