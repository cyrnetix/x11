<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Painter\ScrollBarPainter;
use Cyrnetix\X11\UI\Widget\ScrollBar;
use Cyrnetix\X11\UI\Widget\Scrollable;
use Cyrnetix\X11\UI\Widget\ScrollOrientation;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * ScrollBar — region-aware press (button caps, page-band, thumb), drag
 * tracking on motion when the thumb is captured, release clears all
 * capture state. Note ScrollBar lives as a CHILD of a Scrollable container
 * (ListBox/TreeView/ListView/...), so its hit-test wins over the parent's
 * — that ordering is preserved by putting this handler before the
 * list-family handlers in the dispatch list.
 */
final class ScrollBarHandler extends WidgetHandler
{
    private ?ScrollBar $captured   = null;
    private string     $region     = '';
    private int        $dragOffset = 0;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree       $tree,
        private readonly X11Client        $client,
        private readonly ScrollBarPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof ScrollBar) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $sb = $this->findHit($event->x, $event->y);
        if ($sb === null) return false;

        $region = $sb->hitTestRegion($event->x, $event->y);
        if ($region === null) return true;   // missed the inner regions but we own the press

        $this->captured = $sb;
        $this->region   = $region;
        $sb->setPressedRegion($region);

        switch ($region) {
            case ScrollBar::REGION_LESS:
                $sb->scrollBy(-$sb->step);
                break;
            case ScrollBar::REGION_MORE:
                $sb->scrollBy($sb->step);
                break;
            case ScrollBar::REGION_PAGE_LESS:
                $sb->scrollBy(-$sb->pageSize);
                break;
            case ScrollBar::REGION_PAGE_MORE:
                $sb->scrollBy($sb->pageSize);
                break;
            case ScrollBar::REGION_THUMB:
                [$thumbOffset] = $sb->getThumbBounds();
                $local = $sb->orientation === ScrollOrientation::Vertical
                    ? $event->y - $sb->y - $sb->trackOffset()
                    : $event->x - $sb->x - $sb->trackOffset();
                $this->dragOffset = $local - $thumbOffset;
                break;
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
        if ($this->captured === null || $this->region !== ScrollBar::REGION_THUMB) return false;

        $sb    = $this->captured;
        $local = $sb->orientation === ScrollOrientation::Vertical
            ? $event->y - $sb->y - $sb->trackOffset()
            : $event->x - $sb->x - $sb->trackOffset();

        if ($sb->setValue($sb->valueForThumbOffset($local - $this->dragOffset))) {
            // Only the scrolled widget changed, and this runs on every motion
            // event — so repaint that rectangle, and only that widget, rather
            // than clearing and redrawing the whole window each time.
            [$region, $subtree] = $this->damageFor($sb);
            $this->client->redrawRegion($region, $subtree);
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
        $this->captured->setPressedRegion(null);
        $this->captured   = null;
        $this->region     = '';
        $this->dragOffset = 0;
        $this->client->redraw();
        return true;
    }

    /**
     * What a change to $sb can have altered.
     *
     * The bar itself, plus the widget it scrolls — a list's rows move as well as
     * its thumb, and both are inside the owner's bounds. A standalone bar owns
     * only itself.
     */
    /** @return array{Rect, Widget} the region, and the subtree that covers it */
    private function damageFor(ScrollBar $sb): array
    {
        for ($parent = $sb->parent; $parent !== null; $parent = $parent->parent) {
            // Its own bar, or any bar it embedded: a ListView has two, and the
            // rows move for the horizontal one just as much as for the vertical.
            $owns = $parent instanceof Scrollable
                && ($parent->getScrollBar() === $sb || in_array($sb, $parent->getChildren(), true));

            if ($owns) {
                // The owner fills its own bounds, so painting it is enough to
                // cover everything the clear wiped.
                return [$parent->bounds(), $parent];
            }
        }

        return [Rect::of($sb->x, $sb->y, $sb->width, $sb->height), $sb];
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?ScrollBar
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof ScrollBar && $w->hitTest($mx, $my)
        );
        return $found instanceof ScrollBar ? $found : null;
    }
}
