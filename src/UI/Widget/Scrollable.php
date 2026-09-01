<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;


/**
 * Any widget that exposes an embedded ScrollBar and a hit-test for its outer
 * rectangle. WidgetManager uses this for routing scroll-wheel events
 * (button 4 / 5) to the right scrollbar — hover over a ListBox / TreeView /
 * ListView and spin the wheel to scroll it.
 */
interface Scrollable extends Bounded
{
    /** The scroll bar. */
    public function getScrollBar(): ScrollBar;
    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool;

    // bounds() and paintsOwnBackground() come from Bounded: scrolling changes
    // everything inside the widget and nothing outside, which is exactly the
    // region a repaint has to cover.
}
