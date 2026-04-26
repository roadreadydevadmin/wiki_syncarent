<?php
declare(strict_types=1);

function wiki_set_db_state(?PDO $pdo, ?string $error): void
{
    $GLOBALS['wiki_db_pdo'] = $pdo;
    $GLOBALS['wiki_db_error'] = $error;
}

function wiki_db(): ?PDO
{
    $pdo = $GLOBALS['wiki_db_pdo'] ?? null;

    return $pdo instanceof PDO ? $pdo : null;
}

function wiki_db_error(): ?string
{
    $error = $GLOBALS['wiki_db_error'] ?? null;

    return is_string($error) && $error !== '' ? $error : null;
}

function wiki_db_bootstrap(string $schemaPath): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $bootstrapped = true;
    wiki_set_db_state(null, null);

    try {
        $host = wiki_env('WIKI_DB_HOST', '127.0.0.1');
        $port = wiki_env('WIKI_DB_PORT', '3306');
        $name = wiki_env('WIKI_DB_NAME', 'syncarent_wiki');
        $user = wiki_env('WIKI_DB_USER', 'root');
        $pass = wiki_env('WIKI_DB_PASS', '');

        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('WIKI_DB_NAME may only contain letters, numbers, and underscores.');
        }

        $portInt = (int) $port;
        if ($portInt <= 0) {
            throw new RuntimeException('WIKI_DB_PORT must be a positive integer.');
        }

        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $portInt);
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $serverPdo = new PDO($serverDsn, $user, $pass, $pdoOptions);
        $serverPdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . wiki_db_quote_identifier($name)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $dbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $portInt, $name);
        $pdo = new PDO($dbDsn, $user, $pass, $pdoOptions);

        wiki_apply_schema_file($pdo, $schemaPath);
        wiki_db_ensure_default_admin($pdo);
        wiki_set_db_state($pdo, null);
    } catch (Throwable $exception) {
        wiki_set_db_state(null, $exception->getMessage());
    }
}

function wiki_db_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function wiki_apply_schema_file(PDO $pdo, string $schemaPath): void
{
    if (!is_file($schemaPath) || !is_readable($schemaPath)) {
        throw new RuntimeException('Schema file is missing or not readable: ' . $schemaPath);
    }

    $sql = file_get_contents($schemaPath);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Schema file is empty: ' . $schemaPath);
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_name VARCHAR(100) PRIMARY KEY,
            schema_hash CHAR(64) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $migrationName = 'core_schema';
    $schemaHash = hash('sha256', $sql);
    $existing = wiki_current_schema_hash($pdo, $migrationName);

    if ($existing === $schemaHash) {
        return;
    }

    $statements = wiki_split_sql_statements($sql);

    if (count($statements) === 0) {
        throw new RuntimeException('Schema file does not contain valid SQL statements.');
    }

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $migrationStatement = $pdo->prepare(
        'INSERT INTO schema_migrations (migration_name, schema_hash)
        VALUES (:migration_name, :schema_hash)
        ON DUPLICATE KEY UPDATE schema_hash = VALUES(schema_hash)'
    );

    $migrationStatement->execute([
        'migration_name' => $migrationName,
        'schema_hash' => $schemaHash,
    ]);
}

function wiki_current_schema_hash(PDO $pdo, string $migrationName): ?string
{
    $statement = $pdo->prepare(
        'SELECT schema_hash FROM schema_migrations WHERE migration_name = :migration_name LIMIT 1'
    );

    $statement->execute(['migration_name' => $migrationName]);
    $value = $statement->fetchColumn();

    return is_string($value) && $value !== '' ? $value : null;
}

