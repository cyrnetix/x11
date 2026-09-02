<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * Base for the events the X server sends us.
 *
 * A marker with no members: the parser returns concrete subclasses and listeners
 * are registered per class, so nothing needs a shared shape. Every one of these
 * is built by {@see \Cyrnetix\X11\Protocol\EventParser} from a 32-byte record.
 */
abstract class AbstractX11Event {}
