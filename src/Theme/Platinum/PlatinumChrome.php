<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Platinum;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Drawing\TextClip;
use Cyrnetix\X11\Theme\BaseChrome;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\MessageIcon;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;

/**
 * Mac OS 9 "Apple platinum" chrome, transcribed from screenshots of the real
 * thing rather than from memory.
 *
 * The grammar is completely unlike the Windows bevel. Every control is outlined
 * in **black** with its corners clipped, filled **flat** #DDDDDD, and shaded
 * inside that outline: a white hairline two pixels in along the top and left, a
 * #AAAAAA/#777777 pair along the bottom and right. Nothing is gradient-filled
 * except the three caption boxes (a 45° ramp) and the progress bar (a blue ramp
 * with a bright centre line).
 *
 * Other things measured off the originals and reproduced here:
 *  - the title bar is #CCCCCC with a band of white/#777777 pinstripes either
 *    side of the title, and its boxes vanish when the window goes inactive;
 *  - selections are pale lavender #CCCCFF with the text left **black**, while
 *    menus reverse to white on #333399;
 *  - the menu bar is white, not grey;
 *  - tab panels are #EEEEEE and their tabs are trapezoids with sloped sides;
 *  - both scrollbar arrows sit at the far end of the bar;
 *  - a pop-up menu is a push button with a stacked-arrow box, not a sunken
 *    field.
 */
final class PlatinumChrome extends BaseChrome
{
    /** Corner pixels dropped to fake the 1px radius Platinum draws. */
    private const CORNER = 1;

    // The Platinum grey ramp, straight off a Mac OS 9 screenshot. Recorded whole:
    // an entry with no current caller is a sampled measurement rather than dead
    // code, and re-deriving one means re-sampling the screenshot.
    private const GRAY_LIGHT   = [0xEE, 0xEE, 0xEE];
    private const GRAY_CAPTION = [0xCC, 0xCC, 0xCC];
    private const GRAY_TROUGH  = [0xBB, 0xBB, 0xBB];
    private const GRAY_MID     = [0xAA, 0xAA, 0xAA];
    private const GRAY_WELL    = [0x88, 0x88, 0x88];
    private const GRAY_LINE    = [0x99, 0x99, 0x99];
    private const GRAY_DARK    = [0x77, 0x77, 0x77];
    private const INK          = [0x22, 0x22, 0x22];

    /** Diagonal ramp inside the caption boxes, top-left → bottom-right. */
    private const BOX_RAMP = [
        [0x99, 0x99, 0x99], [0xAA, 0xAA, 0xAA], [0xBB, 0xBB, 0xBB],
        [0xCC, 0xCC, 0xCC], [0xDD, 0xDD, 0xDD], [0xEE, 0xEE, 0xEE],
    ];

    /**
     * The progress bar's vertical ramp: dark blue at the edges, a bright line
     * through the middle. Measured row by row off the installer's bar.
     */
    private const PROGRESS_RAMP = [
        [0x33, 0x33, 0x99], [0x66, 0x66, 0xCC], [0x99, 0x99, 0xFF],
        [0xCC, 0xCC, 0xFF], [0xEE, 0xEE, 0xEE], [0xCC, 0xCC, 0xFF],
        [0x99, 0x99, 0xFF], [0x66, 0x66, 0xCC], [0x33, 0x33, 0x99],
        [0x00, 0x00, 0x55],
    ];

    // -------------------------------------------------------------------------
    // Edges
    // -------------------------------------------------------------------------

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
                $this->outline($r, $rect, $p->frame);
                $this->bevelRing($r, $inner, $p->faceHighlight, self::GRAY_MID);
                return;

            case Edge::Sunken:
                // Content wells: black frame, grey shadow inside the top-left,
                // white glint along the bottom-right.
                $this->outline($r, $rect, $p->frame);
                $this->bevelRing($r, $inner, self::GRAY_WELL, $p->faceHighlight);
                return;

            case Edge::RaisedThin:
                $this->bevelRing($r, $rect, $p->faceHighlight, self::GRAY_MID);
                return;

            case Edge::SunkenThin:
                $this->bevelRing($r, $rect, self::GRAY_WELL, $p->faceHighlight);
                return;

