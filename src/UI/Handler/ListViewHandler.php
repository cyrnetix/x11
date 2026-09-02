<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Painter\ListViewPainter;
use Cyrnetix\X11\UI\Widget\ListView;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * ListView — column-header click sorts, row click selects, double-click on
 * a row fires activation (ListViewItemActivatedEvent). Keyboard nav walks
 * the row list and keeps the scrollbar in sync; Enter activates the
 * selected row.
 */
final class ListViewHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree          $tree,
        private readonly X11Client           $client,
        private readonly DoubleClickDetector $doubleClick,
        private readonly ListViewPainter     $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof ListView) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $lv = $this->findHit($event->x, $event->y);
        if ($lv === null) return false;

        $col = $lv->hitTestHeader($event->x, $event->y);
        if ($col !== -1) {
            $lv->sortBy($col);
            $this->client->redraw();
            return true;
        }

        $idx = $lv->hitTestItem($event->x, $event->y);
        if ($idx !== -1) {
            if ($lv->setSelectedIndex($idx)) $this->client->redraw();

            $item = $lv->getItems()[$idx];
            if ($this->doubleClick->detect($item, $event->time, $event->x, $event->y)) {
                $lv->activateItem($idx);
            }
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
        if (!$focused instanceof ListView) return false;

        $cnt = count($focused->getItems());
        if ($cnt === 0) return false;

        if ($key === 'Enter') {
            $sel = $focused->getSelectedIndex();
            if ($sel >= 0) $focused->activateItem($sel);
            $this->client->redraw();
            return true;
        }

        $sel  = $focused->getSelectedIndex();
        $page = $focused->visibleRowCount();
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
    private function findHit(int $mx, int $my): ?ListView
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof ListView && $w->hitTest($mx, $my)
        );
        return $found instanceof ListView ? $found : null;
    }
}
