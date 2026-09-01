<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * Role of a text run. Themes map these to colours and, where the era calls
 * for it, to an effect: Win9x engraves disabled labels (white shadow one
 * pixel down-right), CDE just greys them out.
 */
enum TextStyle
{
    /** Body text on a Face surface. */
    case Normal;

    /** Body text on a Content (white) surface. */
    case Content;

    /** Text inside a selection highlight. */
    case Selected;

    /** Menu item label at rest. */
    case Menu;

    /**
     * Menu item label under the highlight. Separate from {@see Selected}
     * because the two conventions genuinely diverge — Platinum tints list
     * selections and keeps their text black, but reverses menu highlights to
     * white on the accent colour.
     */
    case MenuHighlighted;

    /** Greyed-out / engraved. */
    case Disabled;

    /** Dimmed but enabled (out-of-month calendar days, secondary columns). */
    case Dim;

    /** Window / dialog caption text. */
    case Caption;

    /** Inactive window caption text. */
    case CaptionInactive;

    /** Tooltip text. */
    case Tooltip;
}
