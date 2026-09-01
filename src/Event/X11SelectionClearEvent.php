<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * Fired when another client has stolen the selection from us by calling
 * SetSelectionOwner. We should drop our cached owned-clipboard text.
 */
final class X11SelectionClearEvent extends AbstractX11Event
{
    /** Records the time, the owner and the selection. */
    public function __construct(
        public readonly int $time,
        public readonly int $owner,
        public readonly int $selection,
    ) {}
}
