<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Trackbar;

/** A slider's value changed. Fires continuously while dragging. */
final class TrackbarChangedEvent extends AbstractUiEvent
{
    /** Records the trackbar. */
    public function __construct(public readonly Trackbar $trackbar) {}
}
