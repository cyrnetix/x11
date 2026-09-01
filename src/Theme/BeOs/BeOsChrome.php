<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\BeOs;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\BaseChrome;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;

/**
 * BeOS R5 chrome, measured off the R5.0.1 screenshots.
 *
 * The era's grammar is a **single-pixel** bevel — white along the top-left,
 * #989898 along the bottom-right — over a flat #D8D8D8 panel. Nothing is two
 * pixels thick and nothing is gradient-filled, which makes it read much crisper
 * than either the Windows or Motif chrome.
 *
 * Its signatures, all reproduced here:
 *  - the **yellow tab**: a #FFCB00 caption only as wide as its own title, with a
 *    close box at the left and the zoom box at the right, floating above the
 *    frame with the desktop showing beside it;
 *  - buttons in a lighter #E8E8E8 with a #616161 outline, and a second outline
 *    around the default one;
 *  - sliders drawn as a wide sunken channel with a pale knob and a bright green
 *    triangle marking the value;
 *  - scroll knobs carrying the "dotted" grip from the Scroll Bar preferences.
 */
final class BeOsChrome extends BaseChrome
{
    // Straight off the screenshots. The ramp is recorded whole, so an entry with
    // no current caller is a sampled measurement rather than a leftover — it
    // cannot be recovered without going back to a screenshot, and the next
    // adjustment to this theme is likely to want it.
    private const PANEL        = [0xD8, 0xD8, 0xD8];
    private const CONTROL      = [0xE8, 0xE8, 0xE8];
    private const LIGHT        = [0xEF, 0xEF, 0xEF];
    private const MID          = [0x98, 0x98, 0x98];
    private const DARK         = [0x64, 0x64, 0x64];
    private const OUTLINE      = [0x61, 0x61, 0x61];
    private const TAB_YELLOW   = [0xFF, 0xCB, 0x00];
    private const TAB_TOP      = [0xFF, 0xFF, 0x50];
    private const TAB_SHADOW   = [0xB7, 0x82, 0x00];
    private const TAB_EDGE     = [0xAF, 0x7B, 0x00];
    private const TAB_RIM      = [0x60, 0x60, 0x60];
    private const BOX_BRIGHT   = [0xFF, 0xFF, 0x3F];

    /**
     * Diagonal ramp inside the tab's boxes, top-left → bottom-right. Sampled off
     * a StyledEdit window: they really are gradient-filled, not flat.
     */
    private const BOX_RAMP = [
        [0xFF, 0xFF, 0x3F], [0xFF, 0xEC, 0x21], [0xFF, 0xCB, 0x00], [0xEA, 0xB5, 0x00],
    ];
    private const KNOB_GREEN   = [0x00, 0xE0, 0x00];

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
                $this->bevelRing($r, $rect, $p->faceHighlight, self::MID);
                return;

            case Edge::Sunken:
            case Edge::SunkenThin:
                $this->bevelRing($r, $rect, self::MID, $p->faceHighlight);
                return;

            case Edge::Etched:
                // Group boxes: a grey hairline with a white one under it.
                $this->outline($r, $rect, [0x80, 0x80, 0x80]);
                $this->outline($r, $rect->inset(1), $p->faceHighlight);
                return;

