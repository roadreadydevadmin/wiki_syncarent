<?php
if (!defined('WIKI_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageMeta = [
    'title' => 'Release Notes',
    'description' => 'Public changelog pages. Add one file per release in this folder.',
];
?>
<section>
    <p>
        This section is dedicated to releases. Create one new page per release and keep older versions for history.
    </p>

    <h2>Latest Release</h2>
    <ul>
        <li><a href="?page=releases/v1-0-0-initial-public-starter">v1.0.0 - Initial Public Starter</a></li>
    </ul>

    <h2>Release Page Naming</h2>
    <p>
        Recommended filename format:
        <code>vX-X-X-short-release-name.php</code>
    </p>
</section>
