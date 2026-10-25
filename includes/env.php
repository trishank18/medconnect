<?php

/** Load simple KEY=value configuration from the project .env without exposing values. */
function medconnect_load_env_file(string $path = __DIR__ . '/../.env'): void
{
    static $loadedPaths = [];
    $resolvedPath = realpath($path) ?: $path;
    if (isset($loadedPaths[$resolvedPath]) || !is_file($resolvedPath)) {
        return;
    }
    $loadedPaths[$resolvedPath] = true;

    $lines = file($resolvedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $trimmed, 2));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        // Process-level configuration takes precedence over the project file.
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] ??= $value;
            $_SERVER[$key] ??= $value;
        }
    }
}
