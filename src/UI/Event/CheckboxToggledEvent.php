<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Checkbox;

/** A checkbox was ticked or unticked. Read the new state from the checkbox. */
final class CheckboxToggledEvent extends AbstractUiEvent
{
    /** Records the checkbox. */
    public function __construct(public readonly Checkbox $checkbox) {}
}
