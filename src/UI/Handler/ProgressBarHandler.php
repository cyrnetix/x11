<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use React\EventLoop\TimerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Painter\ProgressBarPainter;
use Cyrnetix\X11\UI\Widget\ProgressBar;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * ProgressBar — paint-only for input. The marquee animation lives here
 * because it needs the event loop: a single periodic timer ticks at ~20
 * fps, walks the tree, advances every marquee bar's offset, and asks for
 * a redraw. The timer is lazily started the first time paint() sees a
 * marquee bar and self-cancels on the first tick that finds no marquees
 * left — so app code never has to wire start/stop calls.
 */
final class ProgressBarHandler extends WidgetHandler
{
    /** Marquee timer tick — pixels per tick + interval. */
    private const TICK_PX       = 3;
    private const TICK_INTERVAL = 0.05;   // 20 fps

    private ?TimerInterface $marqueeTimer = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree         $tree,
        private readonly X11Client          $client,
        private readonly ProgressBarPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof ProgressBar) return false;
        $this->painter->paint($w, $r);
        if ($w->isMarquee()) $this->ensureMarqueeTimer();
        return true;
    }

    /** Starts the marquee animation timer, if a bar needs one and it is not already running. */
    private function ensureMarqueeTimer(): void
    {
        if ($this->marqueeTimer !== null) return;

        $this->marqueeTimer = $this->client->getLoop()->addPeriodicTimer(
            self::TICK_INTERVAL,
            function (): void {
                $ticked = [];
                $this->tree->visit(function (Widget $w) use (&$ticked): void {
                    if ($w instanceof ProgressBar && $w->isMarquee()) {
                        $w->tickMarquee(self::TICK_PX);
                        $ticked[] = $w;
                    }
                });

                if ($ticked !== []) {
                    // Several times a second, for ever: this is the one repaint
                    // that never stops, so it repaints only the bars.
                    $this->repaint($this->client, $ticked);
                    return;
                }

                // No marquees left — stop burning cycles. paint() will spin
                // the timer back up if marquee is re-enabled later.
                if ($this->marqueeTimer !== null) {
                    $this->client->getLoop()->cancelTimer($this->marqueeTimer);
                    $this->marqueeTimer = null;
                }
            }
        );
    }
}
