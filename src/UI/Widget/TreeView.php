<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\TreeNodeSelectedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style tree view. Hierarchical rows with a [+]/[−] toggle next to
 * any non-leaf node; clicking the toggle expands or collapses, clicking
 * the row selects. Scrolling is delegated to an embedded vertical
 * ScrollBar attached as a child widget (same pattern as ListBox).
 *
 * Layout per row:
 *   indent (depth × metrics()->treeIndent)
 *   ├── toggle box [+]/[−]  (treeToggleSize wide, skipped on leaves)
 *   ├── icon                 (optional, drawn by the node's iconDrawer)
 *   └── label
 */
final class TreeView extends Widget implements Scrollable, Focusable
{
    /** @var list<TreeNode> */
    private array $roots = [];
    private ?TreeNode $selected = null;
    private bool      $focused  = false;
    private ScrollBar $scrollBar;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        /** Optional source of lazy children — null = static, addRoot() only. */
        private readonly ?TreeNodeProvider $provider = null,
    ) {
        parent::__construct($x, $y);

        $m = $this->metrics();
        $this->scrollBar = new ScrollBar(
            ScrollOrientation::Vertical,
            $this->width - $m->scrollBarThickness - $m->treeBorder,
            $m->treeBorder,
            $m->scrollBarThickness,
            $this->height - 2 * $m->treeBorder,
            $dispatcher,
            0, 0, $this->getVisibleRowCount(), 1,
        );
        $this->addChild($this->scrollBar);
    }

    /**
     * Re-derive the embedded scrollbar's bounds from the live metrics. Both
     * relX (so a later re-resolve works) and the absolute x are updated,
     * since the TreeView itself doesn't move here.
     */
    public function relayout(): void
    {
        $m = $this->metrics();

        $this->scrollBar->relX   = $this->width - $m->scrollBarThickness - $m->treeBorder;
        $this->scrollBar->relY   = $m->treeBorder;
        $this->scrollBar->x      = $this->x + $this->scrollBar->relX;
        $this->scrollBar->y      = $this->y + $this->scrollBar->relY;
        $this->scrollBar->width  = $m->scrollBarThickness;
        $this->scrollBar->height = $this->height - 2 * $m->treeBorder;

        $this->refreshScrollRange();
    }

    /** Adds a root. */
    public function addRoot(TreeNode $node): self
    {
        $this->roots[] = $node;
        $this->refreshScrollRange();
        return $this;
    }

    /**
     * Resize the tree panel + reflow the embedded scrollbar. Called from a
     * resize listener when the containing tab page changes shape.
     */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(40, $width);
        $this->height = max(40, $height);
        $this->relayout();
    }

    /** @return list<TreeNode> */
    public function getRoots(): array              { return $this->roots; }

    /**
     * Drop every root, for a tree whose contents are reloaded rather than
     * appended to — a catalog being refreshed, a directory being re-read.
     * Clears the selection with them, since it can't survive the nodes it
     * pointed at.
     */
    public function clearRoots(): void
    {
        $this->roots    = [];
        $this->selected = null;
        $this->scrollBar->setRange(0, 0, $this->getVisibleRowCount());
    }
    /** The selected. */
    public function getSelected(): ?TreeNode       { return $this->selected; }
    /** The scroll bar. */
    public function getScrollBar(): ScrollBar      { return $this->scrollBar; }
    /** The provider. */
    public function getProvider(): ?TreeNodeProvider { return $this->provider; }

    /** The visible row count. */
    public function getVisibleRowCount(): int
    {
        $m = $this->metrics();
        return max(1, intdiv($this->height - 2 * $m->treeBorder, $m->treeRowHeight));
    }

    /**
     * Flat list of every node currently visible in the tree along with its
     * depth. Walks each root in order; only descends into a node's children
     * when it's expanded.
     *
     * @return list<array{TreeNode, int}>
     */
    public function flattenVisibleRows(): array
    {
        $rows = [];
        foreach ($this->roots as $root) {
            $this->walkInto($root, 0, $rows);
        }
        return $rows;
    }

    /** Flattens the expanded tree into the rows the painter draws, depth included. */
    private function walkInto(TreeNode $node, int $depth, array &$rows): void
    {
        $rows[] = [$node, $depth];
        if ($node->expanded) {
            foreach ($node->getChildren() as $child) {
                if (!$child->treeVisible) continue;   // hidden from tree (e.g. files)
                $this->walkInto($child, $depth + 1, $rows);
            }
        }
    }

    /**
     * Set the selected node. By default this is idempotent — setting the
     * same node twice doesn't re-fire the event. Pass $force = true to
     * always dispatch (e.g. so listeners can re-render after a lazy load
     * filled in the children of the currently-selected node).
     */
    public function setSelected(?TreeNode $node, bool $force = false): bool
    {
        $changed = $this->selected !== $node;
        if (!$changed && !$force) return false;

        $this->selected = $node;
        if ($node !== null) {
            $this->dispatcher->dispatch(new TreeNodeSelectedEvent($this, $node));
        }
        return $changed;
    }

    /** Toggles the expanded. */
    public function toggleExpanded(TreeNode $node): void
    {
        if ($node->isLeaf()) return;
        $node->expanded = !$node->expanded;
        $this->refreshScrollRange();
    }

    /** Keep the scrollbar's max / pageSize in sync with current row count. */
    public function refreshScrollRange(): void
    {
        $this->scrollBar->setRange(0, count($this->flattenVisibleRows()), $this->getVisibleRowCount());
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }

    /** The bounds. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    /** A well with a frame: every pixel of the rectangle is ours. */
    public function paintsOwnBackground(): bool { return true; }

    // ---- Focusable -----------------------------------------------------
    /** Whether it is focused. */
    public function isFocused(): bool                       { return $this->focused; }

    /** No disabled state, so always. */
    public function canTakeFocus(): bool { return true; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void         { $this->focused = $focused; }
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool { return $this->hitTest($mx, $my); }
    /** {@inheritDoc} */
    public function handleKey(string $key): bool            { return false; }
    /** The copy. */
    public function copy(): ?string                         { return $this->selected?->label; }
    /** {@inheritDoc} */
    public function paste(string $text): void               {}

    /**
     * Map a click to a tree row. Returns null if outside the rows region,
     * otherwise [region, node] where region is 'toggle' (the +/- box) or
     * 'row' (anywhere else on the row).
     *
     * @return array{string, TreeNode}|null
     */
    public function hitTestRow(int $mx, int $my): ?array
    {
        $m     = $this->metrics();
        $rowsX = $this->x + $m->treeBorder;
        $rowsY = $this->y + $m->treeBorder;
        $rowsW = $this->width  - 2 * $m->treeBorder - $m->scrollBarThickness;
        $rowsH = $this->height - 2 * $m->treeBorder;

        if ($mx < $rowsX || $mx >= $rowsX + $rowsW
            || $my < $rowsY || $my >= $rowsY + $rowsH) {
            return null;
        }

        $rowIdx = intdiv($my - $rowsY, $m->treeRowHeight) + $this->scrollBar->getValue();
        $rows   = $this->flattenVisibleRows();
        if (!isset($rows[$rowIdx])) return null;

        [$node, $depth] = $rows[$rowIdx];

        // The toggle box lives at depth × INDENT + small left pad.
        $toggleX = $rowsX + $depth * $m->treeIndent + 2;
        if (!$node->isLeaf()
            && $mx >= $toggleX && $mx < $toggleX + $m->treeToggleSize) {
            return ['toggle', $node];
        }
        return ['row', $node];
    }
}
