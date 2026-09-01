<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/** The window became visible on screen. */
final class X11MapEvent extends AbstractX11Event
{
    /** Records the window id and the override redirect. */
    public function __construct(
        public readonly int $windowId,
        public readonly bool $overrideRedirect,
    ) {}
}
