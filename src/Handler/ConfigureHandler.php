<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Keeps the client's idea of the window's position and size in step with the server's. */
final class ConfigureHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly X11Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11ConfigureEvent $event): PromiseInterface
    {
        $this->logger->info('Window configured', [
            'x'      => $event->x,
            'y'      => $event->y,
            'width'  => $event->width,
            'height' => $event->height,
        ]);

        $this->client->updateGeometry($event->x, $event->y, $event->width, $event->height);
        $this->client->redraw();

        return resolve(null);
    }
}
