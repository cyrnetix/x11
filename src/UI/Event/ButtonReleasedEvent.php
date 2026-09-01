<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Button;

/** A button came up, whether or not the pointer was still on it. */
final class ButtonReleasedEvent extends AbstractUiEvent
{
    /** Records the button. */
    public function __construct(public readonly Button $button) {}
}
