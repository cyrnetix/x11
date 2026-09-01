<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * Part of a window needs redrawing.
 *
 * The rectangle is the damaged region. `count` is how many more Expose events
 * are still queued for the same window, so a repaint can be deferred until the
 * last of them.
 */
final class X11ExposeEvent extends AbstractX11Event
{
    /** Takes position and size. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $count,
    ) {}
}
