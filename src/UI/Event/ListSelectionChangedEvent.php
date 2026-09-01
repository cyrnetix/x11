<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\ListBox;

/** A list box's selection changed. */
final class ListSelectionChangedEvent extends AbstractUiEvent
{
    /** Records the list. */
    public function __construct(public readonly ListBox $list) {}
}
