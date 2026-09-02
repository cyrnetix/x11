<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * A decoded raster icon. Stored as:
 *  - the raw RGBA pixel grid (for nearest-neighbour scaling when the
 *    target size differs from native), and
 *  - a pre-computed list of horizontal "runs" of contiguous same-colour
 *    opaque pixels for the native size (so drawing at native size is one
 *    setForeground + fillRect per run instead of one per pixel).
 *
 * Run cache is keyed by target size so scaling a 16×16 icon to 14×14 only
 * pays the per-pixel scan once.
 */
final class Icon
{
    /** @var array<int, list<array{int,int,int,int,int,int}>> size => [[r,g,b,x,y,width], ...] */
    private array $runsCache = [];

    /**
     * @param list<list<array{int,int,int,int}>> $pixels [y][x] = [r,g,b,alpha]
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly array $pixels,
    ) {}

    /**
     * Draw the icon onto $r anchored at (x, y). Defaults to the icon's
     * native size; pass a different $size to scale (nearest-neighbour).
     */
    public function drawAt(Renderer $r, int $x, int $y, ?int $size = null): void
    {
        $size ??= $this->width;
        if (!isset($this->runsCache[$size])) {
            $this->runsCache[$size] = $this->computeRuns($size);
        }
        foreach ($this->runsCache[$size] as [$cr, $cg, $cb, $rx, $ry, $rw]) {
            $r->setForeground($cr, $cg, $cb);
            $r->fillRect($x + $rx, $y + $ry, $rw, 1);
        }
    }

    /**
     * Compute horizontal runs of contiguous same-colour opaque pixels for
     * the target size. Output coordinates are in target space (0..size-1).
     *
     * @return list<array{int,int,int,int,int,int}>  [r, g, b, x, y, width]
     */
    private function computeRuns(int $size): array
    {
        $runs   = [];
        $native = $this->width;
        $scale  = $size !== $native;

        for ($ty = 0; $ty < $size; $ty++) {
            $sy = $scale ? (int) ($ty * $native / $size) : $ty;
            $tx = 0;
            while ($tx < $size) {
                $sx = $scale ? (int) ($tx * $native / $size) : $tx;
                $px = $this->pixels[$sy][$sx];
                // Transparent: skip and start a new run on the next pixel.
                if ($px[3] === 0) { $tx++; continue; }
                $startX = $tx;
                [$cr, $cg, $cb] = $px;
                $tx++;
                while ($tx < $size) {
                    $sx2 = $scale ? (int) ($tx * $native / $size) : $tx;
                    $px2 = $this->pixels[$sy][$sx2];
                    if ($px2[3] === 0 || $px2[0] !== $cr || $px2[1] !== $cg || $px2[2] !== $cb) break;
                    $tx++;
                }
                $runs[] = [$cr, $cg, $cb, $startX, $ty, $tx - $startX];
            }
        }
        return $runs;
    }
}
