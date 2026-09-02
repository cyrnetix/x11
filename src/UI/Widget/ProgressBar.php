<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\ProgressBarChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style progress bar. A sunken trough filled with bright-blue 7px
 * segments separated by 1px gaps. Supports both determinate mode (value
 * in [min, max]) and marquee mode — an indeterminate animation where a
 * band of segments slides across the trough.
 *
 * Marquee animation is driven by {@see ProgressBarHandler}: the handler
 * owns a lazily-started periodic timer that advances each marquee bar's
 * offset on every tick. Calling setMarquee(true) is enough; no manual
 * tick wiring needed from app code.
 */
final class ProgressBar extends Widget implements Bounded
{
    private int  $value         = 0;
    private int  $min           = 0;
    private int  $max           = 100;
    private bool $marquee       = false;
    private int  $marqueeOffset = 0;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        public readonly ScrollOrientation $orientation = ScrollOrientation::Horizontal,
        int $min = 0,
        int $max = 100,
        int $value = 0,
    ) {
        parent::__construct($x, $y);
        $this->min   = $min;
        $this->max   = max($min, $max);
        $this->value = max($this->min, min($this->max, $value));
    }

    /** The value. */
    public function getValue(): int { return $this->value; }

    /** A trough with a border; nothing shows through. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    
    /** Whether this widget fills its own rectangle, so a repaint of it alone is safe. */
    public function paintsOwnBackground(): bool { return true; }
    /** The min. */
    public function getMin(): int   { return $this->min; }
    /** The max. */
    public function getMax(): int   { return $this->max; }

    /** Sets value. */
    public function setValue(int $value): bool
    {
        $clamped = max($this->min, min($this->max, $value));
        if ($clamped === $this->value) return false;
        $this->value = $clamped;
        $this->dispatcher->dispatch(new ProgressBarChangedEvent($this));
        return true;
    }

    /** Re-bound the range; the current value clamps to the new range. */
    public function setRange(int $min, int $max): void
    {
        $this->min = $min;
        $this->max = max($min, $max);
        $clamped   = max($this->min, min($this->max, $this->value));
        if ($clamped !== $this->value) {
            $this->value = $clamped;
            $this->dispatcher->dispatch(new ProgressBarChangedEvent($this));
        }
    }

    /** Moves the value by $delta, clamped to the range. False if it did not move. */
    public function step(int $delta): bool { return $this->setValue($this->value + $delta); }

    // ---- Marquee --------------------------------------------------------

    /** Whether it is marquee. */
    public function isMarquee(): bool             { return $this->marquee; }
    /** The marquee offset. */
    public function getMarqueeOffset(): int       { return $this->marqueeOffset; }

    /** Sets marquee. */
    public function setMarquee(bool $marquee): void
    {
        if ($this->marquee === $marquee) return;
        $this->marquee       = $marquee;
        $this->marqueeOffset = 0;
    }

    /** Advance the marquee animation by $delta pixels (called by the handler). */
    public function tickMarquee(int $delta = 3): void
    {
        // The painter handles the wrap modulo, so we can grow unbounded here.
        // Wrap at a large value to keep the int from overflowing on long runs.
        $this->marqueeOffset = ($this->marqueeOffset + $delta) % 1_000_000;
    }

    // ---- Geometry -------------------------------------------------------

    /** Inner trough rectangle (inside the sunken border). */
    public function troughBounds(): array
    {
        $border = $this->metrics()->progressBorder;

        return [
            $this->x + $border,
            $this->y + $border,
            $this->width  - 2 * $border,
            $this->height - 2 * $border,
        ];
    }

    /**
     * Number of pixels along the bar's filled axis that should be coloured.
     * Painter consumes this; rendering the trailing partial segment is the
     * painter's call.
     */
    public function filledPixels(): int
    {
        [$_, $__, $innerW, $innerH] = $this->troughBounds();
        $length = $this->orientation === ScrollOrientation::Horizontal ? $innerW : $innerH;
        $range  = $this->max - $this->min;
        if ($range <= 0) return 0;
        $norm = ($this->value - $this->min) / $range;
        return (int) round($norm * $length);
    }
}
