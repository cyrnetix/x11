<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * Widgets that surface a tooltip implement this interface. The query
 * takes a position so a single widget can offer different tooltips for
 * different sub-regions (a Toolbar returns the hovered button's label,
 * a ListView header could return the column name, …).
 *
 * Returning null = "no tooltip for this position" — the global
 * TooltipHandler hides any pending tooltip and stops scanning.
 */
interface Tooltipped
{
    /** The tooltip to show at these coordinates, if there is one. */
    public function getTooltipAt(int $mx, int $my): ?string;
}
