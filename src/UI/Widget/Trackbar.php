<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\TrackbarChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style trackbar (slider). A thin sunken channel with a raised
 * pentagonal thumb the user drags between min and max; optional tick
 * marks sit on the side opposite the thumb's chevron.
 *
 * Implements Focusable so keyboard nav works after a click:
 *   Left / Down       value -= step
 *   Right / Up        value += step
 *   PgUp              value -= pageSize
 *   PgDn              value += pageSize
 *   Home              value = min
 *   End               value = max
 *
 *   ╭──────────────────────────╮
 *   │   ┌──┐                   │   ← raised thumb
 *   │═══│  │═══════════════════│   ← sunken track channel
 *   │   └▽─┘                   │   ← chevron points toward ticks
 *   │ │ │ │ │ │ │ │ │ │ │ │ │ ││   ← tick marks every tickFrequency
 *   ╰──────────────────────────╯
 */
final class Trackbar extends Widget implements Focusable, Bounded
{
    private int     $value         = 0;
    private bool    $focused       = false;
    private bool    $dragging      = false;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        public readonly ScrollOrientation $orientation = ScrollOrientation::Horizontal,
        private int  $min            = 0,
        private int  $max            = 100,
        int           $value         = 0,
        public int   $step           = 1,
        public int   $pageSize       = 10,
        public int   $tickFrequency  = 10,
    ) {
        parent::__construct($x, $y);
        $this->max   = max($min, $max);
        $this->value = max($min, min($this->max, $value));
    }

    // ---- Value / range ---------------------------------------------------

    /** The value. */
    public function getValue(): int { return $this->value; }
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
        $this->dispatcher->dispatch(new TrackbarChangedEvent($this));
        return true;
    }

    /** Sets range. */
    public function setRange(int $min, int $max): void
    {
        $this->min = $min;
        $this->max = max($min, $max);
        $clamped   = max($this->min, min($this->max, $this->value));
        if ($clamped !== $this->value) {
            $this->value = $clamped;
            $this->dispatcher->dispatch(new TrackbarChangedEvent($this));
        }
    }

    /** Whether it is dragging. */
    public function isDragging(): bool         { return $this->dragging; }
    /** Sets dragging. */
    public function setDragging(bool $d): void { $this->dragging = $d; }

    // ---- Geometry -------------------------------------------------------

    /** Whether it is horizontal. */
    public function isHorizontal(): bool
    {
        return $this->orientation === ScrollOrientation::Horizontal;
    }

    /**
     * Pixel start + length of the track ALONG the value axis. The thumb's
     * centre slides between trackStart + THUMB_LONG/2 and
     * trackStart + length - THUMB_LONG/2 so the thumb never overflows.
     *
     * @return array{int, int}  [start, length]
     */
    public function trackAxisBounds(): array
    {
        $long = $this->metrics()->trackbarThumbLong;

        if ($this->isHorizontal()) {
            return [$this->x + $long / 2, $this->width - $long];
        }
        return [$this->y + $long / 2, $this->height - $long];
    }

    /** Thumb rectangle in widget-absolute coords. */
    public function thumbBounds(): array
    {
        $m     = $this->metrics();
        $long  = $m->trackbarThumbLong;
        $short = $m->trackbarThumbShort;

        [$start, $length] = $this->trackAxisBounds();
        $range   = $this->max - $this->min;
        $norm    = $range > 0 ? ($this->value - $this->min) / $range : 0;
        $axisPos = (int) round($start + $norm * $length - $long / 2);

        if ($this->isHorizontal()) {
            return [$axisPos, $this->y + 1, $long, $short];
        }
        return [$this->x + 1, $axisPos, $short, $long];
    }

    /** Inverse of thumb-position math: a mouse coord along the value axis → a value. */
    public function pixelToValue(int $coord): int
    {
        [$start, $length] = $this->trackAxisBounds();
        if ($length <= 0) return $this->min;
        $range = $this->max - $this->min;
        $norm  = max(0.0, min(1.0, ($coord - $start) / $length));
        return $this->min + (int) round($norm * $range);
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }

    /** Which thumb is at these coordinates, if any. */
    public function hitTestThumb(int $mx, int $my): bool
    {
        [$tx, $ty, $tw, $th] = $this->thumbBounds();
        return $mx >= $tx && $mx < $tx + $tw && $my >= $ty && $my < $ty + $th;
    }

    // ---- Focusable ------------------------------------------------------

    /** Whether it is focused. */
    public function isFocused(): bool                  { return $this->focused; }

    /** No disabled state, so always. */
    public function canTakeFocus(): bool { return true; }

    /** The whole control, ticks included. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    /** A track and a thumb drawn on the surface, not a filled panel. */
    public function paintsOwnBackground(): bool { return false; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void    { $this->focused = $focused; }
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool { return $this->hitTest($mx, $my); }
    /** {@inheritDoc} */
    public function handleKey(string $key): bool       { return false; }
    /** The copy. */
    public function copy(): ?string                    { return (string) $this->value; }
    /** {@inheritDoc} */
    public function paste(string $text): void
    {
        if (ctype_digit(trim($text, "-"))) {
            $this->setValue((int) $text);
        }
    }
}
