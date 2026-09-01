<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

use Cyrnetix\X11\Drawing\Rect;

/**
 * Where the pieces of a caption go: the buttons, and the band left for the title.
 *
 * **One caption, one set of formulas.** The window frame and every dialog draw
 * the same caption, so they must place it the same way — and when they each had
 * their own code they did not. A form's caption was drawn by a single
 * `titleBar()` primitive that hand-rolled its own gradient, put a 12-pixel icon
 * box at a hard-coded offset and had no buttons at all, which is why a dialog's
 * title bar looked like a different era from the window behind it.
 *
 * It lives in `Theme` rather than beside either widget because it is entirely a
 * function of {@see Metrics}: which buttons a caption has, at which end, how big
 * they are and what they leave for the title are all theme numbers. Callers pass
 * the button lists they want — a window's are
 * {@see Metrics::$captionLeading}/{@see Metrics::$captionTrailing}, a dialog's are
 * {@see Metrics::$dialogCaptionLeading}/{@see Metrics::$dialogCaptionTrailing} —
 * and resolve any state-dependent button (Maximize to Restore) before calling.
 */
final class CaptionLayout
{
    /**
     * Buttons and their rectangles, in visual order (leading group first).
     *
     * Buttons live in the caption's furniture band: the bar minus whatever the
     * theme reserves at the bottom. The drag texture uses the same band, which is
     * what keeps the two lined up.
     *
     * @param list<CaptionButton> $leading
     * @param list<CaptionButton> $trailing
     * @return list<array{CaptionButton, Rect}>
     */
    public static function buttons(Metrics $m, Rect $caption, array $leading, array $trailing): array
    {
        if ($caption->isEmpty()) return [];

        $band = $caption->insetEach(0, $m->captionPadding, 0, $m->captionInnerBottom);
        if ($band->isEmpty()) return [];

        $size = min($m->captionButtonSize, $band->height);
        if ($size <= 0) return [];

        $y   = $band->y + intdiv($band->height - $size, 2);
        $out = [];

        $cursor = $caption->x + $m->captionPadding;
        foreach ($leading as $button) {
            $out[] = [$button, Rect::of($cursor, $y, $size, $size)];
            $cursor += $size + $m->captionButtonGap;
        }

        // The trailing group is placed right-to-left so it stays flush to the
        // edge, then reversed back into visual order.
        $cursor = $caption->right() - $m->captionPadding + 1;
        $tail   = [];
        foreach (array_reverse($trailing) as $button) {
            $cursor -= $size;
            $tail[]  = [$button, Rect::of($cursor, $y, $size, $size)];
            $cursor -= $m->captionButtonGap;
        }

        return [...$out, ...array_reverse($tail)];
    }

    /**
     * The band left for the title, between whichever button groups exist.
     *
     * A button is "leading" or "trailing" by which side of the caption's centre
     * it sits on, not by which list it came from — a theme that puts its close
     * box at the left and its zoom box at the right (Mac OS 9) has one of each,
     * and the title has to clear both.
     *
     * @param list<array{CaptionButton, Rect}> $buttons From {@see buttons()}.
     */
    public static function titleRect(Metrics $m, Rect $caption, array $buttons): Rect
    {
        if ($caption->isEmpty()) return $caption;

        $left  = $caption->x;
        $right = $caption->right();

        foreach ($buttons as [, $rect]) {
            if ($rect->centerX() < $caption->centerX()) {
                $left = max($left, $rect->right() + 1 + $m->captionTitleGap);
            } else {
                $right = min($right, $rect->x - 1 - $m->captionTitleGap);
            }
        }

        return Rect::of($left, $caption->y, max(0, $right - $left + 1), $caption->height);
    }
}
