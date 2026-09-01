<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\ListBox;

/**
 * List panel: a themed content well with one row per item. The embedded
 * scrollbar is a child widget, so the tree walker paints it — not this.
 */
final class ListBoxPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(ListBox $lb, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();

        $outer = Rect::of($lb->x, $lb->y, $lb->width, $lb->height);
        $chrome->edge($r, $outer, Edge::Sunken);

        // Content viewport, excluding the scrollbar column.
        $items = $outer->insetEach(
            $m->listBoxBorder,
            $m->listBoxBorder,
            $m->listBoxBorder + $m->scrollBarThickness,
            $m->listBoxBorder,
        );
        $chrome->fill($r, $items, Surface::Content);

        $entries      = $lb->getItems();
        $count        = count($entries);
        $scrollOffset = $lb->getScrollBar()->getValue();
        $visibleCount = $lb->getVisibleItemCount();
        $selectedIdx  = $lb->getSelectedIndex();
        $itemH        = $lb->itemHeight;

        for ($i = 0; $i < $visibleCount; $i++) {
            $itemIdx = $scrollOffset + $i;
            if ($itemIdx >= $count) break;

            $row = Rect::of($items->x, $items->y + $i * $itemH, $items->width, $itemH);

            if ($itemIdx === $selectedIdx) {
                $chrome->fill($r, $row, Surface::Selection);
                $style = TextStyle::Selected;
            } else {
                $style = TextStyle::Content;
            }

            $chrome->text(
                $r,
                $entries[$itemIdx],
                $row->x + $m->listBoxItemPadding,
                $r->baselineYForRect($row->y, $itemH),
                $style,
            );
        }
    }
}
