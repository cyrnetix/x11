<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * What a filled area *is*, so the theme can pick its own colour (and, for
 * themes that want it, a pattern — Platinum's pinstriped title bars, CDE's
 * flat blue-grey face).
 */
enum Surface
{
    /** Dialog/control background — Win9x 3DFACE and friends. */
    case Face;

    /**
     * A tab page's interior. Mac OS 9 lightens it to #EEEEEE against a #DDDDDD
     * window, so anything drawn *on* a page has to ask for this rather than
     * assuming the face colour.
     */
    case Panel;

    /** Editable / scrollable content background (text fields, list rows). */
    case Content;

    /** Status bar, toolbar strip, rebar. */
    case Bar;

    /** The menu strip specifically — white in Mac OS, face-coloured elsewhere. */
    case MenuBar;

    /** Menu popup body. */
    case Menu;

    /** Scrollbar trough / progress trough. */
    case Track;

    /** Selection highlight. */
    case Selection;

    /** Tooltip body. */
    case Tooltip;
}
