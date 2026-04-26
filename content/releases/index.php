<?php
if (!defined('WIKI_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageMeta = [
    'title' => 'Releases',
    'description' => 'Release pages are now generated from database entries.',
];
?>
<section>
    <p class="mb-0">
        Release pages are database-driven. Add releases with status <code>publish</code> to have them appear in the sidebar.
    </p>
</section>
