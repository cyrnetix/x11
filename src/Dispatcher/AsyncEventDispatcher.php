<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Dispatcher;

use Clue\React\Mq\Queue;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/**
 * PSR-14 compatible event dispatcher backed by a clue/mq-react Queue.
 *
 * Events are serialized through a Queue (concurrency=1) so handlers always
 * run in arrival order and never overlap, preserving X11 protocol ordering.
 * Each listener may return a Promise for async work; the dispatcher chains
 * them sequentially before dequeuing the next event.
 */
final class AsyncEventDispatcher implements EventDispatcherInterface
{
    private readonly Queue $queue;

    /** Takes a logger. */
    public function __construct(
        private readonly ListenerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
        $this->queue = new Queue(1, null, function (object $event): PromiseInterface {
            return $this->processEvent($event);
        });
    }

    /**
     * PSR-14 synchronous dispatch — queues the event and returns it immediately.
     * Processing happens asynchronously in the ReactPHP event loop.
     */
    public function dispatch(object $event): object
    {
        $this->dispatchAsync($event)->then(null, function (\Throwable $e) use ($event): void {
            $this->logger->error('Unhandled event dispatch error', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        });

        return $event;
    }

    /**
     * Async dispatch — returns a Promise that resolves when all listeners for
     * the event have finished (including any async work they perform).
     */
    public function dispatchAsync(object $event): PromiseInterface
    {
        return ($this->queue)($event);
    }

    /** Runs every listener for one event, in priority order, awaiting any that return a promise. */
    private function processEvent(object $event): PromiseInterface
    {
        $listeners = [];
        foreach ($this->registry->getListenersForEvent($event) as $listener) {
            $listeners[] = $listener;
        }

        $chain = resolve(null);
        foreach ($listeners as $listener) {
            $chain = $chain->then(function () use ($listener, $event): PromiseInterface {
                try {
                    $result  = $listener($event);
                    $promise = $result instanceof PromiseInterface ? $result : resolve($result);

                    return $promise->then(null, function (\Throwable $e) use ($event): void {
                        $this->logger->warning('Listener promise rejected', [
                            'event' => $event::class,
                            'error' => $e->getMessage(),
                        ]);
                    });
                } catch (\Throwable $e) {
                    $this->logger->warning('Listener threw', [
                        'event' => $event::class,
                        'error' => $e->getMessage(),
                    ]);
                    return resolve(null);
                }
            });
        }

        return $chain;
    }
}
