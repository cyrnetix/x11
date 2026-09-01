<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\UpDown;

/**
 * Up-down (spin) control: two stacked buttons with compact arrows. The pressed
 * half sinks and its arrow nudges along with it — the same idiom as the
 * scrollbar's end caps.
 */
final class UpDownPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(UpDown $ud, Renderer $r): void
    {
        $m = $this->themes->metrics();

        $w       = $m->upDownWidth;
        $halfH   = $m->upDownArrowHeight();
        $pressed = $ud->getPressedRegion();

        $this->paintHalf(
            $r,
            Rect::of($ud->x, $ud->y, $w, $halfH),
            Direction::Up,
            $pressed === UpDown::REGION_UP,
        );
        $this->paintHalf(
            $r,
            Rect::of($ud->x, $ud->y + $halfH, $w, $halfH),
            Direction::Down,
            $pressed === UpDown::REGION_DOWN,
        );
    }

    /** Paints the half. */
    private function paintHalf(Renderer $r, Rect $rect, Direction $direction, bool $pressed): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $chrome->button($r, $rect, $pressed ? ControlState::Pressed : ControlState::Normal);

        $shift = $pressed ? $m->pressOffset : 0;
        $chrome->arrow(
            $r,
            $rect->shift($shift, $shift),
            $direction,
            $this->themes->palette()->text,
            $m->smallArrowSize,
        );
    }
}
