<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * Decodes an icon file into an {@see Icon}. One implementation per container
 * format; {@see IconRegistry} picks by file extension, so a theme can ship
 * whatever its era actually used — .ico for Windows, .png for the Mac set.
 */
interface IconLoader
{
    /** @throws \RuntimeException when the file can't be read or parsed. */
    public function load(string $path): Icon;
}
