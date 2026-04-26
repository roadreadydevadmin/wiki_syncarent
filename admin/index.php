<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/env.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/wiki.php';
require_once dirname(__DIR__) . '/includes/admin.php';

wiki_load_env(dirname(__DIR__) . '/.env');
wiki_db_bootstrap(dirname(__DIR__) . '/database/schema.sql');
wiki_admin_start_session();

function wiki_admin_positive_int_from_query(string $key): int
{
    $raw = isset($_GET[$key]) ? (string) $_GET[$key] : '';
    if (!ctype_digit($raw)) {
        return 0;
    }

    $value = (int) $raw;
    return $value > 0 ? $value : 0;
}

function wiki_admin_redirect(string $url = 'index.php'): void
{
    header('Location: ' . $url);
    exit;
}

$dbError = wiki_db_error();
$siteName = wiki_env('WIKI_SITE_NAME', 'Syncarent Wiki');
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (!wiki_admin_validate_csrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        wiki_admin_set_flash('danger', 'Invalid security token. Please try again.');
        wiki_admin_redirect('index.php');
    }

    wiki_admin_logout();
    wiki_admin_redirect('index.php');
}

$adminUser = wiki_admin_current_user();

if ($adminUser === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
        $login = wiki_admin_login(
            isset($_POST['email']) ? (string) $_POST['email'] : '',
            isset($_POST['password']) ? (string) $_POST['password'] : ''
        );

        if (($login['ok'] ?? false) === true) {
            wiki_admin_redirect('index.php');
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
        wiki_admin_redirect('index.php');
    }

    $action = (string) $_POST['action'];
    $redirectUrl = 'index.php';

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
        } elseif ($action === 'create_feature' || $action === 'edit_feature') {
            $isEdit = $action === 'edit_feature';
            $featureId = isset($_POST['feature_id']) ? (int) $_POST['feature_id'] : 0;
            if ($isEdit && $featureId > 0) {
                $redirectUrl = 'index.php?edit_feature=' . $featureId . '#edit-feature';
            }
            $header = trim((string) ($_POST['feature_header'] ?? ''));
            $slugInput = trim((string) ($_POST['feature_slug'] ?? ''));
            $slug = $slugInput !== '' ? wiki_admin_slugify($slugInput) : wiki_admin_slugify($header);
            $html = (string) ($_POST['feature_html'] ?? '');
            $assetPath = trim((string) ($_POST['feature_asset_path'] ?? ''));
            $removeAsset = isset($_POST['feature_remove_asset']) && $_POST['feature_remove_asset'] === '1';

            $existingFeature = null;
            if ($isEdit) {
                if ($featureId <= 0) {
                    throw new RuntimeException('Invalid feature ID.');
                }
                $existingFeature = wiki_db_fetch_feature_by_id($featureId);
                if ($existingFeature === null) {
                    throw new RuntimeException('Feature not found.');
                }
                if ($assetPath === '' && isset($existingFeature['asset_path']) && is_string($existingFeature['asset_path'])) {
                    $assetPath = (string) $existingFeature['asset_path'];
                }
            }

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

            if ($removeAsset) {
                $assetPath = '';
            }

            if ($header === '') {
                throw new RuntimeException('Feature header is required.');
            }
            if ($slug === '') {
                throw new RuntimeException('Feature slug is required.');
            }

            if ($isEdit) {
                wiki_db_update_feature($featureId, $header, $slug, $html, $assetPath !== '' ? $assetPath : null);
                wiki_admin_set_flash('success', 'Feature updated: ' . $header);
                $redirectUrl = 'index.php?edit_feature=' . $featureId . '#edit-feature';
            } else {
                $newFeatureId = wiki_db_create_feature($header, $slug, $html, $assetPath !== '' ? $assetPath : null);
                wiki_admin_set_flash('success', 'Feature created: ' . $header);
                $redirectUrl = 'index.php?edit_feature=' . $newFeatureId . '#edit-feature';
            }
        } elseif ($action === 'create_release' || $action === 'edit_release') {
            $isEdit = $action === 'edit_release';
            $releaseId = isset($_POST['release_id']) ? (int) $_POST['release_id'] : 0;
            if ($isEdit && $releaseId > 0) {
                $redirectUrl = 'index.php?edit_release=' . $releaseId . '#edit-release';
            }
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

            if ($isEdit) {
                if ($releaseId <= 0 || wiki_db_fetch_release_by_id($releaseId) === null) {
                    throw new RuntimeException('Release not found.');
                }
                wiki_db_update_release($releaseId, $header, $status, $slug, $html);
                wiki_db_replace_release_features($releaseId, $featureIds);
                wiki_admin_set_flash('success', 'Release updated: ' . $header);
                $redirectUrl = 'index.php?edit_release=' . $releaseId . '#edit-release';
            } else {
                $newReleaseId = wiki_db_create_release($header, $status, $slug, $html);
                wiki_db_replace_release_features($newReleaseId, $featureIds);
                wiki_admin_set_flash('success', 'Release created: ' . $header);
                $redirectUrl = 'index.php?edit_release=' . $newReleaseId . '#edit-release';
            }
        } elseif ($action === 'create_help_doc' || $action === 'edit_help_doc') {
            $isEdit = $action === 'edit_help_doc';
            $helpDocId = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
            if ($isEdit && $helpDocId > 0) {
                $redirectUrl = 'index.php?edit_doc=' . $helpDocId . '#edit-doc';
            }
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

            if ($isEdit) {
                if ($helpDocId <= 0 || wiki_db_fetch_help_doc_by_id($helpDocId) === null) {
                    throw new RuntimeException('Help doc not found.');
                }
                wiki_db_update_help_doc($helpDocId, $title, $status, $slug, $html);
                wiki_admin_set_flash('success', 'Help doc updated: ' . $title);
                $redirectUrl = 'index.php?edit_doc=' . $helpDocId . '#edit-doc';
            } else {
                $newHelpDocId = wiki_db_create_help_doc($title, $status, $slug, $html);
                wiki_admin_set_flash('success', 'Help doc created: ' . $title);
                $redirectUrl = 'index.php?edit_doc=' . $newHelpDocId . '#edit-doc';
            }
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        wiki_admin_set_flash('danger', $exception->getMessage());
    }

    wiki_admin_redirect($redirectUrl);
}

