<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Material;

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
 * Material: depth by tone, not by line.
 *
 * The interesting thing about putting this next to {@see \Cyrnetix\X11\Theme\Fluent\FluentChrome}
 * is how little they share despite both being flat and rounded. Four decisions
 * separate them, and each is a method here:
 *
 * - **{@see edge()} draws nothing.** This era has no borders at all: a surface
 *   that sits *above* another is a lighter tone of it, and that difference is
 *   the whole of the depth cue. An empty `edge()` is therefore the design, not
 *   an unimplemented stub — and it is why {@see well()} can be a plain fill and
 *   still read as a field.
 * - **Buttons are pills.** Fully rounded, radius half the height, rather than
 *   the small consistent radius the Windows look uses everywhere.
 * - **A field is filled and underlined.** Not outlined: a tinted surface with a
 *   rule along the bottom, which thickens and takes the accent colour when the
 *   field is active. That rule is the field's entire border.
 * - **The selected tab is marked by a bar, not a shape.** Tabs are all the same
 *   surface, and an underline says which one you are on — so
 *   {@see tab()} draws a rule rather than a raised or notched shape.
 *
 * The palette's values are this project's own, chosen to read like the idiom
 * rather than measured from any published one.
 */
final class MaterialChrome extends BaseChrome
{
    /** How far a surface moves towards the ink when hovered or pressed. */
    private const HOVER   = 0.08;
    private const PRESSED = 0.16;

    /** Thickness of the rule under a field or an active tab. */
    private const INDICATOR = 2;

    /**
     * Nothing.
     *
     * There are no borders in this era. A caller asking for a raised or sunken
     * edge is asking for a depth cue, and here that cue is the surface's own
     * tone — which whoever filled it has already applied. The two explicit
     * cases stay, because a caller asking for an *outline* wants a line rather
     * than a suggestion of depth.
     */
    public function edge(Renderer $r, Rect $rect, Edge $edge): void
    {
        if ($edge !== Edge::Outline && $edge !== Edge::Etched) return;

        $this->outline($r, $rect, $this->palette()->frame);
    }

    /**
     * A filled field with a rule under it.
     *
     * The rule is the border, and the only one: it runs the full width at the
     * bottom edge and nowhere else, which is what distinguishes a Material field
     * from an outlined one at a glance.
     */
    public function well(Renderer $r, Rect $rect): void
    {
        $palette = $this->palette();
        $radius  = $this->metrics()->cornerRadius;

        // Rounded at the top, square at the bottom, because the rule beneath it
        // has to meet the field's full width — so the shape is drawn rounded and
        // then the bottom corners are filled back in.
        $this->roundedRect($r, $rect, $radius, $palette->content);
        $r->setForeground(...$palette->content);
        $r->fillRect($rect->x, $rect->bottom() - $radius, $rect->width, $radius);

        $r->setForeground(...$palette->frame);
        $r->fillRect($rect->x, $rect->bottom() - self::INDICATOR + 1, $rect->width, self::INDICATOR);
    }

    /** {@inheritDoc} */
    public function button(Renderer $r, Rect $rect, ControlState $state): void
    {
        $palette = $this->palette();

        // A pill: the radius is half the height, whatever the height is.
        $radius = intdiv($rect->height, 2);

        // The default action is filled in the accent; everything else is a
        // tonal surface. Neither has an outline.
        if ($state === ControlState::Default) {
            $this->roundedRect($r, $rect, $radius, $palette->accent);

            return;
        }

        $fill = match ($state) {
            ControlState::Hovered  => $this->towardsText($palette->faceLight, self::HOVER),
            ControlState::Pressed  => $this->towardsText($palette->faceLight, self::PRESSED),
            ControlState::Disabled => Palette::mix($palette->face, $palette->faceLight, 0.5),
            default                => $palette->faceLight,
        };

        $this->roundedRect($r, $rect, $radius, $fill);
    }

