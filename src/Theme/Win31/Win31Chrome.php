<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Win31;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\BaseChrome;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;

/**
 * Windows 3.1x chrome — sampled from screenshots, pixel by pixel, not recalled.
 *
 * It is easy to assume Windows 3.1 is Windows 95 with a flat title bar. It
 * isn't, and the differences are the whole look:
 *
 * - **A raised control is outlined in black.** Win95 built its bevel out of four
 *   greys and no outline; 3.1 draws a hard `#000000` rectangle and puts a *two
 *   pixel* `#FFFFFF` highlight inside the top-left and a two pixel `#808080`
 *   shadow inside the bottom-right. Nothing in the era uses `#DFDFDF` — there is
 *   no light-grey layer at all.
 * - **Menus are white**, bar and popup both, with a single black rule under the
 *   bar. Win95 moved them onto the button face. Sampled at `#FFFFFF` in every
 *   reference shot; it is the first thing that reads as "3.1" on screen.
 * - **A checkbox is crossed, not ticked.** An `X` of two diagonal strokes.
 * - **A field is not a well.** An edit control, a list, a combo: two pixels of
 *   `#808080` then `#000000`, white inside. No client edge, no light rim.
 * - **The caption is flat `#000080`** with the title centred in bold white, and
 *   it has **no close button** — the box at the left is the window menu, and
 *   double-clicking it is how you closed a window until Windows 95.
 *
 * The era had no tab control, no toolbar, no progress bar and no tree view; the
 * few methods for those extrapolate from the button and the field rather than
 * inventing a fifth grey.
 */
final class Win31Chrome extends BaseChrome
{
    /** Highlight and shadow bands inside a raised control's outline. */
    private const BEVEL = 2;

    /** Rings of caption colour in a dialog's frame, inside its black outline. */
    private const DIALOG_BAND = 3;

    /** {@inheritDoc} */
    public function edge(Renderer $r, Rect $rect, Edge $edge): void
    {
        if ($rect->isEmpty()) return;

        $p = $this->palette;

        switch ($edge) {
            case Edge::None:
                return;

            case Edge::Raised:
                // Black outline, then two pixels of white and of shadow inside
                // it. Sampled off the PIF Editor's "Advanced..." button:
                // 000000 / FFFFFF FFFFFF / face / 808080 808080 / 000000.
                $this->outline($r, $rect, $p->frame);
                $this->bevelBand($r, $rect->inset(1), self::BEVEL, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::Sunken:
                // The field border, and the same shape the checkbox uses:
                // 808080 then 000000 down the top-left, FFFFFF then face up the
                // bottom-right.
                $this->bevelRing($r, $rect, $p->faceShadow, $p->faceHighlight);
                $this->bevelRing($r, $rect->inset(1), $p->frame, $p->face);
                return;

            case Edge::RaisedThin:
                $this->bevelRing($r, $rect, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::SunkenThin:
                $this->bevelRing($r, $rect, $p->faceShadow, $p->faceHighlight);
                return;

            case Edge::Etched:
                // A group box, and 3.1 drew that as a plain black rectangle —
                // the two-tone groove is a Windows 95 invention.
                $this->outline($r, $rect, $p->frame);
                return;

            case Edge::Outline:
                $this->outline($r, $rect, $p->frame);
                return;
        }
    }

    /**
     * Outlined in black, two pixels of white inside the top-left and two of
     * shadow inside the bottom-right. Pressing it swaps the two bands, which is
     * what makes a held button read as pushed in without moving its outline.
     */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);
        $this->roundedOutline($r, $rect, $p->frame);

        $this->bevelBand(
            $r,
            $rect->inset(1),
            self::BEVEL,
            $state->isPressed() ? $p->faceShadow     : $p->faceHighlight,
            $state->isPressed() ? $p->faceHighlight  : $p->faceShadow,
        );
    }

    /**
     * The button outline with its four corner pixels left out — 3.1's push
     * buttons have clipped corners, and it is the detail that most gives the era
     * away once you have seen it.
     *
     * Measured off the Run dialog's Cancel button: the top and bottom rules run
     * from `x+1` to `right-1`, and the side rules from `y+1` to `bottom-1`, so
     * every corner pixel belongs to whatever is behind the button.
     *
     * The corner keeps the face colour rather than the surface behind it, which
     * is invisible here because this theme paints every surface a widget can
     * stand on — face, panel and bar — the same `#C0C0C0`.
     *
     * @param array{int,int,int} $color
     */
    private function roundedOutline(Renderer $r, Rect $rect, array $color): void
    {
        if ($rect->width < 3 || $rect->height < 3) {
            $this->outline($r, $rect, $color);
            return;
        }

        $r->setForeground(...$color);
        $r->drawLines([
            [$rect->x + 1, $rect->y,          $rect->right() - 1, $rect->y],
            [$rect->x + 1, $rect->bottom(),   $rect->right() - 1, $rect->bottom()],
            [$rect->x,     $rect->y + 1,      $rect->x,           $rect->bottom() - 1],
            [$rect->right(), $rect->y + 1,    $rect->right(),     $rect->bottom() - 1],
        ]);
    }

