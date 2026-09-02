<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * A control in a window's caption. Which of these appear, and at which end of
 * the bar, is a per-era decision held in {@see Metrics::$captionLeading} and
 * {@see Metrics::$captionTrailing} — Windows puts three at the right, Platinum
 * puts the close box at the left, Motif leads with the window-menu button.
 */
enum CaptionButton
{
    /** Window-menu button (Motif) or the app icon (Windows). Decorative here. */
    case Menu;

    case Minimize;

    /** Shown while the window is its normal size. */
    case Maximize;

    /** Shown instead of {@see Maximize} once the window is maximized. */
    case Restore;

    case Close;
}
