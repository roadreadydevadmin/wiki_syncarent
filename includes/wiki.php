<?php
declare(strict_types=1);

function wiki_humanize(string $value): string
{
    $trimmed = trim($value);
    $lower = strtolower($trimmed);

    if ($lower === 'home') {
        return 'Home';
    }

    if ($lower === 'index') {
        return 'Overview';
    }

    $clean = str_replace(['-', '_'], ' ', $trimmed);
    $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

    return ucwords($clean);
}

function wiki_section_label(string $sectionKey): string
{
    $parts = explode('/', $sectionKey);
    $labels = array_map('wiki_humanize', $parts);

    return implode(' / ', $labels);
}

function wiki_slug_to_title(string $slug): string
{
    $slugParts = explode('/', trim($slug, '/'));
    $name = end($slugParts);

    return wiki_humanize($name === false ? $slug : $name);
}

function wiki_sanitize_slug(string $value): string
{
    $slug = trim(str_replace('\\', '/', $value), '/');

    if ($slug === '') {
        return '';
    }

    if (str_contains($slug, '..')) {
        return '';
    }

    if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $slug)) {
        return '';
    }

    return $slug;
}

function wiki_discover_pages(string $contentDir): array
{
    if (!is_dir($contentDir)) {
        return [];
    }

    $contentRoot = rtrim($contentDir, DIRECTORY_SEPARATOR);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($contentRoot, FilesystemIterator::SKIP_DOTS)
    );

    $sections = [];

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $relativePath = substr($fullPath, strlen($contentRoot) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        $slug = preg_replace('/\.php$/i', '', $relativePath);
        $slug = trim($slug ?? '', '/');

        if ($slug === '') {
            continue;
        }

        $sectionKey = dirname($slug);
        $sectionKey = $sectionKey === '.' ? 'general' : $sectionKey;

        if (!array_key_exists($sectionKey, $sections)) {
            $sections[$sectionKey] = [];
        }

        $sections[$sectionKey][] = [
            'slug' => $slug,
            'title' => wiki_slug_to_title($slug),
            'path' => $fullPath,
        ];
    }

    foreach ($sections as &$pages) {
        usort(
            $pages,
            static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title'])
        );
    }
    unset($pages);

    uksort(
        $sections,
        static function (string $a, string $b): int {
            $priority = ['general' => 0, 'releases' => 1];
            $aPriority = $priority[$a] ?? 2;
            $bPriority = $priority[$b] ?? 2;

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            return strnatcasecmp($a, $b);
        }
    );

    $normalized = [];
    foreach ($sections as $sectionKey => $pages) {
        $normalized[] = [
            'key' => $sectionKey,
            'label' => wiki_section_label($sectionKey),
            'pages' => $pages,
        ];
    }

    return $normalized;
}

function wiki_find_page(array $sections, string $slug): ?array
{
    foreach ($sections as $section) {
        foreach ($section['pages'] as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }
    }

    return null;
}

function wiki_default_slug(array $sections): ?string
{
    foreach ($sections as $section) {
        foreach ($section['pages'] as $page) {
            if ($page['slug'] === 'home') {
                return $page['slug'];
            }
        }
    }

    if (isset($sections[0]['pages'][0]['slug'])) {
        return $sections[0]['pages'][0]['slug'];
    }

    return null;
}

function wiki_render_page(string $path): array
{
    $pageMeta = [];

    ob_start();
    include $path;
    $content = (string) ob_get_clean();

    if (!is_array($pageMeta)) {
        $pageMeta = [];
    }

    return [
        'meta' => $pageMeta,
        'content' => $content,
    ];
}