function wiki_split_sql_statements(string $sql): array
{
    $lines = preg_split('/\R/', $sql);
    if (!is_array($lines)) {
        return [];
    }

    $buffer = '';
    $statements = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $buffer .= $line . "\n";

        if (str_ends_with(rtrim($line), ';')) {
            $statement = rtrim(trim($buffer), ';');
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function wiki_db_count_published_releases(): int
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return 0;
    }

    $statement = $pdo->query("SELECT COUNT(*) FROM releases WHERE status = 'publish'");
    $count = $statement->fetchColumn();

    return is_numeric($count) ? (int) $count : 0;
}

function wiki_db_fetch_published_releases(int $limit = 10, int $offset = 0): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $safeLimit = max(1, min(200, $limit));
    $safeOffset = max(0, $offset);
    $statement = $pdo->prepare(
        "SELECT id, header, status, slug, html_content, created_at, updated_at
         FROM releases
         WHERE status = 'publish'
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset"
    );
    $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function wiki_db_fetch_published_release_by_slug(string $slug): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $slug === '') {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT id, header, status, slug, html_content, created_at, updated_at
         FROM releases
         WHERE status = 'publish' AND slug = :slug
         LIMIT 1"
    );
    $statement->execute(['slug' => $slug]);
    $release = $statement->fetch();

    return is_array($release) ? $release : null;
}

function wiki_db_fetch_release_features(int $releaseId): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $releaseId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT f.id, f.header, f.slug, f.html_content, f.asset_path, rf.display_order
         FROM release_features rf
         INNER JOIN features f ON f.id = rf.feature_id
         WHERE rf.release_id = :release_id
         ORDER BY rf.display_order ASC, f.id ASC'
    );
    $statement->execute(['release_id' => $releaseId]);

    return $statement->fetchAll();
}

function wiki_db_fetch_published_help_docs(int $limit = 200, int $offset = 0): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $safeLimit = max(1, min(500, $limit));
    $safeOffset = max(0, $offset);
    $statement = $pdo->prepare(
        "SELECT id, title, status, slug, html_content, created_at, updated_at
         FROM help_docs
         WHERE status = 'publish'
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset"
    );
    $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function wiki_db_fetch_published_help_doc_by_slug(string $slug): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $slug === '') {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT id, title, status, slug, html_content, created_at, updated_at
         FROM help_docs
         WHERE status = 'publish' AND slug = :slug
         LIMIT 1"
    );
    $statement->execute(['slug' => $slug]);
    $doc = $statement->fetch();

    return is_array($doc) ? $doc : null;
}

function wiki_db_fetch_all_features(): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $statement = $pdo->query(
        'SELECT id, header, slug, asset_path, created_at, updated_at
         FROM features
         ORDER BY created_at DESC, id DESC'
    );

    return $statement->fetchAll();
}

function wiki_db_fetch_release_by_id(int $releaseId): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $releaseId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, header, status, slug, html_content, created_at, updated_at
         FROM releases
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $releaseId]);
    $release = $statement->fetch();

    return is_array($release) ? $release : null;
}

function wiki_db_fetch_feature_by_id(int $featureId): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $featureId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, header, slug, html_content, asset_path, created_at, updated_at
         FROM features
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $featureId]);
    $feature = $statement->fetch();

    return is_array($feature) ? $feature : null;
}

function wiki_db_fetch_help_doc_by_id(int $helpDocId): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $helpDocId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, title, status, slug, html_content, created_at, updated_at
         FROM help_docs
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $helpDocId]);
    $helpDoc = $statement->fetch();

    return is_array($helpDoc) ? $helpDoc : null;
}

function wiki_db_fetch_release_feature_ids(int $releaseId): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $releaseId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT feature_id
         FROM release_features
         WHERE release_id = :release_id
         ORDER BY display_order ASC, feature_id ASC'
    );
    $statement->execute(['release_id' => $releaseId]);

    $featureIds = [];
    foreach ($statement->fetchAll() as $row) {
        $featureId = (int) ($row['feature_id'] ?? 0);
        if ($featureId > 0) {
            $featureIds[] = $featureId;
        }
    }

    return $featureIds;
}

