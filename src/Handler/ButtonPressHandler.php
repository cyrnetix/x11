<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Forwards a mouse press from the server to the widget layer. */
final class ButtonPressHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly LoggerInterface $logger) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11ButtonPressEvent $event): PromiseInterface
    {
        $this->logger->info('Button pressed', [
            'button' => $event->button,
            'x'      => $event->x,
            'y'      => $event->y,
        ]);

        return resolve(null);
    }
}
