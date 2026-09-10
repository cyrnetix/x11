<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing\Decoder;

use GdImage;
use RuntimeException;

/**
 * Decodes through the GD extension, which is C and therefore ~12x faster at the
 * part that dominates: inflating a PNG and undoing its scanline filters is 89%
 * of what {@see NativeDecoder} spends on a 64x64 icon, and GD does it in a
 * hundredth of the time. Measured end to end through
 * {@see \Cyrnetix\X11\Drawing\IconRegistry}, a Mac-set icon goes from ~27 ms to
 * ~3.5 ms.
 *
 * Two things about GD are worth knowing, because both shape this class:
 *
 * **Its buffer is opaque.** There is no way to ask GD for a whole raster in one
 * call — `imagegd()` is disabled in many builds, and `imagebmp()` drops the
 * alpha channel, which for icons is the one channel that matters. So the pixels
 * come out through `imagecolorat()` one at a time, and *that* is now the
 * dominant cost here rather than the decode. It is still a fifth of what the
 * hand-written parser costs, but it is why a future Imagick driver would beat
 * this one: `exportImagePixels()` returns the lot in a single call.
 *
 * **Its alpha is 7-bit.** GD stores 0-127 with 0 meaning opaque, so an 8-bit
 * PNG alpha does not survive the round trip exactly: 200 comes back as 201.
 * Fully opaque and fully transparent are exact, which is what almost every
 * icon pixel is, and the toolkit thresholds coverage anyway — but a pixel whose
 * averaged coverage sits within one of the threshold can fall on the other side
 * from the native driver's. `tests/icon_driver_test.php` measures how many
 * actually do across every shipped icon rather than leaving it as a worry.
 */
final class GdDecoder implements ImageDecoder
{
    /**
     * Which `gd_info()` capability gates each extension. Read at runtime rather
     * than hardcoded, because a GD built without PNG must not claim `png` and
     * silently fail every icon — the chain can only fall through to the next
     * driver if this one declines honestly.
     *
     * `ico` is deliberately absent: GD cannot read it at all.
     *
     * @var array<string, string>
     */
    private const CAPABILITY = [
        'png'  => 'PNG Support',
        'gif'  => 'GIF Read Support',
        'jpg'  => 'JPEG Support',
        'jpeg' => 'JPEG Support',
        'webp' => 'WebP Support',
        'bmp'  => 'BMP Support',
        'avif' => 'AVIF Support',
        'xpm'  => 'XPM Support',
        'xbm'  => 'XBM Support',
    ];

    /** {@inheritDoc} */
    public function id(): string { return 'gd'; }

    /** {@inheritDoc} */
    public function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /** {@inheritDoc} */
    public function formats(): array
    {
        if (!$this->isAvailable()) return [];

        $info    = gd_info();
        $formats = [];
        foreach (self::CAPABILITY as $extension => $capability) {
            if (($info[$capability] ?? false) === true) $formats[] = $extension;
        }

        return $formats;
    }

    /** {@inheritDoc} */
    public function decode(string $path, int $preferredSize): RasterImage
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Cannot read image file: $path");
        }

        // From the string rather than imagecreatefrompng(): GD sniffs the
        // container itself, so one call covers every format formats() claims.
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw new RuntimeException("GD could not decode: $path");
        }

        try {
            return $this->toRaster($image);
        } finally {
            imagedestroy($image);
        }
    }

    /** Read every pixel out of a GD image as straight 8-bit RGBA. */
    private function toRaster(GdImage $image): RasterImage
    {
        // A palette image returns *indices* from imagecolorat, not colours, so
        // convert first rather than branching per pixel in the hot loop.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $argb = imagecolorat($image, $x, $y);

                // GD's alpha is 7 bits and inverted: 0 opaque, 127 clear.
                $row[] = [
                    ($argb >> 16) & 0xFF,
                    ($argb >> 8) & 0xFF,
                    $argb & 0xFF,
                    intdiv((127 - (($argb >> 24) & 0x7F)) * 255 + 63, 127),
                ];
            }
            $pixels[] = $row;
        }

        return new RasterImage($width, $height, $pixels);
    }
}
