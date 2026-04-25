<?php
$pageMeta = [
    'title' => 'Getting Started',
    'description' => 'How to add pages and organize sections in this wiki.',
];
?>
<section>
    <h2>How to Add a New Page</h2>
    <ol>
        <li>Create a new file in `content/<section>/` with a `.php` extension.</li>
        <li>Add optional `$pageMeta` for page title and description.</li>
        <li>Write HTML content below the metadata block.</li>
        <li>Refresh the browser. The page appears automatically in the left navigation.</li>
    </ol>

    <h2>Folder-to-Section Mapping</h2>
    <p>
        Each folder under `content/` becomes a section in the sidebar. For example:
    </p>
    <ul>
        <li>`content/getting-started/*.php` appears under <strong>Getting Started</strong>.</li>
        <li>`content/releases/*.php` appears under <strong>Releases</strong>.</li>
    </ul>
</section>

