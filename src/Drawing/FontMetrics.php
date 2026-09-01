<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * Per-character font metrics parsed from an X11 QueryFont reply.
 * Stores one width per glyph in the font's character range so painters and
 * hit-testers can compute the actual rendered width of proportional text.
 */
final class FontMetrics
{
    /**
     * @param int        $ascent        Pixels above the baseline (max).
     * @param int        $descent       Pixels below the baseline (max).
     * @param int        $defaultWidth  Width used for characters outside the table.
     * @param int        $minChar       First character code with a width entry.
     * @param int        $maxChar       Last character code with a width entry.
     * @param list<int>  $widths        $widths[c - $minChar] = pixel advance for char c.
     */
    public function __construct(
        public readonly int   $ascent,
        public readonly int   $descent,
        public readonly int   $defaultWidth,
        public readonly int   $minChar,
        public readonly int   $maxChar,
        public readonly array $widths,
    ) {}

    /** Measures the text. */
    public function measureText(string $text): int
    {
        $sum = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($text[$i]);
            $sum += ($c >= $this->minChar && $c <= $this->maxChar)
                ? ($this->widths[$c - $this->minChar] ?? $this->defaultWidth)
                : $this->defaultWidth;
        }
        return $sum;
    }

    /** The line height. */
    public function lineHeight(): int
    {
        return $this->ascent + $this->descent;
    }
}
