<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

use Cyrnetix\X11\Drawing\Decoder\RasterImage;

/**
 * Parses Windows .ico files into {@see Icon} value objects.
 *
 * ICO layout (little-endian):
 *   ICONDIR     6 bytes      reserved(2)=0, type(2)=1, count(2)
 *   ICONDIRENTRY[count]      16 bytes each — width/height/bpp/offset/size
 *   image data per entry     BITMAPINFOHEADER (40) + palette + XOR + AND mask
 *
 * The "height" inside each image's BITMAPINFOHEADER is icon_height * 2
 * because both the XOR (colour) mask and the AND (transparency) mask are
 * stored bottom-up.
 *
 * Supports 4-bit and 8-bit indexed (the format your Win2k icons use)
 * plus 24-bit and 32-bit direct. PNG-encoded ICO entries (Vista+) are
 * rejected.
 */
final class IcoLoader implements IconLoader
{
    /**
     * The policy {@see load()} applies. A pass-through whenever the container
     * held the size asked for, which every set shipped here does — an .ico's
     * alpha is already all-or-nothing, so there is nothing for the threshold to
     * do either.
     */
    private readonly IconScaler $scaler;

    /** Picks the variant closest to this size, preferring higher bpp on ties. */
    public function __construct(public readonly int $preferredSize = 16)
    {
        $this->scaler = new IconScaler($preferredSize);
    }

    /** {@inheritDoc} */
    public function load(string $path): Icon
    {
        return $this->scaler->toIcon($this->raster($path, $this->preferredSize));
    }

