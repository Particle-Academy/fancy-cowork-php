<?php

declare(strict_types=1);

/*
 * Owner ruling, 2026-11-13: no assumptions about the host's models. Identity,
 * membership and permissions come only from host resolvers. A reference to a
 * User model anywhere in the package or its tests means the package has
 * started assuming one.
 */
it('references no User model anywhere in source, config, migrations or tests', function (): void {
    $root = dirname(__DIR__, 2);
    $hits = [];
    foreach (['src', 'config', 'database', 'routes', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || realpath($file->getPathname()) === realpath(__FILE__)) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            if (preg_match('/Models\\\\User\b|\bUser::|\busers\b|->user\(\)|Authenticatable/', $text)) {
                $hits[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    expect($hits)->toBe([]);
});

it('never uses the word the owner ruled out', function (): void {
    $root = dirname(__DIR__, 2);
    $word = 'imper'.'sonat';
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/vendor/') || str_contains($path, '/.git/') || ! in_array($file->getExtension(), ['php', 'md', 'json'], true)) {
            continue;
        }
        if (stripos((string) file_get_contents($file->getPathname()), $word) !== false) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([]);
});
