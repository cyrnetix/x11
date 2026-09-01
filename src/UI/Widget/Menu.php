<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\ThemeManager;

/**
 * A popup menu — either a top-level menu on a MenuBar or a submenu hanging
 * off a MenuItem. Holds an ordered list of MenuItem | MenuSeparator entries
 * and the runtime state needed to render hover/expansion.
 *
 * Layout math (item heights, popup bounds, item Y offsets) lives here so the
 * painter and hit-tester compute against the same values — which are theme
 * metrics, handed down by the owning {@see MenuBar}.
 */
final class Menu
{
    /** @var list<MenuItem|MenuSeparator> */
    private array $items         = [];
    private int   $hoveredIndex  = -1;
    private int   $expandedIndex = -1;

    /** Live theme source, cascaded from the owning MenuBar. */
    private ?ThemeManager $themes = null;

    /** Takes its label. */
    public function __construct(
        public readonly string $label = '',
    ) {}

    /** Adds an item. */
    public function addItem(MenuItem $item): self
    {
        $this->items[] = $item;
        if ($this->themes !== null) {
            $item->submenu?->setThemes($this->themes);
        }
        return $this;
    }

    /**
     * Take the live theme source, and pass it on to every submenu — menus
     * aren't Widgets, so they can't inherit it through the tree.
     */
    public function setThemes(ThemeManager $themes): void
    {
        $this->themes = $themes;
        foreach ($this->items as $item) {
            if ($item instanceof MenuItem) {
                $item->submenu?->setThemes($themes);
            }
        }
    }

    /** Measurements of the live theme; toolkit defaults before attach. */
    public function metrics(): Metrics
    {
        return $this->themes?->metrics() ?? Metrics::defaults();
    }

    /** Adds a separator. */
    public function addSeparator(): self
    {
        $this->items[] = new MenuSeparator();
        return $this;
    }

    /** @return list<MenuItem|MenuSeparator> */
    public function getItems(): array { return $this->items; }

    /** The hovered index. */
    public function getHoveredIndex(): int  { return $this->hoveredIndex; }
    /** The expanded index. */
    public function getExpandedIndex(): int { return $this->expandedIndex; }

    /** Sets hovered index. */
    public function setHoveredIndex(int $idx): void  { $this->hoveredIndex  = $idx; }
    /** Sets expanded index. */
    public function setExpandedIndex(int $idx): void { $this->expandedIndex = $idx; }

    /** Menu currently shown via the expanded item, or null. */
    public function getExpandedSubmenu(): ?Menu
    {
        if ($this->expandedIndex === -1) return null;
        $item = $this->items[$this->expandedIndex] ?? null;
        return $item instanceof MenuItem ? $item->submenu : null;
    }

    /** Y offset (relative to popup top, before the border) of the item at $idx. */
    public function getItemOffsetY(int $idx): int
    {
        $m = $this->metrics();
        $y = 0;
        for ($j = 0; $j < $idx && $j < count($this->items); $j++) {
            $y += $this->items[$j] instanceof MenuSeparator
                ? $m->menuSeparatorHeight
                : $m->menuItemHeight;
        }
        return $y;
    }

    /**
     * Compute the popup's rendered size. Width is determined by the widest
     * label + the widest shortcut + the icon column, height by summing item
     * heights.
     *
     * @return array{int, int}  [width, height]
     */
    public function computeBounds(Renderer $r): array
    {
        $m           = $this->metrics();
        $maxLabel    = 0;
        $maxShortcut = 0;
        $totalH      = 0;
        $hasSubmenu  = false;

        foreach ($this->items as $item) {
            if ($item instanceof MenuSeparator) {
                $totalH += $m->menuSeparatorHeight;
                continue;
            }
            $maxLabel = max($maxLabel, $r->measureText($item->label));
            if ($item->shortcut !== null) {
                $maxShortcut = max($maxShortcut, $r->measureText($item->shortcut));
            }
            if ($item->submenu !== null) $hasSubmenu = true;
            $totalH += $m->menuItemHeight;
        }

        $shortcutSpace = $maxShortcut > 0 ? $m->menuShortcutGap + $maxShortcut : 0;
        $rightPad      = $hasSubmenu ? $m->menuRightPadSubmenu : $m->menuRightPad;

        $w = 2 * $m->menuPopupBorder + $m->menuIconColumnWidth + $maxLabel + $shortcutSpace + $rightPad;
        $h = 2 * $m->menuPopupBorder + $totalH;

        return [$w, $h];
    }

    /**
     * Hit-test the popup body in local coordinates (relative to popup origin).
     * Returns the item index, or -1 for separators / outside the items area.
     */
    public function hitTestItem(int $localX, int $localY): int
    {
        $m = $this->metrics();
        $y = $m->menuPopupBorder;
        foreach ($this->items as $i => $item) {
            $h = $item instanceof MenuSeparator
                ? $m->menuSeparatorHeight
                : $m->menuItemHeight;
            if ($localY >= $y && $localY < $y + $h) {
                return $item instanceof MenuSeparator ? -1 : $i;
            }
            $y += $h;
        }
        return -1;
    }
}