function wiki_db_create_feature(string $header, string $slug, string $htmlContent, ?string $assetPath): int
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO features (header, slug, html_content, asset_path)
         VALUES (:header, :slug, :html_content, :asset_path)'
    );
    $statement->execute([
        'header' => $header,
        'slug' => $slug,
        'html_content' => $htmlContent !== '' ? $htmlContent : null,
        'asset_path' => $assetPath !== null && $assetPath !== '' ? $assetPath : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function wiki_db_update_feature(int $featureId, string $header, string $slug, string $htmlContent, ?string $assetPath): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $statement = $pdo->prepare(
        'UPDATE features
         SET header = :header,
             slug = :slug,
             html_content = :html_content,
             asset_path = :asset_path
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $featureId,
        'header' => $header,
        'slug' => $slug,
        'html_content' => $htmlContent !== '' ? $htmlContent : null,
        'asset_path' => $assetPath !== null && $assetPath !== '' ? $assetPath : null,
    ]);
}

function wiki_db_create_release(string $header, string $status, string $slug, string $htmlContent): int
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $safeStatus = $status === 'publish' ? 'publish' : 'draft';
    $statement = $pdo->prepare(
        'INSERT INTO releases (header, status, slug, html_content)
         VALUES (:header, :status, :slug, :html_content)'
    );
    $statement->execute([
        'header' => $header,
        'status' => $safeStatus,
        'slug' => $slug,
        'html_content' => $htmlContent !== '' ? $htmlContent : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function wiki_db_update_release(int $releaseId, string $header, string $status, string $slug, string $htmlContent): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $safeStatus = $status === 'publish' ? 'publish' : 'draft';
    $statement = $pdo->prepare(
        'UPDATE releases
         SET header = :header,
             status = :status,
             slug = :slug,
             html_content = :html_content
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $releaseId,
        'header' => $header,
        'status' => $safeStatus,
        'slug' => $slug,
        'html_content' => $htmlContent !== '' ? $htmlContent : null,
    ]);
}

