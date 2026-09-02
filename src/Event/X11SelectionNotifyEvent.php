<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * Fired when a selection owner has finished delivering data to the requestor
 * (i.e. they've called ChangeProperty + SendEvent). $property is 0 when the
 * conversion failed (no owner, owner refused the target, etc.).
 */
final class X11SelectionNotifyEvent extends AbstractX11Event
{
    /** Records who asked, which selection, and the property the answer was written to. */
    public function __construct(
        public readonly int $time,
        public readonly int $requestor,
        public readonly int $selection,
        public readonly int $target,
        public readonly int $property,
    ) {}
}
