<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\RadioButton;

/** A radio button was chosen. Its group has already cleared the others. */
final class RadioToggledEvent extends AbstractUiEvent
{
    /** Records the radio. */
    public function __construct(public readonly RadioButton $radio) {}
}
