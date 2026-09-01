<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\MessageBoxResult;
use Cyrnetix\X11\UI\Widget\MessageBox;

/**
 * A message box was dismissed, and how.
 *
 * The result is the button the user chose, so an application answers this rather
 * than blocking on the dialog.
 */
final class MessageBoxClosedEvent extends AbstractUiEvent
{
    /** Records the dialog and the result. */
    public function __construct(
        public readonly MessageBox       $dialog,
        public readonly MessageBoxResult $result,
    ) {}
}
