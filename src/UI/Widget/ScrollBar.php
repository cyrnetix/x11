<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\UI\Event\ScrollChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Scrollbar — one widget, two orientations.
 *
 * The value range is [min .. max - pageSize], i.e. the scroll position
 * represents the start of the visible viewport. pageSize is also used to
 * size the thumb proportionally to the visible chunk.
 */
final class ScrollBar extends Widget
{
    /** Click target inside the scrollbar — used by WidgetManager to route the click. */
    public const REGION_LESS      = 'less';        // up / left arrow button
    public const REGION_MORE      = 'more';        // down / right arrow button
    public const REGION_PAGE_LESS = 'page-less';   // track above / left-of thumb
    public const REGION_PAGE_MORE = 'page-more';   // track below / right-of thumb
    public const REGION_THUMB     = 'thumb';

    private int     $value         = 0;
    private ?string $pressedRegion = null;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        public readonly ScrollOrientation $orientation,
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        public int $min      = 0,
        public int $max      = 100,
        public int $pageSize = 10,
        public readonly int $step     = 1,
    ) {
        parent::__construct($x, $y);
    }

    /** Size of each end cap (arrow button) along the scroll axis. */
    public function capSize(): int
    {
        return $this->metrics()->scrollBarThickness;
    }

    /**
     * Distance from the bar's start to the track's start. Zero when the theme
     * parks both arrows together at the far end (Mac OS 8/9), one cap otherwise.
     */
    public function trackOffset(): int
    {
        return $this->metrics()->scrollBarArrowsTogether ? 0 : $this->capSize();
    }

    /** Resize the value range (e.g. when a backing list grows). Clamps value. */
    public function setRange(int $min, int $max, int $pageSize): void
    {
        $this->min      = $min;
        $this->max      = $max;
        $this->pageSize = $pageSize;

        $upper = max($this->min, $this->max - $this->pageSize);
        $this->value = max($this->min, min($upper, $this->value));
    }

    /** The value. */
    public function getValue(): int             { return $this->value; }
    /** The pressed region. */
    public function getPressedRegion(): ?string { return $this->pressedRegion; }

    /** Set which sub-region is currently held down (or null to clear). */
    public function setPressedRegion(?string $region): void
    {
        $this->pressedRegion = $region;
    }

    /** Returns true if the value actually changed (clamped + de-duped). */
    public function setValue(int $value): bool
    {
        $upper   = max($this->min, $this->max - $this->pageSize);
        $clamped = max($this->min, min($upper, $value));

        if ($clamped === $this->value) return false;

        $this->value = $clamped;
        $this->dispatcher->dispatch(new ScrollChangedEvent($this));
        return true;
    }

    /** Moves the value by this many steps, clamped to the range. */
    public function scrollBy(int $delta): bool
    {
        return $this->setValue($this->value + $delta);
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }

    /**
     * Identify which sub-region of the scrollbar contains (mx, my).
     * Returns null if outside the scrollbar entirely.
     */
    public function hitTestRegion(int $mx, int $my): ?string
    {
        if (!$this->hitTest($mx, $my)) return null;

        [$local, $total] = $this->orientation === ScrollOrientation::Vertical
            ? [$my - $this->y, $this->height]
            : [$mx - $this->x, $this->width];

        $cap = $this->capSize();

        if ($this->metrics()->scrollBarArrowsTogether) {
            // …[ track ][ less ][ more ]
            if ($local >= $total - $cap)       return self::REGION_MORE;
            if ($local >= $total - 2 * $cap)   return self::REGION_LESS;
        } else {
            // [ less ][ track ][ more ]
            if ($local < $cap)                 return self::REGION_LESS;
            if ($local >= $total - $cap)       return self::REGION_MORE;
        }

        [$thumbOffset, $thumbSize] = $this->getThumbBounds();
        $thumbStart = $this->trackOffset() + $thumbOffset;
        $thumbEnd   = $thumbStart + $thumbSize;

        if ($local < $thumbStart) return self::REGION_PAGE_LESS;
        if ($local >= $thumbEnd)  return self::REGION_PAGE_MORE;
        return self::REGION_THUMB;
    }

    /**
     * Thumb geometry along the scroll axis.
     * @return array{int, int, int}  [thumbOffset, thumbSize, trackLen]
     *                               thumbOffset is the gap before the thumb
     *                               inside the track, so the thumb starts at
     *                               metrics()->scrollBarThickness + offset.
     */
    public function getThumbBounds(): array
    {
        $m        = $this->metrics();
        $totalLen = $this->orientation === ScrollOrientation::Vertical ? $this->height : $this->width;
        $trackLen = $totalLen - 2 * $m->scrollBarThickness;
        if ($trackLen <= 0) return [0, 0, 0];

        $range     = max(1, $this->max - $this->min);
        $thumbSize = max($m->scrollBarMinThumb, (int) round($trackLen * $this->pageSize / $range));
        $thumbSize = min($thumbSize, $trackLen);

        $maxOffset = $trackLen - $thumbSize;
        $valRange  = max(1, $this->max - $this->pageSize - $this->min);
        $offset    = $maxOffset > 0
            ? (int) round($maxOffset * ($this->value - $this->min) / $valRange)
            : 0;

        return [$offset, $thumbSize, $trackLen];
    }

    /** Convert a thumb-start offset back into a scroll value (used by drag). */
    public function valueForThumbOffset(int $thumbOffset): int
    {
        [, $thumbSize, $trackLen] = $this->getThumbBounds();
        $maxOffset = $trackLen - $thumbSize;
        if ($maxOffset <= 0) return $this->min;

        $thumbOffset = max(0, min($maxOffset, $thumbOffset));
        $valRange    = max(1, $this->max - $this->pageSize - $this->min);
        return $this->min + (int) round($valRange * $thumbOffset / $maxOffset);
    }
}