$csrfToken = wiki_admin_csrf_token();
$flashes = wiki_admin_take_flashes();
$allFeatures = wiki_db_fetch_all_features();
$recentReleases = wiki_db_fetch_recent_releases_for_admin(10);
$recentFeatures = wiki_db_fetch_recent_features_for_admin(10);
$recentDocs = wiki_db_fetch_recent_help_docs_for_admin(10);

$editReleaseId = wiki_admin_positive_int_from_query('edit_release');
$editFeatureId = wiki_admin_positive_int_from_query('edit_feature');
$editDocId = wiki_admin_positive_int_from_query('edit_doc');

$editingRelease = $editReleaseId > 0 ? wiki_db_fetch_release_by_id($editReleaseId) : null;
$editingFeature = $editFeatureId > 0 ? wiki_db_fetch_feature_by_id($editFeatureId) : null;
$editingDoc = $editDocId > 0 ? wiki_db_fetch_help_doc_by_id($editDocId) : null;
$editingReleaseFeatureIds = $editingRelease !== null ? wiki_db_fetch_release_feature_ids((int) $editingRelease['id']) : [];

$warnings = [];
if ($editReleaseId > 0 && $editingRelease === null) {
    $warnings[] = 'The selected release could not be found.';
}
if ($editFeatureId > 0 && $editingFeature === null) {
    $warnings[] = 'The selected feature could not be found.';
}
if ($editDocId > 0 && $editingDoc === null) {
    $warnings[] = 'The selected help doc could not be found.';
}
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
            <p class="text-secondary mb-0">Create and edit releases, features, help docs, and assets.</p>
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

    <p class="text-secondary mb-4">
        Signed in as <strong><?= htmlspecialchars((string) ($adminUser['display_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></strong>
        (<?= htmlspecialchars((string) ($adminUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)
    </p>

    <?php if ($dbError !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php foreach ($warnings as $warning): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

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
            <?php if ($editingRelease !== null): ?>
                <div class="card border-0 shadow-sm mb-4" id="edit-release">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Edit Release #<?= (int) $editingRelease['id'] ?></h2>
                            <a class="btn btn-sm btn-outline-secondary" href="index.php">Cancel Edit</a>
                        </div>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="edit_release">
                            <input type="hidden" name="release_id" value="<?= (int) $editingRelease['id'] ?>">

                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label">Header</label>
                                    <input class="form-control" name="release_header" required value="<?= htmlspecialchars((string) ($editingRelease['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="release_status">
                                        <option value="draft" <?= (($editingRelease['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
                                        <option value="publish" <?= (($editingRelease['status'] ?? '') === 'publish') ? 'selected' : '' ?>>Publish</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Slug</label>
                                    <input class="form-control" name="release_slug" required value="<?= htmlspecialchars((string) ($editingRelease['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Release HTML Content</label>
                                    <div class="editor-card wysiwyg">
                                        <div class="editor-toolbar">
                                            <button class="btn btn-sm btn-light" type="button" data-command="bold">Bold</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="italic">Italic</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="insertUnorderedList">Bullet</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="createLink">Link</button>
                                        </div>
                                        <div class="editor-area" contenteditable="true"></div>
                                        <textarea class="d-none" name="release_html"><?= htmlspecialchars((string) ($editingRelease['html_content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Attach Features</label>
                                    <div class="feature-picker">
                                        <?php if (count($allFeatures) === 0): ?>
                                            <p class="text-secondary mb-0">No features yet. Create a feature first.</p>
                                        <?php else: ?>
                                            <?php foreach ($allFeatures as $feature): ?>
                                                <?php
                                                $featureId = (int) ($feature['id'] ?? 0);
                                                $isSelected = in_array($featureId, $editingReleaseFeatureIds, true);
                                                ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="feature_ids[]" value="<?= $featureId ?>" id="edit_release_feature_<?= $featureId ?>" <?= $isSelected ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="edit_release_feature_<?= $featureId ?>">
                                                        <?= htmlspecialchars((string) ($feature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                        <small class="text-secondary">(<?= htmlspecialchars((string) ($feature['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</small>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary" type="submit">Update Release</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($editingFeature !== null): ?>
                <div class="card border-0 shadow-sm mb-4" id="edit-feature">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Edit Feature #<?= (int) $editingFeature['id'] ?></h2>
                            <a class="btn btn-sm btn-outline-secondary" href="index.php">Cancel Edit</a>
                        </div>
                        <form method="post" action="" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="edit_feature">
                            <input type="hidden" name="feature_id" value="<?= (int) $editingFeature['id'] ?>">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Header</label>
                                    <input class="form-control" name="feature_header" required value="<?= htmlspecialchars((string) ($editingFeature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Slug</label>
                                    <input class="form-control" name="feature_slug" required value="<?= htmlspecialchars((string) ($editingFeature['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Asset Path</label>
                                    <input class="form-control" name="feature_asset_path" value="<?= htmlspecialchars((string) ($editingFeature['asset_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if (!empty($editingFeature['asset_path'])): ?>
                                        <div class="form-text">Current: <?= htmlspecialchars((string) $editingFeature['asset_path'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Upload New Asset</label>
                                    <input class="form-control" type="file" name="feature_asset_file">
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="feature_remove_asset" name="feature_remove_asset">
                                        <label class="form-check-label" for="feature_remove_asset">Remove current asset</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Feature HTML Content</label>
                                    <div class="editor-card wysiwyg">
                                        <div class="editor-toolbar">
                                            <button class="btn btn-sm btn-light" type="button" data-command="bold">Bold</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="italic">Italic</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="insertUnorderedList">Bullet</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="createLink">Link</button>
                                        </div>
                                        <div class="editor-area" contenteditable="true"></div>
                                        <textarea class="d-none" name="feature_html"><?= htmlspecialchars((string) ($editingFeature['html_content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary" type="submit">Update Feature</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($editingDoc !== null): ?>
                <div class="card border-0 shadow-sm mb-4" id="edit-doc">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Edit Help Doc #<?= (int) $editingDoc['id'] ?></h2>
                            <a class="btn btn-sm btn-outline-secondary" href="index.php">Cancel Edit</a>
                        </div>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="edit_help_doc">
                            <input type="hidden" name="doc_id" value="<?= (int) $editingDoc['id'] ?>">
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label">Title</label>
                                    <input class="form-control" name="doc_title" required value="<?= htmlspecialchars((string) ($editingDoc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="doc_status">
                                        <option value="draft" <?= (($editingDoc['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
                                        <option value="publish" <?= (($editingDoc['status'] ?? '') === 'publish') ? 'selected' : '' ?>>Publish</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Slug</label>
                                    <input class="form-control" name="doc_slug" required value="<?= htmlspecialchars((string) ($editingDoc['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Doc HTML Content</label>
                                    <div class="editor-card wysiwyg">
                                        <div class="editor-toolbar">
                                            <button class="btn btn-sm btn-light" type="button" data-command="bold">Bold</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="italic">Italic</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="insertUnorderedList">Bullet</button>
                                            <button class="btn btn-sm btn-light" type="button" data-command="createLink">Link</button>
                                        </div>
                                        <div class="editor-area" contenteditable="true"></div>
                                        <textarea class="d-none" name="doc_html"><?= htmlspecialchars((string) ($editingDoc['html_content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary" type="submit">Update Help Doc</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

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
                                <div class="editor-card wysiwyg">
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
                                            <?php $featureId = (int) ($feature['id'] ?? 0); ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="feature_ids[]" value="<?= $featureId ?>" id="create_release_feature_<?= $featureId ?>">
                                                <label class="form-check-label" for="create_release_feature_<?= $featureId ?>">
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
                                <div class="editor-card wysiwyg">
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
                                <div class="editor-card wysiwyg">
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
                            <?php
                            $releaseId = (int) ($release['id'] ?? 0);
                            $releaseSlug = (string) ($release['slug'] ?? '');
                            $releaseStatus = (string) ($release['status'] ?? '');
                            $releaseIsPublic = $releaseStatus === 'publish' && $releaseSlug !== '';
                            ?>
                            <li class="list-group-item">
                                <div class="small text-secondary"><?= htmlspecialchars($releaseStatus, ENT_QUOTES, 'UTF-8') ?></div>
                                <div><?= htmlspecialchars((string) ($release['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-secondary mb-2"><?= htmlspecialchars($releaseSlug, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="d-flex gap-2">
                                    <?php if ($releaseIsPublic): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="../index.php?page=<?= urlencode('releases/' . $releaseSlug) ?>" target="_blank" rel="noopener">View</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?edit_release=<?= $releaseId ?>#edit-release">Edit</a>
                                </div>
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
                            <?php $featureId = (int) ($feature['id'] ?? 0); ?>
                            <li class="list-group-item">
                                <div><?= htmlspecialchars((string) ($feature['header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-secondary mb-2"><?= htmlspecialchars((string) ($feature['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <a class="btn btn-sm btn-outline-primary" href="index.php?edit_feature=<?= $featureId ?>#edit-feature">Edit</a>
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
                            <?php
                            $docId = (int) ($doc['id'] ?? 0);
                            $docSlug = (string) ($doc['slug'] ?? '');
                            $docStatus = (string) ($doc['status'] ?? '');
                            $docIsPublic = $docStatus === 'publish' && $docSlug !== '';
                            ?>
                            <li class="list-group-item">
                                <div class="small text-secondary"><?= htmlspecialchars($docStatus, ENT_QUOTES, 'UTF-8') ?></div>
                                <div><?= htmlspecialchars((string) ($doc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-secondary mb-2"><?= htmlspecialchars($docSlug, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="d-flex gap-2">
                                    <?php if ($docIsPublic): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="../index.php?page=<?= urlencode('help/' . $docSlug) ?>" target="_blank" rel="noopener">View</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?edit_doc=<?= $docId ?>#edit-doc">Edit</a>
                                </div>
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

    if (textarea && textarea.value.trim() !== '') {
        editor.innerHTML = textarea.value;
    }

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