    /**
     * The entry nearest $preferredSize, at its own size, with no policy applied.
     *
     * An .ico is a *container*: it already holds the icon at several sizes, so
     * the right answer is to pick one rather than to scale. That is why this
     * takes the size as an argument where {@see PngLoader::raster()} does not —
     * and why running the result through {@see IconScaler} is a pass-through
     * whenever the container had the size that was asked for, which every set
     * shipped with this package does.
     *
     * @throws \RuntimeException when the file cannot be read or parsed.
     */
    public function raster(string $path, int $preferredSize): RasterImage
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Cannot read ICO file: $path");
        }

        return $this->parse($data, $path, $preferredSize);
    }

    /** Reads the .ico directory and decodes the entry closest to the size asked for. */
    private function parse(string $data, string $path, int $preferredSize): RasterImage
    {
        if (strlen($data) < 6) {
            throw new \RuntimeException("ICO too short: $path");
        }
        $hdr = unpack('vreserved/vtype/vcount', substr($data, 0, 6));
        if ($hdr['type'] !== 1) {
            throw new \RuntimeException("Not an icon (type {$hdr['type']}): $path");
        }
        if ($hdr['count'] === 0) {
            throw new \RuntimeException("ICO has no entries: $path");
        }

        $entries = [];
        for ($i = 0; $i < $hdr['count']; $i++) {
            $eOffset = 6 + $i * 16;
            $e = unpack(
                'Cwidth/Cheight/Ccolor_count/Creserved/vplanes/vbits/Vsize/Voffset',
                substr($data, $eOffset, 16),
            );
            // 0 means 256 in the directory entry per spec.
            if ($e['width']  === 0) $e['width']  = 256;
            if ($e['height'] === 0) $e['height'] = 256;
            $entries[] = $e;
        }

        $entry = $this->pickBest($entries, $preferredSize);

        return $this->decodeBmpImage($data, $entry, $path);
    }

    /**
     * Pick the entry closest to $preferredSize (smaller-or-equal
     * wins ties so we don't scale UP to a smaller-than-icon slot), then
     * highest bpp.
     */
    private function pickBest(array $entries, int $preferredSize): array
    {
        usort($entries, static function (array $a, array $b) use ($preferredSize): int {
            $da = abs($a['width'] - $preferredSize);
            $db = abs($b['width'] - $preferredSize);
            if ($da !== $db) return $da <=> $db;
            // Same distance — prefer the smaller side (down-scales less harmful than up).
            if ($a['width'] !== $b['width']) return $a['width'] <=> $b['width'];
            return $b['bits'] <=> $a['bits'];
        });
        return $entries[0];
    }

    /** Decodes one BMP-encoded icon image, bottom-up rows and all, into pixels. */
    private function decodeBmpImage(string $data, array $entry, string $path): RasterImage
    {
        $img = substr($data, $entry['offset'], $entry['size']);
        if (strlen($img) < 40) {
            throw new \RuntimeException("ICO image data too short: $path");
        }

        // PNG-encoded entries start with the PNG signature. We don't decode
        // PNGs ourselves — Win2k icons are always BMP-style so this is just
        // a sanity error message.
        if (substr($img, 0, 8) === "\x89PNG\r\n\x1a\n") {
            throw new \RuntimeException("PNG-encoded ICO entries not supported: $path");
        }

        $b = unpack(
            'Vhdr_size/Vwidth/lheight/vplanes/vbits/Vcompression/Vsize_image/lxppm/lyppm/Vclr_used/Vclr_important',
            substr($img, 0, 40),
        );

        if ($b['compression'] !== 0) {
            throw new \RuntimeException("Compressed ICO not supported (compression={$b['compression']}): $path");
        }

        $width  = $b['width'];
        $height = intdiv($b['height'], 2);   // header stores XOR + AND together
        $bits   = $b['bits'];

        // Palette (indexed only).
        $palette = [];
        $paletteBytes = 0;
        if ($bits <= 8) {
            $palSize = $b['clr_used'] !== 0 ? $b['clr_used'] : (1 << $bits);
            for ($i = 0; $i < $palSize; $i++) {
                $p = unpack('Cb/Cg/Cr/Creserved', substr($img, 40 + $i * 4, 4));
                $palette[$i] = [$p['r'], $p['g'], $p['b']];
            }
            $paletteBytes = $palSize * 4;
        } elseif ($bits !== 24 && $bits !== 32) {
            throw new \RuntimeException("Unsupported bpp ($bits): $path");
        }

        $xorOffset  = 40 + $paletteBytes;
        $xorRowSize = intdiv($width * $bits + 31, 32) * 4;
        $andOffset  = $xorOffset + $height * $xorRowSize;
        $andRowSize = intdiv($width + 31, 32) * 4;

        // Decode bottom-up rows into a top-down RGBA grid.
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row    = $height - 1 - $y;   // BMPs are bottom-up
            $xorRow = $xorOffset + $row * $xorRowSize;
            $andRow = $andOffset + $row * $andRowSize;
            $line   = [];
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $bl] = $this->decodePixel($img, $xorRow, $x, $bits, $palette);
                $alpha = $this->andMaskBit($img, $andRow, $x) === 0 ? 255 : 0;
                $line[] = [$r, $g, $bl, $alpha];
            }
            $pixels[] = $line;
        }

        return new RasterImage($width, $height, $pixels);
    }

    /** @return array{int, int, int} */
    private function decodePixel(string $img, int $rowStart, int $x, int $bits, array $palette): array
    {
        switch ($bits) {
            case 4:
                $byte = ord($img[$rowStart + intdiv($x, 2)]);
                $idx  = ($x % 2 === 0) ? (($byte >> 4) & 0x0F) : ($byte & 0x0F);
                return $palette[$idx] ?? [0, 0, 0];
            case 8:
                $idx = ord($img[$rowStart + $x]);
                return $palette[$idx] ?? [0, 0, 0];
            case 24:
                $o = $rowStart + $x * 3;
                return [ord($img[$o + 2]), ord($img[$o + 1]), ord($img[$o])];
            case 32:
                $o = $rowStart + $x * 4;
                return [ord($img[$o + 2]), ord($img[$o + 1]), ord($img[$o])];
            default:
                return [0, 0, 0];
        }
    }

    /** Returns the AND-mask bit for pixel $x: 0 = opaque, 1 = transparent. */
    private function andMaskBit(string $img, int $rowStart, int $x): int
    {
        $byte = ord($img[$rowStart + intdiv($x, 8)]);
        return ($byte >> (7 - ($x % 8))) & 1;
    }
}
