<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

use React\Filesystem\AdapterInterface;
use React\Filesystem\Node\DirectoryInterface;
use React\Filesystem\Node\FileInterface;
use React\Filesystem\Node\NodeInterface;
use React\Filesystem\Stat;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Reads one directory into {@see FileEntry} rows for the file dialog.
 *
 * Two backends behind one promise-returning method. Without an $adapter it
 * reads the directory directly, which is what react/filesystem's own Fallback
 * adapter does anyway — so that path costs nothing and keeps react/filesystem
 * an optional dependency rather than one every consumer of this toolkit has to
 * install (and, since its only usable release is a dev version, has to lower
 * their minimum-stability for). Hand it an Eio or Uv adapter and the listing
 * becomes genuinely non-blocking, with no change here or in any caller.
 *
 * A directory we may see but not read resolves to an empty listing rather than
 * rejecting: the dialog shows an empty folder, which is what the user can act
 * on, instead of an error it can't do anything about. An entry we can't stat
 * still appears, with no size and no date — hiding a file the user can see in
 * their terminal would be worse than showing it without its details.
 */
final class DirectoryLister
{
    /**
     * @param AdapterInterface|null $adapter react/filesystem adapter for a
     *        non-blocking read; null reads the directory directly.
     */
    public function __construct(
        private readonly ?AdapterInterface $adapter = null,
    ) {}

    /**
     * @return PromiseInterface<list<FileEntry>> Directories first, then files,
     *         each group alphabetical — the order every file dialog opens in.
     */
    public function list(string $path): PromiseInterface
    {
        $dir = FilePath::normalise($path);

        // Checked up front because the fallback adapter calls scandir()
        // unguarded: handing it a directory we can't read raises three PHP
        // warnings inside vendor code before the promise resolves empty
        // anyway. This is knowable synchronously, so ask first.
        if (!is_dir($dir) || !is_readable($dir)) return resolve([]);

        if ($this->adapter === null) {
            return resolve($this->readDirectly($dir));
        }

        return $this->adapter->detect($dir)
            ->then(static function (NodeInterface $node): PromiseInterface {
                if (!$node instanceof DirectoryInterface) return resolve([]);
                return $node->ls();
            })
            ->then(fn(array $entries): PromiseInterface => $this->statAll($dir, $entries))
            ->then(null, static fn(\Throwable $_): array => []);
    }

    /**
     * scandir + stat, in one pass.
     *
     * @return list<FileEntry>
     */
    private function readDirectly(string $dir): array
    {
        $names = @scandir($dir);
        if ($names === false) return [];

        $listed = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;

            $path  = FilePath::join($dir, $name);
            $isDir = is_dir($path);

            $listed[] = new FileEntry(
                name:     $name,
                path:     $path,
                isDir:    $isDir,
                // A broken symlink or a file in a directory we may list but not
                // stat still belongs in the listing, just without its details.
                size:     $isDir ? 0 : (int) (@filesize($path) ?: 0),
                mtime:    (int) (@filemtime($path) ?: 0),
                readable: is_readable($path),
            );
        }

        return self::sorted($listed);
    }

    /**
     * @param array<NodeInterface> $entries
     * @return PromiseInterface<list<FileEntry>>
     */
    private function statAll(string $dir, array $entries): PromiseInterface
    {
        $pending = [];

        foreach ($entries as $entry) {
            $isDir  = $entry instanceof DirectoryInterface;
            $isFile = $entry instanceof FileInterface;
            // ls() also yields NotExist / Unknown nodes — a symlink whose
            // target is gone. Nothing sensible to show for those.
            if (!$isDir && !$isFile) continue;

            $name = $entry->name();
            if ($name === '' || $name === '.' || $name === '..') continue;

            $path = FilePath::join($dir, $name);

            $pending[] = $entry->stat()->then(
                static fn(Stat $stat): FileEntry => new FileEntry(
                    name:     $name,
                    path:     $path,
                    isDir:    $isDir,
                    size:     $isDir ? 0 : ($stat->size() ?? 0),
                    mtime:    $stat->mtime()?->getTimestamp() ?? 0,
                    readable: is_readable($path),
                ),
                // Unstattable, but real enough to list.
                static fn(\Throwable $_): FileEntry => new FileEntry(
                    name:     $name,
                    path:     $path,
                    isDir:    $isDir,
                    readable: is_readable($path),
                ),
            );
        }

        if ($pending === []) return resolve([]);

        return all($pending)->then(static fn(array $listed): array => self::sorted($listed));
    }

    /**
     * Directories first, then files, each group alphabetical — the order every
     * file dialog opens in, and shared by both backends so which one is in use
     * can never show.
     *
     * @param list<FileEntry> $listed
     * @return list<FileEntry>
     */
    private static function sorted(array $listed): array
    {
        usort($listed, static function (FileEntry $a, FileEntry $b): int {
            if ($a->isDir !== $b->isDir) return $a->isDir ? -1 : 1;
            return strnatcasecmp($a->name, $b->name);
        });

        return array_values($listed);
    }
}
