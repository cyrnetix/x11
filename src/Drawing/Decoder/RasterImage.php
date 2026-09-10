<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing\Decoder;

/**
 * What a decoder hands back: one image at whatever size it was stored at,
 * as straight RGBA.
 *
 * Deliberately *not* an {@see \Cyrnetix\X11\Drawing\Icon}. An icon has had the
 * toolkit's policy applied to it — scaled to the size it will be drawn at, and
 * its alpha collapsed to drawn-or-absent because X11 core rendering cannot
 * blend. That policy is the same whichever driver read the file, so it lives in
 * {@see \Cyrnetix\X11\Drawing\IconScaler} and a driver never has to know about
 * it. A driver's whole job is "bytes on disk to pixels".
 */
final class RasterImage
{
    /**
     * @param list<list<array{int,int,int,int}>> $pixels [y][x] = [r, g, b, alpha]
     *        with alpha 0-255, top-down, non-premultiplied.
     */
    public function __construct(
        public readonly int   $width,
        public readonly int   $height,
        public readonly array $pixels,
    ) {}
}
