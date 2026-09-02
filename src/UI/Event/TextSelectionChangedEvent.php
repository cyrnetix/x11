<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\EditableText;

/**
 * Fires whenever an EditableText widget's caret or selection range moves —
 * from a click, drag, arrow key, typing, paste, delete, Ctrl+A, anything.
 * Listeners can read $widget->getCursor() / getSelectionStart() etc.
 */
final class TextSelectionChangedEvent extends AbstractUiEvent
{
    /** Records the widget. */
    public function __construct(public readonly EditableText $widget) {}
}
