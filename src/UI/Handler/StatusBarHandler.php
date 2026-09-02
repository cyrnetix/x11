<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Painter\StatusBarPainter;
use Cyrnetix\X11\UI\Widget\StatusBar;
use Cyrnetix\X11\UI\Widget\Widget;

/** Paints the status bar and its panes. Display only. */
final class StatusBarHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly StatusBarPainter $painter) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof StatusBar) return false;
        $this->painter->paint($w, $r);
        return true;
    }
}