            case Edge::Outline:
                $this->outline($r, $rect, $p->frame);
                return;
        }
    }

    // -------------------------------------------------------------------------
    // Buttons
    // -------------------------------------------------------------------------

    /**
     * #E8E8E8 face inside a #616161 outline with clipped corners; the dialog's
     * default action gets a second outline around it with a gap.
     */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        if ($state === ControlState::Default) {
            $this->cornerFrame($r, $rect, self::OUTLINE);
            $rect = $rect->inset(3);
        }

        if ($rect->isEmpty()) return;

        $pressed = $state->isPressed();

        $r->setForeground(...($pressed ? self::MID : self::CONTROL));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        $this->cornerFrame($r, $rect, self::OUTLINE);

        $inner = $rect->inset(1);
        if ($inner->isEmpty()) return;

        $this->bevelRing(
            $r,
            $inner,
            $pressed ? self::MID : $this->palette->faceHighlight,
            $pressed ? $this->palette->faceHighlight : self::MID,
        );
    }

    /** {@inheritDoc} */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        if ($checked || $state === ControlState::Pressed) {
            $this->button($r, $rect, ControlState::Pressed);
            return;
        }
        if ($state === ControlState::Hovered) {
            $this->button($r, $rect, ControlState::Normal);
        }
    }

    /** {@inheritDoc} */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, $state->isPressed() ? Edge::SunkenThin : Edge::RaisedThin);

        // Column dividers are hard lines in Tracker.
        $r->setForeground(...self::MID);
        $r->drawLines([[$rect->right(), $rect->y, $rect->right(), $rect->bottom()]]);
    }

    /** {@inheritDoc} */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p = $this->palette;

        $r->setForeground(...($state->isPressed() ? self::PANEL : $p->content));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $this->edge($r, $rect, Edge::Sunken);
        $this->outline($r, $rect, self::OUTLINE);

        if (!$checked) return;

        $this->checkGlyph(
            $r,
            $rect->inset(2),
            $state === ControlState::Disabled ? $p->textDisabled : $p->text,
        );
    }

    /** {@inheritDoc} */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p      = $this->palette;
        $size   = min($rect->width, $rect->height);
        $radius = intdiv($size, 2);
        $cx     = $rect->x + $radius;
        $cy     = $rect->y + $radius;

        $r->setForeground(...($state->isPressed() ? self::PANEL : $p->content));
        $r->fillCircle($cx, $cy, $radius - 1);

        // 1px bevel around the well, then the hard rim.
        $r->setForeground(...self::MID);
        $r->drawArc($rect->x, $rect->y, $size - 1, $size - 1, 45, 180);
        $r->setForeground(...$p->faceHighlight);
        $r->drawArc($rect->x, $rect->y, $size - 1, $size - 1, 225, 180);
        $r->setForeground(...self::OUTLINE);
        $r->drawArc($rect->x + 1, $rect->y + 1, $size - 3, $size - 3, 0, 360);

        if ($checked) {
            $r->setForeground(...($state === ControlState::Disabled ? $p->textDisabled : $p->text));
            $r->fillCircle($cx, $cy, max(1, $radius - 3));
        }
    }

    // -------------------------------------------------------------------------
    // Scrolling and sliders
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        $this->fill($r, $rect, Surface::Track);
        $this->edge($r, $rect, Edge::SunkenThin);
    }

    /** Knob with the "dotted" grip the Scroll Bar preferences offer. */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $r->setForeground(...self::CONTROL);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $this->edge($r, $rect, Edge::RaisedThin);
        $this->outline($r, $rect, self::MID);

        $grip = $rect->inset(3);
        if ($grip->isEmpty()) return;

        $along = $horizontal ? $grip->width : $grip->height;
        if ($along < 10) return;

        // Three dots along the knob's middle.
        $centre = $horizontal ? $grip->centerY() : $grip->centerX();
        $middle = $horizontal ? $grip->centerX() : $grip->centerY();

        foreach ([-4, 0, 4] as $offset) {
            $r->setForeground(...self::DARK);
            $horizontal
                ? $r->fillRect($middle + $offset, $centre, 2, 2)
                : $r->fillRect($centre, $middle + $offset, 2, 2);
        }
    }

    /** A wide sunken channel rather than the thin groove other eras use. */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->fill($r, $rect, Surface::Track);
        $this->edge($r, $rect, Edge::Sunken);
    }

    /**
     * Pale knob in the channel, plus the bright green triangle BeOS marks the
     * value with, pointing back at the track.
     */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $p    = $this->palette;
        $tip  = $this->metrics->trackbarThumbPoint;
        $vert = $point === Direction::Down || $point === Direction::Up;

        $knob = $vert
            ? Rect::of($rect->x, $rect->y, $rect->width, max(1, $rect->height - $tip))
            : Rect::of($rect->x, $rect->y, max(1, $rect->width - $tip), $rect->height);

        $r->setForeground(...$p->content);
        $r->fillRect($knob->x, $knob->y, $knob->width, $knob->height);
        $this->edge($r, $knob, Edge::RaisedThin);
        $this->outline($r, $knob, self::MID);

        // The marker sits just past the knob, aimed at the channel.
        $marker = $vert
            ? Rect::of($knob->x, $knob->bottom() + 1, $knob->width, $tip)
            : Rect::of($knob->right() + 1, $knob->y, $tip, $knob->height);

        if (!$marker->isEmpty()) {
            $this->arrow($r, $marker, $vert ? Direction::Up : Direction::Left, self::KNOB_GREEN, $tip);
        }

        if ($focused) {
            $this->focusRect($r, $rect);
        }
    }

    /** Solid fill in the desktop blue, inside a sunken trough. */
    public function progressFill(Renderer $r, Rect $rect, bool $horizontal): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->palette->progressBar);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        $r->setForeground(...$this->palette->faceHighlight);
        $horizontal
            ? $r->drawLines([[$rect->x, $rect->y, $rect->right(), $rect->y]])
            : $r->drawLines([[$rect->x, $rect->y, $rect->x, $rect->bottom()]]);
    }

    // -------------------------------------------------------------------------
    // Menus and tabs
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function menuBar(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::MenuBar);

        $r->setForeground(...self::MID);
        $r->drawLines([[$rect->x, $rect->bottom(), $rect->right(), $rect->bottom()]]);
    }

    /** Grey sheet with a hard outline and a white inner glint. */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Menu);
        $this->outline($r, $rect, $this->palette->frame);

        $r->setForeground(...$this->palette->faceHighlight);
        $r->drawLines([
            [$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1],
            [$rect->x + 1, $rect->y + 1, $rect->x + 1, $rect->bottom() - 1],
        ]);
    }

    /** {@inheritDoc} */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $r->setForeground(...($active ? self::CONTROL : self::PANEL));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        // Top and both sides only — the tab runs into the page below it.
        $r->setForeground(...$this->palette->faceHighlight);
        $r->drawLines([
            [$rect->x, $rect->y, $rect->right(), $rect->y],
            [$rect->x, $rect->y, $rect->x, $rect->bottom()],
        ]);
        $r->setForeground(...self::MID);
        $r->drawLines([[$rect->right(), $rect->y, $rect->right(), $rect->bottom()]]);
    }

    // -------------------------------------------------------------------------
    // Window frame — the yellow tab
    // -------------------------------------------------------------------------

    /** The desktop shows either side of the tab. */
    public function captionSurround(Renderer $r, Rect $band): void
    {
        if ($band->isEmpty()) return;

        $r->setForeground(...$this->palette->desktopColor());
        $r->fillRect($band->x, $band->y, $band->width, $band->height);
    }

    /**
     * The tab. Framed on three sides only — its bottom edge runs into the body
     * below, which is why the window's border stops at the tab.
     *
     * Tones straight off the reference: #989898 along the top and left with a
     * #FFFF50 highlight just inside, and #AF7B00 over #606060 down the right.
     */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...($active ? self::TAB_YELLOW : self::PANEL));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        // Outer: grey along the top and left, dark down the right.
        $r->setForeground(...self::MID);
        $r->drawLines([
            [$rect->x, $rect->y, $rect->right(), $rect->y],
            [$rect->x, $rect->y, $rect->x, $rect->bottom()],
        ]);
        $r->setForeground(...($active ? self::TAB_RIM : self::MID));
        $r->drawLines([[$rect->right(), $rect->y, $rect->right(), $rect->bottom()]]);

        // Inner: bright highlight under the top and along the left, shadow at
        // the right.
        $r->setForeground(...($active ? self::TAB_TOP : $this->palette->faceHighlight));
        $r->drawLines([
            [$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1],
            [$rect->x + 1, $rect->y + 1, $rect->x + 1, $rect->bottom()],
        ]);
        $r->setForeground(...($active ? self::TAB_EDGE : self::MID));
        $r->drawLines([[$rect->right() - 1, $rect->y + 1, $rect->right() - 1, $rect->bottom()]]);
    }

    /** {@inheritDoc} */
    protected function captionBackground(bool $active): array
    {
        return $active ? self::TAB_YELLOW : self::PANEL;
    }


    /**
     * The tab's boxes: a dark gold outline with a bright inner highlight, filled
     * with a 45° gold ramp and given a bright offset shadow at the bottom-right.
     *
     * The zoom box is *two* of those squares — a small one at the top-left and a
     * larger one overlapping it at the bottom-right — which is how BeOS drew it.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        $size = min($rect->width, $rect->height);
        if ($size < 5) return;

        $box = $rect->centeredSquare($size);

        if ($button === CaptionButton::Maximize || $button === CaptionButton::Restore) {
            // Small square up-left, larger one down-right, drawn in that order so
            // the larger one overlaps.
            $small = Rect::of($box->x, $box->y, intdiv($size * 3, 5), intdiv($size * 3, 5));
            $large = Rect::of(
                $box->x + $size - intdiv($size * 2, 3),
                $box->y + $size - intdiv($size * 2, 3),
                intdiv($size * 2, 3),
                intdiv($size * 2, 3),
            );

            $this->goldBox($r, $small, $state, $active);
            $this->goldBox($r, $large, $state, $active);
            return;
        }

        $this->goldBox($r, $box, $state, $active);
    }

    /**
     * One gold box: outline, inner highlight, diagonal ramp, offset shadow.
     * A pressed box inverts — the ramp runs the other way.
     */
    private function goldBox(Renderer $r, Rect $box, ControlState $state, bool $active): void
    {
        if ($box->width < 4 || $box->height < 4) return;

        $pressed = $state->isPressed();

        if (!$active) {
            // An inactive tab shows its boxes flat, in the panel grey.
            $this->fill($r, $box, Surface::Face);
            $this->outline($r, $box, self::MID);
            return;
        }

        // Interior ramp first, then the frame over it.
        $this->diagonalRamp($r, $box->inset(1), $pressed);

        $this->outline($r, $box, self::TAB_SHADOW);

        $inner = $box->inset(1);
        if (!$inner->isEmpty()) {
            $r->setForeground(...($pressed ? self::TAB_EDGE : self::BOX_BRIGHT));
            $r->drawLines([
                [$inner->x, $inner->y, $inner->right(), $inner->y],
                [$inner->x, $inner->y, $inner->x, $inner->bottom()],
            ]);
        }

        // Bright shadow offset one pixel down and right of the outline.
        $r->setForeground(...self::BOX_BRIGHT);
        $r->drawLines([
            [$box->x + 1, $box->bottom() + 1, $box->right() + 1, $box->bottom() + 1],
            [$box->right() + 1, $box->y + 1, $box->right() + 1, $box->bottom() + 1],
        ]);
    }

    /** 45° gold ramp, batching one request per tone. */
    private function diagonalRamp(Renderer $r, Rect $rect, bool $reversed): void
    {
        if ($rect->isEmpty()) return;

        $ramp    = self::BOX_RAMP;
        $steps   = count($ramp);
        $span    = max(1, $rect->width + $rect->height - 2);
        $buckets = array_fill(0, $steps, []);

        for ($y = 0; $y < $rect->height; $y++) {
            for ($x = 0; $x < $rect->width; $x++) {
                $stop = min($steps - 1, intdiv(($x + $y) * $steps, $span));
                if ($reversed) $stop = $steps - 1 - $stop;
                $buckets[$stop][] = [$rect->x + $x, $rect->y + $y];
            }
        }

        foreach ($buckets as $stop => $points) {
            if ($points === []) continue;
            $r->setForeground(...$ramp[$stop]);
            $r->drawPixels($points);
        }
    }

    /** #9A9A9A, white, panel, then a grey line against the client area. */
    public function windowBorder(Renderer $r, Rect $rect): void
    {
        $t = $this->metrics->windowBorder;
        if ($t <= 0) return;

        $tones = [
            self::MID,
            $this->palette->faceHighlight,
            self::PANEL,
            [0x8A, 0x8A, 0x8A],
        ];

        for ($i = 0; $i < $t; $i++) {
            $ring = $rect->inset($i);
            if ($ring->isEmpty()) break;
            $this->outline($r, $ring, $tones[min($i, count($tones) - 1)]);
        }
    }

    /** Corner grip: a small sunken square, like Tracker's resize handle. */
    public function grip(Renderer $r, Rect $rect): void
    {
        $side = min(12, min($rect->width, $rect->height));
        if ($side < 6) return;

        $box = $rect->rightSlice($side)->bottomSlice($side);

        $this->fill($r, $box, Surface::Face);
        $this->edge($r, $box, Edge::SunkenThin);

        $r->setForeground(...self::DARK);
        $r->drawLines([
            [$box->x + 2, $box->bottom() - 2, $box->right() - 2, $box->y + 2],
            [$box->x + 5, $box->bottom() - 2, $box->right() - 2, $box->y + 5],
        ]);
    }

    /** Disclosure triangles, exactly as BeOS used for "More options". */
    public function treeToggle(Renderer $r, Rect $rect, bool $expanded, bool $onSelection): void
    {
        $this->arrow(
            $r,
            $rect,
            $expanded ? Direction::Down : Direction::Right,
            $this->palette->text,
            $this->metrics->smallArrowSize + 1,
        );
    }

    /** Nothing engraved: disabled text is simply lightened. */
    protected function drawDisabledText(Renderer $r, string $text, int $x, int $baselineY): void
    {
        $r->setForeground(...$this->palette->textDisabled);
        $r->drawGlyphs($text, $x, $baselineY);
    }

    /** Rounded-off outline: BeOS clips a single pixel from each corner. */
    private function cornerFrame(Renderer $r, Rect $rect, array $color): void
    {
        if ($rect->width < 4 || $rect->height < 4) {
            $this->outline($r, $rect, $color);
            return;
        }

        $r->setForeground(...$color);
        $r->drawLines([
            [$rect->x + 1,   $rect->y,        $rect->right() - 1, $rect->y],
            [$rect->x + 1,   $rect->bottom(), $rect->right() - 1, $rect->bottom()],
            [$rect->x,       $rect->y + 1,    $rect->x,           $rect->bottom() - 1],
            [$rect->right(), $rect->y + 1,    $rect->right(),     $rect->bottom() - 1],
        ]);
    }
}
