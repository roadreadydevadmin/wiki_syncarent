<?php
declare(strict_types=1);

function wiki_load_env(string $envPath): void
{
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $pair = explode('=', $trimmed, 2);
        if (count($pair) !== 2) {
            continue;
        }

        $key = trim($pair[0]);
        $value = trim($pair[1]);

        if ($key === '') {
            continue;
        }

        $firstChar = $value[0] ?? '';
        $lastChar = substr($value, -1);
        $isQuoted = ($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'");
        if ($isQuoted && strlen($value) >= 2) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function wiki_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

