<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Menu;
use Cyrnetix\X11\UI\Widget\MenuBar;
use Cyrnetix\X11\UI\Widget\MenuItem;
use Cyrnetix\X11\UI\Widget\MenuSeparator;

/**
 * Menu bar, in two phases:
 *
 *   paintBar()       : the always-visible strip with the top-level labels.
 *   paintOpenMenus() : the popup chain, painted *after* the rest of the tree so
 *                      popups overlay tabs and controls.
 *
 * How a highlighted item looks is the theme's decision, and the two eras
 * disagree fundamentally: Windows and Platinum reverse the item out, Motif
 * raises it and leaves the label black. The painter asks the theme which text
 * role to use rather than assuming.
 */
final class MenuBarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the bar. */
    public function paintBar(MenuBar $bar, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $strip = Rect::of($bar->x, $bar->y, $bar->width, $m->menuBarHeight);
        $chrome->menuBar($r, $strip);

        $openIdx = $bar->getOpenIndex();
        $cursorX = $strip->x + $m->menuBarLabelPadding;
        $baseY   = $r->baselineYForRect($strip->y, $strip->height);

        foreach ($bar->getMenus() as $i => $menu) {
            $labelW = $r->measureText($menu->label);

            if ($i === $openIdx) {
                $chrome->menuItemHighlight($r, Rect::of(
                    $cursorX - $m->menuBarLabelPadding,
                    $strip->y + 1,
                    $labelW + 2 * $m->menuBarLabelPadding,
                    $strip->height - 2,
                ));
                $style = $chrome->menuHighlightTextStyle();
            } else {
                $style = TextStyle::Menu;
            }

            $chrome->text($r, $menu->label, $cursorX, $baseY, $style);
            $cursorX += $labelW + 2 * $m->menuBarLabelPadding;
        }
    }

    /** Paints the open menus. */
    public function paintOpenMenus(MenuBar $bar, Renderer $r): void
    {
        $chain     = $bar->getOpenMenuChain();
        $positions = $bar->getOpenChainPositions($r);

        foreach ($chain as $i => $menu) {
            [$x, $y] = $positions[$i];
            $this->paintPopup($menu, $r, $x, $y);
        }
    }

    /** Paints the popup. */
    private function paintPopup(Menu $menu, Renderer $r, int $x, int $y): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        [$w, $h] = $menu->computeBounds($r);
        $chrome->menuPopup($r, Rect::of($x, $y, $w, $h));

        $itemX = $x + $m->menuPopupBorder;
        $itemY = $y + $m->menuPopupBorder;
        $itemW = $w - 2 * $m->menuPopupBorder;

        foreach ($menu->getItems() as $idx => $item) {
            if ($item instanceof MenuSeparator) {
                $chrome->separator(
                    $r,
                    Rect::of($itemX + 2, $itemY + intdiv($m->menuSeparatorHeight, 2), $itemW - 4, 2),
                    true,
                );
                $itemY += $m->menuSeparatorHeight;
                continue;
            }

            $highlighted = $item->enabled
                && ($idx === $menu->getHoveredIndex() || $idx === $menu->getExpandedIndex());

            $this->paintItem($r, $item, Rect::of($itemX, $itemY, $itemW, $m->menuItemHeight), $highlighted);
            $itemY += $m->menuItemHeight;
        }
    }

    /** Paints the item. */
    private function paintItem(Renderer $r, MenuItem $item, Rect $rect, bool $highlighted): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        if ($highlighted) {
            $chrome->menuItemHighlight($r, $rect);
            $style = $chrome->menuHighlightTextStyle();
        } else {
            $style = $item->enabled ? TextStyle::Menu : TextStyle::Disabled;
        }

        $fg = $this->themes->palette()->forText($style);

        // Icon column: a supplied drawer wins over the check mark.
        if ($item->iconDrawer !== null) {
            $iconSize = min(14, $rect->height - 2);
            ($item->iconDrawer)($r, $rect->x + 3, $rect->y + intdiv($rect->height - $iconSize, 2), $iconSize);
        } elseif ($item->checked) {
            $chrome->checkGlyph($r, Rect::of($rect->x + 4, $rect->y + intdiv($rect->height - 9, 2), 8, 8), $fg);
        }

        $baseY  = $r->baselineYForRect($rect->y, $rect->height);
        $labelX = $rect->x + $m->menuIconColumnWidth + 2;
        $chrome->text($r, $item->label, $labelX, $baseY, $style);

        if ($item->shortcut !== null) {
            $rightPad  = $item->submenu !== null ? $m->menuRightPadSubmenu : $m->menuRightPad;
            $shortcutX = $rect->x + $rect->width - $rightPad - $r->measureText($item->shortcut);
            $chrome->text($r, $item->shortcut, $shortcutX, $baseY, $style);
        }

        if ($item->submenu !== null) {
            $chrome->arrow(
                $r,
                Rect::of($rect->right() - 11, $rect->y, 8, $rect->height),
                Direction::Right,
                $fg,
                $m->smallArrowSize + 1,
            );
        }
    }
}
