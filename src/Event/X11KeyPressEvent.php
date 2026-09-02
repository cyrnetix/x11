<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * A key went down.
 *
 * `keycode` is the physical key, not a character: turning it into text needs the
 * keyboard mapping and the modifier `state`, which is
 * {@see \Cyrnetix\X11\UI\KeyTranslator}'s job.
 */
final class X11KeyPressEvent extends AbstractX11Event
{
    /** Takes position. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $keycode,
        public readonly int $x,
        public readonly int $y,
        public readonly int $rootX,
        public readonly int $rootY,
        public readonly int $time,
        public readonly int $state,
    ) {}
}
