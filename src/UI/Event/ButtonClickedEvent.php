<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Button;

/**
 * A button was pressed and released on itself — the one to listen for.
 *
 * A press that wanders off the button before release is not a click, and does not
 * raise this.
 */
final class ButtonClickedEvent extends AbstractUiEvent
{
    /** Records the button. */
    public function __construct(public readonly Button $button) {}
}
