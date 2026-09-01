<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

/**
 * X11 doesn't ship a DoubleClick event — clients synthesise it by tracking
 * the previous press's time + target + position. Extracted to a tiny service
 * so it can be shared between handlers that care (TreeView label, ListView
 * row) without duplicating the state on each.
 *
 * Returns true when the press is a second click on the same target inside
 * the time + radius window, and *resets* afterwards so a triple-click is
 * treated as click + double + click rather than two overlapping doubles.
 */
final class DoubleClickDetector
{
    public const THRESHOLD_MS  = 500;
    public const RADIUS_PIXELS = 5;

    private mixed $lastTarget = null;
    private int   $lastTime   = 0;
    private int   $lastX      = 0;
    private int   $lastY      = 0;

    /**
     * Whether this click completes a double-click on the same target - close enough in time
     * and in place.
     */
    public function detect(mixed $target, int $time, int $x, int $y): bool
    {
        $isDouble = $this->lastTarget === $target
            && $this->lastTime > 0
            && ($time - $this->lastTime) < self::THRESHOLD_MS
            && ($time - $this->lastTime) >= 0
            && abs($x - $this->lastX) < self::RADIUS_PIXELS
            && abs($y - $this->lastY) < self::RADIUS_PIXELS;

        if ($isDouble) {
            $this->lastTarget = null;
            $this->lastTime   = 0;
        } else {
            $this->lastTarget = $target;
            $this->lastTime   = $time;
            $this->lastX      = $x;
            $this->lastY      = $y;
        }

        return $isDouble;
    }
}
