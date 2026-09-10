<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing\Decoder;

/**
 * A driver that turns an image file into pixels.
 *
 * One implementation per *backend*, not per format — {@see GdDecoder} reads
 * every container GD was compiled with, {@see NativeDecoder} reads the two this
 * package parses by hand. That is the axis that matters, because the question a
 * driver answers is "is this backend here, and can it read this?", and both
 * halves vary at runtime: an extension may be absent, and a present one may
 * have been built without a format.
 *
 * So a driver reports its own capabilities rather than being configured with
 * them, and {@see \Cyrnetix\X11\Drawing\DriverIconLoader} walks a list of them
 * in preference order. Nothing has to be told that GD is missing, and nothing
 * breaks when it is: the next driver that can read the format wins.
 *
 * Adding a backend is one class and one line in the default chain. An Imagick
 * driver is the obvious next one, and it would be *faster* than GD here rather
 * than merely present, because `Imagick::exportImagePixels()` returns the whole
 * raster in one call where GD needs a per-pixel `imagecolorat()`.
 */
interface ImageDecoder
{
    /** Short stable name, for logs and for asking which driver was used. */
    public function id(): string;

    /**
     * Is this backend usable in this process?
     *
     * Checked before {@see formats()} or {@see decode()}, so an implementation
     * may assume its extension exists in those.
     */
    public function isAvailable(): bool;

    /**
     * Lowercase file extensions this driver can read, given what it was built
     * with. Not a fixed list: GD compiled without PNG must not claim `png`.
     *
     * @return list<string>
     */
    public function formats(): array;

    /**
     * Decode $path into pixels at the size it is stored at.
     *
     * $preferredSize is a *hint*, and only a container format uses it: an .ico
     * holds several images and there is no point decoding the 48px one to draw
     * it at 16. A single-image format ignores it, and no driver may rely on the
     * caller having asked for the size it returns — scaling is the caller's.
     *
     * @throws \RuntimeException when the file cannot be read or parsed.
     */
    public function decode(string $path, int $preferredSize): RasterImage;
}
