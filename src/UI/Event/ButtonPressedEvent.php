<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Button;

/** A button went down. Useful for press-and-hold; for "was activated", see {@see ButtonClickedEvent}. */
final class ButtonPressedEvent extends AbstractUiEvent
{
    /** Records the button. */
    public function __construct(public readonly Button $button) {}
}
