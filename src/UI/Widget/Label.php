<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * Static text.
 *
 * $y is the **top** of the text, the same as every other widget — the painter
 * works the baseline out from the font's ascent. Pass $lineHeight to centre the
 * text in a row of that height instead, which is how you line a label up with a
 * field beside it without hardcoding the font's metrics or the theme's control
 * heights.
 */
final class Label extends Widget
{
    /**
     * Colours are normally left null: the painter takes them from the surface
     * the label was dropped onto (a tab page, a rebar strip, the window face),
     * which matters because ImageText8 fills the glyph cell and a mismatch draws
     * a visible box around the words.
     *
     * @param int|null                  $lineHeight Centre the text in a row this tall.
     * @param array{int, int, int}|null $foreground
     * @param array{int, int, int}|null $background
     */
    public function __construct(
        public string $text,
        int $x, int $y,
        public readonly ?int   $lineHeight = null,
        public readonly ?array $foreground = null,
        public readonly ?array $background = null,
    ) {
        parent::__construct($x, $y);
    }

    /**
     * Change the text in place.
     *
     * For a line that reports something — a row count, a status — where making
     * a new Label would mean re-attaching it to the tree just to change a
     * string. The width isn't stored, so nothing needs recomputing.
     */
    public function setText(string $text): void
    {
        $this->text = $text;
    }
}
