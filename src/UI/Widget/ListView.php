<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\ListViewItemActivatedEvent;
use Cyrnetix\X11\UI\Event\ListViewSelectionChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win32-style ListView in "Report" / "Details" mode.
 *
 *   ┌─────────────────────────────────┐
 *   │ Name        │ Size   │ Type    ▴│  ← clickable header strip
 *   ├─────────────────────────────────┤
 *   │ 📄 file.txt │   42   │ File    ║│
 *   │ 📁 docs     │        │ Folder  ║│  ← rows; selected row paints blue
 *   │ …                               ║│
 *   └─────────────────────────────────┘
 *
 * Single-select. Click a header to sort by that column (toggles asc/desc on
 * repeat clicks). Rows + columns are independent: addColumn() defines the
 * grid, addItem() pushes rows whose values[] line up with column indices.
 * An embedded vertical ScrollBar handles vertical overflow, same pattern as
 * ListBox + TreeView.
 */
final class ListView extends Widget implements Scrollable, Focusable
{
    /** @var list<ListViewColumn> */
    private array $columns = [];

    /** @var list<ListViewItem> */
    private array $items = [];

    private int  $selectedIndex = -1;
    private int  $sortColumn    = -1;
    private bool $sortAscending = true;
    private bool $focused       = false;

    private ScrollBar $scrollBar;
    private ScrollBar $hScrollBar;

