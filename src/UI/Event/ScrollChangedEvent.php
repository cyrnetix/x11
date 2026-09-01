<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\ScrollBar;

/** A scrollbar moved. Fires continuously during a drag, so listeners must be cheap. */
final class ScrollChangedEvent extends AbstractUiEvent
{
    /** Records the scroll bar. */
    public function __construct(public readonly ScrollBar $scrollBar) {}
}
