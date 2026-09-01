<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * The kind of border a surface wants — *semantic*, not pixel-level. Each
 * theme's {@see Chrome} decides what "raised" actually looks like: Win9x
 * draws a two-layer light/dark bevel, Platinum a 1px black frame with an
 * inner highlight, CDE a 2px Motif top/bottom shadow pair.
 */
enum Edge
{
    /** Nothing drawn. */
    case None;

    /** Control pops out of the surface (button at rest, tab, header cell). */
    case Raised;

    /** Control sits below the surface (pressed button, text field, list). */
    case Sunken;

    /** Thin one-layer raised edge (toolbar hover, status pane, scroll thumb). */
    case RaisedThin;

    /** Thin one-layer sunken edge (toolbar pressed, status pane, slider track). */
    case SunkenThin;

    /** Etched groove — sunken outer + raised inner (group box, separators). */
    case Etched;

    /** Hard single-colour outline (menu popups, calendar popups). */
    case Outline;
}
