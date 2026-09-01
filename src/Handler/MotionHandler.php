<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Event\X11MotionEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Forwards pointer movement to the widget layer, for hover and drags. */
final class MotionHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly X11Client $client) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11MotionEvent $event): PromiseInterface
    {
        // No logging here — motion events fire at pointer speed and would
        // flood the output. Repaint is the responsibility of whichever
        // widget-level handler actually changes state (drag, hover-toggle,
        // …); the debug overlay's mouse coords just refresh whenever
        // something else triggers a redraw.
        $this->client->updateMouse($event->x, $event->y);

        return resolve(null);
    }
}
