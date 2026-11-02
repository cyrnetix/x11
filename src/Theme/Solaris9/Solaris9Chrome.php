<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Solaris9;

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
 * Solaris 9 chrome: Motif 2.1 under dtwm, one pixel at a time.
 *
 * Everything here was read off screenshots, and the pixels disagree with the
 * Motif the {@see \Cyrnetix\X11\Theme\Cde\CdeChrome} draws in ways that are
 * easy to assume away:
 *
 * - **A shadow is one pixel**, and its corners belong to the top shadow: the
 *   highlight colour takes the whole top row and the whole left column, the
 *   shadow colour the rest of the bottom row and right column. That is how
 *   XmeDrawShadows draws, and {@see ring()} does the same; `bevelRing()` gives
 *   the shadow both corners, which is wrong at two corners of every control.
 * - **A well is shaded from its own colour.** A cream text field has a
 *   `#99948B` line above it and `#CCC5BA` below, not the grey face's shadows,
 *   with a ring of the parent's colour outside that for the keyboard highlight.
 * - **Arrows are bevelled triangles in the trough**, not buttons with arrows
 *   on them: the triangle's left slant is lit, its right slant shaded, and the
 *   edge pixels land only on the rows where it widens. {@see motifArrow()}
 *   reproduces the sampled 11 x 11 arrow row for row.
 * - **A toggle is a tick in a sunken square**, and a radio button is a circle:
 *   Motif 2.1's indicators, not the filled squares and diamonds of Motif 1.2.
 * - **The frame is a five-pixel band** in the title colour, bevelled two pixels
 *   outside and one inside, with no corner handles drawn into it; the title
 *   bar inside it is a bevelled panel between bevelled 19 px buttons; and a
 *   one-pixel raised ring surrounds the client area.
 * - **Nothing moves when pressed.** An armed button swaps its shadows and its
 *   label stays put.
 */
final class Solaris9Chrome extends BaseChrome
{
    /** Rings of bevel on the *outside* of the frame band; the inside has one. */
    private const FRAME_OUTER = 2;

    /**
     * How far a default button sits inside its emphasis ring: the ring, two
     * rows of face, then the button's own highlight. From the Style Manager's
     * OK button.
     */
    private const DEFAULT_INSET = 3;

    /** Size of the caption's minimise glyph, a raised square. */
    private const MINIMIZE_DOT = 4;

    /** Inset of the caption's maximise glyph and the ends of its menu bar. */
    private const GLYPH_INSET = 4;

    /** Rows in the caption's window-menu bar. */
    private const MENU_BAR_ROWS = 4;

    /** Below this many rows a bevelled arrow is just noise: sort indicators stay flat. */
    private const MIN_BEVELLED_ARROW = 5;

    // -------------------------------------------------------------------------
    // Bevels
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function edge(Renderer $r, Rect $rect, Edge $edge): void
    {
        if ($rect->isEmpty()) return;

        $p = $this->palette;

        switch ($edge) {
            case Edge::None:
                return;

            case Edge::Raised:
            case Edge::RaisedThin:
                $this->ring($r, $rect, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::SunkenThin:
                $this->ring($r, $rect, $p->faceShadow, $p->faceHighlight);
                return;

            case Edge::Sunken:
                // A well. The outer ring is Motif's highlight thickness, drawn
                // in the parent's colour until the field has the focus; inside
                // it the content's own shadow and highlight.
                $this->outline($r, $rect, $p->face);
                $this->ring($r, $rect->inset(1), $p->contentShadow(), $p->contentHighlight());
                return;

            case Edge::Etched:
                // XmSHADOW_ETCHED_IN, as the Style Manager's group boxes have
                // it: shadow then highlight going in, both one pixel.
                $this->ring($r, $rect, $p->faceShadow, $p->faceHighlight);
                $this->ring($r, $rect->inset(1), $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::Outline:
                $this->outline($r, $rect, $p->frame);
                return;
        }
    }

    /** The content colour inside, shaded from itself. */
    public function well(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect->inset(1), Surface::Content);
        $this->edge($r, $rect, Edge::Sunken);
    }

    // -------------------------------------------------------------------------
    // Controls
    // -------------------------------------------------------------------------

    /**
     * One ring of shadow, swapped when armed. The dialog's default action
     * wears a sunken ring three pixels out, and the button sits inside it.
     */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Face);

        if ($state === ControlState::Default) {
            $this->ring($r, $rect, $this->palette->faceShadow, $this->palette->faceHighlight);
            $rect = $rect->inset(self::DEFAULT_INSET);
        }

