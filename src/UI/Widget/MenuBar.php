<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Renderer;

/**
 * Win2k-style menu bar. Sits at the top of a window, hosts top-level Menus.
 *
 * State the bar owns:
 *   - openIndex      : index of the currently-open top-level menu (-1 = none)
 *   - sub-menu chain : each Menu's expandedIndex points at its open submenu,
 *                      so getOpenMenuChain() walks from this bar down through
 *                      arbitrary submenu depth.
 *
 * The bar reports a public HEIGHT and resizes its width along with the
 * parent window (via setSize()).
 */
final class MenuBar extends Widget
{
    /** @var list<Menu> */
    private array $menus     = [];
    private int   $openIndex = -1;

    /** Takes position and size. */
    public function __construct(
        int $x, int $y,
        public int $width,
    ) {
        parent::__construct($x, $y);
    }

    /** Adds a menu. */
    public function addMenu(Menu $menu): self
    {
        $this->menus[] = $menu;
        if ($this->themes !== null) {
            $menu->setThemes($this->themes);
        }
        return $this;
    }

    /** Menus aren't Widgets, so the bar hands each one the live theme itself. */
    public function relayout(): void
    {
        if ($this->themes === null) return;
        foreach ($this->menus as $menu) {
            $menu->setThemes($this->themes);
        }
    }

    /** @return list<Menu> */
    public function getMenus(): array  { return $this->menus; }
    /** The open index. */
    public function getOpenIndex(): int { return $this->openIndex; }

    /** Sets open index. */
    public function setOpenIndex(int $idx): void
    {
        // Switching top-level menus collapses any submenus in the previous one.
        if ($idx !== $this->openIndex) {
            foreach ($this->menus as $m) {
                $m->setExpandedIndex(-1);
                $m->setHoveredIndex(-1);
            }
        }
        $this->openIndex = $idx;
    }

    /** Closes every open menu and submenu. */
    public function closeAll(): void
    {
        foreach ($this->menus as $m) {
            $m->setExpandedIndex(-1);
            $m->setHoveredIndex(-1);
        }
        $this->openIndex = -1;
    }

    /** Sets size. */
    public function setSize(int $width): void
    {
        $this->width = max(0, $width);
    }

    /** True if (mx, my) sits anywhere in the bar strip. */
    public function hitTestBar(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->metrics()->menuBarHeight;
    }

    /**
     * Returns the top-level menu index under (mx, my), or -1 if the click
     * lands on the bar but between labels.
     */
    public function hitTestMenu(int $mx, int $my, Renderer $r): int
    {
        if (!$this->hitTestBar($mx, $my)) return -1;

        $pad    = $this->metrics()->menuBarLabelPadding;
        $cursor = $this->x + $pad;
        foreach ($this->menus as $i => $menu) {
            $labelW = $r->measureText($menu->label);
            $right  = $cursor + $labelW + $pad;
            if ($mx >= $cursor - $pad && $mx < $right) {
                return $i;
            }
            $cursor = $right + $pad;
        }
        return -1;
    }

    /** Absolute X where the label for menu $idx begins (text origin). */
    public function getMenuLabelX(int $idx, Renderer $r): int
    {
        $pad    = $this->metrics()->menuBarLabelPadding;
        $cursor = $this->x + $pad;
        for ($i = 0; $i < $idx; $i++) {
            $cursor += $r->measureText($this->menus[$i]->label) + 2 * $pad;
        }
        return $cursor;
    }

    /**
     * Walk the open menu chain: the open top-level menu, then any submenu
     * whose parent has it expanded, recursively.
     *
     * @return list<Menu>
     */
    public function getOpenMenuChain(): array
    {
        if ($this->openIndex === -1 || !isset($this->menus[$this->openIndex])) {
            return [];
        }
        $chain   = [$this->menus[$this->openIndex]];
        $current = $chain[0];
        while ($sub = $current->getExpandedSubmenu()) {
            $chain[] = $sub;
            $current = $sub;
        }
        return $chain;
    }

    /**
     * For each menu in the open chain, return its absolute (x, y) origin —
     * top-left of the popup rectangle.
     *
     * @return list<array{int, int}>
     */
    public function getOpenChainPositions(Renderer $r): array
    {
        $chain = $this->getOpenMenuChain();
        if (empty($chain)) return [];

        $m = $this->metrics();
        $x = $this->getMenuLabelX($this->openIndex, $r) - $m->menuBarLabelPadding;
        $y = $this->y + $m->menuBarHeight;

        $positions = [[$x, $y]];
        for ($i = 0; $i < count($chain) - 1; $i++) {
            $parent      = $chain[$i];
            [$pw]        = $parent->computeBounds($r);
            $expandedIdx = $parent->getExpandedIndex();
            $itemOffsetY = $parent->getItemOffsetY($expandedIdx);

            [$px, $py] = $positions[$i];
            $positions[] = [$px + $pw, $py + $m->menuPopupBorder + $itemOffsetY];
        }

        return $positions;
    }

    /**
     * Hit-test the entire open chain. Returns [depth, itemIndex] where depth
     * is the chain index (0 = top-level menu) and itemIndex may be -1 if the
     * click landed on a separator. Returns null if (mx, my) is outside every
     * open popup.
     *
     * @return array{int, int}|null
     */
    public function hitTestOpenChain(int $mx, int $my, Renderer $r): ?array
    {
        $chain     = $this->getOpenMenuChain();
        $positions = $this->getOpenChainPositions($r);

        // Innermost first so deeper submenus win.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $menu      = $chain[$i];
            [$mw, $mh] = $menu->computeBounds($r);
            [$mx0, $my0] = $positions[$i];

            if ($mx >= $mx0 && $mx < $mx0 + $mw && $my >= $my0 && $my < $my0 + $mh) {
                return [$i, $menu->hitTestItem($mx - $mx0, $my - $my0)];
            }
        }
        return null;
    }
}
