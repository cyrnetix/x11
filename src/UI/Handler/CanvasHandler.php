<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Event\CanvasPhase;
use Cyrnetix\X11\UI\Painter\CanvasPainter;
use Cyrnetix\X11\UI\Widget\Canvas;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Canvas — a press captures, motion continues the stroke, release ends it. The
 * handler owns the capture and the coordinate arithmetic; what a stroke *draws*
 * belongs to the application, through {@see Canvas::setOnPaint()}.
 *
 * Two things it does that a simpler handler would not:
 *
 * **The stroke keeps its own last point.** Pointer motion arrives in jumps of
 * several pixels, so a freehand tool that plotted one pixel per event would draw
 * a dotted line. Every event carries where the previous one was, and the tool
 * joins them.
 *
 * **Coordinates are clamped, and the capture is not released at the edge.** A
 * drag that wanders off the canvas — or off the window — keeps reporting, pinned
 * to the nearest pixel, so a stroke that leaves and comes back is one stroke.
 * Releasing on the way out would strand the tool mid-preview.
 *
 * **Repainting is damage-driven.** The canvas records what the tool touched, and
 * only that rectangle is repainted: a pencil stroke is a few hundred bytes where
 * a whole-window repaint of a canvas-sized window is tens of kilobytes, on every
 * motion event. Note the limit of that — it repaints *the canvas*. A tool that
 * also changes something else (a coordinate readout in a status bar) owes that
 * widget its own repaint; see `example/paint.php`.
 */
final class CanvasHandler extends WidgetHandler
{
    private ?Canvas $captured = null;

    /** Where this stroke was last, in image coordinates. */
    private int $lastX = 0;
    private int $lastY = 0;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree    $tree,
        private readonly X11Client     $client,
        private readonly CanvasPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof Canvas) return false;

        // Deliberately does *not* clear the widget's damage. A paint may be
        // clipped to less than the damage covers — an Expose of one corner, a
        // region repaint aimed at a neighbour — and clearing it here would drop
        // an update that was never actually shown. Leaving it pending only ever
        // costs a wider blit next time.
        $this->painter->paint($w, $r);

        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $canvas = $this->findHit($event->x, $event->y);
        if ($canvas === null) return false;

        [$x, $y] = $this->imagePoint($canvas, $event->x, $event->y);

        $this->captured = $canvas;
        $this->lastX    = $x;
        $this->lastY    = $y;

        // Begin has no previous point, so it is its own — a click that never
        // moves still draws a dot, which is what a pencil should do.
        $canvas->paintAt($x, $y, $x, $y, CanvasPhase::Begin);
        $this->flushDamage($canvas);

        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        $canvas = $this->captured;
        if ($canvas === null) return false;

        [$x, $y] = $this->imagePoint($canvas, $event->x, $event->y);
        if ($x === $this->lastX && $y === $this->lastY) return true;

        $canvas->paintAt($x, $y, $this->lastX, $this->lastY, CanvasPhase::Draw);
        $this->lastX = $x;
        $this->lastY = $y;
        $this->flushDamage($canvas);

        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        $canvas = $this->captured;
        if ($canvas === null) return false;

        [$x, $y] = $this->imagePoint($canvas, $event->x, $event->y);

        $this->captured = null;
        $canvas->paintAt($x, $y, $this->lastX, $this->lastY, CanvasPhase::End);
        $this->flushDamage($canvas);

        return true;
    }

    /**
     * Repaint whatever the tool touched, and nothing else.
     *
     * The rectangle comes from the canvas rather than from the tool: the
     * primitives record what they wrote, so a tool cannot forget to report it —
     * and a tool that drew nothing costs no repaint at all.
     */
    private function flushDamage(Canvas $canvas): void
    {
        $damage = $canvas->takeDamage();
        if ($damage->isEmpty()) return;

        $this->client->redrawRegion($canvas->toWindow($damage), $canvas);
    }

    /**
     * Window coordinates → image coordinates, clamped to the paper.
     *
     * @return array{int, int}
     */
    private function imagePoint(Canvas $canvas, int $mx, int $my): array
    {
        [$x, $y] = $canvas->toImage($mx, $my);

        return [
            max(0, min($canvas->imageWidth()  - 1, $x)),
            max(0, min($canvas->imageHeight() - 1, $y)),
        ];
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?Canvas
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof Canvas && $w->hitTest($mx, $my)
        );

        return $found instanceof Canvas ? $found : null;
    }
}
