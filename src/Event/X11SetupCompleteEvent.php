<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Event;

/**
 * The connection handshake finished and the server's details are known.
 *
 * Nothing may create a window, font or graphics context before this: resource ids
 * are allocated from the range the setup record hands back.
 */
final class X11SetupCompleteEvent extends AbstractX11Event
{
    /** Records what the setup reply said: the root window, the resource id range, the screen and the visual. */
    public function __construct(
        public readonly int $root,
        public readonly int $ridBase,
        public readonly int $screenWidth,
        public readonly int $screenHeight,
        public readonly int $depth,
        public readonly int $visual,
        /**
         * A 32-bit TrueColor visual on this screen, or 0 when the server has
         * none. Its spare 8 bits are an alpha channel, which is the only way to
         * get a genuinely see-through window: the SHAPE extension stops the
         * server *drawing* outside the shape, but under a compositor the pixels
         * it left alone read back as opaque black rather than as a hole.
         */
        public readonly int $argbVisual = 0,
    ) {}
}
