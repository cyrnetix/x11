<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Cde;

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
 * CDE / Motif chrome — the Solaris desktop look.
 *
 * Motif's bevel is *uniform*: `shadowThickness` (2) pixels of topShadow along
 * the top and left, the same thickness of bottomShadow along the bottom and
 * right, with no second tone in between. That single difference is most of
 * what separates it from the Windows look, and everything else here follows
 * from the widget set rather than from colour:
 *
 *  - toggles are squares that sink and fill with the select colour — no tick;
 *  - radio buttons are diamonds;
 *  - armed menu items rise instead of inverting, so their labels stay black;
 *  - scrollbar troughs are *darker* than the face, not lighter;
 *  - the default button wears an extra shadow ring;
 *  - keyboard focus is a solid highlight ring.
 */
final class CdeChrome extends BaseChrome
{
    /** {@inheritDoc} */
    public function edge(Renderer $r, Rect $rect, Edge $edge): void
    {
        if ($rect->isEmpty()) return;

        $p     = $this->palette;
        $inner = $rect->inset(1);

        switch ($edge) {
            case Edge::None:
                return;

            case Edge::Raised:
                // Both rings the same tone — Motif's flat 2px shadow.
                $this->bevelRing($r, $rect,  $p->faceHighlight, $p->faceShadow);
                $this->bevelRing($r, $inner, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::Sunken:
                $this->bevelRing($r, $rect,  $p->faceShadow, $p->faceHighlight);
                $this->bevelRing($r, $inner, $p->faceShadow, $p->faceHighlight);
                return;

            case Edge::RaisedThin:
                $this->bevelRing($r, $rect, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::SunkenThin:
                $this->bevelRing($r, $rect, $p->faceShadow, $p->faceHighlight);
                return;

            case Edge::Etched:
                // XmSHADOW_ETCHED_IN.
                $this->bevelRing($r, $rect,  $p->faceShadow,    $p->faceHighlight);
                $this->bevelRing($r, $inner, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::Outline:
                $this->outline($r, $rect, $p->frame);
                return;
        }
    }

    /** {@inheritDoc} */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        // The dialog's default action keeps a shadow ring around the button,
        // so the button itself shrinks to sit inside it.
        if ($state === ControlState::Default) {
            $this->fill($r, $rect, Surface::Face);
            $this->edge($r, $rect, Edge::Sunken);
            $rect = $rect->inset(3);
        }

        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, $state->isPressed() ? Edge::Sunken : Edge::Raised);
    }

    /** Motif has no flat toolbars — buttons are always bevelled. */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        $sunken = $checked || $state === ControlState::Pressed;
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, $sunken ? Edge::Sunken : Edge::Raised);
    }

    /** {@inheritDoc} */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, $state->isPressed() ? Edge::Sunken : Edge::Raised);
    }

    /**
     * XmToggleButton: a square that rises when off and sinks — filled with the
     * select colour — when on. No tick mark anywhere in sight.
     */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $r->setForeground(...($checked ? $this->palette->selection : $this->palette->face));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $this->edge($r, $rect, $checked ? Edge::Sunken : Edge::Raised);
    }

    /** XmToggleButton in XmONE_OF_MANY mode: a bevelled diamond. */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $this->diamond($r, $rect, $checked);
    }

    /** Notebook tab: raised, with the top corners knocked off. */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);

        // Top edge inset by one pixel at each end, then the diagonal corners.
        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x + 2, $rect->y, $rect->right() - 2, $rect->y],
            [$rect->x + 2, $rect->y + 1, $rect->right() - 2, $rect->y + 1],
            [$rect->x, $rect->y + 2, $rect->x, $rect->bottom()],
            [$rect->x + 1, $rect->y + 2, $rect->x + 1, $rect->bottom()],
            [$rect->x, $rect->y + 2, $rect->x + 2, $rect->y],
        ]);
        $r->setForeground(...$p->faceShadow);
        $r->drawLines([
            [$rect->right(), $rect->y + 2, $rect->right(), $rect->bottom()],
            [$rect->right() - 1, $rect->y + 2, $rect->right() - 1, $rect->bottom()],
            [$rect->right() - 2, $rect->y, $rect->right(), $rect->y + 2],
        ]);

        // An inactive tab reads as "behind" by keeping a shadow along its top.
        if (!$active) {
            $r->drawLines([[$rect->x + 2, $rect->y + 2, $rect->right() - 2, $rect->y + 2]]);
        }
    }

    /** Troughs are darker than the face and cut into it. */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        $this->fill($r, $rect, Surface::Track);
        $this->edge($r, $rect, Edge::SunkenThin);
    }

    /** {@inheritDoc} */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, Edge::Raised);
    }

    /** {@inheritDoc} */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->fill($r, $rect, Surface::Track);
        $this->edge($r, $rect, Edge::Sunken);
    }

    /** XmScale slider: a raised block with a groove across the travel axis. */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, Edge::Raised);

        $p = $this->palette;
        if ($point === Direction::Up || $point === Direction::Down) {
            $cx = $rect->centerX();
            $r->setForeground(...$p->faceShadow);
            $r->drawLines([[$cx, $rect->y + 3, $cx, $rect->bottom() - 3]]);
            $r->setForeground(...$p->faceHighlight);
            $r->drawLines([[$cx + 1, $rect->y + 3, $cx + 1, $rect->bottom() - 3]]);
        } else {
            $cy = $rect->centerY();
            $r->setForeground(...$p->faceShadow);
            $r->drawLines([[$rect->x + 3, $cy, $rect->right() - 3, $cy]]);
            $r->setForeground(...$p->faceHighlight);
            $r->drawLines([[$rect->x + 3, $cy + 1, $rect->right() - 3, $cy + 1]]);
        }

        if ($focused) {
            $this->focusRect($r, $rect);
        }
    }

    /** Menus are raised panels, not framed sheets. */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Menu);
        $this->edge($r, $rect, Edge::Raised);
    }

    /** The whole strip is a raised Motif panel. */
    public function menuBar(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::MenuBar);
        $this->edge($r, $rect, Edge::RaisedThin);
    }

    /** An armed item rises out of the menu; its label stays black. */
    public function menuItemHighlight(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, Edge::Raised);
    }

    /** The menu highlight text style. */
    public function menuHighlightTextStyle(): TextStyle
    {
        return TextStyle::Normal;
    }

    /** {@inheritDoc} */
    public function popupFrame(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Content);
        $this->edge($r, $rect, Edge::Raised);
    }

    /** Raised square with a +/− glyph — the Motif outline expander. */
    public function treeToggle(Renderer $r, Rect $rect, bool $expanded, bool $onSelection): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, Edge::RaisedThin);

        $r->setForeground(...$this->palette->text);
        $mid = intdiv($rect->width, 2);
        $r->drawLines([[$rect->x + 2, $rect->y + $mid, $rect->right() - 2, $rect->y + $mid]]);
        if (!$expanded) {
            $r->drawLines([[$rect->x + $mid, $rect->y + 2, $rect->x + $mid, $rect->bottom() - 2]]);
        }
    }


    /** Raised bar in the active/inactive shade — dtwm's title area. */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /**
     * Motif caption controls are raised squares carrying geometric marks: a
     * small square minimizes, a large one maximizes, a dash is the window menu.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $this->edge($r, $rect, $state->isPressed() ? Edge::Sunken : Edge::Raised);

        $inner = $rect->inset(4);
        if ($inner->isEmpty()) return;

        switch ($button) {
            case CaptionButton::Menu:
                $r->setForeground(...$this->palette->faceShadow);
                $r->fillRect($inner->x, $inner->centerY() - 1, $inner->width, 3);
                $r->setForeground(...$this->palette->faceHighlight);
                $r->fillRect($inner->x, $inner->centerY() + 2, $inner->width, 1);
                return;

            case CaptionButton::Minimize:
                // Tiny square, centred.
                $dot = $inner->centeredSquare(max(3, intdiv($inner->width, 2)));
                $this->fill($r, $dot, Surface::Face);
                $this->edge($r, $dot, Edge::RaisedThin);
                return;

            case CaptionButton::Maximize:
            case CaptionButton::Restore:
                // The same shape, full size.
                $this->fill($r, $inner, Surface::Face);
                $this->edge($r, $inner, Edge::RaisedThin);
                return;

            case CaptionButton::Close:
                $this->captionGlyph($r, $inner, CaptionButton::Close, $this->palette->text);
                return;
        }
    }

    /** Motif frames carry visible corner handles, delimited by shadow lines. */
    public function windowBorder(Renderer $r, Rect $rect): void
    {
        parent::windowBorder($r, $rect);

        $corner = $this->metrics->windowResizeCorner;
        $t      = $this->metrics->windowBorder;
        if ($corner <= 0 || $t <= 0) return;

        $r->setForeground(...$this->palette->faceShadow);
        $r->drawLines([
            // Verticals separating the corner handles from the side rails.
            [$rect->x + $corner, $rect->y, $rect->x + $corner, $rect->y + $t - 1],
            [$rect->right() - $corner, $rect->y, $rect->right() - $corner, $rect->y + $t - 1],
            [$rect->x + $corner, $rect->bottom() - $t + 1, $rect->x + $corner, $rect->bottom()],
            [$rect->right() - $corner, $rect->bottom() - $t + 1, $rect->right() - $corner, $rect->bottom()],
            // Horizontals on the left and right rails.
            [$rect->x, $rect->y + $corner, $rect->x + $t - 1, $rect->y + $corner],
            [$rect->x, $rect->bottom() - $corner, $rect->x + $t - 1, $rect->bottom() - $corner],
            [$rect->right() - $t + 1, $rect->y + $corner, $rect->right(), $rect->y + $corner],
            [$rect->right() - $t + 1, $rect->bottom() - $corner, $rect->right(), $rect->bottom() - $corner],
        ]);
    }

    /** Motif corners are bevelled blocks rather than dotted grips. */
    public function grip(Renderer $r, Rect $rect): void
    {
        $box = $rect->rightSlice(min(14, $rect->width))->bottomSlice(min(14, $rect->height));
        $this->fill($r, $box, Surface::Face);
        $this->edge($r, $box, Edge::Raised);
    }

    /** Solid highlight ring — XmNhighlightThickness made visible. */
    public function focusRect(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;
        $this->outline($r, $rect, $this->palette->focus);
        $this->outline($r, $rect->inset(1), $this->palette->focus);
    }

    /** No engraving in Motif — disabled text is simply dimmed. */
    protected function drawDisabledText(Renderer $r, string $text, int $x, int $baselineY): void
    {
        $r->setForeground(...$this->palette->textDisabled);
        $r->drawGlyphs($text, $x, $baselineY);
    }

    // -------------------------------------------------------------------------
    // Motif-specific helpers
    // -------------------------------------------------------------------------

    /**
     * Bevelled diamond filling $rect. Sunken (selected) diamonds carry the
     * select colour; raised ones stay face-coloured.
     */
    private function diamond(Renderer $r, Rect $rect, bool $selected): void
    {
        $p    = $this->palette;
        $size = min($rect->width, $rect->height);
        // Odd size keeps the apexes on a single pixel.
        $size -= ($size % 2 === 0) ? 1 : 0;
        $half = intdiv($size, 2);
        $cx   = $rect->x + $half;
        $cy   = $rect->y + $half;

        // Interior, drawn as horizontal spans.
        $r->setForeground(...($selected ? $p->selection : $p->face));
        for ($dy = -$half; $dy <= $half; $dy++) {
            $span = $half - abs($dy);
            $r->fillRect($cx - $span, $cy + $dy, 2 * $span + 1, 1);
        }

        // Two-pixel bevel: the upper two facets catch the light, the lower two
        // fall into shadow — and the pair swaps when the diamond is pushed in.
        $lit    = $selected ? $p->faceShadow    : $p->faceHighlight;
        $shaded = $selected ? $p->faceHighlight : $p->faceShadow;

        for ($i = 0; $i < 2; $i++) {
            $r->setForeground(...$lit);
            $r->drawLines([
                [$cx - $half + $i, $cy, $cx, $cy - $half + $i],   // north-west facet
                [$cx, $cy - $half + $i, $cx + $half - $i, $cy],   // north-east facet
            ]);
            $r->setForeground(...$shaded);
            $r->drawLines([
                [$cx + $half - $i, $cy, $cx, $cy + $half - $i],   // south-east facet
                [$cx, $cy + $half - $i, $cx - $half + $i, $cy],   // south-west facet
            ]);
        }
    }
}
