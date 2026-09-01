<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

use Cyrnetix\X11\Drawing\IconName;

/**
 * The sidebar shortcuts: home, the XDG user directories, the filesystem root
 * and whatever is mounted under /media and /mnt.
 *
 * Every candidate is probed before it's offered, because a sidebar entry that
 * leads nowhere is worse than a missing one — a headless box has no Desktop,
 * and this is exactly the kind of list that otherwise rots into dead links.
 */
final class FilePlaces
{
    /** Where removable and extra volumes turn up on a Linux desktop. */
    private const MOUNT_ROOTS = ['/media', '/mnt', '/run/media'];

    /**
     * XDG user dirs, in the order both reference dialogs list them. Names come
     * from user-dirs.dirs when it exists, so a localised setup gets its own
     * "Documents" rather than the English guess.
     */
    private const USER_DIRS = [
        'XDG_DESKTOP_DIR'   => ['Desktop',   IconName::Desktop],
        'XDG_DOCUMENTS_DIR' => ['Documents', IconName::Documents],
        'XDG_DOWNLOAD_DIR'  => ['Downloads', IconName::Folder],
        'XDG_MUSIC_DIR'     => ['Music',     IconName::WaveSound],
        'XDG_PICTURES_DIR'  => ['Pictures',  IconName::BitmapImage],
        'XDG_VIDEOS_DIR'    => ['Videos',    IconName::MovieClip],
    ];

    /** @return list<FilePlace> */
    public function all(): array
    {
        $home   = FilePath::home();
        $places = [new FilePlace(FilePath::name($home), $home, IconName::Documents)];

        $configured = $this->userDirs($home);

        foreach (self::USER_DIRS as $key => [$fallback, $icon]) {
            $path = $configured[$key] ?? FilePath::join($home, $fallback);
            if (is_dir($path) && $path !== $home) {
                $places[] = new FilePlace(FilePath::name($path), $path, $icon);
            }
        }

        $places[] = new FilePlace('File System', '/', IconName::HardDrive);

        foreach ($this->volumes() as $path) {
            $places[] = new FilePlace(FilePath::name($path), $path, IconName::RemovableDrive);
        }

        return $places;
    }

    /**
     * XDG_*_DIR values from ~/.config/user-dirs.dirs.
     *
     * Parsed rather than shelled out to `xdg-user-dir`, which isn't installed
     * everywhere. Lines look like XDG_MUSIC_DIR="$HOME/Muziek".
     *
     * @return array<string, string>
     */
    private function userDirs(string $home): array
    {
        $file = FilePath::join($home, '.config/user-dirs.dirs');
        if (!is_file($file) || !is_readable($file)) return [];

        $dirs  = [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!preg_match('/^(XDG_[A-Z]+_DIR)="?(.*?)"?$/', $line, $m)) continue;

            $path = str_replace('$HOME', $home, $m[2]);
            if ($path !== '' && $path !== $home) $dirs[$m[1]] = FilePath::normalise($path);
        }

        return $dirs;
    }

    /**
     * Mounted volumes, one level below each mount root. /media/<user>/<volume>
     * is the modern layout, so a directory whose children are all directories
     * and which carries the user's own name is descended into once.
     *
     * @return list<string>
     */
    private function volumes(): array
    {
        $found = [];
        $user  = FilePath::name(FilePath::home());

        foreach (self::MOUNT_ROOTS as $root) {
            if (!is_dir($root) || !is_readable($root)) continue;

            foreach ($this->subdirectories($root) as $path) {
                if (FilePath::name($path) === $user) {
                    // /media/<user>/ — the volumes are one level further in.
                    foreach ($this->subdirectories($path) as $volume) $found[] = $volume;
                    continue;
                }
                $found[] = $path;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function subdirectories(string $dir): array
    {
        $names = @scandir($dir);
        if ($names === false) return [];

        $out = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = FilePath::join($dir, $name);
            if (is_dir($path)) $out[] = $path;
        }
        return $out;
    }
}
