<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Fluent;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\BaseChrome;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Palette;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;

/**
 * The current Windows look: rounded corners, hairline borders, no bevels.
 *
 * What actually changes when an era stops using bevels is worth spelling out,
 * because it is not only a matter of colour. Every era before this one drew
 * depth: a control was raised or sunken, and the palette carried four separate
 * roles to say so. Here a control is a **surface with an outline**, and depth is
 * implied by that outline being a shade darker than the fill. So the four bevel
 * roles collapse into two jobs — a fill and a border — and {@see edge()} stops
 * drawing a ring of light and shade and draws one hairline instead.
 *
 * Three consequences follow, and each of them is a method below:
 *
 * - **Corners are round**, drawn through {@see BaseChrome::roundedRect()} at the
 *   theme's own radius, and the *window's* corners are cut to the same curve by
 *   {@see \Cyrnetix\X11\Theme\Corner} — so a button and the window agree.
 * - **Hover is real.** This is the first era in the toolkit where a plain button
 *   reacts to the pointer, so {@see rendersButtonHover()} returns true and the
 *   handler stops skipping the repaint it had been skipping for every other
 *   theme.
 * - **The close button goes red.** Not decoration: it is the one caption control
 *   whose action cannot be undone, and this era distinguishes it on hover rather
 *   than by shape.
 *
 * The colours come from the palette, and the palette's values are this project's
 * own — chosen to read like the era rather than measured from it, which is not
 * something a screenshot could have given here anyway.
 */
final class FluentChrome extends BaseChrome
{
    /** How far a surface moves towards the ink when touched. */
    private const HOVER   = 0.06;
    private const PRESSED = 0.12;

    /** {@inheritDoc} */
    public function edge(Renderer $r, Rect $rect, Edge $edge): void
    {
        if ($edge === Edge::None) return;

        // One hairline, whichever way the caller thinks the edge faces. An era
        // without bevels has nothing to say about direction, and pretending
        // otherwise would put a fake light source on a flat surface.
        $this->roundedOutline($r, $rect, $this->radius($rect), $this->palette()->frame);
    }

    /** {@inheritDoc} */
    public function well(Renderer $r, Rect $rect): void
    {
        $this->roundedRect($r, $rect, $this->radius($rect), $this->palette()->content, $this->palette()->frame);
    }

    /** {@inheritDoc} */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        $palette = $this->palette();

        $fill = match ($state) {
            ControlState::Hovered  => $this->towardsText($palette->faceLight, self::HOVER),
            ControlState::Pressed  => $this->towardsText($palette->faceLight, self::PRESSED),
            ControlState::Disabled => $palette->face,
            default                => $palette->faceLight,
        };

        // The default button is the accent one, as it has been since this look
        // arrived — a filled call to action rather than an extra outline.
        if ($state === ControlState::Default) {
            $this->roundedRect($r, $rect, $this->radius($rect), $palette->accent, $palette->accent);

            return;
        }

