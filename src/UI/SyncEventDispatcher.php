<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

use Psr\EventDispatcher\EventDispatcherInterface;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;

/**
 * Dispatches UI events inline, in the caller's stack.
 *
 * Deliberately not queued. These events are raised *from inside* an X11 handler
 * that is already serialised, so queuing them would only introduce reordering —
 * a click's listener would run after the next event instead of during this one.
 */
final class SyncEventDispatcher implements EventDispatcherInterface
{
    /** Records the registry. */
    public function __construct(private readonly ListenerRegistry $registry) {}

    /** {@inheritDoc} */
    public function dispatch(object $event): object
    {
        foreach ($this->registry->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        return $event;
    }
}
