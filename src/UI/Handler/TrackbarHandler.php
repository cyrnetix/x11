<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Painter\TrackbarPainter;
use Cyrnetix\X11\UI\Widget\Trackbar;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Trackbar — press on the thumb captures for drag, press on the track
 * outside the thumb pages the value toward the cursor by pageSize.
 * Motion updates the value continuously while dragging. Keyboard nav
 * runs through tryKey when the trackbar holds focus.
 *
 * The drag offset is measured along the value axis only (X for
 * horizontal, Y for vertical), so the thumb stays under the cursor at
 * the exact grab point — not snapping its centre to the cursor.
 */
final class TrackbarHandler extends WidgetHandler
{
    private ?Trackbar $captured = null;
    /** Offset from the thumb's leading edge to the press point, in pixels. */
    private int        $dragOffset = 0;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree       $tree,
        private readonly X11Client        $client,
        private readonly TrackbarPainter  $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof Trackbar) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $tb = $this->findHit($event->x, $event->y);
        if ($tb === null) return false;

        // Thumb click → start drag, remember offset so the thumb doesn't
        // snap-centre on the cursor.
        if ($tb->hitTestThumb($event->x, $event->y)) {
            [$tx, $ty]    = $tb->thumbBounds();
            $cursorAxis   = $tb->isHorizontal() ? $event->x : $event->y;
            $thumbLeading = $tb->isHorizontal() ? $tx : $ty;
            $this->dragOffset = $cursorAxis - $thumbLeading;
            $this->captured   = $tb;
            $tb->setDragging(true);
            $this->client->redraw();
            return true;
        }

        // Track click outside the thumb → page jump TOWARD the cursor.
        $cursorAxis  = $tb->isHorizontal() ? $event->x : $event->y;
        $thumbCentre = $this->thumbCentreAxis($tb);
        $delta       = $cursorAxis < $thumbCentre ? -$tb->pageSize : +$tb->pageSize;
        if ($tb->setValue($tb->getValue() + $delta)) {
            $this->client->redraw();
        }
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        if ($this->captured === null) return false;
        $tb         = $this->captured;
        $cursorAxis = $tb->isHorizontal() ? $event->x : $event->y;

        // Convert "leading-edge pixel" → value: leading-edge axis is
        // cursorAxis - dragOffset, plus half the thumb so pixelToValue
        // can operate on the thumb's CENTRE axis (the math it assumes).
        $thumbLeading = $cursorAxis - $this->dragOffset;
        $thumbCentre  = $thumbLeading + intdiv($tb->metrics()->trackbarThumbLong, 2);
        if ($tb->setValue($tb->pixelToValue($thumbCentre))) {
            $this->client->redraw();
        }
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;
        $this->captured->setDragging(false);
        $this->captured = null;
        $this->dragOffset = 0;
        $this->client->redraw();
        return true;
    }

    /**
     * Handles key if it belongs to this widget kind. True means the event was claimed and no later
     * handler sees it.
     */
    public function tryKey(string $key): bool
    {
        $tb = $this->tree->getFocused();
        if (!$tb instanceof Trackbar) return false;

        $next = match ($key) {
            'Up', 'Right' => $tb->getValue() + $tb->step,
            'Down', 'Left' => $tb->getValue() - $tb->step,
            'PgUp'        => $tb->getValue() - $tb->pageSize,
            'PgDn'        => $tb->getValue() + $tb->pageSize,
            'Home'        => $tb->getMin(),
            'End'         => $tb->getMax(),
            default       => null,
        };
        if ($next === null) return false;

        if ($tb->setValue($next)) $this->client->redraw();
        return true;
    }

    /** The thumb's centre along the slider's own axis, which is what a drag measures against. */
    private function thumbCentreAxis(Trackbar $tb): int
    {
        [$tx, $ty, $tw, $th] = $tb->thumbBounds();
        return $tb->isHorizontal() ? $tx + intdiv($tw, 2) : $ty + intdiv($th, 2);
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?Trackbar
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof Trackbar && $w->hitTest($mx, $my)
        );
        return $found instanceof Trackbar ? $found : null;
    }
}
