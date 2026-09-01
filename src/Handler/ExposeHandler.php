<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Event\X11ExposeEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Repaints when the server says part of the window was damaged. */
final class ExposeHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly X11Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11ExposeEvent $event): PromiseInterface
    {
        // X11 batches expose events; only redraw on the last one in each batch.
        if ($event->count > 0) {
            return resolve(null);
        }

        if ($this->client->getDialogWindowId() !== 0
            && $event->windowId === $this->client->getDialogWindowId()) {
            $this->client->redrawDialog();
            return resolve(null);
        }

        if ($this->client->getFileDialogWindowId() !== 0
            && $event->windowId === $this->client->getFileDialogWindowId()) {
            $this->client->redrawFileDialog();
            return resolve(null);
        }

        // An application window of its own.
        $child = $this->client->findChildWindow($event->windowId);
        if ($child !== null) {
            $child->redraw();
            return resolve(null);
        }

        $this->logger->debug('Expose — redrawing window', [
            'window' => sprintf('0x%x', $event->windowId),
            'area'   => "{$event->x},{$event->y} {$event->width}x{$event->height}",
        ]);

        $this->client->redraw();

        return resolve(null);
    }
}
