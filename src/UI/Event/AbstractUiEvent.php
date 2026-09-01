<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

/**
 * Base for the events widgets raise.
 *
 * A marker with no members, like its X11 counterpart. These go out through
 * {@see \Cyrnetix\X11\UI\SyncEventDispatcher}, which dispatches inline —
 * they originate *inside* an already-serialised X11 handler, so queuing them
 * would only reorder them.
 */
abstract class AbstractUiEvent {}
