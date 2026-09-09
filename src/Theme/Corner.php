<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * How far a rounded corner cuts into each row.
 *
 * One table, two readers, and that is the whole reason this is not a private
 * method somewhere: {@see BaseChrome::roundedRect()} draws with it and
 * {@see \Cyrnetix\X11\UI\Widget\WindowFrame::shapeRects()} cuts the window with
 * it. Let those disagree by a pixel and a rounded window shows a hairline of
 * desktop inside its own border, or clips its own edge away — the same class of
 * bug as a widget whose hit-test and painter derive a size separately.
 *
 * There is no anti-aliasing here, and none is coming: the toolkit draws with the
 * core protocol's rectangles and lines, which are hard-edged. So a corner is a
 * staircase, and the only question is where each step falls. The circle is
 * sampled at the *centre* of each row — `sqrt(r² - (r - y - 0.5)²)` — because
 * sampling at the row's edge biases every step the same way and makes a small
 * radius look like a chamfer rather than a curve.
 *
 * Radii are small and few, so the tables are computed once and kept.
 */
final class Corner
{
    /** @var array<int, list<int>> Inset table per radius. */
    private static array $tables = [];

    /**
     * Inset per row for the top-left corner of a circle of this radius, from
     * the outermost row inwards.
     *
     * Row 0 is the topmost row of the corner and is inset the most; row
     * `$radius - 1` is the last row the curve touches. Mirror it for the other
     * three corners.
     *
     * @return list<int> Exactly $radius entries; empty for a radius below 1.
     */
    public static function insets(int $radius): array
    {
        if ($radius < 1) return [];

        if (isset(self::$tables[$radius])) return self::$tables[$radius];

        $insets = [];
        for ($y = 0; $y < $radius; $y++) {
            // Distance from the circle's centre to this row's middle.
            $dy      = $radius - $y - 0.5;
            $reach   = sqrt(max(0.0, $radius * $radius - $dy * $dy));
            $insets[] = max(0, (int) round($radius - $reach));
        }

        return self::$tables[$radius] = $insets;
    }

    /**
     * The rectangles a rounded rectangle occupies, top to bottom.
     *
     * The corner rows one at a time and everything between them as a single
     * rectangle, which is what keeps both callers cheap: a radius of eight is
     * seventeen rectangles rather than one per row of the whole shape.
     *
     * A radius too large for the rectangle is clamped to half its smaller side,
     * so asking for a 20-pixel corner on a 12-pixel button gives a circle-ish
     * blob instead of an inside-out shape.
     *
     * @return list<array{int, int, int, int}> [x, y, width, height]
     */
    public static function rects(int $x, int $y, int $width, int $height, int $radius): array
    {
        if ($width <= 0 || $height <= 0) return [];

        $radius = min($radius, intdiv(min($width, $height), 2));
        if ($radius < 1) return [[$x, $y, $width, $height]];

        $insets = self::insets($radius);
        $rects  = [];

        for ($row = 0; $row < $radius; $row++) {
            $inset = min($insets[$row], intdiv($width, 2));
            $span  = $width - 2 * $inset;
            if ($span <= 0) continue;

            $rects[] = [$x + $inset, $y + $row, $span, 1];
            $rects[] = [$x + $inset, $y + $height - 1 - $row, $span, 1];
        }

        $middle = $height - 2 * $radius;
        if ($middle > 0) {
            $rects[] = [$x, $y + $radius, $width, $middle];
        }

        return $rects;
    }
}
