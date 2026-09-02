<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\ScrollBar;
use Cyrnetix\X11\UI\Widget\ScrollOrientation;

/**
 * Scrollbar: two arrow caps, a trough and a proportional thumb. Geometry comes
 * from the widget (which hit-tests against the same numbers) and the look from
 * the theme, including how wide the whole bar is.
 */
final class ScrollBarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(ScrollBar $sb, Renderer $r): void
    {
        $chrome = $this->themes->chrome();

        $cap        = $sb->capSize();
        $horizontal = $sb->orientation === ScrollOrientation::Horizontal;
        [$thumbOffset, $thumbSize, $trackLen] = $sb->getThumbBounds();

        $pressed     = $sb->getPressedRegion();
        $lessPressed = $pressed === ScrollBar::REGION_LESS;
        $morePressed = $pressed === ScrollBar::REGION_MORE;

        $outer   = Rect::of($sb->x, $sb->y, $sb->width, $sb->height);
        $lessDir = $horizontal ? Direction::Left : Direction::Up;
        $moreDir = $horizontal ? Direction::Right : Direction::Down;

        // Mac OS parks both arrows at the far end; everything else puts one at
        // each end. The widget hit-tests against the same arrangement.
        $together = $sb->trackOffset() === 0;

        if ($horizontal) {
            $track   = $together ? $outer->insetEach(0, 0, 2 * $cap, 0) : $outer->insetEach($cap, 0, $cap, 0);
            $moreCap = $outer->rightSlice($cap);
            $lessCap = $together
                ? $outer->rightSlice(2 * $cap)->leftSlice($cap)
                : $outer->leftSlice($cap);
            $thumb   = Rect::of($outer->x + $sb->trackOffset() + $thumbOffset, $outer->y, $thumbSize, $outer->height);
        } else {
            $track   = $together ? $outer->insetEach(0, 0, 0, 2 * $cap) : $outer->insetEach(0, $cap, 0, $cap);
            $moreCap = $outer->bottomSlice($cap);
            $lessCap = $together
                ? $outer->bottomSlice(2 * $cap)->topSlice($cap)
                : $outer->topSlice($cap);
            $thumb   = Rect::of($outer->x, $outer->y + $sb->trackOffset() + $thumbOffset, $outer->width, $thumbSize);
        }

        // A thumb that would fill the whole track means there's nothing to
        // scroll; the theme decides how an idle bar should look.
        $scrollable = $thumbSize > 0 && $thumbSize < $trackLen;

        $chrome->scrollTrack($r, $track, $horizontal, $scrollable);

        $this->paintCap($r, $lessCap, $lessDir, $lessPressed, $scrollable);
        $this->paintCap($r, $moreCap, $moreDir, $morePressed, $scrollable);

        if ($scrollable) {
            $chrome->scrollThumb($r, $thumb, $horizontal);
        }
    }

    /** Paints the cap. */
    private function paintCap(
        Renderer $r,
        Rect $rect,
        Direction $direction,
        bool $pressed,
        bool $scrollable,
    ): void {
        $chrome  = $this->themes->chrome();
        $palette = $this->themes->palette();

        $state = match (true) {
            !$scrollable => ControlState::Disabled,
            $pressed     => ControlState::Pressed,
            default      => ControlState::Normal,
        };

        $chrome->button($r, $rect, $state);

        $shift = $pressed ? $this->themes->metrics()->pressOffset : 0;
        $chrome->arrow(
            $r,
            $rect->shift($shift, $shift),
            $direction,
            $scrollable ? $palette->text : $palette->textDisabled,
        );
    }
}
