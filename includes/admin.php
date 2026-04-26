<?php
declare(strict_types=1);

function wiki_admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('syncarent_wiki_admin_session');
    session_start();
}

function wiki_admin_csrf_token(): string
{
    wiki_admin_start_session();

    $existing = $_SESSION['wiki_admin_csrf'] ?? null;
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['wiki_admin_csrf'] = $token;

    return $token;
}

function wiki_admin_validate_csrf(?string $submitted): bool
{
    wiki_admin_start_session();

    $token = $_SESSION['wiki_admin_csrf'] ?? null;
    if (!is_string($token) || $token === '' || !is_string($submitted)) {
        return false;
    }

    return hash_equals($token, $submitted);
}

function wiki_admin_set_flash(string $type, string $message): void
{
    wiki_admin_start_session();
    $_SESSION['wiki_admin_flashes'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function wiki_admin_take_flashes(): array
{
    wiki_admin_start_session();
    $flashes = $_SESSION['wiki_admin_flashes'] ?? [];
    unset($_SESSION['wiki_admin_flashes']);

    return is_array($flashes) ? $flashes : [];
}

function wiki_admin_current_user(): ?array
{
    wiki_admin_start_session();

    $token = $_SESSION['wiki_admin_token'] ?? null;
    if (!is_string($token) || $token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $sessionUser = wiki_db_fetch_admin_session_user($tokenHash);
    if (!is_array($sessionUser)) {
        unset($_SESSION['wiki_admin_token']);
        return null;
    }

    if (isset($sessionUser['session_id'])) {
        wiki_db_touch_admin_session((int) $sessionUser['session_id']);
    }

    return [
        'id' => (int) ($sessionUser['admin_user_id'] ?? 0),
        'email' => (string) ($sessionUser['email'] ?? ''),
        'display_name' => (string) ($sessionUser['display_name'] ?? 'Admin'),
    ];
}

function wiki_admin_login(string $email, string $password): array
{
    wiki_admin_start_session();
    wiki_db_delete_expired_admin_sessions();

    $normalizedEmail = trim(strtolower($email));
    if ($normalizedEmail === '' || $password === '') {
        return ['ok' => false, 'error' => 'Email and password are required.'];
    }

    $user = wiki_db_fetch_admin_user_by_email($normalizedEmail);
    if (!is_array($user) || (int) ($user['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    $passwordHash = (string) ($user['password_hash'] ?? '');
    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    session_regenerate_id(true);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24);

    wiki_db_create_admin_session((int) $user['id'], $tokenHash, $expiresAt);
    wiki_db_mark_admin_last_login((int) $user['id']);
    $_SESSION['wiki_admin_token'] = $token;

    return ['ok' => true, 'error' => null];
}

function wiki_admin_logout(): void
{
    wiki_admin_start_session();

    $token = $_SESSION['wiki_admin_token'] ?? null;
    if (is_string($token) && $token !== '') {
        wiki_db_delete_admin_session(hash('sha256', $token));
    }

    $_SESSION = [];
    session_destroy();
}

function wiki_admin_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value;
}

function wiki_admin_extract_feature_ids(array $rawIds): array
{
    $ids = [];

    foreach ($rawIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function wiki_admin_upload_asset(array $file, string $folder = 'uploads'): array
{
    if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'No uploaded file was received.'];
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    $maxMb = (int) wiki_env('WIKI_ADMIN_UPLOAD_MAX_MB', '50');
    $maxBytes = max(1, $maxMb) * 1024 * 1024;
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'File size exceeds upload limit (' . $maxMb . ' MB).'];
    }

    $originalName = isset($file['name']) ? (string) $file['name'] : '';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = [
        'gif', 'jpg', 'jpeg', 'png', 'webp', 'avif', 'svg',
        'mp4', 'webm', 'ogg', 'mov', 'pdf',
    ];

    if (!in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'error' => 'File type is not allowed.'];
    }

    $safeFolder = trim(strtolower($folder), '/');
    if ($safeFolder === '' || !preg_match('/^[a-z0-9\/_-]+$/', $safeFolder) || str_contains($safeFolder, '..')) {
        $safeFolder = 'uploads';
    }

    $ym = date('Y/m');
    $filename = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    $relativeDir = 'assets/' . $safeFolder . '/' . $ym;
    $relativePath = $relativeDir . '/' . $filename;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        return ['ok' => false, 'error' => 'Unable to create upload folder.'];
    }

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        return ['ok' => false, 'error' => 'Unable to move uploaded file.'];
    }

    return ['ok' => true, 'path' => $relativePath];
}
