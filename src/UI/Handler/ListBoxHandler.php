<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\UI\Painter\ListBoxPainter;
use Cyrnetix\X11\UI\Widget\ListBox;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/** Paints list boxes and handles selection by click, arrow key and wheel. */
final class ListBoxHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree     $tree,
        private readonly X11Client      $client,
        private readonly ListBoxPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof ListBox) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $lb = $this->findHit($event->x, $event->y);
        if ($lb === null) return false;

        $idx = $lb->hitTestItem($event->x, $event->y);
        if ($idx !== -1) {
            // setSelectedIndex returns true only on change; the click
            // callback fires regardless so popup-owners can dismiss even
            // when the user re-clicks the already-selected item.
            $lb->setSelectedIndex($idx);
            $lb->notifyItemClicked($idx);
            $this->client->redraw();
        }
        return true;
    }

    /**
     * Handles key if it belongs to this widget kind. True means the event was claimed and no later
     * handler sees it.
     */
    public function tryKey(string $key): bool
    {
        $focused = $this->tree->getFocused();
        if (!$focused instanceof ListBox) return false;

        $cnt = count($focused->getItems());
        if ($cnt === 0) return false;

        $sel  = $focused->getSelectedIndex();
        $page = $focused->getVisibleItemCount();
        $cur  = $sel < 0 ? 0 : $sel;

        $next = match ($key) {
            'Up'    => max(0, $cur - 1),
            'Down'  => min($cnt - 1, $sel < 0 ? 0 : $cur + 1),
            'Home'  => 0,
            'End'   => $cnt - 1,
            'PgUp'  => max(0, $cur - $page),
            'PgDn'  => min($cnt - 1, $cur + $page),
            default => null,
        };
        if ($next === null) return false;

        $focused->setSelectedIndex($next);
        ScrollHelpers::ensureIndexVisible($focused->getScrollBar(), $next, $page);
        $this->client->redraw();
        return true;
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?ListBox
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof ListBox && $w->hitTest($mx, $my)
        );
        return $found instanceof ListBox ? $found : null;
    }
}
