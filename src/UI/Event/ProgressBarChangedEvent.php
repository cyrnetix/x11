<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\ProgressBar;

/** A progress bar's value was set. */
final class ProgressBarChangedEvent extends AbstractUiEvent
{
    /** Records the bar. */
    public function __construct(public readonly ProgressBar $bar) {}
}