        $this->roundedRect($r, $rect, $this->radius($rect), $fill, $palette->frame);
    }

    /**
     * True: this era's buttons light up under the pointer.
     *
     * Every other theme here returns false, and the handler uses that to skip a
     * repaint it would otherwise do on every mouse move for no visible change.
     */
    public function rendersButtonHover(): bool { return true; }

    /** {@inheritDoc} */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        $palette = $this->palette();

        // A flat toolbar shows nothing until you touch it, which is the point of
        // one: the icons are the interface and the buttons are not.
        if (!$checked && $state === ControlState::Normal) return;

        $fill = $checked
            ? Palette::mix($palette->face, $palette->accent, 0.22)
            : $this->towardsText($palette->face, self::HOVER);

        $this->roundedRect($r, $rect, $this->radius($rect), $fill);
    }

    /** {@inheritDoc} */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $palette = $this->palette();
        $radius  = max(2, intdiv($rect->width, 4));

        if ($checked) {
            $this->roundedRect($r, $rect, $radius, $palette->accent, $palette->accent);
            $this->checkGlyph($r, $rect, $palette->selectionText);

            return;
        }

        $fill = $state === ControlState::Hovered
            ? $this->towardsText($palette->content, self::HOVER)
            : $palette->content;

        $this->roundedRect($r, $rect, $radius, $fill, $palette->frame);
    }

    /** {@inheritDoc} */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $palette = $this->palette();
        $centreX = $rect->centerX();
        $centreY = $rect->centerY();
        $radius  = intdiv(min($rect->width, $rect->height), 2);

        // A ring, then a dot — and when unchecked the ring is the border colour
        // so it reads as an empty control rather than a filled one.
        $r->setForeground(...($checked ? $palette->accent : $palette->frame));
        $r->fillCircle($centreX, $centreY, $radius);

        $r->setForeground(...(
            $state === ControlState::Hovered
                ? $this->towardsText($palette->content, self::HOVER)
                : $palette->content
        ));
        $r->fillCircle($centreX, $centreY, max(1, $radius - ($checked ? 1 : 1)));

        if (!$checked) return;

        $r->setForeground(...$palette->accent);
        $r->fillCircle($centreX, $centreY, max(1, intdiv($radius, 2)));
    }

    /** {@inheritDoc} */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        $palette = $this->palette();

        // An inactive tab is just its label. Nothing is drawn for it at all,
        // which is what makes the active one read as selected without a bevel
        // or a notch in the panel below.
        if (!$active) return;

        $this->roundedRect($r, $rect, $this->radius($rect), $palette->panel ?? $palette->face);
    }

    /** {@inheritDoc} */
    public function tabPanel(Renderer $r, Rect $rect): void
    {
        $this->roundedRect(
            $r, $rect, $this->metrics()->cornerRadius,
            $this->palette()->panel ?? $this->palette()->face,
            $this->palette()->frame,
        );
    }

    /** {@inheritDoc} */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        // The trough is barely there, and gone entirely when there is nothing to
        // scroll — this era hides a scrollbar that has no work to do.
        if (!$scrollable) return;

        $this->fill($r, $rect, Surface::Track);
    }

    /** {@inheritDoc} */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        // A pill, inset inside the trough so the trough shows around it, which
        // is where the modern thin scrollbar gets its look.
        $inset = $horizontal
            ? $rect->insetXY(2, max(1, intdiv($rect->height, 3)))
            : $rect->insetXY(max(1, intdiv($rect->width, 3)), 2);

        $shorter = min($inset->width, $inset->height);

        $this->roundedRect($r, $inset, intdiv($shorter, 2), $this->palette()->textDim);
    }

    /** {@inheritDoc} */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $shorter = min($rect->width, $rect->height);

        $this->roundedRect($r, $rect, intdiv($shorter, 2), $this->palette()->track, $this->palette()->frame);
    }

    /** {@inheritDoc} */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $palette = $this->palette();
        $radius  = intdiv(min($rect->width, $rect->height), 2);

        // A disc, not a chevron: this era's slider has no sense of pointing at
        // its own tick marks, so $point is deliberately ignored.
        $r->setForeground(...$palette->content);
        $r->fillCircle($rect->centerX(), $rect->centerY(), $radius);

        $r->setForeground(...($focused ? $palette->accent : $palette->frame));
        $r->drawCircle($rect->centerX(), $rect->centerY(), $radius);

        $r->setForeground(...$palette->accent);
        $r->fillCircle($rect->centerX(), $rect->centerY(), max(1, intdiv($radius, 2)));
    }

    /** {@inheritDoc} */
    public function progressFill(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $shorter = min($rect->width, $rect->height);

        $this->roundedRect($r, $rect, intdiv($shorter, 2), $this->palette()->progressBar);
    }

    /** {@inheritDoc} */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        $this->roundedRect($r, $rect, $this->metrics()->cornerRadius, $this->palette()->menu, $this->palette()->frame);
    }

    /** {@inheritDoc} */
    public function menuItemHighlight(Renderer $r, Rect $rect): void
    {
        // Inset, so the highlight floats inside the popup's rounded edge rather
        // than running into it — which is the detail that makes a rounded menu
        // look rounded.
        $this->roundedRect(
            $r, $rect->insetXY(3, 0), max(2, intdiv($this->metrics()->cornerRadius, 2)),
            Palette::mix($this->palette()->menu, $this->palette()->accent, 0.20),
        );
    }

    /**
     * Ordinary menu text, not reversed.
     *
     * The highlight is a *tint* of the popup rather than a solid accent fill, so
     * the label it sits behind stays readable in the normal colour — reversing
     * it would put light text on a light tint.
     */
    public function menuHighlightTextStyle(): TextStyle { return TextStyle::Menu; }

    /** {@inheritDoc} */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect
    {
        $palette = $this->palette();
        $metrics = $this->metrics();

        $this->roundedRect(
            $r, $rect, $this->radius($rect),
            $editable ? $palette->content : $palette->faceLight,
            $open ? $palette->accent : $palette->frame,
        );

        // No bevelled button carved into the edge: just an arrow in the field's
        // own right-hand end, which is why nothing is drawn for it here.
        $arrow = $rect->rightSlice($metrics->fieldButtonWidth)->insetXY(2, 2);
        $this->arrow($r, $arrow, Direction::Down, $palette->text);

        return $rect->insetEach(
            $metrics->fieldPadding + 2, 1, $metrics->fieldButtonWidth + 2, 1,
        );
    }

    /** {@inheritDoc} */
    public function dropFieldTextStyle(bool $editable): TextStyle
    {
        return $editable ? TextStyle::Content : TextStyle::Normal;
    }

    /** {@inheritDoc} */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $palette = $this->palette();

        $this->fill($r, $rect, Surface::Face);

        // A single rule under the header, and a divider at its right edge. No
        // raised cell: the row below is the content, and the header is a label
        // over it.
        $r->setForeground(...$palette->frame);
        $r->fillRect($rect->x, $rect->bottom(), $rect->width, 1);
        $r->fillRect($rect->right(), $rect->y + 4, 1, max(1, $rect->height - 8));
    }

    /** {@inheritDoc} */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
        // The title bar is the window: same colour, no gradient, no separate
        // band. An inactive one loses a little contrast and nothing else.
        $palette = $this->palette();

        $r->setForeground(...($active ? $palette->captionActive : $palette->captionInactive));
        $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    /** {@inheritDoc} */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void {
        $palette = $this->palette();

        if ($state !== ControlState::Normal) {
            // Close is the one action with no way back, and this era says so
            // with colour rather than with a different glyph.
            $fill = $button === CaptionButton::Close
                ? $palette->iconError
                : $this->towardsText($palette->captionActive, self::HOVER);

            $r->setForeground(...$fill);
            $r->fillRect($rect->x, $rect->y, $rect->width, $rect->height);
        }

        $ink = $button === CaptionButton::Close && $state !== ControlState::Normal
            ? $palette->selectionText
            : ($active ? $palette->captionActiveText : $palette->captionInactiveText);

        $this->captionGlyph($r, $rect->insetXY(4, 4), $button, $ink);
    }

    /** {@inheritDoc} */
    public function windowBorder(Renderer $r, Rect $rect): void
    {
        // One hairline round the whole window. The corners are cut by the SHAPE
        // extension to the same curve, so the border follows them for free —
        // the pixels outside the shape are simply not drawn.
        $this->roundedOutline($r, $rect, $this->metrics()->windowCornerRadius, $this->palette()->frame);
    }

    /** {@inheritDoc} */
    public function dialogFrame(Renderer $r, Rect $rect): void
    {
        $this->panel($r, $rect, Surface::Face, Edge::None);
        $this->roundedOutline($r, $rect, $this->metrics()->windowCornerRadius, $this->palette()->frame);
    }

    /**
     * An accent ring, not a dotted rectangle.
     *
     * Drawn *outside* the control's own rectangle, which is why it is grown
     * rather than inset: this era's focus indicator surrounds a control instead
     * of being stamped inside it, and the repaint paths already leave a couple
     * of pixels of room for exactly this.
     */
    public function focusRect(Renderer $r, Rect $rect): void
    {
        $this->roundedOutline($r, $rect->grow(1), $this->radius($rect) + 1, $this->palette()->focus);
    }

    /** A one-pixel rounded outline with nothing inside it. */
    private function roundedOutline(Renderer $r, Rect $rect, int $radius, array $colour): void
    {
        if ($rect->isEmpty()) return;

        // The same trick roundedRect() uses for its border, without the second
        // fill: the ring is what is left when the inner shape is not drawn.
        // Here the inside has already been painted by the caller, so only the
        // ring itself is emitted.
        $outer = \Cyrnetix\X11\Theme\Corner::rects($rect->x, $rect->y, $rect->width, $rect->height, $radius);
        $inner = $rect->inset(1);
        $keep  = \Cyrnetix\X11\Theme\Corner::rects($inner->x, $inner->y, $inner->width, $inner->height, max(0, $radius - 1));

        // Rows the outline covers, minus the rows the inside covers, leaves the
        // top and bottom caps whole and the sides one pixel wide.
        $insideByRow = [];
        foreach ($inner->isEmpty() ? [] : $keep as [$x, $y, $w, $h]) {
            for ($row = $y; $row < $y + $h; $row++) $insideByRow[$row] = [$x, $x + $w - 1];
        }

        $r->setForeground(...$colour);

        foreach ($outer as [$x, $y, $w, $h]) {
            for ($row = $y; $row < $y + $h; $row++) {
                $span = $insideByRow[$row] ?? null;

                if ($span === null) {
                    $r->fillRect($x, $row, $w, 1);

                    continue;
                }

                $left = $span[0] - $x;
                if ($left > 0) $r->fillRect($x, $row, $left, 1);

                $right = ($x + $w - 1) - $span[1];
                if ($right > 0) $r->fillRect($span[1] + 1, $row, $right, 1);
            }
        }
    }

    /** A control's radius, never more than half its shorter side. */
    private function radius(Rect $rect): int
    {
        return min($this->metrics()->cornerRadius, intdiv(min($rect->width, $rect->height), 2));
    }

}
