<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * Fired when another client wants us (the current selection owner) to hand
 * over the selection in a particular target format. We respond by writing
 * the bytes to ($requestor, $property) and sending a SelectionNotify back.
 */
final class X11SelectionRequestEvent extends AbstractX11Event
{
    /** Records who wants our selection, which one, and where to put the answer. */
    public function __construct(
        public readonly int $time,
        public readonly int $owner,
        public readonly int $requestor,
        public readonly int $selection,
        public readonly int $target,
        public readonly int $property,
    ) {}
}
