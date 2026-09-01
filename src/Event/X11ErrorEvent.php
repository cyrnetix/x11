<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * The server rejected a request.
 *
 * Carries the error code, the failing request's opcode and its sequence number —
 * enough to find which call was wrong, which matters when every request is a
 * hand-packed byte string.
 */
final class X11ErrorEvent extends AbstractX11Event
{
    /** Records the error code, the request that failed and its sequence number. */
    public function __construct(
        public readonly int $errorCode,
        public readonly int $majorOpcode,
        public readonly int $minorOpcode,
        public readonly int $badValue,
        public readonly int $sequence,
    ) {}
}
