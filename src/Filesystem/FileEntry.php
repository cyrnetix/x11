<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

/**
 * One row of a directory listing: what the file dialog needs to show a line
 * and to decide what a click on it means.
 *
 * $size and $mtime are 0 for entries we couldn't stat (a directory we may
 * list but not read, a dangling symlink). $readable says whether opening it
 * is worth trying — the dialog greys the rest out rather than letting the
 * user pick something that will fail.
 */
final class FileEntry
{
    /** Takes size. */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool   $isDir,
        public readonly int    $size     = 0,
        public readonly int    $mtime    = 0,
        public readonly bool   $readable = true,
    ) {}

    /** Whether it is hidden. */
    public function isHidden(): bool
    {
        return $this->name !== '' && $this->name[0] === '.';
    }
}
