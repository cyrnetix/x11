<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Event\X11UnmapEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Notes that the window has been withdrawn. */
final class UnmapHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly LoggerInterface $logger) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11UnmapEvent $event): PromiseInterface
    {
        $this->logger->info('Window unmapped (hidden / minimized)', [
            'window'        => sprintf('0x%x', $event->windowId),
            'fromConfigure' => $event->fromConfigure,
        ]);

        return resolve(null);
    }
}