    private ?\Closure $onItemActivated    = null;
    private ?\Closure $onSelectionChanged = null;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
    ) {
        parent::__construct($x, $y);

        $m = $this->metrics();
        $this->scrollBar = new ScrollBar(
            ScrollOrientation::Vertical,
            $this->width - $m->scrollBarThickness - $m->listViewBorder,
            $m->listViewBorder + $m->listViewHeaderHeight,
            $m->scrollBarThickness,
            $this->height - 2 * $m->listViewBorder - $m->listViewHeaderHeight,
            $dispatcher,
            0, 0, $this->visibleRowCount(), 1,
        );
        $this->addChild($this->scrollBar);

        // The horizontal bar measures *pixels*, not rows: columns are all
        // different widths, so there is nothing else to count. Its step is a
        // theme metric so a chunkier era nudges further per arrow click.
        $this->hScrollBar = new ScrollBar(
            ScrollOrientation::Horizontal,
            $m->listViewBorder,
            $this->height - $m->scrollBarThickness - $m->listViewBorder,
            $this->width - 2 * $m->listViewBorder - $m->scrollBarThickness,
            $m->scrollBarThickness,
            $dispatcher,
            0, 0, 1, $m->scrollBarThickness,
        );
        $this->addChild($this->hScrollBar);

        $this->relayout();
    }

    /**
     * Re-derive the embedded scrollbars' bounds. The vertical one starts below
     * the header strip, whose height is itself a theme metric, and stops short
     * of the horizontal one when there is one.
     */
    public function relayout(): void
    {
        $m       = $this->metrics();
        $scrollH = $this->needsHorizontalScroll() ? $m->scrollBarThickness : 0;

        $this->scrollBar->relX   = $this->width - $m->scrollBarThickness - $m->listViewBorder;
        $this->scrollBar->relY   = $m->listViewBorder + $m->listViewHeaderHeight;
        $this->scrollBar->x      = $this->x + $this->scrollBar->relX;
        $this->scrollBar->y      = $this->y + $this->scrollBar->relY;
        $this->scrollBar->width  = $m->scrollBarThickness;
        $this->scrollBar->height = $this->height - 2 * $m->listViewBorder - $m->listViewHeaderHeight - $scrollH;

        $this->scrollBar->setRange(0, count($this->items), $this->visibleRowCount());

        $this->hScrollBar->relX   = $m->listViewBorder;
        $this->hScrollBar->relY   = $this->height - $m->scrollBarThickness - $m->listViewBorder;
        $this->hScrollBar->x      = $this->x + $this->hScrollBar->relX;
        $this->hScrollBar->y      = $this->y + $this->hScrollBar->relY;
        $this->hScrollBar->width  = $this->width - 2 * $m->listViewBorder - $m->scrollBarThickness;
        $this->hScrollBar->height = $m->scrollBarThickness;

        $this->hScrollBar->setRange(0, $this->contentWidth(), $this->viewportWidth());
    }

    /**
     * The bar is a child like any other, so it has to disappear from the walk
     * rather than merely stop being drawn — otherwise it would still be hit.
     */
    public function getVisibleChildren(): array
    {
        return array_values(array_filter(
            parent::getVisibleChildren(),
            fn(Widget $child): bool => $child !== $this->hScrollBar || $this->needsHorizontalScroll(),
        ));
    }

    /** Total width of every column, which is what has to fit. */
    public function contentWidth(): int
    {
        $total = 0;
        foreach ($this->columns as $col) $total += $col->width;

        return $total;
    }

    /** Width available to the rows, i.e. everything left of the vertical bar. */
    public function viewportWidth(): int
    {
        $m = $this->metrics();

        return max(0, $this->width - 2 * $m->listViewBorder - $m->scrollBarThickness);
    }

    /** Whether the columns are wider than the viewport, so a horizontal bar is needed. */
    public function needsHorizontalScroll(): bool
    {
        return $this->contentWidth() > $this->viewportWidth();
    }

    /**
     * How far the columns are scrolled left, in pixels.
     *
     * Zero when everything fits — the bar is gone in that case, and a stale
     * value from a wider table would otherwise shift a grid that has no way to
     * shift back.
     */
    public function horizontalOffset(): int
    {
        return $this->needsHorizontalScroll() ? $this->hScrollBar->getValue() : 0;
    }

    /** The horizontal scroll bar. */
    public function getHorizontalScrollBar(): ScrollBar { return $this->hScrollBar; }

    /**
     * Resize the list panel + reflow the embedded scrollbar. Called from a
     * resize listener when the containing tab page changes shape.
     */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(40, $width);
        $this->height = max(60, $height);
        $this->relayout();
    }

    /** Adds a column. */
    public function addColumn(string $title, int $width): self
    {
        $this->columns[] = new ListViewColumn($title, $width);
        // A column can be the one that no longer fits, which both gives the
        // horizontal bar something to scroll and takes a row off the vertical.
        $this->relayout();
        return $this;
    }

    /** Adds an item. */
    public function addItem(ListViewItem $item): self
    {
        $this->items[] = $item;
        $this->scrollBar->setRange(0, count($this->items), $this->visibleRowCount());
        return $this;
    }

    /**
     * Drop every column, for a grid whose shape changes — the same table viewer
     * showing a different table.
     *
     * Clears the items with them: rows are indexed by column, so keeping them
     * would leave values pointing at columns that no longer exist. Sorting state
     * goes too, since the column it referred to may be gone.
     */
    public function clearColumns(): void
    {
        $this->columns      = [];
        $this->sortColumn   = -1;
        $this->sortAscending = true;

        $this->clearItems();
        // Nothing left to scroll sideways, so the bar goes and its row comes back.
        $this->relayout();
    }

    /** Clears the items. */
    public function clearItems(): void
    {
        $this->items         = [];
        $this->selectedIndex = -1;
        // setRange already clamps the current value into the new range, so
        // resetting the scroll position to 0 happens implicitly.
        $this->scrollBar->setRange(0, 0, $this->visibleRowCount());
    }

    /** @return list<ListViewColumn> */
    public function getColumns(): array          { return $this->columns; }

    /** @return list<ListViewItem> */
    public function getItems(): array            { return $this->items; }
    /** The selected index. */
    public function getSelectedIndex(): int      { return $this->selectedIndex; }
    /** The selected item. */
    public function getSelectedItem(): ?ListViewItem { return $this->items[$this->selectedIndex] ?? null; }
    /** The sort column. */
    public function getSortColumn(): int         { return $this->sortColumn; }
    /** Whether it is sort ascending. */
    public function isSortAscending(): bool      { return $this->sortAscending; }
    /** The scroll bar. */
    public function getScrollBar(): ScrollBar    { return $this->scrollBar; }

    /** The visible row count. */
    public function visibleRowCount(): int
    {
        return max(1, intdiv($this->viewportHeight(), $this->metrics()->listViewRowHeight));
    }

    /** Height available to the rows — the horizontal bar eats into it. */
    public function viewportHeight(): int
    {
        $m = $this->metrics();

        return max(
            0,
            $this->height - 2 * $m->listViewBorder - $m->listViewHeaderHeight
                - ($this->needsHorizontalScroll() ? $m->scrollBarThickness : 0),
        );
    }

    /** Fire ListViewItemActivatedEvent for the item at $idx (typically a double-click). */
    /**
     * Callbacks for a widget that *owns* this list and has to react to it
     * without the application wiring anything up — the same reason
     * {@see ListBox::setOnItemClicked()} exists. A composite (the file dialog's
     * name field following the selected row) can't reach the listener registry,
     * and shouldn't have to.
     *
     * Both run in addition to the dispatched event, never instead of it.
     */
    public function setOnItemActivated(?\Closure $cb): void    { $this->onItemActivated = $cb; }
    /**
     * What selection changed does. **One closure**: setting it replaces whatever was there, rather
     * than adding to it.
     */
    public function setOnSelectionChanged(?\Closure $cb): void { $this->onSelectionChanged = $cb; }

    /** Activates row $idx - what a double-click or Enter means. */
    public function activateItem(int $idx): void
    {
        if (!isset($this->items[$idx])) return;

        $item = $this->items[$idx];
        $this->dispatcher->dispatch(new ListViewItemActivatedEvent($this, $item));
        if ($this->onItemActivated !== null) ($this->onItemActivated)($item);
    }

    /** Sets selected index. */
    public function setSelectedIndex(int $idx): bool
    {
        if ($idx < -1 || $idx >= count($this->items)) return false;
        if ($idx === $this->selectedIndex) return false;
        $this->selectedIndex = $idx;

        $item = $this->getSelectedItem();
        $this->dispatcher->dispatch(new ListViewSelectionChangedEvent($this, $item));
        if ($this->onSelectionChanged !== null) ($this->onSelectionChanged)($item);
        return true;
    }

    /**
     * Sort items by column $columnIdx. Clicking the currently-sorted column
     * toggles ascending/descending; clicking a different column starts a new
     * ascending sort. Uses strnatcasecmp so "file10" sorts after "file2".
     *
     * {@see ListViewItem::$sortGroup} is compared first and is never reversed,
     * so a group that leads ascending still leads descending — that's what
     * keeps a file dialog's folders above its files in both directions.
     */
    public function sortBy(int $columnIdx): void
    {
        if (!isset($this->columns[$columnIdx])) return;

        if ($columnIdx === $this->sortColumn) {
            $this->sortAscending = !$this->sortAscending;
        } else {
            $this->sortColumn    = $columnIdx;
            $this->sortAscending = true;
        }

        $col       = $this->sortColumn;
        $direction = $this->sortAscending ? 1 : -1;

        // Preserve which item was selected so the user doesn't lose context.
        $selected = $this->getSelectedItem();

        usort($this->items, static function (ListViewItem $a, ListViewItem $b) use ($col, $direction): int {
            if ($a->sortGroup !== $b->sortGroup) return $a->sortGroup <=> $b->sortGroup;

            return strnatcasecmp($a->sortValue($col), $b->sortValue($col)) * $direction;
        });

        if ($selected !== null) {
            $this->selectedIndex = array_search($selected, $this->items, strict: true);
            if ($this->selectedIndex === false) $this->selectedIndex = -1;
        }
    }

    // ---- Hit tests ------------------------------------------------------

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
        $item = $this->getSelectedItem();
        return $item?->values[0] ?? null;
    }
    /** {@inheritDoc} */
    public function paste(string $text): void               {}

    /** Header strip → column index, or -1. */
    public function hitTestHeader(int $mx, int $my): int
    {
        $m       = $this->metrics();
        $headerY = $this->y + $m->listViewBorder;
        if ($my < $headerY || $my >= $headerY + $m->listViewHeaderHeight) return -1;

        // Scrolled sideways, so the same subtraction the painter makes.
        $cursor = $this->x + $m->listViewBorder - $this->horizontalOffset();
        foreach ($this->columns as $i => $col) {
            if ($mx >= $cursor && $mx < $cursor + $col->width) return $i;
            $cursor += $col->width;
        }
        return -1;
    }

    /** Items area → item index (post-scroll), or -1. */
    public function hitTestItem(int $mx, int $my): int
    {
        $m     = $this->metrics();
        $rowsX = $this->x + $m->listViewBorder;
        $rowsY = $this->y + $m->listViewBorder + $m->listViewHeaderHeight;
        $rowsW = $this->viewportWidth();
        $rowsH = $this->viewportHeight();

        if ($mx < $rowsX || $mx >= $rowsX + $rowsW
            || $my < $rowsY || $my >= $rowsY + $rowsH) {
            return -1;
        }

        $idx = intdiv($my - $rowsY, $m->listViewRowHeight) + $this->scrollBar->getValue();
        return $idx >= 0 && $idx < count($this->items) ? $idx : -1;
    }
}
