<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\ComboBox;

/**
 * Editable combo box: the same field as a drop-down, but with a caret and a
 * selection band over the typed text. The popup is an owned ListBox and paints
 * itself on the overlay pass.
 */
final class ComboBoxPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the field. */
    public function paintField(ComboBox $cb, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();
        $palette = $this->themes->palette();

        $outer   = Rect::of($cb->x, $cb->y, $cb->width, $m->fieldHeight);
        $content = $chrome->dropField($r, $outer, $cb->isOpen(), editable: true);

        // Same scroll math as TextBoxPainter; the widget owns viewStart().
        $text    = $cb->getText();
        $cursor  = $cb->getCursor();
        $renderX = $content->x + $m->fieldPadding;
        $renderY = $r->baselineYForRect($outer->y, $outer->height);
        $avail   = $content->width - 2 * $m->fieldPadding;

        $start = $cb->viewStart($r, $avail);
        $end   = $start;
        $len   = strlen($text);
        while ($end < $len
            && $r->measureText(substr($text, $start, $end + 1 - $start)) <= $avail) {
            $end++;
        }

        $chrome->text(
            $r,
            substr($text, $start, $end - $start),
            $renderX,
            $renderY,
            $chrome->dropFieldTextStyle(true),
        );

        if ($cb->hasSelection()) {
            $selStart = max($cb->getSelectionStart(), $start);
            $selEnd   = min($cb->getSelectionEnd(),   $end);

            if ($selEnd > $selStart) {
                $preW = $r->measureText(substr($text, $start,    $selStart - $start));
                $selW = $r->measureText(substr($text, $selStart, $selEnd   - $selStart));

                $band = Rect::of(
                    $renderX + $preW,
                    $renderY - $r->fontAscent(),
                    $selW,
                    $r->fontAscent() + $r->fontDescent(),
                );

                $chrome->fill($r, $band, Surface::Selection);
                $chrome->text(
                    $r,
                    substr($text, $selStart, $selEnd - $selStart),
                    $band->x,
                    $renderY,
                    TextStyle::Selected,
                );
            }
        }

        if ($cb->isFocused()) {
            $caretX = $renderX + $r->measureText(substr($text, $start, $cursor - $start));
            $chrome->caret($r, $caretX, $content->y + 1, $content->height - 2);
        }
    }
}
