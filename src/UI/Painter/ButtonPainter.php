<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Button;

/**
 * Push button. The painter's whole job is the label's position — the face and
 * border are the theme's business, and every era nudges the label when the
 * button is held down, by however many pixels its metrics say.
 */
final class ButtonPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Button $button, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $rect   = Rect::of($button->x, $button->y, $button->width, $button->height);

        $state = ControlState::of(
            pressed: $button->isPressed(),
            hovered: $button->isHovered(),
            enabled: $button->isEnabled(),
        );

        $chrome->button($r, $rect, $state);

        $label  = $button->getLabel();
        $shift  = $state->isPressed() ? $this->themes->metrics()->pressOffset : 0;
        $textX  = $rect->x + intdiv($rect->width - $r->measureText($label), 2) + $shift;
        $textY  = $r->baselineYForRect($rect->y, $rect->height) + $shift;

        $chrome->text(
            $r,
            $label,
            $textX,
            $textY,
            $button->isEnabled() ? TextStyle::Normal : TextStyle::Disabled,
        );

        // Keyboard focus: the dotted ring inside the bevel, as every toolkit of
        // this era drew it. Without it a tabbed-to button is indistinguishable
        // from an idle one and the traversal is invisible.
        if ($button->isFocused() && $button->isEnabled()) {
            $chrome->focusRect($r, $rect->inset($this->themes->metrics()->edge + 1));
        }
    }
}
