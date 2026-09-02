<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/** One column in a ListView. Width is in pixels; title is the header text. */
final class ListViewColumn
{
    /** Takes its title and size. */
    public function __construct(
        public readonly string $title,
        public int $width,
    ) {}
}
