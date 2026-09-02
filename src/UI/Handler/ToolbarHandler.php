<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Painter\ToolbarPainter;
use Cyrnetix\X11\UI\Widget\Toolbar;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Toolbar — same press-capture / release-if-still-over pattern as the
 * Button widget. Hover is tracked per-toolbar so the painter can paint
 * the raised bevel; redraws only fire when the hovered slot actually
 * changes (matches the global hover-redraw policy).
 */
final class ToolbarHandler extends WidgetHandler
{
    private ?Toolbar $captured = null;
    private int      $capturedIdx = -1;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree     $tree,
        private readonly X11Client      $client,
        private readonly ToolbarPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof Toolbar) return false;
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

        $idx = $tb->hitTestItem($event->x, $event->y);
        if ($idx === -1) return true;   // hit the toolbar but on padding/separator

        $item = $tb->getItem($idx);
        if ($item === null || !$item->enabled) return true;

        $this->captured    = $tb;
        $this->capturedIdx = $idx;
        $tb->setPressedIndex($idx);
        $this->client->redraw();
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;
        $tb         = $this->captured;
        $capturedIx = $this->capturedIdx;
        $this->captured    = null;
        $this->capturedIdx = -1;
        $tb->setPressedIndex(-1);

        // Fire only if release landed on the same item we captured.
        $stillOver = $tb->hitTestItem($event->x, $event->y) === $capturedIx;
        if ($stillOver) {
            $tb->activate($capturedIx);
        }
        $this->client->redraw();
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        // Purely visual and on every motion event, so only the bars whose
        // hover actually moved are repainted.
        $changed = [];
        $this->tree->visitAll(function (Widget $w) use ($event, &$changed): void {
            if (!$w instanceof Toolbar) return;
            if ($w->setHoveredIndex($w->hitTestItem($event->x, $event->y))) $changed[] = $w;
        });
        if ($changed !== []) $this->repaint($this->client, $changed);
        return false;
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?Toolbar
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof Toolbar && $w->hitTest($mx, $my)
        );
        return $found instanceof Toolbar ? $found : null;
    }
}
