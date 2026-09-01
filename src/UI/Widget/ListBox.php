<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\ListSelectionChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Listbox. A sunken content panel showing one row per item, with
 * an internal vertical ScrollBar attached as a child along the right edge.
 *
 * Items are plain strings. Single-selection only; click an item to select.
 * Scrolling is delegated to the embedded ScrollBar — the painter just reads
 * scrollBar->getValue() to know which item is at the top of the viewport.
 */
final class ListBox extends Widget implements Scrollable, Focusable
{
    /** @var list<string> */
    private array     $items         = [];
    private int       $selectedIndex = -1;
    private bool      $focused       = false;
    private ScrollBar $scrollBar;
    /** Optional per-click callback — fires regardless of whether selection changed. */
    private ?\Closure $onItemClicked = null;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        public readonly int $itemHeight = 16,
    ) {
        parent::__construct($x, $y);

        // Internal scrollbar at the right edge, inset by the border on each
        // axis. Bounds are re-derived in relayout() once a theme is attached.
        $m = $this->metrics();
        $this->scrollBar = new ScrollBar(
            ScrollOrientation::Vertical,
            $this->width - $m->scrollBarThickness - $m->listBoxBorder,
            $m->listBoxBorder,
            $m->scrollBarThickness,
            $this->height - 2 * $m->listBoxBorder,
            $dispatcher,
            0,
            0,
            $this->getVisibleItemCount(),
            1,
        );
        $this->addChild($this->scrollBar);
    }

    /** Re-derive the embedded scrollbar's bounds from the live metrics. */
    public function relayout(): void
    {
        $m = $this->metrics();

        $this->scrollBar->relX   = $this->width - $m->scrollBarThickness - $m->listBoxBorder;
        $this->scrollBar->relY   = $m->listBoxBorder;
        $this->scrollBar->x      = $this->x + $this->scrollBar->relX;
        $this->scrollBar->y      = $this->y + $this->scrollBar->relY;
        $this->scrollBar->width  = $m->scrollBarThickness;
        $this->scrollBar->height = $this->height - 2 * $m->listBoxBorder;

        $this->scrollBar->setRange(0, count($this->items), $this->getVisibleItemCount());
    }

    /** @return list<string> */
    public function getItems(): array              { return $this->items; }
    /** The selected index. */
    public function getSelectedIndex(): int        { return $this->selectedIndex; }
    /** The selected item. */
    public function getSelectedItem(): ?string     { return $this->items[$this->selectedIndex] ?? null; }
    /** The scroll bar. */
    public function getScrollBar(): ScrollBar      { return $this->scrollBar; }
    /** The visible item count. */
    public function getVisibleItemCount(): int
    {
        return max(1, intdiv($this->height - 2 * $this->metrics()->listBoxBorder, $this->itemHeight));
    }

    /** Adds an item. */
    public function addItem(string $text): void
    {
        $this->items[] = $text;
        $this->scrollBar->setRange(0, count($this->items), $this->getVisibleItemCount());
    }

    /** Sets selected index. */
    public function setSelectedIndex(int $idx): bool
    {
        if ($idx < -1 || $idx >= count($this->items)) return false;
        if ($idx === $this->selectedIndex)            return false;

        $this->selectedIndex = $idx;
        $this->dispatcher->dispatch(new ListSelectionChangedEvent($this));
        return true;
    }

    /**
     * Register a callback fired on every item click — even when the click
     * doesn't change the selection. Popup-owning widgets (DropDown,
     * ComboBox) use this to dismiss the popup on re-click of the already-
     * selected item, matching Win's CBN_CLOSEUP behaviour.
     */
    public function setOnItemClicked(?\Closure $cb): void
    {
        $this->onItemClicked = $cb;
    }

    /** Tells the owner a row was clicked. */
    public function notifyItemClicked(int $idx): void
    {
        if ($this->onItemClicked !== null) ($this->onItemClicked)($idx);
    }

    /**
     * Scroll the embedded scrollbar so the currently-selected item is in
     * view. Used by popup-owning widgets after programmatic selection
     * (keyboard nav) so the highlighted row stays visible.
     */
    public function ensureSelectedVisible(): void
    {
        if ($this->selectedIndex < 0) return;
        $top  = $this->scrollBar->getValue();
        $page = $this->getVisibleItemCount();
        if ($this->selectedIndex < $top) {
            $this->scrollBar->setValue($this->selectedIndex);
        } elseif ($this->selectedIndex >= $top + $page) {
            $this->scrollBar->setValue($this->selectedIndex - $page + 1);
        }
    }

    /**
     * Reset the items list — used by popup-owning widgets when their
     * data is rebuilt. Clears selection + scrollbar range.
     */
    public function clearItems(): void
    {
        $this->items         = [];
        $this->selectedIndex = -1;
        $this->scrollBar->setRange(0, 0, $this->getVisibleItemCount());
    }

    /**
     * Move this ListBox to absolute coords (used when a popup re-anchors
     * itself below an opening field). Re-resolves the embedded scrollbar's
     * absolute position too.
     */
    public function moveTo(int $x, int $y): void
    {
        $this->x    = $x;
        $this->y    = $y;
        $this->relX = $x;
        $this->relY = $y;
        // The scrollbar is a child with its own relX/relY — re-resolve.
        $this->scrollBar->x = $x + $this->scrollBar->relX;
        $this->scrollBar->y = $y + $this->scrollBar->relY;
    }

    /** Resize + reflow the embedded scrollbar (used by popup containers). */
    public function resize(int $width, int $height): void
    {
        $this->width  = $width;
        $this->height = $height;
        $this->relayout();
    }

    /** True if (mx, my) lies anywhere in the listbox's outer rectangle. */
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
    // Keyboard navigation (Up/Down/Home/End/PgUp/PgDn) is dispatched from
    // WidgetManager, so handleKey is a no-op — but the widget still needs
    // to advertise itself as Focusable so clicks acquire focus and Ctrl+C
    // can read the current selection.

    /** Whether it is focused. */
    public function isFocused(): bool                  { return $this->focused; }

    /** No disabled state, so always. */
    public function canTakeFocus(): bool { return true; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void    { $this->focused = $focused; }
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool { return $this->hitTest($mx, $my); }
    /** {@inheritDoc} */
    public function handleKey(string $key): bool       { return false; }
    /** The copy. */
    public function copy(): ?string                    { return $this->getSelectedItem(); }
    /** {@inheritDoc} */
    public function paste(string $text): void          {}

    /**
     * Map (mx, my) to an item index inside the visible viewport. Excludes the
     * scrollbar column and the sunken border. Returns -1 outside the viewport
     * or past the last item.
     */
    public function hitTestItem(int $mx, int $my): int
    {
        $m      = $this->metrics();
        $itemsX = $this->x + $m->listBoxBorder;
        $itemsY = $this->y + $m->listBoxBorder;
        $itemsW = $this->width - 2 * $m->listBoxBorder - $m->scrollBarThickness;
        $itemsH = $this->height - 2 * $m->listBoxBorder;

        if ($mx < $itemsX || $mx >= $itemsX + $itemsW
            || $my < $itemsY || $my >= $itemsY + $itemsH) {
            return -1;
        }

        $visibleIdx = intdiv($my - $itemsY, $this->itemHeight);
        $idx        = $this->scrollBar->getValue() + $visibleIdx;

        return ($idx >= 0 && $idx < count($this->items)) ? $idx : -1;
    }
}
