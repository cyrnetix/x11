<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Drawing\TextClip;

/**
 * Shared scaffolding for {@see Chrome} implementations: the parts that are
 * geometry rather than style (triangles, dotted focus rings, progress
 * chunking, grip dot patterns) plus straightforward defaults expressed in
 * terms of the few genuinely style-bearing primitives.
 *
 * A theme subclasses this and must supply the pieces that define its era —
 * {@see edge()}, {@see button()}, {@see checkBox()}, {@see radioButton()},
 * {@see tab()}, {@see scrollThumb()}, {@see sliderThumb()} and
 * {@see caption()} — then overrides anything else it wants to restyle.
 */
abstract class BaseChrome implements Chrome
{
    /** Built from the palette and metrics this era uses. */
    public function __construct(
        protected readonly Palette $palette,
        protected readonly Metrics $metrics,
    ) {}

    /** The palette. */
    public function palette(): Palette { return $this->palette; }
    /** The metrics. */
    public function metrics(): Metrics { return $this->metrics; }

    // -------------------------------------------------------------------------
    // Surfaces
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function fill(Renderer $r, Rect $rect, Surface $surface): void
    {
        if ($rect->isEmpty()) return;
        $r->setForeground(...$this->palette->forSurface($surface));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /** {@inheritDoc} */
    public function panel(Renderer $r, Rect $rect, Surface $surface, Edge $edge): void
    {
        $this->fill($r, $rect, $surface);
        $this->edge($r, $rect, $edge);
    }

    /** {@inheritDoc} */
    public function well(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Content);
        $this->edge($r, $rect, Edge::Sunken);
    }

    // -------------------------------------------------------------------------
    // Controls with era-neutral defaults
    // -------------------------------------------------------------------------

    /**
     * False, because no era this toolkit ships draws a hovered *button*. A theme
     * whose button() varies with ControlState::Hovered must override this, or
     * the hover it draws will not be repainted.
     */
    public function rendersButtonHover(): bool { return false; }

