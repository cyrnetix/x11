<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\ThemeManager;

/**
 * A caption, drawn the one way there is to draw one.
 *
 * The main window, a form, the file picker and the message box all have a title
 * bar, and they all used to draw it themselves — the frame in three passes with
 * real buttons, the other three with a single `titleBar()` primitive that
 * hand-rolled its own gradient and icon box. That is why a dialog's caption
 * looked like it came from a different era than the window behind it. Placing
 * the pieces is {@see \Cyrnetix\X11\Theme\CaptionLayout}; this draws them.
 *
 * **Three passes, and the order matters.** `caption()` lays the background;
 * `captionGrab()` puts the theme's drag texture *only* in the band between the
 * button groups, which is what keeps it off the boxes; `captionTitle()` then
 * clears its own patch out of that texture before drawing the title. Buttons
 * last, over the background they sit on.
 */
final class CaptionPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * @param list<array{CaptionButton, Rect}> $buttons Placed by CaptionLayout.
     */
    public function paint(
        Renderer $r,
        Rect $caption,
        Rect $titleRect,
        string $title,
        array $buttons = [],
        bool $active = true,
        ?CaptionButton $pressed = null,
        ?CaptionButton $hovered = null,
    ): void {
        if ($caption->isEmpty()) return;

        $chrome = $this->themes->chrome();

        $chrome->caption($r, $caption, $active);
        $chrome->captionGrab($r, $titleRect, $active);
        $chrome->captionTitle($r, $titleRect, $title, $active);

        foreach ($buttons as [$button, $rect]) {
            $chrome->captionButton(
                $r,
                $rect,
                $button,
                ControlState::of(pressed: $button === $pressed, hovered: $button === $hovered),
                $active,
            );
        }
    }
}
