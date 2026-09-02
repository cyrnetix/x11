<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Painter\GroupBoxPainter;
use Cyrnetix\X11\UI\Widget\GroupBox;
use Cyrnetix\X11\UI\Widget\Widget;

/** Paints group boxes. Display only — a group box has no behaviour of its own. */
final class GroupBoxHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly GroupBoxPainter $painter) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof GroupBox) return false;
        $this->painter->paint($w, $r);
        return true;
    }
}
