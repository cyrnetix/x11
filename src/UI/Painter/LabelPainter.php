<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Label;

/**
 * Static text. Only the glyph pixels are drawn, so a label shows whatever
 * surface it was dropped onto — a tab page, a rebar strip, the window face —
 * without having to know its colour. A widget that names a $background is
 * asking for a filled band instead, and gets the opaque draw.
 */
final class LabelPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Label $label, Renderer $renderer): void
    {
        $palette = $this->themes->palette();

        $renderer->setForeground(...($label->foreground ?? $palette->text));

        // The widget's y is the top of the text; a $lineHeight centres it in a
        // row instead, so labels line up with the fields next to them whatever
        // the theme's control heights are.
        $baseline = $label->lineHeight !== null
            ? $renderer->baselineYForRect($label->y, $label->lineHeight)
            : $label->y + $renderer->fontAscent();

        if ($label->background === null) {
            $renderer->drawGlyphs($label->text, $label->x, $baseline);
            return;
        }

        $renderer->setBackground(...$label->background);
        $renderer->drawText($label->text, $label->x, $baseline);
    }
}
