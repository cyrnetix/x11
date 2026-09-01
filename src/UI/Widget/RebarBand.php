<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * One band inside a {@see Rebar}. Holds the child widget that lives in
 * the band plus the per-band layout knobs:
 *   - caption / captionWidth — text shown to the left of the child (Win
 *     calls it the "label"). Pre-computed pixel width since the layout
 *     pass doesn't have a Renderer.
 *   - width / fillWidth     — fixed pixel width, 0 to hug the child's own
 *     width (which keeps theme metrics out of the caller's arithmetic), or
 *     "take the leftover space in this row" for elastic bands (address bar).
 *   - showGrip              — render the chevron handle on the left.
 *
 * Layout-time bookkeeping (computed x position / final width) lives in
 * the band so painter + hit-test can read them without re-running the
 * layout math.
 */
final class RebarBand
{
    /** Filled in by {@see Rebar::layout()}; absolute coords. */
    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;

    /** Takes size. */
    public function __construct(
        public readonly Widget $child,
        public readonly string $caption       = '',
        public readonly int    $captionWidth  = 0,
        /** Fixed pixel width, or 0 to hug the grip + caption + child. */
        public readonly int    $width         = 100,
        public readonly bool   $fillWidth     = false,
        public readonly bool   $showGrip      = true,
    ) {}
}
