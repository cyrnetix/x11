<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Dispatcher;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * A PSR-14 listener provider: callables keyed by event class, priority-sorted.
 *
 * Shared by both dispatchers — the async one for X11 events and the synchronous
 * one for UI events — so an application registers listeners in one place whatever
 * kind of event they are for.
 */
final class ListenerRegistry implements ListenerProviderInterface
{
    /** @var array<string, list<array{listener: callable, priority: int}>> */
    private array $listeners = [];

    /** Adds a listener. */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = ['listener' => $listener, 'priority' => $priority];
        usort(
            $this->listeners[$eventClass],
            static fn(array $a, array $b): int => $b['priority'] <=> $a['priority'],
        );
    }

    /** The listeners for event. */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $class => $entries) {
            if ($event instanceof $class) {
                foreach ($entries as $entry) {
                    yield $entry['listener'];
                }
            }
        }
    }
}
