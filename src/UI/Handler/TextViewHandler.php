<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Painter\TextViewPainter;
use Cyrnetix\X11\UI\Widget\TextView;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Selecting lines in a read-only {@see TextView}: press to start a range, drag
 * to extend it, Shift+click to extend from where it was.
 *
 * Copying needs no code here — a TextView is a {@see \Cyrnetix\X11\UI\Widget\Focusable},
 * so WidgetManager's Ctrl+C already asks it for {@see TextView::copy()}, and its
 * own `handleKey()` answers Ctrl+A and the caret keys.
 *
 * Runs *after* ScrollBarHandler, like the list handlers, because the embedded
 * bars are children of the view and their hit-test has to win.
 */
final class TextViewHandler extends WidgetHandler
{
    /** Non-null while a drag is extending a selection. */
    private ?TextView $dragging = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree      $tree,
        private readonly X11Client       $client,
        private readonly TextViewPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof TextView) return false;

        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $tv = $this->findHit($event->x, $event->y);
        if ($tv === null) return false;

        // Shift extends the range that is already there, which is the one thing
        // a keyboard-less user cannot do with the arrow keys.
        $tv->selectLine($tv->lineAt($event->y), extend: ($event->state & 0x0001) !== 0);
        $this->dragging = $tv;

        $this->repaint($this->client, $tv);
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        if ($this->dragging === null) return false;

        $tv = $this->dragging;

        // Dragging past either edge scrolls, so a selection can run past the
        // bottom of a document that doesn't fit.
        $line = $tv->lineAt($event->y);
        $tv->selectLine($line, extend: true);
        $tv->scrollTo($line);

        $this->repaint($this->client, $tv);
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->dragging === null) return false;

        $this->dragging = null;
        return true;
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?TextView
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof TextView && $w->hitTest($mx, $my),
        );

        return $found instanceof TextView ? $found : null;
    }
}