        $this->bevel($r, $rect, $state->isPressed());
    }

    /** The Mailer's: always raised, sunk while held or when checked. */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->bevel($r, $rect, $checked || $state === ControlState::Pressed);
    }

    /**
     * Motif 2.1's XmToggleButton: a raised square when off; when on it sinks,
     * fills with the select colour and carries a tick. The select colour is
     * the face darkened the way a trough is, which is why it is the track
     * role here: Motif computes exactly one such colour per background.
     */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p = $this->palette;

        if (!$checked) {
            $this->fill($r, $rect, Surface::Face);
            $this->ring($r, $rect, $p->faceHighlight, $p->faceShadow);
            return;
        }

        $this->fill($r, $rect, Surface::Track);
        $this->ring($r, $rect, $p->faceShadow, $p->faceHighlight);
        $this->checkGlyph($r, $rect->inset(2), $p->text);
    }

    /**
     * XmONE_OF_MANY_ROUND: a circle with a one-pixel rim, lit along its
     * upper-left arc and shaded along the lower-right, the two arcs swapping
     * when it is selected — and then a rose disc inside, inset three pixels.
     */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p = $this->palette;
        $d = min($rect->width, $rect->height);
        if ($d < 1) return;

        $r->setForeground(...$p->face);
        foreach ($this->discSpans($d) as [$row, $x0, $x1]) {
            $r->fillRect($rect->x + $x0, $rect->y + $row, $x1 - $x0 + 1, 1);
        }

        $lit    = $checked ? $p->faceShadow    : $p->faceHighlight;
        $shaded = $checked ? $p->faceHighlight : $p->faceShadow;
        foreach ($this->rimRuns($d) as [$row, $x0, $x1, $isLit]) {
            $r->setForeground(...($isLit ? $lit : $shaded));
            $r->fillRect($rect->x + $x0, $rect->y + $row, $x1 - $x0 + 1, 1);
        }

        // Sampled at 15 px: ten pixels of rose starting three in, and ten rows
        // of it two in from the rim. Not centred to the pixel, and neither is
        // Motif's; and fatter than a circle drawn through pixel centres, which
        // is what XFillArc does to a small disc.
        $inner = $d - 5;
        if ($checked && $inner > 0) {
            $r->setForeground(...$p->accent);
            foreach ($this->discSpans($inner, fat: true) as [$row, $x0, $x1]) {
                $r->fillRect($rect->x + 3 + $x0, $rect->y + 3 + $row, $x1 - $x0 + 1, 1);
            }
        }
    }

    /** A raised tab with its top corners knocked off, one pixel deep. */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $this->fill($r, $rect, Surface::Face);
        if ($rect->width < 3 || $rect->height < 2) return;

        $p = $this->palette;

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x + 1, $rect->y, $rect->right() - 1, $rect->y],
            [$rect->x, $rect->y + 1, $rect->x, $rect->bottom()],
        ]);
        $r->setForeground(...$p->faceShadow);
        $r->drawLines([[$rect->right(), $rect->y + 1, $rect->right(), $rect->bottom()]]);

        // A tab behind the active one keeps a shadow under its top edge.
        if (!$active) {
            $r->drawLines([[$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1]]);
        }
    }

    /** Raised square with a +/- mark: there is no Motif tree, so the outline expander stands in. */
    public function treeToggle(Renderer $r, Rect $rect, bool $expanded, bool $onSelection): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->bevel($r, $rect, false);

        $r->setForeground(...$this->palette->text);
        $mid = intdiv($rect->width, 2);
        $r->drawLines([[$rect->x + 2, $rect->y + $mid, $rect->right() - 2, $rect->y + $mid]]);
        if (!$expanded) {
            $r->drawLines([[$rect->x + $mid, $rect->y + 2, $rect->x + $mid, $rect->bottom() - 2]]);
        }
    }

    // -------------------------------------------------------------------------
    // Scrolling and sliders
    // -------------------------------------------------------------------------

    /**
     * The trough and its sunken ring run the whole length of the bar, under
     * the arrows as well: Motif's arrows sit *in* the trough. The painter
     * hands over only the stretch between the two arrow squares, each of
     * which is one thickness long, so the bar is got back by growing it.
     */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        $cap = $this->metrics->scrollBarThickness;
        $bar = $horizontal
            ? $rect->insetEach(-$cap, 0, -$cap, 0)
            : $rect->insetEach(0, -$cap, 0, -$cap);

        $this->fill($r, $bar, Surface::Track);
        $this->ring($r, $bar, $this->palette->faceShadow, $this->palette->faceHighlight);
    }

    /**
     * A bevelled triangle in the square inside the trough's ring, which
     * {@see scrollTrack()} has already drawn; its shadows swap while held.
     * There is no button and nothing changes when there is nothing to scroll.
     */
    public function scrollArrow(Renderer $r, Rect $rect, Direction $direction, ControlState $state): void
    {
        $this->motifArrow($r, $rect->inset(1), $direction, $state->isPressed());
    }

    /** The slider: a raised block one pixel inside the trough's ring. */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $inner = $horizontal ? $rect->insetEach(0, 1, 0, 1) : $rect->insetEach(1, 0, 1, 0);

        $this->fill($r, $inner, Surface::Face);
        $this->bevel($r, $inner, false);
    }

    /** XmScale's trough: the select colour in a sunken ring. */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->fill($r, $rect, Surface::Track);
        $this->ring($r, $rect, $this->palette->faceShadow, $this->palette->faceHighlight);
    }

    /**
     * XmScale's slider: a raised block with a groove across the travel axis,
     * the shadow line one left of (or above) the centre and the highlight on
     * it, both running from the second row to the last.
     */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        if ($rect->isEmpty()) return;

        $p = $this->palette;
        $this->fill($r, $rect, Surface::Face);
        $this->bevel($r, $rect, false);

        if ($point === Direction::Up || $point === Direction::Down) {
            $cx = $rect->centerX();
            $r->setForeground(...$p->faceShadow);
            $r->drawLines([[$cx - 1, $rect->y + 1, $cx - 1, $rect->bottom() - 1]]);
            $r->setForeground(...$p->faceHighlight);
            $r->drawLines([[$cx, $rect->y + 1, $cx, $rect->bottom() - 1]]);
        } else {
            $cy = $rect->centerY();
            $r->setForeground(...$p->faceShadow);
            $r->drawLines([[$rect->x + 1, $cy - 1, $rect->right() - 1, $cy - 1]]);
            $r->setForeground(...$p->faceHighlight);
            $r->drawLines([[$rect->x + 1, $cy, $rect->right() - 1, $cy]]);
        }

        if ($focused) {
            $this->focusRect($r, $rect);
        }
    }

    // -------------------------------------------------------------------------
    // Menus and popups
    // -------------------------------------------------------------------------

    /**
     * Only a shadow along the bottom. In every main window sampled the bar's
     * top highlight is the same row as the client ring's, so a bar that drew
     * its own would double it; see {@see clientEdge()}.
     */
    public function menuBar(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::MenuBar);
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->palette->faceShadow);
        $r->fillRect($rect->x, $rect->bottom(), $rect->width, 1);
    }

    /**
     * A raised panel, one pixel. Extrapolated from the menu bar: no shipped
     * screenshot of this release has a menu open.
     */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Menu);
        $this->bevel($r, $rect, false);
    }

    /** An armed item rises out of the menu; its label stays black. */
    public function menuItemHighlight(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->bevel($r, $rect, false);
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
        $this->bevel($r, $rect, false);
    }

    /**
     * XmComboBox: its own raised ring, a margin, then the text well shaded
     * from its own colour, and the arrow with its bar in a strip at the right.
     * The arrow sinks while the list is open.
     */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect
    {
        $m = $this->metrics;
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);
        $this->bevel($r, $rect, false);

        $inner = $rect->inset(3);
        if ($inner->isEmpty()) return $inner;

        $buttonW = min($m->fieldButtonWidth, $inner->width);
        $well    = $inner->withWidth($inner->width - $buttonW);
        $content = $well->inset(1);

        $this->fill($r, $content, Surface::Content);
        $this->ring($r, $well, $p->contentShadow(), $p->contentHighlight());

        $button = Rect::of($well->x + $well->width, $inner->y, $buttonW, $inner->height);
        $n      = max(3, min(11, $button->width - 4, $button->height - 6));
        $stack  = $n + 3;   // the arrow, one clear row, a two-row bar
        $ax     = $button->x + intdiv($button->width - $n, 2);
        $ay     = $button->y + intdiv($button->height - $stack, 2);

        $this->motifArrow($r, Rect::of($ax, $ay, $n, $n), Direction::Down, $open);
        $this->bevel($r, Rect::of($ax, $ay + $n + 1, $n, 2), $open);

        return $content;
    }

    // -------------------------------------------------------------------------
    // Window furniture
    // -------------------------------------------------------------------------

    /** The title colour, flat: the panel and the buttons bevel themselves. */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /** The title panel: a raised ring around the band between the buttons. */
    public function captionGrab(Renderer $r, Rect $band, bool $active): void
    {
        [$ts, $bs] = $this->captionBevel($active);
        $this->ring($r, $band, $ts, $bs);
    }

    /**
     * dtwm's controls: raised 19 px squares in the title colour, carrying a
     * raised bar (the window menu), a 4 px raised square (minimise) or an
     * 11 px one (maximise). Every mark is the caption colour with the
     * caption's own shadows; nothing shifts when pressed.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        [$ts, $bs] = $this->captionBevel($active);
        if ($state->isPressed()) {
            [$ts, $bs] = [$bs, $ts];
        }
        $this->ring($r, $rect, $ts, $bs);

        switch ($button) {
            case CaptionButton::Menu:
                $this->ring($r, Rect::of(
                    $rect->x + self::GLYPH_INSET,
                    $rect->y + intdiv($rect->height - self::MENU_BAR_ROWS, 2),
                    $rect->width - 2 * self::GLYPH_INSET,
                    self::MENU_BAR_ROWS,
                ), $ts, $bs);
                return;

            case CaptionButton::Minimize:
                $this->ring($r, Rect::of(
                    $rect->x + intdiv($rect->width - self::MINIMIZE_DOT, 2),
                    $rect->y + intdiv($rect->height - self::MINIMIZE_DOT, 2),
                    self::MINIMIZE_DOT,
                    self::MINIMIZE_DOT,
                ), $ts, $bs);
                return;

            case CaptionButton::Maximize:
            case CaptionButton::Restore:
                $this->ring($r, $rect->inset(self::GLYPH_INSET), $ts, $bs);
                return;

            case CaptionButton::Close:
                // dtwm has none; drawn only if an application asks for one.
                $this->captionGlyph(
                    $r,
                    $rect->inset(5),
                    CaptionButton::Close,
                    $active ? $this->palette->captionActiveText : $this->palette->captionInactiveText,
                );
                return;
        }
    }

    /**
     * The frame band in the title colour, rose only while the window is
     * active. No corner handles: the sampled frame is one unbroken band.
     */
    public function windowBorder(Renderer $r, Rect $rect, bool $active = true): void
    {
        $this->frameBand($r, $rect, $this->metrics->windowBorder, $active);
    }

    /** One raised pixel around the client area, inside the caption and the frame. */
    public function clientEdge(Renderer $r, Rect $content): void
    {
        if ($content->isEmpty()) return;

        $this->ring($r, $content->grow(1), $this->palette->faceHighlight, $this->palette->faceShadow);
    }

    /**
     * A dialog gets the window's frame, then XmBulletinBoard's own shadow: a
     * one-pixel raised ring around everything below the caption.
     */
    public function dialogFrame(Renderer $r, Rect $rect): void
    {
        $m = $this->metrics;
        $t = $m->dialogBorder();

        $this->fill($r, $rect, Surface::Face);
        $this->frameBand($r, $rect, $t, true);

        $this->ring($r, Rect::of(
            $rect->x + $t,
            $rect->y + $t + $m->dialogTitleHeight,
            $rect->width - 2 * $t,
            $rect->height - 2 * $t - $m->dialogTitleHeight,
        ), $this->palette->faceHighlight, $this->palette->faceShadow);
    }

    /** A raised block in the corner. */
    public function grip(Renderer $r, Rect $rect): void
    {
        $box = $rect->rightSlice(min(14, $rect->width))->bottomSlice(min(14, $rect->height));
        $this->fill($r, $box, Surface::Face);
        $this->bevel($r, $box, false);
    }

    /** The keyboard highlight: one ring of rose. */
    public function focusRect(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;
        $this->outline($r, $rect, $this->palette->focus);
    }

    /**
     * Bevelled, like every Motif arrow; the colour a caller asks for is
     * ignored, because the triangle is the face with its own shadows. Small
     * ones stay flat: a bevel needs rows to land on.
     */
    public function arrow(Renderer $r, Rect $rect, Direction $direction, array $color, ?int $size = null): void
    {
        $n = $size ?? $this->metrics->arrowSize;
        if ($n < self::MIN_BEVELLED_ARROW) {
            parent::arrow($r, $rect, $direction, $color, $size);
            return;
        }

        // Odd, so the tip is a single pixel on the centre line.
        $n   = $n % 2 === 0 ? $n + 1 : $n;
        $box = Rect::of($rect->centerX() - intdiv($n, 2), $rect->centerY() - intdiv($n, 2), $n, $n);
        $this->motifArrow($r, $box, $direction, false);
    }

    /** No engraving in Motif; an insensitive label is simply dimmed. */
    protected function drawDisabledText(Renderer $r, string $text, int $x, int $baselineY): void
    {
        $r->setForeground(...$this->palette->textDisabled);
        $r->drawGlyphs($text, $x, $baselineY);
    }

    // -------------------------------------------------------------------------
    // Motif helpers
    // -------------------------------------------------------------------------

    /**
     * One ring of shadow with Motif's corner ownership: $topLeft takes the
     * whole top row and the whole left column, $bottomRight the bottom row
     * and right column from their second pixel. Sampled off a scrollbar
     * slider, where the top-right and bottom-left corners are both highlight.
     *
     * @param array{int,int,int} $topLeft
     * @param array{int,int,int} $bottomRight
     */
    private function ring(Renderer $r, Rect $rect, array $topLeft, array $bottomRight): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$topLeft);
        $r->fillRect($rect->x, $rect->y, $rect->width, 1);
        $r->fillRect($rect->x, $rect->y, 1, $rect->height);

        if ($rect->width < 2 && $rect->height < 2) return;

        $r->setForeground(...$bottomRight);
        if ($rect->width > 1) {
            $r->fillRect($rect->x + 1, $rect->bottom(), $rect->width - 1, 1);
        }
        if ($rect->height > 1) {
            $r->fillRect($rect->right(), $rect->y + 1, 1, $rect->height - 1);
        }
    }

    /** A face-coloured ring, raised or sunken. */
    private function bevel(Renderer $r, Rect $rect, bool $sunken): void
    {
        $p = $this->palette;
        if ($sunken) {
            $this->ring($r, $rect, $p->faceShadow, $p->faceHighlight);
        } else {
            $this->ring($r, $rect, $p->faceHighlight, $p->faceShadow);
        }
    }

    /**
     * The caption's bevel colours. Active, they are the ones Motif derived
     * from the rose; inactive, the caption *is* the face and so are they.
     *
     * @return array{array{int,int,int}, array{int,int,int}} [highlight, shadow]
     */
    private function captionBevel(bool $active): array
    {
        $p = $this->palette;

        return $active
            ? [$p->captionHighlight(), $p->captionShadow()]
            : [$p->faceHighlight, $p->faceShadow];
    }

    /**
     * dtwm's frame: a band $t deep in the title colour, two rings of bevel
     * on its outside and one, the other way round, on its inside. Sampled
     * top to bottom as highlight, highlight, colour, colour, shadow.
     */
    private function frameBand(Renderer $r, Rect $rect, int $t, bool $active): void
    {
        if ($t <= 0 || $rect->isEmpty()) return;

        [$ts, $bs] = $this->captionBevel($active);

        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, $t);
        $r->fillRect($rect->x, $rect->bottom() - $t + 1, $rect->width, $t);
        $r->fillRect($rect->x, $rect->y, $t, $rect->height);
        $r->fillRect($rect->right() - $t + 1, $rect->y, $t, $rect->height);

        for ($i = 0; $i < min(self::FRAME_OUTER, $t); $i++) {
            $this->ring($r, $rect->inset($i), $ts, $bs);
        }
        if ($t > self::FRAME_OUTER) {
            $this->ring($r, $rect->inset($t - 1), $bs, $ts);
        }
    }

    /**
     * XmeDrawArrow, as sampled at 11 x 11: the triangle fills an n x n box,
     * widening by one pixel a side every second row, with the face inside.
     * The slant on the left (or top) is highlight, the one on the right (or
     * bottom) shadow, and the edge pixels fall only on the rows where the
     * triangle widens. The base is a highlight line when it is on top or
     * left, a shadow line with a lit first pixel when it is below or right;
     * the tip is lit when it points up or left. $pressed swaps the lot.
     */
    private function motifArrow(Renderer $r, Rect $box, Direction $direction, bool $pressed): void
    {
        $n = min($box->width, $box->height);
        if ($n < 1) return;

        $p    = $this->palette;
        $ts   = $pressed ? $p->faceShadow    : $p->faceHighlight;
        $bs   = $pressed ? $p->faceHighlight : $p->faceShadow;
        $face = $p->face;

        $cx = $box->x + intdiv($n, 2);
        $cy = $box->y + intdiv($n, 2);

        // u runs from the tip towards the base, v across it.
        $plot = static function (int $u, int $v0, int $v1, array $colour) use ($r, $box, $direction, $n, $cx, $cy): void {
            $r->setForeground(...$colour);
            switch ($direction) {
                case Direction::Up:
                    $r->fillRect($cx + $v0, $box->y + $u, $v1 - $v0 + 1, 1);
                    return;
                case Direction::Down:
                    $r->fillRect($cx + $v0, $box->y + $n - 1 - $u, $v1 - $v0 + 1, 1);
                    return;
                case Direction::Left:
                    $r->fillRect($box->x + $u, $cy + $v0, 1, $v1 - $v0 + 1);
                    return;
                case Direction::Right:
                    $r->fillRect($box->x + $n - 1 - $u, $cy + $v0, 1, $v1 - $v0 + 1);
                    return;
            }
        };

        $tipLit = $direction === Direction::Up || $direction === Direction::Left;
        $maxHw  = intdiv($n - 1, 2);

        for ($i = 0; $i < $n; $i++) {
            $hw = min(intdiv($i + 1, 2), $maxHw);

            if ($i === 0) {
                $plot(0, 0, 0, $tipLit ? $ts : $bs);
                continue;
            }

            if ($i === $n - 1) {
                if ($tipLit) {
                    $plot($i, -$hw, -$hw, $ts);
                    if ($hw > 0) $plot($i, -$hw + 1, $hw, $bs);
                } else {
                    $plot($i, -$hw, $hw, $ts);
                }
                continue;
            }

            $plot($i, -$hw, $hw, $face);
            if ($i % 2 === 1) {
                $plot($i, -$hw, -$hw, $ts);
                $plot($i, $hw, $hw, $bs);
            }
        }
    }

    /**
     * Horizontal spans of a filled disc of diameter $d, relative to its box:
     * [row, first x, last x]. A pixel is in when its centre is inside the
     * circle, which is the midpoint circle X draws; $fat takes a pixel that
     * is within half a pixel of the edge as well, which is the filled disc
     * X draws.
     *
     * @return list<array{int, int, int}>
     */
    private function discSpans(int $d, bool $fat = false): array
    {
        $spans  = [];
        $centre = ($d - 1) / 2;
        $radius = $d / 2;

        for ($i = 0; $i < $d; $i++) {
            $dy = $i - $centre;
            $hw = sqrt(max(0.0, $radius * $radius - $dy * $dy));
            $x0 = (int) ($fat ? round($centre - $hw) : ceil($centre - $hw));
            $x1 = (int) ($fat ? round($centre + $hw) : floor($centre + $hw));
            if ($x1 < $x0) continue;
            $spans[] = [$i, $x0, $x1];
        }

        return $spans;
    }

    /**
     * The one-pixel rim of a disc of diameter $d — the disc minus the disc
     * two smaller inside it — as runs of [row, first x, last x, lit], where a
     * pixel is lit on the upper-left arc, split along the anti-diagonal.
     *
     * @return list<array{int, int, int, bool}>
     */
    private function rimRuns(int $d): array
    {
        $outer = [];
        foreach ($this->discSpans($d) as [$row, $x0, $x1]) {
            for ($x = $x0; $x <= $x1; $x++) $outer[$row][$x] = true;
        }
        $inner = [];
        if ($d > 2) {
            foreach ($this->discSpans($d - 2) as [$row, $x0, $x1]) {
                for ($x = $x0; $x <= $x1; $x++) $inner[$row + 1][$x + 1] = true;
            }
        }

        $centre = ($d - 1) / 2;
        $runs   = [];
        for ($row = 0; $row < $d; $row++) {
            $start = null;
            $lit   = false;
            for ($x = 0; $x <= $d; $x++) {
                $isRim = $x < $d && isset($outer[$row][$x]) && !isset($inner[$row][$x]);
                $side  = $isRim ? $this->onLitArc($x - $centre, $row - $centre) : false;

                if ($start !== null && (!$isRim || $side !== $lit)) {
                    $runs[] = [$row, $start, $x - 1, $lit];
                    $start  = null;
                }
                if ($isRim && $start === null) {
                    $start = $x;
                    $lit   = $side;
                }
            }
        }

        return $runs;
    }

    /** Whether a rim pixel at this offset from the centre is on the lit arc. */
    private function onLitArc(float $dx, float $dy): bool
    {
        $s = $dx + $dy;

        return $s < 0 || ($s == 0 && $dy < 0);
    }
}
