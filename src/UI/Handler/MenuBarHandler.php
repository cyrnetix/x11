<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Painter\MenuBarPainter;
use Cyrnetix\X11\UI\Widget\MenuBar;
use Cyrnetix\X11\UI\Widget\MenuItem;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * MenuBar — bar strip clicks open/swap/close top-level menus; clicks
 * inside the open chain navigate submenus or fire leaf items. Hovering
 * while a chain is open switches top-level on a different bar label and
 * auto-opens submenus, matching Win2k. Open popups capture every click,
 * so this handler must run before the focus block in the dispatch list.
 */
final class MenuBarHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree     $tree,
        private readonly X11Client      $client,
        private readonly Renderer       $renderer,
        private readonly MenuBarPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof MenuBar) return false;
        $this->painter->paintBar($w, $r);
        return true;
    }

    /** The menu chain belongs to the widget that opened it. */
    public function overlayAnchor(): ?Widget { return $this->findOpen(); }

    /** Paints the overlay. */
    public function paintOverlay(Renderer $r): void
    {
        $open = $this->findOpen();
        if ($open !== null) $this->painter->paintOpenMenus($open, $r);
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        // Bar strip always wins — opens, swaps, or closes the chain.
        $bar = $this->findBarUnder($event->x, $event->y);
        if ($bar !== null) {
            $this->handleBarClick($bar, $event->x, $event->y);
            return true;
        }

        // Open chain captures clicks outside the bar.
        $open = $this->findOpen();
        if ($open !== null) {
            $this->handleOpenClick($open, $event->x, $event->y);
            return true;
        }
        return false;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        $open = $this->findOpen();
        if ($open === null) return false;
        $this->updateHover($open, $event->x, $event->y);
        return true;
    }

    /** A click on the bar itself: opens that menu, or closes it if it was already open. */
    private function handleBarClick(MenuBar $bar, int $mx, int $my): void
    {
        $idx = $bar->hitTestMenu($mx, $my, $this->renderer);
        if ($idx === -1) {
            if ($bar->getOpenIndex() !== -1) {
                $bar->closeAll();
                $this->client->redraw();
            }
            return;
        }
        if ($bar->getOpenIndex() === $idx) {
            $bar->closeAll();
        } else {
            $bar->setOpenIndex($idx);
        }
        $this->client->redraw();
    }

    /** A click while a menu is open: activate an item, walk into a submenu, or dismiss. */
    private function handleOpenClick(MenuBar $bar, int $mx, int $my): void
    {
        $hit = $bar->hitTestOpenChain($mx, $my, $this->renderer);
        if ($hit === null) {
            $bar->closeAll();
            $this->client->redraw();
            return;
        }

        [$depth, $itemIdx] = $hit;
        if ($itemIdx === -1) return;

        $chain = $bar->getOpenMenuChain();
        $menu  = $chain[$depth];
        $item  = $menu->getItems()[$itemIdx] ?? null;
        if (!$item instanceof MenuItem || !$item->enabled) return;

        if ($item->submenu !== null) {
            $menu->setExpandedIndex($itemIdx);
            $this->collapseBelow($chain, $depth);
            $this->client->redraw();
            return;
        }

        if ($item->onClick !== null) ($item->onClick)();
        $bar->closeAll();
        $this->client->redraw();
    }

    /** Updates the hover. */
    private function updateHover(MenuBar $bar, int $mx, int $my): void
    {
        $changed = false;

        // Top-level switch on hover (only while a chain is open).
        $barIdx = $bar->hitTestMenu($mx, $my, $this->renderer);
        if ($barIdx !== -1 && $barIdx !== $bar->getOpenIndex()) {
            $bar->setOpenIndex($barIdx);
            $this->client->redraw();
            return;
        }

        $chain = $bar->getOpenMenuChain();
        $hit   = $bar->hitTestOpenChain($mx, $my, $this->renderer);

        foreach ($chain as $i => $menu) {
            $hoveredItem = ($hit !== null && $hit[0] === $i) ? $hit[1] : -1;
            if ($menu->getHoveredIndex() !== $hoveredItem) {
                $menu->setHoveredIndex($hoveredItem);
                $changed = true;
            }
        }

        if ($hit !== null) {
            [$depth, $itemIdx] = $hit;
            $menu = $chain[$depth];
            $item = $itemIdx !== -1 ? ($menu->getItems()[$itemIdx] ?? null) : null;

            if ($item instanceof MenuItem && $item->submenu !== null && $item->enabled) {
                if ($menu->getExpandedIndex() !== $itemIdx) {
                    $menu->setExpandedIndex($itemIdx);
                    $this->collapseBelow($chain, $depth);
                    $changed = true;
                }
            } elseif ($menu->getExpandedIndex() !== -1) {
                $menu->setExpandedIndex(-1);
                $this->collapseBelow($chain, $depth);
                $changed = true;
            }
        }

        if ($changed) $this->client->redraw();
    }

    /** @param list<\Cyrnetix\X11\UI\Widget\Menu> $chain */
    private function collapseBelow(array $chain, int $depth): void
    {
        for ($i = $depth + 1; $i < count($chain); $i++) {
            $chain[$i]->setExpandedIndex(-1);
            $chain[$i]->setHoveredIndex(-1);
        }
    }

    /** The menu bar under the pointer, if any. */
    private function findBarUnder(int $mx, int $my): ?MenuBar
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof MenuBar && $w->hitTestBar($mx, $my)
        );
        return $found instanceof MenuBar ? $found : null;
    }

    /** The find open. */
    public function findOpen(): ?MenuBar
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof MenuBar && $w->getOpenIndex() !== -1
        );
        return $found instanceof MenuBar ? $found : null;
    }
}
