<?php
if (!defined('WIKI_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('wiki_release_render_asset')) {
    function wiki_release_render_asset(string $assetPath): string
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
}

$pageMeta = [
    'title' => 'Release Notes',
    'description' => 'Database-driven release notes and feature history.',
];

$releases = [];
$releaseFeatures = [];
$selectedRelease = null;
$requestedReleaseSlug = isset($_GET['release']) ? wiki_sanitize_slug((string) $_GET['release']) : '';
$dbError = wiki_db_error();
$pdo = wiki_db();

if ($pdo instanceof PDO) {
    $releaseStatement = $pdo->query(
        "SELECT id, header, status, slug, html_content, created_at, updated_at
         FROM releases
         WHERE status = 'publish'
         ORDER BY created_at DESC, id DESC"
    );
    $releases = $releaseStatement->fetchAll();

    if ($requestedReleaseSlug !== '') {
        foreach ($releases as $releaseRow) {
            if (($releaseRow['slug'] ?? '') === $requestedReleaseSlug) {
                $selectedRelease = $releaseRow;
                break;
            }
        }
    }

    if ($selectedRelease === null && isset($releases[0]) && is_array($releases[0])) {
        $selectedRelease = $releases[0];
    }

    if ($selectedRelease !== null) {
        $featureStatement = $pdo->prepare(
            'SELECT f.id, f.header, f.slug, f.html_content, f.asset_path, rf.display_order
             FROM release_features rf
             INNER JOIN features f ON f.id = rf.feature_id
             WHERE rf.release_id = :release_id
             ORDER BY rf.display_order ASC, f.id ASC'
        );
        $featureStatement->execute(['release_id' => $selectedRelease['id']]);
        $releaseFeatures = $featureStatement->fetchAll();

        $pageMeta['title'] = (string) ($selectedRelease['header'] ?? 'Release Notes');
        $pageMeta['description'] = 'Release notes and attached feature entries from the database.';
    }
}

$statusText = strtoupper((string) ($selectedRelease['status'] ?? ''));
?>
<section>
    <p class="mb-4">Release entries are now stored in the database and rendered from SQL tables.</p>

    <?php if ($dbError !== null): ?>
        <div class="alert alert-danger" role="alert">
            Database setup issue: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (count($releases) === 0): ?>
        <div class="alert alert-warning mb-0" role="alert">
            No published releases found yet. Add rows to <code>releases</code> and map features in <code>release_features</code>.
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <h2 class="h5">Published Releases</h2>
                <div class="list-group">
                    <?php foreach ($releases as $release): ?>
                        <?php $isActive = $selectedRelease !== null && ($release['id'] ?? 0) === ($selectedRelease['id'] ?? -1); ?>
                        <a
                            class="list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?>"
                            href="?page=releases/index&amp;release=<?= urlencode((string) ($release['slug'] ?? '')) ?>"
                        >
                            <?= htmlspecialchars((string) ($release['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-lg-8">
                <?php if ($selectedRelease !== null): ?>
                    <article class="mb-4">
                        <header class="mb-3">
                            <h2 class="h4 mb-1"><?= htmlspecialchars((string) ($selectedRelease['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="text-secondary mb-0">
                                <strong>Status:</strong> <?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?>
                                <span class="mx-2">|</span>
                                <strong>Slug:</strong> <?= htmlspecialchars((string) ($selectedRelease['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </header>

                        <?php if (!empty($selectedRelease['html_content'])): ?>
                            <div class="mb-0"><?= (string) $selectedRelease['html_content'] ?></div>
                        <?php endif; ?>
                    </article>

                    <h3 class="h5 mb-3">Attached Features</h3>

                    <?php if (count($releaseFeatures) === 0): ?>
                        <p class="mb-0 text-secondary">No features are mapped to this release yet.</p>
                    <?php else: ?>
                        <?php foreach ($releaseFeatures as $feature): ?>
                            <article class="border rounded p-3 mb-3">
                                <h4 class="h6 mb-2"><?= htmlspecialchars((string) ($feature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h4>
                                <?php if (!empty($feature['html_content'])): ?>
                                    <div class="mb-3"><?= (string) $feature['html_content'] ?></div>
                                <?php endif; ?>

                                <?php
                                $assetMarkup = '';
                                if (isset($feature['asset_path']) && is_string($feature['asset_path'])) {
                                    $assetMarkup = wiki_release_render_asset($feature['asset_path']);
                                }
                                ?>
                                <?php if ($assetMarkup !== ''): ?>
                                    <div class="mt-2">
                                        <?= $assetMarkup ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
