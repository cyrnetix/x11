<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\UpDown;

/** A spinner's value changed, by its arrows or by typing. */
final class UpDownChangedEvent extends AbstractUiEvent
{
    /** Records the up down. */
    public function __construct(public readonly UpDown $upDown) {}
}
