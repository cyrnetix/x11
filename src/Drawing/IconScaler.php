<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

use Cyrnetix\X11\Drawing\Decoder\RasterImage;

/**
 * Turns a decoded raster into an {@see Icon} at the size it will be drawn.
 *
 * This is the toolkit's *policy*, and it is deliberately separate from decoding
 * so that every driver produces the same icon from the same file. Two
 * transformations, both of which exist for a reason:
 *
 *  - **Box downscale.** The Mac set's artwork is 64x64 and gets drawn at 16, and
 *    nearest-neighbour at 4:1 throws away three quarters of the detail.
 *    Averaging over each source block keeps it readable.
 *  - **Alpha threshold.** X11 core rendering has no alpha blending, so a pixel
 *    is either drawn or skipped. Averaged coverage below the threshold is
 *    dropped, which trims the soft drop shadows Mac icons carry — left in, they
 *    read as a grey smear rather than a shadow.
 *
 * Colour is weighted by coverage before averaging. Without that, a fully
 * transparent black pixel drags the average of its block towards black and
 * every edge picks up a dark fringe, which is the classic way a resized icon
 * with alpha goes wrong.
 *
 * A raster already at or below the target is not enlarged; each target pixel
 * then takes exactly one source pixel and the pass is a straight threshold.
 * That is what keeps a 16x16 .ico entry byte-identical through here.
 */
final class IconScaler
{
    /**
     * @param int $preferredSize Size icons are drawn at, and the ceiling this
     *        scales down to.
     * @param int $alphaThreshold Averaged coverage (0-255) below which a pixel
     *        is treated as absent.
     */
    public function __construct(
        public readonly int $preferredSize  = 16,
        public readonly int $alphaThreshold = 110,
    ) {}

    /** The icon $image becomes once the toolkit's policy is applied. */
    public function toIcon(RasterImage $image): Icon
    {
        $target = min($this->preferredSize, max($image->width, $image->height));

        return new Icon(
            $target,
            $target,
            $this->downscale($image->pixels, $image->width, $image->height, $target),
        );
    }

    /**
     * Average each source block down to one target pixel, then threshold it.
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
