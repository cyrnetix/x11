<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

use Cyrnetix\X11\Drawing\IconName;

/** One shortcut in the file dialog's sidebar. */
final class FilePlace
{
    /** Takes its label. */
    public function __construct(
        public readonly string   $label,
        public readonly string   $path,
        public readonly IconName $icon,
    ) {}
}
