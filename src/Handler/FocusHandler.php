<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Event\X11FocusInEvent;
use Cyrnetix\X11\Event\X11FocusOutEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/**
 * Handles both FocusIn and FocusOut — register this instance for both event
 * classes and it will update the client's focus state accordingly.
 */
final class FocusHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly X11Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11FocusInEvent|X11FocusOutEvent $event): PromiseInterface
    {
        $gained = $event instanceof X11FocusInEvent;

        $this->logger->info($gained ? 'Window gained focus' : 'Window lost focus');

        $this->client->updateFocus($gained);
        $this->client->redraw();

        return resolve(null);
    }
}
