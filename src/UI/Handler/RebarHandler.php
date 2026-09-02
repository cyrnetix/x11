<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Painter\RebarPainter;
use Cyrnetix\X11\UI\Widget\Rebar;
use Cyrnetix\X11\UI\Widget\Widget;

/**
 * Rebar is paint-only for now — bands hold real Widget children (Toolbar,
 * TextBox, …) whose handlers handle input directly via the existing
 * dispatch. Drag-to-resize the band divider and drag-to-reorder bands
 * via the grip are documented Win features but not implemented here yet.
 */
final class RebarHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly RebarPainter $painter) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof Rebar) return false;
        $this->painter->paint($w, $r);
        return true;
    }
}
