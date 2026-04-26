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
