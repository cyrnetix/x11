<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Event\X11ErrorEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Logs an X11 protocol error: the code, the opcode that caused it and its sequence number. */
final class ErrorHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(private readonly LoggerInterface $logger) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11ErrorEvent $event): PromiseInterface
    {
        $this->logger->error('X11 protocol error', [
            'code'     => $event->errorCode,
            'major'    => $event->majorOpcode,
            'minor'    => $event->minorOpcode,
            'badValue' => sprintf('0x%x', $event->badValue),
            'seq'      => $event->sequence,
        ]);

        return resolve(null);
    }
}