    /** {@inheritDoc} */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        $sunken = $checked || $state === ControlState::Pressed;
        if ($sunken) {
            $this->edge($r, $rect, Edge::SunkenThin);
            return;
        }
        if ($state === ControlState::Hovered) {
            $this->edge($r, $rect, Edge::RaisedThin);
        }
        // Resting toolbar buttons are flat against the strip.
    }

    /**
     * Sunken field with a bevelled arrow button in its right edge, which is how
     * Windows and Motif both draw it.
     */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect
    {
        $m = $this->metrics;

        $this->edge($r, $rect, Edge::Sunken);

        $button = Rect::of(
            $rect->x + $rect->width - $m->fieldButtonWidth - $m->fieldBorder,
            $rect->y + $m->fieldBorder,
            $m->fieldButtonWidth,
            $rect->height - 2 * $m->fieldBorder,
        );
        $this->button($r, $button, $open ? ControlState::Pressed : ControlState::Normal);

        $shift = $open ? $m->pressOffset : 0;
        $this->arrow($r, $button->shift($shift, $shift), Direction::Down, $this->palette->text);

        $content = Rect::of(
            $rect->x + $m->fieldBorder,
            $rect->y + $m->fieldBorder,
            $button->x - $rect->x - $m->fieldBorder,
            $rect->height - 2 * $m->fieldBorder,
        );
        $this->fill($r, $content, Surface::Content);

        return $content;
    }

    /** {@inheritDoc} */
    public function dropFieldTextStyle(bool $editable): TextStyle
    {
        return TextStyle::Content;
    }

    /** {@inheritDoc} */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, $state === ControlState::Pressed ? Edge::SunkenThin : Edge::RaisedThin);
    }

    /**
     * A dialog's frame. Four of the five eras bevel it exactly like a raised
     * panel, which is what this is; Windows 3.1 doesn't, and overrides.
     */
    public function dialogFrame(Renderer $r, Rect $rect): void
    {
        $this->panel($r, $rect, Surface::Face, Edge::Raised);
    }

    /** {@inheritDoc} */
    public function tabPanel(Renderer $r, Rect $rect): void
    {
        $this->panel($r, $rect, Surface::Panel, Edge::Raised);
    }

    /**
     * Boxed [+] / [−] expander — the shape Windows and Motif share. Themes
     * that use a disclosure triangle (Platinum) override this.
     */
    public function treeToggle(Renderer $r, Rect $rect, bool $expanded, bool $onSelection): void
    {
        $r->setForeground(...$this->palette->content);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        $r->setForeground(...$this->palette->faceShadow);
        $r->drawRect($rect->x, $rect->y, $rect->width, $rect->height);

        // On a dark selection the plain text colour disappears; step one
        // shade back so the glyph stays legible either way.
        $r->setForeground(...($onSelection ? $this->palette->faceDarkShadow : $this->palette->text));

        $mid = intdiv($rect->width, 2);
        $r->drawLines([[$rect->x + 2, $rect->y + $mid, $rect->right() - 2, $rect->y + $mid]]);
        if (!$expanded) {
            $r->drawLines([[$rect->x + $mid, $rect->y + 2, $rect->x + $mid, $rect->bottom() - 2]]);
        }
    }

    // -------------------------------------------------------------------------
    // Scrolling and sliders
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        $this->fill($r, $rect, Surface::Track);
    }

    /** {@inheritDoc} */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->edge($r, $rect, Edge::SunkenThin);
    }

    /** {@inheritDoc} */
    public function tick(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;
        $r->setForeground(...$this->palette->faceDarkShadow);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /**
     * Segmented (Win9x) or continuous fill, per
     * {@see Metrics::$progressChunked}. The caller has already clipped $rect
     * to the filled portion, so marquee bands come through here too.
     */
    public function progressFill(Renderer $r, Rect $rect, bool $horizontal): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->palette->progressBar);

        if (!$this->metrics->progressChunked) {
            $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
            return;
        }

        $seg  = $this->metrics->progressSegmentWidth;
        $step = $this->metrics->progressSegmentStep();

        if ($horizontal) {
            for ($x = $rect->x; $x < $rect->x + $rect->width; $x += $step) {
                $w = min($seg, $rect->x + $rect->width - $x);
                $r->fillRect($x, $rect->y, $w, $rect->height);
            }
        } else {
            // Vertical bars grow upward, so segments stack from the bottom.
            for ($y = $rect->y + $rect->height; $y > $rect->y; $y -= $step) {
                $h = min($seg, $y - $rect->y);
                $r->fillRect($rect->x, $y - $h, $rect->width, $h);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Menus, popups, bars
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Menu);
        $this->edge($r, $rect, Edge::Outline);
    }

    /** {@inheritDoc} */
    public function menuItemHighlight(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;
        $r->setForeground(...$this->palette->menuHighlight);
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /** The menu highlight text style. */
    public function menuHighlightTextStyle(): TextStyle
    {
        return TextStyle::MenuHighlighted;
    }

    /**
     * The always-visible menu strip. Windows sets it off with a shadow rule,
     * Platinum with a hairline, Motif raises the whole bar.
     */
    public function menuBar(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::MenuBar);

        $r->setForeground(...$this->palette->faceShadow);
        $r->drawLines([[$rect->x, $rect->bottom(), $rect->right(), $rect->bottom()]]);
    }

    /** {@inheritDoc} */
    public function popupFrame(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Content);
        $this->edge($r, $rect, Edge::Outline);
    }

    /** Two-tone etched line: shadow then highlight, one pixel apart. */
    public function separator(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $r->setForeground(...$this->palette->faceShadow);
        if ($horizontal) {
            $r->drawLines([[$rect->x, $rect->y, $rect->right(), $rect->y]]);
            $r->setForeground(...$this->palette->faceHighlight);
            $r->drawLines([[$rect->x, $rect->y + 1, $rect->right(), $rect->y + 1]]);
        } else {
            $r->drawLines([[$rect->x, $rect->y, $rect->x, $rect->bottom()]]);
            $r->setForeground(...$this->palette->faceHighlight);
            $r->drawLines([[$rect->x + 1, $rect->y, $rect->x + 1, $rect->bottom()]]);
        }
    }

    /** Diagonal cluster of bevelled dots. */
    public function grip(Renderer $r, Rect $rect): void
    {
        $br = $rect->right()  - 3;
        $bb = $rect->bottom() - 3;

        $dots = [
            [$br,     $bb    ],
            [$br - 4, $bb    ], [$br,     $bb - 4],
            [$br - 8, $bb    ], [$br - 4, $bb - 4], [$br, $bb - 8],
        ];

        foreach ($dots as [$dx, $dy]) {
            $r->setForeground(...$this->palette->faceShadow);
            $r->fillRect($dx + 1, $dy + 1, 2, 2);
            $r->setForeground(...$this->palette->faceHighlight);
            $r->fillRect($dx, $dy, 2, 2);
        }
    }

    /** Two bevelled vertical bars, the classic drag affordance. */
    public function bandGrip(Renderer $r, Rect $rect): void
    {
        $top    = $rect->y + 2;
        $bottom = $rect->bottom() - 1;

        foreach ([2, 5] as $offsetX) {
            $x = $rect->x + $offsetX;
            $r->setForeground(...$this->palette->faceShadow);
            $r->drawLines([[$x, $top, $x, $bottom]]);
            $r->setForeground(...$this->palette->faceHighlight);
            $r->drawLines([[$x + 1, $top, $x + 1, $bottom]]);
        }
    }

    // -------------------------------------------------------------------------
    // Window frame
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->captionBackground($active));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /** Most eras leave the caption plain; Mac OS 9 stripes it. */
    public function captionGrab(Renderer $r, Rect $band, bool $active): void {}

    /**
     * Only reached by themes whose caption doesn't span the window. The default
     * fills with the face colour; BeOS shows the desktop through instead.
     */
    public function captionSurround(Renderer $r, Rect $band): void
    {
        $this->fill($r, $band, Surface::Face);
    }

    /** Window titles are bold in all three eras, so measuring happens in bold. */
    public function captionTitle(Renderer $r, Rect $rect, string $title, bool $active): void
    {
        if ($title === '' || $rect->isEmpty()) return;

        $r->withBold(function () use ($r, $rect, $title, $active): void {
            $clipped = TextClip::toWidth($title, $rect->width, $r);
            $x = $this->metrics->captionTitleCentred
                ? $rect->x + intdiv($rect->width - $r->measureText($clipped), 2)
                : $rect->x;

            $this->text(
                $r,
                $clipped,
                $x,
                $r->baselineYForRect($rect->y, $rect->height),
                $active ? TextStyle::Caption : TextStyle::CaptionInactive,
            );
        });
    }

    /**
     * A caption control is a small button carrying a glyph. Themes restyle the
     * button; the glyphs themselves are geometry and stay here.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        $this->button($r, $rect, $state);

        $shift = $state->isPressed() ? $this->metrics->pressOffset : 0;
        $this->captionGlyph($r, $rect->inset(3)->shift($shift, $shift), $button, $this->palette->text);
    }

    /**
     * Border ring around the window. Filled edge by edge so the client area in
     * the middle is never painted over.
     */
    public function windowBorder(Renderer $r, Rect $rect): void
    {
        $t = $this->metrics->windowBorder;

        if ($t <= 0) return;

        if ($t === 1) {
            $this->outline($r, $rect, $this->palette->frame);
            return;
        }

        $this->fill($r, $rect->topSlice($t), Surface::Face);
        $this->fill($r, $rect->bottomSlice($t), Surface::Face);
        $this->fill($r, Rect::of($rect->x, $rect->y + $t, $t, $rect->height - 2 * $t), Surface::Face);
        $this->fill($r, Rect::of($rect->right() - $t + 1, $rect->y + $t, $t, $rect->height - 2 * $t), Surface::Face);

        $this->edge($r, $rect, Edge::Raised);
    }

    /** Most eras run their client area flush to the frame. */
    public function clientEdge(Renderer $r, Rect $content): void {}

    /** @return array{int, int, int} */
    protected function captionBackground(bool $active): array
    {
        return $active ? $this->palette->captionActive : $this->palette->captionInactive;
    }

    /**
     * The mark inside a caption control, sized to $box.
     *
     * @param array{int,int,int} $color
     */
    protected function captionGlyph(Renderer $r, Rect $box, CaptionButton $button, array $color): void
    {
        if ($box->isEmpty()) return;

        $r->setForeground(...$color);

        switch ($button) {
            case CaptionButton::Minimize:
                // Bar along the bottom.
                $r->fillRect($box->x, $box->bottom() - 1, $box->width, 2);
                return;

            case CaptionButton::Maximize:
                // Box with a heavier top edge — the classic "title bar" hint.
                $r->drawRect($box->x, $box->y, $box->width - 1, $box->height - 1);
                $r->fillRect($box->x, $box->y, $box->width, 2);
                return;

            case CaptionButton::Restore:
                // Two overlapping boxes.
                $back  = Rect::of($box->x + 2, $box->y, $box->width - 2, $box->height - 2);
                $front = Rect::of($box->x, $box->y + 2, $box->width - 2, $box->height - 2);
                $r->drawRect($back->x, $back->y, $back->width - 1, $back->height - 1);
                $this->fill($r, $front, Surface::Face);
                $r->setForeground(...$color);
                $r->drawRect($front->x, $front->y, $front->width - 1, $front->height - 1);
                $r->fillRect($front->x, $front->y, $front->width, 2);
                return;

            case CaptionButton::Close:
                // Two-pixel-thick cross.
                $segments = [];
                for ($t = 0; $t < 2; $t++) {
                    $segments[] = [$box->x + $t, $box->y, $box->right() - 1 + $t, $box->bottom()];
                    $segments[] = [$box->right() - $t, $box->y, $box->x + 1 - $t, $box->bottom()];
                }
                $r->drawLines($segments);
                return;

            case CaptionButton::Menu:
                // Dash, the way a window-menu button reads.
                $r->fillRect($box->x, $box->centerY(), $box->width, 2);
                return;
        }
    }

    /** {@inheritDoc} */
    public function tooltip(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Tooltip);
        $r->setForeground(...$this->palette->text);
        $r->drawRect($rect->x, $rect->y, $rect->width - 1, $rect->height - 1);
    }

    // -------------------------------------------------------------------------
    // Glyphs
    // -------------------------------------------------------------------------

    /**
     * Solid triangle built from 1-pixel slices — the same trick the original
     * painters used, hoisted here so every arrow in the toolkit matches.
     */
    public function arrow(Renderer $r, Rect $rect, Direction $direction, array $color, ?int $size = null): void
    {
        $n  = $size ?? $this->metrics->arrowSize;
        $cx = $rect->centerX();
        $cy = $rect->centerY();

        $r->setForeground(...$color);

        // Slices run across the arrow's base-to-tip axis; the half-extent
        // grows toward the base and shrinks toward the tip.
        $half = intdiv($n, 2);

        switch ($direction) {
            case Direction::Up:
                for ($i = 0; $i < $n; $i++) {
                    $r->fillRect($cx - $i, $cy - $half + $i, 1 + 2 * $i, 1);
                }
                break;
            case Direction::Down:
                for ($i = 0; $i < $n; $i++) {
                    $hw = $n - 1 - $i;
                    $r->fillRect($cx - $hw, $cy - $half + $i, 1 + 2 * $hw, 1);
                }
                break;
            case Direction::Left:
                for ($i = 0; $i < $n; $i++) {
                    $r->fillRect($cx - $half + $i, $cy - $i, 1, 1 + 2 * $i);
                }
                break;
            case Direction::Right:
                for ($i = 0; $i < $n; $i++) {
                    $hh = $n - 1 - $i;
                    $r->fillRect($cx - $half + $i, $cy - $hh, 1, 1 + 2 * $hh);
                }
                break;
        }
    }

    /** Three-stroke tick, scaled to the box it's given. */
    public function checkGlyph(Renderer $r, Rect $rect, array $color): void
    {
        $r->setForeground(...$color);

        // Anchor the stroke inside the box: down-leg then up-leg.
        $x    = $rect->x;
        $y    = $rect->y;
        $w    = $rect->width;
        $h    = $rect->height;
        $kneeX = $x + intdiv($w, 3);
        $kneeY = $y + $h - 3;
        $tailX = $x + $w - 1;
        $tailY = $y;

        // Thicker stroke in a roomy box (checkbox), thinner in a menu mark.
        $strokes  = $h >= 10 ? 3 : 2;
        $segments = [];
        for ($t = 0; $t < $strokes; $t++) {
            $segments[] = [$x, $kneeY - 2 + $t, $kneeX, $kneeY + $t];
            $segments[] = [$kneeX, $kneeY + $t, $tailX, $tailY + $t];
        }
        $r->drawLines($segments);
    }

    /** {@inheritDoc} */
    public function sortIndicator(Renderer $r, Rect $rect, bool $ascending): void
    {
        $this->arrow(
            $r,
            $rect,
            $ascending ? Direction::Up : Direction::Down,
            $this->palette->text,
            $this->metrics->smallArrowSize,
        );
    }

    /** {@inheritDoc} */
    public function caret(Renderer $r, int $x, int $top, int $height): void
    {
        $r->setForeground(...$this->palette->contentText);
        $r->drawLines([[$x, $top, $x, $top + $height - 1]]);
    }

    /** Dotted rectangle — the Windows/Motif keyboard-focus convention. */
    public function focusRect(Renderer $r, Rect $rect): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$this->palette->focus);
        for ($i = 0; $i < $rect->width; $i += 2) {
            $r->fillRect($rect->x + $i, $rect->y, 1, 1);
            $r->fillRect($rect->x + $i, $rect->bottom(), 1, 1);
        }
        for ($i = 0; $i < $rect->height; $i += 2) {
            $r->fillRect($rect->x, $rect->y + $i, 1, 1);
            $r->fillRect($rect->right(), $rect->y + $i, 1, 1);
        }
    }

    /** Coloured disc with a letter — overridable for themes that draw shapes. */
    public function messageIcon(Renderer $r, Rect $rect, MessageIcon $icon): void
    {
        [$bg, $label] = match ($icon) {
            MessageIcon::Error       => [$this->palette->iconError,    '!'],
            MessageIcon::Warning     => [$this->palette->iconWarning,  '!'],
            MessageIcon::Question    => [$this->palette->iconQuestion, '?'],
            MessageIcon::Information => [$this->palette->iconInfo,     'i'],
        };

        $radius = intdiv(min($rect->width, $rect->height), 2);
        $cx     = $rect->centerX();
        $cy     = $rect->centerY();

        $r->setForeground(...$bg);
        $r->fillCircle($cx, $cy, $radius);
        $r->setForeground(...$this->palette->frame);
        $r->drawCircle($cx, $cy, $radius);

        $r->setForeground(...$this->palette->faceHighlight);
        $r->drawGlyphs(
            $label,
            $cx - intdiv($r->measureText($label), 2),
            $cy + intdiv($r->fontAscent(), 2),
        );
    }

    // -------------------------------------------------------------------------
    // Text
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function text(
        Renderer $r,
        string $text,
        int $x,
        int $baselineY,
        TextStyle $style,
    ): void {
        if ($text === '') return;

        if ($style === TextStyle::Disabled) {
            $this->drawDisabledText($r, $text, $x, $baselineY);
            return;
        }

        $r->setForeground(...$this->palette->forText($style));
        $r->drawGlyphs($text, $x, $baselineY);
    }

    /**
     * Engraved disabled text: a highlight ghost one pixel down-right with the
     * shadow colour on top. Themes that just grey out override this. The GC
     * background is already set by {@see text()}.
     */
    protected function drawDisabledText(Renderer $r, string $text, int $x, int $baselineY): void
    {
        $r->setForeground(...$this->palette->textEmboss);
        $r->drawGlyphs($text, $x + 1, $baselineY + 1);
        $r->setForeground(...$this->palette->textDisabled);
        $r->drawGlyphs($text, $x, $baselineY);
    }

    // -------------------------------------------------------------------------
    // Helpers for subclasses
    // -------------------------------------------------------------------------

    /**
     * Draw one bevel ring inside $rect: $topLeft along the top and left
     * edges, $bottomRight along the bottom and right ones.
     *
     * @param array{int,int,int} $topLeft
     * @param array{int,int,int} $bottomRight
     */
    protected function bevelRing(Renderer $r, Rect $rect, array $topLeft, array $bottomRight): void
    {
        if ($rect->isEmpty()) return;

        $x = $rect->x;
        $y = $rect->y;
        $right  = $rect->right();
        $bottom = $rect->bottom();

        $r->setForeground(...$topLeft);
        $r->drawLines([
            [$x, $y, $right, $y],
            [$x, $y, $x, $bottom],
        ]);
        $r->setForeground(...$bottomRight);
        $r->drawLines([
            [$x, $bottom, $right, $bottom],
            [$right, $y, $right, $bottom],
        ]);
    }

    /**
     * A rounded rectangle, optionally with a one-pixel border.
     *
     * Drawn as spans from {@see Corner::rects()}, which is also what shapes a
     * rounded *window* — so a theme's buttons and its window corners follow the
     * same arc rather than two that nearly agree.
     *
     * The border is done by filling the shape in the border colour and then
     * filling it again, inset by one and with one less radius, in the fill
     * colour. That is a little overdraw and a lot less arithmetic than tracing
     * the outline: a traced curve has to decide which pixel of each step is the
     * edge, and gets it wrong exactly where the steps change length.
     *
     * @param array{int,int,int}      $fill
     * @param array{int,int,int}|null $border
     */
    protected function roundedRect(
        Renderer $r, Rect $rect, int $radius, array $fill, ?array $border = null,
    ): void {
        if ($rect->isEmpty()) return;

        if ($border !== null) {
            $this->fillRounded($r, $rect, $radius, $border);
            $this->fillRounded($r, $rect->inset(1), max(0, $radius - 1), $fill);

            return;
        }

        $this->fillRounded($r, $rect, $radius, $fill);
    }

    /**
     * Fill a rounded shape in one colour.
     *
     * @param array{int,int,int} $colour
     */
    private function fillRounded(Renderer $r, Rect $rect, int $radius, array $colour): void
    {
        if ($rect->isEmpty()) return;

        $r->setForeground(...$colour);

        foreach (Corner::rects($rect->x, $rect->y, $rect->width, $rect->height, $radius) as [$x, $y, $w, $h]) {
            $r->fillRect($x, $y, $w, $h);
        }
    }

    /**
     * Nudge a colour towards this palette's text colour.
     *
     * How a flat era expresses a state: a hovered surface moves a little towards
     * the ink and a pressed one moves further. Towards the *text* rather than
     * towards black, which is what makes one set of constants work in a light
     * variant and a dark one — in light the surface darkens, in dark it
     * lightens, and neither needs to know which it is.
     *
     * @param array{int,int,int} $colour
     * @return array{int,int,int}
     */
    protected function towardsText(array $colour, float $amount): array
    {
        return Palette::mix($colour, $this->palette->text, $amount);
    }

    /**
     * Outline in a single colour, inset $inset pixels from $rect.
     *
     * @param array{int,int,int} $color
     */
    protected function outline(Renderer $r, Rect $rect, array $color, int $inset = 0): void
    {
        $box = $rect->inset($inset);
        if ($box->isEmpty()) return;
        $r->setForeground(...$color);
        $r->drawRect($box->x, $box->y, $box->width - 1, $box->height - 1);
    }
}
