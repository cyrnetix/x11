<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * A key coming up.
 *
 * The same 32-byte record as {@see X11KeyPressEvent}, and the window has always
 * asked for these — `KeyReleaseMask` is in the event mask — but nothing parsed
 * them, so they arrived and were dropped. A form does not need them: a widget
 * cares that a character was typed, not that a finger came off a key. Anything
 * that has to know a key is *being held* does, which is why this exists.
 *
 * **Core X11 auto-repeat sends these even while the key is held down.** A held
 * key produces press, release, press, release, … so a naive "keys currently
 * down" set flickers empty between repeats. Suppressing that needs the XKB
 * extension's detectable auto-repeat, which this client does not speak, so a
 * caller tracking held keys has to tolerate it — see `HeldKeys` in the
 * phpx11doom project, which ignores a release that a press immediately follows.
 */
final class X11KeyReleaseEvent extends AbstractX11Event
{
    /** Records the key, where the pointer was, and the modifier mask. */
    public function __construct(
        public readonly int $windowId,
        public readonly int $keycode,
        public readonly int $x,
        public readonly int $y,
        public readonly int $rootX,
        public readonly int $rootY,
        public readonly int $time,
        public readonly int $state,
    ) {}
}
