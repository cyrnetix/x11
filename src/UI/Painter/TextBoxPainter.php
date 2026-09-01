<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\TextBox;

/**
 * Single-line text entry. The painter owns the horizontal-scroll and selection
 * arithmetic; the theme owns the well, the caret and the highlight.
 */
final class TextBoxPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(TextBox $tb, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();
        $palette = $this->themes->palette();

        $outer = Rect::of($tb->x, $tb->y, $tb->width, $tb->height);
        $chrome->well($r, $outer);

        // Horizontal scroll: keep the caret visible using real glyph widths.
        // displayText(), not getText(): a masked field draws and measures its
        // stand-in glyphs, so the caret sits where the user sees it.
        $text   = $tb->displayText();
        $cursor = $tb->getCursor();
        $textX  = $outer->x + $m->textBoxBorder + $m->textBoxPadding;
        $textY  = $r->baselineYForRect($outer->y, $outer->height);
        $avail  = $outer->width - 2 * $m->textBoxBorder - 2 * $m->textBoxPadding;

        $start = $tb->viewStart($r, $avail);

        // Grow the visible substring forward from $start until it would overflow.
        $end = $start;
        $len = strlen($text);
        while ($end < $len
            && $r->measureText(substr($text, $start, $end + 1 - $start)) <= $avail) {
            $end++;
        }
        $visible = substr($text, $start, $end - $start);

        $chrome->text($r, $visible, $textX, $textY, TextStyle::Content);

        // Selection band: highlight fill under the selected glyphs, then those
        // glyphs again in the selected role. Clipped to the visible viewport.
        if ($tb->hasSelection()) {
            $selStart = max($tb->getSelectionStart(), $start);
            $selEnd   = min($tb->getSelectionEnd(),   $end);

            if ($selEnd > $selStart) {
                $preW = $r->measureText(substr($text, $start,    $selStart - $start));
                $selW = $r->measureText(substr($text, $selStart, $selEnd   - $selStart));

                $band = Rect::of(
                    $textX + $preW,
                    $textY - $r->fontAscent(),
                    $selW,
                    $r->fontAscent() + $r->fontDescent(),
                );

                $chrome->fill($r, $band, Surface::Selection);
                $chrome->text(
                    $r,
                    substr($text, $selStart, $selEnd - $selStart),
                    $band->x,
                    $textY,
                    TextStyle::Selected,
                );
            }
        }

        if ($tb->isFocused()) {
            $caretX = $textX + $r->measureText(substr($text, $start, $cursor - $start));
            $chrome->caret($r, $caretX, $outer->y + 3, $outer->height - 6);
        }
    }
}
