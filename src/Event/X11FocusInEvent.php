<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/** The window gained keyboard focus. */
final class X11FocusInEvent extends AbstractX11Event
{
    /** Records the window id, the detail and the mode. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $detail,
        public readonly int $mode,
    ) {}
}
