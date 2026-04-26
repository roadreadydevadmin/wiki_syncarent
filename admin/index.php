<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/env.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/wiki.php';
require_once dirname(__DIR__) . '/includes/admin.php';

wiki_load_env(dirname(__DIR__) . '/.env');
wiki_db_bootstrap(dirname(__DIR__) . '/database/schema.sql');
wiki_admin_start_session();

$dbError = wiki_db_error();
$siteName = wiki_env('WIKI_SITE_NAME', 'Syncarent Wiki');
$flashes = wiki_admin_take_flashes();
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (!wiki_admin_validate_csrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        wiki_admin_set_flash('danger', 'Invalid security token. Please try again.');
        header('Location: index.php');
        exit;
    }

    wiki_admin_logout();
    header('Location: index.php');
    exit;
}

$adminUser = wiki_admin_current_user();

if ($adminUser === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
        $login = wiki_admin_login(
            isset($_POST['email']) ? (string) $_POST['email'] : '',
            isset($_POST['password']) ? (string) $_POST['password'] : ''
        );

        if (($login['ok'] ?? false) === true) {
            header('Location: index.php');
            exit;
        }

        $loginError = is_string($login['error'] ?? null) ? $login['error'] : 'Unable to log in.';
    }

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> Admin Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { min-height: 100vh; display: grid; place-items: center; background: linear-gradient(160deg, #eef6f3 0%, #d8e9ec 100%); font-family: "Manrope", "Segoe UI", sans-serif; }
            .login-card { width: min(460px, 92vw); border-radius: 18px; border: none; box-shadow: 0 18px 40px rgba(14, 53, 62, 0.18); }
        </style>
    </head>
    <body>
    <div class="card login-card">
        <div class="card-body p-4 p-lg-5">
            <h1 class="h4 mb-2"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> Admin</h1>
            <p class="text-secondary mb-4">Sign in with an admin user from the database.</p>

            <?php if ($dbError !== null): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" type="email" id="email" name="email" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign In</button>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'logout') {
    $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    if (!wiki_admin_validate_csrf($token)) {
        wiki_admin_set_flash('danger', 'Invalid security token. Please refresh and try again.');
        header('Location: index.php');
        exit;
    }

    $action = (string) $_POST['action'];

    try {
        if ($action === 'upload_asset') {
            if (!isset($_FILES['asset_file']) || !is_array($_FILES['asset_file'])) {
                throw new RuntimeException('Please choose a file to upload.');
            }

            $folder = isset($_POST['upload_folder']) ? (string) $_POST['upload_folder'] : 'uploads';
            $upload = wiki_admin_upload_asset($_FILES['asset_file'], $folder);
            if (($upload['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($upload['error'] ?? 'Upload failed.'));
            }

            wiki_admin_set_flash('success', 'Asset uploaded: ' . (string) ($upload['path'] ?? ''));
        } elseif ($action === 'create_feature') {
            $header = trim((string) ($_POST['feature_header'] ?? ''));
            $slugInput = trim((string) ($_POST['feature_slug'] ?? ''));
            $slug = $slugInput !== '' ? wiki_admin_slugify($slugInput) : wiki_admin_slugify($header);
            $html = (string) ($_POST['feature_html'] ?? '');
            $assetPath = trim((string) ($_POST['feature_asset_path'] ?? ''));

            if (isset($_FILES['feature_asset_file']) && is_array($_FILES['feature_asset_file'])) {
                $fileError = (int) ($_FILES['feature_asset_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($fileError !== UPLOAD_ERR_NO_FILE) {
                    $upload = wiki_admin_upload_asset($_FILES['feature_asset_file'], 'releases');
                    if (($upload['ok'] ?? false) !== true) {
                        throw new RuntimeException((string) ($upload['error'] ?? 'Feature asset upload failed.'));
                    }
                    $assetPath = (string) ($upload['path'] ?? '');
                }
            }

            if ($header === '') {
                throw new RuntimeException('Feature header is required.');
            }
            if ($slug === '') {
                throw new RuntimeException('Feature slug is required.');
            }

            wiki_db_create_feature($header, $slug, $html, $assetPath !== '' ? $assetPath : null);
            wiki_admin_set_flash('success', 'Feature created: ' . $header);
        } elseif ($action === 'create_release') {
            $header = trim((string) ($_POST['release_header'] ?? ''));
            $slugInput = trim((string) ($_POST['release_slug'] ?? ''));
            $slug = $slugInput !== '' ? wiki_admin_slugify($slugInput) : wiki_admin_slugify($header);
            $status = (string) ($_POST['release_status'] ?? 'draft');
            $html = (string) ($_POST['release_html'] ?? '');
            $featureIdsRaw = isset($_POST['feature_ids']) && is_array($_POST['feature_ids']) ? $_POST['feature_ids'] : [];
            $featureIds = wiki_admin_extract_feature_ids($featureIdsRaw);

            if ($header === '') {
                throw new RuntimeException('Release header is required.');
            }
            if ($slug === '') {
                throw new RuntimeException('Release slug is required.');
            }

            $releaseId = wiki_db_create_release($header, $status, $slug, $html);
            wiki_db_replace_release_features($releaseId, $featureIds);
            wiki_admin_set_flash('success', 'Release created: ' . $header);
        } elseif ($action === 'create_help_doc') {
            $title = trim((string) ($_POST['doc_title'] ?? ''));
            $slugInput = trim((string) ($_POST['doc_slug'] ?? ''));
            $slug = $slugInput !== '' ? wiki_admin_slugify($slugInput) : wiki_admin_slugify($title);
            $status = (string) ($_POST['doc_status'] ?? 'draft');
            $html = (string) ($_POST['doc_html'] ?? '');

            if ($title === '') {
                throw new RuntimeException('Doc title is required.');
            }
            if ($slug === '') {
                throw new RuntimeException('Doc slug is required.');
            }

            wiki_db_create_help_doc($title, $status, $slug, $html);
            wiki_admin_set_flash('success', 'Help doc created: ' . $title);
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        wiki_admin_set_flash('danger', $exception->getMessage());
    }

    header('Location: index.php');
    exit;
}

$csrfToken = wiki_admin_csrf_token();
$flashes = wiki_admin_take_flashes();
$allFeatures = wiki_db_fetch_all_features();
$recentReleases = wiki_db_fetch_recent_releases_for_admin(10);
$recentFeatures = wiki_db_fetch_recent_features_for_admin(10);
$recentDocs = wiki_db_fetch_recent_help_docs_for_admin(10);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f3f7f8; font-family: "Manrope", "Segoe UI", sans-serif; }
        .editor-card { border: 1px solid #d4e0e3; border-radius: 12px; background: #fff; }
        .editor-toolbar { border-bottom: 1px solid #e3ecee; padding: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
        .editor-area { min-height: 180px; padding: 12px; outline: none; }
        .list-mini { max-height: 280px; overflow: auto; }
        .feature-picker { max-height: 220px; overflow: auto; border: 1px solid #d4e0e3; border-radius: 8px; padding: 10px; background: #fff; }
    </style>
</head>
<body>
<div class="container py-4 py-lg-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Admin Portal</h1>
            <p class="text-secondary mb-0">Create releases, features, help docs, and upload assets.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="../index.php">View Public Site</a>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-outline-danger btn-sm">Log Out</button>
            </form>
        </div>
    </div>

    <p class="text-secondary mb-4">Signed in as <strong><?= htmlspecialchars((string) ($adminUser['display_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars((string) ($adminUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</p>

    <?php if ($dbError !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php foreach ($flashes as $flash): ?>
        <?php
        $type = isset($flash['type']) && in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true)
            ? $flash['type']
            : 'info';
        $message = isset($flash['message']) ? (string) $flash['message'] : '';
        ?>
        <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Create Release</h2>
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="create_release">

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label">Header</label>
                                <input class="form-control" name="release_header" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="release_status">
                                    <option value="draft">Draft</option>
                                    <option value="publish">Publish</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Slug (optional)</label>
                                <input class="form-control" name="release_slug" placeholder="auto-generated if blank">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Release HTML Content</label>
                                <div class="editor-card wysiwyg" data-input-name="release_html">
                                    <div class="editor-toolbar">
                                        <button class="btn btn-sm btn-light" type="button" data-command="bold">Bold</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="italic">Italic</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="insertUnorderedList">Bullet</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="createLink">Link</button>
                                    </div>
                                    <div class="editor-area" contenteditable="true"></div>
                                    <textarea class="d-none" name="release_html"></textarea>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Attach Features</label>
                                <div class="feature-picker">
                                    <?php if (count($allFeatures) === 0): ?>
                                        <p class="text-secondary mb-0">No features yet. Create a feature first.</p>
                                    <?php else: ?>
                                        <?php foreach ($allFeatures as $feature): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="feature_ids[]" value="<?= (int) ($feature['id'] ?? 0) ?>" id="feature_<?= (int) ($feature['id'] ?? 0) ?>">
                                                <label class="form-check-label" for="feature_<?= (int) ($feature['id'] ?? 0) ?>">
                                                    <?= htmlspecialchars((string) ($feature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                    <small class="text-secondary">(<?= htmlspecialchars((string) ($feature['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Create Release</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Create Feature</h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="create_feature">

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Header</label>
                                <input class="form-control" name="feature_header" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Slug (optional)</label>
                                <input class="form-control" name="feature_slug" placeholder="auto">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Asset Path (optional)</label>
                                <input class="form-control" name="feature_asset_path" placeholder="assets/releases/2026/04/file.gif">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Upload Asset (optional)</label>
                                <input class="form-control" type="file" name="feature_asset_file">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Feature HTML Content</label>
                                <div class="editor-card wysiwyg" data-input-name="feature_html">
                                    <div class="editor-toolbar">
                                        <button class="btn btn-sm btn-light" type="button" data-command="bold">Bold</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="italic">Italic</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="insertUnorderedList">Bullet</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="createLink">Link</button>
                                    </div>
                                    <div class="editor-area" contenteditable="true"></div>
                                    <textarea class="d-none" name="feature_html"></textarea>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Create Feature</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Create Help Doc</h2>
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="create_help_doc">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label">Title</label>
                                <input class="form-control" name="doc_title" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="doc_status">
                                    <option value="draft">Draft</option>
                                    <option value="publish">Publish</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Slug (optional)</label>
                                <input class="form-control" name="doc_slug" placeholder="auto-generated if blank">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Doc HTML Content</label>
                                <div class="editor-card wysiwyg" data-input-name="doc_html">
                                    <div class="editor-toolbar">
                                        <button class="btn btn-sm btn-light" type="button" data-command="bold">Bold</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="italic">Italic</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="insertUnorderedList">Bullet</button>
                                        <button class="btn btn-sm btn-light" type="button" data-command="createLink">Link</button>
                                    </div>
                                    <div class="editor-area" contenteditable="true"></div>
                                    <textarea class="d-none" name="doc_html"></textarea>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Create Help Doc</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Upload Asset</h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="upload_asset">
                        <div class="mb-2">
                            <label class="form-label">Folder Under assets/</label>
                            <input class="form-control" name="upload_folder" value="uploads">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">File</label>
                            <input class="form-control" type="file" name="asset_file" required>
                        </div>
                        <button class="btn btn-outline-primary w-100" type="submit">Upload</button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Recent Releases</h2>
                    <ul class="list-group list-mini">
                        <?php foreach ($recentReleases as $release): ?>
                            <li class="list-group-item">
                                <div class="small text-secondary"><?= htmlspecialchars((string) ($release['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div><?= htmlspecialchars((string) ($release['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-secondary"><?= htmlspecialchars((string) ($release['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Recent Features</h2>
                    <ul class="list-group list-mini">
                        <?php foreach ($recentFeatures as $feature): ?>
                            <li class="list-group-item">
                                <div><?= htmlspecialchars((string) ($feature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-secondary"><?= htmlspecialchars((string) ($feature['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Recent Help Docs</h2>
                    <ul class="list-group list-mini">
                        <?php foreach ($recentDocs as $doc): ?>
                            <li class="list-group-item">
                                <div class="small text-secondary"><?= htmlspecialchars((string) ($doc['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div><?= htmlspecialchars((string) ($doc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-secondary"><?= htmlspecialchars((string) ($doc['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.wysiwyg').forEach(function (wrapper) {
    var editor = wrapper.querySelector('.editor-area');
    var textarea = wrapper.querySelector('textarea');
    var form = wrapper.closest('form');

    wrapper.querySelectorAll('[data-command]').forEach(function (button) {
        button.addEventListener('click', function () {
            var command = button.getAttribute('data-command');
            if (!command) return;

            editor.focus();
            if (command === 'createLink') {
                var url = window.prompt('Enter URL');
                if (!url) return;
                document.execCommand('createLink', false, url);
                return;
            }

            document.execCommand(command, false, null);
        });
    });

    if (form) {
        form.addEventListener('submit', function () {
            textarea.value = editor.innerHTML;
        });
    }
});
</script>
</body>
</html>
