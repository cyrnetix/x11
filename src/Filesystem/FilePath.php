<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

/**
 * Path arithmetic and the two display formats the file list needs.
 *
 * Kept separate from {@see DirectoryLister} because none of it touches the
 * disk: the file dialog calls these while laying out a breadcrumb or a row,
 * which happens far more often than a directory is read.
 */
final class FilePath
{
    /** Absolute, symlink-free where possible, and never with a trailing slash. */
    public static function normalise(string $path): string
    {
        if ($path === '') return self::home();

        $real = realpath($path);
        if ($real !== false) return $real === '/' ? '/' : rtrim($real, '/');

        // Doesn't exist (yet) — a Save filename, or a stale place. Keep the
        // caller's string but make it predictable to compare.
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** The home. */
    public static function home(): string
    {
        $home = getenv('HOME');
        return is_string($home) && $home !== '' ? self::normalise($home) : '/';
    }

    /** Parent directory, or null at the root — which is what stops "Up". */
    public static function parent(string $path): ?string
    {
        $path = self::normalise($path);
        if ($path === '/' || $path === '') return null;

        $parent = dirname($path);
        return $parent === $path ? null : $parent;
    }

    /** Joins a directory and a name with exactly one separator between them. */
    public static function join(string $dir, string $name): string
    {
        if ($name === '') return $dir;
        if ($name[0] === '/') return self::normalise($name);

        return ($dir === '/' ? '' : rtrim($dir, '/')) . '/' . $name;
    }

    /** The last component of a path, with any trailing slash ignored. */
    public static function name(string $path): string
    {
        $path = self::normalise($path);
        return $path === '/' ? '/' : basename($path);
    }

    /**
     * The path as a breadcrumb, root first.
     *
     * The home directory collapses to a single crumb rather than spelling out
     * /home/<user>, the way both references do — the user's own folder reads as
     * one place, not as two levels of someone else's filesystem.
     *
     * @return list<array{label: string, path: string}>
     */
    public static function segments(string $path): array
    {
        $path = self::normalise($path);
        $home = self::home();

        if ($path === $home || str_starts_with($path, $home . '/')) {
            $crumbs = [['label' => self::name($home), 'path' => $home]];
            $rest   = trim(substr($path, strlen($home)), '/');
        } else {
            $crumbs = [['label' => '/', 'path' => '/']];
            $rest   = trim($path, '/');
        }

        $cursor = $crumbs[0]['path'];
        foreach ($rest === '' ? [] : explode('/', $rest) as $part) {
            $cursor   = self::join($cursor, $part);
            $crumbs[] = ['label' => $part, 'path' => $cursor];
        }

        return $crumbs;
    }

    /**
     * Size for the Size column. Directories show nothing at all (as in both
     * references — a directory's own size is meaningless to someone picking a
     * file), and bytes stay bytes below 1 kB so small files don't all read
     * "0.0 KB".
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes === 1 ? '1 byte' : "$bytes bytes";

        foreach (['KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            $scaled = $bytes / (1024 ** ($i + 1));
            if ($scaled < 1024 || $unit === 'TB') {
                return sprintf($scaled < 10 ? '%.1f %s' : '%.0f %s', $scaled, $unit);
            }
        }

        return "$bytes bytes";   // unreachable; keeps the return type honest
    }

    /**
     * Modification time. The format is deliberately sortable as text
     * (year first, zero-padded), because that's what lets the Modified column
     * sort chronologically through {@see \Cyrnetix\X11\UI\Widget\ListView}'s
     * plain string comparison.
     */
    public static function formatTime(int $mtime): string
    {
        return $mtime > 0 ? date('Y-m-d H:i', $mtime) : '';
    }
}
