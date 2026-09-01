<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

use RuntimeException;

/**
 * Parses PNG files into {@see Icon} value objects.
 *
 * Handles the subset icon sets actually use: 8-bit greyscale, truecolour,
 * palette (1/2/4/8-bit) and truecolour-with-alpha, non-interlaced. Everything
 * goes through zlib's inflate and the five PNG scanline filters.
 *
 * Two deliberate transformations happen on load:
 *
 *  - **Box downscale to $preferredSize.** These icons are 64×64 but get drawn at
 *    16px, and nearest-neighbour at 4:1 throws away three quarters of the detail.
 *    Averaging over each source block keeps the artwork readable.
 *  - **Alpha threshold.** X11 core rendering has no alpha blending, so a pixel is
 *    either drawn or skipped. Averaged coverage below the threshold is dropped,
 *    which trims the soft drop shadows Mac icons carry.
 */
final class PngLoader implements IconLoader
{
    /** Records the preferred size. */
    public function __construct(
        public readonly int $preferredSize = 16,
        /** Averaged coverage (0-255) below which a pixel is treated as absent. */
        public readonly int $alphaThreshold = 110,
    ) {}

    /** {@inheritDoc} */
    public function load(string $path): Icon
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Cannot read PNG file: $path");
        }
        return $this->parse($data, $path);
    }

    /** Reads the PNG signature, IHDR and IDAT chunks and inflates the pixel data. */
    private function parse(string $data, string $path): Icon
    {
        if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException("Not a PNG: $path");
        }

        $width = $height = $depth = $type = 0;
        $palette = [];
        $idat    = '';
        $pos     = 8;

        while ($pos + 8 <= strlen($data)) {
            $length = unpack('N', substr($data, $pos, 4))[1];
            $kind   = substr($data, $pos + 4, 4);
            $chunk  = substr($data, $pos + 8, $length);
            $pos   += 12 + $length;

            switch ($kind) {
                case 'IHDR':
                    $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunk);
                    [$width, $height, $depth, $type] = [
                        $ihdr['width'], $ihdr['height'], $ihdr['depth'], $ihdr['type'],
                    ];
                    if ($ihdr['interlace'] !== 0) {
                        throw new RuntimeException("Interlaced PNG unsupported: $path");
                    }
                    break;

                case 'PLTE':
                    for ($i = 0; $i + 2 < strlen($chunk); $i += 3) {
                        $palette[] = [ord($chunk[$i]), ord($chunk[$i + 1]), ord($chunk[$i + 2])];
                    }
                    break;

                case 'IDAT':
                    $idat .= $chunk;
                    break;

                case 'IEND':
                    break 2;
            }
        }

        if ($width <= 0 || $height <= 0 || $idat === '') {
            throw new RuntimeException("PNG has no image data: $path");
        }

        $channels = match ($type) {
            0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4,
            default => throw new RuntimeException("Unsupported PNG colour type $type: $path"),
        };
        if ($depth !== 8 && !($type === 3 && in_array($depth, [1, 2, 4, 8], true))) {
            throw new RuntimeException("Unsupported PNG bit depth $depth: $path");
        }

        $raw = @gzuncompress($idat);
        if ($raw === false) {
            throw new RuntimeException("PNG image data failed to inflate: $path");
        }

        $pixels = $this->unfilter($raw, $width, $height, $depth, $type, $channels, $palette, $path);

        $target = min($this->preferredSize, max($width, $height));

        return new Icon($target, $target, $this->downscale($pixels, $width, $height, $target));
    }

    /**
     * Undo the per-scanline filters and expand each row to [r, g, b, a] tuples.
     *
     * @param list<array{int,int,int}> $palette
     * @return list<list<array{int,int,int,int}>>
     */
    private function unfilter(
        string $raw,
        int $width,
        int $height,
        int $depth,
        int $type,
        int $channels,
        array $palette,
        string $path,
    ): array {
        $stride = intdiv($width * $channels * $depth + 7, 8);
        $step   = max(1, intdiv($channels * $depth, 8));

        $rows   = [];
        $prior  = str_repeat("\x00", $stride);
        $offset = 0;

        for ($y = 0; $y < $height; $y++) {
            if ($offset + 1 + $stride > strlen($raw)) {
                throw new RuntimeException("PNG scanlines truncated: $path");
            }

            $filter = ord($raw[$offset]);
            $line   = substr($raw, $offset + 1, $stride);
            $offset += 1 + $stride;

            $out = '';
            for ($i = 0; $i < $stride; $i++) {
                $x = ord($line[$i]);
                $a = $i >= $step ? ord($out[$i - $step]) : 0;
                $b = ord($prior[$i]);
                $c = $i >= $step ? ord($prior[$i - $step]) : 0;

                $value = match ($filter) {
                    0 => $x,
                    1 => $x + $a,
                    2 => $x + $b,
                    3 => $x + intdiv($a + $b, 2),
                    4 => $x + $this->paeth($a, $b, $c),
                    default => throw new RuntimeException("Unknown PNG filter $filter: $path"),
                };
                $out .= chr($value & 0xFF);
            }
            $prior = $out;

            $rows[] = $this->expandRow($out, $width, $depth, $type, $channels, $palette);
        }

        return $rows;
    }

    /**
     * The PNG Paeth predictor: whichever of the three neighbours is closest to their linear
     * estimate.
     */
    private function paeth(int $a, int $b, int $c): int
    {
        $p  = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /**
     * @param list<array{int,int,int}> $palette
     * @return list<array{int,int,int,int}>
     */
    private function expandRow(string $line, int $width, int $depth, int $type, int $channels, array $palette): array
    {
        $row = [];

        for ($x = 0; $x < $width; $x++) {
            switch ($type) {
                case 3:
                    if ($depth === 8) {
                        $index = ord($line[$x]);
                    } else {
                        $perByte = intdiv(8, $depth);
                        $byte    = ord($line[intdiv($x, $perByte)]);
                        $shift   = 8 - $depth * (($x % $perByte) + 1);
                        $index   = ($byte >> $shift) & ((1 << $depth) - 1);
                    }
                    $rgb   = $palette[$index] ?? [0, 0, 0];
                    $row[] = [$rgb[0], $rgb[1], $rgb[2], 255];
                    break;

                case 2:
                    $i     = $x * $channels;
                    $row[] = [ord($line[$i]), ord($line[$i + 1]), ord($line[$i + 2]), 255];
                    break;

                case 6:
                    $i     = $x * $channels;
                    $row[] = [ord($line[$i]), ord($line[$i + 1]), ord($line[$i + 2]), ord($line[$i + 3])];
                    break;

                case 4:
                    $i     = $x * $channels;
                    $grey  = ord($line[$i]);
                    $row[] = [$grey, $grey, $grey, ord($line[$i + 1])];
                    break;

                default:
                    $grey  = ord($line[$x * $channels]);
                    $row[] = [$grey, $grey, $grey, 255];
            }
        }

        return $row;
    }

    /**
     * Average each source block down to one target pixel. Colour is weighted by
     * coverage so transparent pixels don't wash the edges out, and the averaged
     * coverage is then thresholded into "drawn" or "not".
     *
     * @param list<list<array{int,int,int,int}>> $pixels
     * @return list<list<array{int,int,int,int}>>
     */
    private function downscale(array $pixels, int $width, int $height, int $target): array
    {
        $out = [];

        for ($ty = 0; $ty < $target; $ty++) {
            $y0 = intdiv($ty * $height, $target);
            $y1 = max($y0 + 1, intdiv(($ty + 1) * $height, $target));

            $row = [];
            for ($tx = 0; $tx < $target; $tx++) {
                $x0 = intdiv($tx * $width, $target);
                $x1 = max($x0 + 1, intdiv(($tx + 1) * $width, $target));

                $r = $g = $b = $a = 0;
                $samples = 0;

                for ($y = $y0; $y < $y1; $y++) {
                    for ($x = $x0; $x < $x1; $x++) {
                        [$pr, $pg, $pb, $pa] = $pixels[$y][$x];
                        $r += $pr * $pa;
                        $g += $pg * $pa;
                        $b += $pb * $pa;
                        $a += $pa;
                        $samples++;
                    }
                }

                $coverage = intdiv($a, max(1, $samples));
                if ($a === 0 || $coverage < $this->alphaThreshold) {
                    $row[] = [0, 0, 0, 0];
                    continue;
                }

                $row[] = [intdiv($r, $a), intdiv($g, $a), intdiv($b, $a), 255];
            }
            $out[] = $row;
        }

        return $out;
    }
}
