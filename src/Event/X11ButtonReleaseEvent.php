<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * A mouse button came back up.
 *
 * Delivered to whichever window the press went to while a pointer grab is in
 * force, which is how a drag keeps receiving events after leaving the widget it
 * started on.
 */
final class X11ButtonReleaseEvent extends AbstractX11Event
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
