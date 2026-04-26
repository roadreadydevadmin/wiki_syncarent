<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/wiki.php';

wiki_load_env(__DIR__ . '/.env');
wiki_db_bootstrap(__DIR__ . '/database/schema.sql');

$siteName = wiki_env('WIKI_SITE_NAME', 'Syncarent Wiki');
$siteTagline = wiki_env('WIKI_SITE_TAGLINE', 'Public documentation and release notes');
$siteLogoUrl = wiki_env('WIKI_LOGO_URL', 'https://roadready-cust-assets.s3.us-east-2.amazonaws.com/localhost/logo/syncarent_logo_md%20%281%29.png');
$accessPassword = wiki_env('WIKI_ACCESS_PASSWORD', 'Password123');

function wiki_safe_redirect_target(string $candidate): string
{
    $candidate = trim($candidate);

    if ($candidate === '') {
        return 'index.php';
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return 'index.php';
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
        return 'index.php';
    }

    $path = $parts['path'] ?? '/';
    if ($path === '' || !str_starts_with($path, '/')) {
        $path = '/' . ltrim($path, '/');
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $path . $query;
}

function wiki_replace_release_section_pages(array $sections, array $releasePages): array
{
    if (count($releasePages) === 0) {
        return $sections;
    }

    foreach ($sections as &$section) {
        if (($section['key'] ?? '') === 'releases') {
            $section['pages'] = $releasePages;
            return $sections;
        }
    }
    unset($section);

    $sections[] = [
        'key' => 'releases',
        'label' => 'Releases',
        'pages' => $releasePages,
    ];

    return $sections;
}

function wiki_release_date_label(?string $dateValue): string
{
    if (!is_string($dateValue) || $dateValue === '') {
        return 'Unknown date';
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return $dateValue;
    }

    return date('F j, Y', $timestamp);
}

function wiki_release_asset_markup(string $assetPath): string
{
    $normalized = ltrim(str_replace('\\', '/', trim($assetPath)), '/');
    if ($normalized === '' || str_contains($normalized, '..')) {
        return '';
    }

    $escapedPath = htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8');
    $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

    if (in_array($extension, ['mp4', 'webm', 'ogg'], true)) {
        return '<video class="w-100 rounded border" controls preload="metadata" playsinline src="' . $escapedPath . '"></video>';
    }

    if (in_array($extension, ['gif', 'jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
        return '<img class="img-fluid rounded border" src="' . $escapedPath . '" alt="Feature asset">';
    }

    return '<a href="' . $escapedPath . '" target="_blank" rel="noopener">Open linked asset</a>';
}

function wiki_render_release_content(array $release, array $features): string
{
    ob_start();
    ?>
    <section>
        <p class="text-secondary mb-3"><?= htmlspecialchars(wiki_release_date_label($release['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>

        <?php if (!empty($release['html_content'])): ?>
            <div class="mb-4"><?= (string) $release['html_content'] ?></div>
        <?php endif; ?>

        <h2 class="h5 mb-3">Features</h2>
        <?php if (count($features) === 0): ?>
            <p class="mb-0 text-secondary">No features are attached to this release yet.</p>
        <?php else: ?>
            <?php foreach ($features as $feature): ?>
                <article class="border rounded p-3 mb-3">
                    <h3 class="h6 mb-2"><?= htmlspecialchars((string) ($feature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php if (!empty($feature['html_content'])): ?>
                        <div class="mb-3"><?= (string) $feature['html_content'] ?></div>
                    <?php endif; ?>
                    <?php
                    $assetMarkup = '';
                    if (isset($feature['asset_path']) && is_string($feature['asset_path'])) {
                        $assetMarkup = wiki_release_asset_markup($feature['asset_path']);
                    }
                    ?>
                    <?php if ($assetMarkup !== ''): ?>
                        <div class="mt-2"><?= $assetMarkup ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

function wiki_render_older_releases_content(array $olderReleases, int $currentPage, int $totalPages): string
{
    ob_start();
    ?>
    <section>
        <?php if (count($olderReleases) === 0): ?>
            <p class="mb-0">No older releases found yet.</p>
        <?php else: ?>
            <ul class="list-group mb-3">
                <?php foreach ($olderReleases as $release): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                        <a href="?page=<?= urlencode('releases/' . (string) ($release['slug'] ?? '')) ?>">
                            <?= htmlspecialchars((string) ($release['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <small class="text-secondary text-nowrap">
                            <?= htmlspecialchars(wiki_release_date_label($release['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Older releases pagination">
                <ul class="pagination mb-0">
                    <?php $previousPage = max(1, $currentPage - 1); ?>
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=releases/older&amp;older_page=<?= $previousPage ?>">Previous</a>
                    </li>
                    <li class="page-item disabled">
                        <span class="page-link">Page <?= $currentPage ?> of <?= $totalPages ?></span>
                    </li>
                    <?php $nextPage = min($totalPages, $currentPage + 1); ?>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=releases/older&amp;older_page=<?= $nextPage ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

session_name('syncarent_wiki_session');
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

$isAuthenticated = isset($_SESSION['wiki_authenticated']) && $_SESSION['wiki_authenticated'] === true;
$loginError = '';
$redirectTarget = wiki_safe_redirect_target((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'));

if (!$isAuthenticated) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedPassword = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $submittedRedirect = isset($_POST['redirect']) ? (string) $_POST['redirect'] : 'index.php';
        $redirectTarget = wiki_safe_redirect_target($submittedRedirect);

        if ($submittedPassword !== '' && hash_equals($accessPassword, $submittedPassword)) {
            $_SESSION['wiki_authenticated'] = true;
            header('Location: ' . $redirectTarget);
            exit;
        }

        $loginError = 'Incorrect password. Try again.';
    }

    http_response_code(401);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <style>
            body { min-height: 100vh; display: grid; place-items: center; background: linear-gradient(155deg, #e7f0ef 0%, #d7eaee 100%); font-family: "Manrope", "Segoe UI", Tahoma, sans-serif; }
            .login-card { width: min(460px, 92vw); border: none; border-radius: 18px; box-shadow: 0 20px 38px rgba(12, 45, 56, 0.15); }
            .login-logo { width: 200px; max-width: 100%; border-radius: 10px; background: #fff; padding: 8px 10px; }
        </style>
    </head>
    <body>
    <div class="card login-card">
        <div class="card-body p-4 p-lg-5">
            <div class="text-center mb-4">
                <img src="<?= htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Syncarent logo" class="login-logo mb-3">
                <h1 class="h4 mb-1"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-secondary mb-0">This site is private while setup is in progress.</p>
            </div>

            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger py-2" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8') ?>">
                <label for="password" class="form-label">Site Password</label>
                <input type="password" class="form-control form-control-lg mb-3" id="password" name="password" required autofocus>
                <button type="submit" class="btn btn-primary w-100 btn-lg">Enter Wiki</button>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

define('WIKI_BOOTSTRAPPED', true);

$sections = wiki_discover_pages(__DIR__ . '/content');
$recentReleaseLimit = 10;
$recentReleasePageSize = 25;
$recentReleases = wiki_db_fetch_published_releases($recentReleaseLimit, 0);
$totalPublishedReleases = wiki_db_count_published_releases();
$releaseNavPages = [];

foreach ($recentReleases as $releaseRow) {
    if (!isset($releaseRow['slug'], $releaseRow['header'])) {
        continue;
    }

    $releaseSlug = (string) $releaseRow['slug'];
    if ($releaseSlug === '') {
        continue;
    }

    $releaseNavPages[] = [
        'slug' => 'releases/' . $releaseSlug,
        'title' => (string) $releaseRow['header'],
    ];
}

if ($totalPublishedReleases > $recentReleaseLimit) {
    $releaseNavPages[] = [
        'slug' => 'releases/older',
        'title' => 'Older Releases',
    ];
}

$sections = wiki_replace_release_section_pages($sections, $releaseNavPages);
$defaultSlug = wiki_default_slug($sections) ?? '';

$rawPage = isset($_GET['page']) ? (string) $_GET['page'] : '';
$requestedSlug = $rawPage === '' ? $defaultSlug : wiki_sanitize_slug($rawPage);

if ($requestedSlug === 'releases/index' && isset($recentReleases[0]['slug'])) {
    $requestedSlug = 'releases/' . (string) $recentReleases[0]['slug'];
}

$pageNotFound = $rawPage !== '' && $requestedSlug === '';
$resolvedRelease = null;
$showOlderReleasesPage = false;

$currentPage = $requestedSlug !== '' ? wiki_find_page($sections, $requestedSlug) : null;

if ($requestedSlug === 'releases/older') {
    $showOlderReleasesPage = true;
    $currentPage = [
        'slug' => 'releases/older',
        'title' => 'Older Releases',
    ];
} elseif (str_starts_with($requestedSlug, 'releases/')) {
    $releaseSlug = substr($requestedSlug, strlen('releases/'));

    if ($releaseSlug !== '' && !str_contains($releaseSlug, '/')) {
        $resolvedRelease = wiki_db_fetch_published_release_by_slug($releaseSlug);
        if ($resolvedRelease !== null) {
            $currentPage = [
                'slug' => 'releases/' . $releaseSlug,
                'title' => (string) ($resolvedRelease['header'] ?? 'Release'),
            ];
        }
    }
}

if ($currentPage === null && $defaultSlug !== '') {
    $currentPage = wiki_find_page($sections, $defaultSlug);

    if ($rawPage !== '' && $requestedSlug !== $defaultSlug) {
        $pageNotFound = true;
    }
}

$pageTitle = 'Welcome';
$pageDescription = 'Browse documentation, guides, and release notes from the sidebar.';
$pageContent = '<p class="mb-0">No content has been created yet.</p>';

if ($resolvedRelease !== null) {
    $features = wiki_db_fetch_release_features((int) ($resolvedRelease['id'] ?? 0));
    $pageTitle = (string) ($resolvedRelease['header'] ?? 'Release');
    $pageDescription = 'Release details and attached features.';
    $pageContent = wiki_render_release_content($resolvedRelease, $features);
} elseif ($showOlderReleasesPage) {
    $olderPageRaw = isset($_GET['older_page']) ? (string) $_GET['older_page'] : '1';
    $olderPage = ctype_digit($olderPageRaw) ? max(1, (int) $olderPageRaw) : 1;
    $totalOlderReleases = max(0, $totalPublishedReleases - $recentReleaseLimit);
    $totalOlderPages = max(1, (int) ceil($totalOlderReleases / $recentReleasePageSize));
    $olderPage = min($olderPage, $totalOlderPages);
    $olderOffset = $recentReleaseLimit + (($olderPage - 1) * $recentReleasePageSize);
    $olderReleases = $totalOlderReleases > 0
        ? wiki_db_fetch_published_releases($recentReleasePageSize, $olderOffset)
        : [];

    $pageTitle = 'Older Releases';
    $pageDescription = 'Browse all releases older than the latest 10 entries.';
    $pageContent = wiki_render_older_releases_content($olderReleases, $olderPage, $totalOlderPages);
} elseif ($currentPage !== null && isset($currentPage['path']) && is_string($currentPage['path'])) {
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
            <img src="<?= htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Syncarent logo" class="wiki-logo">
            <span>
                <strong class="wiki-brand-name d-block"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></strong>
                <small class="wiki-brand-tagline"><?= htmlspecialchars($siteTagline, ENT_QUOTES, 'UTF-8') ?></small>
            </span>
        </a>
        <div class="wiki-sidebar-actions mb-4">
            <a class="btn btn-sm btn-outline-light" href="?logout=1">Log Out</a>
        </div>

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
