<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\DateTimePicker;

/** A date/time picker's value changed. */
final class DateTimeChangedEvent extends AbstractUiEvent
{
    /** Records the picker. */
    public function __construct(public readonly DateTimePicker $picker) {}
}
