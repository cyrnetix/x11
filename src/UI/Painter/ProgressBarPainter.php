<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\ProgressBar;
use Cyrnetix\X11\UI\Widget\ScrollOrientation;

/**
 * Progress bar. The painter works out which slice of the trough is "done" —
 * including the travelling band in marquee mode — and the theme decides whether
 * that slice reads as Windows' segment stack or one continuous gauge.
 *
 * Vertical bars fill bottom-up, like the originals.
 */
final class ProgressBarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(ProgressBar $bar, Renderer $r): void
    {
        $chrome = $this->themes->chrome();

        $outer = Rect::of($bar->x, $bar->y, $bar->width, $bar->height);
        $chrome->edge($r, $outer, Edge::Sunken);

        $trough = Rect::fromArray($bar->troughBounds());
        $chrome->fill($r, $trough, Surface::Track);

        $horizontal = $bar->orientation === ScrollOrientation::Horizontal;

        if ($bar->isMarquee()) {
            $this->paintMarquee($bar, $r, $trough, $horizontal);
            return;
        }

        $filled = $bar->filledPixels();
        if ($filled <= 0) return;

        $chrome->progressFill(
            $r,
            $horizontal ? $trough->leftSlice($filled) : $trough->bottomSlice($filled),
            $horizontal,
        );
    }

    /**
     * Marquee: a band that enters from one side and leaves the other. Its
     * length is the theme's segment stride times the segment count, so a
     * continuous-fill theme still gets a sensibly-sized travelling block.
     */
    private function paintMarquee(ProgressBar $bar, Renderer $r, Rect $trough, bool $horizontal): void
    {
        $m = $this->themes->metrics();

        $length = $horizontal ? $trough->width : $trough->height;
        $band   = $m->progressMarqueeSegments * $m->progressSegmentStep();

        // One cycle carries the band all the way across and fully off again.
        $cycle = $length + $band;
        if ($cycle <= 0) return;

        $pos       = $bar->getMarqueeOffset() % $cycle;
        $bandStart = $pos - $band;

        $clipStart = max(0, $bandStart);
        $clipEnd   = min($length, $bandStart + $band);
        if ($clipEnd <= $clipStart) return;

        $span = $clipEnd - $clipStart;
        $rect = $horizontal
            ? Rect::of($trough->x + $clipStart, $trough->y, $span, $trough->height)
            // Vertical animation rides bottom-up to match the determinate fill.
            : Rect::of($trough->x, $trough->y + $trough->height - $clipEnd, $trough->width, $span);

        $this->themes->chrome()->progressFill($r, $rect, $horizontal);
    }
}
