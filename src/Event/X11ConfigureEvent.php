<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * The window was moved or resized.
 *
 * Also arrives for the position and size the window manager chose at map time, so
 * it is where an application learns its real geometry rather than the one it
 * asked for.
 */
final class X11ConfigureEvent extends AbstractX11Event
{
    /** Takes position and size. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $borderWidth,
        public readonly bool $overrideRedirect,
    ) {}
}
