<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Win9x;

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
 * Windows 9x / 2000 chrome — the look this toolkit was originally written
 * against, moved out of the painters unchanged.
 *
 * Its signature is the two-layer bevel: an outer ring of 3DHIGHLIGHT and
 * 3DDKSHADOW, then an inner ring of 3DLIGHT and 3DSHADOW one pixel in.
 * Sinking a control swaps the two rings; every other 3D effect in the era
 * (tabs, thumbs, checkboxes, group boxes) is a variation on that.
 */
final class Win9xChrome extends BaseChrome
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
                $this->bevelRing($r, $rect,  $p->faceHighlight, $p->faceDarkShadow);
                $this->bevelRing($r, $inner, $p->faceLight,     $p->faceShadow);
                return;

            case Edge::Sunken:
                $this->bevelRing($r, $rect,  $p->faceDarkShadow, $p->faceHighlight);
                $this->bevelRing($r, $inner, $p->faceShadow,     $p->faceLight);
                return;

            case Edge::RaisedThin:
                $this->bevelRing($r, $rect, $p->faceHighlight, $p->faceShadow);
                return;

            case Edge::SunkenThin:
                $this->bevelRing($r, $rect, $p->faceShadow, $p->faceHighlight);
                return;

            case Edge::Etched:
                // Sunken outer + raised inner: the group-box groove.
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
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, $state->isPressed() ? Edge::Sunken : Edge::Raised);
    }

    /** Column headers get a single-layer bevel with the hard outer shadow. */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Face);
        if ($state->isPressed()) {
            $this->bevelRing($r, $rect, $this->palette->faceDarkShadow, $this->palette->faceHighlight);
        } else {
            $this->bevelRing($r, $rect, $this->palette->faceHighlight, $this->palette->faceDarkShadow);
        }
    }

    /** {@inheritDoc} */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        // White well with a sunken rim.
        $this->fill($r, $rect, Surface::Content);
        $this->edge($r, $rect, Edge::Sunken);

        if (!$checked) return;

        // Three offset stroke pairs give the 3px-thick tick. Offsets are
        // expressed against the box size so a theme-resized checkbox scales.
        $s = $rect->width;
        $r->setForeground(...$this->palette->forText(
            $state === ControlState::Disabled ? TextStyle::Disabled : TextStyle::Content,
        ));

        $x0 = $rect->x + intdiv(3 * $s, 13);
        $y0 = $rect->y + intdiv(6 * $s, 13);
        $kx = $rect->x + intdiv(5 * $s, 13);
        $ky = $rect->y + intdiv(8 * $s, 13);
        $x1 = $rect->x + intdiv(10 * $s, 13);
        $y1 = $rect->y + intdiv(3 * $s, 13);

        $segments = [];
        for ($t = 0; $t < 3; $t++) {
            $segments[] = [$x0, $y0 + $t, $kx, $ky + $t];
            $segments[] = [$kx, $ky + $t, $x1, $y1 + $t];
        }
        $r->drawLines($segments);
    }

    /** {@inheritDoc} */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $p    = $this->palette;
        $size = $rect->width;
        $cx   = $rect->x + intdiv($size, 2);
        $cy   = $rect->y + intdiv($size, 2);

        // White interior disc.
        $r->setForeground(...$p->content);
        $r->fillCircle($cx, $cy, intdiv($size, 2) - 1);

        // Sunken rim: dark over the top-left half, light over the bottom-right.
        // Angles are degrees, 0° at 3 o'clock, counter-clockwise.
        $outer = $size - 1;
        $r->setForeground(...$p->faceDarkShadow);
        $r->drawArc($rect->x, $rect->y, $outer, $outer, 45, 180);
        $r->setForeground(...$p->faceHighlight);
        $r->drawArc($rect->x, $rect->y, $outer, $outer, 225, 180);

        $inner = $size - 3;
        $r->setForeground(...$p->faceShadow);
        $r->drawArc($rect->x + 1, $rect->y + 1, $inner, $inner, 45, 180);
        $r->setForeground(...$p->faceLight);
        $r->drawArc($rect->x + 1, $rect->y + 1, $inner, $inner, 225, 180);

        if ($checked) {
            $r->setForeground(...($state === ControlState::Disabled ? $p->textDisabled : $p->text));
            $r->fillCircle($cx, $cy, 2);
        }
    }

    /**
     * Tab with no bottom edge — the active one is handed a taller rect by the
     * painter so it overlaps and erases the panel's top border, which is what
     * makes it read as "in front".
     */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Face);

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x, $rect->y, $rect->right(), $rect->y],
            [$rect->x, $rect->y, $rect->x, $rect->bottom()],
        ]);
        $r->setForeground(...$p->faceDarkShadow);
        $r->drawLines([[$rect->right(), $rect->y, $rect->right(), $rect->bottom()]]);
        $r->setForeground(...$p->faceShadow);
        $r->drawLines([[$rect->right() - 1, $rect->y + 1, $rect->right() - 1, $rect->bottom()]]);
    }

    /** {@inheritDoc} */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->fill($r, $rect, Surface::Face);
        $this->edge($r, $rect, Edge::Raised);
    }

    /**
     * Pentagonal slider thumb: a rectangular body with a chevron pointing at
     * the tick side, bevelled highlight on the leading edges.
     */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $p     = $this->palette;
        $tip   = $this->metrics->trackbarThumbPoint;
        $horiz = $point === Direction::Down || $point === Direction::Up;

        if ($horiz) {
            $bodyH   = $rect->height - $tip;
            $bodyBot = $rect->y + $bodyH - 1;

            $r->setForeground(...$p->face);
            $r->fillRect($rect->x, $rect->y, $rect->width, $bodyH);
            for ($i = 0; $i < $tip; $i++) {
                $rowW = $rect->width - 2 * $i;
                if ($rowW <= 0) break;
                $r->fillRect($rect->x + $i, $bodyBot + 1 + $i, $rowW, 1);
            }

            $r->setForeground(...$p->faceHighlight);
            $r->drawLines([
                [$rect->x, $rect->y, $rect->right(), $rect->y],
                [$rect->x, $rect->y, $rect->x, $bodyBot],
                [$rect->x, $bodyBot, $rect->x + $tip, $bodyBot + $tip],
            ]);
            $r->setForeground(...$p->faceDarkShadow);
            $r->drawLines([
                [$rect->right(), $rect->y, $rect->right(), $bodyBot],
                [$rect->right(), $bodyBot, $rect->right() - $tip, $bodyBot + $tip],
            ]);

            if ($focused) {
                $this->focusRect($r, Rect::of($rect->x + 2, $rect->y + 2, $rect->width - 4, $bodyH - 4));
            }
            return;
        }

        $bodyW     = $rect->width - $tip;
        $bodyRight = $rect->x + $bodyW - 1;

        $r->setForeground(...$p->face);
        $r->fillRect($rect->x, $rect->y, $bodyW, $rect->height);
        for ($i = 0; $i < $tip; $i++) {
            $rowH = $rect->height - 2 * $i;
            if ($rowH <= 0) break;
            $r->fillRect($bodyRight + 1 + $i, $rect->y + $i, 1, $rowH);
        }

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x, $rect->y, $bodyRight, $rect->y],
            [$rect->x, $rect->y, $rect->x, $rect->bottom()],
            [$bodyRight, $rect->y, $bodyRight + $tip, $rect->y + $tip],
        ]);
        $r->setForeground(...$p->faceDarkShadow);
        $r->drawLines([
            [$rect->x, $rect->bottom(), $bodyRight, $rect->bottom()],
            [$bodyRight, $rect->bottom(), $bodyRight + $tip, $rect->bottom() - $tip],
        ]);

        if ($focused) {
            $this->focusRect($r, Rect::of($rect->x + 2, $rect->y + 2, $bodyW - 4, $rect->height - 4));
        }
    }

    /** Menu popups get a hard frame plus a thin inner highlight for lift. */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $p = $this->palette;

        $this->fill($r, $rect, Surface::Menu);
        $r->setForeground(...$p->frame);
        $r->drawRect($rect->x, $rect->y, $rect->width, $rect->height);

        $r->setForeground(...$p->faceHighlight);
        $r->drawLines([
            [$rect->x + 1, $rect->y + 1, $rect->right() - 1, $rect->y + 1],
            [$rect->x + 1, $rect->y + 1, $rect->x + 1, $rect->bottom() - 1],
        ]);
    }

    /** Gradient caption, deep blue at the left fading out to the right. */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        if ($rect->isEmpty()) return;

        $p = $this->palette;
        $r->fillHorizontalGradient(
            $rect->x, $rect->y, $rect->width, $rect->height,
            $active ? $p->captionActive    : $p->captionInactive,
            $active ? $p->captionActiveEnd : $p->captionInactiveEnd,
        );
    }

    /**
     * The leading control is the app icon rather than a real button: a small
     * framed square, no bevel.
     */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        if ($button !== CaptionButton::Menu) {
            parent::captionButton($r, $rect, $button, $state, $active);
            return;
        }

        $icon = $rect->centeredSquare(min($rect->width, $rect->height) - 2);
        $r->setForeground(...$this->palette->faceHighlight);
        $r->fillRect($icon->x, $icon->y, $icon->width, $icon->height);
        $r->setForeground(...$this->palette->faceDarkShadow);
        $r->drawRect($icon->x, $icon->y, $icon->width - 1, $icon->height - 1);
    }

}
