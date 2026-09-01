<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\TextView;

/**
 * Read-only multi-line text in a well: the visible slice of lines, slid left by
 * the horizontal offset and clipped to the viewport.
 *
 * The clip is what makes sideways scrolling possible at all — a line scrolled
 * half-way off starts left of the well's own border, and without a clip its
 * glyphs would run out over the frame and into whatever sits beside the widget.
 */
final class TextViewPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(TextView $tv, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        // The widget can't measure its own lines — it has no Renderer — so the
        // first paint tells it how wide they are, which is what decides whether
        // the horizontal bar is there at all.
        $tv->measure($r);

        $outer = Rect::of($tv->x, $tv->y, $tv->width, $tv->height);
        $chrome->well($r, $outer);

        $viewport = Rect::of(
            $outer->x + $m->textBoxBorder,
            $outer->y + $m->textBoxBorder,
            $tv->viewportWidth() + 2 * $m->textBoxPadding,
            $tv->viewportHeight(),
        );
        if ($viewport->isEmpty()) return;

        // Neither bar reaches the corner where they meet, and the well behind it
        // is white — so fill it, or a scrolled view shows a white notch there.
        if ($tv->needsHorizontalScroll()) {
            $chrome->fill($r, Rect::of(
                $outer->right() - $m->textBoxBorder - $m->scrollBarThickness + 1,
                $outer->bottom() - $m->textBoxBorder - $m->scrollBarThickness + 1,
                $m->scrollBarThickness,
                $m->scrollBarThickness,
            ), Surface::Face);
        }

        $lines   = $tv->getLines();
        $first   = $tv->getScrollBar()->getValue();
        $visible = $tv->visibleLineCount();
        $offset  = $tv->horizontalOffset();

        $r->withClip($viewport, function () use (
            $tv, $r, $chrome, $m, $viewport, $lines, $first, $visible, $offset
        ): void {
            for ($i = 0; $i < $visible; $i++) {
                $idx = $first + $i;
                if (!isset($lines[$idx])) break;

                $row = Rect::of(
                    $viewport->x,
                    $viewport->y + $i * $tv->lineHeight,
                    $viewport->width,
                    $tv->lineHeight,
                );

                $style = TextStyle::Content;
                if ($tv->isLineSelected($idx)) {
                    $chrome->fill($r, $row, Surface::Selection);
                    $style = TextStyle::Selected;
                }

                if ($lines[$idx] === '') continue;

                $chrome->text(
                    $r,
                    $lines[$idx],
                    $row->x + $m->textBoxPadding - $offset,
                    $r->baselineYForRect($row->y, $row->height),
                    $style,
                );
            }
        });
    }
}
