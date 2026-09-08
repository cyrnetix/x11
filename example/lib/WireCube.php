<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Example;

/**
 * A rotating wireframe cube, projected to 2D line segments.
 *
 * The other half of what a framebuffer is for. A paint program writes pixels
 * where the pointer went; a renderer *computes* them — and this is the smallest
 * honest example of that: eight vertices, two rotations, a perspective divide,
 * twelve edges. It is what a mid-90s Windows game did between the message pump
 * and the blit, and it is why `CreateDIBSection` existed.
 *
 * No X11 here, and no drawing: {@see project()} hands back segments in pixel
 * coordinates and the caller decides what to do with them — which is what lets
 * `tests/wirecube_test.php` check the geometry with no display, and lets the
 * same numbers drive a canvas, a plotter or a set of PolyLines.
 *
 * @phpstan-type Segment array{int, int, int, int}
 */
final class WireCube
{
    /**
     * The unit cube's corners. Order matters only in that {@see EDGES} indexes
     * into it: bits of the index are the sign of x, y and z.
     *
     * @var list<array{float, float, float}>
     */
    private const VERTICES = [
        [-1.0, -1.0, -1.0], [1.0, -1.0, -1.0], [1.0, 1.0, -1.0], [-1.0, 1.0, -1.0],
        [-1.0, -1.0,  1.0], [1.0, -1.0,  1.0], [1.0, 1.0,  1.0], [-1.0, 1.0,  1.0],
    ];

    /**
     * The twelve edges: the four of the back face, the four of the front, and
     * the four joining them.
     *
     * @var list<array{int, int}>
     */
    private const EDGES = [
        [0, 1], [1, 2], [2, 3], [3, 0],
        [4, 5], [5, 6], [6, 7], [7, 4],
        [0, 4], [1, 5], [2, 6], [3, 7],
    ];

    private float $angle = 0.0;

    /** @param float $distance Eye distance in cube units; larger is flatter. */
    public function __construct(
        private readonly float $distance = 3.2,
    ) {}

    /** Advance the rotation. One call per animation tick. */
    public function advance(float $radians = 0.06): void
    {
        $this->angle += $radians;
    }

    /** The rotation, for a caller that wants to draw two cubes out of phase. */
    public function angle(): float { return $this->angle; }

    /**
     * The cube's edges as line segments, centred in a $width x $height image.
     *
     * Rotated about Y and then X — two axes rather than one, because a cube
     * spinning about a single axis reads as a flat hexagon and gives away
     * nothing about the projection being real.
     *
     * @return list<Segment> [[x0, y0, x1, y1], …]
     */
    public function project(int $width, int $height): array
    {
        $cy = cos($this->angle);
        $sy = sin($this->angle);
        $cx = cos($this->angle * 0.6);
        $sx = sin($this->angle * 0.6);

        // Scaled to the smaller side so the cube stays inside a non-square
        // canvas. 0.7 is not a guess: the widest a corner can project is
        // 0.64 * scale from the centre (maximise |x| / (distance - z) over the
        // unit cube's corners), so 0.7 leaves it just inside the edge at every
        // angle. The canvas would clip a stray pixel harmlessly, but a cube
        // whose corners flatten against the frame looks like a bug.
        $scale   = min($width, $height) * 0.7;
        $originX = intdiv($width, 2);
        $originY = intdiv($height, 2);

        $points = [];
        foreach (self::VERTICES as [$x, $y, $z]) {
            // Y axis, then X.
            $rx =  $x * $cy + $z * $sy;
            $rz = -$x * $sy + $z * $cy;
            $ry =  $y * $cx - $rz * $sx;
            $rz =  $y * $sx + $rz * $cx;

            // The perspective divide, and the whole reason this reads as a solid
            // rather than a hexagon: the nearer a corner, the further from the
            // centre it lands. The eye sits outside the cube, so the
            // denominator cannot reach zero — the clamp is there for a caller
            // that passes an unreasonable distance.
            $factor   = $scale / max(0.1, $this->distance - $rz);
            $points[] = [
                $originX + (int) round($rx * $factor),
                $originY + (int) round($ry * $factor),
            ];
        }

        $segments = [];
        foreach (self::EDGES as [$from, $to]) {
            $segments[] = [$points[$from][0], $points[$from][1], $points[$to][0], $points[$to][1]];
        }

        return $segments;
    }
}
