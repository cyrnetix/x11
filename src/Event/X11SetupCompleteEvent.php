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
        /**
         * Byte order the server wants image data in: 0 = LSBFirst, 1 = MSBFirst.
         * It is the server's *own* endianness, so it is LSBFirst on every local
         * x86 server and only differs across a connection to a big-endian one.
         * {@see \Cyrnetix\X11\Drawing\Renderer::putImage()} is the only caller —
         * the pixels in a PutImage are raw memory, not a value the protocol
         * byte-swaps for us.
         */
        public readonly int $imageByteOrder = 0,
        /**
         * Longest request the server accepts, in 4-byte words. A request's
         * length field is 16 bits, so this cannot exceed 65535 without the
         * BIG-REQUESTS extension — which is why a framebuffer blit bigger than
         * about 256 kB has to go out as several PutImages.
         */
        public readonly int $maxRequestLength = 65535,
    ) {}
}
