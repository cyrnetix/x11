<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\TextClip;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\TreeView;

/**
 * Tree view: a content well with indented rows. Each row is
 * `[expander] [icon] label`, and the expander's shape is the theme's business —
 * a boxed [+]/[−] under Windows and Motif, a disclosure triangle under Platinum.
 */
final class TreeViewPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(TreeView $tv, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();

        $outer = Rect::of($tv->x, $tv->y, $tv->width, $tv->height);
        $chrome->edge($r, $outer, Edge::Sunken);

        // Content viewport, excluding the scrollbar column.
        $viewport = $outer->insetEach(
            $m->treeBorder,
            $m->treeBorder,
            $m->treeBorder + $m->scrollBarThickness,
            $m->treeBorder,
        );
        $chrome->fill($r, $viewport, Surface::Content);

        $rows        = $tv->flattenVisibleRows();
        $scroll      = $tv->getScrollBar()->getValue();
        $visible     = $tv->getVisibleRowCount();
        $selectedRef = $tv->getSelected();

        for ($i = 0; $i < $visible; $i++) {
            $rowIdx = $scroll + $i;
            if (!isset($rows[$rowIdx])) break;

            [$node, $depth] = $rows[$rowIdx];

            $row = Rect::of(
                $viewport->x,
                $viewport->y + $i * $m->treeRowHeight,
                $viewport->width,
                $m->treeRowHeight,
            );

            $isSelected = $node === $selectedRef;
            if ($isSelected) {
                $chrome->fill($r, $row, Surface::Selection);
                $style = TextStyle::Selected;
            } else {
                $style = TextStyle::Content;
            }

            $cursorX = $row->x + $depth * $m->treeIndent + 2;

            if (!$node->isLeaf()) {
                $chrome->treeToggle(
                    $r,
                    Rect::of(
                        $cursorX,
                        $row->y + intdiv($row->height - $m->treeToggleSize, 2),
                        $m->treeToggleSize,
                        $m->treeToggleSize,
                    ),
                    $node->expanded,
                    $isSelected,
                );
            }
            $cursorX += $m->treeToggleSize + $m->treeToggleGap;

            // Expanded variant when the node is open and a pair was supplied
            // (closed/open folder); otherwise the default drawer.
            $drawer = ($node->expanded && $node->expandedIconDrawer !== null)
                ? $node->expandedIconDrawer
                : $node->iconDrawer;

            if ($drawer !== null) {
                $iconY = $row->y + intdiv($row->height - $m->treeIconSize, 2);
                $drawer($r, $cursorX, $iconY, $m->treeIconSize);
                $cursorX += $m->treeIconSize + 2;
            }

            // Trailing ellipsis while the provider is still fetching children.
            $label = $node->loading ? $node->label . ' …' : $node->label;
            $label = TextClip::toWidth($label, $row->right() - $cursorX - 1, $r);

            $chrome->text($r, $label, $cursorX, $r->baselineYForRect($row->y, $row->height), $style);
        }
    }
}
