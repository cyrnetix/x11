<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing\Decoder;

use Cyrnetix\X11\Drawing\IcoLoader;
use Cyrnetix\X11\Drawing\PngLoader;

/**
 * The driver that needs nothing installed: this package's own PNG and ICO
 * parsers, in plain PHP over `unpack()` and zlib.
 *
 * It is last in the default chain and it is the reason the chain can have a
 * last entry at all — {@see isAvailable()} is unconditionally true, so icons
 * work on a bare PHP with no image extension whatever. That is the same bargain
 * the rest of this package makes: it speaks the X11 wire protocol rather than
 * requiring Xlib, so requiring GD to show a folder icon would be out of
 * character.
 *
 * It is also the only driver that reads **.ico**, because GD cannot and Imagick
 * would only do so via a delegate. The Windows 2000 set is entirely .ico, so
 * this driver is not a fallback for that half of the shipped resources — it is
 * the implementation.
 *
 * Being slow is the trade. Hand-written inflate-and-unfilter is ~89% of the
 * cost of loading a PNG here, which is what makes {@see GdDecoder} worth having
 * in front of it.
 */
final class NativeDecoder implements ImageDecoder
{
    public function __construct(
        private readonly PngLoader $png = new PngLoader(),
        private readonly IcoLoader $ico = new IcoLoader(),
    ) {}

    /** {@inheritDoc} */
    public function id(): string { return 'native'; }

    /**
     * Always. Plain PHP with zlib, which any build running this toolkit has —
     * and the chain needs one driver that cannot decline.
     */
    public function isAvailable(): bool { return true; }

    /** {@inheritDoc} */
    public function formats(): array { return ['png', 'ico']; }

    /** {@inheritDoc} */
    public function decode(string $path, int $preferredSize): RasterImage
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'ico'   => $this->ico->raster($path, $preferredSize),
            'png'   => $this->png->raster($path),
            default => throw new \RuntimeException("NativeDecoder cannot read .$extension: $path"),
        };
    }
}