            case Edge::Etched:
                // Platinum's group divider: a grey hairline with a white one
                // underneath it.
                $this->outline($r, $rect, self::GRAY_WELL);
                $this->outline($r, $inner, $p->faceHighlight);
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
     * Push button: black rounded frame, flat face, white hairline two pixels in
     * along the top-left, and a #777777/#AAAAAA pair along the bottom-right.
     * The default action wears an extra ring outside all that.
     */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        if ($state === ControlState::Default) {
            $this->roundedFrame($r, $rect, $this->palette->frame);
            $r->setForeground(...self::GRAY_MID);
            $this->roundedFrameOn($r, $rect->inset(1));
            $rect = $rect->inset(3);
        }

        $this->buttonBody($r, $rect, $state);
    }

    /** The six-tone rounded body a Platinum push button is made of, without its label. */
    private function buttonBody(Renderer $r, Rect $rect, ControlState $state): void
    {
        if ($rect->isEmpty()) return;

        $p       = $this->palette;
        $pressed = $state->isPressed();

        $r->setForeground(...($pressed ? self::GRAY_MID : $p->face));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        $this->roundedFrame($r, $rect, $p->frame);

        $one = $rect->inset(1);
        $two = $rect->inset(2);
        if ($two->isEmpty()) return;

        // Pressed controls swap which side catches the light.
        $glint  = $pressed ? self::GRAY_DARK : $p->faceHighlight;
        $shade1 = $pressed ? $p->faceHighlight : self::GRAY_DARK;
        $shade2 = $pressed ? self::GRAY_LIGHT : self::GRAY_MID;

        $r->setForeground(...$shade1);
        $r->drawLines([
            [$one->x + 1, $one->bottom(), $one->right() - 1, $one->bottom()],
            [$one->right(), $one->y + 1, $one->right(), $one->bottom() - 1],
        ]);

        $r->setForeground(...$glint);
        $r->drawLines([
            [$two->x, $two->y, $two->right(), $two->y],
            [$two->x, $two->y, $two->x, $two->bottom()],
        ]);

        $r->setForeground(...$shade2);
        $r->drawLines([
            [$two->x + 1, $two->bottom(), $two->right(), $two->bottom()],
            [$two->right(), $two->y + 1, $two->right(), $two->bottom()],
        ]);
    }

    /** Toolbar buttons are ordinary buttons once hovered, flat until then. */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        if ($checked || $state === ControlState::Pressed) {
            $this->buttonBody($r, $rect, ControlState::Pressed);
            return;
        }
        if ($state === ControlState::Hovered) {
            $this->buttonBody($r, $rect, ControlState::Normal);
        }
    }

    /** Finder column header: flat #CCCCCC, black rule under it, white glint. */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $p = $this->palette;

        $r->setForeground(...($state->isPressed() ? self::GRAY_MID : self::GRAY_CAPTION));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([[$rect->x, $rect->y, $rect->right(), $rect->y]]);

        $r->setForeground(...$p->frame);
        $r->drawLines([
            [$rect->x, $rect->bottom(), $rect->right(), $rect->bottom()],
            [$rect->right(), $rect->y, $rect->right(), $rect->bottom()],
        ]);
    }

    // -------------------------------------------------------------------------
    // Fields
    // -------------------------------------------------------------------------

    /**
     * A closed pop-up menu is a *button* with a small stacked-arrow box at its
     * right end; an editable field keeps the white well. Both are what Mac OS 9
     * actually drew.
     */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect
    {
        $m   = $this->metrics;
        $box = Rect::of(
            $rect->x + $rect->width - $m->fieldButtonWidth - 2,
            $rect->y + 3,
            $m->fieldButtonWidth,
            $rect->height - 6,
        );

        if ($editable) {
            $this->well($r, $rect);
            $content = $rect->inset(2)->insetEach(0, 0, $box->width + 2, 0);
        } else {
            $this->buttonBody($r, $rect, $open ? ControlState::Pressed : ControlState::Normal);
            $content = $rect->insetEach(4, 2, $box->width + 4, 2);
        }

        // The arrow box: its own small bevelled button carrying ▲▼.
        $this->buttonBody($r, $box, $open ? ControlState::Pressed : ControlState::Normal);

        $glyph = $box->inset(3);
        if (!$glyph->isEmpty()) {
            $half = intdiv($glyph->height, 2);
            $this->arrow(
                $r,
                Rect::of($glyph->x, $glyph->y, $glyph->width, $half),
                Direction::Up,
                $this->palette->text,
                3,
            );
            $this->arrow(
                $r,
                Rect::of($glyph->x, $glyph->bottom() - $half + 1, $glyph->width, $half),
                Direction::Down,
                $this->palette->text,
                3,
            );
        }

        return $content;
    }

    /** Text on a closed pop-up sits on the button's face, not in a white well. */
    public function dropFieldTextStyle(bool $editable): TextStyle
    {
        return $editable ? TextStyle::Content : TextStyle::Normal;
    }

    /**
     * Mac OS 9's checkbox is a *raised* box, not a sunken white well: face
     * coloured inside a black frame, with a white hairline along the top and
     * left and a grey one along the bottom.
     */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p = $this->palette;

        $r->setForeground(...($state->isPressed() ? self::GRAY_MID : $p->face));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        $this->outline($r, $rect, $p->frame);

        $inner = $rect->inset(1);
        if (!$inner->isEmpty()) {
            $r->setForeground(...$p->faceHighlight);
            $r->drawLines([
                [$inner->x, $inner->y, $inner->right(), $inner->y],
                [$inner->x, $inner->y, $inner->x, $inner->bottom()],
            ]);
            $r->setForeground(...self::GRAY_WELL);
            $r->drawLines([
                [$inner->x + 1, $inner->bottom(), $inner->right(), $inner->bottom()],
                [$inner->right(), $inner->y + 1, $inner->right(), $inner->bottom()],
            ]);
        }

        if (!$checked) return;

        $this->checkGlyph(
            $r,
            $rect->inset(2),
            $state === ControlState::Disabled ? $p->textDisabled : $p->text,
        );
    }

    /**
     * And its radio button is a little pearl: a black rim over the same 45°
     * ramp, filled almost solid black when selected.
     */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p      = $this->palette;
        $size   = min($rect->width, $rect->height);
        $radius = intdiv($size, 2);
        $cx     = $rect->x + $radius;
        $cy     = $rect->y + $radius;

        if ($state->isPressed()) {
            $r->setForeground(...self::GRAY_MID);
            $r->fillCircle($cx, $cy, $radius - 1);
        } else {
            $this->diagonalRampDisc($r, $cx, $cy, $radius - 1);
        }

        $r->setForeground(...$p->frame);
        $r->drawArc($rect->x, $rect->y, $size - 1, $size - 1, 0, 360);

        if ($checked) {
            $r->setForeground(...($state === ControlState::Disabled ? $p->textDisabled : $p->text));
            $r->fillCircle($cx, $cy, max(1, $radius - 2));
        }
    }

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------

    /**
     * Trapezoid with sloped sides and clipped top corners. The active tab is
     * filled in the panel's own #EEEEEE and leaves its bottom edge open so it
     * runs into the page; inactive tabs are #CCCCCC and stay closed.
     */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $p     = $this->palette;
        $slant = $this->metrics->tabSlant;
        $rows  = max(1, $rect->height - 1);

        $fill = $active
            ? $this->palette->forSurface(Surface::Panel)
            : self::GRAY_CAPTION;

        // Fill row by row, narrowing toward the top by the slope.
        for ($i = 0; $i < $rect->height; $i++) {
            $inset = (int) round($slant * (1 - $i / $rows));
            $y     = $rect->y + $i;
            $x0    = $rect->x + $inset;
            $w     = $rect->width - 2 * $inset;
            if ($w <= 0) continue;

            $r->setForeground(...$fill);
            $r->fillRect($x0, $y, $w, 1);

            // Black outline down both slopes.
            $r->setForeground(...$p->frame);
            $r->drawPixels([[$x0, $y], [$x0 + $w - 1, $y]]);
        }

        // Top edge, corners clipped.
        $topInset = $slant;
        $r->setForeground(...$p->frame);
        $r->drawLines([[
            $rect->x + $topInset + self::CORNER, $rect->y,
            $rect->right() - $topInset - self::CORNER, $rect->y,
        ]]);

        // White glint under the top edge.
        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([[
            $rect->x + $topInset + self::CORNER, $rect->y + 1,
            $rect->right() - $topInset - self::CORNER, $rect->y + 1,
        ]]);
    }

    /** {@inheritDoc} */
    public function tabPanel(Renderer $r, Rect $rect): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Panel);

        $this->outline($r, $rect, $p->frame);

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([[$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1]]);
    }

    // -------------------------------------------------------------------------
    // Scrolling and sliders
    // -------------------------------------------------------------------------

    /**
     * An idle bar is pale (#EEEEEE); one with something to scroll darkens to
     * #AAAAAA so the periwinkle thumb reads against it. Both straight off Mac
     * OS 9 — an empty SimpleText window versus a scrolled list.
     */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        $r->setForeground(...($scrollable ? self::GRAY_MID : self::GRAY_LIGHT));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $this->outline($r, $rect, $this->palette->frame);
    }

    /**
     * The scroll box is the one control Mac OS 9 tints: #9999FF with a #CCCCFF
     * glint on top, #6666CC underneath, and four dark grip lines across its
     * middle. (The Appearance panel called this "Lavender … for menus and
     * controls".)
     */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        if ($rect->isEmpty()) return;

        $p    = $this->palette;
        $body = $rect->inset(1);

        $r->setForeground(...self::PROGRESS_RAMP[2]);          // #9999FF
        $r->fillRect($body->x, $body->y, $body->width, $body->height);

        // Glint along the leading edge, shade along the trailing one.
        $r->setForeground(...$p->selection);                   // #CCCCFF
        $r->drawLines($horizontal
            ? [[$body->x, $body->y, $body->right(), $body->y]]
            : [[$body->x, $body->y, $body->x, $body->bottom()]]);
        $r->setForeground(...self::PROGRESS_RAMP[1]);          // #6666CC
        $r->drawLines($horizontal
            ? [[$body->x, $body->bottom(), $body->right(), $body->bottom()]]
            : [[$body->right(), $body->y, $body->right(), $body->bottom()]]);

        $this->outline($r, $rect, $p->frame);
        $this->thumbGrip($r, $rect, $horizontal);
    }

    /** Thin channel: black frame, grey interior, white hairline beneath it. */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $r->setForeground(...self::GRAY_WELL);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $this->outline($r, $rect, $this->palette->frame);

        $r->setForeground(...$this->palette->faceHighlight);
        if ($horizontal) {
            $r->drawLines([[$rect->x, $rect->bottom() + 1, $rect->right(), $rect->bottom() + 1]]);
        } else {
            $r->drawLines([[$rect->right() + 1, $rect->y, $rect->right() + 1, $rect->bottom()]]);
        }
    }

    /**
     * The slider thumb really is pointed in Mac OS 9 — a lavender shield aimed
     * at the tick marks, with three grip lines across it.
     */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $p    = $this->palette;
        $tip  = $this->metrics->trackbarThumbPoint;
        $vert = $point === Direction::Down || $point === Direction::Up;

        $body = $vert
            ? Rect::of($rect->x, $rect->y, $rect->width, $rect->height - $tip)
            : Rect::of($rect->x, $rect->y, $rect->width - $tip, $rect->height);

        // Shield body.
        $r->setForeground(...$p->selection);
        $r->fillRect($body->x, $body->y, $body->width, $body->height);

        // Point, narrowing one step per row/column.
        for ($i = 0; $i < $tip; $i++) {
            if ($vert) {
                $w = $body->width - 2 * $i;
                if ($w <= 0) break;
                $r->setForeground(...$p->selection);
                $r->fillRect($body->x + $i, $body->bottom() + 1 + $i, $w, 1);
            } else {
                $h = $body->height - 2 * $i;
                if ($h <= 0) break;
                $r->setForeground(...$p->selection);
                $r->fillRect($body->right() + 1 + $i, $body->y + $i, 1, $h);
            }
        }

        // Black outline + a light glint along the top.
        $r->setForeground(...$p->frame);
        if ($vert) {
            $r->drawLines([
                [$body->x, $body->y, $body->right(), $body->y],
                [$body->x, $body->y, $body->x, $body->bottom()],
                [$body->right(), $body->y, $body->right(), $body->bottom()],
                [$body->x, $body->bottom(), $body->x + $tip, $body->bottom() + $tip],
                [$body->right(), $body->bottom(), $body->right() - $tip, $body->bottom() + $tip],
            ]);
        } else {
            $r->drawLines([
                [$body->x, $body->y, $body->right(), $body->y],
                [$body->x, $body->y, $body->x, $body->bottom()],
                [$body->x, $body->bottom(), $body->right(), $body->bottom()],
                [$body->right(), $body->y, $body->right() + $tip, $body->y + $tip],
                [$body->right(), $body->bottom(), $body->right() + $tip, $body->bottom() - $tip],
            ]);
        }

        $r->setForeground(...self::GRAY_LIGHT);
        $r->drawLines([[$body->x + 1, $body->y + 1, $body->right() - 1, $body->y + 1]]);

        // Three grip lines across the travel axis.
        $grip = $body->inset(3);
        if (!$grip->isEmpty()) {
            $r->setForeground(...self::PROGRESS_RAMP[1]);
            if ($vert) {
                $cx = $grip->centerX();
                foreach ([-2, 0, 2] as $d) {
                    $r->drawLines([[$cx + $d, $grip->y, $cx + $d, $grip->bottom()]]);
                }
            } else {
                $cy = $grip->centerY();
                foreach ([-2, 0, 2] as $d) {
                    $r->drawLines([[$grip->x, $cy + $d, $grip->right(), $cy + $d]]);
                }
            }
        }

        if ($focused) {
            $this->focusRect($r, $rect->inset(-2));
        }
    }

    /** {@inheritDoc} */
    public function tick(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;
        $r->setForeground(...self::GRAY_DARK);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /** Blue ramp with a bright line through the middle, plus a dark end cap. */
    public function progressFill(Renderer $r, Rect $rect, bool $horizontal): void
    {
        if ($rect->isEmpty()) return;

        $ramp  = self::PROGRESS_RAMP;
        $steps = count($ramp);

        if ($horizontal) {
            for ($i = 0; $i < $rect->height; $i++) {
                $stop = min($steps - 1, intdiv($i * $steps, max(1, $rect->height)));
                $r->setForeground(...$ramp[$stop]);
                $r->fillRect($rect->x, $rect->y + $i, $rect->width, 1);
            }
            // End cap, so the bar reads as a solid object rather than a wash.
            $r->setForeground(...$ramp[0]);
            $r->drawLines([[$rect->right(), $rect->y, $rect->right(), $rect->bottom()]]);
            return;
        }

        for ($i = 0; $i < $rect->width; $i++) {
            $stop = min($steps - 1, intdiv($i * $steps, max(1, $rect->width)));
            $r->setForeground(...$ramp[$stop]);
            $r->fillRect($rect->x + $i, $rect->y, 1, $rect->height);
        }
        $r->setForeground(...$ramp[0]);
        $r->drawLines([[$rect->x, $rect->y, $rect->right(), $rect->y]]);
    }

    // -------------------------------------------------------------------------
    // Menus
    // -------------------------------------------------------------------------

    /** The classic Mac menu bar is white with a hairline under it. */
    public function menuBar(Renderer $r, Rect $rect): void
    {
        $r->setForeground(...$this->palette->forSurface(Surface::MenuBar));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        $r->setForeground(...$this->palette->frame);
        $r->drawLines([[$rect->x, $rect->bottom(), $rect->right(), $rect->bottom()]]);
    }

    /** Menus are #DDDDDD sheets with a black frame and a hard drop shadow. */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $p = $this->palette;

        // Shadow first, offset down-right, then the sheet over it.
        $r->setForeground(...self::GRAY_WELL);
        $r->fillRect($rect->x + 3, $rect->y + 3, $rect->width, $rect->height);

        $this->fill($r, $rect, Surface::Menu);
        $this->outline($r, $rect, $p->frame);

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1],
            [$rect->x + 1, $rect->y + 1, $rect->x + 1, $rect->bottom() - 1],
        ]);
    }

    /** {@inheritDoc} */
    public function popupFrame(Renderer $r, Rect $rect): void
    {
        $r->setForeground(...self::GRAY_WELL);
        $r->fillRect($rect->x + 3, $rect->y + 3, $rect->width, $rect->height);

        $this->fill($r, $rect, Surface::Content);
        $this->outline($r, $rect, $this->palette->frame);
    }

    /** {@inheritDoc} */
    public function separator(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $r->setForeground(...self::GRAY_WELL);
        if ($horizontal) {
            $r->drawLines([[$rect->x, $rect->y, $rect->right(), $rect->y]]);
            $r->setForeground(...$this->palette->faceHighlight);
            $r->drawLines([[$rect->x, $rect->y + 1, $rect->right(), $rect->y + 1]]);
            return;
        }
        $r->drawLines([[$rect->x, $rect->y, $rect->x, $rect->bottom()]]);
        $r->setForeground(...$this->palette->faceHighlight);
        $r->drawLines([[$rect->x + 1, $rect->y, $rect->x + 1, $rect->bottom()]]);
    }

    // -------------------------------------------------------------------------
    // Window frame
    // -------------------------------------------------------------------------

    /**
     * #CCCCCC bar with a band of white/#777777 pinstripes either side of the
     * title, and a #999999 rule along the bottom. An inactive window loses the
     * stripes entirely.
     */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...self::GRAY_CAPTION);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);

        // The stripes are a separate pass — see captionGrab() — so they can keep
        // clear of the boxes. No rule along the bottom either: clientEdge()
        // draws the #999999 line that separates furniture from content.
    }

    /**
     * The grab texture: alternating white and #777777 rows across the top twelve
     * rows of the bar, which is where Mac OS 9 puts them relative to the title's
     * baseline.
     */
    public function captionGrab(Renderer $r, Rect $band, bool $active): void
    {
        if (!$active || $band->isEmpty()) return;

        $rows = min(12, max(0, $band->height - 4));
        $this->pinstripe($r, $band->topSlice($rows));
    }

    /**
     * Bold, centred, with a plain patch cleared out of the stripes behind it —
     * measured in bold so the patch is the right width.
     */
    public function captionTitle(Renderer $r, Rect $rect, string $title, bool $active): void
    {
        if ($title === '' || $rect->isEmpty()) return;

        $r->withBold(function () use ($r, $rect, $title, $active): void {
            $clipped = TextClip::toWidth($title, max(0, $rect->width - 12), $r);
            if ($clipped === '') return;

            $width = $r->measureText($clipped);
            $x     = $rect->x + intdiv($rect->width - $width, 2);

            $r->setForeground(...self::GRAY_CAPTION);
            $r->fillRect($x - 5, $rect->y, $width + 10, $rect->height);

            $this->text(
                $r,
                $clipped,
                $x,
                $r->baselineYForRect($rect->y, $rect->height),
                $active ? TextStyle::Caption : TextStyle::CaptionInactive,
            );
        });
    }

    /** {@inheritDoc} */
    protected function captionBackground(bool $active): array
    {
        return self::GRAY_CAPTION;
    }


    /**
     * Close, zoom and collapse boxes: a #222222 frame set into the bar with a
     * grey/white bevel around it, filled with a 45° grey ramp. They disappear
     * on an inactive window, exactly as they did.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        if (!$active) return;

        $size = min($rect->width, $rect->height);
        $box  = $rect->centeredSquare($size);

        // Bevel outside the frame: grey above-left, white below-right.
        $r->setForeground(...self::GRAY_WELL);
        $r->drawLines([
            [$box->x, $box->y, $box->right(), $box->y],
            [$box->x, $box->y, $box->x, $box->bottom()],
        ]);
        $r->setForeground(...$this->palette->faceHighlight);
        $r->drawLines([
            [$box->x, $box->bottom(), $box->right(), $box->bottom()],
            [$box->right(), $box->y, $box->right(), $box->bottom()],
        ]);

        $frame = $box->inset(1);
        if ($frame->isEmpty()) return;

        $this->outline($r, $frame, self::INK);

        $face = $frame->inset(1);
        if ($face->isEmpty()) return;

        // A pressed box inverts to the ink colour, which is how they flash.
        if ($state->isPressed()) {
            $r->setForeground(...self::INK);
            $r->fillRect($face->x, $face->y, $face->width, $face->height);
            return;
        }

        $this->diagonalRamp($r, $face);
        $this->captionBoxGlyph($r, $face, $button);
    }

    /**
     * The window's edge, reproducing Mac OS 9's ramp from the outside in:
     * black, white, the caption grey, a #999999 rule, then black again around
     * the content.
     */
    public function windowBorder(Renderer $r, Rect $rect): void
    {
        $t = $this->metrics->windowBorder;
        if ($t <= 0) return;

        // Outer ramp only — the inner #999999/black pair belongs to the client
        // area and is drawn by clientEdge().
        $tones = [
            $this->palette->frame,
            $this->palette->faceHighlight,
            self::GRAY_CAPTION,
            self::GRAY_CAPTION,
        ];

        for ($i = 0; $i < $t; $i++) {
            $ring = $rect->inset($i);
            if ($ring->isEmpty()) break;
            $this->outline($r, $ring, $tones[min($i, count($tones) - 1)]);
        }
    }

    /** #999999 rule with a black frame inside it, hugging the client area. */
    public function clientEdge(Renderer $r, Rect $content): void
    {
        if ($content->isEmpty()) return;

        $this->outline($r, $content->inset(-2), self::GRAY_LINE);
        $this->outline($r, $content->inset(-1), $this->palette->frame);
    }

    /** Disclosure triangle, not a boxed [+]/[−]. */
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

    /** {@inheritDoc} */
    public function focusRect(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;
        $this->outline($r, $rect, $this->palette->accent);
    }

    /** Mac OS greys disabled text out; it never engraves it like Windows. */
    protected function drawDisabledText(Renderer $r, string $text, int $x, int $baselineY): void
    {
        $r->setForeground(...$this->palette->textDisabled);
        $r->drawGlyphs($text, $x, $baselineY);
    }

    /** {@inheritDoc} */
    public function messageIcon(Renderer $r, Rect $rect, MessageIcon $icon): void
    {
        $p = $this->palette;

        [$bg, $label] = match ($icon) {
            MessageIcon::Error       => [$p->iconError,    '!'],
            MessageIcon::Warning     => [$p->iconWarning,  '!'],
            MessageIcon::Question    => [$p->iconQuestion, '?'],
            MessageIcon::Information => [$p->iconInfo,     'i'],
        };

        $r->setForeground(...$bg);
        $r->fillRect($rect->x + 1, $rect->y + 1, $rect->width - 2, $rect->height - 2);
        $this->roundedFrame($r, $rect, $p->frame);

        $r->setForeground(...$p->faceHighlight);
        $r->drawGlyphs(
            $label,
            $rect->centerX() - intdiv($r->measureText($label), 2),
            $rect->centerY() + intdiv($r->fontAscent(), 2),
        );
    }

    // -------------------------------------------------------------------------
    // Platinum-specific helpers
    // -------------------------------------------------------------------------

    /** Rectangle outline with the four corner pixels left out. */
    private function roundedFrame(Renderer $r, Rect $rect, array $color): void
    {
        $r->setForeground(...$color);
        $this->roundedFrameOn($r, $rect);
    }

    /** As {@see roundedFrame()} but leaves the current colour alone. */
    private function roundedFrameOn(Renderer $r, Rect $rect): void
    {
        if ($rect->width <= 2 * self::CORNER || $rect->height <= 2 * self::CORNER) {
            $r->drawRect($rect->x, $rect->y, max(0, $rect->width - 1), max(0, $rect->height - 1));
            return;
        }

        $c = self::CORNER;
        $r->drawLines([
            [$rect->x + $c,  $rect->y,         $rect->right() - $c, $rect->y],
            [$rect->x + $c,  $rect->bottom(),  $rect->right() - $c, $rect->bottom()],
            [$rect->x,       $rect->y + $c,    $rect->x,            $rect->bottom() - $c],
            [$rect->right(), $rect->y + $c,    $rect->right(),      $rect->bottom() - $c],
        ]);
    }

    /** Alternating white / #777777 rows — the title bar's grab texture. */
    private function pinstripe(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;

        for ($y = $rect->y; $y <= $rect->bottom(); $y += 2) {
            $r->setForeground(...$this->palette->faceHighlight);
            $r->fillRect($rect->x, $y, $rect->width, 1);

            if ($y + 1 <= $rect->bottom()) {
                $r->setForeground(...self::GRAY_DARK);
                $r->fillRect($rect->x, $y + 1, $rect->width, 1);
            }
        }
    }

    /** 45° grey ramp, batching one request per tone. */
    private function diagonalRamp(Renderer $r, Rect $rect): void
    {
        $steps = count(self::BOX_RAMP);
        $span  = max(1, $rect->width + $rect->height - 2);
        $buckets = array_fill(0, $steps, []);

        for ($y = 0; $y < $rect->height; $y++) {
            for ($x = 0; $x < $rect->width; $x++) {
                $stop = min($steps - 1, intdiv(($x + $y) * $steps, $span));
                $buckets[$stop][] = [$rect->x + $x, $rect->y + $y];
            }
        }

        foreach ($buckets as $stop => $points) {
            if ($points === []) continue;
            $r->setForeground(...self::BOX_RAMP[$stop]);
            $r->drawPixels($points);
        }
    }

    /** As {@see diagonalRamp()} but clipped to a disc, for the radio pearls. */
    private function diagonalRampDisc(Renderer $r, int $cx, int $cy, int $radius): void
    {
        if ($radius <= 0) return;

        $steps   = count(self::BOX_RAMP);
        $span    = max(1, 4 * $radius);
        $buckets = array_fill(0, $steps, []);

        for ($dy = -$radius; $dy <= $radius; $dy++) {
            $half = (int) floor(sqrt(max(0, $radius * $radius - $dy * $dy)));
            for ($dx = -$half; $dx <= $half; $dx++) {
                $stop = min($steps - 1, intdiv(($dx + $dy + 2 * $radius) * $steps, $span));
                $buckets[$stop][] = [$cx + $dx, $cy + $dy];
            }
        }

        foreach ($buckets as $stop => $points) {
            if ($points === []) continue;
            $r->setForeground(...self::BOX_RAMP[$stop]);
            $r->drawPixels($points);
        }
    }

    /** The marks inside the caption boxes. */
    private function captionBoxGlyph(Renderer $r, Rect $face, CaptionButton $button): void
    {
        $r->setForeground(...self::INK);

        switch ($button) {
            case CaptionButton::Maximize:
            case CaptionButton::Restore:
                // A smaller pane tucked into the top-left of a larger one.
                $step = max(2, intdiv($face->width, 2));
                $r->drawLines([
                    [$face->x, $face->y + $step, $face->x + $step, $face->y + $step],
                    [$face->x + $step, $face->y, $face->x + $step, $face->y + $step],
                ]);
                return;

            case CaptionButton::Minimize:
                // Window-shade bars across the full width.
                $mid = $face->centerY();
                $r->drawLines([
                    [$face->x, $mid - 1, $face->right(), $mid - 1],
                    [$face->x, $mid + 1, $face->right(), $mid + 1],
                ]);
                return;

            case CaptionButton::Close:
            case CaptionButton::Menu:
                // Both are plain in Mac OS.
                return;
        }
    }

    /**
     * Four dark grip lines with light ones between, centred on the thumb.
     * Skipped on a thumb too short to hold them.
     */
    private function thumbGrip(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $along = $horizontal ? $rect->width : $rect->height;
        if ($along < 22) return;

        $dark  = self::PROGRESS_RAMP[0];        // #333399
        $light = $this->palette->selection;     // #CCCCFF

        $centre = $horizontal ? $rect->centerX() : $rect->centerY();
        $span   = $horizontal ? $rect->inset(4)->height : $rect->inset(4)->width;
        if ($span <= 0) return;

        for ($i = -3; $i <= 3; $i++) {
            $at    = $centre + $i * 2;
            $color = $i % 2 === 0 ? $dark : $light;

            $r->setForeground(...$color);
            $r->drawLines($horizontal
                ? [[$at, $rect->y + 4, $at, $rect->bottom() - 4]]
                : [[$rect->x + 4, $at, $rect->right() - 4, $at]]);
        }
    }

    /**
     * Grow box: three diagonal ridges, each a white leading edge with a #777777
     * one two pixels behind it. Transcribed from the corner of a Mac OS 9
     * window; it deliberately paints no background so it sits on whatever strip
     * hosts it.
     */
    public function grip(Renderer $r, Rect $rect): void
    {
        $side = min($rect->width, $rect->height);
        if ($side < 8) return;

        $box = $rect->rightSlice($side)->bottomSlice($side);

        // Ridges run along the anti-diagonals, four pixels apart, clipped to a
        // band so the texture reads as three strokes rather than a full hatch.
        $base   = intdiv($side, 2) + 1;
        $firstY = 3;
        $lastY  = $side - 5;

        $whites = [];
        $darks  = [];

        for ($k = 0; $k < 3; $k++) {
            $c = $base + 4 * $k;
            for ($by = $firstY; $by <= $lastY; $by++) {
                foreach ([[$c, &$whites], [$c + 2, &$darks]] as [$diagonal, &$into]) {
                    $bx = $diagonal - $by;
                    if ($bx < 1 || $bx > $side - 2) continue;
                    $into[] = [$box->x + $bx, $box->y + $by];
                }
                unset($into);
            }
        }

        if ($whites !== []) {
            $r->setForeground(...$this->palette->faceHighlight);
            $r->drawPixels($whites);
        }
        if ($darks !== []) {
            $r->setForeground(...self::GRAY_DARK);
            $r->drawPixels($darks);
        }
    }
}
