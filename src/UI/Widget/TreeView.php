<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\TreeNodeSelectedEvent;
use Cyrnetix\X11\UI\Event\TreeSelectionChangedEvent;
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
 *
 * **Multi-select is opt-in** ({@see setMultiSelect()}), and then follows
 * Explorer: a plain click selects one row, Ctrl+click adds or removes a row,
 * Shift+click selects the run of visible rows from the anchor to the one
 * clicked. {@see getSelected()} stays the one node keyboard navigation moves
 * from — the most recently picked — and {@see getSelection()} is the whole set.
 * A tree that never opts in behaves exactly as it did, but still reports
 * {@see TreeSelectionChangedEvent}, so a listener can be written once.
 */
final class TreeView extends Widget implements Scrollable, Focusable
{
    /** @var list<TreeNode> */
    private array $roots = [];
    private ?TreeNode $selected = null;
    /**
     * Every selected node, keyed by object id. Always holds {@see $selected}
     * when that is set; a multi-select tree may hold more.
     *
     * @var array<int, TreeNode>
     */
    private array     $selection   = [];
    /** Where a Shift+click range starts: the last row clicked without Shift. */
    private ?TreeNode $anchor      = null;
    private bool      $multiSelect = false;
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
        $this->roots     = [];
        $this->selected  = null;
        $this->selection = [];
        $this->anchor    = null;
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
        // A plain selection is also a *collapse*: clicking the row that is
        // already current, while others are selected beside it, has to leave
        // that row alone, or there is no way back to one row but Ctrl-clicking
        // every other one off.
        $changed = $this->selected !== $node || count($this->selection) !== ($node === null ? 0 : 1);
        if (!$changed && !$force) return false;

        $this->selected  = $node;
        $this->anchor    = $node;
        $this->selection = $node === null ? [] : [spl_object_id($node) => $node];
        if ($node !== null) {
            $this->dispatcher->dispatch(new TreeNodeSelectedEvent($this, $node));
        }
        if ($changed) $this->announceSelection();
        return $changed;
    }

    /** Opt in to Ctrl+click and Shift+click. Turning it off keeps only the current row. */
    public function setMultiSelect(bool $multiSelect): void
    {
        $this->multiSelect = $multiSelect;
        if (!$multiSelect && count($this->selection) > 1) {
            $this->setSelected($this->selected);
        }
    }

    /** Whether Ctrl+click and Shift+click select more than one row. */
    public function isMultiSelect(): bool { return $this->multiSelect; }

    /** Whether this node is part of the selection. */
    public function isSelected(TreeNode $node): bool
    {
        return isset($this->selection[spl_object_id($node)]);
    }

    /**
     * Every selected node, in the order the rows appear.
     *
     * Row order rather than click order, because that is the order the user
     * reads them in. A node selected and then hidden by collapsing its parent
     * stays selected — collapsing is looking, not choosing — and follows the
     * visible ones.
     *
     * @return list<TreeNode>
     */
    public function getSelection(): array
    {
        $left    = $this->selection;
        $ordered = [];
        foreach ($this->flattenVisibleRows() as [$node]) {
            $id = spl_object_id($node);
            if (!isset($left[$id])) continue;
            $ordered[] = $node;
            unset($left[$id]);
        }

        return [...$ordered, ...array_values($left)];
    }

    /**
     * Ctrl+click: add a row to the selection, or take it out.
     *
     * Adding makes it the current row. Taking out the current row hands that
     * role to the most recently picked of the rest, so {@see getSelected()} is
     * always one of the selected nodes; taking out the last leaves nothing
     * selected, which is what it looks like. Without multi-select this is a
     * plain {@see setSelected()}.
     */
    public function toggleSelected(TreeNode $node): void
    {
        if (!$this->multiSelect) {
            $this->setSelected($node);
            return;
        }

        $id           = spl_object_id($node);
        $this->anchor = $node;

        if (isset($this->selection[$id])) {
            unset($this->selection[$id]);
            if ($this->selected === $node) {
                $rest           = array_values($this->selection);
                $this->selected = $rest === [] ? null : $rest[count($rest) - 1];
            }
            $this->announceSelection();
            return;
        }

        $this->selection[$id] = $node;
        $this->selected       = $node;
        $this->dispatcher->dispatch(new TreeNodeSelectedEvent($this, $node));
        $this->announceSelection();
    }

    /**
     * Shift+click: select every visible row from the anchor to this one.
     *
     * Replaces the selection, as Explorer does; the anchor stays put, so a
     * second Shift+click re-draws the run from the same place rather than from
     * the end of the last one. Without multi-select, or with no anchor yet,
     * this is a plain {@see setSelected()}.
     */
    public function extendSelectionTo(TreeNode $node): void
    {
        $anchor = $this->anchor;
        if (!$this->multiSelect || $anchor === null) {
            $this->setSelected($node);
            return;
        }

        $rows = array_map(static fn(array $row): TreeNode => $row[0], $this->flattenVisibleRows());
        $from = array_search($anchor, $rows, true);
        $to   = array_search($node, $rows, true);
        if ($from === false || $to === false) {
            $this->setSelected($node);
            return;
        }

        $this->selection = [];
        foreach (array_slice($rows, min($from, $to), abs($to - $from) + 1) as $picked) {
            $this->selection[spl_object_id($picked)] = $picked;
        }
        $this->selected = $node;
        $this->anchor   = $anchor;

        $this->dispatcher->dispatch(new TreeNodeSelectedEvent($this, $node));
        $this->announceSelection();
    }

    /** Tell listeners what the whole selection is now. */
    private function announceSelection(): void
    {
        $this->dispatcher->dispatch(new TreeSelectionChangedEvent($this, $this->getSelection()));
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
    public function copy(): ?string
    {
        // Every selected label, one per line, the way a list copies its rows.
        $labels = array_map(static fn(TreeNode $n): string => $n->label, $this->getSelection());
        return $labels === [] ? null : implode("\n", $labels);
    }
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
