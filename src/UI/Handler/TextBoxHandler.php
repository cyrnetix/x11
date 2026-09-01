<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Painter\TextBoxPainter;
use Cyrnetix\X11\UI\Widget\TextBox;
use Cyrnetix\X11\UI\Widget\Widget;

/**
 * TextBox — paint-only handler. The interactive parts (caret position on
 * click, drag-to-extend selection, key-driven editing) are owned by
 * EditableTextHandler so the same code drives ComboBox's text area too.
 */
final class TextBoxHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly TextBoxPainter $painter) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof TextBox) return false;
        $this->painter->paint($w, $r);
        return true;
    }
}
