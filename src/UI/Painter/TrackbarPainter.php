<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Trackbar;

/**
 * Trackbar (slider): a groove along the value axis, tick marks on the far side,
 * and a thumb. The painter places all three; the theme draws them — which is
 * where the eras part company, since Windows' thumb is a pentagon pointing at
 * the ticks and Motif's is a plain bevelled block.
 *
 * Horizontal layout keeps the groove in the upper half with ticks below;
 * vertical mirrors it into the left half with ticks to the right.
 */
final class TrackbarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Trackbar $tb, Renderer $r): void
    {
        $tb->isHorizontal()
            ? $this->paintHorizontal($tb, $r)
            : $this->paintVertical($tb, $r);
    }

    /** Paints the horizontal. */
    private function paintHorizontal(Trackbar $tb, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $thumbTop = $tb->y + 1;

        $chrome->sliderTrack($r, Rect::of(
            $tb->x + 1,
            $thumbTop + intdiv($m->trackbarThumbShort - $m->trackThickness, 2),
            $tb->width - 2,
            $m->trackThickness,
        ), true);

        $this->paintTicks($tb, $r, $thumbTop + $m->trackbarThumbShort + $m->tickGap, true);

        $chrome->sliderThumb($r, Rect::fromArray($tb->thumbBounds()), Direction::Down, $tb->isFocused());
    }

    /** Paints the vertical. */
    private function paintVertical(Trackbar $tb, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $thumbLeft = $tb->x + 1;

        $chrome->sliderTrack($r, Rect::of(
            $thumbLeft + intdiv($m->trackbarThumbShort - $m->trackThickness, 2),
            $tb->y + 1,
            $m->trackThickness,
            $tb->height - 2,
        ), false);

        $this->paintTicks($tb, $r, $thumbLeft + $m->trackbarThumbShort + $m->tickGap, false);

        $chrome->sliderThumb($r, Rect::fromArray($tb->thumbBounds()), Direction::Right, $tb->isFocused());
    }

    /**
     * Ticks every tickFrequency values, plus one forced at the maximum so the
     * scale always reads as closed even when the range isn't a whole multiple.
     */
    private function paintTicks(Trackbar $tb, Renderer $r, int $offAxis, bool $horizontal): void
    {
        if ($tb->tickFrequency <= 0) return;

        $range = $tb->getMax() - $tb->getMin();
        if ($range <= 0) return;

        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        [$start, $length] = $tb->trackAxisBounds();

        $positions = [];
        for ($v = $tb->getMin(); $v <= $tb->getMax(); $v += $tb->tickFrequency) {
            $positions[] = (int) round($start + (($v - $tb->getMin()) / $range) * $length);
        }
        $positions[] = (int) round($start + $length);

        foreach (array_unique($positions) as $pos) {
            $chrome->tick($r, $horizontal
                ? Rect::of($pos, $offAxis, 1, $m->tickLength)
                : Rect::of($offAxis, $pos, $m->tickLength, 1));
        }
    }
}
