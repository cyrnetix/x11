<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Separator;

/** Draws a {@see Separator} through the theme's own dividing-line treatment. */
final class SeparatorPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Separator $separator, Renderer $r): void
    {
        $thickness = $separator->thickness();

        $rect = $separator->isHorizontal()
            ? Rect::of($separator->x, $separator->y, $separator->length, $thickness)
            : Rect::of($separator->x, $separator->y, $thickness, $separator->length);

        if ($rect->isEmpty()) return;

        $this->themes->chrome()->separator($r, $rect, $separator->isHorizontal());
    }
}
