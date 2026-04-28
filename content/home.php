<?php
if (!defined('WIKI_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageMeta = [
    'title' => 'Welcome to Syncarent Wiki',
    'description' => 'Central documentation hub for public-facing product knowledge and release communication.',
];
?>
<section>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Getting Started</h2>
                    <p class="mb-3">Learn the layout and where to get started.</p>
                    <a class="btn btn-outline-primary btn-sm" href="?page=getting-started/overview">Open Guide</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Help Docs</h2>
                    <p class="mb-3">Browse how-to guides, troubleshooting steps, and common questions.</p>
                    <a class="btn btn-outline-primary btn-sm" href="?page=getting-started/overview">Browse Docs</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Releases</h2>
                    <p class="mb-3">Latest release information and updates.</p>
                    <a class="btn btn-outline-primary btn-sm" href="?page=releases/index">View Releases</a>
                </div>
            </div>
        </div>
    </div>
</section>
