<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\DropDown;

/** A drop-down's selected item changed. */
final class DropDownChangedEvent extends AbstractUiEvent
{
    /** Records the drop down. */
    public function __construct(public readonly DropDown $dropDown) {}
}
