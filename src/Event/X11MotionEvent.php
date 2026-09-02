<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * The pointer moved.
 *
 * Note the argument order: root coordinates come **before** window coordinates,
 * unlike {@see X11ButtonPressEvent}. Getting them the wrong way round produces a
 * drag that silently does nothing.
 */
final class X11MotionEvent extends AbstractX11Event
{
    /** Takes position. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $rootX,
        public readonly int $rootY,
        public readonly int $x,
        public readonly int $y,
        public readonly int $time,
        public readonly int $state,
    ) {}
}
