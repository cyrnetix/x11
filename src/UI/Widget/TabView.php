<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Renderer;

/**
 * A tabbed container: one page visible at a time, the rest kept intact.
 *
 * Pages are real containers of ordinary widgets. Only the active one is returned
 * from `getVisibleChildren()`, so the others are neither painted nor clickable
 * while they are hidden — but they keep their state and their children.
 */
final class TabView extends Widget
{
    private int $activeIndex = 0;

    /** Takes position and size. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
    ) {
        parent::__construct($x, $y);
    }

    /** Update the panel's dimensions in response to a parent resize. */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(0, $width);
        $this->height = max(0, $height);
    }

    /** Convenience: create + attach a TabPage in one call. */
    public function addTab(string $label): TabPage
    {
        $page = new TabPage($label);
        $this->addChild($page);
        return $page;
    }

    /** The active index. */
    public function getActiveIndex(): int { return $this->activeIndex; }

    /** Sets active index. */
    public function setActiveIndex(int $idx): void
    {
        if ($idx >= 0 && $idx < count($this->children)) {
            $this->activeIndex = $idx;
        }
    }

    /** Tree-walk only descends into the active tab. */
    public function getVisibleChildren(): array
    {
        $page = $this->children[$this->activeIndex] ?? null;
        return $page !== null ? [$page] : [];
    }

    /** The content offset X. */
    public function contentOffsetX(): int { return $this->metrics()->tabBorder; }
    /** The content offset Y. */
    public function contentOffsetY(): int
    {
        $m = $this->metrics();
        return $m->tabHeight + $m->tabBorder;
    }

    /**
     * Absolute x + width of every tab, sized to its own label rather than by
     * dividing the strip evenly — otherwise a row of tabs with different-length
     * names runs its text together.
     *
     * When the labels don't fit, every tab shrinks by the same factor so the row
     * still ends at the panel's edge. Painter and hit-test share this, so a click
     * always lands on the tab whose label was drawn there.
     *
     * @return list<array{int, int}> [x, width] in tab order
     */
    public function tabBounds(Renderer $r): array
    {
        $m     = $this->metrics();
        $extra = 2 * $m->tabLabelPadding + 2 * $m->tabSlant;

        $widths = [];
        $total  = 0;
        foreach ($this->children as $page) {
            $w        = max(1, $r->measureText($page->label)) + $extra;
            $widths[] = $w;
            $total   += $w;
        }
        if ($widths === []) return [];

        // Scale down to fit, never up: a half-empty strip looks right, a
        // clipped one doesn't.
        $scale = $total > $this->width && $total > 0 ? $this->width / $total : 1.0;

        $bounds = [];
        $cursor = $this->x;
        foreach ($widths as $w) {
            $scaled = max(8, (int) floor($w * $scale));
            $bounds[] = [$cursor, $scaled];
            $cursor  += $scaled;
        }
        return $bounds;
    }

    /** Returns tab index if the header strip was hit, -1 otherwise. */
    public function hitTestTab(int $mx, int $my, Renderer $r): int
    {
        if ($my < $this->y || $my >= $this->y + $this->metrics()->tabHeight) return -1;

        foreach ($this->tabBounds($r) as $i => [$x, $w]) {
            if ($mx >= $x && $mx < $x + $w) return $i;
        }
        return -1;
    }
}