    /** This era's buttons respond to the pointer. */
    public function rendersButtonHover(): bool { return true; }

    /** {@inheritDoc} */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void
    {
        $palette = $this->palette();

        if (!$checked && $state === ControlState::Normal) return;

        // A circle, because a toolbar button here is an icon with a tonal disc
        // behind it rather than a rectangle with a border.
        $radius = intdiv(min($rect->width, $rect->height), 2);

        $r->setForeground(...(
            $checked
                ? Palette::mix($palette->face, $palette->accent, 0.24)
                : $this->towardsText($palette->face, self::HOVER)
        ));
        $r->fillCircle($rect->centerX(), $rect->centerY(), $radius);
    }

    /** {@inheritDoc} */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $palette = $this->palette();
        $radius  = max(2, intdiv($rect->width, 5));

        if ($checked) {
            $this->roundedRect($r, $rect, $radius, $palette->accent);
            $this->checkGlyph($r, $rect, $palette->selectionText);

            return;
        }

        // Unchecked is an outline and nothing else — the one place this era does
        // draw a border, because an empty box with no edge is invisible on a
        // tonal surface.
        $this->roundedRect($r, $rect, $radius, $palette->textDim);
        $this->roundedRect(
            $r,
            $rect->inset(self::INDICATOR),
            max(1, $radius - 1),
            $state === ControlState::Hovered ? $this->towardsText($palette->face, self::HOVER) : $palette->face,
        );
    }

    /** {@inheritDoc} */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void
    {
        $palette = $this->palette();
        $radius  = intdiv(min($rect->width, $rect->height), 2);

        $r->setForeground(...($checked ? $palette->accent : $palette->textDim));
        $r->fillCircle($rect->centerX(), $rect->centerY(), $radius);

        $r->setForeground(...(
            $state === ControlState::Hovered ? $this->towardsText($palette->face, self::HOVER) : $palette->face
        ));
        $r->fillCircle($rect->centerX(), $rect->centerY(), max(1, $radius - self::INDICATOR));

        if (!$checked) return;

        // The dot is the accent again, inside its own ring.
        $r->setForeground(...$palette->accent);
        $r->fillCircle($rect->centerX(), $rect->centerY(), max(1, $radius - self::INDICATOR * 2));
    }

    /**
     * A tab is marked by a bar beneath it, not by its shape.
     *
     * Every tab shares the panel's surface, so there is nothing to raise or
     * notch: the indicator is a rule along the bottom of the active one, in the
     * accent colour.
     */
    public function tab(Renderer $r, Rect $rect, bool $active): void
    {
        if (!$active) return;

        $palette = $this->palette();

        $r->setForeground(...$palette->accent);
        $r->fillRect($rect->x + 2, $rect->bottom() - self::INDICATOR + 1, max(0, $rect->width - 4), self::INDICATOR);
    }

    /** {@inheritDoc} */
    public function tabPanel(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Panel);
    }

    /** {@inheritDoc} */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void
    {
        if (!$scrollable) return;

        $this->fill($r, $rect, Surface::Track);
    }

    /** {@inheritDoc} */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $inset = $horizontal
            ? $rect->insetXY(2, max(1, intdiv($rect->height, 3)))
            : $rect->insetXY(max(1, intdiv($rect->width, 3)), 2);

        $this->roundedRect($r, $inset, intdiv(min($inset->width, $inset->height), 2), $this->palette()->textDim);
    }

    /** {@inheritDoc} */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->roundedRect($r, $rect, intdiv(min($rect->width, $rect->height), 2), $this->palette()->track);
    }

    /** {@inheritDoc} */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void
    {
        $palette = $this->palette();
        $radius  = intdiv(min($rect->width, $rect->height), 2);

        // A solid accent disc, with a tonal halo when focused — this era's
        // version of a focus ring.
        if ($focused) {
            $r->setForeground(...Palette::mix($palette->face, $palette->accent, 0.30));
            $r->fillCircle($rect->centerX(), $rect->centerY(), $radius);
        }

        $r->setForeground(...$palette->accent);
        $r->fillCircle($rect->centerX(), $rect->centerY(), max(1, $radius - ($focused ? 3 : 0)));
    }

    /** {@inheritDoc} */
    public function progressFill(Renderer $r, Rect $rect, bool $horizontal): void
    {
        $this->roundedRect($r, $rect, intdiv(min($rect->width, $rect->height), 2), $this->palette()->progressBar);
    }

    /** {@inheritDoc} */
    public function menuPopup(Renderer $r, Rect $rect): void
    {
        // No border: the popup is a lighter tone than what it covers, and that
        // is what says it is above it.
        $this->roundedRect($r, $rect, $this->metrics()->cornerRadius, $this->palette()->menu);
    }

    /** {@inheritDoc} */
    public function menuItemHighlight(Renderer $r, Rect $rect): void
    {
        $this->roundedRect(
            $r, $rect->insetXY(4, 0), $this->metrics()->cornerRadius,
            Palette::mix($this->palette()->menu, $this->palette()->accent, 0.18),
        );
    }

    /** Ordinary text: the highlight is a tint, not a reversal. */
    public function menuHighlightTextStyle(): TextStyle { return TextStyle::Menu; }

    /** {@inheritDoc} */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect
    {
        $palette = $this->palette();
        $metrics = $this->metrics();

        $this->roundedRect($r, $rect, $metrics->cornerRadius, $editable ? $palette->content : $palette->faceLight);

        // The same rule a field has, taking the accent while the list is open.
        $r->setForeground(...($open ? $palette->accent : $palette->frame));
        $r->fillRect($rect->x, $rect->bottom() - self::INDICATOR + 1, $rect->width, self::INDICATOR);

        $this->arrow($r, $rect->rightSlice($metrics->fieldButtonWidth)->insetXY(2, 2), Direction::Down, $palette->text);

        return $rect->insetEach($metrics->fieldPadding + 2, 1, $metrics->fieldButtonWidth + 2, self::INDICATOR + 1);
    }

    /** {@inheritDoc} */
    public function dropFieldTextStyle(bool $editable): TextStyle
    {
        return $editable ? TextStyle::Content : TextStyle::Normal;
    }

    /** {@inheritDoc} */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void
    {
        $this->fill($r, $rect, Surface::Face);

        $r->setForeground(...$this->palette()->frame);
        $r->fillRect($rect->x, $rect->bottom(), $rect->width, 1);
    }

    /** {@inheritDoc} */
    public function caption(Renderer $r, Rect $rect, bool $active): void
    {
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

        // A tonal disc under the glyph, as everywhere else in this era.
        if ($state !== ControlState::Normal) {
            $r->setForeground(...(
                $button === CaptionButton::Close
                    ? $palette->iconError
                    : $this->towardsText($palette->captionActive, self::HOVER)
            ));
            $r->fillCircle($rect->centerX(), $rect->centerY(), intdiv(min($rect->width, $rect->height), 2));
        }

        $ink = $button === CaptionButton::Close && $state !== ControlState::Normal
            ? $palette->selectionText
            : ($active ? $palette->captionActiveText : $palette->captionInactiveText);

        $this->captionGlyph($r, $rect->insetXY(5, 5), $button, $ink);
    }

    /** No border at all: the window is a surface, and the desktop shows around it. */
    public function windowBorder(Renderer $r, Rect $rect): void {}

    /** {@inheritDoc} */
    public function dialogFrame(Renderer $r, Rect $rect): void
    {
        $this->fill($r, $rect, Surface::Face);
    }

    /** A tonal halo, in keeping with the sliders and the discs. */
    public function focusRect(Renderer $r, Rect $rect): void
    {
        $this->outline($r, $rect->grow(1), $this->palette()->accent);
    }
}