function wiki_db_replace_release_features(int $releaseId, array $featureIds): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->beginTransaction();

    try {
        $deleteStatement = $pdo->prepare('DELETE FROM release_features WHERE release_id = :release_id');
        $deleteStatement->execute(['release_id' => $releaseId]);

        if (count($featureIds) > 0) {
            $insertStatement = $pdo->prepare(
                'INSERT INTO release_features (release_id, feature_id, display_order)
                 VALUES (:release_id, :feature_id, :display_order)'
            );
            $order = 1;
            foreach ($featureIds as $featureId) {
                $featureIdInt = (int) $featureId;
                if ($featureIdInt <= 0) {
                    continue;
                }
                $insertStatement->execute([
                    'release_id' => $releaseId,
                    'feature_id' => $featureIdInt,
                    'display_order' => $order,
                ]);
                $order++;
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function wiki_db_create_help_doc(string $title, string $status, string $slug, string $htmlContent): int
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $safeStatus = $status === 'publish' ? 'publish' : 'draft';
    $statement = $pdo->prepare(
        'INSERT INTO help_docs (title, status, slug, html_content)
         VALUES (:title, :status, :slug, :html_content)'
    );
    $statement->execute([
        'title' => $title,
        'status' => $safeStatus,
        'slug' => $slug,
        'html_content' => $htmlContent !== '' ? $htmlContent : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function wiki_db_update_help_doc(int $helpDocId, string $title, string $status, string $slug, string $htmlContent): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $safeStatus = $status === 'publish' ? 'publish' : 'draft';
    $statement = $pdo->prepare(
        'UPDATE help_docs
         SET title = :title,
             status = :status,
             slug = :slug,
             html_content = :html_content
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $helpDocId,
        'title' => $title,
        'status' => $safeStatus,
        'slug' => $slug,
        'html_content' => $htmlContent !== '' ? $htmlContent : null,
    ]);
}

function wiki_db_fetch_recent_releases_for_admin(int $limit = 20): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $safeLimit = max(1, min(100, $limit));
    $statement = $pdo->prepare(
        'SELECT id, header, status, slug, created_at
         FROM releases
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function wiki_db_fetch_recent_features_for_admin(int $limit = 20): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $safeLimit = max(1, min(100, $limit));
    $statement = $pdo->prepare(
        'SELECT id, header, slug, asset_path, created_at
         FROM features
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function wiki_db_fetch_recent_help_docs_for_admin(int $limit = 20): array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $safeLimit = max(1, min(100, $limit));
    $statement = $pdo->prepare(
        'SELECT id, title, status, slug, created_at
         FROM help_docs
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function wiki_db_fetch_admin_user_by_email(string $email): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, email, display_name, password_hash, is_active, last_login_at, created_at, updated_at
         FROM admin_users
         WHERE LOWER(email) = LOWER(:email)
         LIMIT 1'
    );
    $statement->execute(['email' => trim($email)]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function wiki_db_mark_admin_last_login(int $adminUserId): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return;
    }

    $statement = $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
    $statement->execute(['id' => $adminUserId]);
}

function wiki_db_create_admin_session(int $adminUserId, string $tokenHash, string $expiresAt): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO admin_sessions (admin_user_id, token_hash, expires_at, last_seen_at)
         VALUES (:admin_user_id, :token_hash, :expires_at, NOW())'
    );
    $statement->execute([
        'admin_user_id' => $adminUserId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);
}

function wiki_db_fetch_admin_session_user(string $tokenHash): ?array
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $tokenHash === '') {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT s.id AS session_id, s.admin_user_id, s.expires_at, s.last_seen_at,
                u.id, u.email, u.display_name, u.is_active, u.last_login_at
         FROM admin_sessions s
         INNER JOIN admin_users u ON u.id = s.admin_user_id
         WHERE s.token_hash = :token_hash
           AND s.expires_at > NOW()
           AND u.is_active = 1
         LIMIT 1'
    );
    $statement->execute(['token_hash' => $tokenHash]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function wiki_db_touch_admin_session(int $sessionId): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return;
    }

    $statement = $pdo->prepare('UPDATE admin_sessions SET last_seen_at = NOW() WHERE id = :id');
    $statement->execute(['id' => $sessionId]);
}

function wiki_db_delete_admin_session(string $tokenHash): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO || $tokenHash === '') {
        return;
    }

    $statement = $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = :token_hash');
    $statement->execute(['token_hash' => $tokenHash]);
}

function wiki_db_delete_expired_admin_sessions(): void
{
    $pdo = wiki_db();
    if (!$pdo instanceof PDO) {
        return;
    }

    $pdo->exec('DELETE FROM admin_sessions WHERE expires_at <= NOW()');
}

function wiki_db_ensure_default_admin(PDO $pdo): void
{
    $count = $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if (is_numeric($count) && (int) $count > 0) {
        return;
    }

    $email = trim(strtolower(wiki_env('WIKI_ADMIN_DEFAULT_EMAIL', 'admin@syncarent.local')));
    $displayName = trim(wiki_env('WIKI_ADMIN_DEFAULT_NAME', 'Admin'));
    $password = wiki_env('WIKI_ADMIN_DEFAULT_PASSWORD', 'ChangeMe123!');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'admin@syncarent.local';
    }

    if ($displayName === '') {
        $displayName = 'Admin';
    }

    if (strlen($password) < 8) {
        $password = 'ChangeMe123!';
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Unable to hash default admin password.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO admin_users (email, display_name, password_hash, is_active)
         VALUES (:email, :display_name, :password_hash, 1)'
    );
    $statement->execute([
        'email' => $email,
        'display_name' => $displayName,
        'password_hash' => $passwordHash,
    ]);
}