    /**
     * A column header is a button, because 3.1 had no header control and the
     * closest thing it shipped — File Manager's sort bar — was a row of them.
     */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->button($r, $rect, $state);
    }

    /** White box with a sunken rim and, when checked, a black X. */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Content);
        $this->edge($r, $rect, Edge::Sunken);

        if (!$checked) return;

        $r->setForeground(...$this->palette->forText(
            $state === ControlState::Disabled ? TextStyle::Disabled : TextStyle::Content,
        ));

        // Two strokes each two pixels thick, inset off the rim. Offsets scale
        // with the box so a theme that widens the checkbox still gets an X.
        $box  = $rect->inset(3);
        $segs = [];
        for ($t = 0; $t < 2; $t++) {
            $segs[] = [$box->x + $t, $box->y, $box->right() + $t, $box->bottom()];
            $segs[] = [$box->right() - $t, $box->y, $box->x - $t, $box->bottom()];
        }
        $r->drawLines($segs);
    }

    /**
     * Two-tone circle: shadow over the top-left arc, white under the
     * bottom-right, black dot when selected. One ring, not the two Win95 used.
     */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p     = $this->palette;
        $size  = $rect->width;
        $outer = $size - 1;
        $cx    = $rect->x + intdiv($size, 2);
        $cy    = $rect->y + intdiv($size, 2);

        $r->setForeground(...$p->content);
        $r->fillCircle($cx, $cy, intdiv($size, 2) - 1);

        // Degrees, 0° at 3 o'clock, counter-clockwise.
        $r->setForeground(...$p->faceShadow);
        $r->drawArc($rect->x, $rect->y, $outer, $outer, 45, 180);
        $r->setForeground(...$p->faceHighlight);
        $r->drawArc($rect->x, $rect->y, $outer, $outer, 225, 180);

        if ($checked) {
            $r->setForeground(...($state === ControlState::Disabled ? $p->textDisabled : $p->text));
            $r->fillCircle($cx, $cy, 2);
        }
    }

    /**
     * A field is a black-lined box, not a bevelled well — so the content area
     * has to come back out inset by the border rather than by a bevel.
     *
     * 3.1's combo box puts a small raised button *inside* the field's border,
     * flush against it.
     */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect
    {
        $m = $this->metrics;

        $this->fill($r, $rect, Surface::Content);
        $this->edge($r, $rect, Edge::Sunken);

        $button = Rect::of(
            $rect->right() - $m->fieldButtonWidth - $m->fieldBorder + 1,
            $rect->y + $m->fieldBorder,
            $m->fieldButtonWidth,
            $rect->height - 2 * $m->fieldBorder,
        );
        $this->button($r, $button, $open ? ControlState::Pressed : ControlState::Normal);

        $shift = $open ? $m->pressOffset : 0;
        $this->arrow($r, $button->shift($shift, $shift), Direction::Down, $this->palette->text);

        return Rect::of(
            $rect->x + $m->fieldBorder,
            $rect->y + $m->fieldBorder,
            $button->x - $rect->x - $m->fieldBorder,
            $rect->height - 2 * $m->fieldBorder,
        );
    }

    /**
     * Tabs postdate the era by four years, so this is an extrapolation and says
     * so: a button-shaped tab, the active one without its bottom band, which is
     * what the black outline already suggests.
     */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);
        $r->setForeground(...$p->frame);
        $r->drawLines([
            [$rect->x, $rect->y + 1, $rect->x, $rect->bottom()],
            [$rect->x + 1, $rect->y, $rect->right() - 1, $rect->y],
            [$rect->right(), $rect->y + 1, $rect->right(), $rect->bottom()],
        ]);

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1],
            [$rect->x + 1, $rect->y + 1, $rect->x + 1, $rect->bottom()],
        ]);
        $r->setForeground(...$p->faceShadow);
        $r->drawLines([[$rect->right() - 1, $rect->y + 2, $rect->right() - 1, $rect->bottom()]]);
    }

    /** The scroll box is a small button, outline and all. */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->button($r, $rect, ControlState::Normal);
    }

    /**
     * Plain rectangular slider grip. The pentagonal thumb with the pointed tip
     * is a Win95 control; 3.1's only slider was the volume fader in Media
     * Player, a plain raised block.
     */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $this->button($r, $rect, ControlState::Normal);

        if ($focused) {
            $this->focusRect($r, $rect->inset(3));
        }
    }

    /** White, with a hard black frame. No inner highlight — that came with 95. */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Menu);
        $this->outline($r, $rect, $this->palette->frame);
    }

    /** White strip, black rule beneath, sampled at y=41 of the PIF Editor. */
    public function menuBar(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::MenuBar);

        $r->setForeground(...$this->palette->frame);
        $r->drawLines([[$rect->x, $rect->bottom(), $rect->right(), $rect->bottom()]]);
    }

    /** Flat navy, and a black rule along the bottom to close the caption off. */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, max(0, $rect->height - 1));

        $r->setForeground(...$this->palette->frame);
        $r->drawLines([[$rect->x, $rect->bottom(), $rect->right(), $rect->bottom()]]);
    }

    /**
     * Caption controls are thinner than push buttons — one pixel of highlight,
     * two of shadow — and they sit shoulder to shoulder with no gap, which is
     * why the black outline is what separates them.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);

        // Black only where the control meets the navy — its *sides*. The row
        // above it is the frame's own inner rule and the row below is the
        // caption's, so drawing top and bottom lines here doubles both of them,
        // which is the artefact this replaced. `captionInnerBottom` keeps the
        // control clear of the caption's rule; the frame keeps it clear above.
        $leftRule = $this->opensTrailingGroup($button);

        $r->setForeground(...$p->frame);
        $sides = [[$rect->right(), $rect->y, $rect->right(), $rect->bottom()]];
        if ($leftRule) {
            $sides[] = [$rect->x, $rect->y, $rect->x, $rect->bottom()];
        }
        $r->drawLines($sides);

        // The window-menu box is **flat**: face, its one rule, and the glyph.
        // Only the minimise and maximise boxes are bevelled — compare the two in
        // any reference shot and the difference is plain, which is what tells you
        // the left one isn't really a button.
        if ($button !== CaptionButton::Menu) {
            // One pixel of highlight, *two* of shadow — not the symmetric pair a
            // push button gets. Measured off the minimise box: white at the
            // caption's first row, face, then two rows of #808080 above the rule.
            $inner = $rect->insetEach($leftRule ? 1 : 0, 0, 1, 0);
            $this->bevelRing(
                $r,
                $inner,
                $state->isPressed() ? $p->faceShadow    : $p->faceHighlight,
                $state->isPressed() ? $p->faceHighlight : $p->faceShadow,
            );
            $this->bevelRing(
                $r,
                $inner->inset(1),
                $p->face,
                $state->isPressed() ? $p->faceHighlight : $p->faceShadow,
            );
        }

        $shift = $state->isPressed() ? $this->metrics->pressOffset : 0;
        $this->captionGlyph($r, $rect->inset(3)->shift($shift, $shift), $button, $p->text);
    }

    /**
     * Is this the control that opens the group at the *right* end of the caption?
     *
     * Every caption control rules its right edge in black; that one line is what
     * separates it from the navy, or from its neighbour, or from the frame. Only
     * the control that starts the trailing group needs a second rule on its
     * *left*, because its left is the one edge no other rule reaches — measured
     * on the minimise box, which is ruled both sides while the maximise box
     * beside it starts straight in on its highlight.
     */
    private function opensTrailingGroup(CaptionButton $button): bool
    {
        $first = $this->metrics->captionTrailing[0] ?? null;

        // Restore stands in for Maximize on a maximised window.
        return $first === $button
            || ($first === CaptionButton::Maximize && $button === CaptionButton::Restore);
    }

    /**
     * 3.1's caption glyphs, none of which match the ones Windows 95 settled on.
     *
     * Minimise and maximise are solid **triangles**, down and up — not a bar and
     * a box. Restore stacks both, which is what a maximised window showed. And
     * the window-menu box is a drawer pull: a white bar with a black outline and
     * a grey shadow offset a pixel down and right.
     *
     * @param array{int,int,int} $color
     */
    protected function captionGlyph(Renderer $r, Rect $box, CaptionButton $button, array $color): void
    {
        if ($box->isEmpty()) return;

        switch ($button) {
            case CaptionButton::Menu:
                // Three rows tall — outline, white core, outline — and a pixel
                // wider than the inset box, which is the 13x3 pull the shots
                // show inside a 19px control.
                $bar = Rect::of($box->x - 1, $box->centerY() - 2, max(4, $box->width + 1), 3);

                $r->setForeground(...$this->palette->faceShadow);
                $r->fillRect($bar->x + 1, $bar->y + 1, $bar->width, $bar->height);
                $r->setForeground(...$this->palette->content);
                $r->fillRect($bar->x, $bar->y, $bar->width, $bar->height);
                $r->setForeground(...$color);
                $r->drawRect($bar->x, $bar->y, $bar->width - 1, $bar->height - 1);
                return;

            case CaptionButton::Minimize:
                $this->arrow($r, $box, Direction::Down, $color, $this->captionArrowSize($box));
                return;

            case CaptionButton::Maximize:
                $this->arrow($r, $box, Direction::Up, $color, $this->captionArrowSize($box));
                return;

            case CaptionButton::Restore:
                // Both triangles, stacked — the maximised window's control.
                $half = intdiv($box->height, 2);
                $size = max(2, intdiv($box->width, 3));
                $this->arrow($r, $box->topSlice($half), Direction::Up, $color, $size);
                $this->arrow($r, $box->bottomSlice($half), Direction::Down, $color, $size);
                return;

            default:
                parent::captionGlyph($r, $box, $button, $color);
        }
    }

    /**
     * Black outer line, grey body, black inner line — sampled off the top edge
     * as 000000 / C0C0C0 C0C0C0 / 000000. Both lines matter: without the inner
     * one the caption bleeds into the frame.
     */
    public function windowBorder(Renderer $r, Rect $rect): void
    {
        $t = $this->metrics->windowBorder;
        if ($t <= 0) return;

        $this->fill($r, $rect->topSlice($t), Surface::Face);
        $this->fill($r, $rect->bottomSlice($t), Surface::Face);
        $this->fill($r, Rect::of($rect->x, $rect->y + $t, $t, $rect->height - 2 * $t), Surface::Face);
        $this->fill($r, Rect::of($rect->right() - $t + 1, $rect->y + $t, $t, $rect->height - 2 * $t), Surface::Face);

        $this->outline($r, $rect, $this->palette->frame);
        $this->outline($r, $rect->inset($t - 1), $this->palette->frame);
    }

    /**
     * A dialog's frame: a thick flat band in the caption colour.
     *
     * This is the era's most distinctive window furniture and it is *not* a
     * bevel — 3.1's dialogs are ringed in navy, not in grey, which is why
     * {@see \Cyrnetix\X11\Theme\Chrome::dialogFrame()} exists apart from
     * {@see Edge::Raised}. Sampled off the Run dialog: one pixel of black, three
     * of `#000080`, then a single white highlight against the face.
     */
    public function dialogFrame(Renderer $r, Rect $rect): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);
        $this->outline($r, $rect, $p->frame);

        $r->setForeground(...$p->captionActive);
        for ($i = 1; $i <= self::DIALOG_BAND; $i++) {
            $ring = $rect->inset($i);
            if ($ring->isEmpty()) return;
            $r->drawRect($ring->x, $ring->y, $ring->width - 1, $ring->height - 1);
        }

        $this->bevelRing($r, $rect->inset(self::DIALOG_BAND + 1), $p->faceHighlight, $p->faceHighlight);
    }


    /**
     * Triangle size for a caption control: 9 across and 5 down in an 18px box.
     *
     * {@see BaseChrome::arrow()} draws `2n-1` wide by `n` tall, and the era's
     * glyph leaves a clear margin inside the bevel rather than filling it.
     */
    private function captionArrowSize(Rect $box): int
    {
        return max(2, intdiv($box->width, 2) - 1);
    }

    /**
     * Highlight and shadow as *bands* rather than single-pixel rings.
     *
     * {@see BaseChrome::bevelRing()} draws one pixel; a 3.1 button wants two,
     * and its caption controls want one, so the width is the parameter. Drawn as
     * nested rings so the corners meet the way the era's do: the highlight owns
     * the top-left corner, the shadow the bottom-right.
     *
     * @param array{int,int,int} $topLeft
     * @param array{int,int,int} $bottomRight
     */
    private function bevelBand(Renderer $r, Rect $rect, int $width, array $topLeft, array $bottomRight): void
    {
        for ($i = 0; $i < $width; $i++) {
            $ring = $rect->inset($i);
            if ($ring->isEmpty()) return;

            $this->bevelRing($r, $ring, $topLeft, $bottomRight);
        }
    }
}
