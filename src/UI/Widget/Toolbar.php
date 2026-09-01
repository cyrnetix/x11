<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\ToolbarButtonClickedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style flat toolbar. A horizontal row of icon-only buttons with
 * occasional separators between groups. Buttons paint flat until the
 * cursor hovers (raised bevel) or the user presses (sunken bevel);
 * toggle items stay sunken while checked.
 *
 *   ┌──────────────────────────────────────────┐
 *   │ [icon][icon][icon] │ [icon][icon] │ …    │  ← square button cells
 *   └──────────────────────────────────────────┘
 *
 * Width is the sum of item widths — set after each addX(). Designed to
 * sit as a child of a future Rebar band, so the widget exposes its
 * natural width via the standard $width property.
 */
final class Toolbar extends Widget implements Tooltipped, Bounded
{
    /** @var list<ToolbarItem> */
    private array $items = [];

    private int $hoveredIdx = -1;
    private int $pressedIdx = -1;

    public int $width  = 0;
    public int $height;

    /** Takes position and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        private readonly SyncEventDispatcher $dispatcher,
    ) {
        parent::__construct($x, $y);
        $this->computeSize();
    }

    /**
     * Natural size from the item list and the live metrics. The width is a
     * derived value, so it's recomputed rather than accumulated — a theme with
     * chunkier buttons produces a wider toolbar.
     */
    private function computeSize(): void
    {
        $m = $this->metrics();

        $this->height = $m->toolbarButtonSize + 2 * $m->toolbarPadding;
        $this->width  = 2 * $m->toolbarPadding;
        foreach ($this->items as $item) {
            $this->width += $item->isSeparator ? $m->toolbarSeparatorWidth : $m->toolbarButtonSize;
        }
    }

    /** {@inheritDoc} */
    public function relayout(): void
    {
        $this->computeSize();
    }

    // ---- Item management ------------------------------------------------

    /** Adds an item. */
    public function addItem(ToolbarItem $item): self
    {
        $this->items[] = $item;
        $this->computeSize();
        return $this;
    }

    /** @return list<ToolbarItem> */
    public function getItems(): array { return $this->items; }

    /** The item. */
    public function getItem(int $idx): ?ToolbarItem
    {
        return $this->items[$idx] ?? null;
    }

    /** The hovered index. */
    public function getHoveredIndex(): int    { return $this->hoveredIdx; }
    /** The pressed index. */
    public function getPressedIndex(): int    { return $this->pressedIdx; }
    /** Sets hovered index. */
    public function setHoveredIndex(int $i): bool
    {
        if ($i === $this->hoveredIdx) return false;
        $this->hoveredIdx = $i;
        return true;
    }
    /** Sets pressed index. */
    public function setPressedIndex(int $i): void { $this->pressedIdx = $i; }

    /**
     * Fire an item's click — flips checked for toggles, invokes onClick,
     * dispatches ToolbarButtonClickedEvent. No-op for separators and
     * disabled items.
     */
    public function activate(int $idx): void
    {
        $item = $this->items[$idx] ?? null;
        if ($item === null || $item->isSeparator || !$item->enabled) return;

        if ($item->isToggle) $item->checked = !$item->checked;
        if ($item->onClick !== null) ($item->onClick)();
        $this->dispatcher->dispatch(new ToolbarButtonClickedEvent($this, $item));
    }

    // ---- Hit testing ----------------------------------------------------

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

    /** The painter fills the whole band before drawing any item onto it. */
    public function paintsOwnBackground(): bool { return true; }

    /**
     * Map a click to an item index. Returns -1 outside the widget, on a
     * separator (un-clickable), or in the padding.
     */
    public function hitTestItem(int $mx, int $my): int
    {
        if (!$this->hitTest($mx, $my)) return -1;
        $m = $this->metrics();
        if ($my < $this->y + $m->toolbarPadding || $my >= $this->y + $this->height - $m->toolbarPadding) return -1;

        $cursor = $this->x + $m->toolbarPadding;
        foreach ($this->items as $i => $item) {
            $w = $item->isSeparator ? $m->toolbarSeparatorWidth : $m->toolbarButtonSize;
            if ($mx >= $cursor && $mx < $cursor + $w) {
                return $item->isSeparator ? -1 : $i;
            }
            $cursor += $w;
        }
        return -1;
    }

    /** The tooltip to show at these coordinates, if there is one. */
    public function getTooltipAt(int $mx, int $my): ?string
    {
        $idx = $this->hitTestItem($mx, $my);
        if ($idx === -1) return null;
        $item = $this->getItem($idx);
        if ($item === null || $item->label === '') return null;
        return $item->label;
    }

    /**
     * Pixel bounds [x, y, w, h] for the item at $idx (separators
     * included). Painter consumes this; hit-test mirrors the layout
     * inline above so the two stay byte-for-byte aligned.
     */
    public function itemBounds(int $idx): array
    {
        $m      = $this->metrics();
        $cursor = $this->x + $m->toolbarPadding;
        foreach ($this->items as $i => $item) {
            $w = $item->isSeparator ? $m->toolbarSeparatorWidth : $m->toolbarButtonSize;
            if ($i === $idx) {
                return [$cursor, $this->y + $m->toolbarPadding, $w, $m->toolbarButtonSize];
            }
            $cursor += $w;
        }
        return [0, 0, 0, 0];
    }
}
