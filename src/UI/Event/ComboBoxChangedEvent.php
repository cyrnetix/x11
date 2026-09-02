<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\ComboBox;

/**
 * A combo box's value changed.
 *
 * `$fromList` distinguishes picking an item from typing one, which matters when a
 * combo box accepts values that are not in its list.
 */
final class ComboBoxChangedEvent extends AbstractUiEvent
{
    /** Records the combo box and the from list. */
    public function __construct(
        public readonly ComboBox $comboBox,
        /** True if change came from a list pick; false if from typing. */
        public readonly bool $fromList,
    ) {}
}
