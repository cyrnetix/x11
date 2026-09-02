<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/** The window was withdrawn from the screen. */
final class X11UnmapEvent extends AbstractX11Event
{
    /** Records the window id and the from configure. */
    public function __construct(
        public readonly int $windowId,
        public readonly bool $fromConfigure,
    ) {}
}
