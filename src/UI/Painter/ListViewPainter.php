<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\TextClip;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\ListView;
use Cyrnetix\X11\UI\Widget\ListViewItem;

/**
 * Details-mode list:
 *
 *   sunken frame
 *   ─────────────────────────────
 *   header strip:  │ col title  ▴│  ← sort indicator on the active column
 *   ─────────────────────────────
 *   content rows; the selected row is filled with the highlight
 */
final class ListViewPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(ListView $lv, Renderer $r): void
    {
        $this->themes->chrome()->edge(
            $r,
            Rect::of($lv->x, $lv->y, $lv->width, $lv->height),
            Edge::Sunken,
        );

        $this->paintHeader($lv, $r);
        $this->paintRows($lv, $r);

        // With both bars up, neither reaches the corner where they meet; fill it
        // or it keeps whatever was on screen before.
        if ($lv->needsHorizontalScroll()) {
            $m = $this->themes->metrics();
            $this->themes->chrome()->fill(
                $r,
                Rect::of(
                    $lv->x + $lv->width  - $m->listViewBorder - $m->scrollBarThickness,
                    $lv->y + $lv->height - $m->listViewBorder - $m->scrollBarThickness,
                    $m->scrollBarThickness,
                    $m->scrollBarThickness,
                ),
                Surface::Face,
            );
        }
    }

    /** Paints the header. */
    private function paintHeader(ListView $lv, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $strip = Rect::of(
            $lv->x + $m->listViewBorder,
            $lv->y + $m->listViewBorder,
            $lv->width - 2 * $m->listViewBorder,
            $m->listViewHeaderHeight,
        );

        // Backstop fill for any slack to the right of the last column.
        $chrome->fill($r, $strip, Surface::Face);

        $cursor  = $strip->x - $lv->horizontalOffset();
        $sortCol = $lv->getSortColumn();

        // Scrolled sideways, the first column starts left of the frame — so the
        // strip is a clip, not just a fill, or a half-scrolled header would
        // paint over the border and out into the widget beside it.
        $r->withClip($strip, function () use ($lv, $r, $chrome, $m, $strip, $cursor, $sortCol): void {
            foreach ($lv->getColumns() as $i => $col) {
                $cell = Rect::of($cursor, $strip->y, $col->width, $strip->height);
                $cursor += $col->width;

                // Wholly off one end: nothing to draw, and skipping it keeps a
                // very wide grid from costing a request per hidden column.
                if ($cell->right() < $strip->x || $cell->x > $strip->right()) continue;

                $chrome->headerCell($r, $cell, ControlState::Normal);

                $chrome->text(
                    $r,
                    $col->title,
                    $cell->x + $m->listViewCellPadding,
                    $r->baselineYForRect($cell->y, $cell->height),
                    TextStyle::Normal,
                );

                if ($i === $sortCol) {
                    $chrome->sortIndicator(
                        $r,
                        Rect::of($cell->right() - 13, $cell->y, 8, $cell->height),
                        $lv->isSortAscending(),
                    );
                }
            }
        });
    }

    /** Paints the rows. */
    private function paintRows(ListView $lv, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();

        $viewport = Rect::of(
            $lv->x + $m->listViewBorder,
            $lv->y + $m->listViewBorder + $m->listViewHeaderHeight,
            $lv->viewportWidth(),
            $lv->viewportHeight(),
        );
        $chrome->fill($r, $viewport, Surface::Content);

        $items       = $lv->getItems();
        $columns     = $lv->getColumns();
        $visible     = $lv->visibleRowCount();
        $scroll      = $lv->getScrollBar()->getValue();
        $selectedIdx = $lv->getSelectedIndex();
        $offset      = $lv->horizontalOffset();

        // Same reason as the header: a scrolled row starts left of the viewport.
        $r->withClip($viewport, function () use (
            $lv, $r, $chrome, $m, $viewport, $items, $columns, $visible, $scroll, $selectedIdx, $offset
        ): void {
            for ($i = 0; $i < $visible; $i++) {
                $itemIdx = $scroll + $i;
                if (!isset($items[$itemIdx])) break;

                $row = Rect::of(
                    $viewport->x,
                    $viewport->y + $i * $m->listViewRowHeight,
                    $viewport->width,
                    $m->listViewRowHeight,
                );

                if ($itemIdx === $selectedIdx) {
                    $chrome->fill($r, $row, Surface::Selection);
                    $style = TextStyle::Selected;
                } else {
                    $style = TextStyle::Content;
                }

                $this->paintItemRow($r, $row, $items[$itemIdx], $columns, $style, $offset);
            }
        });
    }

    /**
     * @param list<\Cyrnetix\X11\UI\Widget\ListViewColumn> $columns
     */
    private function paintItemRow(
        Renderer $r,
        Rect $row,
        ListViewItem $item,
        array $columns,
        TextStyle $style,
        int $offset,
    ): void {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $cursor   = $row->x - $offset;
        $baseline = $r->baselineYForRect($row->y, $row->height);

        foreach ($columns as $i => $col) {
            $textX  = $cursor + $m->listViewCellPadding;
            $cellR  = $cursor + $col->width;
            $cursor = $cellR;

            // Scrolled out of sight on either side.
            if ($cellR < $row->x || $textX > $row->right()) continue;

            // Icon lives only in the first column.
            if ($i === 0 && $item->iconDrawer !== null) {
                $iconY = $row->y + intdiv($row->height - $m->listViewIconWidth, 2);
                ($item->iconDrawer)($r, $textX, $iconY, $m->listViewIconWidth);
                $textX += $m->listViewIconWidth + $m->listViewIconGap;
            }

            $value = $item->values[$i] ?? '';
            if ($value !== '') {
                // Trimmed to the cell as well as clipped to the viewport: the
                // clip stops a value spilling out of the list, this stops it
                // spilling into the next column.
                $maxW = $cellR - $textX - 2;
                $chrome->text($r, TextClip::toWidth($value, $maxW, $r), $textX, $baseline, $style);
            }
        }
    }
}
