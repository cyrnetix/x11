<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use React\EventLoop\TimerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\UI\Painter\UpDownPainter;
use Cyrnetix\X11\UI\Widget\UpDown;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * UpDown spinner — press fires one step + schedules a 400ms initial delay
 * → 80ms repeat timer, same shape as Windows. Release cancels.
 */
final class UpDownHandler extends WidgetHandler
{
    private ?UpDown         $captured    = null;
    private ?TimerInterface $repeatTimer = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree    $tree,
        private readonly X11Client     $client,
        private readonly UpDownPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof UpDown) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $ud = $this->findHit($event->x, $event->y);
        if ($ud === null) return false;

        $region = $ud->hitTestRegion($event->x, $event->y);
        if ($region === null) return true;

        $this->captured = $ud;
        $ud->setPressedRegion($region);
        $ud->step($region);
        $this->client->redraw();

        $loop = $this->client->getLoop();
        $this->repeatTimer = $loop->addTimer(0.4, function () use ($ud, $region, $loop): void {
            $this->repeatTimer = $loop->addPeriodicTimer(0.08, function () use ($ud, $region): void {
                if ($this->captured !== $ud) return;
                if (!$ud->step($region)) return;
                $this->client->redraw();
            });
        });

        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;
        $this->stopRepeat();
        $this->captured->setPressedRegion(null);
        $this->captured = null;
        $this->client->redraw();
        return true;
    }

    /** Cancels the auto-repeat timer a held arrow started. */
    private function stopRepeat(): void
    {
        if ($this->repeatTimer !== null) {
            $this->client->getLoop()->cancelTimer($this->repeatTimer);
            $this->repeatTimer = null;
        }
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?UpDown
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof UpDown && $w->hitTest($mx, $my)
        );
        return $found instanceof UpDown ? $found : null;
    }
}
