<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

/**
 * Where in a stroke a {@see CanvasPaintEvent} falls.
 *
 * A tool needs all three and they are not interchangeable: a shape tool takes
 * its snapshot on {@see Begin}, redraws the preview from it on every
 * {@see Draw}, and commits on {@see End}. A pencil ignores the distinction and
 * just joins the last point to this one.
 */
enum CanvasPhase
{
    /** The pointer went down. There is no previous point: it equals this one. */
    case Begin;

    /** The pointer moved with the button held. */
    case Draw;

    /** The button came up. The coordinates are where it came up. */
    case End;
}
