<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * A mouse button went down.
 *
 * `x`/`y` are relative to the window it happened in; `rootX`/`rootY` are on the
 * screen, which is what a window drag needs. `state` is the modifier mask at the
 * time of the press.
 */
final class X11ButtonPressEvent extends AbstractX11Event
{
    /** Takes position. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $button,
        public readonly int $x,
        public readonly int $y,
        public readonly int $rootX,
        public readonly int $rootY,
        public readonly int $time,
        public readonly int $state,
    ) {}
}
